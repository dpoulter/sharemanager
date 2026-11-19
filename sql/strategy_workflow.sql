-- The screen to strategy to backtest to practice to live workflow: the two
-- places it needs to remember something.
--
--   mysql <database> < sql/strategy_workflow.sql
--
-- ARCHIVED. strategy_rules.archived_at is set when a strategy is archived
-- (Stage C) and is the only thing that says so. An archived strategy keeps
-- its rules, runs, orders and accounts; the night run skips it. Null means
-- not archived.
--
-- WHAT A RUN TESTED. strategy_backtest_runs.rules_snapshot is the rules a run
-- was given, with the criteria of the screen it used, as JSON; fingerprint is
-- the value includes/rules_fingerprint.php gives for that snapshot. They are
-- what lets the stage say "a completed run has the fingerprint of the current
-- rules": a later edit cannot change what a run says it tested. Both are null
-- on a run that predates them, which is read as "not known to match".
--
-- The stage itself is NOT stored anywhere. It is derived each time from what
-- exists (includes/strategy_stage.php), so it cannot drift.
--
-- Safe to apply twice: each column is added only if it is not there.

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_rules'
                   and column_name = 'archived_at') = 0,
               'alter table strategy_rules add column archived_at datetime null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'rules_snapshot') = 0,
               'alter table strategy_backtest_runs add column rules_snapshot text null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'strategy_backtest_runs'
                   and column_name = 'fingerprint') = 0,
               'alter table strategy_backtest_runs add column fingerprint varchar(64) null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

select 'workflow columns' thing,
       (select count(*) from information_schema.columns
         where table_schema = database()
           and ((table_name = 'strategy_rules' and column_name = 'archived_at')
             or (table_name = 'strategy_backtest_runs'
                 and column_name in ('rules_snapshot','fingerprint')))) present;
