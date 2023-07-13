# sharemanager
Share Portfolio Manager written using LAMP stack. It uses the Yahoo Finance API's and data scraped from financial websites.




This script, refresh_all.sh, should be scheduled to run periodically to refresh all data. Contents are as follows:


php /var/www/includes/get_share_prices.php

php /var/www/includes/scrape_key_statistics.php

--> Replaced: php /var/www/includes/get_api_stats.php with

python3 get_api_stats.py

php /var/www/includes/get_statistics.php

php /var/www/includes/performance_function.php


get_share_prices.php
----------------------
Calls function get_historical_prices  (/var/www/includes/share_functions.php)

get_historical_prices
-----------------------
Calls World Trade Data API (https://api.marketstack.com/v1/eod?symbols) for each stock symbol


scrape_key_statistics.php
---------------------------
Scrape website morning star  (NO LONGER WORKS)

get_api_stats.php (NO LONGER USED)
------------------
Call get_key_ratios (share_functions.php) for each symbol to get stock statistics (NO LONGER WORKS as requires morning star)

Then for each record in screen_indicators inserts the value into statistics table (uses class Statistics.php)

For every stat you want to collect on a stock you should store the Stat description in screen_indicators table (in description field)

THIS IS DEPRECATED- USE **get_api_stats.py**


New Python function to get all share info using YFinance
----------------------------------------------------------
pyhton/stock_info.sql  - mysql table creation script
python/fetch_stock_info.sql - calls Yfinance ticker api to get stock info for all stock symbols
