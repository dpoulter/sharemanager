-- Dividends received by a Mac mini backtest run.
--
--   mysql <database> < sql/backtest_dividends.sql
--
-- After sql/backtest_dsr.sql. dividends_received is the cash dividends, in
-- pounds, the engine credited on the run's holdings (gross, on their payment
-- dates). Null on a run from before dividends were credited, and on N100 runs,
-- which credit none. Safe to apply twice.

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'dividends_received') = 0,
               'alter table strategy_backtest_runs add column dividends_received decimal(20,4) null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

select 'dividends column' thing,
       (select count(*) from information_schema.columns
         where table_schema = database() and table_name = 'strategy_backtest_runs'
           and column_name = 'dividends_received') present;
