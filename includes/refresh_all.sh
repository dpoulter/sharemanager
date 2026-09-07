python3.8 /var/www/shares/python/fetch_stock_info.py
python3.8 /var/www/shares/python/get_api_stats.py
php /var/www/shares/includes/get_share_prices.php
# Incremental by default (most recent as of date per symbol). To rebuild history
# run manually with a date range, e.g.
#   php /var/www/shares/includes/get_momentum_statistics.php 2015-01-01 2025-12-31
php /var/www/shares/includes/get_momentum_statistics.php
php /var/www/shares/includes/get_statistics.php
php /var/www/shares/includes/performance_function.php
