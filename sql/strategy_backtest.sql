-- What a backtest did, one row per run.
--
--   mysql <database> < sql/strategy_backtest.sql
--
-- A backtest replays a strategy's own rules over stored history through the
-- same engine the paper account uses - same targets, same exit rules, same
-- costs, same limits - under its own account name, prefixed bt_ (see
-- STRATEGY_BACKTEST_PREFIX in includes/strategy_functions.php). Nothing it
-- does can reach a live paper account: the prefix is reserved, the nightly
-- run skips it, and includes/backtest_strategy.php refuses to write to an
-- account without it.
--
-- The orders, positions and nightly values of a run live in the ordinary
-- strategy_orders, strategy_positions and strategy_values tables under that
-- account name, which is the "same tables with a run id" the brief offered:
-- one engine, one set of tables, and a name that says which run a row
-- belongs to. This table is the index of those runs and the two caveats
-- that have to be read with any of them.
--
-- Re-runnable.

create table if not exists strategy_backtest_runs (
  run_id           int auto_increment primary key,
  strategy         varchar(50) not null,      -- the live strategy whose rules were replayed
  account          varchar(50) not null,      -- the bt_ account the replay traded
  from_date        date not null,
  to_date          date not null,
  started_at       datetime not null,
  finished_at      datetime,
  status           varchar(20) not null,      -- RUNNING, DONE, REFUSED, FAILED
  trading_days     int not null default 0,
  rebalances       int not null default 0,
  exits            int not null default 0,
  fills            int not null default 0,
  start_value      decimal(20,4),
  final_value      decimal(20,4),
  total_return     decimal(14,6),             -- fraction, not percent
  benchmark_return decimal(14,6),             -- the same period, equal weighted
  max_drawdown     decimal(14,6),
  costs_paid       decimal(20,4),
  note             varchar(1000),             -- why it was refused, or what to read it with
  key (strategy, started_at),
  key (account)
);
