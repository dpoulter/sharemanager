# EODHD migration

Replaces yfinance (fundamentals), marketstack (prices) and the Morningstar
scraping with a single provider.

## Setup

1. Subscribe to a plan that includes **both** fundamentals and end-of-day
   prices for non-US exchanges. LSE tickers are addressed as `CODE.LSE`.

2. Put the key in the environment of whatever runs the jobs. Nothing reads a
   committed key — `includes/constants.php` reads `EODHD_API_KEY` from the
   environment and the Python jobs read the same variable.

   For cron, set it in the crontab rather than a shell profile, which cron does
   not load:

   ```
   EODHD_API_KEY=your_key_here
   0 2 * * * /var/www/shares/includes/refresh_all.sh
   ```

   The web server needs it too, for the live quote on the quote page
   (`SetEnv EODHD_API_KEY ...` in the vhost, or via the fpm pool config).

3. Apply the schema change and the field mapping:

   ```sh
   mysql sharemanager < sql/eodhd_migration.sql
   ```

4. Load fundamentals once and look at what actually came back:

   ```sh
   EODHD_API_KEY=... python3 python/fetch_eodhd_fundamentals.py
   mysql sharemanager -e "select distinct attribute from stock_info order by attribute;"
   ```

   **Correct the mapping against that list.** The `provider_field` values in
   `sql/eodhd_migration.sql` are EODHD's documented names but were never
   confirmed against a live response. Fixing one is an `update`, not a code
   change:

   ```sql
   update screen_indicators set provider_field='<real name>' where name='pe';
   ```

5. Backfill prices for a range before relying on the nightly run:

   ```sh
   php includes/get_share_prices.php 2015-01-01 2026-01-01
   ```

## How the mapping works

`screen_indicators.name` is the application's own key. It is written to
`statistics.indicator` and hard-coded in the scoring SQL in
`get_statistics.php`, so it must never change.

`screen_indicators.provider_field` is the provider's name for the same number.
`fetch_eodhd_fundamentals.py` stores **every** field EODHD returns into
`stock_info` as `Section.Field`, and `get_api_stats.py` joins
`stock_info.attribute` against `provider_field`, falling back to `name` when
`provider_field` is null.

Two consequences worth knowing:

- Adding an indicator needs only a `screen_indicators` row, not a code change,
  because the field is already in `stock_info`.
- Changing provider again is an `update` over `provider_field`. Existing
  `statistics` history stays valid because `name` never moved.

## Not migrated

- **Financial statements.** `financial_statement_items/periods/values` are still
  fed by the old path. EODHD returns these under `Financials` in the same
  fundamentals document, so the data is already being fetched and discarded by
  `flatten_fundamentals`; loading it into those three tables is a separate job.
- **`scrape_key_statistics.php`** (Morningstar scraping) is untouched and can be
  retired once the mapping above is confirmed to cover the same indicators.
- **`fetch_stock_info.py`** (yfinance) is left in place as a fallback but is no
  longer called by `refresh_all.sh`.
- **`share_functions.php:3342`** still calls worldtradingdata, which has been
  defunct for years, with a committed API token. Dead code — worth deleting.

## Two derived indicators

`shareholder_yield` and `price_free_cash_flow_per_share` are not published
directly by any provider and are left with a null `provider_field`, so they keep
falling back to whatever populates them today. Both need the cash flow
statement:

- shareholder yield = (dividends paid + net buybacks) / market cap
- price / FCF per share = free cash flow / shares outstanding

## What is tested

`tests/test_eodhd.py` covers flattening and ticker construction offline, and
`tests/tests.php` covers the quote adapter and the `provider_field` join,
including a guard proving the old name-only join would miss the mapped
indicators. Run both with `tests/run_tests.sh`.

**No test touches the EODHD API.** Nothing here confirms the real field names,
the response shapes, or that your plan covers LSE fundamentals. Step 4 above is
the check that matters.
