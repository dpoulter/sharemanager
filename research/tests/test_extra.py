"""The N100's extra symbols, the trusts and funds (brief of 21 Nov 2026, trust and fund prices): tests 1 to 3."""
from __future__ import annotations

import json

import numpy as np
import pandas as pd
import pytest

from conftest import built_workspace, make_market
from research import data_version as dv
from research import extra, indicator_job
from research import publish as pub
from research.indicator_store import IndicatorStore
from research.strategy import spec_from_dict
from research.universe import build_universe, membership
from test_publish import FakeImport

N = 330
NAMES = pub.PUBLISHED
RESEARCH = ["S0", "S1", "S2", "S3", "S4"]
EXTRAS = ["CTY", "PSH", "SMT"]          # SMT: FMP has nothing for it


class FakeFmp:
    calls = 0
    throttled = 0

    def __init__(self, dates):
        rng = np.random.default_rng(11)
        self.dates = dates
        self.px = {s: 500 * np.cumprod(1 + rng.normal(0.0003, 0.008, len(dates))) for s in ["CTY.L", "PSH.L"]}

    def get(self, path, **p):
        self.calls += 1
        sym = p.get("symbol")
        if path == "profile":
            return [{"symbol": sym, "currency": "GBp", "isFund": True, "country": "GB"}]
        if path.startswith("historical-price-eod"):
            if sym not in self.px:
                return []
            a, b = pd.Timestamp(p["from"]), pd.Timestamp(p["to"])
            return [{"date": str(d.date()), "close": float(v), "adjClose": float(v), "volume": 1000}
                    for d, v in zip(self.dates, self.px[sym]) if a <= d <= b]
        if path == "dividends":
            return [{"date": str(self.dates[100].date()), "dividend": 3.0}] if sym in self.px else []
        return []


@pytest.fixture
def world(cfg):
    rng = np.random.default_rng(7)
    prices = {f"{s}.L": list(100 * np.cumprod(1 + rng.normal(0.0004, 0.01, N))) for s in RESEARCH}
    m = make_market(prices, start="2024-01-01")
    u = build_universe(m, size=5, large=2, start="2024-01-01")
    cfg.raw["universe"]["start"] = "2024-01-01"
    extra.write_list(cfg, RESEARCH + EXTRAS, covered=set(RESEARCH))
    fake = FakeFmp(m.dates)
    extra.fetch(cfg, client=fake)
    built_workspace(cfg, m, u, names=NAMES)
    mk = pub.fetch_marker(cfg)
    mk.parent.mkdir(parents=True, exist_ok=True)
    mk.write_text(json.dumps({"ok": True}))
    return cfg, m, u


def test_the_list_is_the_n100_symbols_minus_the_research_master(world):
    cfg, m, u = world
    lst = extra.read_list(cfg)
    assert lst["n100_symbol"].tolist() == sorted(EXTRAS)
    assert lst["symbol"].tolist() == ["CTY.L", "PSH.L", "SMT.L"]
    assert lst["read_at"].notna().all()


def test_1_a_publish_covers_every_n100_symbol_and_names_only_what_fmp_lacks(world):
    cfg, m, u = world
    api = FakeImport(RESEARCH + EXTRAS)
    s = pub.publish(cfg, full=True, api=api, market=m)
    prices, stats = api.sent()
    assert s["missing"] == ["SMT"]
    assert {r[0] for r in prices} == set(RESEARCH) | {"CTY", "PSH"}
    for sym in ("CTY", "PSH"):
        assert {r[2] for r in stats if r[0] == sym} == set(NAMES)
    assert all(pd.Timestamp(r[1]).day_of_week < 5 for r in prices + stats)
    xm = extra.load(cfg)
    d = xm.dates[-1]
    p = {(r[0], r[1]): r[2] for r in prices}
    assert p[("CTY", str(d.date()))] == pytest.approx(FakeFmp(m.dates).px["CTY.L"][-1], abs=1e-3)   # raw pence


def test_2_an_extra_never_reaches_the_universe_a_ranking_or_a_backtest(world):
    cfg, m, u = world
    research_store = IndicatorStore.for_config(cfg)
    assert not any(k.startswith(extra.DIRNAME) for k in research_store.keys())
    for k in research_store.keys():
        assert not set(research_store.frame(k)["symbol"]) & {"CTY.L", "PSH.L"}
    assert not set(membership(u, m.dates)[0].columns) & {"CTY.L", "PSH.L"}
    assert extra.store(cfg).has("close_price@raw")
    ws = indicator_job.workspace(cfg, market=m, universe=u)
    assert not set(ws.m.close.columns) & {"CTY.L", "PSH.L"}
    out = ws.run(spec_from_dict({"name": "t", "signals": {"6mnth": 1}, "holdings": 3}),
                 start="2024-11-01", end="2025-03-31", write_report=False)
    text = repr(out)
    assert "CTY" not in text and "PSH" not in text
    import inspect
    from research import engine, from_rules, strategy, universe
    for mod in (engine, from_rules, strategy, universe):
        assert "n100_extra" not in inspect.getsource(mod) and "from .extra" not in inspect.getsource(mod)


def test_3_a_changed_extra_price_is_recorded_and_rebuilt(world):
    cfg, m, u = world
    p = extra.extra_dir(cfg) / "prices.parquet"
    df = pd.read_parquet(p)
    day = pd.Timestamp(m.dates[-30])
    hit = (df["symbol"] == "CTY.L") & (pd.to_datetime(df["date"]) == day)
    df.loc[hit, "close"] *= 1.05
    df.to_parquet(p)
    before = extra.store(cfg).read("close_price@raw", [day]).frame.loc[day, "CTY.L"]
    reps = {r["indicator"]: r for r in indicator_job.run(cfg, names=NAMES, market=m, universe=u)}
    ch = dv.changes_since(cfg.data_dir, dv.current(cfg.data_dir) - 1)
    assert set(ch["panel"]) == {"extra_close"} and set(ch["symbol"]) == {"CTY.L"}
    r = reps["n100_extra/close_price@raw"]
    assert r["ok"] and r["dates_rebuilt"] > 0 and r.get("rebuilt_symbols") == ["CTY.L"]
    assert reps["close_price@raw"]["dates_rebuilt"] == 0          # research indicators untouched
    after = extra.store(cfg).read("close_price@raw", [day]).frame.loc[day, "CTY.L"]
    assert after == pytest.approx(before * 1.05)
