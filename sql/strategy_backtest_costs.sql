-- What a backtest run cost to trade.
--
-- strategy_backtest_overrides.sql records what rules a run was told to use.
-- It does not record what the run paid to deal, and that is the other half
-- of what makes two runs different: trend_following has two rows reading
-- "rebalance=monthly, hold-rank=30" with returns of +104.4% and +97.0%,
-- and the only thing between them was the commission the account was paying
-- when each was run.
--
-- Recorded as the settings themselves - commission, stamp duty, the spread
-- allowance and the minimum order - rather than as a total, so a label can
-- say "no commission" against "GBP 5.95 commission" instead of leaving a
-- reader to infer it from the result.
--
-- Safe to apply twice.

set @have := (select count(*) from information_schema.columns
               where table_schema = database()
                 and table_name   = 'strategy_backtest_runs'
                 and column_name  = 'cost_settings');

set @sql := if(@have = 0,
               "alter table strategy_backtest_runs
                  add column cost_settings varchar(255) null after overrides",
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

select run_id, strategy, overrides, cost_settings
  from strategy_backtest_runs order by run_id desc limit 10;
