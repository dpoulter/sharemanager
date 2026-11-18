#!/usr/bin/env python3
"""
Check FMP's currencies for a few symbols, against the live API.

    FMP_API_KEY=... python3 tools/fmp_currency_check.py SHEL BGEO RR

Prints, per symbol, the two currencies that are easy to confuse - the one the
price is quoted in (profile.currency, GBp for London) and the one the accounts
are kept in (income-statement.reportedCurrency) - and then the three figures
that tell you which currency FMP's market capitalisation is in:

    price x shares implied by the cap        if the cap is in the quote
                                             currency this is the share count
    the same, divided by the GBP->ccy rate   what it would be if the cap were
                                             in the reporting currency

Only one of those can be the real share count, and the two differ by the whole
exchange rate, so a quick look at the company's reported share count settles
it. This is the check behind the assumption in python/fmp.py's
normalise_market_cap(): that marketCap is in the price's currency, not the
company's. It makes four calls per symbol and writes nothing.
"""

import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'python'))

from fmp import (                                                 # noqa: E402
    fetch_sections, fetch_reported_currency, fetch_fx_pair,
    resolve_fx_rate, normalise_market_cap, FmpError)


def main(symbols):
    if not symbols:
        print(__doc__)
        return 2

    for symbol in symbols:
        try:
            sections = fetch_sections(symbol)
            reported = fetch_reported_currency(symbol)
        except FmpError as e:
            print("{}: {}".format(symbol, e))
            continue

        profile = sections.get("Profile") or {}
        quote = sections.get("Quote") or {}
        ratios = sections.get("Ratios") or {}

        quote_ccy = profile.get("currency")
        price = quote.get("price") or profile.get("price")
        cap = profile.get("marketCap")
        cap_in_quote_units = normalise_market_cap(cap, quote_ccy)

        rate, how = (1.0, "same currency")
        if reported and reported != "GBP":
            rate, how = resolve_fx_rate(reported, fetch_fx_pair)

        print("\n{}".format(symbol))
        print("  price quoted in      {}".format(quote_ccy))
        print("  accounts kept in     {}".format(reported))
        print("  GBP->{}              {} ({})".format(reported, rate, how))
        print("  price                {}".format(price))
        print("  marketCap (raw)      {}".format(cap))
        print("  cap in price units   {}".format(cap_in_quote_units))

        if price and cap_in_quote_units:
            shares_if_quote = cap_in_quote_units / float(price)
            print("  shares if cap is in the quote currency      {:,.0f}"
                  .format(shares_if_quote))
            if rate:
                print("  shares if cap is in the reporting currency  {:,.0f}"
                      .format(shares_if_quote / float(rate)))

        pe = ratios.get("priceToEarningsRatioTTM")
        if pe is not None and rate:
            print("  P/E as FMP reports it            {}".format(pe))
            print("  P/E once converted               {:.2f}"
                  .format(float(pe) * float(rate)))

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
