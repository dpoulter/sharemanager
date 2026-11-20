"""20 Nov 2026: UK companies only, the neighbour-ratio market cap rescale, one-day cap spikes, the ceiling check."""
from __future__ import annotations

import numpy as np
import pandas as pd
import pytest

from research.clean import (CAP_CEILING, cap_ceiling_offenders, clean_market_caps, fix_cap_spikes, fix_cap_steps)

D = pd.bdate_range("2023-01-02", periods=400)


def caps_of(**series) -> pd.DataFrame:
    return pd.concat([pd.DataFrame({"symbol": s, "date": D[:len(v)], "market_cap": v}) for s, v in series.items()])


def wobble(level: float, n: int, seed: int = 0) -> np.ndarray:
    return level * (1 + np.random.default_rng(seed).normal(0, 0.01, n))


def out_of(fixed, sym):
    return fixed[fixed["symbol"] == sym]["market_cap"].to_numpy()


# ------------------------------------------------------------------ rescale

def test_100x_low_segment_flanked_by_normal_values_is_corrected():
    """SENX-like: a small cap at £5m, 100 days of it read as £50k. The old 1m guard refused this."""
    v = np.r_[wobble(5e6, 150), wobble(5e4, 100, 1), wobble(5e6, 150, 2)]
    fixed, done, refused = fix_cap_steps(caps_of(SENX=v))
    out = out_of(fixed, "SENX")
    assert out[150:250] == pytest.approx(v[150:250] * 100)
    assert out[:150].tolist() == v[:150].tolist() and out[250:].tolist() == v[250:].tolist()
    assert list(done["symbol"]) == ["SENX"] and done["rows"].iloc[0] == 100 and refused.empty


def test_100x_low_segment_of_a_larger_company_is_corrected_too():
    """RGO-like, a £45bn company: the old guard refused it because the median was above 1m."""
    v = np.r_[np.full(350, 4.5e10), np.full(30, 4.5e8), np.full(20, 4.5e10)]
    fixed, done, refused = fix_cap_steps(caps_of(RGO=v))
    assert out_of(fixed, "RGO").tolist() == np.full(400, 4.5e10).tolist() and refused.empty


def test_100x_high_segment_flanked_by_normal_values_is_divided():
    v = np.r_[wobble(8e8, 150), wobble(8e10, 60, 1), wobble(8e8, 190, 2)]
    fixed, done, refused = fix_cap_steps(caps_of(HI=v))
    assert out_of(fixed, "HI")[150:210] == pytest.approx(v[150:210] / 100)


def test_a_genuine_small_cap_is_left_alone():
    """Flat, no neighbour 100x away: nothing rescaled, nothing refused (even at a few thousand pounds)."""
    fixed, done, refused = fix_cap_steps(caps_of(TINY=wobble(3e3, 400), BIG=np.linspace(1e9, 3e9, 400)))
    assert done.empty and refused.empty
    assert out_of(fixed, "TINY").tolist() == wobble(3e3, 400).tolist()


def test_a_134x_segment_is_refused_and_the_refusal_carries_both_ratios():
    """WG.L-style: 134 times its neighbours is not a pence/pounds slip, so it stays, and is logged."""
    v = np.r_[np.full(150, 1e9), np.full(90, 1.34e11), np.full(160, 1e9)]
    fixed, done, refused = fix_cap_steps(caps_of(WG=v))
    assert out_of(fixed, "WG").tolist() == v.tolist() and done.empty
    assert len(refused) == 1
    r = refused.iloc[0]
    assert r["ratio_before"] == pytest.approx(134) and r["ratio_after"] == pytest.approx(134)
    assert r["from"] == D[150] and r["to"] == D[239]


def test_a_100x_segment_that_is_not_flat_is_refused():
    seg = np.r_[np.full(20, 1e8), np.full(10, 5e8), np.full(20, 1e8)]    # largest 5 times the median
    v = np.r_[np.full(150, 1e10), seg * 1.0, np.full(150, 1e10)]
    fixed, done, refused = fix_cap_steps(caps_of(BUMPY=v))
    assert out_of(fixed, "BUMPY").tolist() == v.tolist()
    assert len(refused) == 1 and "not flat" in refused.iloc[0]["reason"]


def test_a_segment_at_the_end_needs_20_days_and_never_moves_the_long_history():
    """Five odd days at the end of a long, normal history: refused, and the history is not touched."""
    v = np.r_[np.full(395, 1e9), np.full(5, 1e11)]
    fixed, done, refused = fix_cap_steps(caps_of(TAIL=v))
    assert out_of(fixed, "TAIL").tolist() == v.tolist() and done.empty
    assert len(refused) == 1 and refused.iloc[0]["to"] == D[399]


def test_two_segments_the_shorter_end_moves_to_the_longer():
    """BCG-like: pence read as pounds for the first 120 days. One neighbour, 100x, 120 days: fixed."""
    v = np.r_[np.full(120, 1.5e11), np.full(280, 1.5e9)]
    fixed, done, refused = fix_cap_steps(caps_of(BCG=v))
    assert out_of(fixed, "BCG").tolist() == np.full(400, 1.5e9).tolist() and refused.empty
    v = np.r_[np.full(280, 1.5e9), np.full(120, 1.5e11)]           # and at the other end
    fixed, done, refused = fix_cap_steps(caps_of(BCG=v))
    assert out_of(fixed, "BCG").tolist() == np.full(400, 1.5e9).tolist()


def test_the_normal_ends_of_a_corrected_series_are_not_dragged_down():
    """Normal, 100x low, normal: only the middle moves. The two ends have the middle as their one
    neighbour, which has been corrected, so they are in line with it."""
    v = np.r_[np.full(100, 2e9), np.full(40, 2e7), np.full(100, 2e9)]
    fixed, done, refused = fix_cap_steps(caps_of(MID=v))
    assert out_of(fixed, "MID").tolist() == np.full(240, 2e9).tolist() and refused.empty


def test_a_refused_middle_leaves_the_ends_alone():
    v = np.r_[np.full(100, 2e9), np.full(40, 2e7 * 1.34), np.full(100, 2e9)]    # 74.6x: outside 80 to 125
    fixed, done, refused = fix_cap_steps(caps_of(ODD=v))
    assert out_of(fixed, "ODD").tolist() == v.tolist() and done.empty
    assert len(refused) == 1


def test_a_series_that_flips_between_levels_is_corrected_segment_by_segment():
    """NUM-like: 100x-low stretches of 10 days between normal stretches of 10. A 40-day window that runs
    past the next step takes in the other level, so the neighbour median is in line with neither."""
    v = np.r_[np.full(50, 2e9), np.full(10, 2e7), np.full(10, 2e9), np.full(10, 2e7), np.full(10, 2e9),
              np.full(10, 2e7), np.full(50, 2e9)]
    fixed, done, refused = fix_cap_steps(caps_of(NUM=v))
    assert out_of(fixed, "NUM").tolist() == np.full(150, 2e9).tolist()
    assert refused.empty and done["rows"].iloc[0] == 30


def test_a_series_with_no_step_is_untouched_and_a_short_series_does_not_crash():
    fixed, done, refused = fix_cap_steps(caps_of(A=np.array([1e9]), B=np.array([np.nan, np.nan, 1e9])))
    assert done.empty and refused.empty and list(fixed.columns) == ["symbol", "date", "market_cap"]


# ------------------------------------------------------------------ spikes

def test_one_day_spike_is_replaced_by_the_previous_day():
    """SDRY-like: 1.2bn with one day at 267bn."""
    v = wobble(1.2e9, 400)
    v[200] = 2.67e11
    fixed, spikes = fix_cap_spikes(caps_of(SDRY=v))
    out = out_of(fixed, "SDRY")
    assert out[200] == v[199] and (np.delete(out, 200) == np.delete(v, 200)).all()
    assert len(spikes) == 1
    s = spikes.iloc[0]
    assert s["symbol"] == "SDRY" and s["date"] == D[200] and s["market_cap"] == 2.67e11
    assert s["replaced_with"] == v[199] and s["local_median"] == pytest.approx(1.2e9, rel=0.05)


def test_a_spike_on_the_first_day_takes_the_next_and_a_two_day_run_is_not_a_spike():
    v = wobble(1e9, 100)
    v[0] = 1e11
    v[50:52] = 1e11
    fixed, spikes = fix_cap_spikes(caps_of(X=v))
    out = out_of(fixed, "X")
    assert out[0] == v[1] and out[50] == v[50] and out[51] == v[51]
    assert len(spikes) == 1


def test_a_rise_that_stays_is_not_a_spike_and_a_20x_jump_needs_to_clear_the_median():
    v = np.r_[np.full(100, 1e9), np.full(100, 3e10)]           # 30x, but it stays
    fixed, spikes = fix_cap_spikes(caps_of(UP=v))
    assert spikes.empty and out_of(fixed, "UP").tolist() == v.tolist()
    v = wobble(1e9, 100)
    v[40] = 1.9e10                                              # 19 times: under the line
    assert fix_cap_spikes(caps_of(UNDER=v))[1].empty


def test_a_spike_inside_a_low_segment_no_longer_stops_the_rescale():
    """TIA/LOOK-like: a 100x-low stretch with one wild day inside. Spike out first, then the segment is flat."""
    v = np.r_[wobble(4e9, 150), wobble(4e7, 80, 1), wobble(4e9, 170, 2)]
    v[190] = 4e11
    fixed, done, refused, spikes = clean_market_caps(caps_of(TIA=v))
    out = out_of(fixed, "TIA")
    assert len(spikes) == 1 and refused.empty
    assert out.max() < 5e9 and out[150:230] == pytest.approx(np.r_[v[150:190], v[189], v[191:230]] * 100)
    # Without the spike step the same series is refused as not flat.
    assert len(fix_cap_steps(caps_of(TIA=v))[2]) == 1


# ------------------------------------------------------------------ ceiling

def test_ceiling_lists_offenders_without_dropping_anything():
    caps = pd.DataFrame({"symbol": ["AAA.L", "AAA.L", "AZN.L", "AZN.L", "HSBA.L", "FOO.L", "BP.L"],
                         "date": pd.to_datetime(["2020-01-02", "2020-01-03", "2020-01-02", "2020-01-03",
                                                 "2020-01-02", "2020-01-02", "2020-01-02"]),
                         "market_cap": [4e9, 6.0e11,        # AAA over 500bn once
                                        3.5e11, 2.0e11,     # AZN over 300bn: a giant, allowed
                                        5.1e11,             # HSBA over 500bn: not allowed even for a giant
                                        3.2e11,             # FOO over 300bn, not a giant
                                        1.0e11]})
    off = cap_ceiling_offenders(caps)
    assert list(off["symbol"]) == ["AAA.L", "FOO.L", "HSBA.L"]
    assert CAP_CEILING == 500e9 and len(caps) == 7


def test_the_data_report_fails_the_build_on_an_offender_and_lists_it(tmp_path):
    from research.checks import write_data_report
    from research.clean import CapCeilingError
    import sys
    sys.path.insert(0, str(__import__("pathlib").Path(__file__).parent))
    from conftest import make_market
    n = 30
    prices = {"OK.L": [10.0] * n, "BAD.L": [10.0] * n}
    caps = {"OK.L": 2e9, "BAD.L": [1e9] * 29 + [6e11]}
    m = make_market(prices, caps=caps, master={"OK.L": {"name": "Ok plc"}, "BAD.L": {"name": "Bad plc"}})
    pd.DataFrame({"symbol": "OK.L", "date": pd.bdate_range("2020-01-01", periods=2), "close": 1.0}) \
        .to_parquet(tmp_path / "prices.parquet")
    with pytest.raises(CapCeilingError, match="BAD.L"):
        write_data_report(m, tmp_path)
    text = (tmp_path / "data_report.md").read_text()
    assert "Market cap ceiling" in text and "BAD.L" in text and "Top 20 caps per year" in text
    assert (tmp_path / "cap_ceiling_offenders.csv").exists()
    # With the offender gone the report is written and nothing is raised.
    m.market_cap.loc[:, "BAD.L"] = 1e9
    write_data_report(m, tmp_path)


# ------------------------------------------------------------------ UK companies only

def _symbols_data(tmp_path, rows: list[dict], n: int = 80):
    """rows: symbol, name, isin, country, cap (GBP unless cap_ccy), cap_ccy. Prices in pence, 300p, 1e8 shares."""
    dates = pd.bdate_range("2025-01-01", periods=n)
    syms = [r["symbol"] for r in rows]
    pd.DataFrame([(s, d, 300.0, 300.0, 1e5) for s in syms for d in dates],
                 columns=["symbol", "date", "close", "adj_close", "volume"]).to_parquet(tmp_path / "prices.parquet")
    pd.DataFrame([(r["symbol"], d, r.get("cap", 3e8)) for r in rows for d in dates],
                 columns=["symbol", "date", "market_cap"]).to_parquet(tmp_path / "market_cap.parquet")
    pd.DataFrame([(d, c, g) for d in dates for c, g in (("USD", 0.75), ("EUR", 0.85))],
                 columns=["date", "currency", "gbp_per_unit"]).to_parquet(tmp_path / "fx.parquet")
    pd.DataFrame([(r["symbol"], pd.Timestamp("2024-12-31"), 1e8, r.get("cap_ccy", "GBP")) for r in rows],
                 columns=["symbol", "period_end", "weightedAverageShsOut", "reportedCurrency"]) \
        .assign(freq="annual").to_parquet(tmp_path / "statements.parquet")
    pd.DataFrame({"symbol": syms, "name": [r["name"] for r in rows],
                  "currency": [r.get("profile_ccy", "GBp") for r in rows],
                  "isin": [r.get("isin") for r in rows], "country": [r.get("country") for r in rows],
                  "is_aim": None, "is_etf": False, "is_fund": False, "delisted_date": None}) \
        .to_parquet(tmp_path / "symbols.parquet")


ROWS = [
    {"symbol": "UKCO.L", "name": "Uk Co plc", "isin": "GB00B0000010"},                      # kept, quoted in pence
    {"symbol": "IRCO.L", "name": "Irish Co plc", "isin": "IE00B0000011"},                    # IE is home
    {"symbol": "JERS.L", "name": "Jersey Co plc", "isin": "JE00B0000012"},
    {"symbol": "KONM.L", "name": "Konami Holdings", "isin": "JP3300200007"},                 # overseas ISIN
    {"symbol": "BOE.L", "name": "Boeing Co", "isin": "US0970231058"},
    {"symbol": "MGNT.L", "name": "Magnit PJSC", "isin": None, "country": "RU"},             # no ISIN, not UK
    {"symbol": "NOUK.L", "name": "Mystery Group", "isin": None, "country": None},          # no ISIN, no country
    {"symbol": "NOIS.L", "name": "No Isin Uk plc", "isin": None, "country": "GB"},         # no ISIN, FMP says GB
    {"symbol": "USDC.L", "name": "Dollar Cap plc", "isin": "GB00B0000013", "profile_ccy": "USD",
     "cap": 4e8, "cap_ccy": "USD"},                                                         # UK ISIN, cap in dollars
]


def test_master_keeps_uk_companies_only_and_reports_the_rest(tmp_path):
    import json
    from research.master import refine_master
    _symbols_data(tmp_path, ROWS)
    m = refine_master(tmp_path).set_index("symbol")
    r = m["exclude_reason"]
    assert r[["UKCO.L", "IRCO.L", "JERS.L", "NOIS.L"]].isna().all()
    assert r["KONM.L"] == r["BOE.L"] == "overseas ISIN"
    assert r["MGNT.L"] == "no ISIN, not UK" and r["NOUK.L"] == "no ISIN, not UK"
    assert r["USDC.L"] == "cap not in GBP"
    rep = json.loads((tmp_path / "master_report.json").read_text())
    assert [x["symbol"] for x in rep["kept_without_isin_uk_profile"]] == ["NOIS.L"]
    assert [x["symbol"] for x in rep["cap_not_in_gbp_uk_isin"]] == ["USDC.L"]
    assert rep["by_reason"]["overseas ISIN"] == 2 and rep["by_reason"]["cap not in GBP"] == 1


def test_the_profile_country_comes_from_the_raw_profiles_when_the_master_has_none(tmp_path):
    from research.master import refine_master
    _symbols_data(tmp_path, ROWS)
    s = pd.read_parquet(tmp_path / "symbols.parquet").drop(columns="country")
    s.to_parquet(tmp_path / "symbols.parquet")
    (tmp_path / "raw").mkdir()
    pd.DataFrame({"symbol": ["NOIS.L", "MGNT.L"], "country": ["GB", "RU"]}).to_parquet(tmp_path / "raw" / "profiles.parquet")
    m = refine_master(tmp_path).set_index("symbol")
    assert pd.isna(m.loc["NOIS.L", "exclude_reason"]) and m.loc["MGNT.L", "exclude_reason"] == "no ISIN, not UK"


def test_the_universe_has_no_overseas_no_isin_foreign_or_non_gbp_cap_line(tmp_path):
    """Konami and Boeing would carry caps of 3e8 'pounds' as readily as anyone: they must not be ranked."""
    from research.master import refine_master
    from research.store import load_market
    from research.universe import build_universe
    rows = [dict(r, cap=r.get("cap", 3e8) * (1000 if r["symbol"] in ("KONM.L", "BOE.L", "MGNT.L") else 1)) for r in ROWS]
    _symbols_data(tmp_path, rows)
    refine_master(tmp_path)
    m = load_market(tmp_path)
    u = build_universe(m, size=10, large=3, start="2025-01-01")
    held = set(u["symbol"])
    assert held == {"UKCO.L", "IRCO.L", "JERS.L", "NOIS.L"}
    assert u["market_cap"].max() <= 3e8 * 1.0001
    assert (u[u["symbol"] == "UKCO.L"]["market_cap"] == 3e8).all()           # a pence line, cap in pounds


def test_cap_far_from_price_times_shares_is_listed_not_changed():
    """SDRY-like: a cap 3,000 times price x shares; a normal line and a 2x-off line are not listed."""
    import sys
    sys.path.insert(0, str(__import__("pathlib").Path(__file__).parent))
    from conftest import make_market
    from research.checks import cap_vs_price_shares
    n = 60
    dates = pd.bdate_range("2020-01-01", periods=n)
    st = pd.DataFrame({"symbol": ["OK.L", "SDRY.L", "TWO.L"], "period_end": pd.Timestamp("2019-12-31"),
                       "weightedAverageShsOut": 1e8})
    prices = {s: [10.0] * n for s in ("OK.L", "SDRY.L", "TWO.L")}        # £10 x 1e8 = £1bn
    caps = {"OK.L": 1.1e9, "SDRY.L": 3e12, "TWO.L": 2e9}
    m = make_market(prices, caps=caps, statements=st)
    far = cap_vs_price_shares(m)
    assert list(far["symbol"]) == ["SDRY.L"] and far["median_ratio"].iloc[0] == pytest.approx(3000)
    assert (m.market_cap["SDRY.L"] == 3e12).all()


# ------------------------------------------------------------------ the price x shares referee and overrides

def _referee_data(tmp_path, caps: dict, refused: list[tuple], quote="GBp", shares=1e8, price=300.0):
    """Prices in pence, so price x shares is 3e8 pounds at 300p and 1e8 shares."""
    dates = pd.bdate_range("2024-01-01", periods=200)
    pd.DataFrame([(s, d, price) for s in caps for d in dates], columns=["symbol", "date", "close"]) \
        .to_parquet(tmp_path / "prices.parquet")
    pd.DataFrame([(s, d, v) for s, vals in caps.items() for d, v in zip(dates, vals)],
                 columns=["symbol", "date", "market_cap"]).to_parquet(tmp_path / "market_cap.parquet")
    pd.DataFrame({"symbol": list(caps), "period_end": pd.Timestamp("2023-12-31"),
                  "weightedAverageShsOut": shares}).to_parquet(tmp_path / "statements.parquet")
    pd.DataFrame({"symbol": list(caps), "quote_currency": quote}).to_parquet(tmp_path / "symbols.parquet")
    pd.DataFrame([{"symbol": s, "from": dates[a], "to": dates[b], "factor": np.nan, "segment_median": 1.0,
                   "segment_max": 1.0, "ratio_before": 64.0, "ratio_after": 139.0, "reason": "outside the band"}
                  for s, a, b in refused]).to_parquet(tmp_path / "cap_rescale_refused.parquet")
    return dates


def test_a_refused_segment_is_rescaled_when_price_times_shares_agrees(tmp_path):
    """NUM-like: neighbour ratios of 64 and 139 are refused, but cap / (price x shares) is 100: divide."""
    from research.clean import rescue_refused_caps
    cap = np.full(200, 3e8)
    cap[50:90] = 3e10                                           # 100x price x shares
    dates = _referee_data(tmp_path, {"NUM.L": cap.copy()}, [("NUM.L", 50, 89)])
    got = rescue_refused_caps(tmp_path)
    after = pd.read_parquet(tmp_path / "market_cap.parquet").sort_values("date")["market_cap"].to_numpy()
    assert after.tolist() == np.full(200, 3e8).tolist()
    assert len(got) == 1 and got["factor"].iloc[0] == 0.01 and got["price_shares_ratio"].iloc[0] == pytest.approx(100)
    assert pd.read_parquet(tmp_path / "cap_rescale_refused.parquet").empty


def test_a_refused_segment_stays_when_price_times_shares_does_not_agree(tmp_path):
    """WG-like 134x, and a segment 3,000x out (SDRY): left as it was, and the ratio recorded for the report."""
    from research.clean import rescue_refused_caps
    cap = np.full(200, 3e8)
    cap[50:90] = 3e8 * 134
    wg = cap.copy()
    sd = np.full(200, 3e8)
    sd[20:80] = 3e8 * 3000
    _referee_data(tmp_path, {"WG.L": wg, "SDRY.L": sd}, [("WG.L", 50, 89), ("SDRY.L", 20, 79)])
    assert rescue_refused_caps(tmp_path).empty
    left = pd.read_parquet(tmp_path / "cap_rescale_refused.parquet").set_index("symbol")
    assert left["price_shares_ratio"]["WG.L"] == pytest.approx(134) and left["price_shares_ratio"]["SDRY.L"] == pytest.approx(3000)
    assert pd.read_parquet(tmp_path / "market_cap.parquet")["market_cap"].max() == 3e8 * 3000


def test_the_referee_leaves_a_segment_alone_that_is_not_flat_or_has_no_shares(tmp_path):
    from research.clean import rescue_refused_caps
    cap = np.full(200, 3e8)
    cap[50:90] = np.r_[np.full(20, 3e10), np.full(20, 3e11)]      # half of it at 100x, half at 1,000x
    _referee_data(tmp_path, {"BUMP.L": cap}, [("BUMP.L", 50, 89)])
    assert rescue_refused_caps(tmp_path).empty
    (tmp_path / "statements.parquet").unlink()
    assert rescue_refused_caps(tmp_path).empty                    # no statements: nothing to judge by, no crash


def test_overrides_drop_multiply_and_price_times_shares(tmp_path):
    from research.clean import apply_cap_overrides
    cap = np.full(200, 3e8)
    caps = {"SDRY.L": cap * np.r_[np.ones(20), np.full(60, 3000.0), np.ones(120)], "A.L": cap.copy(), "B.L": cap.copy()}
    dates = _referee_data(tmp_path, caps, [])
    (tmp_path / "reference").mkdir()
    rows = [("SDRY.L", dates[20], dates[79], "price_x_shares", "", "1000x series error"),
            ("A.L", dates[0], dates[9], "drop", "", "no good"), ("B.L", dates[100], dates[109], "multiply", "10", "x10")]
    pd.DataFrame(rows, columns=["symbol", "from", "to", "action", "value", "reason"]) \
        .to_csv(tmp_path / "reference" / "cap_overrides.csv", index=False)
    log = apply_cap_overrides(tmp_path)
    out = pd.read_parquet(tmp_path / "market_cap.parquet")
    by = {s: g.sort_values("date")["market_cap"].to_numpy() for s, g in out.groupby("symbol")}
    assert by["SDRY.L"].tolist() == np.full(200, 3e8).tolist()       # price x shares: 300p x 1e8
    assert len(by["A.L"]) == 190 and by["B.L"][100:110].tolist() == np.full(10, 3e9).tolist()
    assert list(log["action"]) == ["price_x_shares", "drop", "multiply"] and list(log["rows"]) == [60, 10, 10]
    pd.DataFrame([("X.L", dates[0], dates[1], "delete", "", "")], columns=["symbol", "from", "to", "action", "value", "reason"]) \
        .to_csv(tmp_path / "reference" / "cap_overrides.csv", index=False)
    with pytest.raises(ValueError, match="action"):
        apply_cap_overrides(tmp_path)


def test_a_london_line_with_a_uk_profile_country_but_roubles_is_out(tmp_path):
    """Lukoil: no ISIN, FMP profile country GB, profile currency RUB."""
    from research.master import refine_master
    rows = [{"symbol": "UKCO.L", "name": "Uk Co plc", "isin": "GB00B0000010"},
            {"symbol": "LKOD.L", "name": "PJSC Lukoil", "isin": None, "country": "GB", "profile_ccy": "RUB"},
            {"symbol": "NOIS.L", "name": "No Isin Uk plc", "isin": None, "country": "GB"}]
    _symbols_data(tmp_path, rows)
    m = refine_master(tmp_path).set_index("symbol")
    assert m.loc["LKOD.L", "exclude_reason"] == "no ISIN, not sterling"
    assert pd.isna(m.loc["NOIS.L", "exclude_reason"]) and pd.isna(m.loc["UKCO.L", "exclude_reason"])


def test_the_referee_accepts_a_segment_with_a_few_wild_days(tmp_path):
    """DCI-like: median 100x, but a few days out of range: still rescaled (80% of days inside 50 to 200)."""
    from research.clean import rescue_refused_caps
    cap = np.full(200, 3e8)
    cap[50:100] = 3e10
    cap[60], cap[61], cap[70] = 3e11, 1.0e11, 9e10                 # three wild days in fifty
    _referee_data(tmp_path, {"DCI.L": cap.copy()}, [("DCI.L", 50, 99)])
    assert len(rescue_refused_caps(tmp_path)) == 1


# ------------------------------------------------------------------ the N100's rebalance as-of dates

def test_rebalance_on_the_last_trading_day_on_or_before_the_n100_as_of():
    from research.engine import rebalance_flags
    d = pd.bdate_range("2022-12-28", "2023-02-03").drop(pd.to_datetime(["2023-01-02"]))     # 2 Jan: bank holiday
    asof = ["2022-12-01", "2023-01-02", "2023-02-01"]
    f = rebalance_flags(d, "monthly", asof)
    assert list(d[f]) == [pd.Timestamp("2022-12-30"), pd.Timestamp("2023-02-01")]      # 2 Jan: decide on 30 Dec, fill 3 Jan
    # No as_of dates: the first trading day of each month, as before.
    assert list(d[rebalance_flags(d, "monthly")]) == [pd.Timestamp("2022-12-28"), pd.Timestamp("2023-01-03"),
                                                      pd.Timestamp("2023-02-01")]


def test_n100_asof_dates_come_from_its_statistics_calendar(tmp_path):
    from research.validate import n100_asof_dates
    pd.DataFrame({"date": ["2022-11-01", "2022-12-01", "2023-01-02", "2023-01-03", "2023-02-01", "2023-02-02"]}) \
        .to_csv(tmp_path / "d.csv", index=False)
    assert n100_asof_dates(tmp_path / "d.csv", "2022-11-01", "2023-02-28") == \
        ["2022-11-01", "2022-12-01", "2023-01-02", "2023-02-01"]


def test_the_six_month_base_counts_back_from_the_n100_as_of_not_the_decision_day():
    """As-of Mon 2 Jan 2023 (a holiday), decided on Fri 30 Dec: the N100's base is the first close on or
    after 2 Jul, which is Mon 4 Jul, not 30 Jun."""
    import sys
    sys.path.insert(0, str(__import__("pathlib").Path(__file__).parent))
    from conftest import make_market
    from research.pit import Fundamentals
    from research.signals import Context
    dates = pd.bdate_range("2022-06-01", "2023-01-31").drop(pd.to_datetime(["2023-01-02"]))
    px = list(100.0 + np.arange(len(dates), dtype=float))
    m = make_market({"A.L": px}, start="2022-06-01")
    m.close.index = m.adj.index = m.market_cap.index = dates          # the bank holiday is not a trading day
    ctx = Context(m, Fundamentals(pd.DataFrame()))
    d = pd.Timestamp("2022-12-30")
    def base_of(c):
        return c.n100_return_detail(d, 6).loc["A.L", "start"]
    assert base_of(ctx) == "2022-06-30"                                # from the decision day: the old answer
    ctx.asof[d] = pd.Timestamp("2023-01-02")
    assert base_of(ctx) == "2022-07-04"                                # from the as-of date
    assert ctx.n100_return_detail(d, 6).loc["A.L", "end"] == "2022-12-30"
