"""
Shared database helpers for the Python data loaders.

Credentials come from the environment, the same SM_DB_* variables the PHP side
reads from the php-fpm pool, so there is one place to change them and nothing
secret lives in a tracked file. Under cron, which does not see the pool, source
them from /etc/sharemanager.env; see includes/refresh_all.sh.

    SM_DB_HOST          default 127.0.0.1
    SM_DB_NAME          default sharemanager
    SM_DB_USER          required
    SM_DB_PASS          required
    SM_DB_AUTH_PLUGIN   optional; only sent when set

query() returns a list of tuples, from a plain cursor. Callers index by
position (row[0]), which every loader here does. It is not changed to return
dictionaries because the existing scripts depend on the tuple shape.
"""

import os

import mysql.connector


class ConfigError(Exception):
    pass


def _required(name):
    value = os.environ.get(name, "").strip()
    if not value:
        raise ConfigError(
            "{} is not set. The loaders read the same SM_DB_* variables as the "
            "php-fpm pool; under cron source them from /etc/sharemanager.env. "
            "There is deliberately no built-in fallback password.".format(name))
    return value


def db_config():
    """Connection settings from the environment.

    A function rather than a module-level dict so that importing this module
    does not require the database to be configured - the tests and the pure
    helpers import it without ever connecting - and so a missing credential
    fails where it is used, with a message naming the variable.
    """
    config = {
        'host':     os.environ.get('SM_DB_HOST', '').strip() or '127.0.0.1',
        'database': os.environ.get('SM_DB_NAME', '').strip() or 'sharemanager',
        'user':     _required('SM_DB_USER'),
        'password': _required('SM_DB_PASS'),
    }

    # Only sent when asked for. Forcing mysql_native_password is what the
    # hard-coded config used to do, and it is rejected by servers that have
    # retired the plugin.
    plugin = os.environ.get('SM_DB_AUTH_PLUGIN', '').strip()
    if plugin:
        config['auth_plugin'] = plugin

    return config

def query(query_string):
    """Run a statement.

    Returns a list of tuples for anything with a result set, and the number of
    affected rows for anything without one, which is committed before
    returning.

    It used to call fetchall() unconditionally. On an UPDATE that raises "No
    result set to fetch from", and the error was caught and turned into None,
    so the statement looked merely unsuccessful while actually never being
    committed: insert_statistic() updates through here, so a re-run could
    never correct a value, and one get_api_stats run printed 1,394 of those
    errors.

    Errors are raised now rather than returned as None. None was
    indistinguishable from "no rows matched", which let a nightly job report
    success having written nothing.
    """
    print ("query string="+query_string)

    connection = mysql.connector.connect(**db_config())
    try:
        cursor = connection.cursor()
        try:
            cursor.execute(query_string)

            # with_rows is false for INSERT, UPDATE and DELETE. Fetching then
            # is the error; committing is what they actually need.
            if cursor.with_rows:
                result = cursor.fetchall()
            else:
                connection.commit()
                result = cursor.rowcount

            print (result)
            return result
        finally:
            cursor.close()
    finally:
        connection.close()


def insert(insert_string):
    """Run an insert and commit it. Raises on error, as query() does."""
    print ("insert string="+insert_string)

    connection = mysql.connector.connect(**db_config())
    try:
        cursor = connection.cursor()
        try:
            cursor.execute(insert_string)
            connection.commit()
            return cursor.rowcount
        finally:
            cursor.close()
    finally:
        connection.close()
