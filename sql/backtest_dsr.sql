-- The DSR probability of a Mac mini backtest run.
--
--   mysql <database> < sql/backtest_dsr.sql
--
-- After sql/backtest_mac_mini.sql. deflated_sharpe_prob is the probability
-- (0 to 1) that the true Sharpe is above zero after the family's trials
-- (Bailey and Lopez de Prado, 2014). Null on a Mac mini run means the family
-- had too few distinct trials for the deflation to mean anything; the page
-- says so in words. Safe to apply twice.

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'deflated_sharpe_prob') = 0,
               'alter table strategy_backtest_runs add column deflated_sharpe_prob decimal(14,6) null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

select 'dsr column' thing,
       (select count(*) from information_schema.columns
         where table_schema = database() and table_name = 'strategy_backtest_runs'
           and column_name = 'deflated_sharpe_prob') present;
