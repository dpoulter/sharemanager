"""Run 19 fixes: the cap-weighted benchmark and the deflated Sharpe."""
from __future__ import annotations

import numpy as np
import pandas as pd
import pytest

from conftest import make_market
from research import engine, metrics


def test_cap_weighted_total_return_of_three_companies_by_hand():
    # Three companies over four days. A pays a dividend on day 2 (adjusted close rises, raw does not).
    close = {"A.L": [10.0, 11.0, 11.0, 12.1], "B.L": [20.0, 20.0, 18.0, 18.0], "C.L": [5.0, 5.5, 5.5, 5.0]}
    adj = {"A.L": [10.0, 11.0, 11.55, 12.705], "B.L": [20.0, 20.0, 18.0, 18.0], "C.L": [5.0, 5.5, 5.5, 5.0]}
    caps = {"A.L": [100.0, 110.0, 110.0, 121.0], "B.L": [200.0, 200.0, 180.0, 180.0], "C.L": [100.0, 110.0, 110.0, 100.0]}
    m = make_market(close, adj=adj, caps=caps, start="2024-01-01")
    member = pd.DataFrame(True, index=m.dates, columns=m.close.columns)
    b = engine.benchmarks(m, member, m.dates[0], m.dates[-1])
    # Day 1: weights 100/200/100 of 400 on returns +10%, 0, +10% -> +5%.
    # Day 2: weights 110/200/110 of 420 on +5% (dividend), -10%, 0 -> (5.5 - 20) / 420.
    # Day 3: weights 110/180/110 of 400 on +10%, 0, -9.0909% -> (11 - 10) / 400.
    want = 1.05 * (1 + (5.5 - 20.0) / 420) * (1 + 1.0 / 400)
    assert b["cap_weight"].iloc[-1] == pytest.approx(want)
    ew = (1 + (0.1 + 0 + 0.1) / 3) * (1 + (0.05 - 0.1 + 0) / 3) * (1 + (0.1 + 0 - 1 / 11) / 3)
    assert b["equal_weight"].iloc[-1] == pytest.approx(ew)
    # Price only would miss A's dividend: the total return is higher.
    from dataclasses import replace
    raw = engine.benchmarks(replace(m, adj=m.close, _ma200=None), member, m.dates[0], m.dates[-1])
    assert b["cap_weight"].iloc[-1] > raw["cap_weight"].iloc[-1]


def _dsr(family):
    rng = np.random.default_rng(7)
    daily = rng.normal(0.0006, 0.01, 2000)
    sr = metrics.sharpe(pd.Series(daily))
    return metrics.deflated_sharpe(sr, len(daily), 0.0, 3.0, len(family) + 1, family + [sr]), sr


def test_two_trials_are_too_few_to_deflate():
    out, sr = _dsr([0.5])
    assert out["deflation_meaningful"] is False and out["distinct_trials"] == 2


def test_rerunning_the_same_rules_is_not_more_trials():
    out, sr = _dsr([0.4] * 9)
    assert out["deflation_meaningful"] is False


def test_enough_trials_deflate_below_the_plain_probability():
    out, sr = _dsr([0.1, 0.3, 0.5, 0.7, 0.9, 1.1])
    assert out["deflation_meaningful"] is True
    assert 0 <= out["dsr"] < out["psr"] <= 1
    assert out["deflated_sharpe"] < sr
