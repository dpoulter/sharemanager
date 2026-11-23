"""The partial turnover rule (brief of 23 Nov 2026): tests 1-4 and 6 on the engine side, 7's setting."""
from __future__ import annotations

import json
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

from conftest import built_workspace, make_market
from research import engine
from research.strategy import spec_from_dict
from research.universe import build_universe
from research.validate import RUN9

CASES = json.loads((Path(__file__).resolve().parents[2] / "tests" / "fixtures" / "turnover_cut_cases.json").read_text())


@pytest.mark.parametrize("case", CASES["cases"], ids=[c["name"][:40] for c in CASES["cases"]])
def test_6_the_shared_cases_hold_back_what_the_n100_holds_back(case):
    # the same file tests/tests.php runs through strategy_turnover_cut()
    orders = [{"symbol": s, "side": side, "value": float(v), "held": float(h), "leaver": bool(lv)}
              for s, side, v, h, lv in case["orders"]]
    keep, back, traded = engine.turnover_cut(orders, case["strength"], float(case["limit"]))
    assert sorted(orders[k]["symbol"] for k in keep) == sorted(case["kept"])
    assert sorted(keep + back) == list(range(len(orders)))
    assert traded == pytest.approx(case["turnover"])
    assert traded <= case["limit"] + 1e-6                     # never past the limit


def test_1_80_per_cent_wanted_at_a_50_per_cent_limit_trades_50():
    c = CASES["cases"][0]
    orders = [{"symbol": s, "side": side, "value": float(v), "held": float(h), "leaver": bool(lv)}
              for s, side, v, h, lv in c["orders"]]
    keep, back, traded = engine.turnover_cut(orders, c["strength"], 50_000)
    assert traded == 50_000
    sold = [orders[k]["symbol"] for k in keep if orders[k]["side"] == "SELL"]
    bought = [orders[k]["symbol"] for k in keep if orders[k]["side"] == "BUY"]
    assert sold == ["L1", "L5", "L3", "L7", "L2"]             # weakest leavers first
    assert bought == ["N1", "N2", "N3", "N4", "N5"]           # best new targets first
    assert sorted(orders[k]["symbol"] for k in back) == ["L4", "L6", "L8", "N6", "N7", "N8"]
    # a holding that stays a target is never sold to make room: there are none here to sell
    assert not any(orders[k]["side"] == "SELL" and not orders[k]["leaver"] for k in keep)


def _churn_world(cfg, n=170):
    """Ten shares whose ranking turns over completely each month: the top five change every month."""
    syms = [f"S{i}.L" for i in range(10)]
    prices = {s: [100.0] * n for s in syms}
    m = make_market(prices, start="2024-01-01")
    u = build_universe(m, size=10, large=5, start="2024-01-01")
    cfg.raw["universe"]["start"] = "2024-01-01"
    ws = built_workspace(cfg, m, u, names=["close_price"])
    return ws, m, u


def _scores(m):
    """By month, alternating halves: S0-S4 one month, S5-S9 the next (each 10, 9, 8, 7, 6 in order)."""
    out = {}
    for d in m.dates:
        first = (d.month % 2 == 0)
        vals = {}
        for i in range(10):
            top = (i < 5) == first
            vals[f"S{i}.L"] = (10 - i % 5) if top else (5 - i % 5) / 10
        out[d] = pd.Series(vals)
    return out


def _run(ws, m, u, rule, limit=0.5):
    from research.costs import from_dict
    from research.universe import membership
    member, large = membership(u, m.dates)
    member = member.reindex(columns=m.close.columns, fill_value=False)
    large = large.reindex(columns=m.close.columns, fill_value=False)
    rules = engine.Rules(holdings=5, rebalance="monthly", max_turnover=limit, turnover_rule=rule, drift_band=0.02)
    costs = from_dict({"commission": 0.0, "minimum_order": 1.0, "decimals": 2})
    return engine.simulate(m, member, large, _scores(m), rules, costs, m.dates[0], m.dates[-1])


def test_1_2_3_4_in_the_engine(cfg):
    ws, m, u = _churn_world(cfg)
    part = _run(ws, m, u, "partial")
    old = _run(ws, m, u, "refuse")
    # 4: the first build is exempt and fills the account on both rules
    first = part.fills[part.fills["date"] == part.fills["date"].min()]
    assert (first["side"] == "BUY").sum() == 5
    # the old rule refuses every later rebalance (100% turnover); the partial one trades half of each
    assert old.refusals >= 3 and part.refusals == 0
    assert old.fills["date"].nunique() == 1
    assert part.held_back > 0
    per_day = part.fills.groupby("date")
    later = [g for d, g in per_day if d != part.fills["date"].min()]
    for g in later:
        sold = (g.loc[g["side"] == "SELL", "consideration"]).sum()
        bought = (g.loc[g["side"] == "BUY", "consideration"]).sum()
        assert min(sold, bought) <= 0.5 * 100_000 * 1.02           # 1: never past the limit
    # 2: the next month is planned afresh: holdings stay five names, nothing bought twice or lost
    h = part.values["holdings"]
    assert h.max() <= 5 and h.iloc[-1] == 5
    assert not part.fills.duplicated(["date", "symbol", "side"]).any()
    # 3: under the limit (100%) everything trades in full, as before
    full = _run(ws, m, u, "partial", limit=1.0)
    assert full.held_back == 0 and full.refusals == 0


def test_7_run9_replays_on_the_old_rule():
    rules = spec_from_dict(RUN9).rules()
    assert rules.turnover_rule == "refuse" and rules.max_turnover == 0.5


def test_a_translated_limit_reaches_the_engine():
    import copy
    from research.from_rules import Untranslatable, translate
    from test_worker import STORE, claim
    c = claim()
    c["rules_snapshot"]["rules"]["max_turnover"] = 0.3
    s = translate(copy.deepcopy(c), STORE)
    r = spec_from_dict(s).rules()
    assert r.max_turnover == 0.3 and r.turnover_rule == "partial"
    assert spec_from_dict(translate(claim(), STORE)).rules().max_turnover == 0.5     # absent: the default
    c["rules_snapshot"]["rules"]["max_turnover"] = 1.5
    with pytest.raises(Untranslatable, match="max_turnover"):
        translate(c, STORE)
