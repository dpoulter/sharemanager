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
    assert "SANB.L" in rep["lines_with_fmp_cap_over_3x_lse (reported only)"]      # flagged, not removed
    assert {"BP.L", "INVP.L", "JMAT.L"} <= kept
    # RHI and Amaroq have no category in any list; the issuer flag (N) is not
    # enough on its own, so they need the overrides file, and the report says so.
    assert rep["overseas_rule_counts_latest"]["no category: out"] == 2


def test_overrides_file(lse_data):
    pd.DataFrame({"symbol": ["RHIM", "AMRQ.L", "SANB"], "decision": ["in", "in", "out"],
                  "reason": ["premium since 2017", "ESCC 2024", "preference share line"]}).to_csv(
        lse_data / "reference" / "listing_overrides.csv", index=False)
    m = _market("2026-11-02")
    master = m.master.reset_index().rename(columns={"index": "symbol"})
    elig, _ = build_status(lse_data, master, m.market_cap, pd.DatetimeIndex(["2026-11-01"]), False)
    u = build_universe(m, size=350, large=100, start="2026-11-01", eligible=elig)
    assert set(PREMIUM_OVERSEAS) <= set(u["symbol"])
    assert "SANB.L" not in set(u["symbol"])


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


def test_two_lines_same_company_month_by_month():
    """S: BDEV (old code) stops trading, BTRW starts; INVP and INVR trade together."""
    n = 120
    prices = {"BDEV.L": [500.0] * 60 + [np.nan] * 60, "BTRW.L": [np.nan] * 55 + [400.0] * 65,
              "INVP.L": [500.0] * n, "INVR.L": [500.0] * n, "OTHER.L": [100.0] * n}
    m = make_market(prices, start="2025-01-01",
                    master={"BDEV.L": {"norm": "BARRATT REDROW"}, "BTRW.L": {"norm": "BARRATT REDROW"},
                            "INVP.L": {"norm": "INVESTEC"}, "INVR.L": {"norm": "INVESTEC"}, "OTHER.L": {"norm": "OTHER"}})
    m.volume = pd.DataFrame({"BDEV.L": 1e6, "BTRW.L": 1e6, "INVP.L": 1e6, "INVR.L": 1e3, "OTHER.L": 1e5},
                            index=m.dates)
    u = build_universe(m, size=10, large=10, start="2025-01-01")
    first, last = u[u["date"] == u["date"].min()], u[u["date"] == u["date"].max()]
    assert "BDEV.L" in set(first["symbol"]) and "BTRW.L" not in set(first["symbol"])
    assert "BTRW.L" in set(last["symbol"]) and "BDEV.L" not in set(last["symbol"])
    assert "INVP.L" in set(last["symbol"]) and "INVR.L" not in set(last["symbol"])


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


def test_rank_with_trusts_then_drop_them():
    """V: the top 10 by cap with trusts counted, then the trusts left out."""
    prices = {f"C{i}.L": [100.0] * 20 for i in range(10)}
    caps = {f"C{i}.L": 1e9 * (10 - i) for i in range(10)}            # £10bn down to £1bn
    m = make_market(prices, start="2026-01-01", caps=caps)
    trusts = pd.DataFrame({"T1.L": 9.5e9, "T2.L": 5.5e9, "T3.L": 1.5e9}, index=m.dates)
    u = build_universe(m, size=10, large=5, start="2026-01-01", trust_caps=trusts)
    first = set(u[u["date"] == u["date"].min()]["symbol"])
    assert first == {f"C{i}.L" for i in range(8)}                    # T1 and T2 took 2 of the 10 places
    assert not {"T1.L", "T2.L", "T3.L"} & first


def test_two_files_whose_names_say_the_same_year(tmp_path):
    """Q: the 2025 archive is a file named ..._2026-01-05... in a 2025 folder."""
    rows = [("DCC plc", "IRELAND", "Main Market", 5000.0, "N")]
    a = tmp_path / "Issuer_list_archive_2025_11"
    b = tmp_path / "Issuer_list_archive_2026_10"
    a.mkdir(); b.mkdir()
    _write_book(a / "Issuer list archive_2026-01-05-09-44-33.xlsx", {"Jan": _rows_2020(rows), "Dec": _rows_2020(rows)}, 6)
    _write_book(b / "Issuer list archive_2026-10-01-07-55-43.xlsx", {"Jan": _rows_2020(rows), "Sep": _rows_2020(rows)}, 6)
    # A loose file with no year in its folder: the admission dates inside decide (latest Nov 2023 -> Dec sheet is 2023).
    loose = pd.DataFrame([{"Admission Date": "2023-11-20", "Company Name": "DCC plc", "Country of Incorporation": "IRELAND",
                           "Market": "MAIN MARKET "}])
    _write_book(tmp_path / "Issuer list archive_2099.xlsx", {"Dec": loose}, 6)
    df, info = read_issuer_lists(tmp_path)
    got = set(df["month"].dt.strftime("%Y-%m"))
    assert {"2025-01", "2025-12", "2026-01", "2026-09", "2023-12"} <= got
    assert "2099-12" not in got
    assert not info["_months_from_two_files"]


def test_unreadable_workbook_stops_the_run(tmp_path):
    """P: a workbook that cannot be read is an error, not a skipped year."""
    from research.lse import UnreadableWorkbook
    (tmp_path / "LISTDATE HISTORIC 2016.xls").write_bytes(b"not a workbook")
    with pytest.raises(UnreadableWorkbook):
        read_issuer_lists(tmp_path)


def test_xls_2016_categories_reach_today(tmp_path):
    """P and R: the 2016 premium category, read from the old file, carries to 2026 by
    matched symbol, though the company's name has changed (DCC -> DCC ENERGY)."""
    ref = tmp_path / "reference" / "lse_issuer_lists"
    (ref / "2016").mkdir(parents=True)
    (ref / "2026").mkdir(parents=True)
    _write_book(ref / "2016" / "LISTDATE HISTORIC 2016.xlsx",
                {"Jan": _rows_2016([("DCC", "IRELAND", "Main Market", 4000.0, "Premium"),
                                    ("BHP Billiton plc", "AUSTRALIA", "Main Market", 9e4, "Standard")])}, 6)
    _write_book(ref / "2026" / "Issuer list archive 2026.xlsx",
                {"Nov": _rows_2020([("DCC ENERGY PLC", "IRELAND", "Main Market", 5000.0, "Y"),
                                    ("BHP Billiton plc", "AUSTRALIA", "Main Market", 9e4, "Y")])}, 7)
    m = make_market({"DCC.L": [100.0] * 5, "BHP.L": [100.0] * 5}, start="2026-11-02",
                    master={"DCC.L": {"name": "DCC plc"}, "BHP.L": {"name": "BHP Billiton plc"}})
    master = m.master.reset_index().rename(columns={"index": "symbol"})
    elig, rep = build_status(tmp_path, master, m.market_cap, pd.DatetimeIndex(["2016-01-01", "2026-11-01"]), False)
    assert "2016" in rep["listing_category_years"]
    assert bool(elig.at[pd.Timestamp("2026-11-01"), "DCC.L"]) is True       # Y flag does not exclude
    assert bool(elig.at[pd.Timestamp("2026-11-01"), "BHP.L"]) is False


def test_blank_market_cell_does_not_crash(tmp_path):
    """Y: a row with a blank Market reads as None, not pd.NA."""
    rows = pd.DataFrame([{"Admission Date": "2001-01-01", "Company Name": "Alpha plc", "Country of Incorporation": "UNITED KINGDOM",
                          "Market": None}, {"Admission Date": "2001-01-01", "Company Name": "Beta plc",
                                            "Country of Incorporation": None, "Market": "MAIN MARKET "}])
    (tmp_path / "2026").mkdir()
    _write_book(tmp_path / "2026" / "list.xlsx", {"Nov": rows}, 6)
    df, _ = read_issuer_lists(tmp_path)
    assert pd.isna(df.loc[df["company"] == "Alpha plc", "market"].iloc[0])
    assert df.loc[df["company"] == "Beta plc", "market"].iloc[0] == "MAIN MARKET"


def test_investec_plc_and_ltd_kept_apart(tmp_path):
    """Investec plc (Main Market) and Investec Ltd (admission to trading only) must not merge."""
    rows = [("Investec Limited", "SOUTH AFRICA", "MAIN MARKET / ADMISSION TO TRADING ONLY", 3000.0, "N"),
            ("Investec plc", "UNITED KINGDOM", "Main Market", 3000.0, "N")]
    (tmp_path / "2026").mkdir()
    _write_book(tmp_path / "2026" / "list.xlsx", {"Nov": _rows_2020(rows)}, 6)
    df, _ = read_issuer_lists(tmp_path)
    plc = df[df["company"] == "Investec plc"].iloc[0]
    assert plc["market"] == "MAIN MARKET" and plc["norm"] == "INVESTEC PLC"
    assert len(df) == 2


def test_lse_names_in_its_own_style():
    assert norm_name("SAINSBURY (J) PLC") == norm_name("J Sainsbury plc")
    assert norm_name("WETHERSPOON (J.D.) PLC") == norm_name("J D Wetherspoon plc")
    assert norm_name("MORGAN ADVANCED MATERIALS PLC ORD 25P") == norm_name("Morgan Advanced Materials plc")
    for a, b in [("SHELL PLC", "Shell plc"), ("TATE & LYLE PLC", "Tate & Lyle plc"),
                 ("CHEMRING GROUP PLC", "Chemring Group PLC"), ("AUTO TRADER GROUP PLC", "Auto Trader Group plc"),
                 ("BARRATT REDROW PLC", "Barratt Redrow plc")]:
        assert norm_name(a) == norm_name(b)


def test_cap_steps_of_100x_rescaled_bcg_case():
    """Z: BCG.L at £156.9bn on 20 Nov 2024 and ~£1bn in 2026: pence read as pounds."""
    from research.clean import fix_cap_steps
    d = pd.bdate_range("2024-06-03", "2026-09-30")
    cap = np.where(d < pd.Timestamp("2025-03-03"), 1.5e11, 1.5e9) * np.linspace(1.04, 0.7, len(d))
    caps = pd.DataFrame({"symbol": "BCG.L", "date": d, "market_cap": cap})
    other = pd.DataFrame({"symbol": "OK.L", "date": d, "market_cap": np.linspace(1e9, 3e9, len(d))})
    fixed, log = fix_cap_steps(pd.concat([caps, other]))
    b = fixed[fixed["symbol"] == "BCG.L"].set_index("date")["market_cap"]
    assert 1.2e9 < b.loc["2024-12-02"] < 1.8e9
    assert b.loc["2026-09-30"] == pytest.approx(cap[-1])
    assert list(log["symbol"]) == ["BCG.L"]
    assert (fixed[fixed["symbol"] == "OK.L"]["market_cap"].to_numpy() == other["market_cap"].to_numpy()).all()


def test_trust_candidates_closed_ended_only_one_line_per_issuer():
    """AA: no 0P codes, ETFs or open-ended classes; one line per issuer, the largest; Main Market only."""
    from research.universe import trust_candidates
    prices = {f"C{i}.L": [100.0] * 15 for i in range(10)}
    caps = {f"C{i}.L": 1e9 * (10 - i) for i in range(10)}
    names = {"SMT.L": "Scottish Mortgage Investment Trust PLC", "0P0000ABC.L": "Some Fund Acc",
             "ISF.L": "iShares Core FTSE 100 UCITS ETF", "FCIT.L": "F&C Investment Trust PLC",
             "FCIT-B.L": "F&C Investment Trust PLC", "AIMT.L": "Small AIM Trust plc"}
    m = make_market(prices, start="2026-11-02", caps=caps)
    for s, nm in names.items():
        m.master.loc[s, ["name", "exclude_reason", "included", "is_etf", "overseas_isin"]] = \
            [nm, "FMP isFund", False, s == "ISF.L", False]
    cands = set(trust_candidates(m.master))
    assert cands == {"SMT.L", "FCIT.L", "FCIT-B.L", "AIMT.L"}
    trusts = pd.DataFrame({"SMT.L": 9.5e9, "FCIT.L": 5.5e9, "FCIT-B.L": 5.4e9, "AIMT.L": 8.5e9}, index=m.dates)
    month = pd.Timestamp("2026-11-01")
    elig = pd.DataFrame({s: [True] for s in list(prices) + ["SMT.L", "FCIT.L", "FCIT-B.L"]}, index=[month])
    elig["AIMT.L"] = False                                              # on AIM per the LSE list
    u = build_universe(m, size=10, large=5, start="2026-11-01", eligible=elig, trust_caps=trusts)
    first = set(u["symbol"])
    assert first == {f"C{i}.L" for i in range(8)}                      # SMT and one F&C line counted
    assert u.attrs["trusts_counted"][u["date"].min()] == 2
