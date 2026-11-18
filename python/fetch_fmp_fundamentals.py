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

Seven calls per symbol, plus three for the yearly accounts of a company
whose year has turned since the last ones were stored. At 2000 symbols that
is 14,000 calls, about twenty minutes against a 750/minute plan, so there is
no need for the bulk endpoints - which are an Ultimate-tier feature and
answer 402 on Premium anyway. The accounts add about 1,050 on the first
night for the 350 the strategies trade, and after that only the companies
whose year end has passed: a few hundred a night at most, nothing most
nights.

Re-running is safe: each symbol's rows for the date are replaced, not added
to, and a symbol that fails leaves the previous run's rows alone rather than
blanking them.
"""

import datetime
import sys
import time

import mysql.connector

from functions import query, db_config
from pipeline import pipeline_resolve_asofdate, report_ccy_is_stale
from fmp import (fetch_sections, flatten, fetch_reported_currency,
                 fetch_fx_pair, resolve_fx_rate, is_fund_flag, quote_currency,
                 company_isin, fetch_statements, statement_rows,
                 unmapped_fields, FX_BASE,
                 FmpError)

EXCHANGE = 'XLON'
# quote, profile, key-metrics-ttm, ratios-ttm, one quarterly cash flow,
# financial-scores and financial-growth. The three annual statements are on
# top of these and only for the symbols whose year has turned; see
# STATEMENT_CALLS below.
CALLS_PER_SYMBOL = 7

# Stay comfortably inside the plan's ceiling. The limit is per minute, so this
# is the floor on the gap between calls; going right to the edge risks 429s
# that would cost more time than the pacing saves.
RATE_PER_MINUTE = 600
MIN_INTERVAL = 60.0 / RATE_PER_MINUTE * CALLS_PER_SYMBOL


# A company's annual accounts change two to four times a year, so they are
# not re-fetched every night. They are asked for when a symbol has none
# stored, and again once a year has passed since its last reported period -
# which is when a new one must exist - until the new one appears.
#
# Three calls each. Everything on the first night (about 1,050 on this
# universe); after that only the companies whose year has turned, which
# spread across the calendar at roughly a quarter of them at a time.
STATEMENT_CALLS = 3
STATEMENT_DUE_DAYS = 365


def statements_due(last_end, today, due_days=STATEMENT_DUE_DAYS):
    """Whether to ask for this symbol's accounts tonight.

    last_end is the end date of the newest period stored, or None.
    """
    if last_end is None:
        return True
    try:
        days = (today - last_end).days
    except TypeError:
        return True
    return days >= due_days


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


def store_statements(cursor, connection, symbol, rows):
    """Write one symbol's statement lines, period by period.

    financial_statement_periods is shared by every company - one row per end
    date - and has no unique key on the date, so the period is looked up
    before it is inserted or the table would collect a row per symbol per
    night. The values for a period are replaced rather than added to, so a
    re-run is a no-op and a restated year overwrites the old figure.
    """
    if not rows:
        return 0

    periods = {}
    for end_date, _item, _value in rows:
        periods[end_date] = None

    for end_date in list(periods):
        cursor.execute("select period_id from financial_statement_periods "
                       "where end_date=%s order by period_id limit 1", (end_date,))
        found = cursor.fetchone()
        if found:
            periods[end_date] = found[0]
            continue
        cursor.execute("insert into financial_statement_periods (end_date, period_name) "
                       "values (%s,%s)", (end_date, str(end_date.year)))
        periods[end_date] = cursor.lastrowid

    written = 0
    for end_date, item, value in rows:
        period_id = periods.get(end_date)
        if period_id is None:
            continue
        cursor.execute("delete from financial_statement_values "
                       "where symbol=%s and period_id=%s and item_name=%s",
                       (symbol, period_id, item))
        cursor.execute("insert into financial_statement_values "
                       "(symbol, period_id, item_name, value) values (%s,%s,%s,%s)",
                       (symbol, period_id, item, round(float(value), 4)))
        written += 1

    connection.commit()
    return written


def latest_period_end(cursor, symbol):
    """The end date of the newest period stored for this symbol, or None.

    Through the loader's own cursor, with the symbol as a parameter:
    functions.query() takes a string and nothing else, and a symbol pasted
    into SQL is a habit worth not having even where it comes from this
    application's own table.
    """
    cursor.execute("select max(fsp.end_date) "
                   "from financial_statement_periods fsp, financial_statement_values fsv "
                   "where fsv.period_id = fsp.period_id and fsv.symbol = %s", (symbol,))
    row = cursor.fetchone()
    if not row or row[0] is None:
        return None
    return row[0] if isinstance(row[0], datetime.date) else None


def mapped_provider_fields():
    """{indicator: provider_field} for every enabled indicator that has one."""
    rows = query("select name, provider_field from screen_indicators "
                 "where enabled='Y' and provider_field is not null "
                 "and provider_field <> ''")
    return dict((row[0], row[1]) for row in (rows or []) if row and row[0])


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
    currencies_read = funds_flagged = foreign_quotes = isins_read = 0
    statement_symbols = statement_rows_written = 0
    started = time.time()

    # What the indicators ask the provider for, so a field name that is
    # wrong can be named at the end rather than leaving a column of dashes
    # on the quote page with nothing to say why.
    wanted_fields = mapped_provider_fields()
    never_seen = dict(wanted_fields)
    today = datetime.date.today()

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

        # What the share is QUOTED in, which the paper engine needs to size a
        # trade in pounds. Nearly every London line is "GBp" - pence - and the
        # engine read those as pounds until this column existed, so it sized
        # every trade a hundred times too small. Stored as the provider spells
        # it: "GBp" and "GBP" are the same three letters a hundred times
        # apart, so the case is the data. See sql/strategy_prices.sql.
        quote_ccy = quote_currency(sections.get("Profile"))
        if quote_ccy is not None:
            cursor.execute("update stock_symbols set quote_ccy=%s, quote_ccy_asof=%s "
                           "where symbol=%s and exchange=%s",
                           (quote_ccy, asofdate, symbol, EXCHANGE))
            connection.commit()
            if quote_ccy.upper() not in ("GBP", "GBX"):
                foreign_quotes += 1

        # The ISIN, which is what the broker connection matches on. Trading
        # 212's ticker keeps a company's OLD code after a rename - Barratt
        # Redrow is still BDEVl_EQ there - so matching on a ticker misses
        # exactly the companies that have changed, and every one of their
        # instruments carries an ISIN. Written from the profile that is
        # fetched every run anyway, so it costs nothing.
        isin = company_isin(sections.get("Profile"))
        if isin is not None:
            cursor.execute("update stock_symbols set isin=%s "
                           "where symbol=%s and exchange=%s",
                           (isin, symbol, EXCHANGE))
            connection.commit()
            isins_read += 1

        if not flat:
            # FMP answers an unknown ticker with an empty list rather than an
            # error. Leave whatever a previous run stored rather than deleting
            # it: no data is not the same as the data being gone.
            skipped += 1
            continue

        # Every indicator whose field appeared in this symbol's response is
        # one the mapping has got right for at least one company.
        for indicator in set(never_seen) - set(unmapped_fields(flat, never_seen)):
            del never_seen[indicator]

        # The yearly accounts, for a company that has none stored or whose
        # year has turned since the last one. Three calls, and most nights
        # most companies need none.
        try:
            if statements_due(latest_period_end(cursor, symbol), today):
                rows = statement_rows(fetch_statements(symbol))
                written = store_statements(cursor, connection, symbol, rows)
                if written:
                    statement_symbols += 1
                    statement_rows_written += written
        except FmpError as e:
            print("  {}: accounts: {}".format(symbol, e))
        except Exception as e:                      # one bad symbol, carry on
            print("  {}: accounts: {}: {}".format(symbol, type(e).__name__, e))

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
          "{} flagged as funds, {} quoted in something other than sterling, "
          "{} ISINs, {} with accounts ({} lines), {:.0f}s"
          .format(asofdate, loaded, skipped, failed, currencies_read,
                  funds_flagged, foreign_quotes, isins_read,
                  statement_symbols, statement_rows_written, time.time() - started))

    # An indicator mapped to a field no response carried. Said by name: the
    # alternative is a column of dashes on the quote page and a month before
    # anybody works out which end it is missing from.
    if loaded and never_seen:
        for indicator in sorted(never_seen):
            print("  no figure anywhere for {}: nothing sent {}"
                  .format(indicator, never_seen[indicator]))
    # A run where nothing loaded is a failure worth a non-zero exit, so cron
    # reports it rather than looking like a clean night.
    return 1 if loaded == 0 else 0


if __name__ == '__main__':
    sys.exit(main())
