# sharemanager
Share Portfolio Manager written using LAMP stack. It uses the Yahoo Finance API's and data scraped from financial websites.



Python modules to install
--------------------------------
sudo apt-get install python3.8-dev
python3.8 -m pip install versioneer
sudo apt-get install numpty
pip3 install yfinance
pip3 install mysql-connector-python
pip3 install requests-cache
pip3 install requests-ratelimiter
pip3 install pyrate-limiter


Other Installation
--------------------------
Apache2:  

sudo apt update
sudo apt-get install apache2
sudo apt install libapache2-mod-php


Edit hosts file: sudo nano /etc/hostst
Add this entry: 127.0.0.1       shares.localhost

cd /etc/apache2/sites-available
sudo cp 000-default.conf shares.localhost.conf

Add lines: 
ServerName shares.localhost
DirectoryIndex index.php index.html index.cgi index.pl index.php index.xhtml index.htm

Change Document Root entry: DocumentRoot /var/www/shares

sudo a2ensite shares.localhost.conf 
sudo service apache2 restart


Initial Setup
-------------
Run in mysql: intial_data.sql
Setup historical data for at least 3 months: php /var/www/includes/get_share_prices.php 2023-04-01 2023-07-16


Refresh_all.sh
------------------------------
This script, refresh_all.sh, should be scheduled to run periodically to refresh all data. Contents are as follows:


python3 /var/www/shares/python/fetch_stock_info.py
python3 /var/www/shares/python/get_api_stats.py
php /var/www/shares/includes/get_share_prices.php
#php /var/www/shares/includes/get_momentum_statistics.php
php /var/www/shares/includes/get_statistics.php
php /var/www/shares/includes/performance_function.php


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




Initial setup for data
-------------------------------
Run initial_data.sql in the database to popoulate data in tables for the first time
