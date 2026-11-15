#!/usr/bin/env python3
"""Offline tests for python/fmp.py.

The fixtures below are real VOD.L responses from the stable API, captured by
python/fmp_probe.py and trimmed. No key and no network are needed, and because
the data is real rather than invented, a field name that does not exist cannot
pass.
"""

import sys
import os

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "python"))
import fmp                                                   # noqa: E402

passed = failed = 0


def check(name, ok, detail=""):
    global passed, failed
    if ok:
        passed += 1
        print("  \033[32mPASS\033[0m  {}".format(name))
    else:
        failed += 1
        print("  \033[31mFAIL\033[0m  {}{}".format(name, "\n          " + detail if detail else ""))


# --- real VOD.L responses, trimmed ------------------------------------------
QUOTE = {
    "symbol": "VOD.L", "name": "Vodafone Group Plc", "price": 116.95,
    "changePercentage": -0.93181, "change": -1.1, "volume": 28338030,
    "dayLow": 115.3, "dayHigh": 117.15, "yearHigh": 132.072, "yearLow": 88.398,
    "marketCap": 27095677700, "priceAvg50": 122.41, "priceAvg200": 115.6521,
    "exchange": "LSE", "open": 116.5, "previousClose": 118.05,
    "timestamp": 1794588534,
}
PROFILE = {
    "symbol": "VOD.L", "price": 116.95, "marketCap": 27095677700, "beta": 0.321,
    "currency": "GBp", "cik": None, "isin": "GB00BH4HKS39", "cusip": None,
    "companyName": "Vodafone Group Plc", "exchange": "LSE",
    "exchangeFullName": "London Stock Exchange",
    "industry": "Telecommunications Services", "website": "https://www.vodafone.com",
    "description": "Vodafone Group Public Limited Company provides telecommunications services " * 6,
    "ceo": "Margherita Della Valle", "sector": "Communication Services",
    "country": "GB", "fullTimeEmployees": 91128, "city": "Newbury",
    "image": "https://images.financialmodelingprep.com/symbol/VOD.L.png",
    "isEtf": False, "isActivelyTrading": True,
}
KEY_METRICS = {
    "symbol": "VOD.L", "marketCap": 32475795464.112003,
    "evToEBITDATTM": 5.1401508938940905,
    "returnOnEquityTTM": -0.1286567186887078,
    "returnOnAssetsTTM": -0.04705548708442561,
    "grahamNumberTTM": None,
    "salesGeneralAndAdministrativeToRevenueTTM": 0.23768199274994664,
    "researchAndDevelopementToRevenueTTM": 0,
}
RATIOS = {
    "symbol": "VOD.L", "priceToEarningsRatioTTM": -5.329718326996197,
    "priceToBookRatioTTM": 0.7491988312842853,
    "priceToSalesRatioTTM": 0.7589619576712687,
    "netProfitMarginTTM": -0.14110347537870865,
    "operatingProfitMarginTTM": 0.07976750695972686,
    "dividendYieldTTM": 0.03389654,
}
SECTIONS = {"Quote": QUOTE, "Profile": PROFILE,
            "KeyMetrics": KEY_METRICS, "Ratios": RATIOS}

print("\n--- ticker translation ---")
check("a bare code gets the London suffix FMP uses", fmp.fmp_symbol("VOD") == "VOD.L")
check("an already-suffixed code is left alone", fmp.fmp_symbol("VOD.L") == "VOD.L")
check("the EODHD spelling is not produced", fmp.fmp_symbol("VOD") != "VOD.LSE")

print("\n--- units ---")
# FMP quotes LSE in pence but reports market cap in pounds. Mixing them is a
# hundredfold error on the quote page's Mkt cap.
check("a pence-quoted market cap is converted to pence",
      fmp.normalise_market_cap(27095677700, "GBp") == 2709567770000)
check("a pound-quoted market cap is left alone",
      fmp.normalise_market_cap(27095677700, "USD") == 27095677700)
check("a missing market cap stays missing",
      fmp.normalise_market_cap(None, "GBp") is None)

shares = fmp.shares_outstanding(PROFILE, QUOTE)
# 27.1bn pounds / 1.1695 pounds per share is about 23.2bn shares. Vodafone has
# roughly 25bn in issue, so the derivation should land in that region.
check("shares outstanding derives to the right order of magnitude",
      shares is not None and 2.0e10 < shares < 3.0e10, "got {}".format(shares))
check("shares outstanding is absent when the price is",
      fmp.shares_outstanding({"marketCap": 1}, {}) is None)

print("\n--- flattening ---")
flat = fmp.flatten(SECTIONS)
check("keys are Section.field", "Ratios.priceToEarningsRatioTTM" in flat)
check("every section contributes",
      all(any(k.startswith(s + ".") for k in flat)
          for s in ("Quote", "Profile", "KeyMetrics", "Ratios")))
check("the ratios the screen needs survive",
      flat.get("Ratios.priceToEarningsRatioTTM") == -5.329718326996197
      and flat.get("Ratios.priceToBookRatioTTM") == 0.7491988312842853
      and flat.get("KeyMetrics.returnOnEquityTTM") == -0.1286567186887078)
check("nulls are dropped rather than stored as None",
      "KeyMetrics.grahamNumberTTM" not in flat and "Profile.cik" not in flat)
check("a real zero is kept, since it is a value",
      flat.get("KeyMetrics.researchAndDevelopementToRevenueTTM") == 0)
check("numbers keep their type rather than all becoming strings",
      isinstance(flat["Quote.price"], float) and isinstance(flat["Quote.volume"], int))
check("an over-long description is truncated to fit the column",
      len(flat["Profile.description"]) <= fmp.MAX_VALUE)

# The 52 character name is why the column is widened to varchar(100); under the
# old varchar(50) this field was silently dropped.
check("the longest field FMP returns is kept, not skipped",
      "KeyMetrics.salesGeneralAndAdministrativeToRevenueTTM" in flat)
check("no attribute exceeds the column width",
      all(len(k) <= fmp.MAX_ATTRIBUTE for k in flat),
      max(flat, key=len))

check("the derived figures are written under their own section",
      flat.get("Derived.sharesOutstanding") == shares
      and flat.get("Derived.marketCapInQuoteCurrency") == 2709567770000)
check("derived market cap is not confused with the provider's own",
      flat["Profile.marketCap"] != flat["Derived.marketCapInQuoteCurrency"])

print("\n--- buyback yield ---")

# FMP reports the dividend yield but not what a company handed back by buying
# its own shares, and the two together are the shareholder yield the value
# score ranks on. commonStockRepurchased is a cash outflow, so it arrives
# negative.
def period(date, spent=None):
    row = {"date": date}
    if spent is not None:
        row["commonStockRepurchased"] = spent
    return row


# A quarterly reporter: four periods are a year.
quarters = [period("2026-09-30", -1000.0), period("2026-06-30", -2000.0),
            period("2026-03-31", -3000.0), period("2025-12-31", -4000.0)]
check("four quarters are a year, and the sign is dropped",
      len(fmp.trailing_twelve_months(quarters)) == 4
      and fmp.buyback_yield(quarters, 100000.0) == 0.1,
      str(fmp.buyback_yield(quarters, 100000.0)))

# A half-yearly reporter: FMP labels the two periods Q2 and Q4, so four of
# them are two years. TRN's four summed 304m against a true trailing twelve
# months of 172m, and the yields that came out of that reached 65%.
half_yearly = [period("2026-08-31", -172000.0), period("2026-02-28", 0.0),
               period("2025-08-31", -132000.0), period("2025-02-28", 0.0)]
check("a half-yearly reporter's year is two periods, not four",
      len(fmp.trailing_twelve_months(half_yearly)) == 2,
      str(len(fmp.trailing_twelve_months(half_yearly))))
check("and the sum is the trailing twelve months, not two years",
      fmp.buyback_yield(half_yearly, 1000000.0) == 0.172,
      str(fmp.buyback_yield(half_yearly, 1000000.0)))
# Summing all four would be 0.304 - which is also over the sanity cap, so
# the old code would hand back nothing here rather than a wrong number. The
# comparison is written to survive either answer so that a regression reports
# as a failure rather than a TypeError three lines later.
all_four = fmp.buyback_yield(half_yearly, 1000000.0)
check("summing all four would have given almost twice that, or nothing at all",
      all_four is not None and all_four < 0.304 * 0.6,
      "summing all four is {}".format(304000.0 / 1000000.0))

# An annual reporter: one period is the year.
annual = [period("2026-03-31", -5000.0), period("2025-03-31", -6000.0)]
check("an annual reporter's year is one period",
      len(fmp.trailing_twelve_months(annual)) == 1
      and fmp.buyback_yield(annual, 100000.0) == 0.05)

# The boundary. A period ending exactly a year before the latest belongs to
# the year before it.
check("a period ending exactly a year earlier is left out",
      len(fmp.trailing_twelve_months([period("2026-08-31"), period("2025-08-31")])) == 1)
check("one ending a day later is in",
      len(fmp.trailing_twelve_months([period("2026-08-31"), period("2025-09-01")])) == 2)
check("29 February does not break the year before it",
      len(fmp.trailing_twelve_months([period("2028-02-29"), period("2027-02-28")])) == 1)

check("periods arrive in any order and the latest still anchors the year",
      len(fmp.trailing_twelve_months(list(reversed(quarters)))) == 4)
check("a period with no readable date cannot be placed, so it is not counted",
      fmp.trailing_twelve_months([{"commonStockRepurchased": -1.0}]) == []
      and fmp.buyback_yield([{"commonStockRepurchased": -1.0}], 100000.0) is None)

check("a quarter with nothing in it is not a gap in the sum",
      fmp.buyback_yield([period("2026-09-30", -1000.0),
                         period("2026-06-30", None),
                         period("2026-03-31")], 100000.0) == 0.01)
# A positive number here is stock issued, not a negative buyback.
check("issuing shares is not a negative buyback yield",
      fmp.buyback_yield([period("2026-09-30", 5000.0),
                         period("2026-06-30", -1000.0)], 100000.0) == 0.01)
check("a company that bought nothing back yields nothing, which is a real zero",
      fmp.buyback_yield([period("2026-09-30", 0.0)], 100000.0) == 0.0)
check("no cash flow at all is no answer, not a zero",
      fmp.buyback_yield([], 100000.0) is None
      and fmp.buyback_yield([{}], 100000.0) is None
      and fmp.buyback_yield(None, 100000.0) is None)
check("no market cap is no answer either",
      fmp.buyback_yield(quarters, None) is None
      and fmp.buyback_yield(quarters, 0) is None)
check("a non-numeric figure is skipped rather than crashing the loader",
      fmp.buyback_yield([period("2026-09-30", "n/a"),
                         period("2026-06-30", -1000.0)], 100000.0) == 0.01)

# The sanity cap. A quarter of the market capitalisation in a year is
# possible and rare; a run of them means the periods do not add up to a year,
# which is exactly what happened. Nothing is written, because a wrong yield
# ranks.
said_cap = []
check("a yield over the cap is not written",
      fmp.buyback_yield([period("2026-09-30", -30000.0)], 100000.0,
                        log=said_cap.append) is None)
check("and it says so, rather than looking like a company that bought nothing",
      len(said_cap) == 1 and "cap" in said_cap[0] and "30.0%" in said_cap[0],
      str(said_cap))
check("a yield just under the cap is kept",
      fmp.buyback_yield([period("2026-09-30", -24000.0)], 100000.0) == 0.24)
check("the cap is a quarter of the market capitalisation",
      fmp.BUYBACK_YIELD_CAP == 0.25)

print("\n--- the scores and growth rates the provider works out ---")

# This application computed Piotroski and Altman itself from
# financial_statement_values, which is empty on the live database, so neither
# was ever written. RR comes back from the provider as 7 and 3.09.
scored = fmp.flatten({"Profile": {}, "Quote": {}, "KeyMetrics": {}, "Ratios": {},
                      "CashFlowQuarters": [],
                      "Scores": {"piotroskiScore": 7, "altmanZScore": 3.09},
                      "Growth": {"revenueGrowth": 0.0512, "epsgrowth": 0.1834,
                                 "ebitgrowth": 0.21}})
check("the two scores are written under their own section",
      scored.get("Scores.piotroskiScore") == 7
      and scored.get("Scores.altmanZScore") == 3.09)
check("the growth rates come through as well",
      scored.get("Growth.revenueGrowth") == 0.0512
      and scored.get("Growth.epsgrowth") == 0.1834
      and scored.get("Growth.ebitgrowth") == 0.21)

# A provider_field is one column, and FMP sends epsgrowth on some responses
# and epsDilutedGrowth on others, so the choice is made once here.
check("EPS growth is published under one name whichever field carries it",
      scored.get("Derived.epsGrowth") == 0.1834)
diluted = fmp.flatten({"Profile": {}, "Quote": {}, "KeyMetrics": {}, "Ratios": {},
                       "CashFlowQuarters": [], "Scores": {},
                       "Growth": {"epsDilutedGrowth": 0.09}})
check("the diluted field is used when the other is absent",
      diluted.get("Derived.epsGrowth") == 0.09)
check("the undiluted one wins when both are there",
      fmp.flatten({"Profile": {}, "Quote": {}, "KeyMetrics": {}, "Ratios": {},
                   "CashFlowQuarters": [], "Scores": {},
                   "Growth": {"epsgrowth": 0.11, "epsDilutedGrowth": 0.09}}
                  ).get("Derived.epsGrowth") == 0.11)
check("no growth at all writes no EPS growth, rather than a zero",
      "Derived.epsGrowth" not in fmp.flatten(
          {"Profile": {}, "Quote": {}, "KeyMetrics": {}, "Ratios": {},
           "CashFlowQuarters": [], "Scores": {}, "Growth": {}})
      and "Derived.epsGrowth" not in fmp.flatten(
          {"Profile": {}, "Quote": {}, "KeyMetrics": {}, "Ratios": {},
           "CashFlowQuarters": [], "Scores": {},
           "Growth": {"epsgrowth": None, "epsDilutedGrowth": ""}}))

# Both endpoints are asked for, once each, per symbol.
asked_paths = []
saved_get2 = fmp._get
try:
    def record(path, session=None, **params):
        asked_paths.append((path, params.get("limit")))
        return []
    fmp._get = record
    fmp.fetch_sections("RR")
finally:
    fmp._get = saved_get2
check("financial-scores is fetched",
      ("financial-scores", None) in asked_paths, str(asked_paths))
check("financial-growth is fetched, one period of it",
      ("financial-growth", 1) in asked_paths, str(asked_paths))
check("and nothing is fetched twice",
      len(asked_paths) == len(set(asked_paths)), str(asked_paths))
check("the pacing counts every call the loader makes",
      len(asked_paths) == 7, "{} calls, CALLS_PER_SYMBOL should match".format(len(asked_paths)))

print("\n--- whether the provider calls it a fund ---")

# fund_sectors catches a fund whose sector says so. FGT files under "Equity
# Investments", VEIL and VOF under "General Financial", SEQI under "Financial
# Services" - all four are investment companies and none is in the list.
check("a true flag is a fund", fmp.is_fund_flag({"isFund": True}) == 'Y')
check("a false flag is not", fmp.is_fund_flag({"isFund": False}) == 'N')
check("the strings providers send instead of booleans are read",
      fmp.is_fund_flag({"isFund": "true"}) == 'Y'
      and fmp.is_fund_flag({"isFund": "False"}) == 'N'
      and fmp.is_fund_flag({"isFund": 1}) == 'Y'
      and fmp.is_fund_flag({"isFund": 0}) == 'N')
check("a profile that does not say is left alone rather than guessed at",
      fmp.is_fund_flag({}) is None and fmp.is_fund_flag(None) is None
      and fmp.is_fund_flag({"isFund": "perhaps"}) is None)

# It goes into stock_info under Derived, beside the other computed figures,
# and it uses the raw market cap rather than the pence-normalised one: the
# cash flow figures are in major units and so is marketCap.
flat_bb = fmp.flatten({"Profile": {"marketCap": 100000.0, "currency": "GBp"},
                       "Quote": {}, "KeyMetrics": {}, "Ratios": {},
                       "CashFlowQuarters": quarters})
said_flat = []
fmp.flatten({"Profile": {"marketCap": 100000.0, "currency": "GBp"},
             "Quote": {}, "KeyMetrics": {}, "Ratios": {},
             "CashFlowQuarters": [period("2026-09-30", -90000.0)]},
            log=said_flat.append)
check("the loader is told when a yield was dropped for being absurd",
      len(said_flat) == 1 and "cap" in said_flat[0], str(said_flat))
check("the buyback yield is written under Derived",
      flat_bb.get("Derived.buybackYieldTTM") == 0.1,
      str(flat_bb.get("Derived.buybackYieldTTM")))
check("it is the raw market cap that is used, not the pence one",
      flat_bb["Derived.marketCapInQuoteCurrency"] == 10000000.0
      and flat_bb["Derived.buybackYieldTTM"] == 0.1)
check("the quarterly list itself is not written out as fields",
      not any(k.startswith("CashFlowQuarters") for k in flat_bb))

print("\n--- reporting currency and the exchange rate ---")

# FMP divides a sterling price by per-share figures from accounts kept in the
# company's own currency and converts nothing, so every non-sterling reporter
# read cheaper than it is by the whole exchange rate. SHEL reports in dollars:
# P/E 8.43 where it is about 11.1. BGEO reports in lari, and GBPGEL is not
# quoted on this plan - the endpoint answers [] - so it has to be crossed
# through the dollar.

quoted = {"GBPUSD": 1.3212, "USDGEL": 2.71, "GBPEUR": 1.1403}


def pair(code):
    return quoted.get(code)


rate, how = fmp.resolve_fx_rate("USD", pair)
check("a quoted pair is used directly", rate == 1.3212 and how == "quote", how)
rate, how = fmp.resolve_fx_rate("EUR", pair)
check("so is another one", rate == 1.1403 and how == "quote")

rate, how = fmp.resolve_fx_rate("GEL", pair)
check("an unquoted pair is crossed through the dollar",
      abs(rate - 1.3212 * 2.71) < 1e-9 and how == "cross:USD",
      "{} ({})".format(rate, how))

rate, how = fmp.resolve_fx_rate("GBP", pair)
check("the base currency needs no rate", rate == 1.0, how)

rate, how = fmp.resolve_fx_rate("XYZ", pair)
check("a currency with neither a pair nor a cross has no rate",
      rate is None, "{} ({})".format(rate, how))
check("and it says why, so the log names the currency",
      "XYZ" in how, how)

rate, how = fmp.resolve_fx_rate("USD", lambda code: None)
check("a missing GBPUSD is not silently crossed with itself", rate is None, how)
rate, how = fmp.resolve_fx_rate("GEL", lambda code: 2.71 if code == "USDGEL" else None)
check("a cross needs both legs", rate is None, "{} ({})".format(rate, how))
check("an empty currency is no currency", fmp.resolve_fx_rate("", pair)[0] is None)
check("the currency is matched whatever case it arrives in",
      fmp.resolve_fx_rate("usd", pair)[0] == 1.3212)

# What the company reports in, read off one income statement.
saved_get = fmp._get
try:
    fmp._get = lambda path, session=None, **params: (
        [{"reportedCurrency": "usd ", "symbol": params.get("symbol")}]
        if path == "income-statement" else [])
    check("the reporting currency is read from the income statement",
          fmp.fetch_reported_currency("SHEL") == "USD")

    fmp._get = lambda path, session=None, **params: [{"reportedCurrency": ""}]
    check("an empty reporting currency is no currency",
          fmp.fetch_reported_currency("SHEL") is None)
    fmp._get = lambda path, session=None, **params: [{"reportedCurrency": "Pounds"}]
    check("something that is not a three letter code is refused",
          fmp.fetch_reported_currency("SHEL") is None)
    fmp._get = lambda path, session=None, **params: []
    check("a symbol with no statements has no reporting currency",
          fmp.fetch_reported_currency("MNO") is None)

    # The pair endpoint. FMP answers an unquoted pair with [] rather than an
    # error, which is why GBPGEL has to be crossed rather than retried.
    fmp._get = lambda path, session=None, **params: (
        [{"price": 1.3212}] if params.get("symbol") == "GBPUSD" else [])
    check("a quoted pair comes back as a number", fmp.fetch_fx_pair("GBPUSD") == 1.3212)
    check("an unquoted pair comes back as nothing", fmp.fetch_fx_pair("GBPGEL") is None)
    fmp._get = lambda path, session=None, **params: [{"price": 0}]
    check("a zero rate is not a rate", fmp.fetch_fx_pair("GBPUSD") is None)
    fmp._get = lambda path, session=None, **params: [{"price": "n/a"}]
    check("a non-numeric rate is not a rate", fmp.fetch_fx_pair("GBPUSD") is None)
finally:
    fmp._get = saved_get

# The market cap is normalised on the currency the PRICE is quoted in, which
# is a different question from the one the accounts are kept in. A dollar
# reporter listed in London is still quoted in pence, so the hundredfold
# conversion applies and the exchange rate does not.
check("market cap is normalised on the quote currency, not the reporting one",
      fmp.normalise_market_cap(100.0, "GBp") == 10000.0
      and fmp.normalise_market_cap(100.0, "USD") == 100.0)

print("\n--- a symbol the provider has nothing for ---")
empty = fmp.flatten({"Quote": {}, "Profile": {}, "KeyMetrics": {}, "Ratios": {}})
check("yields nothing rather than raising", empty == {})

print()
if failed:
    print("\033[31mFAILURES\033[0m  {} passed, {} failed".format(passed, failed))
    sys.exit(1)
print("\033[32mALL PASSED\033[0m  {} passed, 0 failed".format(passed))
