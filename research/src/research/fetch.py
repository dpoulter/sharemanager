"""Download from FMP into Parquet, resumably.

Layout under the data folder:

    raw/<dataset>/<SYMBOL>.parquet   one file per symbol per dataset, the unit
                                     of resume: a file that exists is done
    raw/profiles.parquet             one row per symbol
    symbols.parquet                  the symbol master, with exclusions
    prices.parquet                   symbol, date, close (raw, pence), adj_close
    market_cap.parquet               symbol, date, market_cap (pounds)
    dividends.parquet                symbol, ex_date, amount (quote unit), payment_date, kind
    statements.parquet               one row per symbol, period, statement fields
    fx.parquet                       date, currency, gbp_per_unit

    raw/topup_state.json             what the nightly top-up knows: the last trading day
                                     read, and per symbol when its price history was last read
                                     whole and when its statements and dividends are next due

A full pull writes every per-symbol file it does not already have, so an
interrupted run started again carries on where it stopped.

`fetch` without --full tops up (the nightly run, contract data_refresh: under
an hour). Calls run a small pool at a time, up to the configured calls a
minute (fmp.py). What it asks FMP for, all of it on Dale's plan (checked on
the Mac mini with the real key, 22 Nov 2026):

- Prices: `batch-quote`, fmp.batch_symbols symbols a call, after the close,
  when there is one new trading day since the last one held. It gives only
  the latest price, so which day that is, and that the quote is that day's
  close, is read from a few symbols' own history first. A symbol's own
  `historical-price-eod` is asked for only when a night was missed (every
  missed day is filled, never left as a gap), when it is new or behind, when
  it has gone ex-dividend since its history was last read (FMP then re-bases
  the whole adjusted series), or when the quote's previous close is not the
  close held (a split or a correction). Between ex-dates a new day's adjusted
  close keeps the ratio to the raw close of the day before.
- Market cap: `market-capitalization-batch` for the one new day, and a
  symbol's own history where that does not bring it up to its last price.
- Dividends: `dividends-calendar` each night, and each symbol's own list once
  every fmp.dividends_refresh_days.
- Statements: once every fmp.statements_refresh_days, spread over the nights.
- A delisted symbol whose files are all there is never asked for again.

It never asks for `eod-bulk`, `batch-exchange-quote` or
`latest-financial-statements`: they are not on the plan. If FMP refuses any
call as not on the plan (HTTP 402) nothing is fallen back to: the top-up
stops, names the endpoint, records no data version, and the night does not
count as complete (logs/fetch_last.json), so nothing is published. The state
file is written when the downloads finish, so a top-up that stops part way is
simply run again.
"""
from __future__ import annotations

import datetime as dt
import hashlib
import json
import logging
import re
import sys
import threading
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import numpy as np
import pandas as pd

from .config import Config, mask
from .fmp import Fmp, FmpError, FmpRestricted

log = logging.getLogger(__name__)

FUND_SECTORS = {"Investment Trusts", "Closed End Investments", "Collective Investments"}
FUND_NAME = re.compile(
    r"investment trust|\bucits\b|\betf\b|\betc\b|\bfund\b|\bincome trust\b|"
    r"\bventure capital trust\b|\bvct\b|\bsicav\b|\boeic\b|\btracker\b|\bishares\b|"
    r"\bxtrackers\b|\bwisdomtree\b|\bspdr\b|\bvanguard\b|\binvesco\b.*\b(etf|ucits)\b",
    re.I)
PREF_NAME = re.compile(r"\bpref(erence)?\b|\bcum(ulative)?\b.*%|\d+(\.\d+)?\s?%|\bdebenture\b|\bnotes?\b|\bbond\b", re.I)
DEPOSITARY = re.compile(r"\bgdr\b|\badr\b|depositary", re.I)
IOB = re.compile(r"^0[A-Z0-9]{3}\.L$")
LOAN_STOCK = re.compile(r"^\d{2}[A-Z]{2}\.L$")      # 49GP.L: a Croda bond under Croda's name

TRUST_REASONS = ("FMP isFund", "fund sector", "fund or trust by name")

STATEMENTS = {
    "income": "income-statement",
    "balance": "balance-sheet-statement",
    "cashflow": "cash-flow-statement",
}


STATEMENT_SETS = [f"{name}_{period}" for name in STATEMENTS for period in ("annual", "quarter")]
STATE = "topup_state.json"
PROBES = 3                 # symbols whose own history is read each night: the new trading days, and their closes
CLOSE_TOLERANCE = 1e-6     # relative: two closes further apart than this are different
# A quote's previous close (price - change) further than this from the close held, relative, is a
# split or a correction: the symbol's history is read again. If more than MOVED_SHARE of the symbols
# (and more than MOVED_MIN) say so on one night it is the quotes, not the symbols, and none is.
PREVIOUS_CLOSE_TOLERANCE = 0.01
MOVED_SHARE = 0.05
MOVED_MIN = 5


def _today() -> str:
    return dt.date.today().isoformat()


def _spread(sym: str, days: int) -> int:
    """A fixed number in 0..days-1 for a symbol: spreads periodic work evenly over the days."""
    return int(hashlib.sha256(sym.encode()).hexdigest(), 16) % max(1, days)


def _day(d) -> str:
    return pd.Timestamp(d).date().isoformat()


def _num(v) -> float:
    try:
        return float(v)
    except (TypeError, ValueError):
        return float("nan")


def _same_close(a: float, b: float) -> bool:
    return bool(abs(a - b) <= CLOSE_TOLERANCE * max(abs(a), abs(b)))


def _traded(quote: dict, day: str, held: tuple | None) -> bool:
    """Whether a batch-quote row is a trade on `day`, not the last price of an earlier day.

    The quote's own time says, where FMP gives one. Without it: a price and a volume both the same
    as the last day held is that day's quote again.
    """
    ts = _num(quote.get("timestamp"))
    if np.isfinite(ts) and ts > 0:
        return dt.datetime.fromtimestamp(ts, dt.timezone.utc).date().isoformat() == day
    if held is None:
        return False
    return not (_same_close(_num(quote.get("price")), held[1]) and _num(quote.get("volume")) == held[3])


class Fetcher:
    def __init__(self, cfg: Config, client: Fmp | None = None):
        self.cfg = cfg
        self.data = cfg.data_dir
        self.raw = self.data / "raw"
        self.raw.mkdir(parents=True, exist_ok=True)
        self.fmp = client or Fmp(cfg)
        self.failures: list[dict] = []
        self.topup: dict = {}          # what the last top-up did, for the log and the tests

    def _each(self, fn, items: list, what: str) -> None:
        """fn(item) for every item, a small pool at a time. An FmpError is a failure of that item only."""
        items = list(items)
        done = [0]
        lock = threading.Lock()

        def one(item):
            try:
                fn(item)
            except FmpRestricted:
                raise                   # not one item's failure: the night stops and names the endpoint
            except FmpError as e:
                sym = item if isinstance(item, str) else str(item)
                self.failures.append({"symbol": sym, "dataset": what, "error": str(e)})
                log.warning("%s %s: %s", what, sym, mask(e))
            with lock:
                done[0] += 1
                if done[0] % 200 == 0 or done[0] == len(items):
                    log.info("%s %d/%d, %d calls, %d 429s", what, done[0], len(items), self.fmp.calls,
                             getattr(self.fmp, "throttled", 0))

        workers = min(int(getattr(self.fmp, "concurrency", 1)), len(items))
        if workers <= 1:
            for item in items:
                one(item)
        else:
            with ThreadPoolExecutor(max_workers=workers, thread_name_prefix="fmp") as pool:
                list(pool.map(one, items))

    # ------------------------------------------------------------ symbols
    def list_symbols(self) -> pd.DataFrame:
        listed = self.fmp.get("stock-list") or []
        rows = [{"symbol": r["symbol"], "name": r.get("companyName") or r.get("name"),
                 "delisted_date": None} for r in listed if str(r.get("symbol", "")).endswith(".L")]
        # The delisted list is a hundred or so pages: a few at a time, until one comes back empty.
        wave = max(1, min(8, int(getattr(self.fmp, "concurrency", 1))))
        page, more = 0, True
        while more and page <= 500:
            pages = list(range(page, page + wave))
            if wave == 1:
                batches = [self.fmp.get("delisted-companies", page=page, limit=100) or []]
            else:
                with ThreadPoolExecutor(max_workers=wave) as pool:
                    batches = list(pool.map(lambda k: self.fmp.get("delisted-companies", page=k, limit=100) or [], pages))
            for batch in batches:
                if not batch:
                    more = False
                    break
                for r in batch:
                    sym = str(r.get("symbol", ""))
                    if sym.endswith(".L"):
                        rows.append({"symbol": sym, "name": r.get("companyName"),
                                     "delisted_date": r.get("delistedDate"),
                                     "ipo_date": r.get("ipoDate")})
            page += wave
        df = pd.DataFrame(rows)
        # A symbol both listed and delisted (ticker reused) keeps the listed row
        # and the delisting date of the earlier company is recorded separately.
        df = df.sort_values("delisted_date", na_position="first").drop_duplicates("symbol")
        return df.reset_index(drop=True)

    def profiles(self, symbols: list[str]) -> pd.DataFrame:
        path = self.raw / "profiles.parquet"
        have = pd.read_parquet(path) if path.exists() else pd.DataFrame(columns=["symbol"])
        todo = [s for s in symbols if s not in set(have["symbol"])]
        todo += self.country_backfill(have, symbols)
        rows = []
        log.info("profiles: %d to fetch, %d held", len(todo), len(have))

        def one(sym):
            data = self.fmp.get("profile", symbol=sym)
            p = data[0] if isinstance(data, list) and data else {}
            rows.append({
                "symbol": sym, "isin": p.get("isin"), "sector": p.get("sector"),
                "industry": p.get("industry"), "currency": p.get("currency"),
                "exchange": p.get("exchange") or p.get("exchangeShortName"),
                "exchange_full": p.get("exchangeFullName"),
                "is_etf": bool(p.get("isEtf")), "is_fund": bool(p.get("isFund")),
                "is_adr": bool(p.get("isAdr")),
                "active": p.get("isActivelyTrading"), "ipo_date": p.get("ipoDate"),
                "profile_name": p.get("companyName"),
                "country": p.get("country"), "country_checked": True,
            })

        # In lots, so a long first pull that stops keeps the profiles it has.
        for k in range(0, len(todo), 200):
            self._each(one, todo[k:k + 200], "profile")
            if rows:
                have = self._add_profiles(have, sorted(rows, key=lambda r: r["symbol"]))
                have.to_parquet(path)
                rows.clear()
        return have

    @staticmethod
    def _add_profiles(have: pd.DataFrame, rows: list[dict]) -> pd.DataFrame:
        """New profile rows over the held ones: a re-fetched symbol replaces its old row."""
        new = pd.DataFrame(rows)
        return pd.concat([have[~have["symbol"].isin(new["symbol"])], new], ignore_index=True)

    @staticmethod
    def country_backfill(have: pd.DataFrame, symbols: list[str]) -> list[str]:
        """Held profiles that have no ISIN and were fetched before the country was kept.

        The universe keeps a line with no ISIN only if the profile says it is a UK company
        (master.py), so those, and only those, are fetched again, once.
        """
        if have.empty:
            return []
        isin = have["isin"] if "isin" in have else pd.Series(np.nan, index=have.index)
        checked = have["country_checked"].fillna(False).astype(bool) if "country_checked" in have \
            else pd.Series(False, index=have.index)
        sel = have.loc[isin.isna() & ~checked & have["symbol"].isin(symbols), "symbol"]
        return sel.tolist()

    def build_master(self, full: bool) -> pd.DataFrame:
        listed = self.list_symbols()
        prof = self.profiles(listed["symbol"].tolist())
        df = listed.merge(prof, on="symbol", how="left", suffixes=("", "_p"))
        if "ipo_date_p" in df:
            df["ipo_date"] = df["ipo_date"].astype(object).fillna(df["ipo_date_p"]) if "ipo_date" in df else df["ipo_date_p"]
        if "profile_name" in df:
            df["name"] = df["name"].astype(object).fillna(df["profile_name"])
        df["is_aim"] = df.apply(_is_aim, axis=1)
        aim_list = self.cfg["universe"].get("aim_list")
        if aim_list:
            aim = set(pd.read_csv(Path(aim_list).expanduser())["symbol"].astype(str))
            df.loc[df["symbol"].isin(aim) | df["symbol"].str.replace(".L", "").isin(aim), "is_aim"] = True
        df["exclude_reason"] = df.apply(exclusion_reason, axis=1)
        df["included"] = df["exclude_reason"].isna()
        df.to_parquet(self.data / "symbols.parquet")
        return df

    # ------------------------------------------------------------ per symbol
    def _path(self, dataset: str, sym: str) -> Path:
        d = self.raw / dataset
        d.mkdir(exist_ok=True)
        return d / f"{sym}.parquet"

    def _chunks(self, start: str, end: str):
        years = int(self.cfg["fmp"]["price_chunk_years"])
        a = pd.Timestamp(start)
        e = pd.Timestamp(end)
        while a <= e:
            b = min(a + pd.DateOffset(years=years) - pd.Timedelta(days=1), e)
            yield a.date().isoformat(), b.date().isoformat()
            a = b + pd.Timedelta(days=1)

    def _merge_write(self, path: Path, new: pd.DataFrame, keys: list[str]) -> None:
        if path.exists() and len(new):
            old = pd.read_parquet(path)
            new = pd.concat([old, new], ignore_index=True).drop_duplicates(keys, keep="last")
        elif path.exists():
            return
        new.sort_values(keys).reset_index(drop=True).to_parquet(path)

    def prices(self, sym: str, full: bool, end: str, start: str | None = None) -> None:
        path = self._path("prices", sym)
        start = start or self.cfg["fmp"]["price_start"]
        if path.exists():
            if full:
                return
            last = pd.read_parquet(path, columns=["date"])["date"].max()
            if pd.isna(last):
                return          # FMP had nothing for it; a quote says if that changes (top_up_prices)
            start = (pd.Timestamp(last) + pd.Timedelta(days=1)).date().isoformat()
            if start > end:
                return
        df = self._price_frame(sym, start, end)
        if df.empty:
            if not path.exists():
                pd.DataFrame({"date": pd.Series(dtype="datetime64[ns]"), "close": [], "adj_close": [],
                              "volume": []}).to_parquet(path)
            return
        self._merge_write(path, df, ["date"])

    def _price_frame(self, sym: str, start: str, end: str) -> pd.DataFrame:
        """A symbol's own history from FMP: date, close, volume, adj_close (dividend-adjusted)."""
        raw, adj = [], []
        for a, b in self._chunks(start, end):
            r = self.fmp.get("historical-price-eod/full", symbol=sym, **{"from": a, "to": b}) or []
            raw += r if isinstance(r, list) else r.get("historical", [])
            j = self.fmp.get("historical-price-eod/dividend-adjusted", symbol=sym, **{"from": a, "to": b}) or []
            adj += j if isinstance(j, list) else j.get("historical", [])
        df = pd.DataFrame(raw)
        if df.empty:
            return df
        df = df[["date", "close"] + (["volume"] if "volume" in df else [])]
        if adj:
            a = pd.DataFrame(adj)
            col = "adjClose" if "adjClose" in a else "close"
            df = df.merge(a[["date", col]].rename(columns={col: "adj_close"}), on="date", how="left")
        else:
            df["adj_close"] = pd.NA
        df["date"] = pd.to_datetime(df["date"])
        return df

    def history(self, sym: str, end: str, start: str | None = None) -> None:
        """The whole price history again, over what is held: for a symbol whose adjusted series FMP
        has re-based (an ex-dividend date) or whose held close no longer agrees with FMP's."""
        df = self._price_frame(sym, start or self.cfg["fmp"]["price_start"], end)
        if len(df):
            self._merge_write(self._path("prices", sym), df, ["date"])

    def market_cap(self, sym: str, full: bool, end: str) -> None:
        path = self._path("market_cap", sym)
        start = self.cfg["fmp"]["market_cap_start"]
        if path.exists():
            if full:
                return
            last = pd.read_parquet(path, columns=["date"])["date"].max()
            if pd.isna(last):
                return          # FMP had no market cap for it
            start = (pd.Timestamp(last) + pd.Timedelta(days=1)).date().isoformat()
            if start > end:
                return
        rows = []
        for a, b in self._chunks(start, end):
            rows += self.fmp.get("historical-market-capitalization", symbol=sym, limit=5000,
                                 **{"from": a, "to": b}) or []
        df = pd.DataFrame(rows)
        if df.empty:
            df = pd.DataFrame({"date": pd.Series(dtype="datetime64[ns]"), "market_cap": []})
        else:
            df = df[["date", "marketCap"]].rename(columns={"marketCap": "market_cap"})
            df["date"] = pd.to_datetime(df["date"])
        self._merge_write(path, df, ["date"])

    def dividends(self, sym: str, full: bool) -> None:
        path = self._path("dividends", sym)
        if path.exists() and full:
            return
        rows = self.fmp.get("dividends", symbol=sym, limit=1000) or []
        df = self._dividend_frame(rows)
        if path.exists() and len(df):
            self._merge_write(path, df, ["ex_date"])
        elif not path.exists():
            df.to_parquet(path)

    @staticmethod
    def _dividend_frame(rows: list[dict]) -> pd.DataFrame:
        df = pd.DataFrame(rows)
        if df.empty:
            return pd.DataFrame({"ex_date": pd.Series(dtype="datetime64[ns]"), "amount": []})
        amt = "dividend" if "dividend" in df else "adjDividend"
        df = df.rename(columns={"date": "ex_date", amt: "amount", "paymentDate": "payment_date"})
        # FMP's feed has no cash/scrip flag on most rows; where it gives a type, it is kept as kind.
        typ = next((c for c in ("dividendType", "type") if c in df), None)
        df["kind"] = df[typ].astype(str).str.lower() if typ else "cash"
        if "payment_date" not in df:
            df["payment_date"] = pd.NaT
        df = df[["ex_date", "amount", "payment_date", "kind"]]
        df["ex_date"] = pd.to_datetime(df["ex_date"])
        df["payment_date"] = pd.to_datetime(df["payment_date"], errors="coerce")
        df["amount"] = pd.to_numeric(df["amount"], errors="coerce")
        return df

    def statements(self, sym: str, full: bool) -> None:
        for name, endpoint in STATEMENTS.items():
            for period in ("annual", "quarter"):
                path = self._path(f"{name}_{period}", sym)
                if path.exists() and full:
                    continue
                limit = 1000 if (full or not path.exists()) else 4
                rows = self.fmp.get(endpoint, symbol=sym, period=period, limit=limit) or []
                df = pd.DataFrame(rows)
                if df.empty:
                    if not path.exists():
                        pd.DataFrame({"date": pd.Series(dtype="datetime64[ns]")}).to_parquet(path)
                    continue
                df["date"] = pd.to_datetime(df["date"])
                for c in df.columns:
                    if df[c].dtype == object and c not in ("symbol", "period", "reportedCurrency",
                                                          "cik", "filingDate", "acceptedDate",
                                                          "fiscalYear", "link", "finalLink"):
                        df[c] = pd.to_numeric(df[c], errors="coerce")
                df = df.astype({c: "string" for c in df.columns if df[c].dtype == object})
                self._merge_write(path, df, ["date", "period"] if "period" in df else ["date"])

    def fx(self, currencies: set[str], full: bool, end: str) -> None:
        path = self.data / "fx.parquet"
        old = pd.read_parquet(path) if path.exists() else pd.DataFrame(columns=["date", "currency", "gbp_per_unit"])
        frames = [old]
        for cur in sorted(c for c in currencies if c and c not in ("GBP", "GBp", "GBX")):
            have = old[old["currency"] == cur]
            start = "1999-01-01" if have.empty or full else (pd.Timestamp(have["date"].max()) + pd.Timedelta(days=1)).date().isoformat()
            if start > end:
                continue
            rows = []
            for a, b in self._chunks(start, end):
                rows += self.fmp.get("historical-price-eod/light", symbol=f"{cur}GBP", **{"from": a, "to": b}) or []
            if rows:
                f = pd.DataFrame(rows)
                col = "price" if "price" in f else "close"
                frames.append(pd.DataFrame({"date": pd.to_datetime(f["date"]), "currency": cur,
                                            "gbp_per_unit": pd.to_numeric(f[col])}))
        out = pd.concat(frames, ignore_index=True)
        out["date"] = pd.to_datetime(out["date"])
        out.drop_duplicates(["date", "currency"], keep="last").sort_values(["currency", "date"]).to_parquet(path)

    # ------------------------------------------------------------ the nightly top-up
    def _load_state(self) -> dict:
        p = self.raw / STATE
        st = json.loads(p.read_text()) if p.exists() else {}
        for k in ("prices_to", "history_at", "statements_due", "dividends_due"):
            st.setdefault(k, {})
        return st

    def _save_state(self, st: dict) -> None:
        tmp = self.raw / (STATE + ".tmp")
        tmp.write_text(json.dumps(st))
        tmp.replace(self.raw / STATE)

    def _opt(self, name: str, default):
        return self.cfg["fmp"].get(name, default)

    def _last(self, dataset: str, sym: str):
        """(last date, that row) of a per-symbol file; (None, None) if there is no file or it is empty."""
        path = self.raw / dataset / f"{sym}.parquet"
        if not path.exists():
            return None, None
        df = pd.read_parquet(path)
        if df.empty or df["date"].isna().all():
            return None, None
        row = df.loc[df["date"].idxmax()]
        return _day(row["date"]), row

    def _ex_dates(self, sym: str) -> list[str]:
        path = self.raw / "dividends" / f"{sym}.parquet"
        if not path.exists():
            return []
        d = pd.read_parquet(path, columns=["ex_date"])["ex_date"].dropna()
        return [_day(x) for x in d]

    def top_up_dividends(self, syms: list[str], end: str, st: dict) -> None:
        """New dividends: the calendar (one call), and each symbol's own list when it is due.

        The calendar only adds ex-dates a symbol does not hold; the symbol's own list, which is
        FMP's record of it, also corrects the ones held. A newly seen ex-date that has passed puts
        the symbol's price history back on the list to be read again (history_at).
        """
        every = int(self._opt("dividends_refresh_days", 7))
        due, hist = st["dividends_due"], st["history_at"]
        want = set(syms)
        before = {s: set(self._ex_dates(s)) for s in syms}
        a = (pd.Timestamp(end) - pd.Timedelta(days=14)).date().isoformat()
        b = (pd.Timestamp(end) + pd.Timedelta(days=75)).date().isoformat()
        rows = self.fmp.get("dividends-calendar", **{"from": a, "to": b}) or []
        by: dict[str, list] = {}
        for r in rows:
            if isinstance(r, dict) and r.get("symbol") in want and r.get("date"):
                by.setdefault(r["symbol"], []).append(r)
        for sym, rs in by.items():
            path = self._path("dividends", sym)
            if not path.exists():
                continue
            new = self._dividend_frame(rs)
            new = new[~new["ex_date"].map(_day).isin(before[sym]) & new["amount"].notna()]
            if len(new):
                self._merge_write(path, new, ["ex_date"])
        for s in syms:
            if s not in due and (self.raw / "dividends" / f"{s}.parquet").exists():
                due[s] = _day(pd.Timestamp(end) + pd.Timedelta(days=1 + _spread(s, every)))
        todo = [s for s in syms if due.get(s, "") <= end]

        def one(sym):
            self.dividends(sym, False)
            due[sym] = _day(pd.Timestamp(end) + pd.Timedelta(days=every))

        self._each(one, todo, "dividends")
        for s in syms:
            passed = [x for x in set(self._ex_dates(s)) - before[s] if x <= end]
            if passed and before[s]:
                back = _day(pd.Timestamp(min(passed)) - pd.Timedelta(days=1))
                hist[s] = min(hist.get(s, back), back)
        self.topup.update({"dividends_asked": len(todo)})

    def _quotes(self, syms: list[str]) -> dict[str, dict]:
        """symbol -> its batch-quote row (price, change, volume; the latest price only)."""
        size = int(self._opt("batch_symbols", 50))
        out: dict[str, dict] = {}

        def lot(chunk):
            for r in self.fmp.get("batch-quote", symbols=",".join(chunk)) or []:
                if isinstance(r, dict) and r.get("symbol") in chunk:
                    out[r["symbol"]] = r

        failed = len(self.failures)
        self._each(lot, [tuple(syms[k:k + size]) for k in range(0, len(syms), size)], "batch-quote")
        if len(self.failures) > failed:         # a lot that failed is not a failed symbol: no quotes tonight
            why = self.failures[-1]["error"]
            del self.failures[failed:]
            raise FmpError(why)
        return out

    def top_up_prices(self, syms: list[str], end: str, st: dict, ends: dict | None = None,
                      start: str | None = None) -> dict:
        """Prices for `syms` up to `end` (a symbol's own end in `ends`, for a delisted one).

        A few symbols' own history says which trading days are new since the last day held. One
        new day: every symbol's close for it from batch-quote (the latest price, which after the
        close is the day's close; checked against those few symbols' own close). More than one: a
        night was missed, and every symbol's own history fills the days, so there is never a gap.

        Returns {quotes, data_to, anchor, new_data_days, new_days: {symbol: [days added from the
        quotes]}, before: {symbol: last day held before}, after: {symbol: last day held now}}.
        """
        ends = ends or {}
        prices_to, hist = st["prices_to"], st["history_at"]
        held = {}
        for s in syms:
            d, row = self._last("prices", s)
            if d is not None:
                held[s] = (d, _num(row["close"]), _num(row.get("adj_close")), _num(row.get("volume")))
        exists = {s for s in syms if (self.raw / "prices" / f"{s}.parquet").exists()}
        empty = [s for s in syms if s in exists and s not in held]      # FMP had nothing when asked
        anchor = pd.Series([h[0] for h in held.values()]).mode().max() if held else None
        quotes, why, new_data_days, probe_close = None, "nothing held yet", [], {}
        if anchor is not None:
            # Which trading days are new: the own history of a few symbols that traded on the last day held.
            pool = sorted((s for s in held if held[s][0] == anchor),
                          key=lambda s: hashlib.sha256(f"{end}{s}".encode()).hexdigest())
            seen: set[str] = set()
            for s in pool[:PROBES]:
                try:
                    r = self.fmp.get("historical-price-eod/full", symbol=s, **{"from": anchor, "to": end}) or []
                except FmpRestricted:
                    raise
                except FmpError as e:
                    log.warning("prices: %s's own history not read (%s)", s, mask(e))
                    continue
                r = r if isinstance(r, list) else r.get("historical", [])
                own_days = {str(x["date"])[:10]: _num(x.get("close")) for x in r
                            if pd.Timestamp(str(x["date"])[:10]).day_of_week < 5}
                seen |= {d for d in own_days if d > anchor}
                probe_close[s] = own_days
            new_data_days = sorted(seen)
            if not probe_close:
                why = "no symbol's own history could be read to say which trading days are new"
            elif len(new_data_days) > 1:
                why = f"{len(new_data_days)} trading days since {anchor} (a night was missed): filled from each symbol's history"
            else:
                quotes, why = {}, None
        eligible = [s for s in held if prices_to.get(s, held[s][0]) >= anchor] if quotes is not None else []
        day_new = new_data_days[0] if quotes is not None and new_data_days else None
        if day_new is not None:
            try:
                quotes = self._quotes(eligible + empty)
            except FmpRestricted:
                raise
            except FmpError as e:
                quotes, why = None, f"batch-quote not read ({mask(e)})"
            # After the close the quote is the day's close: it has to be, for the symbols whose own history was read.
            for s, own_days in probe_close.items() if quotes is not None else []:
                q = _num((quotes.get(s) or {}).get("price"))
                if day_new in own_days and not _same_close(q, own_days[day_new]):
                    quotes, why = None, (f"the quote for {s} ({q}) is not its close for {day_new} "
                                         f"({own_days[day_new]}): the quotes are not the day's closes yet")
                    break
        if quotes is None and anchor is not None:
            log.warning("prices: asking per symbol tonight: %s", why)
            eligible = []

        end_of = lambda s: ends.get(s, end)                                 # noqa: E731
        rebase = []         # read the whole history again
        behind = []         # the symbol's own top-up call (new, behind, or a missed day)
        new_days: dict[str, list[str]] = {}
        covered: list[str] = []
        pending: dict[str, pd.DataFrame] = {}
        moved: list[str] = []       # the quote's previous close is not the close held: a split or a correction
        for s in syms:
            if s in held:
                last = held[s][0]
                at = hist.get(s) or _day(pd.Timestamp(last) - pd.Timedelta(days=7))
                if any(at < x <= end_of(s) for x in self._ex_dates(s)):
                    rebase.append(s)
                elif s not in eligible:
                    behind.append(s)
                else:
                    covered.append(s)
                    q = quotes.get(s) if day_new is not None and day_new > last else None
                    price, volume = _num((q or {}).get("price")), _num((q or {}).get("volume"))
                    if q is None or not np.isfinite(price) or price <= 0 or not _traded(q, day_new, held[s]):
                        continue            # no trade that day: no row, as its own history has none
                    change = _num(q.get("change"))
                    if np.isfinite(change) and abs((price - change) - held[s][1]) > PREVIOUS_CLOSE_TOLERANCE * held[s][1]:
                        moved.append(s)
                    ratio = held[s][2] / held[s][1] if held[s][1] else float("nan")
                    df = pd.DataFrame({"date": pd.to_datetime([day_new]), "close": [price], "volume": [volume]})
                    df["adj_close"] = df["close"] * ratio if np.isfinite(ratio) else pd.NA
                    pending[s] = df
            elif s in empty:
                # nothing from FMP so far: asked again only when a quote dated that day names it
                q = (quotes or {}).get(s)
                if day_new is not None and q and q.get("timestamp") and _traded(q, day_new, None):
                    (self.raw / "prices" / f"{s}.parquet").unlink()
                    behind.append(s)
            else:
                behind.append(s)
        if len(moved) > max(MOVED_MIN, MOVED_SHARE * max(1, len(pending))):
            log.warning("prices: the quotes' previous close disagrees with the close held for %d of %d symbols; "
                        "taken as the quotes' doing, not %d corrections", len(moved), len(pending), len(moved))
            moved = []
        for s, df in pending.items():
            if s in moved:
                rebase.append(s)
                covered.remove(s)
            else:
                self._merge_write(self._path("prices", s), df, ["date"])
                new_days[s] = [day_new]
        for s in covered:
            hist[s] = new_days.get(s, [held[s][0]])[-1]
        data_to = (day_new or anchor) if quotes is not None else None
        after = {s: new_days.get(s, [held[s][0]])[-1] for s in held}

        def own(sym, whole):
            if whole:
                self.history(sym, end_of(sym), start)
            else:
                self.prices(sym, False, end_of(sym), start)
            last, _ = self._last("prices", sym)
            if last is not None:
                after[sym] = last
                hist[sym] = last        # every ex-date up to here is in the adjusted series held
                prices_to[sym] = max(last, data_to or last)

        self._each(lambda s: own(s, True), rebase, "price history")
        self._each(lambda s: own(s, False), behind, "prices")
        for s in covered:
            prices_to[s] = data_to
        info = {"quotes": quotes is not None, "anchor": anchor, "data_to": data_to,
                "new_data_days": new_data_days if quotes is not None else [],
                "new_days": new_days, "before": {s: h[0] for s, h in held.items()}, "after": after}
        self.topup.update({"prices_from_quotes": info["quotes"], "why_per_symbol": why, "new_trading_days": new_data_days,
                           "price_histories_read_again": len(rebase), "prices_asked_per_symbol": len(behind),
                           "symbols_with_new_prices_from_quotes": len(new_days)})
        log.info("prices: new trading days since %s: %s; %d symbols from batch-quote, %d histories read again, "
                 "%d asked per symbol", anchor, ", ".join(new_data_days) or "none", len(new_days), len(rebase), len(behind))
        return info

    def top_up_caps(self, syms: list[str], trusts: list[str], end: str, info: dict, ends: dict | None = None) -> None:
        """Market cap up to each symbol's last price day (a trust: the last trading day).

        One new day since the cap held: the batch call gives it. Anything else (a missed day, a new
        symbol, a batch row dated another day): the symbol's own history call, so no day is left out.
        """
        ends = ends or {}
        target = {s: info["after"].get(s) for s in syms}
        target.update({s: info["data_to"] for s in trusts})
        one_new_day = info["new_data_days"][0] if len(info["new_data_days"]) == 1 else None
        own, batch = [], []
        for s in list(syms) + list(trusts):
            path = self.raw / "market_cap" / f"{s}.parquet"
            last, _ = self._last("market_cap", s)
            if not path.exists() or not info["quotes"]:
                own.append(s)
            elif last is None or target[s] is None or last >= target[s]:
                continue                    # FMP has no cap for it, or it is up to date
            elif (info["new_days"].get(s) == [target[s]] and last == info["before"].get(s)) or \
                    (s in trusts and target[s] == one_new_day and last == info["anchor"]):
                batch.append(s)
            else:
                own.append(s)
        size = int(self._opt("batch_symbols", 50))
        got: set[str] = set()

        def lot(chunk):
            for r in self.fmp.get("market-capitalization-batch", symbols=",".join(chunk)) or []:
                s, cap = r.get("symbol"), _num(r.get("marketCap"))
                if s in chunk and str(r.get("date"))[:10] == target[s] and np.isfinite(cap) and cap > 0:
                    self._merge_write(self._path("market_cap", s),
                                      pd.DataFrame({"date": pd.to_datetime([target[s]]), "market_cap": [cap]}), ["date"])
                    got.add(s)

        failures = len(self.failures)
        self._each(lot, [tuple(batch[k:k + size]) for k in range(0, len(batch), size)], "market cap batch")
        if len(self.failures) > failures:       # a lot lost to the network is not a failed symbol: asked singly
            log.warning("market cap batch not read (%s)", self.failures[-1]["error"])
            del self.failures[failures:]
        own += [s for s in batch if s not in got]
        self._each(lambda s: self.market_cap(s, False, ends.get(s, end)), own, "market cap")
        self.topup.update({"caps_from_batch": len(got), "caps_asked_per_symbol": len(own)})

    def top_up_statements(self, syms: list[str], end: str, st: dict) -> None:
        """Statements once every fmp.statements_refresh_days, each symbol on its own night of them
        (and at once for one with a file missing)."""
        every = int(self._opt("statements_refresh_days", 7))
        due = st["statements_due"]
        todo = []
        for s in syms:
            if s not in due:
                due[s] = _day(pd.Timestamp(end) + pd.Timedelta(days=1 + _spread(s, every)))
            missing = any(not (self.raw / d / f"{s}.parquet").exists() for d in STATEMENT_SETS)
            if missing or due[s] <= end:
                todo.append(s)

        def one(sym):
            self.statements(sym, False)
            due[sym] = _day(pd.Timestamp(end) + pd.Timedelta(days=every))

        self._each(one, todo, "statements")
        self.topup.update({"statements_asked": len(todo)})

    def _symbol(self, sym: str, full: bool, end: str) -> None:
        self.prices(sym, full, end)
        self.market_cap(sym, full, end)
        self.dividends(sym, full)
        self.statements(sym, full)

    def _complete(self, sym: str) -> bool:
        return all((self.raw / d / f"{sym}.parquet").exists()
                   for d in ["prices", "market_cap", "dividends"] + STATEMENT_SETS)

    def top_up(self, master: pd.DataFrame, todo: list[str], end: str, trusts: bool) -> None:
        delisted = master.set_index("symbol")["delisted_date"].reindex(todo)
        ends = {s: min(end, str(d)[:10]) for s, d in delisted.items() if pd.notna(d)}
        # A delisted company has nothing new once its files are all there; one that is missing a
        # file gets just that file.
        unfinished = [s for s in ends if not self._complete(s)]
        active = [s for s in todo if s not in ends]
        self._each(lambda s: self._symbol(s, True, ends[s]), unfinished, "symbol")
        st = self._load_state()
        self.top_up_dividends(active, end, st)
        info = self.top_up_prices(active, end, st)
        trust_syms = []
        if trusts:
            sel = master[master["exclude_reason"].isin(TRUST_REASONS)]
            trust_syms = sel["symbol"].tolist()
            ends.update({r.symbol: min(end, str(r.delisted_date)[:10]) for r in sel.itertuples(index=False)
                         if pd.notna(r.delisted_date)})
        self.top_up_caps(active, trust_syms, end, info, ends)
        self.top_up_statements(active, end, st)
        self._save_state(st)
        self.topup.update({"active": len(active), "delisted_left_alone": len(ends) - len(unfinished)})
        log.info("top-up: %s", json.dumps(self.topup))

    # ------------------------------------------------------------ trusts
    def trust_caps(self, master: pd.DataFrame, full: bool, end: str) -> int:
        """Market cap only, for investment trusts and funds.

        The FTSE 350 is ranked with its trusts in it; the universe takes the
        top 350 that way and then drops the trusts (universe.rank_with_trusts).
        Only their size is needed, so only their market cap is fetched.
        """
        sel = master[master["exclude_reason"].isin(TRUST_REASONS)]
        log.info("trust market caps: %d lines", len(sel))
        ends = {r.symbol: end if pd.isna(r.delisted_date) else min(end, str(r.delisted_date)[:10])
                for r in sel.itertuples(index=False)}
        failed = len(self.failures)
        self._each(lambda s: self.market_cap(s, full, ends[s]), list(ends), "trust_cap")
        return len(ends) - (len(self.failures) - failed)

    # ------------------------------------------------------------ the run
    def run(self, full: bool = False, symbols: list[str] | None = None, merge_only: bool = False,
            trusts: bool = True) -> dict:
        """Download, then merge. Safe to start again: per-symbol files that exist are not re-fetched
        on a full pull, and when fetch_state.json says the downloads finished, a re-run (or
        merge_only) goes straight to the merge without one call."""
        end = _today()
        state_path = self.data / "fetch_state.json"
        state = json.loads(state_path.read_text()) if state_path.exists() else {}
        master_path = self.data / "symbols.parquet"
        # A run that died in the merge left done == of without the flag (18 Nov 2026).
        finished = state.get("downloaded") or (state.get("done") and state.get("done") == state.get("of"))
        downloaded = bool(finished) and master_path.exists() and (full or merge_only)
        if merge_only and not master_path.exists():
            raise RuntimeError("nothing to merge: no symbols.parquet yet")
        if downloaded or merge_only:
            log.info("downloads already complete (%s); merging only", state.get("downloaded_at", "?"))
            master = pd.read_parquet(master_path)
            todo = symbols or master.loc[master["included"], "symbol"].tolist()
        else:
            master = self.build_master(full)
            todo = symbols or master.loc[master["included"], "symbol"].tolist()
            log.info("fetching %d symbols (%s)", len(todo), "full" if full else "top-up")
            if full:
                delisted = master.set_index("symbol")["delisted_date"].reindex(todo)
                ends = {s: min(end, str(d)[:10]) for s, d in delisted.items() if pd.notna(d)}
                self._each(lambda s: self._symbol(s, True, ends.get(s, end)), todo, "symbol")
            else:
                self.top_up(master, todo, end, trusts=trusts and not symbols)
            state_path.write_text(json.dumps({"downloaded": True, "downloaded_at": dt.datetime.now().isoformat(),
                                              "of": len(todo), "calls": self.fmp.calls,
                                              "failures": len(self.failures)}))
        if trusts and not merge_only and not symbols and (full or downloaded):
            self.trust_caps(master, full, end)
        log.info("merging per-symbol files")
        consolidate(self.data)
        st = self.data / "statements.parquet"
        stmt_ccy = set()
        if st.exists():
            stmt_ccy = set(pd.read_parquet(st, columns=["reportedCurrency"])["reportedCurrency"].dropna())
        quote_ccy = set(master["currency"].dropna())
        # FX before the master pass: it needs the rates to tell a pence quote from a dollar one.
        self.fx(stmt_ccy | quote_ccy, False, end)
        from .master import refine_master
        from .clean import clean_prices
        refine_master(self.data)
        clean_prices(self.data)
        from .clean import apply_cap_overrides, rescue_refused_caps
        rescued = rescue_refused_caps(self.data)
        overridden = apply_cap_overrides(self.data)
        log.info("market cap: %d refused segments rescaled because price x shares agrees; %d override rows applied",
                 len(rescued), len(overridden))
        if self.failures:
            pd.DataFrame(self.failures).to_parquet(self.data / "fetch_failures.parquet")
        # A top-up leaves the next run free to download again; a full pull stays marked as done.
        done = {"downloaded": bool(full or downloaded or merge_only),
                "downloaded_at": state.get("downloaded_at") if (downloaded or merge_only) else dt.datetime.now().isoformat(),
                "merged_at": dt.datetime.now().isoformat(), "of": len(todo),
                "calls": self.fmp.calls, "failures": len(self.failures)}
        state_path.write_text(json.dumps(done))
        return {"symbols": len(todo), "calls": self.fmp.calls, "failures": len(self.failures), **self.topup}


def _is_aim(row) -> bool | None:
    text = " ".join(str(row.get(k) or "") for k in ("exchange_full", "exchange"))
    if re.search(r"\baim\b|alternative investment", text, re.I):
        return True
    return False


def exclusion_reason(row) -> str | None:
    sym = str(row["symbol"])
    name = str(row.get("name") or "")
    if IOB.match(sym):
        return "international order book line (0xxx.L)"
    if LOAN_STOCK.match(sym):
        return "loan stock or bond line (99XX.L)"
    if row.get("is_etf") is True:
        return "FMP isEtf"
    if row.get("is_fund") is True:
        return "FMP isFund"
    if str(row.get("sector") or "") in FUND_SECTORS or str(row.get("industry") or "") in FUND_SECTORS:
        return "fund sector"
    if FUND_NAME.search(name):
        return "fund or trust by name"
    if PREF_NAME.search(name):
        return "preference share or debt line by name"
    if DEPOSITARY.search(name) or row.get("is_adr") is True:
        return "depositary receipt"
    return None


def consolidate(data: Path) -> None:
    """Fold the per-symbol files into one Parquet file per dataset."""
    raw = data / "raw"

    def gather(dataset: str) -> pd.DataFrame:
        frames = []
        d = raw / dataset
        if not d.exists():
            return pd.DataFrame()
        for f in sorted(d.glob("*.parquet")):
            df = pd.read_parquet(f)
            if len(df):
                # FMP's own rows often carry a symbol column already: set, never insert.
                df["symbol"] = f.stem
                frames.append(df[["symbol"] + [c for c in df.columns if c != "symbol"]])
        return pd.concat(frames, ignore_index=True) if frames else pd.DataFrame()

    p = gather("prices")
    if len(p):
        p["adj_close"] = pd.to_numeric(p["adj_close"], errors="coerce")
        if "volume" not in p:
            p["volume"] = np.nan
        p[["symbol", "date", "close", "adj_close", "volume"]].to_parquet(data / "prices.parquet")
    m = gather("market_cap")
    if len(m):
        # Pence read as pounds for part of a series: rescale here, at the source,
        # so nothing downstream sees a cap 100 times too big.
        from .clean import clean_market_caps
        m["date"] = pd.to_datetime(m["date"])
        m, rescaled, refused, spikes = clean_market_caps(m[["symbol", "date", "market_cap"]])
        rescaled.to_parquet(data / "cap_rescaled.parquet")
        refused.to_parquet(data / "cap_rescale_refused.parquet")
        spikes.to_parquet(data / "cap_spikes.parquet")
        log.info("market cap: %d symbols, %d rows rescaled by 100 (pence read as pounds); %d rescales refused; "
                 "%d one-day spikes replaced", len(rescaled), int(rescaled["rows"].sum()) if len(rescaled) else 0,
                 len(refused), len(spikes))
        m.to_parquet(data / "market_cap.parquet")
    d = gather("dividends")
    if len(d):
        d.to_parquet(data / "dividends.parquet")

    parts = []
    for name in STATEMENTS:
        for period in ("annual", "quarter"):
            df = gather(f"{name}_{period}")
            if df.empty or "date" not in df:
                continue
            if "symbol" in df.columns[1:]:
                df = df.loc[:, ~df.columns.duplicated()]
            df["freq"] = "annual" if period == "annual" else "interim"
            df["statement"] = name
            parts.append(df)
    if parts:
        st = merge_statements(parts)
        st.to_parquet(data / "statements.parquet")


def merge_statements(parts: list[pd.DataFrame]) -> pd.DataFrame:
    """One row per symbol, period end and frequency, the three statements side by side.

    A field on two statements (netIncome is on income and cash flow) keeps the
    first non-empty value.
    """
    drop = {"date", "statement", "cik", "link", "finalLink", "acceptedDate", "fillingDate", "filingDate"}
    frames = []
    for df in parts:
        df = df.copy()
        df["period_end"] = pd.to_datetime(df["date"])
        frames.append(df[[c for c in df.columns if c not in drop]])
    out = pd.concat(frames, ignore_index=True)
    out = out.groupby(["symbol", "period_end", "freq"], as_index=False).first()
    return out.sort_values(["symbol", "period_end", "freq"]).reset_index(drop=True)


def main_fetch(cfg: Config, full: bool) -> None:
    logging.basicConfig(stream=sys.stdout, level=logging.INFO, format="%(asctime)s %(message)s")
    summary = Fetcher(cfg).run(full=full)
    log.info("fetch done: %s", summary)
