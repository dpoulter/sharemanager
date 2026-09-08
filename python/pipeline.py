"""
Conventions shared across the data pipeline.

Deliberately free of database and HTTP dependencies so it can be imported and
tested anywhere, including without mysql.connector installed.
"""

import datetime


def pipeline_asofdate():
    """
    The date the fundamentals pipeline keys on.

    The loader stamps stock_info.asofdate with this and get_api_stats.py reads
    stock_info at exactly this date, so the two must agree or the join finds
    nothing and the whole fundamentals half of the run is a silent no-op. It
    lives here, in one place, because it was two separate expressions in two
    files and they drifted apart.

    Yesterday, not today: the run happens overnight against the previous
    session's close, which is also the date get_statistics.php calculates for.
    """
    return (datetime.datetime.now() - datetime.timedelta(days=1)).strftime('%Y-%m-%d')
