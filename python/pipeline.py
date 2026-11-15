"""
Conventions shared across the data pipeline.

The one database query in here is made through a function that is passed in or
imported lazily, so importing this module still needs nothing but the standard
library and can be tested without mysql.connector installed.
"""

import datetime
import re

# The exchange the pipeline runs for. Interpolated into SQL below, so it is
# checked rather than trusted, the same way the PHP side checks its grouping.
PIPELINE_EXCHANGE = 'XLON'


def _query(query_fn):
    """The query function to use: the one passed in, or the real one."""
    if query_fn is not None:
        return query_fn
    from functions import query          # imported here, not at module level
    return query


def _as_date_string(value):
    if value is None:
        return None
    if hasattr(value, 'strftime'):
        return value.strftime('%Y-%m-%d')
    return str(value)[:10] or None


def pipeline_last_trading_date(query_fn=None, exchange=PIPELINE_EXCHANGE):
    """
    The last date prices were loaded for, or None.

    This is what the market says about when it was last open. The calendar
    does not know: run on a Sunday, "yesterday" is a Saturday, and a Saturday
    has no prices, no fundamentals and no business being an as-of date.
    """
    if not re.match(r'^[A-Za-z0-9]{1,10}$', str(exchange)):
        return None

    rows = _query(query_fn)(
        "select max(date) from historical_prices where exchange='" + exchange + "'")

    if not rows:
        return None

    return _as_date_string(rows[0][0])


def pipeline_asofdate(query_fn=None, exchange=PIPELINE_EXCHANGE):
    """
    The date the fundamentals pipeline keys on.

    The loader stamps stock_info.asofdate with this and get_api_stats.py reads
    stock_info at exactly this date, so the two must agree or the join finds
    nothing and the whole fundamentals half of the run is a silent no-op. It
    lives here, in one place, because it was two separate expressions in two
    files and they drifted apart.

    The last trading day, which is the same rule and the same source
    get_statistics.php uses, so the PHP and Python halves of the run agree
    whatever day either is started on.
    """
    return pipeline_last_trading_date(query_fn, exchange)


def pipeline_resolve_asofdate(requested=None, query_fn=None,
                              exchange=PIPELINE_EXCHANGE):
    """
    The as-of date for a run, or None if there is not one to use.

    With nothing requested, the last trading day. With a date, that date -
    but only if prices were loaded for it. None means refuse: a job that
    calculates for a day the market was shut writes a half set of figures and
    then tells every reader that that is the newest date there is.
    """
    if requested is None or not str(requested).strip():
        return pipeline_asofdate(query_fn, exchange)

    requested = str(requested).strip()

    # A real date in the one format. strptime accepts 2026-2-3 and rejects
    # 2026-02-30, so the round trip pins the spelling as well as the date.
    try:
        parsed = datetime.datetime.strptime(requested, '%Y-%m-%d')
    except ValueError:
        return None
    if parsed.strftime('%Y-%m-%d') != requested:
        return None

    if not re.match(r'^[A-Za-z0-9]{1,10}$', str(exchange)):
        return None

    rows = _query(query_fn)(
        "select count(*) from historical_prices where exchange='" + exchange
        + "' and date='" + requested + "'")

    if not rows or not rows[0] or int(rows[0][0]) == 0:
        return None

    return requested
