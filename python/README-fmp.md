# Financial Modeling Prep

The second supported provider, alongside EODHD. `SM_DATA_PROVIDER` picks one;
it defaults to `eodhd`, so setting nothing keeps an existing deployment as it
was.

## What the probe established

`python/fmp_probe.py` was run against a Premium plan, and everything below is
from real responses rather than the documentation.

- **The `/api/v3` endpoints are gone.** They answer 403 *"Legacy Endpoint ...
  only available for legacy users"* for accounts opened after the change, for
  US symbols as well as LSE. The `stable` API is the only target and v3 is not
  a fallback. FMP's own docs still lead with v3 examples.
- **LSE is fully covered on Premium.** `VOD.L` returns data on quote, profile,
  key-metrics-ttm and ratios-ttm.
- **Tickers are `CODE.L`**, where EODHD says `CODE.LSE` and
  `stock_symbols.exchange` holds the MIC `XLON`. None are interchangeable.
- **Prices are GBp, market cap is GBP.** `VOD.L` came back at `116.95` with a
  cap of `27,095,677,700`; read as pence that is a 271 million pound company.
  `normalise_market_cap()` puts the cap in the price's unit, and the result is
  stored under `Derived.` rather than overwriting what FMP sent.
- **Shares outstanding is not reported** by any stable endpoint, so it is
  derived from cap over price and stored as `Derived.sharesOutstanding`.
- **The bulk endpoints are Ultimate tier**, not Premium: `profile-bulk`,
  `ratios-ttm-bulk`, `key-metrics-ttm-bulk`, `scores-bulk` and `batch-eod` all
  answer 402. `batch-quote` works.

## Why there is no bulk loader

At 750 calls/minute the per-symbol path is fast enough that the bulk endpoints
would not earn an upgrade:

| refresh | calls at 2000 symbols | time |
|---|---|---|
| fundamentals, 4 calls/symbol | 8,000 | ~11 min |
| price history, 1 call/symbol | 2,000 | ~3 min |
| both | 10,000 | ~13 min |

The loader paces itself to 600 calls/minute, leaving headroom rather than
risking 429s that would cost more than the pacing saves.

## Setup

0. The loaders need the MySQL driver. On Debian and Ubuntu:

   ```sh
   sudo apt install python3-mysql.connector
   ```

   Nothing else: `python/fmp.py` is standard library only.

1. Put the settings in the environment the jobs and the web server run under.
   Nothing reads a committed key, and nothing falls back to a built-in
   database password.

   php-fpm pool, for the pages:

   ```ini
   env[SM_DATA_PROVIDER] = fmp
   env[FMP_API_KEY] = your_key_here
   ```

   Cron sees none of that, so `includes/refresh_all.sh` reads
   `/etc/sharemanager.env`, which must be root-only because it holds the
   database password and the API key:

   ```sh
   sudo install -m 600 /dev/null /etc/sharemanager.env
   sudo editor /etc/sharemanager.env
   ```

   ```
   SM_DB_HOST=127.0.0.1
   SM_DB_NAME=sharemanager
   SM_DB_USER=shares
   SM_DB_PASS=the-database-password
   SM_DATA_PROVIDER=fmp
   FMP_API_KEY=the-fmp-key
   ```

   ```
   0 2 * * * /var/www/sharemanager/includes/refresh_all.sh >> /var/log/sharemanager-refresh.log 2>&1
   ```

2. Apply the mapping and the column widening:

   ```sh
   mysql sharemanager < sql/fmp_migration.sql
   ```

   `stock_info.attribute` goes to varchar(100): FMP's longest field name is 52
   characters and the old varchar(50) silently skipped anything longer.

3. Load a few symbols first and look at what arrived:

   ```sh
   FMP_API_KEY=... python3 python/fetch_fmp_fundamentals.py --limit 20
   mysql sharemanager -e "select distinct attribute from stock_info order by attribute;"
   ```

4. Backfill prices, then rely on the nightly run:

   ```sh
   php includes/get_share_prices.php 2015-01-01 2026-01-01
   ```

## Dependencies

`python/fmp.py` is standard library only. `fetch_fmp_fundamentals.py` needs the
MySQL driver, as the other loaders do, but nothing else - no `requests`, no
`requests_cache`. On Debian and Ubuntu that is the `python3-mysql.connector`
apt package; `pip install mysql-connector-python` works too.

## Database credentials

`python/functions.py` reads `SM_DB_HOST`, `SM_DB_NAME`, `SM_DB_USER`,
`SM_DB_PASS` and optionally `SM_DB_AUTH_PLUGIN` - the same variables the PHP
side takes from the php-fpm pool. A missing user or password is an error
naming the variable; there is deliberately no fallback.

It previously carried a hard-coded host, user and password, and forced
`auth_plugin=mysql_native_password`. **Those credentials are in the git
history and should be treated as exposed**: if `myuser` / `mypassword` was
ever a real login on a real database, change it. The forced auth plugin is
also gone, since a server that has retired the plugin rejects the login.
