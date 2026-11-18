"""Fixes K to O: ISIN edge cases, the LSE issuer lists, overseas listings, second lines, list files."""
from __future__ import annotations

import numpy as np
import pandas as pd
import pytest

from conftest import make_market
from research.lse import build_status, norm_name, read_issuer_lists
from research.universe import build_universe, read_symbol_list, to_fmp

# The 12 overseas-incorporated FTSE 350 members that must stay in, and the six
# secondary listings that must stay out (brief, fixes L).
PREMIUM_OVERSEAS = {
    "DCC.L": ("DCC plc", "IRELAND"), "GFTU.L": ("Grafton Group plc", "IRELAND"),
    "GNC.L": ("Greencore Group plc", "IRELAND"), "IAG.L": ("International Consolidated Airlines Group S.A.", "SPAIN"),
    "CCH.L": ("Coca-Cola HBC AG", "SWITZERLAND"), "RHIM.L": ("RHI Magnesita N.V.", "NETHERLANDS"),
    "HSX.L": ("Hiscox Ltd", "BERMUDA"), "LRE.L": ("Lancashire Holdings Limited", "BERMUDA"),
    "PLUS.L": ("Plus500 Ltd", "ISRAEL"), "XPP.L": ("XP Power Limited", "SINGAPORE"),
    "ATYM.L": ("Atalaya Mining Copper S.A.", "CYPRUS"), "AMRQ.L": ("Amaroq Ltd", "CANADA"),
}
SECONDARY = {
    "BHP.L": ("BHP Group Limited", "AUSTRALIA"), "BNC.L": ("Banco Santander S.A.", "SPAIN"),
    "CKI.L": ("CK Infrastructure Holdings Limited", "HONG KONG"), "CAN.L": ("Canal+ SA", "FRANCE"),
    "EGY.L": ("VAALCO Energy Inc", "UNITED STATES"), "DAU.L": ("Dauch Corporation", "UNITED STATES"),
}
HOME = {"BP.L": ("BP P.L.C.", "UNITED KINGDOM"), "INVP.L": ("Investec plc", "UNITED KINGDOM"),
        "SANB.L": ("Santander UK plc", "UNITED KINGDOM"), "JMAT.L": ("Johnson Matthey Plc", "UNITED KINGDOM")}
AIM = {"AMS.L": ("Advanced Medical Solutions Group plc", "UNITED KINGDOM"),
       "GBG.L": ("GB Group plc", "UNITED KINGDOM")}


def _write_book(path, sheets: dict[str, pd.DataFrame], title_rows: int):
    with pd.ExcelWriter(path) as xw:
        for name, df in sheets.items():
            pad = pd.DataFrame([["London Stock Exchange issuer list"]] + [[""]] * (title_rows - 1))
            pad.to_excel(xw, sheet_name=name, header=False, index=False)
            df.to_excel(xw, sheet_name=name, startrow=title_rows, index=False)
        pd.DataFrame([["notes"]]).to_excel(xw, sheet_name="Sheet3", header=False, index=False)   # a stray sheet


def _rows_2016(companies):
    return pd.DataFrame([{"List Date": "2001-01-01", "Company": n, "Group": "", "Sector": "", "Sub Sector": "",
                          "Country of Incorporation": c, "Market": mk, "Mkt Cap £m": cap,
                          "International Main Market": "N", "FCA Listing Category": cat}
                         for n, c, mk, cap, cat in companies])


def _rows_2020(companies):
    # Capitals with a trailing space, no listing category.
    return pd.DataFrame([{"Admission Date": "2001-01-01", "Company Name": n, "ICB Industry": "",
                          "ICB Super-Sector": "", "Country of Incorporation": c, "World Region": "",
                          "Market": mk.upper() + " ", "International Issuer": ii, "Company Market Cap (£m)": cap}
                         for n, c, mk, cap, ii in companies])


@pytest.fixture
def lse_data(tmp_path):
    ref = tmp_path / "reference" / "lse_issuer_lists"
    (ref / "2016").mkdir(parents=True)
    (ref / "2026").mkdir(parents=True)
    big = 5000.0
    y16 = [(n, c, "Main Market", big, "Premium") for n, c in {**PREMIUM_OVERSEAS, **HOME}.values()
           if n not in ("RHI Magnesita N.V.", "Amaroq Ltd")]
    y16 += [(n, c, "Main Market", big, "Standard") for n, c in SECONDARY.values()]
    y16 += [(n, c, "AIM", 300.0, "") for n, c in AIM.values()]
    y16 = [r if r[0] != "Santander UK plc" else (r[0], r[1], r[2], 50.0, r[4]) for r in y16]   # a small pref line
    _write_book(ref / "2016" / "issuer_list_2016.xlsx", {"Jan": _rows_2016(y16), "Feb": _rows_2016(y16)}, 6)
    # 2026: no category column; RHI and Amaroq have no category anywhere, only the issuer flag.
    y26 = [(n, c, "Main Market", big, "N") for n, c in {**PREMIUM_OVERSEAS, **HOME}.values()]
    y26 += [(n, c, "Main Market", big, "Y") for n, c in SECONDARY.values()]
    y26 += [(n, c, "AIM", 300.0, "N") for n, c in AIM.values()]
    y26 = [r if r[0] != "Santander UK plc" else (r[0], r[1], r[2], 50.0, r[4]) for r in y26]
    _write_book(ref / "2026" / "Issuer list archive 2026.xlsx", {"Oct": _rows_2020(y26), "Nov": _rows_2020(y26)}, 7)
    return tmp_path


def _market(start, n=15):
    names = {**PREMIUM_OVERSEAS, **SECONDARY, **HOME, **AIM, "INVR.L": ("Investec plc", "UNITED KINGDOM")}
    prices = {s: [100.0] * n for s in names}
    caps = {s: 5e9 for s in names}
    caps["SANB.L"] = 60e9           # FMP gives Santander UK's line the group's size
    caps["AMS.L"] = caps["GBG.L"] = 3e8
    master = {s: {"name": nm, "overseas_isin": c not in ("UNITED KINGDOM",)} for s, (nm, c) in names.items()}
    return make_market(prices, start=start, caps=caps, master=master)


def test_issuer_lists_read_header_months_and_columns(lse_data):
    df, cols = read_issuer_lists(lse_data / "reference" / "lse_issuer_lists")
    assert set(df["month"].dt.strftime("%Y-%m")) == {"2016-01", "2016-02", "2026-10", "2026-11"}
    assert "category" in cols["2016"] and "category" not in cols["2026"]
    assert set(df["market"]) == {"MAIN MARKET", "AIM"}          # trailing space and case normalised


def test_name_normalising():
    assert norm_name("BP P.L.C.") == "BP"
    assert norm_name("Fuller, Smith & Turner PLC") == "FULLER SMITH AND TURNER"
    assert norm_name("Lancashire Holdings Limited") == "LANCASHIRE"
    assert norm_name(None) == ""


def test_overseas_premium_in_secondary_out_aim_out(lse_data):
    m = _market("2026-11-02")
    months = pd.DatetimeIndex(["2026-11-01"])
    master = m.master.reset_index().rename(columns={"index": "symbol"})
    master.loc[master["symbol"] == "INVR.L", "included"] = False     # master's one-line rule took it out
    m.master.loc["INVR.L", "included"] = False
    elig, rep = build_status(lse_data, master, m.market_cap, months, include_aim=False)
    u = build_universe(m, size=350, large=100, start="2026-11-01", eligible=elig)
    kept = set(u["symbol"])
    assert set(PREMIUM_OVERSEAS) - {"RHIM.L", "AMRQ.L"} <= kept      # category carried from 2016
    assert not (set(SECONDARY) & kept)
    assert not (set(AIM) & kept)
    assert "SANB.L" not in kept and "SANB.L" in rep["lines_with_fmp_cap_over_3x_lse"]
    assert {"BP.L", "INVP.L", "JMAT.L"} <= kept
    # RHI and Amaroq have no category in any list; the issuer flag (N) is not
    # enough on its own, so they need the overrides file, and the report says so.
    assert rep["overseas_rule_counts_latest"]["no category: out"] == 2


def test_overrides_file(lse_data):
    pd.DataFrame({"symbol": ["RHIM", "AMRQ.L"], "decision": ["in", "in"],
                  "reason": ["premium since 2017", "ESCC 2024"]}).to_csv(
        lse_data / "reference" / "listing_overrides.csv", index=False)
    m = _market("2026-11-02")
    master = m.master.reset_index().rename(columns={"index": "symbol"})
    elig, _ = build_status(lse_data, master, m.market_cap, pd.DatetimeIndex(["2026-11-01"]), False)
    u = build_universe(m, size=350, large=100, start="2026-11-01", eligible=elig)
    assert set(PREMIUM_OVERSEAS) <= set(u["symbol"])


def test_before_2016_uses_jan_2016_status(lse_data):
    m = _market("2015-06-01", n=200)
    master = m.master.reset_index().rename(columns={"index": "symbol"})
    months = pd.DatetimeIndex(["2015-06-01", "2016-02-01"])
    elig, rep = build_status(lse_data, master, m.market_cap, months, False)
    assert bool(elig.at[pd.Timestamp("2015-06-01"), "DCC.L"]) is True
    assert bool(elig.at[pd.Timestamp("2015-06-01"), "BHP.L"]) is False
    assert "Jan 2016 status (before the lists start)" in rep["basis_counts"]


def test_master_isin_edge_cases(tmp_path):
    """K: no ISIN, a short one and a lower-case one must not break the master pass."""
    from research.master import refine_master
    n = 80
    dates = pd.bdate_range("2025-01-01", periods=n)
    rng = np.random.default_rng(1)
    syms = ["AAA.L", "BBB.L", "CCC.L", "DDD.L"]
    rows = [(s, d, v, v, 1e5) for s in syms for d, v in zip(dates, 300 * np.cumprod(1 + rng.normal(0, 0.02, n)))]
    pd.DataFrame(rows, columns=["symbol", "date", "close", "adj_close", "volume"]).to_parquet(tmp_path / "prices.parquet")
    pd.DataFrame([(s, d, 1e9) for s in syms for d in dates], columns=["symbol", "date", "market_cap"]) \
        .to_parquet(tmp_path / "market_cap.parquet")
    pd.DataFrame({"symbol": syms, "name": ["Alpha plc", "Beta plc", "Gamma plc", "Delta SA"], "currency": "GBp",
                  "isin": [None, "GB12", "gb00b1234567", "FR0000120271"], "is_aim": None, "is_etf": False,
                  "is_fund": False, "delisted_date": None}).to_parquet(tmp_path / "symbols.parquet")
    m = refine_master(tmp_path).set_index("symbol")
    assert m["included"].all() or m.loc["DDD.L", "exclude_reason"].startswith("overseas")
    assert m.loc["CCC.L", "isin_country"] == "GB"
    assert pd.isna(m.loc["AAA.L", "isin_country"]) and pd.isna(m.loc["BBB.L", "isin_country"])
    assert bool(m.loc["DDD.L", "overseas_isin"]) is True


def test_two_lines_same_company_name(tmp_path):
    """N: INVP.L and INVR.L, both 'Investec plc': one line kept, the more traded."""
    from research.master import refine_master
    n = 80
    dates = pd.bdate_range("2025-01-01", periods=n)
    rng = np.random.default_rng(2)
    rows = []
    for s, vol in (("INVP.L", 1e6), ("INVR.L", 1e4)):
        for d, v in zip(dates, 500 * np.cumprod(1 + rng.normal(0, 0.02, n))):
            rows.append((s, d, v, v, vol))
    pd.DataFrame(rows, columns=["symbol", "date", "close", "adj_close", "volume"]).to_parquet(tmp_path / "prices.parquet")
    pd.DataFrame([(s, d, 5e9) for s in ("INVP.L", "INVR.L") for d in dates],
                 columns=["symbol", "date", "market_cap"]).to_parquet(tmp_path / "market_cap.parquet")
    (tmp_path / "reference" / "lse_issuer_lists").mkdir(parents=True)     # lists present: no ISIN exclusion
    pd.DataFrame({"symbol": ["INVP.L", "INVR.L"], "name": ["Investec plc", "Investec PLC"], "currency": "GBp",
                  "isin": ["GB00B17BBQ50", "ZAE000081949"], "is_aim": None, "is_etf": False, "is_fund": False,
                  "delisted_date": None}).to_parquet(tmp_path / "symbols.parquet")
    m = refine_master(tmp_path).set_index("symbol")
    assert bool(m.loc["INVP.L", "included"]) is True
    assert m.loc["INVR.L", "exclude_reason"] == "second line of the same company (kept INVP.L)"


def test_live_list_reader(tmp_path):
    """O: tab-separated with commas in sector names, is_fund, and BT.A -> BT-A.L."""
    p = tmp_path / "n100.tsv"
    p.write_text("symbol\tis_fund\tsector\nBT.A\tN\tTelecoms, Media\nVOD\tN\tTelecoms\nSMT\tY\tFunds, Trusts\n")
    df = read_symbol_list(p)
    assert list(df["symbol"]) == ["BT-A.L", "VOD.L"]
    c = tmp_path / "n100.csv"
    c.write_text("symbol,is_fund\nBT.A,N\nSMT,Y\n")
    assert list(read_symbol_list(c)["symbol"]) == ["BT-A.L"]
    assert to_fmp("bt.a") == "BT-A.L" and to_fmp("VOD.L") == "VOD.L"
