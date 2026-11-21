"""indicators, indicator_store, indicator_job, data_refresh's data version, and the engine reading the store
(brief of 20 Nov 2026: tests 2 to 6; test 1 is the run 9 replay check on the Mac mini, test 7 the suite)."""
from __future__ import annotations

import json
import subprocess
import sys
from dataclasses import replace
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

from conftest import built_workspace, make_market
from research import data_version as dv
from research import indicator_job
from research.indicator_store import IndicatorStore, MissingDates, VersionMismatch
from research.strategy import IndicatorRefused, spec_from_dict
from research.universe import build_universe, membership

N = 420


def _statements(symbols):
    rows = []
    for i, s in enumerate(symbols):
        for y in (2022, 2023, 2024):
            rows.append({"symbol": s, "period_end": f"{y}-12-31", "freq": "annual", "reportedCurrency": "GBP",
                         "totalStockholdersEquity": 1e8 * (i + 1), "totalAssets": 3e8, "netIncome": 1e7 * (i + 1),
                         "revenue": 2e8, "operatingCashFlow": 1.5e7, "weightedAverageShsOut": 1e6})
    return pd.DataFrame(rows)


def _market(n=N, seed=4):
    rng = np.random.default_rng(seed)
    syms = [f"S{i}.L" for i in range(8)]
    prices = {s: list(100 * np.cumprod(1 + rng.normal(0.0005, 0.01, n))) for s in syms}
    return make_market(prices, start="2024-01-01", statements=_statements(syms))


def _setup(cfg, names, n=N):
    m = _market(n)
    u = build_universe(m, size=8, large=4, start="2024-01-01")
    cfg.raw["universe"]["start"] = "2024-01-01"
    ws = built_workspace(cfg, m, u, names=names)
    return ws, m, u


def _member(m, u):
    return membership(u, m.dates)[0].reindex(columns=m.close.columns, fill_value=False)


# ---------------------------------------------------------------- test 2: a changed definition
def test_a_changed_definition_changes_its_version_and_only_it_is_rebuilt(cfg, monkeypatch):
    from research import indicators
    ws, m, u = _setup(cfg, ["momentum_12_1", "reversal_1m"])
    before = indicators.versions(cfg)
    defn = indicators.REGISTRY["momentum_12_1"]

    def momentum_12_1(ctx, date):        # the same idea, one month further back
        return ctx.adj_months_back(date, 2) / ctx.adj_months_back(date, 12) - 1
    monkeypatch.setitem(indicators.REGISTRY, "momentum_12_1", replace(defn, fn=momentum_12_1))
    after = indicators.versions(cfg)
    changed = {k for k in after if after[k] != before[k]}
    assert changed == {"momentum_12_1@raw", "momentum_12_1@adjusted"}

    # Before the job: the engine refuses the stale indicator, naming it.
    ws2 = indicator_job.workspace(cfg, market=m, universe=u)
    spec = spec_from_dict({"name": "mom", "signals": {"momentum_12_1": 1}, "holdings": 3})
    with pytest.raises(IndicatorRefused, match="momentum_12_1@adjusted.*definition"):
        ws2.run(spec, start="2024-11-01", end="2025-04-30", write_report=False)

    reps = {r["indicator"]: r for r in indicator_job.run(cfg, names=["momentum_12_1", "reversal_1m"],
                                                          market=m, universe=u)}
    assert reps["momentum_12_1@raw"]["action"] == reps["momentum_12_1@adjusted"]["action"] == "rebuilt whole"
    assert "definition changed" in reps["momentum_12_1@raw"]["why"]
    for k in ("reversal_1m@raw", "reversal_1m@adjusted"):
        assert reps[k]["action"] == "updated" and reps[k]["dates_added"] == 0 and reps[k]["dates_rebuilt"] == 0
    store = IndicatorStore.for_config(cfg)
    assert store.meta("momentum_12_1@adjusted")["version"] == after["momentum_12_1@adjusted"]
    ws2.run(spec, start="2024-11-01", end="2025-04-30", write_report=False)
    rec = ws2.log.read().iloc[-1]
    used = json.loads(rec["indicators"])
    assert used == {"momentum_12_1@adjusted": {"version": after["momentum_12_1@adjusted"],
                                               "data_version": int(rec["data_version"])}}


def test_rebuild_by_hand_rebuilds_that_indicator_whole(cfg):
    ws, m, u = _setup(cfg, ["reversal_1m", "high_52w"])
    reps = {r["indicator"]: r for r in indicator_job.run(cfg, rebuild=["reversal_1m"], names=["reversal_1m", "high_52w"],
                                                          market=m, universe=u)}
    assert reps["reversal_1m@raw"]["action"] == "rebuilt whole" and reps["high_52w@raw"]["action"] == "updated"


# ---------------------------------------------------------------- test 3: a read with a missing date
def test_a_read_over_a_range_with_one_missing_date_is_refused_naming_it(cfg):
    ws, m, u = _setup(cfg, ["reversal_1m"])
    store = IndicatorStore.for_config(cfg)
    k = "reversal_1m@adjusted"
    meta = store.meta(k)
    rows = store.frame(k)
    hole = m.dates[300]
    store.write(k, rows[rows["date"] != hole], version=meta["version"], data_version=meta["data_version"],
                calendar=meta["calendar"], dates=meta["dates"].drop(hole), data_last_date=m.dates[-1])
    with pytest.raises(MissingDates) as e:
        store.read_range(k, m.dates[290], m.dates[310], calendar=m.dates)
    assert e.value.dates == [str(hole.date())] and str(hole.date()) in str(e.value)
    got = store.read_range(k, m.dates[301], m.dates[310], calendar=m.dates)       # the rest still reads whole
    assert list(got.frame.index) == list(m.dates[301:311])
    with pytest.raises(MissingDates):                                               # nor past the last date
        store.read(k, [m.dates[-1] + pd.Timedelta(days=3)])


def test_the_store_never_mixes_versions_or_holds_a_date_after_its_data(cfg):
    ws, m, u = _setup(cfg, ["reversal_1m"])
    store = IndicatorStore.for_config(cfg)
    k = "reversal_1m@adjusted"
    with pytest.raises(VersionMismatch):
        store.update(k, store.frame(k).head(0), version="not-this-one", data_version=None, dates=[m.dates[-1]],
                     data_last_date=m.dates[-1])
    with pytest.raises(Exception, match="never stored"):
        store.update(k, pd.DataFrame({"date": [m.dates[-1] + pd.Timedelta(days=1)], "symbol": ["S1.L"], "value": [1.0]}),
                     version=store.meta(k)["version"], data_version=None, dates=[m.dates[-1] + pd.Timedelta(days=1)],
                     data_last_date=m.dates[-1])
    cov = store.coverage().set_index("indicator").loc[k]
    assert cov["last"] == str(m.dates[-1].date()) and cov["companies_per_year"][2025] == 8


# ---------------------------------------------------------------- test 4: an indicator not in the store
def test_a_backtest_needing_an_indicator_not_in_the_store_is_refused_naming_it(cfg):
    ws, m, u = _setup(cfg, ["momentum_12_1"])
    spec = spec_from_dict({"name": "lv", "signals": {"momentum_12_1": 1, "low_volatility": 1}, "holdings": 3})
    with pytest.raises(IndicatorRefused) as e:
        ws.run(spec, start="2024-11-01", end="2025-04-30", write_report=False)
    assert e.value.indicator == "low_volatility@adjusted" and "not in the store" in str(e.value)
    assert ws.log.read().empty                                   # refused before anything ran or was logged


def test_the_translator_refuses_an_indicator_the_store_does_not_hold(cfg):
    from research.from_rules import Untranslatable, translate
    from test_worker import claim
    ws, m, u = _setup(cfg, ["close_price", "ma200"])               # no 6mnth, no trend_following
    with pytest.raises(Untranslatable) as e:
        translate(claim(), ws.store)
    assert e.value.rule == "rank_by" and "6mnth" in str(e.value)


# ---------------------------------------------------------------- test 5: the engine imports no indicator
ENGINE_MODULES = ["research.engine", "research.strategy", "research.from_rules", "research.worker",
                  "research.indicator_store", "research.data_version", "research.metrics", "research.report"]


def test_the_engine_does_not_import_the_indicators_package():
    code = ("import sys\n" + "".join(f"import {m}\n" for m in ENGINE_MODULES) +
            "bad = sorted(m for m in sys.modules if m.startswith('research.indicators'))\n"
            "print(','.join(bad))\n")
    out = subprocess.run([sys.executable, "-c", code], capture_output=True, text=True, check=True)
    assert out.stdout.strip() == "", f"imported: {out.stdout.strip()}"
    src = Path(__file__).parents[1] / "src" / "research"
    for name in ("engine.py", "strategy.py", "from_rules.py", "worker.py", "indicator_store.py"):
        text = (src / name).read_text()
        assert "from .indicators" not in text and "research.indicators" not in text, name


def test_only_the_store_knows_the_layout_of_data_indicators():
    src = Path(__file__).parents[1] / "src" / "research"
    for p in src.rglob("*.py"):
        if p.name != "indicator_store.py":
            assert '"indicators" / ' not in p.read_text() and "data/indicators/" not in p.read_text().replace(
                "data/indicators/.job.lock", ""), p.name


# ---------------------------------------------------------------- test 6: a cap override on past dates
def test_a_cap_override_rebuilds_only_that_companys_values_and_a_run_before_the_job_is_refused(cfg):
    names = ["book_to_market", "earnings_yield", "momentum_12_1", "close_price", "ma200"]
    ws, m, u = _setup(cfg, names)
    store = IndicatorStore.for_config(cfg)
    spec = spec_from_dict({"name": "btm", "signals": {"book_to_market": 1}, "holdings": 3})
    ws.run(spec, start="2025-01-02", end="2025-04-30", write_report=False)
    before = {k: store.frame(k) for k in store.keys()}
    v0 = dv.current(cfg.data_dir)

    # data_refresh applies an override: S3's cap in July and August 2024 was 10 times too small.
    window = (m.dates >= "2024-07-01") & (m.dates <= "2024-08-31")
    m.market_cap.loc[window, "S3.L"] = m.market_cap.loc[window, "S3.L"] * 10
    rec = dv.record(cfg, "cap override S3.L", market=m, member=_member(m, u))
    assert rec["version"] == v0 + 1
    ch = dv.changes_since(cfg.data_dir, v0)
    assert list(zip(ch["panel"], ch["symbol"])) == [("market_cap", "S3.L")]
    assert ch["from"].iloc[0] == pd.Timestamp("2024-07-01") and ch["to"].iloc[0] == pd.Timestamp("2024-08-31")

    # Before the job: refused, naming the indicator, because it was built from the older data version.
    with pytest.raises(IndicatorRefused, match="book_to_market.*data version"):
        ws.run(spec, start="2025-01-02", end="2025-04-30", write_report=False)

    reps = {r["indicator"]: r for r in indicator_job.run(cfg, names=names, market=m, universe=u)}
    for k in ("book_to_market", "earnings_yield"):
        r = reps[k]
        assert r["action"] == "updated" and r["companies_rebuilt"] == 1 and r["rebuilt_symbols"] == ["S3.L"]
        assert r["rebuilt_from"] == "2024-07-01"
        old, new = before[k].set_index(["date", "symbol"])["value"], store.frame(k).set_index(["date", "symbol"])["value"]
        others = [ix for ix in old.index if ix[1] != "S3.L"]
        pd.testing.assert_series_equal(old.loc[others], new.loc[others])           # nobody else touched
        s3_old, s3_new = old.xs("S3.L", level="symbol"), new.xs("S3.L", level="symbol")
        moved = s3_new.index[(s3_new - s3_old.reindex(s3_new.index)).abs() > 1e-12]
        assert len(moved) and moved.min() >= pd.Timestamp("2024-07-01") and moved.max() <= pd.Timestamp("2024-08-31")
    for k in ("momentum_12_1@adjusted", "close_price@raw", "ma200@raw"):        # caps are not their input
        assert reps[k]["dates_rebuilt"] == 0
        pd.testing.assert_frame_equal(before[k], store.frame(k))
    assert all(store.meta(k)["data_version"] == rec["version"] for k in store.keys())
    out = ws.run(spec, start="2025-01-02", end="2025-04-30", write_report=False)
    assert out["record"]["data_version"] == rec["version"]


# ---------------------------------------------------------------- the nightly top-up, and unrecorded changes
def test_a_new_day_is_added_without_rebuilding_history(cfg):
    names = ["six_month", "reversal_1m", "book_to_market"]
    full = _market(N + 3)
    m = replace(full, close=full.close.iloc[:N], adj=full.adj.iloc[:N], market_cap=full.market_cap.iloc[:N])
    u = build_universe(full, size=8, large=4, start="2024-01-01")
    cfg.raw["universe"]["start"] = "2024-01-01"
    built_workspace(cfg, m, u, names=names)
    reps = {r["indicator"]: r for r in indicator_job.run(cfg, names=names, market=full, universe=u)}
    assert reps["reversal_1m@adjusted"]["dates_added"] == 3 and reps["reversal_1m@adjusted"]["dates_rebuilt"] == 0
    cal_days = (full.dates[-1] - m.dates[-1]).days
    assert reps["six_month@raw"]["dates_added"] == cal_days and reps["six_month@raw"]["dates_rebuilt"] == 0
    store = IndicatorStore.for_config(cfg)
    assert store.meta("six_month@raw")["dates"][-1] == full.dates[-1]
    # Worked out again from scratch, the added days are the same values.
    k = "reversal_1m@adjusted"
    fresh = indicator_job.compute(cfg, indicator_job.definition(k), k, full, _member(full, u), full.dates[-3:])
    got = store.read(k, full.dates[-3:]).frame.rename_axis(columns="symbol").stack().rename("value").reset_index()
    pd.testing.assert_series_equal(got.sort_values(["date", "symbol"])["value"].reset_index(drop=True),
                                   fresh.sort_values(["date", "symbol"])["value"].reset_index(drop=True))


def test_a_data_file_changed_without_a_record_is_refused(cfg):
    ws, m, u = _setup(cfg, ["reversal_1m"])
    (cfg.data_dir / "prices.parquet").write_bytes(b"x")
    with pytest.raises(dv.UnrecordedChange, match="prices.parquet"):
        dv.current(cfg.data_dir)
    spec = spec_from_dict({"name": "rev", "signals": {"reversal_1m": 1}, "holdings": 3})
    with pytest.raises(IndicatorRefused, match="research data-version"):
        ws.run(spec, start="2025-01-02", end="2025-04-30", write_report=False)


def test_a_full_refetch_rebuilds_everything(cfg):
    ws, m, u = _setup(cfg, ["reversal_1m"])
    dv.record(cfg, "fetch --full", market=m, member=_member(m, u), full=True)
    reps = indicator_job.run(cfg, names=["reversal_1m"], market=m, universe=u)
    assert {r["action"] for r in reps} == {"rebuilt whole"}


# ---------------------------------------------------------------- the job and the worker
def test_the_job_does_not_run_while_the_worker_has_a_run_and_the_worker_waits_for_the_job(cfg):
    ws, m, u = _setup(cfg, ["reversal_1m"])
    state = indicator_job.worker_state_path(cfg)
    state.parent.mkdir(parents=True, exist_ok=True)
    state.write_text(json.dumps({"run_id": 7, "started": "now"}))
    with pytest.raises(indicator_job.JobRefused, match="run 7"):
        indicator_job.run(cfg, names=["reversal_1m"], market=m, universe=u)
    state.unlink()
    assert not indicator_job.lock_path(cfg).exists()

    from research import worker as worker_mod
    from test_worker import FakeApi, claim
    indicator_job.lock_path(cfg).write_text(json.dumps({"pid": __import__("os").getpid()}))
    api = FakeApi([claim()])
    w = worker_mod.Worker(cfg, api, workspace_factory=lambda c: ws, gate=lambda c: None)
    assert w.run_once() is False and api.calls == []               # nothing claimed while the job runs
    indicator_job.lock_path(cfg).unlink()


def test_one_indicator_failing_does_not_stop_the_others(cfg, monkeypatch):
    from research import indicators
    ws, m, u = _setup(cfg, ["reversal_1m", "high_52w"])

    def broken(ctx, date):
        raise ZeroDivisionError("on purpose")
    monkeypatch.setitem(indicators.REGISTRY, "reversal_1m", replace(indicators.REGISTRY["reversal_1m"], fn=broken))
    reps = {r["indicator"]: r for r in indicator_job.run(cfg, names=["reversal_1m", "high_52w"], market=m, universe=u)}
    assert not reps["reversal_1m@raw"]["ok"] and "on purpose" in reps["reversal_1m@raw"]["reason"]
    assert reps["high_52w@raw"]["ok"] and "seconds" in reps["high_52w@raw"]
    log = (cfg.p("logs") / "indicator_job.jsonl").read_text().splitlines()
    assert any('"on purpose' in line or "on purpose" in line for line in log)


def test_stored_n100_indicators_match_the_definitions_on_a_sunday(cfg):
    """close_price, ma200 and 6mnth are kept for every calendar day: a Sunday reads Friday's close and
    average, and its six months count back from the Sunday (the N100's as-of rule)."""
    ws, m, u = _setup(cfg, ["close_price", "ma200", "6mnth"])
    from research.indicators import Context
    sunday = [d for d in pd.date_range(m.dates[250], m.dates[-1]) if d.day_of_week == 6][0]
    friday = m.dates[m.dates.searchsorted(sunday) - 1]
    store = IndicatorStore.for_config(cfg)
    rm = replace(m, adj=m.close, _ma200=None)
    ctx = Context(rm, None)
    ctx.asof[friday] = sunday
    pd.testing.assert_series_equal(store.read("close_price@raw", [sunday]).frame.iloc[0].dropna(),
                                   rm.close.loc[friday].dropna(), check_names=False)
    pd.testing.assert_series_equal(store.read("6mnth@raw", [sunday]).frame.iloc[0].dropna().sort_index(),
                                   (ctx.n100_return(friday, 6) * 100).dropna().sort_index(), check_names=False)
