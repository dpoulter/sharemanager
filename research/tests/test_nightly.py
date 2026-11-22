"""The nightly run in under an hour (brief of 22 Nov 2026, nightly fetch speed): tests 1 to 5.

1. a normal night's calls are a small multiple of the trading days, not of the symbols
2. the pool never goes over the configured calls a minute; a 429 still backs off
3. statements are read again only when due; a new filing is picked up
4. (the close whose answer is lost: test_publish.py)
5. a row fetched again unchanged records no change; one changed company rebuilds only itself
"""
from __future__ import annotations

import threading
import urllib.error
from collections import Counter

import numpy as np
import pandas as pd
import pytest

from research import data_version as dv
from research import fetch as fetch_mod
from research import indicator_job
from research.fetch import Fetcher, FmpError
from research.fmp import Fmp
from research.indicator_store import IndicatorStore
from research.store import load_market
from research.universe import build_universe

DAYS = pd.bdate_range("2025-06-02", "2026-12-31")
T0 = "2026-11-18"          # the full pull
STATEMENT_PATHS = ("income-statement", "balance-sheet-statement", "cash-flow-statement")


def day(n: int) -> str:
    """The n-th business day after T0."""
    return str(DAYS[DAYS.get_loc(pd.Timestamp(T0)) + n].date())


class World:
    """FMP as the fetch sees it, in memory: what it holds on `today`."""

    def __init__(self, n=8):
        rng = np.random.default_rng(5)
        self.syms = [f"C{i:02d}.L" for i in range(n)]
        self.px = {s: np.round(300 * np.cumprod(1 + rng.normal(0.0004, 0.01, len(DAYS))), 4) for s in self.syms}
        self.dividends = {s: [(str(DAYS[60].date()), 4.0)] for s in self.syms}
        self.assets = {s: {2023: 2.0e9, 2024: 2.1e9, 2025: 2.2e9} for s in self.syms}
        self.filings: list[dict] = []
        self.today = T0
        self.refuse: set[str] = set()
        self.bulk_scale = 1.0
        self.halted: dict[str, str] = {}        # symbol -> its last day traded

    def held(self, s):
        return DAYS <= pd.Timestamp(min(self.today, self.halted.get(s, self.today)))

    def adj(self, s) -> np.ndarray:
        f = np.ones(len(DAYS))
        for ex, amount in self.dividends[s]:
            i = DAYS.get_loc(pd.Timestamp(ex))
            if ex <= self.today:
                f[:i] *= 1 - amount / self.px[s][i - 1]
        return self.px[s] * f


class StubFmp:
    """Counts every call by path. Thread-safe, and run a few at a time, as the real client is."""
    concurrency = 4
    throttled = 0

    def __init__(self, world: World):
        self.w = world
        self.by_path: Counter = Counter()
        self.asked: list[tuple] = []
        self._lock = threading.Lock()

    @property
    def calls(self):
        return sum(self.by_path.values())

    def get(self, path, **p):
        with self._lock:
            self.by_path[path] += 1
            self.asked.append((path, p.get("symbol")))
        w = self.w
        if path in w.refuse:
            raise FmpError(f"HTTP 402 on {path}: not in your plan")
        sym = p.get("symbol")
        if path == "stock-list":
            return [{"symbol": s, "companyName": f"{s} plc"} for s in w.syms] + \
                   [{"symbol": "TRU.L", "companyName": "Big Investment Trust plc"}]
        if path == "delisted-companies":
            return [{"symbol": "DED.L", "companyName": "Dead plc", "delistedDate": "2026-03-31"}] if p["page"] == 0 else []
        if path == "profile":
            return [{"symbol": sym, "currency": "GBp", "sector": "Industrials", "exchange": "LSE",
                     "isin": f"GB00B000{sum(map(ord, sym)) % 1000:03d}0", "country": "GB"}]
        if path.startswith("historical-price-eod"):
            if sym not in w.px and sym != "DED.L":
                return []
            s = sym if sym in w.px else w.syms[0]
            sel = (DAYS >= pd.Timestamp(p["from"])) & (DAYS <= pd.Timestamp(p["to"])) & w.held(s)
            if sym == "DED.L":
                sel &= DAYS <= pd.Timestamp("2026-03-31")
            vals = w.adj(s) if path.endswith("dividend-adjusted") else w.px[s]
            return [{"symbol": sym, "date": str(d.date()), "close": float(v), "adjClose": float(v), "volume": 1000}
                    for d, v in zip(DAYS[sel], vals[sel])][::-1]
        if path == "eod-bulk":          # the whole market for one day, as the CSV parses: every value a string
            d = pd.Timestamp(p["date"])
            if d not in DAYS or p["date"] > w.today:
                return []
            i = DAYS.get_loc(d)
            return [{"symbol": s, "date": p["date"], "close": str(w.px[s][i] * w.bulk_scale),
                     "adjClose": str(w.px[s][i]), "volume": "1000"} for s in w.syms
                    if p["date"] <= w.halted.get(s, p["date"])] + \
                   [{"symbol": "TRU.L", "date": p["date"], "close": "500", "volume": "10"},
                    {"symbol": "AAPL", "date": p["date"], "close": "200", "volume": "10"}]
        if path == "historical-market-capitalization":
            sel = (DAYS >= pd.Timestamp(p["from"])) & (DAYS <= pd.Timestamp(p["to"])) & (DAYS <= pd.Timestamp(w.today))
            return [{"symbol": sym, "date": str(d.date()), "marketCap": self._cap(sym, i)}
                    for i, d in enumerate(DAYS) if sel[i]]
        if path == "market-capitalization-batch":
            i = int(np.flatnonzero(DAYS <= pd.Timestamp(w.today))[-1])
            return [{"symbol": s, "date": str(DAYS[i].date()), "marketCap": self._cap(s, i)}
                    for s in p["symbols"].split(",")]
        if path == "dividends":
            return [{"symbol": sym, "date": ex, "dividend": a} for ex, a in w.dividends.get(sym, [])]
        if path == "dividends-calendar":
            return [{"symbol": s, "date": ex, "dividend": a} for s in w.syms for ex, a in w.dividends[s]
                    if p["from"] <= ex <= p["to"]]
        if path in STATEMENT_PATHS:
            if p["period"] != "annual" or sym not in w.assets:
                return []
            return [{"symbol": sym, "date": f"{y}-12-31", "period": "FY", "reportedCurrency": "GBP", "revenue": 1e9,
                     "grossProfit": 4e8, "netIncome": 1e8, "totalAssets": a, "operatingCashFlow": 1.2e8,
                     "weightedAverageShsOut": 1e7, "totalStockholdersEquity": 1e9}
                    for y, a in sorted(w.assets[sym].items(), reverse=True)][:p["limit"]]
        if path == "latest-financial-statements":
            return w.filings if p["page"] == 0 else []
        return []

    def _cap(self, sym, i):
        return 5e8 if sym not in self.w.px else float(self.w.px[sym][i]) * 1e5


def night(cfg, world, today, monkeypatch, full=False, record=True):
    """One `research fetch` on `today`: the stub's calls, what the fetch says it did, the data version record."""
    world.today = today
    monkeypatch.setattr(fetch_mod, "_today", lambda: today)
    stub = StubFmp(world)
    f = Fetcher(cfg, client=stub)
    summary = f.run(full=full)
    assert summary["failures"] == 0, f.failures
    rec = dv.record(cfg, "fetch --full" if full else "fetch", market=load_market(cfg.data_dir), full=full) \
        if record else None
    return stub, summary, rec


@pytest.fixture
def quiet(cfg):
    """Statements and dividends refreshed rarely, so a test sees only what it makes due."""
    cfg.raw["fmp"].update({"statements_refresh_days": 20000, "dividends_refresh_days": 20000})
    cfg.raw["universe"]["start"] = "2025-06-02"
    return cfg


def raw(cfg, dataset, sym):
    return pd.read_parquet(cfg.data_dir / "raw" / dataset / f"{sym}.parquet")


# ------------------------------------------------------------------ 1: calls
@pytest.mark.parametrize("n", [8, 24])
def test_1_a_normal_night_costs_a_few_calls_a_trading_day_whatever_the_number_of_symbols(quiet, monkeypatch, n):
    cfg, w = quiet, World(n)
    night(cfg, w, T0, monkeypatch, full=True)
    night(cfg, w, day(1), monkeypatch)                      # the first top-up sets the state
    stub, summary, rec = night(cfg, w, day(2), monkeypatch)
    trading_days = 1
    assert stub.calls <= 14 + 3 * trading_days, stub.by_path
    assert stub.calls < n or n == 8                         # 24 symbols, fewer calls than symbols
    assert stub.by_path["eod-bulk"] == 2                    # the day held (to compare) and the new one
    for path in ("historical-price-eod/dividend-adjusted", "historical-market-capitalization", "dividends",
                 *STATEMENT_PATHS):
        assert stub.by_path[path] == 0, path
    assert summary["bulk"] and summary["symbols_with_new_prices_from_bulk"] == n
    # what the bulk rows and the batch gave is what the per-symbol calls would have given
    i = DAYS.get_loc(pd.Timestamp(day(2)))
    for s in w.syms:
        p = raw(cfg, "prices", s).set_index("date")
        assert p.index.max() == DAYS[i] and p.index.is_unique
        assert p["close"].iloc[-1] == pytest.approx(w.px[s][i])
        assert p["adj_close"].iloc[-1] == pytest.approx(w.adj(s)[i])
        assert p["volume"].iloc[-1] == 1000
        c = raw(cfg, "market_cap", s).set_index("date")["market_cap"]
        assert c.index.max() == DAYS[i] and c.iloc[-1] == pytest.approx(w.px[s][i] * 1e5)
    assert raw(cfg, "market_cap", "TRU.L")["date"].max() == DAYS[i]        # the trust's cap, from the batch
    assert stub.by_path["historical-price-eod/full"] == fetch_mod.BULK_PROBES


def test_1_a_weekend_and_a_missed_night_cost_a_call_a_day(quiet, monkeypatch):
    cfg, w = quiet, World()
    night(cfg, w, T0, monkeypatch, full=True)
    night(cfg, w, day(1), monkeypatch)
    stub, summary, _ = night(cfg, w, day(5), monkeypatch)       # four trading days and a weekend later
    assert stub.by_path["eod-bulk"] == 5
    p = raw(cfg, "prices", w.syms[0])
    assert list(p["date"].iloc[-5:]) == [pd.Timestamp(day(k)) for k in range(1, 6)]
    # more than one new day: the batch gives only the last, so each cap history is asked for, once
    assert stub.by_path["historical-market-capitalization"] == len(w.syms) + 1
    c = raw(cfg, "market_cap", w.syms[0])
    assert list(c["date"].iloc[-5:]) == [pd.Timestamp(day(k)) for k in range(1, 6)]


def test_1_a_refused_bulk_call_falls_back_to_per_symbol_calls_with_the_same_result(quiet, monkeypatch):
    cfg, w = quiet, World()
    night(cfg, w, T0, monkeypatch, full=True)
    w.refuse = {"eod-bulk", "market-capitalization-batch", "dividends-calendar", "latest-financial-statements"}
    stub, summary, _ = night(cfg, w, day(1), monkeypatch)
    assert summary["bulk"] is False and "refused" in summary["why_not_bulk"]
    i = DAYS.get_loc(pd.Timestamp(day(1)))
    for s in w.syms:
        p = raw(cfg, "prices", s)
        assert p["date"].max() == DAYS[i] and p["close"].iloc[-1] == pytest.approx(w.px[s][i])
        assert raw(cfg, "market_cap", s)["date"].max() == DAYS[i]
    assert stub.by_path["historical-price-eod/full"] == len(w.syms)
    assert stub.by_path["dividends"] == len(w.syms)


def test_1_bulk_rows_that_disagree_with_the_closes_held_are_not_used(quiet, monkeypatch):
    cfg, w = quiet, World()
    night(cfg, w, T0, monkeypatch, full=True)
    w.bulk_scale = 0.01                                     # the bulk file in pounds, say, the history in pence
    stub, summary, _ = night(cfg, w, day(1), monkeypatch)
    assert summary["bulk"] is False and "disagrees" in summary["why_not_bulk"]
    i = DAYS.get_loc(pd.Timestamp(day(1)))
    assert raw(cfg, "prices", w.syms[0])["close"].iloc[-1] == pytest.approx(w.px[w.syms[0]][i])


def test_1_an_ex_dividend_date_has_the_adjusted_history_read_again_for_that_symbol_only(quiet, monkeypatch):
    cfg, w = quiet, World()
    night(cfg, w, T0, monkeypatch, full=True)
    night(cfg, w, day(1), monkeypatch)
    x = w.syms[3]
    w.dividends[x].append((day(2), 6.0))                    # announced, in the calendar; goes ex on day 2
    stub, summary, _ = night(cfg, w, day(2), monkeypatch)
    assert summary["price_histories_read_again"] == 1
    assert {s for path, s in stub.asked if path == "historical-price-eod/dividend-adjusted"} == {x}
    i = DAYS.get_loc(pd.Timestamp(day(2)))
    p = raw(cfg, "prices", x).set_index("date")["adj_close"]
    assert p.iloc[-1] == pytest.approx(w.px[x][i])
    np.testing.assert_allclose(p.to_numpy(), w.adj(x)[DAYS <= DAYS[i]][-len(p):])      # the whole series, re-based
    assert pd.Timestamp(day(2)) in set(raw(cfg, "dividends", x)["ex_date"])
    stub, summary, _ = night(cfg, w, day(3), monkeypatch)   # and only once
    assert summary["price_histories_read_again"] == 0


def test_1_a_company_that_stops_trading_costs_no_calls_of_its_own(quiet, monkeypatch):
    cfg, w = quiet, World()
    night(cfg, w, T0, monkeypatch, full=True)
    x = w.syms[1]
    w.halted[x] = day(1)
    for d in (1, 2, 3, 4):
        stub, summary, _ = night(cfg, w, day(d), monkeypatch)
    assert summary["prices_asked_per_symbol"] == 0 and summary["price_histories_read_again"] == 0
    assert x not in {s for path, s in stub.asked if path.startswith("historical-price-eod")} or \
        stub.by_path["historical-price-eod/full"] == fetch_mod.BULK_PROBES
    assert raw(cfg, "prices", x)["date"].max() == pd.Timestamp(day(1))
    assert raw(cfg, "prices", w.syms[0])["date"].max() == pd.Timestamp(day(4))


def test_1_a_delisted_company_is_never_asked_for_again(quiet, monkeypatch):
    cfg, w = quiet, World()
    night(cfg, w, T0, monkeypatch, full=True)
    for d in (1, 2):
        stub, _, _ = night(cfg, w, day(d), monkeypatch)
        assert "DED.L" not in {s for _, s in stub.asked}


def test_a_top_up_that_stops_part_way_is_run_again_safely(quiet, monkeypatch):
    cfg, w = quiet, World()
    night(cfg, w, T0, monkeypatch, full=True)
    night(cfg, w, day(1), monkeypatch)
    w.today = day(2)
    monkeypatch.setattr(fetch_mod, "_today", lambda: day(2))
    f = Fetcher(cfg, client=StubFmp(w))
    monkeypatch.setattr(f, "top_up_caps", lambda *a, **k: (_ for _ in ()).throw(RuntimeError("the power went")))
    with pytest.raises(RuntimeError):
        f.run()
    version = dv.state(cfg.data_dir)["version"]                # prices written, nothing recorded
    assert raw(cfg, "prices", w.syms[0])["date"].max() == pd.Timestamp(day(2))
    monkeypatch.undo()
    stub, summary, rec = night(cfg, w, day(2), monkeypatch)
    assert rec["version"] == version + 1
    for s in w.syms:
        assert raw(cfg, "prices", s)["date"].is_unique
        assert raw(cfg, "market_cap", s)["date"].max() == pd.Timestamp(day(2))


# ------------------------------------------------------------------ 2: the pool and the limit
class Clock:
    def __init__(self):
        self.t = 0.0
        self.sleeps: list[float] = []

    def __call__(self):
        return self.t

    def sleep(self, s):
        self.sleeps.append(s)
        self.t += max(s, 0)


def client(cfg, per_minute, opener, clock=None, **kw):
    import os
    os.environ.setdefault("FMP_API_KEY", "test-key")
    cfg.raw["fmp"].update({"calls_per_minute": per_minute, **kw})
    if clock is None:
        return Fmp(cfg, opener=opener)
    return Fmp(cfg, opener=opener, clock=clock, sleep=clock.sleep)


def test_2_never_more_than_the_configured_calls_in_any_minute(cfg):
    clock, starts = Clock(), []

    def opener(url):
        starts.append(clock.t)
        return {}, "[]"

    c = client(cfg, 600, opener, clock)
    for _ in range(2000):
        c.get("x")
    starts = np.array(starts)
    in_a_minute = [int(((starts >= t) & (starts < t + 60)).sum()) for t in starts]
    assert max(in_a_minute) <= 600
    assert starts[-1] >= 199                    # 2,000 calls at 600 a minute take over three minutes


def test_2_the_pool_shares_the_limit_and_runs_calls_side_by_side(cfg):
    import time
    starts, live, peak, lock = [], [0], [0], threading.Lock()

    def opener(url):
        with lock:
            starts.append(time.monotonic())
            live[0] += 1
            peak[0] = max(peak[0], live[0])
        time.sleep(0.2)
        with lock:
            live[0] -= 1
        return {}, "[]"

    c = client(cfg, 1200, opener, concurrency=6)             # one start every 0.05s
    f = Fetcher(cfg, client=c)
    f._each(lambda s: c.get("x", symbol=s), [f"S{i}" for i in range(24)], "test")
    gaps = np.diff(sorted(starts))
    assert len(starts) == 24 and gaps.min() >= 0.045         # never closer than the limit allows
    assert 2 <= peak[0] <= 6                                 # side by side, and no more than the pool
    assert c.calls == 24


def test_2_a_429_backs_every_thread_off_and_the_call_is_tried_again(cfg):
    clock, starts, n = Clock(), [], [0]

    def opener(url):
        n[0] += 1
        starts.append(clock.t)
        if n[0] == 3:
            raise urllib.error.HTTPError(url, 429, "Too Many Requests", {"Retry-After": "30"}, None)
        return {}, '[{"ok": 1}]'

    c = client(cfg, 600, opener, clock)
    out = [c.get("x") for _ in range(5)]
    assert all(o == [{"ok": 1}] for o in out) and c.throttled == 1
    assert n[0] == 6                                         # five calls and the one tried again
    assert starts[3] - starts[2] >= 30                       # nothing starts until the wait is over


def test_2_the_bulk_file_is_read_as_csv(cfg):
    c = client(cfg, 600, lambda url: ({}, '"symbol","date","close","volume"\n"AAA.L","2026-11-18","12.5","100"\n'))
    assert c.get("eod-bulk", date="2026-11-18") == [{"symbol": "AAA.L", "date": "2026-11-18", "close": "12.5",
                                                    "volume": "100"}]


# ------------------------------------------------------------------ 3: statements when due
def statement_calls(stub):
    return sorted({s for path, s in stub.asked if path in STATEMENT_PATHS})


def test_3_statements_are_read_again_only_when_due_and_a_new_filing_is_picked_up(quiet, monkeypatch):
    cfg, w = quiet, World()
    night(cfg, w, T0, monkeypatch, full=True)
    stub, _, _ = night(cfg, w, day(1), monkeypatch)
    assert statement_calls(stub) == []
    x = w.syms[2]
    w.assets[x][2026] = 2.5e9                                # a new annual report
    w.filings = [{"symbol": x, "calendarYear": 2026, "period": "FY", "date": "2026-12-31", "dateAdded": day(2)},
                 {"symbol": "AAPL", "calendarYear": 2026, "period": "Q4", "date": "2026-09-30", "dateAdded": day(2)}]
    stub, summary, rec = night(cfg, w, day(2), monkeypatch)
    assert statement_calls(stub) == [x] and summary["statements_asked"] == 1
    st = pd.read_parquet(cfg.data_dir / "statements.parquet")
    assert st[(st["symbol"] == x) & (st["period_end"] == "2026-12-31")]["totalAssets"].tolist() == [2.5e9]
    ch = rec["rows"]
    assert set(ch[ch["panel"] == "statements"]["symbol"]) == {x}
    stub, _, _ = night(cfg, w, day(3), monkeypatch)          # the same list the night after names nothing new
    assert statement_calls(stub) == []


def test_3_without_a_filing_every_symbol_comes_round_once_in_the_refresh_days(cfg, monkeypatch):
    cfg.raw["fmp"].update({"statements_refresh_days": 7, "dividends_refresh_days": 20000})
    w = World(20)
    night(cfg, w, T0, monkeypatch, full=True, record=False)
    seen = []
    for d in pd.date_range("2026-11-19", "2026-12-03"):      # every night for a fortnight, weekends too
        stub, _, _ = night(cfg, w, str(d.date()), monkeypatch, record=False)
        seen.append(statement_calls(stub))
    assert seen[0] == []
    assert set().union(*seen[:8]) == set(w.syms)             # each of them within the week
    assert max(len(s) for s in seen) <= len(w.syms) // 2     # spread out, not all on one night
    assert all(sum(s in night_ for night_ in seen) == 2 for s in w.syms)      # once a week, each


# ------------------------------------------------------------------ 5: only what changed
def test_5_rows_fetched_again_unchanged_record_no_change(quiet, monkeypatch):
    cfg, w = quiet, World()
    night(cfg, w, T0, monkeypatch, full=True)
    _, _, first = night(cfg, w, day(1), monkeypatch)
    assert set(first["rows"]["panel"]) <= {"close", "adj", "market_cap"}           # the new day
    assert first["rows"]["from"].min() == pd.Timestamp(day(1))
    # the same night again: every statement, dividend, cap and price history read again, the same values
    st = fetch_mod.json.loads((cfg.data_dir / "raw" / fetch_mod.STATE).read_text())
    st["statements_due"] = {s: T0 for s in w.syms}
    st["dividends_due"] = {s: T0 for s in w.syms}
    st["history_at"] = {s: "2025-06-02" for s in w.syms}
    (cfg.data_dir / "raw" / fetch_mod.STATE).write_text(fetch_mod.json.dumps(st))
    stub, summary, again = night(cfg, w, day(1), monkeypatch)
    assert summary["statements_asked"] == len(w.syms) and summary["price_histories_read_again"] == len(w.syms)
    assert stub.by_path["dividends"] == len(w.syms)
    assert again["changed"] == 0 and again["version"] == first["version"]


def test_5_one_changed_company_rebuilds_only_that_company_from_its_change(quiet, monkeypatch):
    cfg, w = quiet, World()
    names = ["gross_profitability", "ma200"]
    night(cfg, w, T0, monkeypatch, full=True)
    m = load_market(cfg.data_dir)
    build_universe(m, size=6, large=3, start="2025-06-02").to_parquet(cfg.data_dir / "universe.parquet")
    dv.record(cfg, "universe")
    assert all(r["ok"] for r in indicator_job.run(cfg, names=names))
    night(cfg, w, day(1), monkeypatch)
    reports = indicator_job.run(cfg, names=names)
    assert all(r["ok"] and r["dates_rebuilt"] == 0 and r["dates_added"] == 1 for r in reports), reports
    store = IndicatorStore.for_config(cfg)
    before = store.frame("gross_profitability")

    x = w.syms[4]
    w.assets[x][2024] = 4.2e9                                # 2024 restated: half the profitability
    w.filings = [{"symbol": x, "period": "FY", "date": "2024-12-31", "dateAdded": day(2)}]
    _, _, rec = night(cfg, w, day(2), monkeypatch)
    ch = rec["rows"]
    assert set(ch[ch["panel"] == "statements"]["symbol"]) == {x}
    assert set(ch[ch["panel"] != "statements"]["from"]) == {pd.Timestamp(day(2))}  # everyone else: the new day
    by = {r["indicator"]: r for r in indicator_job.run(cfg, names=names)}
    gp = by["gross_profitability"]
    assert gp["ok"] and gp["companies_rebuilt"] == 1 and gp["rebuilt_symbols"] == [x]
    assert gp["dates_added"] == 1
    for k, r in by.items():
        if k != "gross_profitability":
            assert r["ok"] and r["dates_rebuilt"] == 0 and r["dates_added"] == 1, r   # prices: only the new day
    after = store.frame("gross_profitability")
    old = before.merge(after, on=["date", "symbol"], suffixes=("_old", "_new"))
    moved = old[~np.isclose(old["value_old"], old["value_new"])]
    assert set(moved["symbol"]) == {x} and moved["date"].min() >= pd.Timestamp("2025-04-01")
    # and what is stored is what a rebuild of the whole indicator gives
    assert all(r["ok"] for r in indicator_job.run(cfg, names=["gross_profitability"], rebuild=["gross_profitability"]))
    pd.testing.assert_frame_equal(store.frame("gross_profitability"), after)
