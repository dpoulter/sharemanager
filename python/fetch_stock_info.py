import yfinance as yf
import mysql.connector
from datetime import date
import datetime
import requests_cache
from datetime import timedelta
from functions import query
from requests import Session
from requests_cache import CacheMixin, SQLiteCache
from requests_ratelimiter import LimiterMixin, MemoryQueueBucket
from pyrate_limiter import Duration, RequestRate, Limiter

# Database configuration
db_config = {
    'host': 'localhost',
    'user': 'myuser',
    'password': 'mypassword',
    'database': 'sharemanager',
    'auth_plugin': 'mysql_native_password'
}

class CachedLimiterSession(CacheMixin, LimiterMixin, Session):
    pass

session = CachedLimiterSession(
    limiter=Limiter(RequestRate(2, Duration.SECOND*5)),  # max 2 requests per 5 seconds
    bucket_class=MemoryQueueBucket,
    backend=SQLiteCache("yfinance.cache"),
)

def convert_to_string(value):
    if isinstance(value, list):
        return ', '.join(map(str, value))
    return str(value)

def fetch_stock_info(symbol, asofdate, session):
    try:
        # Fetch stock data using yfinance
        stock = yf.Ticker(symbol, session=session)

        # Get stock info
        return  stock.info

    except Exception as e:
        print(f"An error occurred: {str(e)}")

# Set exchange
exchange = 'XLON'

# Get current date
as_of_date = datetime.datetime.now() - timedelta(days=1)
print("As of date:", as_of_date.strftime('%Y-%m-%d'))

asofdate = as_of_date.strftime('%Y-%m-%d')




# Get symbols
rows = query("select symbol from stock_symbols where enabled='Y' and exchange='" +exchange+ "'  order by symbol")


for row in rows:
    try:
        symbol = row[0]
        print ("Symbol="+symbol)
        
        # Get stock info
        info = fetch_stock_info(symbol+".L",asofdate,session)
    
        # Connect to the database
        connection = mysql.connector.connect(**db_config)
        cursor = connection.cursor()

        # Insert the stock info into the stock_info table
        insert_query = """
        INSERT INTO stock_info (symbol, asofdate, attribute, value)
        VALUES (%s, %s, %s, %s)
        """

        data = []
        for key, value in info.items():
            if isinstance(value, list) or isinstance(value, dict):
                value = convert_to_string(value)
            data.append((symbol, asofdate, key, value))

        cursor.executemany(insert_query, data)

        # Commit the changes and close the connection
        connection.commit()
        connection.close()

    except Exception as e:
        print(f"An error occurred: {str(e)}")