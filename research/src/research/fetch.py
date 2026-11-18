"""Download from FMP into Parquet, resumably.

Layout under the data folder:

    raw/<dataset>/<SYMBOL>.parquet   one file per symbol per dataset, the unit
                                     of resume: a file that exists is done
    raw/profiles.parquet             one row per symbol
    symbols.parquet                  the symbol master, with exclusions
    prices.parquet                   symbol, date, close (raw, pence), adj_close
    market_cap.parquet               symbol, date, market_cap (pounds)
    dividends.parquet                symbol, ex_date, amount (pence)
    statements.parquet               one row per symbol, period, statement fields
    fx.parquet                       date, currency, gbp_per_unit

A full pull writes every per-symbol file it does not already have, so an
interrupted run started again carries on where it stopped. `fetch` without
--full tops up: prices and market cap from the day after the last stored
date, and the latest few statements, merged over what is held.
"""
from __future__ import annotations

import datetime as dt
import json
import logging
import re
import sys
from pathlib import Path

import pandas as pd

from .config import Config, mask
from .fmp import Fmp, FmpError

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

STATEMENTS = {
    "income": "income-statement",
    "balance": "balance-sheet-statement",
    "cashflow": "cash-flow-statement",
}


def _today() -> str:
    return dt.date.today().isoformat()


class Fetcher:
    def __init__(self, cfg: Config, client: Fmp | None = None):
        self.cfg = cfg
        self.data = cfg.data_dir
        self.raw = self.data / "raw"
        self.raw.mkdir(parents=True, exist_ok=True)
        self.fmp = client or Fmp(cfg)
        self.failures: list[dict] = []

    # ------------------------------------------------------------ symbols
    def list_symbols(self) -> pd.DataFrame:
        listed = self.fmp.get("stock-list") or []
        rows = [{"symbol": r["symbol"], "name": r.get("companyName") or r.get("name"),
                 "delisted_date": None} for r in listed if str(r.get("symbol", "")).endswith(".L")]
        page = 0
        while True:
            batch = self.fmp.get("delisted-companies", page=page, limit=100) or []
            if not batch:
                break
            for r in batch:
                sym = str(r.get("symbol", ""))
                if sym.endswith(".L") or str(r.get("exchange", "")).upper() in ("LSE", "LON"):
                    if not sym.endswith(".L"):
                        continue
                    rows.append({"symbol": sym, "name": r.get("companyName"),
                                 "delisted_date": r.get("delistedDate"),
                                 "ipo_date": r.get("ipoDate")})
            page += 1
            if page > 500:
                break
        df = pd.DataFrame(rows)
        # A symbol both listed and delisted (ticker reused) keeps the listed row
        # and the delisting date of the earlier company is recorded separately.
        df = df.sort_values("delisted_date", na_position="first").drop_duplicates("symbol")
        return df.reset_index(drop=True)

    def profiles(self, symbols: list[str]) -> pd.DataFrame:
        path = self.raw / "profiles.parquet"
        have = pd.read_parquet(path) if path.exists() else pd.DataFrame(columns=["symbol"])
        todo = [s for s in symbols if s not in set(have["symbol"])]
        rows = []
        for i, sym in enumerate(todo):
            try:
                data = self.fmp.get("profile", symbol=sym)
            except FmpError as e:
                self.failures.append({"symbol": sym, "dataset": "profile", "error": str(e)})
                continue
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
            })
            if rows and (len(rows) % 200 == 0 or i == len(todo) - 1):
                have = pd.concat([have, pd.DataFrame(rows)], ignore_index=True)
                have.to_parquet(path)
                rows = []
        if rows:
            have = pd.concat([have, pd.DataFrame(rows)], ignore_index=True)
            have.to_parquet(path)
        return have

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

    def prices(self, sym: str, full: bool, end: str) -> None:
        path = self._path("prices", sym)
        start = self.cfg["fmp"]["price_start"]
        if path.exists():
            if full:
                return
            last = pd.read_parquet(path, columns=["date"])["date"].max()
            start = (pd.Timestamp(last) + pd.Timedelta(days=1)).date().isoformat()
            if start > end:
                return
        raw, adj = [], []
        for a, b in self._chunks(start, end):
            r = self.fmp.get("historical-price-eod/full", symbol=sym, **{"from": a, "to": b}) or []
            raw += r if isinstance(r, list) else r.get("historical", [])
            j = self.fmp.get("historical-price-eod/dividend-adjusted", symbol=sym, **{"from": a, "to": b}) or []
            adj += j if isinstance(j, list) else j.get("historical", [])
        df = pd.DataFrame(raw)
        if df.empty:
            if not path.exists():
                pd.DataFrame({"date": pd.Series(dtype="datetime64[ns]"), "close": [], "adj_close": [],
                              "volume": []}).to_parquet(path)
            return
        df = df[["date", "close"] + (["volume"] if "volume" in df else [])]
        if adj:
            a = pd.DataFrame(adj)
            col = "adjClose" if "adjClose" in a else "close"
            df = df.merge(a[["date", col]].rename(columns={col: "adj_close"}), on="date", how="left")
        else:
            df["adj_close"] = pd.NA
        df["date"] = pd.to_datetime(df["date"])
        self._merge_write(path, df, ["date"])

    def market_cap(self, sym: str, full: bool, end: str) -> None:
        path = self._path("market_cap", sym)
        start = self.cfg["fmp"]["market_cap_start"]
        if path.exists():
            if full:
                return
            last = pd.read_parquet(path, columns=["date"])["date"].max()
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
        df = pd.DataFrame(rows)
        if df.empty:
            df = pd.DataFrame({"ex_date": pd.Series(dtype="datetime64[ns]"), "amount": []})
        else:
            amt = "dividend" if "dividend" in df else "adjDividend"
            df = df.rename(columns={"date": "ex_date", amt: "amount"})[["ex_date", "amount"]]
            df["ex_date"] = pd.to_datetime(df["ex_date"])
        if path.exists() and len(df):
            self._merge_write(path, df, ["ex_date"])
        elif not path.exists():
            df.to_parquet(path)

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

    # ------------------------------------------------------------ the run
    def run(self, full: bool = False, symbols: list[str] | None = None) -> dict:
        end = _today()
        master = self.build_master(full)
        todo = symbols or master.loc[master["included"], "symbol"].tolist()
        state_path = self.data / "fetch_state.json"
        log.info("fetching %d symbols (%s)", len(todo), "full" if full else "top-up")
        for i, sym in enumerate(todo, 1):
            delisted = master.loc[master["symbol"] == sym, "delisted_date"]
            sym_end = end
            if len(delisted) and pd.notna(delisted.iloc[0]):
                sym_end = min(end, str(delisted.iloc[0])[:10])
                # A delisted company has nothing new to top up once fetched.
                if not full and self._path("prices", sym).exists():
                    continue
            try:
                self.prices(sym, full, sym_end)
                self.market_cap(sym, full, sym_end)
                self.dividends(sym, full)
                self.statements(sym, full)
            except FmpError as e:
                self.failures.append({"symbol": sym, "dataset": "symbol", "error": str(e)})
                log.warning("%s: %s", sym, mask(e))
            if i % 25 == 0:
                state_path.write_text(json.dumps({"done": i, "of": len(todo), "last": sym,
                                                  "calls": self.fmp.calls, "at": dt.datetime.now().isoformat()}))
                log.info("%d/%d %s (%d calls)", i, len(todo), sym, self.fmp.calls)
        stmt_ccy = set()
        consolidate(self.data)
        st = self.data / "statements.parquet"
        if st.exists():
            stmt_ccy = set(pd.read_parquet(st, columns=["reportedCurrency"])["reportedCurrency"].dropna())
        quote_ccy = set(master["currency"].dropna())
        self.fx(stmt_ccy | quote_ccy, full, end)
        if self.failures:
            pd.DataFrame(self.failures).to_parquet(self.data / "fetch_failures.parquet")
        state_path.write_text(json.dumps({"done": len(todo), "of": len(todo), "calls": self.fmp.calls,
                                          "finished": dt.datetime.now().isoformat(),
                                          "failures": len(self.failures)}))
        return {"symbols": len(todo), "calls": self.fmp.calls, "failures": len(self.failures)}


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
                df.insert(0, "symbol", f.stem)
                frames.append(df)
        return pd.concat(frames, ignore_index=True) if frames else pd.DataFrame()

    p = gather("prices")
    if len(p):
        p["adj_close"] = pd.to_numeric(p["adj_close"], errors="coerce")
        p[["symbol", "date", "close", "adj_close"]].to_parquet(data / "prices.parquet")
    m = gather("market_cap")
    if len(m):
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
