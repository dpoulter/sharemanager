#!/usr/bin/env python3
"""
Loads Financial Modeling Prep dividend payments into symbol_dividends.

    FMP_API_KEY=... python3 python/fetch_fmp_dividends.py
    FMP_API_KEY=... python3 python/fetch_fmp_dividends.py --limit 20

One call per enabled symbol. historical_prices holds RAW closes, which leave
dividends out, so the paper engine credits what a holding paid from this
table (sql/dividends.sql). A company's dividends change a few times a year, so
a symbol is asked about again only once its last load is a week old.

The amount is stored in the unit historical_prices uses for that share -
pence for a London line - and dividend_rows() in fmp.py puts it there by the
price on the ex-date. Everything that could not be stored is counted by
reason at the end, so a field name that is wrong shows up as "300 had no
ex-date" rather than as a table that quietly stays empty.

Re-running is safe: a payment is keyed on (symbol, exchange, ex-date) and is
replaced, not added to, and a symbol that fails leaves what it had.
"""

import datetime
import sys
import time

import mysql.connector

from functions import db_config, query
from fmp import fetch_dividends, dividend_rows, FmpError

EXCHANGE = 'XLON'
REFRESH_DAYS = 7
# Well inside the plan's ceiling; there is no hurry for a weekly load.
MIN_INTERVAL = 0.15


def dividends_due(last_loaded, today, refresh_days=REFRESH_DAYS):
    """Whether a symbol's dividends are worth asking about again."""
    if last_loaded is None:
        return True
    if isinstance(last_loaded, datetime.datetime):
        last_loaded = last_loaded.date()
    return (today - last_loaded).days >= refresh_days


def store_dividends(cursor, connection, symbol, rows, loaded_at):
    """Replace one symbol's rows for the ex-dates it was just given."""
    for ex, pay, amount in rows:
        cursor.execute(
            "insert into symbol_dividends "
            "(symbol, exchange, ex_date, pay_date, amount, source, loaded_at) "
            "values (%s, %s, %s, %s, %s, 'fmp', %s) "
            "on duplicate key update pay_date=values(pay_date), "
            "amount=values(amount), source=values(source), "
            "loaded_at=values(loaded_at)",
            (symbol, EXCHANGE, ex, pay, amount, loaded_at))
    connection.commit()
    return len(rows)


def parse_args(argv):
    limit = None
    for i, arg in enumerate(argv):
        if arg == '--limit' and i + 1 < len(argv):
            limit = int(argv[i + 1])
    return limit


def main():
    limit = parse_args(sys.argv[1:])

    ready = query("select count(*) from information_schema.tables "
                  "where table_schema=database() and table_name='symbol_dividends'")
    if not ready or not ready[0][0]:
        print("sql/dividends.sql has not been applied; nothing to load")
        return 1

    rows = query("select symbol from stock_symbols where enabled='Y' and exchange='"
                 + EXCHANGE + "' order by symbol")
    if rows is None:
        print("could not read stock_symbols; see the database error above")
        return 1
    symbols = [row[0] for row in rows]
    if limit:
        symbols = symbols[:limit]
    if not symbols:
        print("no enabled {} symbols in stock_symbols, nothing to load".format(EXCHANGE))
        return 1

    connection = mysql.connector.connect(**db_config())
    cursor = connection.cursor()
    today = datetime.date.today()
    now = datetime.datetime.now()

    totals = {"seen": 0, "kept": 0, "as_pence": 0, "as_pounds": 0, "no_ex_date": 0,
              "no_amount": 0, "no_price": 0, "implausible": 0}
    symbols_done = symbols_skipped = failed = 0

    print("fmp dividends: {} symbols".format(len(symbols)))

    for symbol in symbols:

        cursor.execute("select max(loaded_at) from symbol_dividends "
                       "where symbol=%s and exchange=%s", (symbol, EXCHANGE))
        last = cursor.fetchone()
        if not dividends_due(last[0] if last else None, today):
            symbols_skipped += 1
            continue

        try:
            records = fetch_dividends(symbol)
        except FmpError as e:
            print("  {}: {}".format(symbol, e))
            failed += 1
            if failed >= 5 and symbols_done == 0:
                print("failing on every symbol so far, stopping early")
                break
            continue

        def price_on(ex_date, _symbol=symbol):
            cursor.execute(
                "select price from historical_prices where symbol=%s and exchange=%s "
                "and date<=%s and price is not null order by date desc limit 1",
                (_symbol, EXCHANGE, ex_date))
            found = cursor.fetchone()
            return float(found[0]) if found and found[0] is not None else None

        stored, report = dividend_rows(records, price_on)
        for key in totals:
            totals[key] += report[key]

        store_dividends(cursor, connection, symbol, stored, now)
        symbols_done += 1
        time.sleep(MIN_INTERVAL)

    print("fmp dividends: {} loaded, {} not due, {} failed".format(
        symbols_done, symbols_skipped, failed))
    print("  payments seen {}, stored {} ({} read as pence, {} as pounds)".format(
        totals["seen"], totals["kept"], totals["as_pence"], totals["as_pounds"]))

    # Said by reason, so a wrong field name is named rather than left as a
    # table that stays empty.
    for key, words in (("no_ex_date", "had no ex-date"),
                       ("no_amount", "had no amount"),
                       ("no_price", "had no price to judge the unit by"),
                       ("implausible", "were over {:.0%} of the price".format(0.25))):
        if totals[key]:
            print("  ! {} payment(s) {}".format(totals[key], words))
    if totals["seen"] and not totals["kept"]:
        print("  ! nothing was stored from {} payments: check the field names "
              "with python/fmp_probe.py".format(totals["seen"]))

    connection.close()
    return 0 if failed == 0 else 1


if __name__ == '__main__':
    sys.exit(main())
