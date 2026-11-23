"""Net payout yield and dividend yield (brief of 23 Nov 2026: tests 1 to 6)."""
from __future__ import annotations

import json
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

from conftest import built_workspace, make_market
from research import indicators
from research import publish as pub
from research.indicators import Context, Fundamentals
from research.indicators import payout as po
from research.universe import build_universe

CAP = 1e6          # make_market: cap = close * 1e6; every close is 1.0 here, so every cap is 1,000,000


def _row(symbol, year, **f):
    return {"symbol": symbol, "period_end": f"{year}-12-31", "freq": "annual", "reportedCurrency": "GBP", **f}


def _accounts():
    """FMP's signs: cash paid out negative, cash raised positive."""
    return pd.DataFrame([
        # DIV pays dividends only: 40,000 / 1,000,000 = 4%
        _row("DIV.L", 2023, commonDividendsPaid=-40_000, commonStockRepurchased=0, commonStockIssuance=0),
        # BUY pays 20,000 and buys back 30,000, issues 5,000: 45,000 / 1,000,000 = 4.5%
        _row("BUY.L", 2023, commonDividendsPaid=-20_000, commonStockRepurchased=-30_000, commonStockIssuance=5_000),
        # ISS pays 10,000 and issues 60,000: -50,000 / 1,000,000 = -5%
        _row("ISS.L", 2023, commonDividendsPaid=-10_000, commonStockRepurchased=0, commonStockIssuance=60_000),
        # GAP has no buyback field at all: no net payout yield, but a dividend yield
        _row("GAP.L", 2023, commonDividendsPaid=-10_000, commonStockIssuance=0),
        # ZERO pays nothing, every field present and zero: a value of 0
        _row("ZERO.L", 2023, commonDividendsPaid=0, commonStockRepurchased=0, commonStockIssuance=0),
        # LAG: 2022 pays 10,000, 2023 pays 50,000
        _row("LAG.L", 2022, commonDividendsPaid=-10_000, commonStockRepurchased=0, commonStockIssuance=0),
        _row("LAG.L", 2023, commonDividendsPaid=-50_000, commonStockRepurchased=0, commonStockIssuance=0),
    ])


SYMS = ["DIV.L", "BUY.L", "ISS.L", "GAP.L", "ZERO.L", "LAG.L"]


def _ctx(statements=None):
    m = make_market({s: [1.0] * 400 for s in SYMS}, start="2024-01-01")
    return Context(m, Fundamentals(_accounts() if statements is None else statements, 4, 3))


def _value(name, date, statements=None):
    return indicators.REGISTRY[name](_ctx(statements), pd.Timestamp(date))


# ---------------------------------------------------------------- tests 1 and 2
def test_1_hand_made_accounts_give_the_hand_worked_yields_one_negative():
    v = _value("net_payout_yield", "2024-06-28")
    assert v["DIV.L"] == pytest.approx(0.04)
    assert v["BUY.L"] == pytest.approx(0.045)
    assert v["ISS.L"] == pytest.approx(-0.05)
    d = _value("dividend_yield", "2024-06-28")
    assert d["DIV.L"] == pytest.approx(0.04) and d["BUY.L"] == pytest.approx(0.02) and d["ISS.L"] == pytest.approx(0.01)


def test_2_fmps_signs_negative_paid_out_raises_positive_issuance_lowers():
    st = _accounts()
    flipped = st.copy()                      # the same company with the signs the other way round
    for c in ("commonDividendsPaid", "commonStockRepurchased", "commonStockIssuance"):
        flipped[c] = -flipped[c]
    a = _value("net_payout_yield", "2024-06-28", st)
    b = _value("net_payout_yield", "2024-06-28", flipped)
    pd.testing.assert_series_equal(a, b)     # the size is taken: same answer either way
    more = st.copy()
    more.loc[more["symbol"] == "DIV.L", "commonStockIssuance"] = 10_000
    assert _value("net_payout_yield", "2024-06-28", more)["DIV.L"] == pytest.approx(0.03)
    assert a["DIV.L"] > 0


# ---------------------------------------------------------------- test 3
def test_3_a_missing_field_gives_no_value_a_zero_field_gives_zero():
    v = _value("net_payout_yield", "2024-06-28")
    assert np.isnan(v["GAP.L"])
    assert v["ZERO.L"] == 0.0
    assert _value("dividend_yield", "2024-06-28")["GAP.L"] == pytest.approx(0.01)


# ---------------------------------------------------------------- test 4
def test_4_accounts_to_31_december_are_not_used_before_30_april():
    assert _value("net_payout_yield", "2024-04-29")["LAG.L"] == pytest.approx(0.01)
    assert _value("net_payout_yield", "2024-04-30")["LAG.L"] == pytest.approx(0.05)
    assert "DIV.L" not in _value("net_payout_yield", "2024-04-29").dropna().index


# ---------------------------------------------------------------- test 5 and the constraints
def test_5_no_ready_made_yield_and_no_sector_is_read():
    src = Path(po.__file__).read_text()
    code = src.split('"""', 2)[2]
    for name in ("dividendYield", "YieldTTM", "shareholder_yield", "payoutRatio", "sector", "industry"):
        assert name.lower() not in code.lower(), name
    st = _accounts()
    st["dividendYieldTTM"] = 0.99
    st["shareholder_yield"] = 0.99
    pd.testing.assert_series_equal(_value("net_payout_yield", "2024-06-28", st), _value("net_payout_yield", "2024-06-28"))


def test_the_cap_and_the_accounts_are_in_one_currency():
    st = _accounts()
    st.loc[st["symbol"] == "DIV.L", "reportedCurrency"] = "USD"
    ctx = _ctx(st)
    ctx.m.fx_rate = lambda c, d: 0.8 if c == "USD" else 1.0      # one dollar is 80p
    v = indicators.REGISTRY["net_payout_yield"](ctx, pd.Timestamp("2024-06-28"))
    assert v["DIV.L"] == pytest.approx(0.032)


def test_stored_under_their_names_from_2000():
    for name in ("net_payout_yield", "dividend_yield"):
        d = indicators.REGISTRY[name]
        assert d.keys() == [name] and d.start == "2000-01-01"


# ---------------------------------------------------------------- test 6
@pytest.fixture
def built(cfg):
    from test_publish import _accounts as acc
    rng = np.random.default_rng(2)
    syms = [f"S{i}.L" for i in range(5)]
    prices = {s: list(100 * np.cumprod(1 + rng.normal(0.0004, 0.01, 260))) for s in syms}
    st = acc(syms)
    st["commonDividendsPaid"] = -3e6
    st["commonStockRepurchased"] = -1e6
    st["commonStockIssuance"] = 5e5
    m = make_market(prices, start="2024-01-01", statements=st)
    u = build_universe(m, size=5, large=2, start="2024-01-01")
    cfg.raw["universe"]["start"] = "2024-01-01"
    built_workspace(cfg, m, u, names=pub.PUBLISHED + pub.ACCOUNTS + ["dividend_yield"])
    mk = pub.fetch_marker(cfg)
    mk.parent.mkdir(parents=True, exist_ok=True)
    mk.write_text(json.dumps({"ok": True}))
    return cfg, m, u


def test_6_the_publish_carries_net_payout_yield_with_its_history_and_not_dividend_yield(built):
    from test_publish import FakeImport
    from research import data_version as dv
    from research.indicator_store import IndicatorStore
    cfg, m, _ = built
    api = FakeImport(["S0", "S1", "S2", "S3", "S4"], version=dv.current(cfg.data_dir), latest=str(m.dates[-3].date()))
    pub.publish(cfg, api=api, market=m)
    _, stats = api.sent()
    mine = {(r[0], r[1]) for r in stats if r[2] == "net_payout_yield"}
    frame = IndicatorStore.for_config(cfg).frame("net_payout_yield")
    frame = frame[frame["date"].dt.day_of_week < 5]
    assert mine == {(s.removesuffix(".L"), str(d.date())) for s, d in zip(frame["symbol"], frame["date"])}
    assert len(mine) > 100
    assert "net_payout_yield" in api.calls[-1]["statistic_from"]
    assert "dividend_yield" not in {r[2] for r in stats}


def test_6_a_failed_net_payout_yield_stops_the_publish(built):
    from test_publish import FakeImport
    cfg, m, _ = built
    with (cfg.p("logs") / "indicator_job.jsonl").open("a") as fh:
        fh.write(json.dumps({"indicator": "net_payout_yield", "ok": False, "reason": "ValueError: boom"}) + "\n")
    api = FakeImport(["S0"])
    with pytest.raises(pub.PublishRefused, match="net_payout_yield: the last indicator job failed"):
        pub.publish(cfg, api=api, market=m)
    assert api.calls == []


def test_the_two_strategies_run(built):
    from research import indicator_job
    from research.strategy import load_spec
    cfg, m, u = built
    ws = indicator_job.workspace(cfg, market=m, universe=u)
    root = Path(__file__).resolve().parents[1] / "strategies"
    for f in ("net_payout_q5.yaml", "dividend_yield_q5.yaml"):
        spec = load_spec(root / f)
        assert spec.family == "payout"
        r = ws.run(spec, start=m.dates[0], end=m.dates[-1], write_report=False)["record"]
        assert r["coverage_share_median"] == 1.0
