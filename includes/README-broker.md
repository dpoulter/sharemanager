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
| `sql/broker_account_fields.sql` | The second migration: the account summary's real fields, the notices column, shortName. |
| `sql/broker_mapping.sql` | The third: one instrument per share, enforced, and the venue and currency columns. **Run all three.** |

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

## What the API really answers

Version 0 is beta and its documentation gives no response fields, no price
units and no ticker format for London shares. The first live run (17 Nov
2026) settled two of the three; `tools/t212_probe.php` is what settled them
and is what to run again if anything changes.

**The account summary nests.** This is what it answers:

```
{"id":..., "currency":"GBP", "totalValue":250.00,
 "cash":{"availableToTrade":250.00,"reservedForOrders":0,"inPies":0},
 "investments":{"currentValue":0,"totalCost":0,
                "realizedProfitLoss":0,"unrealizedProfitLoss":0}}
```

Values are pounds. `broker_fields()` holds those paths, dotted, and
`broker_field()` follows them. The first version read top-level keys only,
was handed the `cash` object, made a null of it and called the run a success:
a correct total of GBP250.00 beside a blank cash balance. Every figure the
page shows at the top is now checked by name, and a field that is missing -
or that holds an object - is a problem that says which field and what the
response had instead.

**The ticker keeps a company's old code after a rename, and `shortName`
carries the current one.** Barratt Redrow is `BDEVl_EQ`, Entain is `GVCl_EQ`,
Frasers is `SPDl_EQ`, Vistry is `BVSl_EQ`, LSEG is `LSEl_EQ`, Harbour Energy
is `PMOl_EQ`. Matching on the ticker alone left 32 of the FTSE 350 unmatched.
The rule, in order:

1. the **ISIN**, where both sides have one. All 18,600 of their instruments
   carry one; ours come from FMP (see below);
2. **shortName**, among the sterling instruments only - `GBX` for most London
   lines, `GBP` for a few. A short name is not unique across the world;
3. the **stripped ticker**, for a company that has never been renamed.

London ticker forms are `<code>l_EQ`, `<code>l1_EQ` and `<code>p_EQ`; other
markets are `<code>_US_EQ` and `<code>_CA_EQ`. A share class is `BT/A` there
and `BT-A` here, and the separators are ignored on both sides.

Rules 2 and 3 apply to London lines only. A name and a three-letter code are
not unique across 18,600 instruments: `ASC` is our ASOS and a US company,
`RKT` is Reckitt and Rocket Companies, and matching those on the code gave
twelve shares two instruments each. A line on a foreign form can only be
matched by its ISIN.

The form says which venue, not the currency: the London line for **IHG** is
`IHGl1_EQ` and reports USD.

| Form | Venue |
|---|---|
| `<code>l_EQ`, `<code>l1_EQ`, `<code>p_EQ`, `<code>I_EQ` | London |
| `<code>_US_EQ`, `<code>_CA_EQ`, `<code>d_EQ` (EUR), `<code>s_EQ` (CHF) | foreign |
| anything else | unknown, treated as foreign |

An unknown form is treated as foreign on purpose: a new London suffix then
shows up as an unmatched share, which is visible, rather than as a wrong
match, which is not.

Each instrument makes one claim, by the best rule it can. Where two claim the
same share the better rule wins, then sterling, then the London ticker form;
if they still tie, neither is used and the page lists them with their ISIN
and currency. `SHCp_EQ` beside `CAPCl_EQ` is the case that needs all of it:
both reduce to the code `SHC`, and `CAPCl_EQ` is the one whose shortName says
so. `MNO` is the other: one ISIN on a London and a Canadian line, settled by
the form.

**A refresh replaces the mapping, it does not add to it.** That is what went
wrong after the second deploy: the rows the previous rule had matched stayed
behind as matched, so the table held 362 rows for 350 shares and four
ambiguous rows whose notes named pairs that had been settled a deploy ago. A
mapping is a photograph of somebody else's list. There is also a unique key
on (broker, symbol), so a second instrument for one share cannot be written
at all, and the sync checks for one anyway.

**Pounds or pence is still open.** The account held nothing on the first run,
so there was no price to compare. Instruments report `currencyCode` GBX for
London shares, which points at pence, but a position may differ, so the
comparison guard stays: `broker_price_unit()` decides by comparing the
broker's price for a share against our own close for the same share, and it
settles on the first holding. The decision is per holding, with the
sync-wide vote as the fallback, because a London line quoted in USD must not
decide the unit for the rest or be forced into theirs; a holding in a line
that is not sterling is named as a notice and its price is in that currency,
not pounds. Its value and gain are the broker's own figures, which are in the
account's currency. A position whose unit cannot be decided stores
no price at all rather than one that might be a hundred times out, and the
page says why. `historical_prices` is pence and the strategy engine is
pounds, so this is a factor of 100 on every figure.

`stock_symbols.isin` is filled by `python/fetch_fmp_fundamentals.py` from the
FMP company profile, which makes the match exact rather than clever. Until
that has run once, the mapping is by shortName and ticker. A share whose ISIN
Trading 212 does not list at all cannot be traded there, and the sync says so
as a notice.

## Problems and notices

A **problem** stopped the data being right: a call that failed, a field the
application needed and could not read, a price unit it could not settle on a
share that is held. It sets the run to PARTIAL or FAILED and shows on the
page under "What the last sync could not do".

A **notice** is worth saying and wrong with nothing: instruments that fit
more than one share, a holding whose instrument is not one of ours, a share
whose ISIN the broker does not list. It leaves the run OK and shows under
"Worth knowing".

The two were one list on the first deploy, so six instruments the matcher
could not tell apart made every run a failure.

## Deploying it

1. Dale puts the key and secret in `/etc/sharemanager.env` and the php-fpm
   pool, and sets `SM_T212_ENV=live-read`. Nobody else handles them.
2. Back up the database, deploy, then run the migrations:
   `sql/broker.sql`, `sql/broker_account_fields.sql`, `sql/broker_mapping.sql`.
   The last one empties `broker_instruments`; the next sync rebuilds it,
   which costs one request.
3. `php tools/t212_probe.php` and send the output back if anything in the
   responses has changed.
4. `php includes/sync_broker.php --instruments`, then check the cash and the
   positions on the broker page against the Trading 212 app.
5. The ISINs arrive with the next nightly fundamentals run, or run
   `python3 python/fetch_fmp_fundamentals.py` by hand; the next
   `--instruments` sync then matches on them.

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
