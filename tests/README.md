# Tests

A regression suite for the momentum batch jobs and the queries behind the
dashboard. Plain PHP with no framework or Composer dependency, matching the rest
of the project.

## Running

```sh
tests/run_tests.sh
```

Exits 0 if everything passes, 1 on any failure, 2 if the environment is not
usable (no PHP, no mysql client, cannot connect).

Needs PHP with `pdo_mysql`, a `mysql` or `mariadb` client, and a MySQL/MariaDB
server you can create tables on. Settings come from the environment:

| variable       | default             | notes                          |
| -------------- | ------------------- | ------------------------------ |
| `SM_TEST_DB`   | `sharemanager_test` | **must end in `_test`**        |
| `SM_TEST_HOST` | `127.0.0.1`         |                                |
| `SM_TEST_USER` | `smtest`            |                                |
| `SM_TEST_PASS` | `smtest`            |                                |

One-off setup:

```sql
create database sharemanager_test;
create user 'smtest'@'%' identified by 'smtest';
grant all on sharemanager_test.* to 'smtest'@'%';
```

The runner refuses to start unless the database name ends in `_test`. It drops
and recreates every table on each run, so pointing it at a real database would
destroy data.

Nothing here touches `includes/constants.php`. `tests/fixtures` goes first on the
include path, so every relative `require("constants.php")` in the application
resolves to `tests/fixtures/constants.php`, which reads the variables above. No
credentials are committed.

## Layout

| file                          | purpose                                          |
| ----------------------------- | ------------------------------------------------ |
| `run_tests.sh`                | creates the database, seeds it, runs the suite    |
| `schema.sql`                  | table definitions (**a reconstruction** — see below) |
| `seed.php`                    | deterministic symbols, prices, indicators, user   |
| `tests.php`                   | the assertions                                    |
| `fixtures/constants.php`      | test configuration, read from the environment     |

## The schema is a reconstruction

`schema.sql` was **inferred from the SQL in the PHP source**, not dumped from the
production database. It exists so the jobs and the dashboard can run without a
live database. Column types, lengths, indexes and defaults are guesses and will
differ from production.

Do not use it to create or migrate a real database. Absolute timings from the
suite are not meaningful for production capacity planning, and it will not
surface anything that depends on real index shapes or query plans. What it does
test reliably is behaviour: which rows get written, at which dates, for which
exchange.

Two details were pinned down by making the code actually run, and are worth
knowing if you compare against the real schema:

- `screen_indicators.rank_order` holds an **ORDER BY expression** (`value DESC`),
  not just a direction. `get_statistics.php` interpolates it straight into SQL,
  so a bare `DESC` is a syntax error.
- `jobs` carries two different kinds of row: `log_job()` writes
  `job_name = '<script>'` with the run time, while `get_statistics.php` writes
  `job_name = 'get_statistics_asof'` with the date the statistics are current
  as of. Pages that display statistics must read the as-of marker.

## Seed data

12 symbols on `XLON` — the exchange the loaders actually write — plus one on
`LON` that nothing should ever calculate, which is how the exchange filtering is
tested. Prices are weekday-only from a fixed formula rather than `rand()`, so
runs are byte-identical and assertions can compare exact counts. History runs to
today, because `get_statistics.php` calculates for "yesterday" and the dashboard
only shows data at that as-of date.

Login as `tester` / `testpass` if you want to click around a running site
against the same data.

## What is covered

- **As-of date generation.** The backfill reproduces the exact date sequence the
  original inline loop produced, including PHP's end-of-month overflow. A guard
  test first proves the naive one-shot month arithmetic would differ, so the
  comparison is not vacuous.
- **Incremental vs backfill.** A nightly run does one as-of date per symbol and
  is far cheaper than a backfill; an explicit range writes nothing outside it.
- **Exchange scoping.** Only `XLON` is written, and the `LON` symbol is never
  touched.
- **Idempotency.** Repeated nightly runs, which recalculate the same as-of date
  until the month rolls over, do not duplicate rows.
- **Date argument validation.** Unparseable, out-of-range (`2024-13-45`,
  `2024-02-30`), non-zero-padded and reversed ranges are rejected; a real leap
  day is accepted.
- **The debug logging gate.** `DEBUG_LOG` off keeps a whole backfill to one
  summary row; on, per-row tracing comes back.
- **The jobs table and the dashboard.** The run row and the as-of row are
  distinct, readers resolve the as-of marker, resolving the run row instead
  would miss the scores, and all four top-ten panels return rows at the same
  as-of date.

## Verifying the suite has teeth

Each of these reintroduces a real bug; the suite goes red for each, and the
named tests are the ones that fail.

| change                                                     | result           |
| ---------------------------------------------------------- | ---------------- |
| exchange back to `'LON'`                                    | 4 tests fail     |
| nightly processes all dates (drop the `array_slice`)        | 3 tests fail     |
| as-of marker written as `'get_statistics'` again            | 8 tests fail     |
| `get_overall_topten` back to `INTERVAL 1 DAY`               | 1 test fails     |
| `calc_momentum` tracing back to `write_log`                 | 2 tests fail     |

## Not covered

The site is only exercised through the functions behind the pages, not over
HTTP. If you want to check the rendered dashboard, serve it against the same
database:

```sh
php -S 127.0.0.1:8080 -t public \
    -d include_path=tests/fixtures:includes \
    -d opcache.enable=0
```

`opcache.enable=0` matters: `php -S` runs under the `cli-server` SAPI where
`opcache.enable` applies rather than `opcache.enable_cli`, so without it your
edits are served stale.

Value, quality and overall scores need fundamentals (`pe`, `shareholder_yield`
and similar) that synthetic price data cannot produce. The suite inserts
placeholder scores to exercise those panels, so it proves the panels resolve the
right as-of date — not that the scores themselves are calculated correctly.
