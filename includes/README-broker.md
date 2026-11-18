# The broker connection (Trading 212)

Stage 1 is **read only**: the application reads the account and writes down
what it said. Stage 2 sends the strategy engine's orders to Trading 212's
**practice account**, and to nothing else.

**No live orders, ever.** The order call refuses unless `SM_T212_ENV=demo`,
builds its URL from a constant demo host rather than from the base URL the
reads use, and refuses any host whose name contains the live one. The live key
has no Orders - Execute permission, so the other end would refuse it too. Real
money stays off until Dale decides, after at least three months of paper
results.

## What it is

| File | What it does |
|---|---|
| `includes/t212_client.php` | The only thing that talks to Trading 212. One class, GET only - there is no method on it that posts. Timeouts, the published rate limits kept before the fact, `x-ratelimit-*` read after it, cursor paging, and a typed error for 401, 403, 429 and 5xx. Masks credentials in everything that leaves it. |
| `includes/broker.php` | What the answers mean: the field map, the instrument matching, the pounds-or-pence decision, the storage, and `broker_sync()`. |
| `includes/sync_broker.php` | The nightly job. `php includes/sync_broker.php [--instruments]` |
| `tools/t212_probe.php` | The discovery script. Run it first; see below. |
| `public/broker.php`, `templates/broker.php` | The page. Logged in only, like every other page. |
| `includes/broker_orders.php` | Stage 2: sending, resolving and reconciling. |
| `includes/send_broker_orders.php` | The daytime job that sends. |
| `tools/t212_stub.php` | The stand-in the suite talks to. No test calls Trading 212. |
| `sql/broker.sql` | The tables. Safe to apply twice. |
| `sql/broker_account_fields.sql` | The second migration: the account summary's real fields, the notices column, shortName. |
| `sql/broker_mapping.sql` | The third: one instrument per share, enforced, and the venue and currency columns. |
| `sql/broker_trading.sql` | The fourth: fractional quantities, where an order is, and whether a strategy is halted. |
| `sql/broker_fills.sql` | The fifth: the tax a fill was charged, the cash that moved, and what a holding cost. **Run all five.** |

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
   `sql/broker.sql`, `sql/broker_account_fields.sql`, `sql/broker_mapping.sql`,
   `sql/broker_trading.sql`, `sql/broker_fills.sql`. `broker_mapping.sql`
   empties `broker_instruments`; the next sync rebuilds it, which costs one
   request.
3. `php tools/t212_probe.php` and send the output back if anything in the
   responses has changed.
4. `php includes/sync_broker.php --instruments`, then check the cash and the
   positions on the broker page against the Trading 212 app.
5. The ISINs arrive with the next nightly fundamentals run, or run
   `python3 python/fetch_fmp_fundamentals.py` by hand; the next
   `--instruments` sync then matches on them.

The nightly run does the sync after the strategies and is allowed to fail
without taking the night with it.

## Stage 2: sending orders to the practice account

An account's `mode` is `PAPER` or `T212_DEMO`. The difference is a table, in
`strategy_mode_rules()`:

| | `PAPER` | `T212_DEMO` |
|---|---|---|
| Minimum order | GBP 250 | GBP 10 |
| Quantities | whole shares | fractions, 2 decimal places |
| Commission | GBP 5.95 | none |
| Stamp duty | 0.5% of a buy | 0.5% of a buy |
| Spread allowance | 25bp each way | 25bp when planning; the real fill price when filling |
| Filled by | this database, at the next close | Trading 212, during the session |

The paper figures are deliberately unchanged: its record only means something
if the rules it was built under do not move. The PTM levy starts at GBP 10,000
and cannot apply to an account this size.

The GBP 250 minimum and whole shares were written for an account paying GBP
5.95 a trade. Trading 212 charges nothing and deals from GBP 1, so a GBP 250
account can hold all fifteen names at about GBP 16.67 each - but only if a
quantity can be 0.44 of a share. Quantities round **down**, so an order never
costs more than the target it was sized from; what is left sits idle until the
next rebalance, and an order that rounds to nothing or falls under the minimum
is recorded with its reason rather than vanishing. The 2-point drift band
stays: without it a small account would trade pennies at every rebalance.

## Which account, and what can reach it

Three switches, read independently, so the practice twin keeps running
beside the live account instead of stopping the day it is read:

```
SM_T212_DEMO=on          read and trade the PRACTICE account
SM_T212_LIVE_READ=on     read the LIVE account with the read-only key
SM_T212_LIVE_ORDERS=on   send orders to the LIVE account
```

`SM_T212_LIVE_ORDERS` is off unless it is exactly `on`, takes a key of its
own (`SM_T212_LIVE_ORDERS_API_KEY` and `_SECRET`), and is **not enough on
its own**: a live order also needs the strategy armed from its own page by
a logged-in user and the batch approved. None of that lives in the
environment.

`SM_T212_ENV` is still read for one release and mapped onto the first two.
It can never switch live orders on - it never could, and a variable that
used to mean "read the live account" must not come to mean "trade it". The
sync says in its output when it has been mapped.

Every row in every `broker_*` table carries an `environment` of `demo` or
`live`, because two accounts are read now and a position in one is not a
position in the other. The instrument mapping is per account as well: an
instrument the practice account lists is not evidence about the live one.

**What cannot happen today.** There is no code in this application that
sends an order to the live account. `T212Client::place_market_order()`
refuses any environment but `demo` and uses the demo host constant; the
sending job and `broker_send_orders()` both refuse any account whose mode
is not `T212_DEMO`, checked on the mode rather than on the client - a
client built from the default environment while the practice switch is on
is a demo client, and sending a live account's orders to the practice
account is not a smaller mistake than sending them to the live one.

A `T212_LIVE` account therefore plans its orders with everything else and
they wait. The suite asserts this, and those checks stay when the live
path is built.

**When things happen.** The nightly run plans and sends nothing: a market order
queued overnight fills at whatever the open does, which is not the price
anything was decided at. `includes/send_broker_orders.php` sends, from cron
through the session:

```
0,10,20,30,40,50 8-16 * * 1-5 \
  /var/www/sharemanager/includes/broker_run.sh \
  >> /var/log/sharemanager-broker.log 2>&1
```

Through the wrapper, not `php` directly. Cron sees no login shell, no profile
and no php-fpm pool, so a line that runs the script itself gets none of
`SM_T212_ENV`, the key or the database login - and the sender refuses without
them, which is also what it does when the feature is switched off, so the
failure is silent. `includes/broker_run.sh` sources `/etc/sharemanager.env`
the way `refresh_all.sh` does, syncs, then sends.

The sync in the wrapper is for the Broker page - the cash, the order history
and the dividends - not for the sender, which reads the positions again
itself after writing a fill up.

The sender does three things in order: resolve what is already out there and
re-read the positions if anything was written up, reconcile the two books,
then send what is still PENDING.

**An order's life.** `PENDING` (planned) → `SENT` (gone, with the time written
*before* the call) → `FILLED`, `REJECTED` or `CANCELLED`. The order calls are
not idempotent, so a request whose answer never arrived becomes `UNKNOWN` and
is never sent again blind: the next run matches it against the broker's
pending orders and history by ticker, side and time. One candidate is adopted;
none, after fifteen minutes, means it never arrived; **two means the strategy
halts**, because a wrong guess there doubles a position.

**Halting.** A difference between the engine's positions and the broker's
stops that strategy until a person clears it (`strategy_accounts.halted`). A
disagreement about what is held is a disagreement about what everything is
worth. An order in flight explains a difference, so it is not one, and a
reconciliation against yesterday's read is not attempted at all.

Neither is a fill the broker has not been asked about yet. Run 2 of the first
build resolved three fills, wrote them into this application's book, and
compared that book against a read of the broker's positions taken before the
fills happened: "CMCX: this application holds 46.86, the broker holds 0" was a
true statement about two different moments, and it halted the strategy. So the
sender re-reads the positions after resolving anything, and a share whose fill
is newer than the last read is reported as waiting to be read back rather than
counted as a difference.

**Limits**: the same 20% position, 50 orders and 50% turnover as the paper
engine, the same kill switch file, plus `T212_MAX_RUN_VALUE` (GBP 1,000) as a
cap on what one run may send.

### Answered on the practice account, 17 November 2026

The first nine orders went in and filled, which settled the open questions:

- **fractional quantities are accepted** for London shares, at **2 decimal
  places** (`SM_T212_QUANTITY_DECIMALS`).
- **market orders fill in 10 to 30 seconds** (`tradingMethod` OTC).
- **prices are pence, account figures are pounds.** `currentPrice`,
  `averagePricePaid` and a fill's `price` are pence; everything under
  `walletImpact` - `netValue`, `totalCost`, `currentValue`,
  `unrealizedProfitLoss` - is pounds. `SM_T212_PRICE_UNIT` is `pence` by
  default now, and the comparison against our own closes has become a
  tripwire: a held share that disagrees with the setting is a problem,
  because one of the two is then wrong.
- **stamp duty is charged and itemised**, and it is taken from the broker
  rather than worked out: B&M is Jersey registered and was charged none, and
  a calculated 0.5% would have invented GBP 1.65 of tax on that fill.
- still unknown: **the smallest quantity**, and **whether any FTSE 350 share
  is whole-share only**. Nothing in the data says, so it is learnt - an order
  refused for its quantity sets `broker_instruments.whole_shares` and that
  share is sized in whole shares from then on.

### The shapes they answer with

The order history nests the order inside an envelope with its fill beside it:

```
{"items":[{"order":{"id","ticker","quantity","filledQuantity","status","side",
                    "createdAt","instrument":{"ticker","name","isin","currency"}},
           "fill":{"id","quantity","price","filledAt",
                   "walletImpact":{"netValue","fxRate",
                                   "taxes":[{"name","quantity",...}]}}}]}
```

Positions nest the instrument and the money:

```
[{"instrument":{"ticker","name","isin","currency"},"createdAt","quantity",
  "currentPrice","averagePricePaid",
  "walletImpact":{"totalCost","currentValue","unrealizedProfitLoss"}}]
```

Pending orders are flat, with the instrument nested beside them.

Three things that cost a deploy each:

- **the side is its own field.** The history reports a sale as `side: SELL`
  with a *positive* quantity. Reading the sign - which is how an order is
  sent - would make every sale a purchase.
- **`netValue` is the cash that moved**, taxes and all. The fill price times
  the quantity is the consideration, which is a different number.
- **times carry an offset.** Pending orders and positions give `+02:00`;
  history gives `Z`. Both are parsed; neither is assumed to be UTC.

A position or history row this cannot read records a problem rather than
being skipped, and the sync cross-checks itself: money invested with no
positions stored is a problem, not an OK run. That is what the first stage 2
sync did - it reported OK with nothing stored while the broker held nine
shares.

### The sender waits

- Nothing new goes out while an order sent more than `T212_SETTLE_SECONDS`
  (3 minutes) ago is still unresolved; past `T212_STUCK_SECONDS` (30 minutes)
  the strategy halts. Orders sent seconds ago are settling, which is normal.
- Nothing goes out unless the two books have been compared today, with one
  exception: a strategy that holds nothing and has never had a fill has
  nothing of its own to disagree about. A sync that finished OK counts as a
  read whether or not it found anything - "no rows" is not "nobody looked".
- The sender reconciles for itself rather than trusting the caller to have
  done it first.
- `--dry-run` applies the run cap, so it shows what **one** run would send
  and how many orders are left over. It needs no key.
