-- What a backtest run was told to do differently.
--
-- A run can be given --rebalance, --hold-rank or --holdings, which override
-- the strategy's stored rules for that run only. Without recording them, a
-- table of four runs of the same strategy is four identical labels and four
-- different numbers.
--
-- turnover_refusals is the other thing the first run could not report: it
-- printed the first ten problems and said how many more there were, so "10 or
-- more weekly rebalances refused" was as precise as it got. A rebalance the
-- turnover cap refuses is a rebalance that did not happen, which is a fact
-- about the result rather than a problem with the run.
--
-- Safe to apply twice.

set @have := (select count(*) from information_schema.columns
               where table_schema = database()
                 and table_name   = 'strategy_backtest_runs'
                 and column_name  = 'overrides');

set @sql := if(@have = 0,
               "alter table strategy_backtest_runs
                  add column overrides varchar(255) null after to_date,
                  add column turnover_refusals int not null default 0 after fills,
                  add column starting_cash decimal(20,4) null after start_value",
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

select run_id, strategy, overrides, turnover_refusals, starting_cash
  from strategy_backtest_runs order by run_id desc limit 10;
