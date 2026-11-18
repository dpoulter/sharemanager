"""The tests the brief lists, section 8, on small hand-made data."""
from __future__ import annotations

import math

import numpy as np
import pandas as pd
import pytest

from conftest import make_market
from research import engine, metrics
from research.costs import T212, Costs, from_dict
from research.periods import HoldoutLocked, check_holdout
from research.pit import Fundamentals
from research.runlog import Frozen, RunLog
from research.signals import Context, REGISTRY
from research.strategy import Workspace, spec_from_dict
from research.universe import build_universe, membership


# ---------------------------------------------------------------- publication lag
def statements(rows):
    df = pd.DataFrame(rows)
    df["period_end"] = pd.to_datetime(df["period_end"])
    return df


def test_signal_on_31_march_cannot_see_december_accounts():
    st = statements([
        {"symbol": "AAA.L", "period_end": "2022-12-31", "freq": "annual", "totalAssets": 100.0, "reportedCurrency": "GBP"},
        {"symbol": "AAA.L", "period_end": "2023-12-31", "freq": "annual", "totalAssets": 200.0, "reportedCurrency": "GBP"},
    ])
    f = Fundamentals(st, annual_lag_months=4, interim_lag_months=3)
    assert f.annual("2024-03-31").loc["AAA.L", "totalAssets"] == 100.0     # 2023 accounts not yet usable
    assert f.annual("2024-04-30").loc["AAA.L", "totalAssets"] == 200.0     # usable from period end + 4 months
    assert f.annual("2024-04-29").loc["AAA.L", "totalAssets"] == 100.0


def test_half_year_lag_and_ttm_from_two_halves():
    st = statements([
        {"symbol": "AAA.L", "period_end": "2023-12-31", "freq": "annual", "revenue": 100.0, "reportedCurrency": "GBP"},
        {"symbol": "AAA.L", "period_end": "2023-12-31", "freq": "interim", "revenue": 55.0, "reportedCurrency": "GBP"},
        {"symbol": "AAA.L", "period_end": "2024-06-30", "freq": "interim", "revenue": 60.0, "reportedCurrency": "GBP"},
    ])
    f = Fundamentals(st)
    assert f.ttm("2024-09-29").loc["AAA.L", "revenue"] == 100.0             # H1 2024 not usable yet
    assert f.ttm("2024-09-30").loc["AAA.L", "revenue"] == 115.0             # H2 2023 + H1 2024


# ---------------------------------------------------------------- delisting
def test_delisted_company_leaves_at_last_price_and_cash_waits():
    n = 60
    prices = {"AAA.L": [10.0] * 30, "BBB.L": [5.0] * n}      # AAA stops trading after day 30
    m = make_market(prices, delisted={"AAA.L": "2020-02-11"})
    member = pd.DataFrame(True, index=m.dates, columns=m.close.columns)
    large = member.copy()
    sc = pd.Series({"AAA.L": 2.0, "BBB.L": 1.0})
    scores = {d: sc for d in m.dates[engine.rebalance_flags(m.dates, "monthly")]}
    scores[m.dates[0]] = sc
    rules = engine.Rules(holdings=2, rebalance="monthly", drift_band=0.0)
    res = engine.simulate(m, member, large, scores, rules, T212.free(), m.dates[0], m.dates[-1])
    sells = res.fills[(res.fills["symbol"] == "AAA.L") & (res.fills["side"] == "SELL")]
    assert len(sells) == 1
    assert sells.iloc[0]["reason"] == "delisted"
    assert sells.iloc[0]["date"] == m.dates[29]
    assert sells.iloc[0]["price"] == 10.0
    # The cash is held, not reinvested, until the next rebalance (first trading day of March).
    v = res.values
    march = v.index[v.index.month == 3][0]
    between = v.loc[m.dates[30]: march - pd.Timedelta(days=1)]
    assert (between["holdings"] == 1).all()
    assert between["cash"].iloc[0] > 49_000


# ---------------------------------------------------------------- universe
def test_share_growing_into_top_350_enters_at_next_rebuild():
    n = 70
    dates = pd.bdate_range("2020-01-01", periods=n)
    caps = {f"S{i:03d}.L": [1e9 - i] * n for i in range(350)}
    grow = [1.0] * n
    mid_jan = 10
    for k in range(mid_jan, n):
        grow[k] = 2e9                       # becomes the largest in mid January
    caps["NEW.L"] = grow
    prices = {k: [1.0] * n for k in caps}
    m = make_market(prices, caps=caps)
    u = build_universe(m, size=350, large=100, start="2020-01-01")
    jan, feb = dates[0], dates[dates.month == 2][0]
    assert "NEW.L" not in set(u[u["date"] == jan]["symbol"])
    assert "NEW.L" in set(u[u["date"] == feb]["symbol"])
    member, _ = membership(u, m.dates)
    assert not member.loc[dates[mid_jan + 2], "NEW.L"]
    assert member.loc[feb, "NEW.L"]
    assert not member.loc[feb, "S349.L"]           # pushed out at the same rebuild


# ---------------------------------------------------------------- costs
def test_costs_worked_examples_section_j():
    """The Trading 212 worked examples from the automated strategies brief, section J."""
    b = T212.buy(1000, 6.60, large=True)                 # a £6,600 FTSE 100 buy
    assert b["stamp_duty"] == pytest.approx(33.00) and b["spread"] == pytest.approx(6.60)
    assert b["commission"] == 0 and b["ptm"] == 0
    b = T212.buy(1000, 6.60, large=False)                # the same in a FTSE 250 share
    assert b["stamp_duty"] == pytest.approx(33.00) and b["spread"] == pytest.approx(16.50)
    s = T212.sell(1000, 12.0, large=True)                # a £12,000 sell: the spread + £1 PTM
    assert s["stamp_duty"] == 0 and s["ptm"] == 1.00
    assert s["total"] == pytest.approx(12.00 + 1.00)
    s = T212.sell(1000, 12.0, large=False)
    assert s["total"] == pytest.approx(30.00 + 1.00)
    assert T212.buy(1000, 6.60, large=False, aim=True)["stamp_duty"] == 0   # AIM: no stamp
    assert T212.buy(1000, 10.0, large=True)["ptm"] == 0  # exactly £10,000 is not over
    assert T212.free().buy(1000, 5.0, large=False)["total"] == 0


def test_periods_validation_starts_2023(cfg):
    assert cfg["periods"]["val"][0] == "2023-01-01"
    assert cfg["periods"]["dev"][1] == "2022-12-31"


# ---------------------------------------------------------------- bad ticks (H)
def test_bad_tick_dropped_real_move_kept():
    from research.clean import bad_tick_positions
    p = np.array([100, 101, 1500, 102, 103, 104, 300, 310, 305, 99, 100, 200, 210, 220, 230.0])
    bad = bad_tick_positions(p)
    assert 2 in bad                      # 15x and back next day
    assert {6, 7, 8} <= set(bad)         # up 3x for three sessions, back within 20%
    assert 11 not in bad                 # a doubling that stays


# ---------------------------------------------------------------- master (F, G)
def test_units_from_prices_not_profile_currency():
    from research.master import infer_units
    fx = pd.DataFrame({"date": pd.to_datetime(["2026-11-02"] * 2), "currency": ["USD", "EUR"],
                       "gbp_per_unit": [0.78, 0.85]})
    # IHG-like: profile says USD, trades at 9,000p, cap reported in USD.
    shares = 160e6
    cap_usd = 90.0 * shares / 0.78
    q, c, _ = infer_units(9000.0, cap_usd, shares, "USD", fx)
    assert (q, c) == ("GBp", "USD")
    # Compass-like: profile EUR (wrongly), trades in pence, cap in pounds.
    q, c, _ = infer_units(2500.0, 25.0 * 1.7e9, 1.7e9, "EUR", fx)
    assert (q, c) == ("GBp", "GBP")
    # No shares known: pence and pounds, as nearly every London line.
    assert infer_units(100.0, 1e9, float("nan"), "USD", fx)[:2] == ("GBp", "GBP")


def test_master_drops_bonds_classes_overseas_and_marks_aim(tmp_path):
    from research.master import refine_master
    from research.fetch import exclusion_reason
    assert exclusion_reason({"symbol": "49GP.L", "name": "Croda International"}).startswith("loan stock")
    n = 300
    dates = pd.bdate_range("2025-01-01", periods=n)
    rng = np.random.default_rng(0)
    rows, caps = [], []
    series = {
        "BP.L": 400 * np.cumprod(1 + rng.normal(0, 0.015, n)),
        "BP-A.L": 400 * np.cumprod(1 + rng.normal(0, 0.015, n)),
        "BT-A.L": 150 * np.cumprod(1 + rng.normal(0, 0.015, n)),
        "BT-B.L": 150 * np.cumprod(1 + rng.normal(0, 0.015, n)),
        "BHP.L": 2000 * np.cumprod(1 + rng.normal(0, 0.015, n)),
        "LOAN.L": 100 + rng.normal(0, 0.05, n),
        "AMS.L": 50 * np.cumprod(1 + rng.normal(0, 0.02, n)),
    }
    vol = {"BT-A.L": 1e6, "BT-B.L": 1e3}
    for s, p in series.items():
        for d, v in zip(dates, p):
            rows.append((s, d, v, v, vol.get(s, 1e5)))
            caps.append((s, d, v * 1e7))
    pd.DataFrame(rows, columns=["symbol", "date", "close", "adj_close", "volume"]).to_parquet(tmp_path / "prices.parquet")
    pd.DataFrame(caps, columns=["symbol", "date", "market_cap"]).to_parquet(tmp_path / "market_cap.parquet")
    isin = {"BP.L": "GB0007980591", "BP-A.L": "GB0007980592", "BT-A.L": "GB0030913577", "BT-B.L": "GB0030913578",
            "BHP.L": "AU000000BHP4", "LOAN.L": "GB00B0000001", "AMS.L": "GB00B1234567"}
    pd.DataFrame({"symbol": list(series), "name": [f"{s} plc" for s in series], "currency": "GBp",
                  "isin": [isin[s] for s in series], "is_aim": None, "is_etf": False, "is_fund": False,
                  "delisted_date": None}).to_parquet(tmp_path / "symbols.parquet")
    (tmp_path / "reference").mkdir()
    pd.DataFrame({"TIDM": ["AMS"], "ISIN": ["GB00B1234567"]}).to_csv(tmp_path / "reference" / "aim_companies.csv", index=False)
    m = refine_master(tmp_path).set_index("symbol")
    kept = set(m.index[m["included"]])
    assert kept == {"BP.L", "BT-A.L", "AMS.L"}
    assert m.loc["BP-A.L", "exclude_reason"] == "second share class (kept BP.L)"
    assert m.loc["BT-B.L", "exclude_reason"] == "second share class (kept BT-A.L)"
    assert m.loc["BHP.L", "exclude_reason"] == "overseas company (ISIN AU)"
    assert m.loc["LOAN.L", "exclude_reason"] == "price series looks like a bond"
    assert bool(m.loc["AMS.L", "is_aim"]) is True


def test_load_market_uses_quote_and_cap_currency(tmp_path):
    """CPG/IHG case: profile currency EUR/USD, quoted in pence; both must rank in the top 100."""
    from research.store import load_market
    dates = pd.bdate_range("2026-10-01", periods=30)
    syms = [f"S{i:03d}.L" for i in range(150)]
    px, cp = [], []
    for i, s in enumerate(syms):
        for d in dates:
            px.append((s, d, 100.0, 100.0))
            cp.append((s, d, 1e8 * (200 - i)))           # £20bn down to £5bn, in pounds
    for d in dates:
        px += [("IHG.L", d, 9000.0, 9000.0), ("CPG.L", d, 2500.0, 2500.0)]
        cp += [("IHG.L", d, 14e9 / 0.78), ("CPG.L", d, 42e9)]   # IHG's cap in USD
    pd.DataFrame(px, columns=["symbol", "date", "close", "adj_close"]).to_parquet(tmp_path / "prices.parquet")
    pd.DataFrame(cp, columns=["symbol", "date", "market_cap"]).to_parquet(tmp_path / "market_cap.parquet")
    pd.DataFrame({"date": dates, "currency": "USD", "gbp_per_unit": 0.78}).to_parquet(tmp_path / "fx.parquet")
    master = pd.DataFrame({"symbol": syms + ["IHG.L", "CPG.L"], "included": True, "is_aim": False,
                           "currency": ["GBp"] * 150 + ["USD", "EUR"], "quote_currency": "GBp",
                           "cap_currency": ["GBP"] * 150 + ["USD", "GBP"], "delisted_date": None})
    master.to_parquet(tmp_path / "symbols.parquet")
    m = load_market(tmp_path)
    assert m.close["IHG.L"].iloc[-1] == pytest.approx(90.0)       # pence to pounds, not dollars
    assert m.market_cap["IHG.L"].iloc[-1] == pytest.approx(14e9)
    u = build_universe(m, size=100, large=100, start="2026-10-01")
    top = u[u["date"] == u["date"].max()].set_index("symbol")["rank"]
    assert top["CPG.L"] <= 100 and top["IHG.L"] <= 100


# ---------------------------------------------------------------- holdout
def test_holdout_lock(cfg):
    with pytest.raises(HoldoutLocked):
        check_holdout(cfg, "2024-01-01", "2025-05-18", final=False)
    assert check_holdout(cfg, "2024-01-01", "2025-05-17", final=False) is False
    assert check_holdout(cfg, "2025-05-18", "2026-11-13", final=True) is True


def _workspace(cfg, n=560):
    rng = np.random.default_rng(1)
    prices = {f"S{i}.L": list(100 * np.cumprod(1 + rng.normal(0.0004, 0.01, n))) for i in range(10)}
    m = make_market(prices, start="2024-01-01")
    u = build_universe(m, size=10, large=5, start="2024-01-01")
    cfg.raw["universe"]["start"] = "2024-01-01"
    return Workspace(cfg, market=m, universe=u)


def test_run_touching_holdout_needs_final_and_is_logged_and_frozen(cfg):
    ws = _workspace(cfg)
    spec = spec_from_dict({"name": "mom", "signals": {"momentum_12_1": 1}, "holdings": 3})
    with pytest.raises(HoldoutLocked):
        ws.run(spec, start="2025-02-03", end="2026-03-01", write_report=False)
    out = ws.run(spec, period="holdout", final=True, start="2025-02-03", end="2026-03-01", write_report=False)
    assert out["record"]["final"] is True
    log = ws.log.read()
    assert bool(log.iloc[-1]["final"]) is True
    with pytest.raises(Frozen):         # only one final exam
        ws.run(spec, final=True, start="2025-02-03", end="2026-03-01", write_report=False)
    changed = spec_from_dict({"name": "mom", "signals": {"momentum_12_1": 1}, "holdings": 4})
    with pytest.raises(Frozen):         # and the settings are frozen
        ws.run(changed, start="2025-02-03", end="2025-05-01", write_report=False)


# ---------------------------------------------------------------- test log
def test_log_is_append_only(tmp_path):
    log = RunLog(tmp_path / "runs.parquet")
    log.append({"run_id": "a", "family": "f", "sharpe": 1.0})
    log.append({"run_id": "b", "family": "f", "sharpe": 0.5})
    assert list(log.read()["run_id"]) == ["a", "b"]
    with pytest.raises(FileExistsError):
        log.append({"run_id": "a", "family": "f", "sharpe": 9.9})
    assert log.read().set_index("run_id").loc["a", "sharpe"] == 1.0
    assert log.trials("f") == 2
    assert not any(hasattr(RunLog, n) for n in ("delete", "remove", "clear", "rewrite"))


def test_every_run_is_logged_with_its_trial_count(cfg):
    ws = _workspace(cfg)
    for h in (3, 4, 5):
        ws.run(spec_from_dict({"name": f"mom{h}", "family": "mom", "signals": {"momentum_12_1": 1},
                               "holdings": h}), start="2025-02-03", end="2025-05-16", write_report=False)
    log = ws.log.read()
    assert (log["family"] == "mom").sum() == 3
    assert log.iloc[-1]["trials"] == 3
    assert (log["fills"] > 0).all()
    for col in ("run_id", "time", "git_commit", "strategy", "signals", "parameters", "universe", "period",
                "final", "annual_return", "max_drawdown", "sharpe", "deflated_sharpe", "strategy_hash"):
        assert col in log.columns


# ---------------------------------------------------------------- pence
def test_690p_with_6600_target_buys_about_956_shares():
    m = make_market({"AAA.L": [6.90] * 5})      # 690p is £6.90
    member = pd.DataFrame(True, index=m.dates, columns=m.close.columns)
    rules = engine.Rules(holdings=1, start_cash=6600 * (1 + 0.005 + 0.0025), drift_band=0.0)
    res = engine.simulate(m, member, ~member, {m.dates[0]: pd.Series({"AAA.L": 1.0})}, rules,
                          from_dict({"decimals": 0}), m.dates[0], m.dates[-1])
    q = res.fills.iloc[0]["quantity"]
    assert q == 956
    res2 = engine.simulate(m, member, ~member, {m.dates[0]: pd.Series({"AAA.L": 1.0})}, rules,
                           T212, m.dates[0], m.dates[-1])
    assert res2.fills.iloc[0]["quantity"] == pytest.approx(956.52, abs=0.01)


def test_fill_is_next_close_and_returns_use_adjusted():
    raw = [10.0, 11.0, 11.0, 11.0]
    adj = [10.0, 11.0, 11.0, 12.1]                 # a 10% dividend's worth on the last day
    m = make_market({"AAA.L": raw}, adj={"AAA.L": adj})
    member = pd.DataFrame(True, index=m.dates, columns=m.close.columns)
    rules = engine.Rules(holdings=1, start_cash=1000, drift_band=0.0)
    res = engine.simulate(m, member, member, {m.dates[0]: pd.Series({"AAA.L": 1.0})}, rules,
                          T212.free(), m.dates[0], m.dates[-1])
    f = res.fills.iloc[0]
    assert f["date"] == m.dates[1] and f["price"] == 11.0
    assert res.values["total"].iloc[-1] == pytest.approx(1000 * 1.1, rel=1e-3)


def test_buffer_keeps_a_holding_inside_hold_rank():
    m = make_market({s: [1.0] * 40 for s in ["A.L", "B.L", "C.L", "D.L"]})     # January and February
    member = pd.DataFrame(True, index=m.dates, columns=m.close.columns)
    feb = m.dates[m.dates.month == 2][0]
    scores = {m.dates[0]: pd.Series({"A.L": 4, "B.L": 3, "C.L": 2, "D.L": 1.0}),
              feb: pd.Series({"A.L": 4, "B.L": 2.5, "C.L": 3, "D.L": 1.0})}
    rules = engine.Rules(holdings=2, hold_rank=3, start_cash=10_000, drift_band=0.5)
    res = engine.simulate(m, member, member, scores, rules, T212.free(), m.dates[0], m.dates[-1])
    held_sells = res.fills[res.fills["side"] == "SELL"]
    assert held_sells.empty                          # B ranks 3rd, inside the buffer: kept
    rules.hold_rank = None
    res = engine.simulate(m, member, member, scores, rules, T212.free(), m.dates[0], m.dates[-1])
    assert set(res.fills[res.fills["side"] == "SELL"]["symbol"]) == {"B.L"}


def test_exit_rule_two_closes_below_ma200():
    up = list(np.linspace(100, 200, 220))
    down = [150.0, 140.0, 130.0, 130.0, 130.0, 130.0]
    m = make_market({"A.L": up + down})
    flags = engine.ma200_below_twice(m.adj)
    first_below = 220
    assert not flags["A.L"].iloc[first_below]
    assert flags["A.L"].iloc[first_below + 1]


# ---------------------------------------------------------------- signals on hand data
def test_price_signals_rank_by_hand():
    n = 300
    a = [100.0] * n
    b = [100.0] * (n - 252) + list(np.linspace(100, 150, 252))     # risen steadily
    c = [100.0] * (n - 252) + list(np.linspace(100, 60, 252))      # fallen steadily
    m = make_market({"A.L": a, "B.L": b, "C.L": c})
    ctx = Context(m, Fundamentals(pd.DataFrame()))
    d = m.dates[-1]
    mom = REGISTRY["momentum_12_1"](ctx, d)
    assert list(mom.rank(ascending=False).sort_values().index) == ["B.L", "A.L", "C.L"]
    hi = REGISTRY["high_52w"](ctx, d)
    assert hi["B.L"] == pytest.approx(1.0) and hi["A.L"] == pytest.approx(1.0) and hi["C.L"] < 0.7
    vol = REGISTRY["low_volatility"](ctx, d)
    assert vol["A.L"] == pytest.approx(0.0) and vol["A.L"] > vol["B.L"]
    rev = REGISTRY["reversal_1m"](ctx, d)
    assert rev["C.L"] > rev["A.L"] > rev["B.L"]


def test_accounting_signals_rank_by_hand():
    rows = []
    for sym, ta0, ta1, gp, ni, cfo, sh0, sh1, eq in [
            ("A.L", 100, 100, 40, 10, 15, 100, 100, 50),
            ("B.L", 100, 50, 20, 10, 5, 120, 100, 20),
            ("C.L", 100, 90, 30, 10, 10, 90, 100, 80)]:
        rows.append({"symbol": sym, "period_end": "2022-12-31", "freq": "annual", "totalAssets": ta1,
                     "weightedAverageShsOut": sh1, "reportedCurrency": "GBP"})
        rows.append({"symbol": sym, "period_end": "2023-12-31", "freq": "annual", "totalAssets": ta0,
                     "grossProfit": gp, "revenue": gp * 2, "netIncome": ni, "operatingCashFlow": cfo,
                     "weightedAverageShsOut": sh0, "totalStockholdersEquity": eq, "reportedCurrency": "GBP"})
    st = statements(rows)
    m = make_market({s: [1.0] * 5 for s in ["A.L", "B.L", "C.L"]}, start="2024-06-03",
                    caps={"A.L": 100.0, "B.L": 100.0, "C.L": 100.0})
    ctx = Context(m, Fundamentals(st))
    d = m.dates[-1]
    order = lambda s: list(s.sort_values(ascending=False).index)
    assert order(REGISTRY["gross_profitability"](ctx, d)) == ["A.L", "C.L", "B.L"]
    assert order(REGISTRY["asset_growth"](ctx, d)) == ["A.L", "C.L", "B.L"]          # 0%, +11%, +100%
    assert order(REGISTRY["accruals"](ctx, d)) == ["A.L", "C.L", "B.L"]              # (-5, 0, +5) / avg TA
    assert order(REGISTRY["net_share_issuance"](ctx, d)) == ["C.L", "A.L", "B.L"]
    assert order(REGISTRY["book_to_market"](ctx, d)) == ["C.L", "A.L", "B.L"]
    ey = REGISTRY["earnings_yield"](ctx, d)
    assert ey["A.L"] == pytest.approx(0.1)


# ---------------------------------------------------------------- deflated Sharpe
def test_deflated_sharpe_falls_with_trials():
    one = metrics.deflated_sharpe(1.0, 2520, 0.0, 3.0, 1)
    many = metrics.deflated_sharpe(1.0, 2520, 0.0, 3.0, 100, [0.2, 0.5, 1.0, -0.3, 0.8])
    assert one["deflated_sharpe"] == pytest.approx(1.0)
    assert many["deflated_sharpe"] < one["deflated_sharpe"]
    assert many["dsr"] < one["dsr"]
