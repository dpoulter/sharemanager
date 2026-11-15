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
from pipeline import pipeline_asofdate
from share_functions import insert_statistic

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




# Get symbols
rows = query("select symbol from stock_symbols where enabled='Y' and exchange='" +exchange+ "'  order by symbol")


written = 0
symbols_with_data = 0

for row in rows:

    symbol = row[0]

    indicator_stats = query("select sti.symbol, sci.name, sti.value from screen_indicators sci, stock_info sti where sci.enabled='Y' and sti.attribute = coalesce(nullif(sci.provider_field,''), sci.name) and sti.symbol='"+symbol+"' and sti.asofdate = '"+date+"' order by order_number")

    if indicator_stats:
        symbols_with_data += 1
    print("  {:8s} {} indicators".format(symbol, len(indicator_stats)))

    for stat in indicator_stats:
        insert_statistic(stat[0], exchange, stat[1], date, stat[2])
        written += 1

print("{}: {} statistics written for {} of {} symbols".format(date, written, symbols_with_data, len(rows)))

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
