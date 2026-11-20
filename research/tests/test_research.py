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
    # T: Compass-like with profile GBP but a dollar cap: the reporting currency decides.
    q, c, _ = infer_units(2500.0, 25.0 * 1.7e9 / 0.78, 1.7e9, "GBp", fx, reporting_ccy="USD")
    assert (q, c) == ("GBp", "USD")
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
    assert m.loc["BHP.L", "exclude_reason"] == "overseas ISIN"
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


# ---------------------------------------------------------------- W: run 9 builds on day one
def test_trend_strategy_builds_on_day_one_with_gaps_in_the_panel():
    """A 200-day signal must build on the first day when 200+ closes exist,
    even with a bad-tick hole, a missing day, and a date only one line traded."""
    n = 320
    rng = np.random.default_rng(5)
    prices = {f"S{i}.L": list(100 * np.cumprod(1 + rng.normal(0.002, 0.01, n))) for i in range(20)}
    prices["S0.L"][150] = np.nan                    # a dropped bad tick
    for s in list(prices)[1:]:
        prices[s][200] = np.nan                     # a date only S0 traded (thin calendar date)
    m = make_market(prices, start="2021-01-01")
    from research.store import drop_thin_dates
    m = drop_thin_dates(m)
    assert len(m.dates) == n - 1                    # the thin date is not a trading day
    member = pd.DataFrame(True, index=m.dates, columns=m.close.columns)
    from research.pit import Fundamentals
    ctx = Context(m, Fundamentals(pd.DataFrame()))
    d = m.dates[260]
    score = REGISTRY["trend_following"](ctx, d)
    assert score.notna().sum() >= 15                # the averages exist
    rules = engine.Rules(holdings=15, hold_rank=30, exit_ma200=True)
    res = engine.simulate(m, member, member, {d: score}, rules, T212, d, m.dates[-1],
                          engine.ma200_below_twice(m.adj))
    first = res.fills[res.fills["date"] == m.dates[261]]
    assert len(first) == 15 and (first["side"] == "BUY").all()


# ---------------------------------------------------------------- X: price basis
def _ws_with_dividends(cfg):
    n = 420
    rng = np.random.default_rng(9)
    raw = {f"S{i}.L": list(100 * np.cumprod(1 + rng.normal(0.001, 0.01, n))) for i in range(6)}
    adj = {k: list(np.array(v) * np.linspace(0.85, 1.0, n)) for k, v in raw.items()}   # dividends lift the adjusted series
    m = make_market(raw, start="2024-01-01", adj=adj)
    u = build_universe(m, size=6, large=3, start="2024-01-01")
    cfg.raw["universe"]["start"] = "2024-01-01"
    return Workspace(cfg, market=m, universe=u), m


def test_raw_basis_credits_no_dividends(cfg):
    ws, m = _ws_with_dividends(cfg)
    base = {"signals": {"momentum_12_1": 1}, "holdings": 6, "drift_band": 1.0}
    a = ws.run(spec_from_dict({"name": "adj", **base}), start="2025-02-03", end="2025-05-16", write_report=False)
    r = ws.run(spec_from_dict({"name": "raw", **base, "price_basis": "raw"}), start="2025-02-03", end="2025-05-16",
               write_report=False)
    assert a["record"]["price_basis"] == "adjusted" and r["record"]["price_basis"] == "raw"
    assert a["record"]["total_return"] > r["record"]["total_return"]
    # Raw: the value moves with raw closes only, and the benchmark is on the same basis.
    eq_raw = (m.close.loc["2025-02-03":"2025-05-16"].iloc[-1] / m.close.loc["2025-02-03":"2025-05-16"].iloc[0]).mean() - 1
    assert r["record"]["bench_equal_weight_return"] == pytest.approx(eq_raw, abs=0.01)


def test_validate_reports_identical_months_and_passes_on_itself(cfg, tmp_path):
    """Export our own raw-basis run as if it were the N100's: validate must pass with every month identical,
    and with one share swapped in a month it must name that month and the shares."""
    from research.validate import run9
    ws, m = _ws_with_dividends(cfg)
    lst = tmp_path / "n100.tsv"
    lst.write_text("symbol\tis_fund\tsector\n" + "".join(f"{s[:-2]}\tN\tX, Y\n" for s in m.close.columns))
    kw = dict(start="2025-02-03", end="2025-09-30")
    first = run9(ws, lst, None, None, **kw)
    assert first["price_basis"] == "raw"
    from research.strategy import spec_from_dict
    out = ws.run(spec_from_dict({**__import__("research.validate", fromlist=["RUN9"]).RUN9,
                                 "universe": f"list:{cfg.data_dir / 'validation' / 'run9_universe_no_funds.csv'}"}),
                 kind="engine_validation", start=kw["start"], end=kw["end"], write_report=False)
    fills = out["result"].fills
    orders = tmp_path / "orders.csv"
    fills[["date", "symbol", "side", "quantity", "price"]].to_csv(orders, index=False)
    values = tmp_path / "values.csv"
    out["result"].values["total"].rename("total").reset_index().to_csv(values, index=False)
    rep = run9(ws, lst, orders, values, **kw)
    assert rep["passed"] and abs(rep["return_gap_pp"]) < 0.01 and rep["fill_gap_pct"] == 0
    n = rep["months_with_identical_holdings"].split(" of ")
    assert n[0] == n[1]
    # Swap one share in the first month: the first differing month and the two shares are named.
    bad = fills.copy()
    first_buy = bad.index[bad["side"] == "BUY"][0]
    bad.loc[bad["symbol"] == bad.at[first_buy, "symbol"], "symbol"] = "NOTOURS.L"
    bad[["date", "symbol", "side", "quantity", "price"]].to_csv(orders, index=False)
    rep2 = run9(ws, lst, orders, values, **kw)
    diff = rep2["first_month_holdings_differ"]
    assert "NOTOURS.L" in diff["only_n100"] and len(diff["only_ours"]) == 1
    assert {r["symbol"] for r in diff["shares"]} == set(diff["only_n100"]) | set(diff["only_ours"])


# ---------------------------------------------------------------- AB: the N100's 6-month return
def test_six_month_return_is_the_n100s_calendar_definition():
    """First price ON OR AFTER date-6M to last price ON OR BEFORE the date, each share's own prices."""
    dates = pd.bdate_range("2022-05-02", "2022-11-30")
    n = len(dates)
    base = pd.Timestamp("2022-11-01") - pd.DateOffset(months=6)          # Sat 1 May 2022: first price is Mon 2 May
    flat = [100.0] * n
    a = [100.0 + i for i in range(n)]                                   # rising every day
    gap = list(a)
    gap[0] = np.nan; gap[1] = np.nan                                    # no price on the first two days
    short = [np.nan] * 100 + [50.0] * (n - 100)                         # listed part-way through the window
    stale = [100.0] * 40 + [np.nan] * (n - 40)                          # stopped trading; last price long ago
    m = make_market({"FLAT.L": flat, "RISE.L": a, "GAP.L": gap, "SHORT.L": short, "STALE.L": stale},
                    start="2022-05-02")
    ctx = Context(m, Fundamentals(pd.DataFrame()))
    d = pd.Timestamp("2022-11-01")
    det = ctx.n100_return_detail(d)
    i = ctx.pos(d)
    assert det.at["RISE.L", "start"] == "2022-05-02" and det.at["RISE.L", "end"] == "2022-11-01"
    assert det.at["RISE.L", "ret"] == pytest.approx(a[i] / a[0] - 1)
    assert det.at["GAP.L", "start"] == "2022-05-04"                     # the first price on or AFTER the base date
    assert det.at["GAP.L", "ret"] == pytest.approx(a[i] / a[2] - 1)
    assert det.at["SHORT.L", "start"] == str(dates[100].date())         # the return over the history it has
    assert det.at["FLAT.L", "ret"] == 0
    assert det.at["STALE.L", "end"] == str(dates[39].date())            # last price on or before, however old
    assert ctx.n100_return(d).notna().sum() == 5


# ---------------------------------------------------------------- AC: matching the N100's selection and fills
def test_candidate_need_not_have_traded_on_the_rebalance_date_when_the_n100_rule_is_on():
    """strategy_price_asof takes the last price on or before the date. Research's default needs a trade that day,
    so a share that skips a day drops out of the ranking and is replaced; the N100 rule keeps it."""
    n = 40
    prices = {"B.L": [100.0] * n, "C.L": [100.0] * 20 + [np.nan] * 3 + [100.0] * 17}
    m = make_market(prices, start="2024-01-01")
    member = pd.DataFrame(True, index=m.dates, columns=m.close.columns)
    sc = pd.Series({"B.L": 1.0, "C.L": 2.0})                     # C is always the better share
    scores = {d: sc for d in m.dates}
    bought = {}
    for traded_today in (True, False):
        rules = engine.Rules(holdings=1, start_cash=10_000, drift_band=0.5, rebalance="daily",
                             require_traded_today=traded_today)
        res = engine.simulate(m, member, member, scores, rules, T212.free(), m.dates[0], m.dates[-1])
        bought[traded_today] = bool(((res.fills["symbol"] == "B.L") & (res.fills["side"] == "BUY")).any())
    assert bought[True] is True        # C had no close on three dates: B stood in
    assert bought[False] is False      # a stale price is enough, so C was kept throughout


def test_max_orders_refuses_the_whole_rebalance_and_events_say_why():
    prices = {f"S{i}.L": [100.0] * 10 for i in range(8)}
    m = make_market(prices, start="2024-01-01")
    member = pd.DataFrame(True, index=m.dates, columns=m.close.columns)
    sc = pd.Series({f"S{i}.L": float(i) for i in range(8)})
    rules = engine.Rules(holdings=8, start_cash=100_000, max_orders=5)
    res = engine.simulate(m, member, member, {m.dates[0]: sc}, rules, T212.free(), m.dates[0], m.dates[-1])
    assert res.fills.empty and res.refusals == 1
    assert any(e["event"] == "rebalance refused: too many orders" for e in res.events)


def test_events_record_top_up_drift_band_and_minimum_order():
    prices = {"A.L": [100.0] * 80, "B.L": [100.0] * 80}
    prices["A.L"][30:] = [60.0] * 50                       # A falls 40% after the first build: well under target weight
    m = make_market(prices, start="2024-01-01")
    member = pd.DataFrame(True, index=m.dates, columns=m.close.columns)
    feb = m.dates[m.dates.month == 2][0]
    mar = m.dates[m.dates.month == 3][0]
    sc = pd.Series({"A.L": 2.0, "B.L": 1.0})
    rules = engine.Rules(holdings=2, start_cash=100_000, drift_band=0.02)
    res = engine.simulate(m, member, member, {m.dates[0]: sc, feb: sc, mar: sc}, rules, T212.free(), m.dates[0], m.dates[-1])
    kinds = {(e["symbol"], e["event"]) for e in res.events}
    assert ("A.L", "ordered top-up") in kinds                  # outside the band: topped up, like KNOS


def test_two_closes_below_the_average_survive_a_day_the_share_did_not_trade():
    up = list(np.linspace(100, 200, 220))
    a = up + [150.0, np.nan, 140.0, 130.0]                 # below, no trade, below, below
    m = make_market({"A.L": a})
    flags = engine.ma200_below_twice(m.adj, m.ma200)
    assert flags["A.L"].iloc[221]                          # the carried close is still below: two in a row


def test_validate_reports_sells_positions_and_fills_side_by_side(cfg, tmp_path):
    from research.validate import RUN9, run9
    ws, m = _ws_with_dividends(cfg)
    lst = tmp_path / "n100.tsv"
    lst.write_text("symbol\tis_fund\tsector\n" + "".join(f"{s[:-2]}\tN\tX, Y\n" for s in m.close.columns))
    kw = dict(start="2025-02-03", end="2025-09-30")
    run9(ws, lst, None, None, **kw)
    spec = spec_from_dict({**RUN9, "universe": f"list:{cfg.data_dir / 'validation' / 'run9_universe_no_funds.csv'}"})
    out = ws.run(spec, kind="engine_validation", start=kw["start"], end=kw["end"], write_report=False)
    fills = out["result"].fills
    values = tmp_path / "values.csv"
    out["result"].values["total"].rename("total").reset_index().to_csv(values, index=False)
    orders = tmp_path / "orders.csv"
    # The N100 buys one share more of the first holding: same set, different quantity.
    bad = fills.copy()
    i0 = bad.index[bad["side"] == "BUY"][0]
    bad.loc[i0, "quantity"] += 25
    bad[["date", "symbol", "side", "quantity", "price"]].to_csv(orders, index=False)
    rep = run9(ws, lst, orders, values, **kw)
    assert rep["months_with_identical_holdings"].split(" of ")[0] == rep["months_with_identical_holdings"].split(" of ")[1]
    assert rep["months_with_identical_positions (shares and quantities)"].split(" of ")[0] != \
        rep["months_with_identical_positions (shares and quantities)"].split(" of ")[1]
    diff = rep["first_month_holdings_differ"]
    assert diff["quantities"] and diff["quantities"][0]["symbol"] == bad.at[i0, "symbol"]
    assert diff["fills_side_by_side"] and "n100" in diff["fills_side_by_side"][0]
    assert diff["n100_statistics_check"].startswith("not available")
    assert "months_with_identical_sells" in rep and "first_month_sells_differ" in rep
    # With the N100's statistics for the date, a share whose screen membership differs is named.
    d = diff["rebalance_date"]
    s = diff["quantities"][0]["symbol"]
    stats = pd.DataFrame({"symbol": [x[:-2] for x in m.close.columns], "close_price": 100.0, "ma200": 90.0, "6mnth": 5.0})
    stats.loc[stats["symbol"] == s[:-2], "ma200"] = 500.0                 # the N100 has it below its average
    stats.to_csv(cfg.data_dir / "validation" / f"n100_stats_{d}.csv", index=False)
    rep2 = run9(ws, lst, orders, values, **kw)
    chk = rep2["first_month_holdings_differ"]["n100_statistics_check"]
    assert any(r["symbol"] == s for r in chk["screen_membership_differs"]) or chk["values_over_0.1pct_apart"]


# ---------------------------------------------------------------- AD: day one, units, our orders file
def _run9_out(cfg, tmp_path):
    from research.validate import RUN9, run9
    ws, m = _ws_with_dividends(cfg)
    lst = tmp_path / "n100.tsv"
    lst.write_text("symbol\tis_fund\tsector\n" + "".join(f"{s[:-2]}\tN\tX, Y\n" for s in m.close.columns))
    kw = dict(start="2025-02-03", end="2025-09-30")
    rep = run9(ws, lst, None, None, **kw)
    spec = spec_from_dict({**RUN9, "universe": f"list:{cfg.data_dir / 'validation' / 'run9_universe_no_funds.csv'}"})
    out = ws.run(spec, kind="engine_validation", start=kw["start"], end=kw["end"], write_report=False)
    return ws, m, lst, kw, rep, out


def test_our_orders_file_and_the_first_value_the_n100_way(cfg, tmp_path):
    ws, m, lst, kw, rep, out = _run9_out(cfg, tmp_path)
    f = pd.read_csv(cfg.data_dir / "validation" / "run9_ours_orders.csv")
    assert list(f.columns) == ["date", "symbol", "side", "quantity", "price"]
    assert not f["symbol"].str.endswith(".L").any() and len(f) == rep["ours_fills"]
    fv = rep["first_value_the_n100_way"]
    assert fv["fills"] == 6 and fv["value_as_the_n100_writes_it"] < 100_000       # the build's costs are already out
    assert fv["value_as_the_n100_writes_it"] == pytest.approx(
        fv["cash_after_first_build"] + fv["holdings_at_decision_close"], abs=0.01)
    assert out["result"].values["total"].iloc[0] == 100_000                      # ours is still whole on that date


def test_day_one_analysis_fits_one_investable_when_only_the_sizing_base_differs(cfg, tmp_path):
    from research.validate import day_one_analysis
    ws, m, lst, kw, rep, out = _run9_out(cfg, tmp_path)
    d0 = min(out["scores"])
    ours = out["result"].fills
    # The N100 sizes from a slightly different base (multiplier 1.0075 instead of 1.006): same shares, fewer of each.
    ev = next(e for e in out["result"].events if e["event"] == "sizing" and e["date"] == d0)
    n100 = ours.copy()
    close0 = out["market"].close.loc[d0]
    inv = 100_000 / 1.0075
    n100["quantity"] = [float(np.floor(ev["weight"] * inv / close0[s])) if side == "BUY" else q
                        for s, side, q in zip(n100["symbol"], n100["side"], n100["quantity"])]
    res = day_one_analysis(out, n100, ours, d0, 100_000.0)
    assert res["one_investable_fits_all"] is True
    lo, hi = res["n100_implied_multiplier_between"]
    assert lo <= 1.0075 <= hi and res["our_multiplier"] == pytest.approx(ev["multiplier"])
    # One share's quantity off by a lot: no single investable fits.
    bad = n100.copy()
    i0 = bad.index[bad["side"] == "BUY"][0]
    bad.loc[i0, "quantity"] *= 1.3
    res2 = day_one_analysis(out, bad, ours, d0, 100_000.0)
    assert res2["one_investable_fits_all"] is False
    assert res2["fill_price_ratio_min_max"] == [1.0, 1.0]


def test_price_unit_steps_and_statistics_units_normalised(cfg, tmp_path):
    from research.validate import price_unit_steps, run9
    s = pd.Series([3.0, 3.1, 3.05, 305.0, 306.0, 310.0], index=pd.bdate_range("2024-01-01", periods=6))
    steps = price_unit_steps(s)
    assert len(steps) == 1 and steps[0]["date"] == "2024-01-04" and steps[0]["ratio"] > 90
    ws, m, lst, kw, rep, out = _run9_out(cfg, tmp_path)
    first = min(out["scores"])
    orders = tmp_path / "orders.csv"
    fills = out["result"].fills.copy()
    bad = fills.copy()
    bad.loc[bad.index[bad["side"] == "BUY"][0], "quantity"] += 25
    bad[["date", "symbol", "side", "quantity", "price"]].to_csv(orders, index=False)
    values = tmp_path / "values.csv"
    out["result"].values["total"].rename("total").reset_index().to_csv(values, index=False)
    rep1 = run9(ws, lst, orders, values, **kw)
    d = rep1["first_month_holdings_differ"]["rebalance_date"]
    syms = list(m.close.columns)
    stats = pd.DataFrame({"symbol": [x[:-2] for x in syms], "close_price": [m.close[x].loc[:d].iloc[-1] * 100 for x in syms],
                          "ma200": [m.ma200[x].loc[:d].iloc[-1] * 100 for x in syms],
                          "6mnth": 5.0})                          # the N100 stores pence; ours are pounds
    odd = syms[1][:-2]
    stats.loc[stats["symbol"] == odd, ["close_price", "ma200"]] /= 100          # one series in pounds, not pence
    stats.to_csv(cfg.data_dir / "validation" / f"n100_stats_{d}.csv", index=False)
    chk = run9(ws, lst, orders, values, **kw)["first_month_holdings_differ"]["n100_statistics_check"]
    assert chk["unit_baseline_n100_over_ours"] == pytest.approx(100.0, rel=1e-3)
    assert [u["symbol"] for u in chk["unit_mismatches"]] == [syms[1]]
    assert "x100" in chk["unit_mismatches"][0]["reading"]
    assert not chk["screen_membership_differs"]                  # the unit mix-up does not change close vs ma200 here
    qd = rep1["first_month_quantities_differ"]
    assert qd["month"] and qd["shares_that_differ"] == 1 and qd["day_one"]["one_investable_fits_all"] is False
    assert "HFD.L" in rep1["watch_shares"]


# ---------------------------------------------------------------- AE: the N100 handles shares in symbol order
def test_cash_trim_falls_on_the_last_buy_alphabetically_not_the_lowest_ranked():
    """Day one, 2 Nov 2022: the N100 trimmed TRN (last alphabetically), not DOCS (lowest ranked). Prices
    rise 10% overnight so the build needs more cash than there is; the last buy filled is trimmed."""
    from research.engine import n100_symbol_key
    assert n100_symbol_key("BT-A.L") == "BT.A" and n100_symbol_key("TRN.L") == "TRN"
    prices = {s: [100.0] + [110.0] * 9 for s in ("AAA.L", "MMM.L", "ZZZ.L")}
    m = make_market(prices, start="2022-11-01")
    member = pd.DataFrame(True, index=m.dates, columns=m.close.columns)
    # ZZZ is the best ranked, AAA the worst: rank order would trim AAA, symbol order trims ZZZ.
    sc = pd.Series({"ZZZ.L": 3.0, "MMM.L": 2.0, "AAA.L": 1.0})
    rules = engine.Rules(holdings=3, start_cash=30_000, drift_band=0.5)
    from dataclasses import replace
    whole = replace(T212.free(), decimals=0)                  # the N100's paper account: whole shares
    res = engine.simulate(m, member, member, {m.dates[0]: sc}, rules, whole, m.dates[0], m.dates[-1])
    q = res.fills[res.fills["side"] == "BUY"].set_index("symbol")["quantity"]
    assert q["AAA.L"] == q["MMM.L"] == 99          # full target: floor(0.333333 x 30,000 / 100)
    assert 0 < q["ZZZ.L"] < 99                     # the last alphabetically is the one cash ran out on
    assert any(e["event"] == "buy trimmed to fit cash" and e["symbol"] == "ZZZ.L" for e in res.events)


def test_sells_before_buys_trims_then_exits_then_buys_each_in_symbol_order():
    """strategy_plan_orders plans targets (trims and buys) in symbol order, then held shares that are no
    longer targets in symbol order; the executor puts every sell before any buy, stably."""
    n = 40
    names = ("AAA", "BBB", "CCC", "DDD", "EEE", "FFF")
    prices = {f"{s}.L": [100.0] * n for s in names}
    prices["EEE.L"][2:] = [200.0] * (n - 2)           # EEE doubles after the first build: overweight, trimmed
    prices["CCC.L"][2:] = [50.0] * (n - 2)            # CCC halves: underweight, topped up
    m = make_market(prices, start="2024-01-01")
    member = pd.DataFrame(True, index=m.dates, columns=m.close.columns)
    feb = m.dates[m.dates.month == 2][0]
    first = pd.Series({"EEE.L": 6.0, "DDD.L": 5.0, "CCC.L": 4.0, "BBB.L": 3.0, "AAA.L": 2.0, "FFF.L": 1.0})
    second = pd.Series({"AAA.L": 6.0, "CCC.L": 5.0, "EEE.L": 4.0, "FFF.L": 3.0, "BBB.L": 0.5, "DDD.L": 0.4})
    rules = engine.Rules(holdings=4, hold_rank=4, start_cash=100_000, drift_band=0.02)
    res = engine.simulate(m, member, member, {m.dates[0]: first, feb: second}, rules, T212.free(), m.dates[0], m.dates[-1])
    day = res.fills[res.fills["date"] == m.dates[m.dates.get_loc(feb) + 1]].reset_index(drop=True)
    key = lambda s: engine.n100_symbol_key(s)
    sells, buys = day[day["side"] == "SELL"], day[day["side"] == "BUY"]
    assert list(day["side"]) == ["SELL"] * len(sells) + ["BUY"] * len(buys)       # every sell before any buy
    trims = sells[sells["reason"] == "rebalance"]["symbol"].tolist()
    exits = sells[sells["reason"] == "no_longer_selected"]["symbol"].tolist()
    assert trims == ["EEE.L"] and exits == ["BBB.L", "DDD.L"]
    assert list(sells["reason"]) == ["rebalance"] * len(trims) + ["no_longer_selected"] * len(exits)
    assert [key(s) for s in exits] == sorted(key(s) for s in exits)
    assert buys["symbol"].tolist() == ["AAA.L", "CCC.L", "FFF.L"]                  # symbol order, not rank order
