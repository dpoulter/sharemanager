import sys
import datetime
import requests_cache
from datetime import timedelta
from functions import query
from pipeline import pipeline_asofdate
from share_functions import get_key_ratios, insert_statistic
from requests import Session
from requests_cache import CacheMixin, SQLiteCache
from requests_ratelimiter import LimiterMixin, MemoryQueueBucket
from pyrate_limiter import Duration, RequestRate, Limiter


class CachedLimiterSession(CacheMixin, LimiterMixin, Session):
    pass

session = CachedLimiterSession(
    limiter=Limiter(RequestRate(2, Duration.SECOND*5)),  # max 2 requests per 5 seconds
    bucket_class=MemoryQueueBucket,
    backend=SQLiteCache("yfinance.cache"),
)

# Set exchange
exchange = 'XLON'

# Get current date. Shared with the loader: it stamps stock_info.asofdate with
# the same value and the join below needs an exact match.
date = pipeline_asofdate()
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
