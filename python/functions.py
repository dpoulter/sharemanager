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
    """Run a select. Returns a list of tuples, or None if the query failed.

    None rather than an exception is long-standing behaviour that other
    scripts rely on, so callers must check before iterating.
    """
    try:

        print ("query string="+query_string)

        # Connect to the database
        connection = mysql.connector.connect(**db_config())
        cursor = connection.cursor()

        # Execute the query
        cursor.execute(query_string)
        result = cursor.fetchall()

        # Close the cursor and connection
        cursor.close()
        connection.close()

        print (result)

        return result

    except mysql.connector.Error as error:
        print("Error while connecting to MySQL:", error)

def insert(insert_string):
    try:

        print ("insert string="+insert_string)

         # Connect to the database
        connection = mysql.connector.connect(**db_config())

        # Create a cursor
        cursor = connection.cursor()
        cursor.execute(insert_string)
        connection.commit()

         # Close the cursor and connection
        cursor.close()
        connection.close()

    except mysql.connector.Error as error:
        print("Error while connecting to MySQL:", error)
