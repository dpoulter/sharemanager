# Fundamentals from EODHD. Needs EODHD_API_KEY in the environment; see
# python/README-eodhd.md. fetch_stock_info.py (yfinance) is kept for now as a
# fallback but is no longer run.
python3.8 /var/www/shares/python/fetch_eodhd_fundamentals.py
python3.8 /var/www/shares/python/get_api_stats.py
php /var/www/shares/includes/get_share_prices.php
# Incremental by default (most recent as of date per symbol). To rebuild history
# run manually with a date range, e.g.
#   php /var/www/shares/includes/get_momentum_statistics.php 2015-01-01 2025-12-31
php /var/www/shares/includes/get_momentum_statistics.php
php /var/www/shares/includes/get_statistics.php
php /var/www/shares/includes/performance_function.php
