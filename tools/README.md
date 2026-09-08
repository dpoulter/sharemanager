# tools

To run this on a server rather than your laptop, see
[docs/sandbox-on-server.md](../docs/sandbox-on-server.md).

## sandbox.sh

Stands up a throwaway copy of the site with generated data, so you can click
around without touching anything real.

```sh
tools/sandbox.sh                 # build the data, serve on http://127.0.0.1:8080
tools/sandbox.sh --port 9000     # somewhere else
tools/sandbox.sh --rebuild       # regenerate the data first
tools/sandbox.sh --no-serve      # build the data only
```

Log in as **tester / testpass**.

### Setup

Needs PHP with `pdo_mysql`, a `mysql` or `mariadb` client, and a database you can
create tables in. Once:

```sql
create database sharemanager_sandbox;
create user 'smtest'@'%' identified by 'smtest';
grant all on sharemanager_sandbox.* to 'smtest'@'%';
```

### Settings

Defaults are `sharemanager_sandbox` / `smtest` / `smtest` on `127.0.0.1`. If you
created a different user, write the real values once instead of exporting them
in every new shell:

```sh
cp tools/sandbox.env.example tools/sandbox.env
$EDITOR tools/sandbox.env
```

`tools/sandbox.env` is gitignored, because it holds a password. Environment
variables of the same names still override it, which is what the systemd unit in
the deployment guide relies on. The script **refuses to run unless the database name ends in
`_sandbox`**, because building drops and recreates every table.

Your own `includes/constants.php` is never read: the sandbox puts
`tests/fixtures/constants.php` first on the include path, and that file takes its
settings from the environment. So the sandbox cannot reach your real database
even by accident, and no credentials are committed.

### What the data looks like

From `tests/schema.sql` and `tests/seed.php`, the same fixtures the test suite
uses, plus `tools/sandbox_data.sql` for things the tests deliberately leave out.

- 12 symbols on XLON with weekday prices from January 2023 to today, plus one on
  LON that nothing should ever touch.
- A portfolio: several open positions, one closed at a profit, dividends, cash
  movements, and a month of daily valuations.
- Three transactions dated in the current month, because `edit.php` filters by
  month and year and defaults to the current one.
- Four scores per symbol, each ranking the symbols differently so the dashboard
  panels do not all show the same order.
- A saved screen with criteria, a strategy with a backtest result, financial
  statements, and a funded paper trading account.

Prices come from a fixed formula rather than `rand()`, so a rebuild reproduces
the same data and anything you notice stays reproducible.

### Why the scores are not in tests/seed.php

Several tests run `get_statistics.php` and assert on the scores it produces.
Pre-seeding those rows would mask a genuine failure, so they live in
`tools/sandbox_data.sql` and only the sandbox gets them.

### Notes

- `opcache.enable` applies under the `cli-server` SAPI that `php -S` runs, not
  `opcache.enable_cli`. The script disables it, without which your edits are
  served stale and you will chase changes that appear not to take effect.
- `stockgraph.php` and `performance_graph.php` fall back to a small GD renderer
  when the jpgraph library is absent, which it is here, so the price and
  performance charts do render. `chart.php` and `jpgraph.php` still need
  `phpChart_Lite/` and do not.
- No EODHD key is set, so live quotes and price fetches degrade to empty rather
  than erroring. Everything the sandbox shows comes from the generated data.

## eodhd_stub.php

A stand-in for the EODHD API, so the sandbox exercises the real request path
rather than skipping it. Serves the two endpoints the application calls, in the
shapes EODHD documents, built from the seeded price history:

```
GET /real-time/{CODE}.LSE          latest quote
GET /eod/{CODE}.LSE?from=&to=      daily prices
```

`sandbox.sh` starts it automatically on `PORT + 10` and points the application
at it. It has to be its own process: `php -S` serves one request at a time, so
an application server calling a stub inside itself would wait for a response it
cannot produce.

It behaves like the real thing where that matters. A missing `api_token` gets a
401, and an unknown code gets `"NA"` in every field rather than a 404, which is
what the real API does and what `eodhd_quote_to_rows()` has to reject.

Without it, `lookup()` finds no price, `get_share_info()` returns false, and
every quote page says "Invalid Symbol" for a perfectly valid symbol.
