"""price_clean (contracts/modules.yaml, 23 Nov 2026): the brief's tests 1 to 7, and the gap check.

The series are built to look like the cases Jarvis found (prices in pence, as prices.parquet):
Lookers 115p to 8,700p in January 2008, RGO.L x106.7 on 19 Oct 2005, Marston's adjusted close up
677% in April 2009 while the raw close rose 11%.
"""
from __future__ import annotations

import numpy as np
import pandas as pd
import pytest

from research import data_version as dv
from research import price_clean as pc
from research.fmp import FmpRestricted
from research.store import load_market, moving_average

D = pd.bdate_range("2004-01-01", "2010-12-31")


def walk(level, n, seed, vol=0.01):
    return level * np.exp(np.cumsum(np.random.default_rng(seed).normal(0, vol, n)))


def at(day) -> int:
    return int(D.searchsorted(pd.Timestamp(day)))


def series() -> dict[str, tuple[np.ndarray, np.ndarray]]:
    """symbol -> (close, adj), each over D."""
    n = len(D)
    out = {}
    # Lookers: ~115p to the end of 2007, then a real fall to ~87p read as 8,700p until the end of April 2009.
    true = np.r_[walk(115, at("2008-01-02"), 1), walk(87, n - at("2008-01-02"), 2)]
    c = true.copy()
    a0, a1 = at("2008-01-02"), at("2009-05-01")
    c[a0:a1] *= 100
    out["LOOK.L"] = (c, c * 0.9)
    # RGO.L: x106.7 from 19 Oct 2005 for five sessions (longer than the bad-tick window), then back.
    t = walk(400, n, 3)
    c = t.copy()
    i = at("2005-10-19")
    c[i:i + 5] *= 106.7
    out["RGO.L"] = (c, c)
    # A real 1-for-10 consolidation on 2006-06-01, recorded by FMP.
    t = walk(20, n, 4)
    s = at("2006-06-01")
    c = t.copy()
    c[s:] *= 10
    adj = t * 10                     # FMP's adjusted series is smooth across it
    out["CONS.L"] = (c, adj)
    # A real collapse: down 90% on 2008-10-10 and staying there.
    t = walk(300, n, 5)
    t[at("2008-10-10"):] *= 0.1
    out["CRASH.L"] = (t, t)
    # A pence and pounds stretch: 60 sessions quoted in pounds.
    t = walk(250, n, 6)
    c = t.copy()
    c[at("2007-03-01"):at("2007-03-01") + 60] /= 100
    out["PP.L"] = (c, c)
    # A one-day spike, x3 and back the next day.
    t = walk(50, n, 7)
    c = t.copy()
    c[at("2006-02-14")] *= 3
    out["SPIKE.L"] = (c, c)
    # A x4 jump for eight sessions, then back: rule 4.
    t = walk(80, n, 8)
    c = t.copy()
    c[at("2009-09-01"):at("2009-09-01") + 8] *= 4
    out["REV.L"] = (c, c)
    # Marston's: the adjusted close a seventh of what it should be until April 2009, the raw close normal.
    raw = walk(120, n, 9)
    out["MRO.L"] = (raw, None)
    return out


MRO_DIVS = pd.DataFrame({"symbol": "MRO.L", "ex_date": pd.to_datetime(["2005-06-01", "2006-06-01", "2007-06-01",
                                                                       "2008-06-04", "2009-06-03", "2010-06-02"]),
                         "amount": [4.0, 4.5, 5.0, 5.0, 3.0, 3.0], "payment_date": None, "kind": "cash"})
SPLITS = {"CONS.L": [{"symbol": "CONS.L", "date": "2006-06-01", "numerator": 1, "denominator": 10}]}


class FakeFmp:
    def __init__(self, refuse=False):
        self.refuse, self.asked = refuse, []

    def get(self, path, **params):
        assert path == "splits"
        self.asked.append(params["symbol"])
        if self.refuse:
            raise FmpRestricted("splits", "Restricted Endpoint: not available under your current subscription")
        return SPLITS.get(params["symbol"], [])


def write_data(d, drop: dict | None = None):
    d.mkdir(parents=True, exist_ok=True)
    rows = []
    for sym, (c, a) in series().items():
        if a is None:            # MRO.L: FMP's adjusted close, wrong until April 2009
            a = pc.rebuilt_adjusted(c, D.to_numpy(), MRO_DIVS, None)
            a[:at("2009-04-15")] /= 7.77
        f = pd.DataFrame({"symbol": sym, "date": D, "close": c, "adj_close": a, "volume": 1e5})
        if drop and sym in drop:
            f = f[~f["date"].isin(drop[sym])]
        rows.append(f)
    pd.concat(rows, ignore_index=True).to_parquet(d / "prices.parquet")
    syms = list(series())
    pd.DataFrame({"symbol": syms, "included": True, "is_aim": False, "currency": "GBp", "quote_currency": "GBp",
                  "delisted_date": None}).to_parquet(d / "symbols.parquet")
    MRO_DIVS.to_parquet(d / "dividends.parquet")


@pytest.fixture
def data(cfg):
    write_data(cfg.data_dir)
    dv.record(cfg, "fetch --full", full=True)
    return cfg.data_dir


def fixes_of(d, sym):
    f = pd.read_parquet(d / pc.FIXES)
    return f[f["symbol"] == sym].set_index("date")


def test_1_lookers_january_2008_is_caught_and_fixed(cfg, data):
    out = pc.run(cfg, client=FakeFmp())
    f = fixes_of(data, "LOOK.L")
    assert set(f["rule"]) == {"1"}
    assert f.index.min() == pd.Timestamp("2008-01-02") and f.index.max() == pd.Timestamp("2009-04-30")
    m = load_market(data)
    jan = m.close.loc["2008-01-02", "LOOK.L"] * 100          # back in pence
    assert 70 < jan < 110
    r = m.close["LOOK.L"].pct_change().abs()
    assert r.max() < 0.5 and out["version"] > 1          # the real fall into 2008 stays


def test_2_rgo_october_2005_is_caught_and_fixed(cfg, data):
    pc.run(cfg, client=FakeFmp())
    f = fixes_of(data, "RGO.L")
    assert list(f.index) == list(D[at("2005-10-19"):at("2005-10-19") + 5]) and set(f["rule"]) == {"1"}
    assert (f["close_after"] / f["close_before"]).tolist() == pytest.approx([0.01] * 5)
    assert load_market(data).close["RGO.L"].pct_change().abs().max() < 0.2


def test_3_a_recorded_1_for_10_consolidation_is_left_alone(cfg, data):
    pc.run(cfg, client=FakeFmp())
    assert fixes_of(data, "CONS.L").empty
    rep = pd.read_csv(data / pc.REPORT_CSV)
    assert not (rep["symbol"] == "CONS.L").any()


def test_4_a_real_collapse_is_left_alone_and_reported(cfg, data):
    pc.run(cfg, client=FakeFmp())
    assert fixes_of(data, "CRASH.L").empty
    rep = pd.read_csv(data / pc.REPORT_CSV)
    r = rep[rep["symbol"] == "CRASH.L"]
    assert len(r) and r["finding"].str.contains("stays").all() and set(r["kind"]) == {"reported"}


def test_5_a_pence_and_pounds_stretch_is_rescaled(cfg, data):
    pc.run(cfg, client=FakeFmp())
    f = fixes_of(data, "PP.L")
    i = at("2007-03-01")
    assert list(f.index) == list(D[i:i + 60]) and set(f["rule"]) == {"1"}
    assert (f["close_after"] / f["close_before"]).tolist() == pytest.approx([100.0] * 60)


def test_spike_marstons_and_rule_4(cfg, data):
    pc.run(cfg, client=FakeFmp())
    s = fixes_of(data, "SPIKE.L")                       # rule 2: the previous close
    assert list(s.index) == [D[at("2006-02-14")]] and s["rule"].iloc[0] == "2"
    assert s["close_after"].iloc[0] == pytest.approx(series()["SPIKE.L"][0][at("2006-02-14") - 1])
    mro = fixes_of(data, "MRO.L")                       # rule 3: rebuilt from raw and dividends
    assert set(mro["rule"]) == {"3"} and mro.index.max() < pd.Timestamp("2009-04-15")
    assert mro["close_after"].tolist() == pytest.approx(mro["close_before"].tolist())
    m = load_market(data)
    assert m.adj["MRO.L"].pct_change().abs().max() < 0.2
    rev = fixes_of(data, "REV.L")                       # rule 4: unusable, no price
    i = at("2009-09-01")
    assert list(rev.index) == list(D[i:i + 8]) and not rev["usable"].any() and set(rev["rule"]) == {"4"}
    assert m.close.loc[D[i:i + 8], "REV.L"].isna().all() and m.adj.loc[D[i:i + 8], "REV.L"].isna().all()


def test_the_change_is_a_data_version_naming_the_companies(cfg, data):
    v0 = dv.current(data)
    out = pc.run(cfg, client=FakeFmp())
    assert out["version"] == v0 + 1
    ch = dv.changes_since(data, v0)
    assert set(ch["reason"]) == {"price_clean"}
    named = set(ch["symbol"])
    assert {"LOOK.L", "RGO.L", "PP.L", "SPIKE.L", "MRO.L", "REV.L"} <= named
    assert not named & {"CONS.L", "CRASH.L"}
    look = ch[(ch["symbol"] == "LOOK.L") & (ch["panel"] == "close")].iloc[0]
    assert look["from"] == pd.Timestamp("2008-01-01") and look["to"] >= pd.Timestamp("2009-04-30")


def test_6_a_second_run_changes_nothing(cfg, data):
    fmp = FakeFmp()
    first = pc.run(cfg, client=fmp)
    before = pd.read_parquet(data / pc.FIXES)
    asked = list(fmp.asked)
    second = pc.run(cfg, client=fmp)
    assert second["version"] == first["version"] and second["changed"] == 0
    pd.testing.assert_frame_equal(before, pd.read_parquet(data / pc.FIXES))
    assert fmp.asked == asked                       # nothing asked of FMP again either


def test_7_a_refused_split_endpoint_is_named_rules_1_to_3_run_rule_4_reports(cfg, data):
    with pytest.raises(FmpRestricted) as e:
        pc.run(cfg, client=FakeFmp(refuse=True))
    assert e.value.endpoint == "splits"
    f = pd.read_parquet(data / pc.FIXES)
    assert {"1", "2", "3"} <= set(f["rule"]) and f["usable"].all()      # nothing made unusable
    rep = pd.read_csv(data / pc.REPORT_CSV)
    rev = rep[rep["symbol"] == "REV.L"]
    assert rev["finding"].str.contains("report only").any()
    assert "FMP refused `splits`" in (data / pc.REPORT_MD).read_text()
    assert dv.changes_since(data, 1)["reason"].eq("price_clean").all()   # rules 1 to 3 recorded


def test_7_the_night_stops_naming_the_endpoint(cfg, data, monkeypatch):
    import json

    from research import cli, fetch
    monkeypatch.setattr(fetch.Fetcher, "__init__", lambda self, cfg, client=None: None)
    monkeypatch.setattr(fetch.Fetcher, "run", lambda self, **k: {})
    monkeypatch.setattr(cli, "_fetch_extras", lambda cfg, full: None)
    real = pc.run
    monkeypatch.setattr(pc, "run", lambda cfg: real(cfg, client=FakeFmp(refuse=True)))
    a = type("A", (), {"profile_country": False, "trust_caps": False, "full": False, "merge_only": False})()
    with pytest.raises(SystemExit) as e:
        cli.cmd_fetch(cfg, a)
    assert "splits" in str(e.value)
    st = json.loads((cfg.p("logs") / "fetch_last.json").read_text())
    assert st["refused_endpoint"] == "splits" and st["ok"] is False

def test_overrides_win_and_need_a_reason(cfg, data, tmp_path):
    ov = tmp_path / "ov.yaml"
    ov.write_text("- {symbol: REV.L, from: 2009-09-01, to: 2009-09-30, action: keep, reason: checked the RNS}\n"
                  "- {symbol: CRASH.L, date: 2008-10-10, action: exclude, reason: suspended that day}\n"
                  "- {symbol: SPIKE.L, date: 2006-02-14, action: set, close: 51.5, reason: LSE close}\n")
    cfg.raw["price_clean"] = {"overrides": str(ov)}
    pc.run(cfg, client=FakeFmp())
    assert fixes_of(data, "REV.L").empty
    c = fixes_of(data, "CRASH.L")
    assert len(c) == 1 and not c["usable"].iloc[0] and c["note"].iloc[0] == "suspended that day"
    s = fixes_of(data, "SPIKE.L")
    assert s["close_after"].iloc[0] == 51.5 and s["rule"].iloc[0] == "override: set"
    ov.write_text("- {symbol: REV.L, date: 2009-09-01, action: keep}\n")
    with pytest.raises(ValueError, match="reason"):
        pc.load_overrides(ov)


def test_the_overrides_file_in_the_repo_loads():
    pc.load_overrides()


def test_an_unusable_date_reads_as_a_day_with_no_trade(cfg, tmp_path):
    """The 200-day average and the momentum inputs see an unusable date exactly as a non-trading day."""
    i = at("2009-09-01")
    gone = list(D[i:i + 8])
    write_data(cfg.data_dir)
    dv.record(cfg, "fetch --full", full=True)
    pc.run(cfg, client=FakeFmp(), record_version=False)
    cleaned = load_market(cfg.data_dir)
    other = tmp_path / "other"
    write_data(other, drop={"REV.L": gone})
    (other / pc.FIXES).write_bytes((cfg.data_dir / pc.FIXES).read_bytes())
    missing = load_market(other)
    pd.testing.assert_series_equal(cleaned.adj["REV.L"], missing.adj["REV.L"])
    ma_a, ma_b = moving_average(cleaned.adj[["REV.L"]]), moving_average(missing.adj[["REV.L"]])
    pd.testing.assert_frame_equal(ma_a, ma_b)
    assert ma_a["REV.L"].loc[gone].notna().all()      # the average carries over the gap as over a holiday
    from research.indicators.base import Context
    from research.indicators.pit import Fundamentals
    ca, cb = (Context(x, Fundamentals(pd.DataFrame())) for x in (cleaned, missing))
    for day in ("2009-09-30", "2010-03-31"):
        pd.testing.assert_series_equal(ca.adj_months_back(day, 1), cb.adj_months_back(day, 1))
        pd.testing.assert_series_equal(ca.n100_return(day, 6), cb.n100_return(day, 6))
