# This job reads the database only: it turns the fundamentals a loader has
# already stored into statistics rows. It makes no HTTP calls, so it needs
# nothing beyond the standard library and mysql.connector.
#
# It used to build a rate-limited caching HTTP session and import
# get_key_ratios, both left over from the yfinance loader and neither used.
# The imports alone were enough to stop the nightly run on a server where
# those packages are not installed.
import sys
import datetime
from datetime import timedelta
from functions import query
from pipeline import pipeline_asofdate, fx_correct
from share_functions import insert_statistic

# The statistics the Mac mini publishes (includes/data_ownership.php,
# DATA_PUBLISHED_STATISTICS): data_import is their only writer, so this job
# never writes one, whatever screen_indicators maps. An indicator with no
# provider_field falls back to matching stock_info on its own name, so
# unmapping piotroski_score alone would not be enough if an attribute of that
# name ever turned up. The suite checks this list against the PHP one.
MAC_MINI_STATISTICS = ('close_price', 'ma50', 'ma200', '1mnth', '3mnth', '6mnth', '12mnth',
                       'piotroski_score', 'book_to_market')

# Set exchange
exchange = 'XLON'

# Get current date. Shared with the loader: it stamps stock_info.asofdate with
# the same value and the join below needs an exact match. The last trading day,
# the same rule get_statistics.php uses, rather than the calendar's yesterday -
# which on a Sunday is a Saturday and has no prices or fundamentals at all.
date = pipeline_asofdate()
if date is None:
    print("no prices have been loaded for", exchange, "- refusing to run")
    sys.exit(2)
print("As of date:", date)




# Get symbols, and what each one keeps its accounts in.
rows = query("select symbol, report_ccy from stock_symbols where enabled='Y' and exchange='" +exchange+ "'  order by symbol")

report_ccy = dict((row[0], (row[1] or '').strip().upper()) for row in rows)

# Today's rates, quote units per pound, written by the loader. A currency that
# is not in here has no rate for the day, which is not the same as a rate of 1.
fx_rows = query("select quote_ccy, rate from fx_rates where date='" + date + "' and base='GBP'")
fx = dict((row[0], float(row[1])) for row in (fx_rows or []))
print("fx rates for {}: {}".format(date, fx or "none"))

# FMP's price ratios divide a sterling price by per-share figures from accounts
# kept in the company's own currency, with no conversion. SHEL reports in
# dollars and came back with a P/E of 8.43 where it is about 11.1; BGEO reports
# in lari, P/E 2.38 where it is about 8.6. 62 of 272 companies with statements
# report in something other than sterling, and seven of the thirty places in
# the value, quality and overall top tens were held by one of them.
#
# screen_indicators.fx_adjust says what each indicator needs: MULTIPLY for a
# price over a per-share figure, DIVIDE for the same ratio inverted, NONE for
# anything that is one currency over the same currency. Corrected here rather
# than in the loader so stock_info keeps the provider's own numbers and the
# correction stays auditable: raw value, rate and reporting currency are all
# still on record.

written = 0
corrected = 0
cleared = 0
symbols_with_data = 0
unconvertible = {}

for row in rows:

    symbol = row[0]
    currency = report_ccy.get(symbol, '')
    # GBP reporters need no conversion; an unknown currency is left alone
    # rather than assumed to be sterling.
    rate = 1.0 if currency in ('', 'GBP') else fx.get(currency)

    # Clear this date's provider-sourced statistics for the symbol before
    # writing the new set, so a figure the provider has stopped sending stops
    # being there. insert_statistic() is an upsert, which updates a value that
    # comes again and leaves one that does not: on the re-run for 2026-11-13
    # TRN's buyback yield was correctly not written, being over the sanity
    # cap, and the 47.9% from the run before it stayed in statistics and went
    # on being ranked. FGT kept 65% and VEIL 48% the same way.
    #
    # Only the indicators that come from a provider_field, and only for a
    # symbol the provider answered for this run. Everything this application
    # works out for itself - the momentum series, the moving averages, the
    # scores - is written by get_statistics.php afterwards and is not this
    # job's to delete. And a symbol with nothing in stock_info for the date is
    # a symbol the loader got nothing for, which is not the same as the data
    # being gone: the loader takes that view too, and leaves what it has.
    has_data = query("select count(*) from stock_info where symbol='"+symbol+"' and asofdate='"+date+"'")
    if has_data and has_data[0][0]:
        removed = query("delete s from statistics s, screen_indicators sci"
                        " where s.indicator = sci.name"
                        "   and coalesce(nullif(sci.provider_field,''),'') <> ''"
                        "   and s.symbol='"+symbol+"'"
                        "   and s.exchange='"+exchange+"'"
                        "   and s.date='"+date+"'")
        cleared += removed if isinstance(removed, int) else 0

    indicator_stats = query("select sti.symbol, sci.name, sti.value, ifnull(nullif(sci.fx_adjust,''),'NONE') from screen_indicators sci, stock_info sti where sci.enabled='Y' and sti.attribute = coalesce(nullif(sci.provider_field,''), sci.name) and sti.symbol='"+symbol+"' and sti.asofdate = '"+date+"' and sci.name not in ("+", ".join("'"+n+"'" for n in MAC_MINI_STATISTICS)+") order by order_number")

    if indicator_stats:
        symbols_with_data += 1

    skipped_here = 0
    for stat in indicator_stats:
        value = fx_correct(stat[2], stat[3], rate)
        if value is None:
            # No rate for this currency. Writing the provider's figure anyway
            # would rank a company a third cheaper than it is, which is worse
            # than leaving it out of that ranking for the day.
            skipped_here += 1
            unconvertible[currency] = unconvertible.get(currency, 0) + 1
            continue
        if str(value) != str(stat[2]):
            corrected += 1
        insert_statistic(stat[0], exchange, stat[1], date, value)
        written += 1

    # The rate that was used, beside the values it was used on, so "why is
    # SHEL's P/E 11.14 when FMP says 8.43" is answerable from one table.
    if currency not in ('', 'GBP') and rate:
        insert_statistic(symbol, exchange, 'fx_rate', date, rate)

    print("  {:8s} {} indicators{}{}".format(
        symbol, len(indicator_stats),
        "" if currency in ('', 'GBP') else "  [" + currency + "]",
        "" if not skipped_here else "  {} not written, no rate".format(skipped_here)))

print("{}: {} statistics written for {} of {} symbols, {} converted from another "
      "currency, {} stale rows cleared"
      .format(date, written, symbols_with_data, len(rows), corrected, cleared))

if unconvertible:
    print("NO RATE: " + ", ".join(
        "{} ({} values not written)".format(ccy or "unknown currency", n)
        for ccy, n in sorted(unconvertible.items())))
    print("         Those companies are not ranked on their price ratios today.")

# Finding nothing is not success. This used to pass silently, which is how a
# one day disagreement between the loader's asofdate and this date could have
# gone unnoticed: the join simply matched no rows and the job "succeeded".
if written == 0:
    loaded = query("select count(*), min(asofdate), max(asofdate) from stock_info")
    print("ERROR: no statistics written for " + date + ".")
    if loaded and loaded[0][0]:
        print("       stock_info holds {} rows dated {} to {}."
              .format(loaded[0][0], loaded[0][1], loaded[0][2]))
        print("       If those dates do not include " + date + ", the loader and this job "
              "disagree on the as of date.")
    else:
        print("       stock_info is empty; run fetch_eodhd_fundamentals.py first.")
    sys.exit(1)
