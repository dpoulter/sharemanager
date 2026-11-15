"""
Loads EODHD fundamentals into stock_info.

Replaces fetch_stock_info.py, which pulled the same data from yfinance. The
storage shape is unchanged: one (symbol, asofdate, attribute, value) row per
field, so get_api_stats.py and everything downstream keep working.

Run before get_api_stats.py:
    EODHD_API_KEY=... python3 fetch_eodhd_fundamentals.py [asofdate]
"""

import sys
import time
from datetime import date

import mysql.connector

from functions import query, db_config
from pipeline import pipeline_resolve_asofdate
from eodhd import get_fundamentals, flatten_fundamentals, EodhdError

EXCHANGE = 'XLON'

# EODHD's own guidance is to stay under 1000 requests/minute. Two a second is
# far below that; the binding constraint on these plans is the daily call
# budget, not the rate.
#
# This was a requests_cache + requests_ratelimiter session. Those packages are
# not installed on the server and nothing installs them, so the nightly job
# could not start whenever SM_DATA_PROVIDER was eodhd. Pacing by the clock
# needs no dependency. The response caching went with them: this job runs once
# a night, so nothing was being served from that cache anyway.
RATE_PER_SECOND = 2
MIN_INTERVAL = 1.0 / RATE_PER_SECOND

# The HTTP client no longer takes a session; the argument is kept so the call
# sites read the same as before.
session = None


def main():
    # Must match what get_api_stats.py reads, or the join finds nothing and the
    # fundamentals never reach statistics. Shared rather than restated. A date
    # given on the command line has to be one prices were loaded for, the same
    # rule get_statistics.php applies.
    asofdate = pipeline_resolve_asofdate(sys.argv[1] if len(sys.argv) > 1 else None)
    if asofdate is None:
        print("no prices for the requested as-of date - refusing to run")
        return 2

    rows = query("select symbol from stock_symbols where enabled='Y' "
                 "and exchange='" + EXCHANGE + "' order by symbol")

    connection = mysql.connector.connect(**db_config())
    cursor = connection.cursor()

    loaded = skipped = failed = 0

    for row in rows:
        symbol = row[0]
        turn = time.time()
        try:
            document = get_fundamentals(symbol, session=session)
        except EodhdError as error:
            # A key or quota problem affects every symbol, so stop rather than
            # grinding through the whole universe logging the same failure.
            print("ERROR {}: {}".format(symbol, error))
            break
        except Exception as error:
            print("ERROR {}: {}".format(symbol, error))
            failed += 1
            continue

        attributes = flatten_fundamentals(document)
        if not attributes:
            print("  {:8s} no fundamentals returned".format(symbol))
            skipped += 1
            continue

        # Replace rather than append, so re-running for a date is idempotent.
        cursor.execute("delete from stock_info where symbol=%s and asofdate=%s",
                       (symbol, asofdate))
        cursor.executemany(
            "insert into stock_info (symbol, asofdate, attribute, value) values (%s,%s,%s,%s)",
            [(symbol, asofdate, attribute, str(value))
             for attribute, value in attributes.items()])
        connection.commit()

        print("  {:8s} {} attributes".format(symbol, len(attributes)))
        loaded += 1

        # Pace to the rate the session used to enforce.
        spent = time.time() - turn
        if spent < MIN_INTERVAL:
            time.sleep(MIN_INTERVAL - spent)

    cursor.close()
    connection.close()

    print("fundamentals for {}: {} loaded, {} without data, {} failed"
          .format(asofdate, loaded, skipped, failed))


if __name__ == '__main__':
    main()
