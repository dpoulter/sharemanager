# Paper execution

Runs the screen as if it were trading, against historical prices, so the code
path and the costs can be measured before any money is involved. Nothing here
talks to a broker and nothing supports `mode = 'LIVE'`.

## Running it

```sh
mysql sharemanager < sql/paper_trading.sql

mysql sharemanager -e "insert into strategy_accounts
  (strategy,cash,currency,mode,enabled,created_at)
  values ('momentum_top10',100000,'GBP','PAPER','Y',now());"

php includes/generate_targets.php momentum_top10            # signal -> targets
php includes/run_paper_execution.php momentum_top10 --dry-run   # inspect
php includes/run_paper_execution.php momentum_top10             # execute
```

With no date both default to the current `get_statistics_asof` marker, so
targets line up with the data that produced them rather than with today.

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
- **Kill switch.** `STOP_TRADING` is checked before every order, so dropping the
  file stops a run in progress.
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
- **It does not tell you the strategy works.** `backing_winners.php` tests exit
  rules, not the screen, so the scores have never been validated against
  forward returns. A paper run measures the plumbing and the costs, not the
  edge.
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
