import datetime
import requests_cache
from datetime import timedelta
from functions import query
from share_functions import get_key_ratios, insert_statistic
from requests import Session
from requests_cache import CacheMixin, SQLiteCache
from requests_ratelimiter import LimiterMixin, MemoryQueueBucket
from pyrate_limiter import Duration, RequestRate, Limiter


class CachedLimiterSession(CacheMixin, LimiterMixin, Session):
    pass

session = CachedLimiterSession(
    limiter=Limiter(RequestRate(2, Duration.SECOND*5)),  # max 2 requests per 5 seconds
    bucket_class=MemoryQueueBucket,
    backend=SQLiteCache("yfinance.cache"),
)

# Set exchange
exchange = 'XLON'

# Get current date
as_of_date = datetime.datetime.now() - timedelta(days=1)
print("As of date:", as_of_date.strftime('%Y-%m-%d'))

date = as_of_date.strftime('%Y-%m-%d')




# Get symbols
rows = query("select symbol from stock_symbols where enabled='Y' and exchange='" +exchange+ "'  order by symbol")


for row in rows:

    symbol = row[0]

    print ("Symbol="+symbol)
    
    share_info = get_key_ratios(session,exchange, symbol+".L")
    
    if share_info:
        print(share_info)
        
        indicators = query("select name, description from screen_indicators where enabled='Y' order by order_number")
        
        if indicators:
            for indicator in indicators:
                if indicator[1] in share_info:
                    value = share_info[indicator[1]]
                    if value:
                    
                        insert_statistic(symbol, exchange, indicator[0], date, value)
