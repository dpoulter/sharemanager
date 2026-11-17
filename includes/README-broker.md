# The broker connection (Trading 212)

Stage 1 of the Trading 212 brief: **read only**. The application reads the
account and writes down what it said. Nothing in this path can place, amend or
cancel an order, in either environment, and the live key has no
Orders - Execute permission anyway.

## What it is

| File | What it does |
|---|---|
| `includes/t212_client.php` | The only thing that talks to Trading 212. One class, GET only - there is no method on it that posts. Timeouts, the published rate limits kept before the fact, `x-ratelimit-*` read after it, cursor paging, and a typed error for 401, 403, 429 and 5xx. Masks credentials in everything that leaves it. |
| `includes/broker.php` | What the answers mean: the field map, the instrument matching, the pounds-or-pence decision, the storage, and `broker_sync()`. |
| `includes/sync_broker.php` | The nightly job. `php includes/sync_broker.php [--instruments]` |
| `tools/t212_probe.php` | The discovery script. Run it first; see below. |
| `public/broker.php`, `templates/broker.php` | The page. Logged in only, like every other page. |
| `tools/t212_stub.php` | The stand-in the suite talks to. No test calls Trading 212. |
| `sql/broker.sql` | The tables. Safe to apply twice. |

## Configuration

Read from the environment, like the FMP and Anthropic keys: `/etc/sharemanager.env`
for cron, the php-fpm pool for the website. Never committed, never logged.

| Setting | Meaning |
|---|---|
| `SM_T212_ENV` | `off` (default), `live-read` or `demo` |
| `SM_T212_API_KEY`, `SM_T212_API_SECRET` | the live, read-only key |
| `SM_T212_DEMO_API_KEY`, `SM_T212_DEMO_API_SECRET` | the demo key, for stage 2 |
| `SM_T212_PRICE_UNIT` | `auto` (default), `pounds` or `pence` - see below |
| `SM_T212_INSTRUMENT_DAYS` | how often the instrument list is refreshed (7) |
| `SM_T212_PROBE_DIR` | where the probe writes (`/var/lib/sharemanager/probe`) |

With `SM_T212_ENV=off`, or a missing key, every broker function is a no-op
that says so: no socket is opened, no row is written, and nothing else in the
application behaves differently. That is the state of a host nobody has
configured, and the suite checks it.

## The three things the API does not tell us

Version 0 of the API is beta, and its documentation gives no response fields,
no price units and no ticker format for London shares - the only example is
`AAPL_US_EQ`. All three are therefore worked out rather than assumed:

1. **Field names.** Every read goes through `broker_field()`, which takes the
   spellings that are plausible and records a problem when the response
   carries none of them, instead of storing null and looking fine.
2. **The ticker format.** `broker_ticker_code()` strips the parts that are
   certainly not the company's code - a trailing `_EQ`, a `_XX` country
   marker, a single trailing lower-case letter - and matches what is left.
   ISIN wins where both sides have one. A code that fits more than one of our
   shares, or two instruments that fit one share, is left unmatched and shown
   on the page: a wrong match values the wrong company's holding.
3. **Pounds or pence.** `historical_prices` is pence and the strategy engine
   is pounds, so this is a factor of 100 on every figure.
   `broker_price_unit()` decides it by comparing the broker's price for a
   share against our own close for the same share. A position whose unit
   cannot be decided stores no price at all rather than one that might be a
   hundred times out, and the page says why.

Run `php tools/t212_probe.php` to settle all three against real responses. It
makes three GETs, prints the answers, and writes the raw JSON - credentials
masked, mode 600 - to `SM_T212_PROBE_DIR`, outside the web root. Once the
answers are known, the candidate lists in `broker_fields()` can be cut to the
spelling that is real and `SM_T212_PRICE_UNIT` set to the right one. Keep the
guards: a beta API can change them back.

Nothing fills `stock_symbols.isin` yet - no job this application runs has an
ISIN to write - so in practice the mapping is by code until something does.
FMP's company profile carries one, which is the obvious place to get it.

## Deploying it

1. Dale puts the key and secret in `/etc/sharemanager.env` and the php-fpm
   pool, and sets `SM_T212_ENV=live-read`. Nobody else handles them.
2. Back up the database, deploy, then `mysql sharemanager < sql/broker.sql`.
3. `php tools/t212_probe.php` and send the output back before trusting the
   mapping.
4. `php includes/sync_broker.php --instruments`, then check the cash and the
   positions on the broker page against the Trading 212 app.

The nightly run does the sync after the strategies and is allowed to fail
without taking the night with it.

## Stage 2, when stage 1 has been checked

Not built. It is order placement **against the demo environment only**, and
the brief's guards are the design: a demo host that is a constant with no code
path to the live one, an order recorded as `SENT` with its own id before the
call and left `UNKNOWN` rather than resent on a timeout, the same limits and
kill switch as the paper engine, sending only while the market is open, and a
daily reconciliation that halts a strategy on any difference. Live orders are
a separate brief after the paper period and Dale's decision.

The suite already asserts the half of that which can be asserted today:
nothing in `includes/t212_client.php`, `includes/broker.php` or
`includes/sync_broker.php` names any HTTP method but GET, the page opens no
connection of its own, and `live.trading212.com` appears exactly once in the
whole application - where the constant is defined.
