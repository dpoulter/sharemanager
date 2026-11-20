-- Backtests on the Mac mini research engine.
--
--   mysql <database> < sql/backtest_mac_mini.sql
--
-- After sql/backtest_queue.sql. Every new run is queued for the Mac mini
-- (engine = 'mac_mini'), claimed through public/backtest_api.php, and its
-- result posted back there. A run with engine null is an earlier N100 run and
-- stays as it is; the page shows it as an N100 run.
--
-- benchmark_return keeps meaning the equal-weight universe; cap_weight_return
-- is the cap-weighted one. fills is the number of trades, costs_paid the costs.
-- report_file is a name the server chose, in BACKTEST_REPORTS_DIR.
--
-- A run still QUEUED when this is applied is handed to the Mac mini: nothing
-- on the N100 replays a queued run any more.
--
-- Safe to apply twice.

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'engine') = 0,
               'alter table strategy_backtest_runs add column engine varchar(10) null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'claimed_at') = 0,
               'alter table strategy_backtest_runs add column claimed_at datetime null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'research_run_id') = 0,
               'alter table strategy_backtest_runs add column research_run_id varchar(64) null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'engine_commit') = 0,
               'alter table strategy_backtest_runs add column engine_commit varchar(40) null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'annual_return') = 0,
               'alter table strategy_backtest_runs add column annual_return decimal(14,6) null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'cap_weight_return') = 0,
               'alter table strategy_backtest_runs add column cap_weight_return decimal(14,6) null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'sharpe') = 0,
               'alter table strategy_backtest_runs add column sharpe decimal(14,6) null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'deflated_sharpe') = 0,
               'alter table strategy_backtest_runs add column deflated_sharpe decimal(14,6) null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'trial_count') = 0,
               'alter table strategy_backtest_runs add column trial_count int null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'report_file') = 0,
               'alter table strategy_backtest_runs add column report_file varchar(255) null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'result_json') = 0,
               'alter table strategy_backtest_runs add column result_json mediumtext null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

update strategy_backtest_runs set engine = 'mac_mini' where status = 'QUEUED' and engine is null;

select 'mac mini columns' thing,
       (select count(*) from information_schema.columns
         where table_schema = database() and table_name = 'strategy_backtest_runs'
           and column_name in ('engine','claimed_at','research_run_id','engine_commit','annual_return','cap_weight_return','sharpe','deflated_sharpe','trial_count','report_file','result_json')) present;
