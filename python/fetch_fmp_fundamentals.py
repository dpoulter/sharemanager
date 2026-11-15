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
from pipeline import pipeline_resolve_asofdate, report_ccy_is_stale
from fmp import (fetch_sections, flatten, fetch_reported_currency,
                 fetch_fx_pair, resolve_fx_rate, is_fund_flag, FX_BASE,
                 FmpError)

EXCHANGE = 'XLON'
# quote, profile, key-metrics-ttm, ratios-ttm and one quarterly cash flow.
CALLS_PER_SYMBOL = 5

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
    # None when the date asked for has no prices, which main() refuses on. The
    # default is the last trading day, the same rule get_statistics.php uses.
    return pipeline_resolve_asofdate(asofdate), limit


def store_fx_rates(cursor, connection, asofdate):
    """One rate per currency the universe reports in, for this date.

    Pounds per... the other way round: quote units per pound, which is what a
    ratio built from a sterling price over a foreign per-share figure has to be
    multiplied by. GBPGEL is not quoted on this plan, so resolve_fx_rate()
    crosses it through the dollar.

    A currency with no rate is reported and left out of the table, which
    leaves the ratios for its companies unwritten rather than wrong. Rewritten
    for the date on every run, so a second run for the same date replaces the
    rates instead of failing on the primary key.
    """
    cursor.execute(
        "select distinct report_ccy from stock_symbols "
        "where enabled=%s and exchange=%s and report_ccy is not null "
        "and report_ccy <> %s", ('Y', EXCHANGE, FX_BASE))
    currencies = sorted(row[0] for row in cursor.fetchall() if row[0])

    if not currencies:
        print("fx: every reporter is in " + FX_BASE + ", no rates needed")
        return

    stored, missing = 0, []
    for currency in currencies:
        try:
            rate, how = resolve_fx_rate(currency, fetch_fx_pair)
        except FmpError as e:
            rate, how = None, str(e)
        if rate is None:
            missing.append("{} ({})".format(currency, how))
            continue
        cursor.execute(
            "replace into fx_rates (date, base, quote_ccy, rate, source) "
            "values (%s,%s,%s,%s,%s)",
            (asofdate, FX_BASE, currency, round(float(rate), 8), how))
        stored += 1
        print("  fx {}{} = {:.6f} ({})".format(FX_BASE, currency, rate, how))
    connection.commit()

    print("fx: {} of {} rates stored for {}".format(stored, len(currencies), asofdate))
    if missing:
        # Named, because the consequence is silent otherwise: every company
        # reporting in one of these keeps no corrected ratio and drops out of
        # the value and quality rankings for the day.
        print("fx: NO RATE for " + ", ".join(missing)
              + " - companies reporting in those currencies will not be ranked "
                "on their price ratios today")


def main():
    asofdate, limit = parse_args(sys.argv[1:])

    if asofdate is None:
        print("no prices for the requested as-of date - refusing to run")
        return 1

    rows = query("select symbol, report_ccy, report_ccy_asof from stock_symbols "
                 "where enabled='Y' and exchange='" + EXCHANGE + "' order by symbol")

    # query() hands back tuples from a plain cursor, so the symbol is row[0];
    # indexing it by name raises "tuple indices must be integers". It also
    # swallows a connection error and returns None, which would otherwise fail
    # here as "NoneType is not iterable" rather than saying what went wrong.
    if rows is None:
        print("could not read stock_symbols; see the database error above")
        return 1
    symbols = [row[0] for row in rows]
    reported = dict((row[0], (row[1], row[2])) for row in rows)
    if limit:
        symbols = symbols[:limit]
    if not symbols:
        print("no enabled {} symbols in stock_symbols, nothing to load".format(EXCHANGE))
        return 1

    estimate = len(symbols) * CALLS_PER_SYMBOL
    print("fmp fundamentals: {} symbols, about {} calls, as of {}"
          .format(len(symbols), estimate, asofdate))

    connection = mysql.connector.connect(**db_config())
    cursor = connection.cursor()

    loaded = skipped = failed = 0
    currencies_read = funds_flagged = 0
    started = time.time()

    for n, symbol in enumerate(symbols, 1):
        turn = time.time()

        # What the company keeps its accounts in. One extra call, and only for
        # a symbol with no answer stored or one over a month old - the figure
        # changes about once a decade. Without it the ratios below cannot be
        # corrected, and an uncorrected dollar reporter looks a third cheaper
        # than it is.
        stored_ccy, stored_on = reported.get(symbol, (None, None))
        if report_ccy_is_stale(stored_ccy, stored_on):
            try:
                found = fetch_reported_currency(symbol)
            except FmpError as e:
                found = None
                print("  {}: reporting currency: {}".format(symbol, e))
            if found:
                cursor.execute(
                    "update stock_symbols set report_ccy=%s, report_ccy_asof=%s "
                    "where symbol=%s and exchange=%s",
                    (found, asofdate, symbol, EXCHANGE))
                connection.commit()
                reported[symbol] = (found, asofdate)
                currencies_read += 1

        try:
            sections = fetch_sections(symbol)
            flat = flatten(sections, log=print)
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

        # Whether FMP calls this a fund. The profile is fetched every run
        # anyway, so this costs nothing, and the sector list alone misses the
        # ones filed under "Equity Investments", "General Financial" and
        # "Financial Services" - which then filled the value top ten on the
        # strength of buyback yields no operating company could post.
        fund_flag = is_fund_flag(sections.get("Profile"))
        if fund_flag is not None:
            cursor.execute("update stock_symbols set is_fund=%s where symbol=%s and exchange=%s",
                           (fund_flag, symbol, EXCHANGE))
            connection.commit()
            if fund_flag == 'Y':
                funds_flagged += 1

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

    store_fx_rates(cursor, connection, asofdate)

    cursor.close()
    connection.close()

    print("as of {}: {} loaded, {} with no data, {} failed, {} currencies read, "
          "{} flagged as funds, {:.0f}s"
          .format(asofdate, loaded, skipped, failed, currencies_read,
                  funds_flagged, time.time() - started))
    # A run where nothing loaded is a failure worth a non-zero exit, so cron
    # reports it rather than looking like a clean night.
    return 1 if loaded == 0 else 0


if __name__ == '__main__':
    sys.exit(main())
