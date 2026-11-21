"""Dividends credited as cash (backtest_engine, 21 Nov 2026): the brief's tests 1, 2, 3 and 5."""
from __future__ import annotations

import numpy as np
import pandas as pd
import pytest

from conftest import built_workspace, make_market
from research import engine
from research.costs import T212
from research.store import dividends_in_pounds
from research.strategy import spec_from_dict
from research.universe import build_universe

FREE = T212.free()
N = 40


def _market(divs):
    m = make_market({"A.L": [10.0] * N, "B.L": [10.0] * N}, start="2024-01-01")
    m.dividends = pd.DataFrame(divs, columns=["symbol", "ex_date", "pay_date", "amount", "kind"])
    return m


def _div(m, ex=5, pay=20, amount=0.10, kind="cash", sym="A.L"):
    return [(sym, m.dates[ex], m.dates[pay] if pay is not None else m.dates[ex], amount, kind)]


def _run(m, scores, rules, end=None, exit_flags=None, dividends=True):
    member = pd.DataFrame(True, index=m.dates, columns=m.close.columns)
    large = member.copy()
    return engine.simulate(m, member, large, scores, rules, FREE, m.dates[0], end or m.dates[-1], exit_flags,
                           m.dividends if dividends else None)


def _hold_a(m):
    return {d: pd.Series({"A.L": 1.0, "B.L": np.nan}) for d in m.dates}


def test_1000_shares_10p_ex_day_5_paid_day_20_lands_100_pounds_on_day_20():
    m0 = make_market({"A.L": [10.0] * N}, start="2024-01-01")
    m = _market(_div(m0))
    r = _run(m, _hold_a(m), engine.Rules(holdings=1, start_cash=10_000, drift_band=1.0))
    cash = r.values["cash"]
    assert r.fills.iloc[0]["quantity"] == 1000
    assert cash.iloc[19] == pytest.approx(0.0)
    assert cash.iloc[20] - cash.iloc[19] == pytest.approx(100.0, abs=1e-9)
    assert r.dividends_received == pytest.approx(100.0)
    assert list(r.dividends["status"]) == ["credited"]


def test_a_share_sold_on_day_4_gets_nothing():
    m = _market(_div(make_market({"A.L": [10.0] * N}, start="2024-01-01")))
    flags = pd.DataFrame(False, index=m.dates, columns=m.close.columns)
    flags.iloc[3, 0] = True                      # decided on day 3, sold at day 4's close
    r = _run(m, _hold_a(m), engine.Rules(holdings=1, start_cash=10_000, drift_band=1.0, exit_ma200=True),
             exit_flags=flags)
    sold = r.fills[r.fills["side"] == "SELL"]
    assert sold.iloc[0]["date"] == m.dates[4]
    assert r.dividends_received == 0.0 and r.values["cash"].iloc[20] == r.values["cash"].iloc[19]


def test_a_share_bought_on_day_5_gets_nothing():
    m = _market(_div(make_market({"A.L": [10.0] * N}, start="2024-01-01")))
    scores = {m.dates[0]: pd.Series(dtype=float), m.dates[4]: pd.Series({"A.L": 1.0})}
    r = _run(m, scores, engine.Rules(holdings=1, start_cash=10_000, drift_band=1.0,
                                     rebalance_asof=(m.dates[0], m.dates[4])))
    assert r.fills.iloc[0]["date"] == m.dates[5] and r.fills.iloc[0]["side"] == "BUY"
    assert r.dividends_received == 0.0


def test_dividend_cash_waits_for_the_next_rebalance():
    m = _market(_div(make_market({"A.L": [10.0] * N}, start="2024-01-01")))
    r = _run(m, _hold_a(m), engine.Rules(holdings=1, start_cash=10_000, drift_band=0.0))
    cash = r.values["cash"]
    nxt = int(np.flatnonzero(m.dates.month == 2)[0])      # the February rebalance, decided on its first day
    assert all(cash.iloc[k] == pytest.approx(100.0) for k in range(20, nxt + 1))     # held, not invested
    buys = r.fills[(r.fills["side"] == "BUY") & (r.fills["date"] > m.dates[1])]
    assert buys.iloc[0]["date"] == m.dates[nxt + 1] and buys.iloc[0]["quantity"] == 10   # 100 pounds, 10 shares
    assert cash.iloc[nxt + 1] == pytest.approx(0.0)


def test_a_payment_after_the_run_end_is_not_credited_and_is_counted():
    m = _market(_div(make_market({"A.L": [10.0] * N}, start="2024-01-01"), ex=5, pay=30))
    r = _run(m, _hold_a(m), engine.Rules(holdings=1, start_cash=10_000, drift_band=1.0), end=m.dates[25])
    assert r.dividends_received == 0.0
    assert r.dividends_after_end == 1 and r.dividends_after_end_cash == pytest.approx(100.0)
    assert r.values["cash"].iloc[-1] == pytest.approx(0.0)


def test_scrip_is_skipped_and_counted():
    m = _market(_div(make_market({"A.L": [10.0] * N}, start="2024-01-01"), kind="scrip"))
    r = _run(m, _hold_a(m), engine.Rules(holdings=1, start_cash=10_000, drift_band=1.0))
    assert r.dividends_received == 0.0 and r.dividends_skipped_noncash == 1


def test_the_ex_date_holding_is_measured_after_that_days_fills_and_no_payment_is_lost_to_bad_dates():
    m = _market(_div(make_market({"A.L": [10.0] * N}, start="2024-01-01"), ex=5, pay=3))   # paid "before" ex
    r = _run(m, _hold_a(m), engine.Rules(holdings=1, start_cash=10_000, drift_band=1.0))
    assert r.dividends_received == pytest.approx(100.0)
    assert r.values["cash"].iloc[5] - r.values["cash"].iloc[4] == pytest.approx(100.0)


def test_pence_dividends_become_pounds_by_the_price_rule():
    factor = pd.DataFrame({"A.L": [0.01] * 3, "B.L": [1.0] * 3}, index=pd.bdate_range("2024-01-01", periods=3))
    raw = pd.DataFrame({"symbol": ["A.L", "B.L"], "ex_date": ["2024-01-02", "2024-01-02"], "amount": [10.0, 0.25],
                        "payment_date": ["2024-02-01", None]})
    d = dividends_in_pounds(raw, factor).set_index("symbol")
    assert d.loc["A.L", "amount"] == pytest.approx(0.10) and d.loc["B.L", "amount"] == pytest.approx(0.25)
    assert d.loc["A.L", "pay_date"] == pd.Timestamp("2024-02-01")
    assert d.loc["B.L", "pay_date"] == pd.Timestamp("2024-01-02")       # no payment date: the ex-date
    assert set(d["kind"]) == {"cash"}


def _ws(cfg):
    n = 420
    rng = np.random.default_rng(4)
    prices = {f"S{i}.L": list(100 * np.cumprod(1 + rng.normal(0.0008, 0.01, n))) for i in range(6)}
    m = make_market(prices, start="2024-01-01")
    rows = [(s, d, d + pd.Timedelta(days=7), 2.0, "cash") for s in prices for d in m.dates[30::21]]
    m.dividends = pd.DataFrame(rows, columns=["symbol", "ex_date", "pay_date", "amount", "kind"])
    u = build_universe(m, size=6, large=3, start="2024-01-01")
    cfg.raw["universe"]["start"] = "2024-01-01"
    return built_workspace(cfg, m, u, names=["momentum_12_1"]), m


def test_dividends_off_equals_the_engine_before_dividends_to_the_penny(cfg):
    ws, m = _ws(cfg)
    base = {"signals": {"momentum_12_1": 1}, "holdings": 3, "price_basis": "raw"}
    off = ws.run(spec_from_dict({"name": "off", **base, "dividends": "off"}), start="2025-02-03", end="2025-05-16",
                 write_report=False)
    saved = m.dividends
    m.dividends = pd.DataFrame(columns=saved.columns)        # as if the engine had no dividends at all
    none = ws.run(spec_from_dict({"name": "none", **base, "dividends": "off"}), start="2025-02-03",
                  end="2025-05-16", write_report=False)
    m.dividends = saved
    pd.testing.assert_frame_equal(off["result"].values, none["result"].values)
    pd.testing.assert_frame_equal(off["result"].fills, none["result"].fills)
    on = ws.run(spec_from_dict({"name": "on", **base}), start="2025-02-03", end="2025-05-16", write_report=True)
    assert on["record"]["dividends"] == "credited as cash" and off["record"]["dividends"] == "off"
    assert on["record"]["dividends_received"] > 0 and off["record"]["dividends_received"] == 0
    assert on["record"]["total_return"] > off["record"]["total_return"]
    log = ws.log.read()
    assert "dividends" in log.columns and set(log["dividends"]) >= {"off", "credited as cash"}
    text = on["report"].read_text()
    assert "Dividends received" in text and "dividends included" in text


def test_on_the_adjusted_basis_dividends_are_not_credited_twice(cfg):
    ws, m = _ws(cfg)
    r = ws.run(spec_from_dict({"name": "adj", "signals": {"momentum_12_1": 1}, "holdings": 3}),
               start="2025-02-03", end="2025-05-16", write_report=False)
    assert r["record"]["dividends"] == "in the adjusted price" and r["record"]["dividends_received"] == 0


def test_the_run9_check_runs_with_dividends_off():
    from research.validate import RUN9
    assert RUN9["dividends"] == "off"


def test_a_change_to_dividends_changes_the_data_version():
    from research import data_version as dv
    assert "dividends.parquet" in dv.FILES
    m = _market(_div(make_market({"A.L": [10.0] * N}, start="2024-01-01")))
    a = dv.snapshot(m, None)
    m.dividends.loc[0, "amount"] = 0.11
    b = dv.snapshot(m, None)
    changed = dv.diff(a, b, dv.daily_from(m.dates))
    assert ("dividends", "A.L") in set(zip(changed["panel"], changed["symbol"]))
