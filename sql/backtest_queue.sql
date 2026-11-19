-- Backtests asked for from the page, and run by a job outside the web server.
--
--   mysql <database> < sql/backtest_queue.sql
--
-- After sql/strategy_backtest.sql and sql/strategy_workflow.sql. A request
-- from the strategy's page is a row in strategy_backtest_runs with status
-- QUEUED; includes/run_backtests.php picks it up, one at a time, and the
-- engine (includes/backtest_strategy.php --run=<id>) fills in the result. The
-- statuses are QUEUED, RUNNING, DONE, FAILED and REFUSED.
--
-- requested_at / requested_by say when and by whom; cost_model is the dealing
-- model the run was asked to use ('t212' by default, or 'paper'). starting_cash
-- is also added here if sql/strategy_backtest_overrides.sql has not been
-- applied, because a queued run carries the cash it was asked for.
--
-- Safe to apply twice: each column is added only if it is not there.

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'requested_at') = 0,
               'alter table strategy_backtest_runs add column requested_at datetime null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'requested_by') = 0,
               'alter table strategy_backtest_runs add column requested_by varchar(50) null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'cost_model') = 0,
               'alter table strategy_backtest_runs add column cost_model varchar(10) null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'starting_cash') = 0,
               'alter table strategy_backtest_runs add column starting_cash decimal(20,4) null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

select 'queue columns' thing,
       (select count(*) from information_schema.columns
         where table_schema = database() and table_name = 'strategy_backtest_runs'
           and column_name in ('requested_at','requested_by','cost_model','starting_cash')) present;
