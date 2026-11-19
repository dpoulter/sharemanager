# Paper execution

Runs the screen as if it were trading, against historical prices, so the code
path and the costs can be measured before any money is involved. The paper
engine talks to no broker at all; a `T212_DEMO` account sends the same plan to
Trading 212's practice account, and a `T212_LIVE` account is real money and
sends nothing without being armed first - see **Live orders** at the end.

## Running it

```sh
mysql sharemanager < sql/paper_trading.sql      # the engine's tables
mysql sharemanager < sql/strategy_rules.sql     # the strategies and the valuations
mysql sharemanager < sql/strategy_backtest.sql  # the backtest index

php includes/run_strategies.php --dry-run       # what tonight would do
php includes/run_strategies.php                 # every enabled strategy
```

`run_strategies.php` is what `refresh_all.sh` calls, after
`get_statistics.php` because a strategy selects on the statistics and screens
that job writes. For one strategy at a time the two halves are still there:

```sh
php includes/generate_targets.php trending_value              # signal -> targets
php includes/run_paper_execution.php trending_value --dry-run # inspect
php includes/run_paper_execution.php trending_value           # execute
```

With no date all three default to the current `get_statistics_asof` marker or
the last trading day, so targets line up with the data that produced them
rather than with today.

## What a strategy is

A row in `strategy_rules`, not a script. `sql/strategy_rules.sql` defines the
two that run:

| strategy | selects | holdings | rebalance | exits between rebalances |
| --- | --- | --- | --- | --- |
| `trending_value` | the Trending Value screen | 15 | monthly | none |
| `trend_following` | the Trend following (200-day) screen, ranked by `6mnth` | 15 | weekly | `below_ma200_2d` |

Both start with £100,000 of paper money. `momentum_top10`, which only ever
existed to exercise the engine, is disabled and keeps its row.

The fields: `selection` (`screen` with a `screen_id`, or `score` with an
indicator), `rank_by` for when the selection gives more names than
`holdings`, `holdings`, `rebalance` (`monthly`, `weekly` or `daily`, meaning
the first *trading* date of that period), `exit_rules`, `exclude_funds` and
`start_date`. Screens are matched by name in the migration, never by id: ids
differ between databases.

Equal weight is one over `holdings`, not one over the number of names found.
A selection that gives twelve names for a fifteen holding strategy holds
twelve positions and 20% cash - padding the weights would turn a thin signal
into a concentrated bet.

A holding within `STRATEGY_DRIFT_BAND` (two percentage points) of its target
is left alone. Trimming fifteen holdings by a few shares every month is
thirty orders to correct a drift the market will undo by Thursday.

## Exits between rebalances

Checked every night, on every holding. A triggered exit sells the whole
position and the cash stays idle until the next rebalance: the exit says this
holding is wrong, not that another one is right.

- `below_ma200_2d` - closed below its 200 day average on the last two as of
  dates. Two, not one: a single close through a long average is noise, and
  selling on it pays dealing costs to be whipsawed.
- A holding that has stopped trading - taken over, suspended, delisted - is
  sold at the last price it really traded at, flagged in the log and on the
  page. This one is not configurable: a book cannot carry a price nobody
  will ever trade at again. `STRATEGY_STALE_SESSIONS` (five) is how many
  sessions the rest of the market has to trade without it first, so one
  missing day is a data gap rather than a delisting.

## The page

`public/strategies.php`: a card per strategy with its value, return,
benchmark over the same period, worst fall, cash, holdings, rebalance dates
and dealing costs paid, then its holdings, its trades with the reason for
each, and any backtests. The chart draws every strategy and the benchmark
rebased to 100 at its own start.

"Dry run tonight" calls `strategy_run_night($strategy, $as_of, true)`, which
computes a plan and writes nothing: no targets, no orders, no cash movement.

The 2023 "Backing Winners" page is in `archive/public/strategies.php`.

## The benchmark

`strategy_values` under `_benchmark`: the FTSE 350 equal weighted, compounded
one trading day at a time from the constituents' own prices and rebased to
100. There is no index level anywhere in the data, so the alternative was
having nothing to compare against. Two things it is not, both on the page: it
is today's constituents, so it carries the same survivorship flattery a
backtest does, and it is a price index rather than a total return one - the
same treatment the strategies get, so the comparison is like for like.

## Backtesting

```sh
php includes/backfill_trend_statistics.php 2015-01-02 2026-11-16   # once
php includes/backtest_strategy.php trend_following 2016-01-04 2026-11-14
```

`backfill_trend_statistics.php` writes the indicators that prices alone
decide - `close_price`, `ma50`, `ma200`, `3mnth`, `6mnth`, `12mnth` - over a
range of dates, from the prices up to each date. That list is the line, not
a convenience: a close, a moving average and a return over N months are
facts about a price series, so working one out for an old date invents
nothing, while a value or quality score is a statement about figures that
had not been published yet. It uses the same definitions and the same
windows as `get_statistics.php` and `get_momentum_statistics.php`, and the
suite checks its `6mnth` against the momentum job's own value to the last
decimal place - a backtest ranking on a slightly different number from the
live strategy is measuring a different strategy.

It is needed because `get_momentum_statistics.php` only ever wrote the
momentum indicators for its own monthly as of dates. Without it a weekly
strategy ranking on `6mnth` would find no value on almost every rebalance
date in history, select nothing, and draw a flat line that looked like a
result.

`backtest_strategy.php` replays a strategy's own rules through the same
engine, under its own account named `bt_<strategy>_<run_id>`. That prefix is
reserved: the nightly run skips it, the page skips it, and the script refuses
to write to an account without it. The runs are indexed in
`strategy_backtest_runs` and the nightly values are in `strategy_values`
under the backtest's name, which is what the page draws.

**Look-ahead** is refused rather than worked around. Each date uses only the
statistics stored for that date, and a strategy whose indicators do not go
back that far is refused with the indicator named - so Trending Value cannot
be backtested before the November 2026 fundamentals, full stop. The close and
its moving averages are the one exception that can be manufactured honestly,
because they are computable from prices alone: that is what
`backfill_trend_statistics.php` is for, and it writes each date from the
prices up to that date, with a full window or no row.

**Survivorship** cannot be fixed from this data and is stated with every
result: the universe is today's FTSE 350, so the companies that left the
index are not in it. Every backtest here is flattered by that.

## The split

`generate_targets.php` reads `statistics` and writes `strategy_targets`. It
never reads positions, cash or orders, so a bug in the signal cannot place a
trade; the worst it can do is write a target the executor refuses.

`run_paper_execution.php` never looks at scores. It resumes, reconciles, plans,
then places. Keeping these apart is what lets the executor be exercised against
a real signal without the signal being able to reach the account.

## Order of operations, and why

1. **Resume** anything left `PENDING` by a previous run.
2. **Reconcile** holdings, cash and order state.
3. **Plan** orders from targets.
4. **Place and fill**, re-checking the kill switch before each one.

Resume comes before reconcile deliberately. A `PENDING` order is a known,
recoverable state, but reconciliation treats it as a discrepancy and refuses to
trade. With resume after the gate, a crashed run could never recover on its own.

## Safety properties

- **One order per symbol per rebalance.** `client_order_id` is
  `strategy-date-SYMBOL` — neither quantity nor side. Re-running values the
  account after dealing costs, so targets come out a share or two lower and the
  correction is a SELL; with side in the id that is a different key and the
  re-run quietly trades again. Keyed on the symbol alone, a re-run does nothing.
- **Recorded before placed.** The order row is written `PENDING` before the
  broker is called, so a crash leaves a resumable record rather than a position
  the database does not know about.
- **Fills happen after the as-of date.** An order decided on a close cannot
  transact at that same close. If there is no later session the order is
  rejected, which is what stops a same-day rebalance filling against itself.
- **Fail closed.** An account that cannot be fully priced is not traded. A
  reconciliation problem stops the run. A breached limit aborts the whole
  rebalance rather than trimming it: half a rebalance is a different portfolio
  from the one the signal asked for.
- **Kill switch.** `/var/lib/sharemanager/STOP_TRADING` is checked before every
  order, so dropping the file stops a run in progress. It stops the nightly
  run, the command line and the page's dry run alike, and the page shows a
  banner while it is there. `SM_KILL_SWITCH_FILE` overrides the path, which
  has to be inside php-fpm's `open_basedir` or the page cannot read it - the
  old default, `/var/www/shares/STOP_TRADING`, was outside it and did not
  exist, so the switch could not be operated at all.
- **Long only.** Selling more than is held is rejected, never shorted.

## Limits

Constants in `strategy_functions.php`, not database configuration, so a bad row
cannot widen them and a change shows up in a diff.

| limit | default | |
| --- | --- | --- |
| `STRATEGY_MAX_POSITION_WEIGHT` | 0.20 | no holding over 20% |
| `STRATEGY_MAX_ORDERS_PER_RUN` | 50 | |
| `STRATEGY_MAX_TURNOVER` | 0.50 | `min(buys, sells)` / account value |
| `STRATEGY_MIN_ORDER_VALUE` | 250.00 | below this the costs exceed the benefit |

Turnover is `min(buys, sells)`, the standard definition. It measures how much of
the book is being swapped, not how much is being moved, so deploying idle cash
scores zero — otherwise the first rebalance of any new account would breach
every sane limit.

## Units

**`historical_prices` holds London prices in pence; the engine works in
pounds.** `strategy_price_asof()` and `strategy_next_price()` convert, and
everything downstream - sizing, fills, average cost, valuations, the page,
the backtest - is in pounds because it reads them. `fill_price` and
`avg_price` are pounds in the database.

This was wrong for the first two days of the engine's life: it read 690p as
£690, so a £100,000 account invested about £1,000, the £5.95 commission was a
tenth of every order and the £250 minimum order was really £25,000.

`stock_symbols.quote_ccy` (sql/strategy_prices.sql) says what each share is
quoted in, written by the FMP loader from the profile's `currency`. Null means
pence on XLON, which is what the exchange quotes in. The case matters: the
provider writes pence as `GBp` and pounds as `GBP`, the same three letters a
hundred times apart. A minor unit (`GBp`, `ZAc`, `ILA`) is divided by 100 and
then converted; a major foreign unit (`USD`, `EUR`) is converted and **not**
divided. A foreign quote with no stored rate is unpriceable, and the engine
refuses to trade a share it cannot price.

## Costs

Stamp duty 0.5% on purchases, £5.95 commission per order, 25bps slippage each
way. Position sizing reserves these up front: sizing to a full 100% of the
account leaves nothing for costs, so the last order of every rebalance would be
rejected for want of cash and the book would quietly run one name short.

**AIM stocks are exempt from stamp duty and this does not model that**, so costs
are overstated for an AIM-heavy book. Erring expensive is the right direction.

## What this does not tell you

- **Fills use the adjusted close**, which is restated by later dividends and
  splits. A real broker fills at an unadjusted traded price, and at a price set
  by the order book rather than by yesterday's close. Fills here are
  approximate and, on illiquid names, optimistic.
- **Market impact is not modelled at all.** A fixed 25bps slippage is a poor
  proxy for a real spread on an AIM small cap.
- **It does not tell you the strategy works.** A paper run measures the
  plumbing and the costs over the weeks it has been running, not the edge.
  `backtest_strategy.php` measures the rules over history, and what it
  measures is flattered by survivorship and limited by how far back the
  indicators go. Neither is a forward test.
- **Fundamentals in `stock_info` carry the load date, not the reporting date**,
  so any backtest using ratios has look-ahead bias. Fixing that is a
  prerequisite for believing any result involving value or quality scores.

## Before this goes anywhere near a broker

The reconciliation here compares the ledger against itself, because the paper
broker and the ledger are the same database. Against a real broker it must
compare against *the broker*, which is the only source of truth for positions
and cash, and a `PENDING` order must be resolved by querying the broker by
`client_order_id` rather than assumed unfilled — hence the `mode` check in
`strategy_resume_pending()`.

## Live orders

Dale decided on 18 November 2026 to run trend following with the £250 in his
Trading 212 Invest account. A `T212_LIVE` account plans like any other and
**no order goes out unless that account has been armed**, which is a decision
he makes himself on the strategy's page. Jarvis deploys, tests on practice and
reports; Jarvis does not arm and does not approve.

```sh
mysql sharemanager < sql/strategy_live.sql   # arming, batches, the log
php includes/live_check.php                  # what each live account could do, reads only
php includes/send_live_orders.php --dry-run  # what would go out, sends nothing
```

Six things have to be true before a live order can be sent, and
`broker_live_conditions()` in `includes/broker_live.php` is where they are
checked - from inside the order function, not by its caller:

1. the account's mode is `T212_LIVE`;
2. `SM_T212_LIVE_ORDERS=on` with its own key, which has Orders - Execute;
3. the account is armed: a live row in `strategy_live_arming`;
4. the order's batch has been approved, unless approval is switched off for
   that account;
5. every cap holds;
6. the kill switch is off and the strategy is not halted.

**Arming** is a form on the strategy's page: the app password typed again and
the strategy's display name typed in full, with a cap in pounds and an expiry
(35 days by default). `broker_live_arm()` refuses the command line outright, so
nothing a deploy or a cron job runs can arm an account, and the suite checks
that no other file in the tree writes an armed row. Disarming is one click and
needs no password - off is the safe direction - and a halt disarms.

**Approval**: a live account's orders are planned into one batch a night, which
starts unapproved. The Orders tab shows the batch with each order's estimated
value and stamp duty and the totals, and approving needs the password. An
unapproved batch is recorded as expired at the next night's run and its orders
cancelled, and so is an approved one that was not sent in its own session: the
next run plans afresh. Per account, approval can be switched off ("send
without asking"), with the password, and who did it is recorded.

**The caps** are literals in `constants.php` rather than settings, because a
cap an environment variable can widen is not a cap: £500 the most arming may
state, 15% of the armed cap in one order, £150 in one run, the cap again as a
day's buys, and 30 orders a day. The strategy's cash is its own ledger - cash
in the Trading 212 account does not raise what the strategy may put at risk.

**Only its own positions**: the account is Dale's and holds shares he bought
himself, so a live strategy reconciles what it bought. Extra holdings at the
broker are reported and not a difference; less of a share than the strategy
holds is a difference and halts it. It never sells more of a share than it
bought, and never one it did not buy. Two live strategies cannot be armed on
the same account.

**The log**: `strategy_live_log` holds every arm, disarm, approval, rejection,
expiry and refusal. The application only inserts into it; there is no code path
that deletes or rewrites a row, and the suite checks that there is none.

**Sending**: `includes/send_live_orders.php`, from cron through
`includes/broker_run.sh` every ten minutes through the session, beside the
practice sender and never instead of it. It resolves what is already out
there, compares this strategy's own positions with the broker's, and sends
what is approved and inside every cap - one order at a time, marked SENT
before the call, because the order calls are not idempotent. The one function
that posts to the live account is `broker_live_place_order()` in
`includes/broker_live_send.php`; it builds its URL from the live constant,
refuses the practice host whatever anything is set to, and asks
`broker_live_conditions()` before it opens a socket. `T212Client` has no
write path for the live account at all.

Two senders, two keys, two hosts: `send_broker_orders.php` can only reach the
practice account and `send_live_orders.php` can only reach the live one.
Neither can be turned into the other by an argument, and the suite checks
that the live host is used for a write in exactly one function.

### A key that cannot read orders

Sending is not the same permission as finding out what became of it. On 19
November 2026 thirteen live orders filled and were never recorded, because the
key that reads the order book answered 403 to the pending orders and to the
order history, and nothing said so. The orders stayed SENT and the strategy
halted "without being resolved", which reads as a slow broker.

```sh
mysql sharemanager < sql/broker_key_scopes.sql   # the blind flag (the file keeps its old name)
```

- `broker_resolve_orders()` marks the book **blind** when either call is
  refused with 401 or 403, names the missing permission and leaves the orders
  SENT. It never marks an order filled, refused or missing from a book it could
  not read, and writes no holding from the positions alone. The flag is kept in
  `broker_order_book_state` and renewed on every read, so a fixed key clears it.
- `stuck_check()` in `includes/broker_orders.php` is the one stuck-order rule,
  called by both senders. A blind book changes only the words of the halt: the
  key cannot read orders, the orders may have filled, and the broker is to be
  checked before the halt is cleared.
- `refusal_reason()` in `includes/t212_client.php` is the one reader of a
  refusal body, called by both order functions. It uses the broker's code,
  message, title and detail, and never records "no reason" for a body that had
  words in it.

None of this sends, amends or cancels anything, and none of it clears a halt.

A buy that would breach a cap is refused with the reason and left PENDING for
the next run. Two orders in one run over a cap on their own size halts the
strategy: that is the sizing being wrong rather than the cap being tight.
Reaching the £150 a run or the day's allowance just ends the run.
