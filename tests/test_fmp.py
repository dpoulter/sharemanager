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
