#!/usr/bin/env python3
"""
Loads Financial Modeling Prep fundamentals into stock_info.

The storage shape matches fetch_eodhd_fundamentals.py exactly: one
(symbol, asofdate, attribute, value) row per field, so get_api_stats.py and
share_fundamentals() read either provider's output without knowing which ran.
What differs is the attribute prefix - Quote./Profile./KeyMetrics./Ratios./
Derived. rather than EODHD's General./Highlights./... - so rows from the two
providers are distinguishable in one table.

    FMP_API_KEY=... python3 python/fetch_fmp_fundamentals.py [asofdate]
    FMP_API_KEY=... python3 python/fetch_fmp_fundamentals.py --limit 20

Four calls per symbol. At 2000 symbols that is 8000 calls, about eleven
minutes against a 750/minute plan, so there is no need for the bulk endpoints
- which are an Ultimate-tier feature and answer 402 on Premium anyway.

Re-running is safe: each symbol's rows for the date are replaced, not added
to, and a symbol that fails leaves the previous run's rows alone rather than
blanking them.
"""

import sys
import time

import mysql.connector

from functions import query, db_config
from pipeline import pipeline_asofdate
from fmp import fetch_sections, flatten, FmpError

EXCHANGE = 'XLON'
CALLS_PER_SYMBOL = 4

# Stay comfortably inside the plan's ceiling. The limit is per minute, so this
# is the floor on the gap between calls; going right to the edge risks 429s
# that would cost more time than the pacing saves.
RATE_PER_MINUTE = 600
MIN_INTERVAL = 60.0 / RATE_PER_MINUTE * CALLS_PER_SYMBOL


def parse_args(argv):
    """Returns (asofdate, limit). --limit n is for a dry run on a few symbols."""
    asofdate, limit, rest = None, None, []
    i = 0
    while i < len(argv):
        if argv[i] == "--limit" and i + 1 < len(argv):
            limit = int(argv[i + 1])
            i += 2
            continue
        rest.append(argv[i])
        i += 1
    if rest:
        asofdate = rest[0]
    return asofdate or pipeline_asofdate(), limit


def main():
    asofdate, limit = parse_args(sys.argv[1:])

    rows = query("select symbol from stock_symbols where enabled='Y' "
                 "and exchange='" + EXCHANGE + "' order by symbol")
    symbols = [r['symbol'] for r in rows]
    if limit:
        symbols = symbols[:limit]

    estimate = len(symbols) * CALLS_PER_SYMBOL
    print("fmp fundamentals: {} symbols, about {} calls, as of {}"
          .format(len(symbols), estimate, asofdate))

    connection = mysql.connector.connect(**db_config)
    cursor = connection.cursor()

    loaded = skipped = failed = 0
    started = time.time()

    for n, symbol in enumerate(symbols, 1):
        turn = time.time()
        try:
            flat = flatten(fetch_sections(symbol))
        except FmpError as e:
            # A key or plan problem affects every symbol, so there is no point
            # grinding through two thousand of them to say so each time.
            print("  {}: {}".format(symbol, e))
            failed += 1
            if failed >= 5 and loaded == 0:
                print("failing on every symbol so far, stopping early")
                break
            continue
        except Exception as e:                      # one bad symbol, carry on
            print("  {}: {}: {}".format(symbol, type(e).__name__, e))
            failed += 1
            continue

        if not flat:
            # FMP answers an unknown ticker with an empty list rather than an
            # error. Leave whatever a previous run stored rather than deleting
            # it: no data is not the same as the data being gone.
            skipped += 1
            continue

        cursor.execute("delete from stock_info where symbol=%s and asofdate=%s",
                       (symbol, asofdate))
        cursor.executemany(
            "insert into stock_info (symbol, asofdate, attribute, value) values (%s,%s,%s,%s)",
            [(symbol, asofdate, attribute, str(value))
             for attribute, value in flat.items()])
        connection.commit()
        loaded += 1

        if n % 100 == 0:
            print("  {}/{} ... {:.0f}s elapsed".format(n, len(symbols), time.time() - started))

        # Pace to the plan's rate limit.
        spent = time.time() - turn
        if spent < MIN_INTERVAL:
            time.sleep(MIN_INTERVAL - spent)

    cursor.close()
    connection.close()

    print("as of {}: {} loaded, {} with no data, {} failed, {:.0f}s"
          .format(asofdate, loaded, skipped, failed, time.time() - started))
    # A run where nothing loaded is a failure worth a non-zero exit, so cron
    # reports it rather than looking like a clean night.
    return 1 if loaded == 0 and symbols else 0


if __name__ == '__main__':
    sys.exit(main())
