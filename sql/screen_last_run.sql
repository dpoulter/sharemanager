-- When a screen was last run.
--
-- The screens list shows one row per screen with how many shares it matches
-- and when it was last run. Nothing recorded the second fact: screens are run
-- on demand rather than by the nightly job, so there was no run history to
-- read. screen_list.php stamps this when it runs one.
--
-- Safe to apply twice: the column is added only if it is not there.

set @have := (select count(*) from information_schema.columns
               where table_schema = database()
                 and table_name   = 'screen'
                 and column_name  = 'last_run');

set @sql := if(@have = 0,
               'alter table screen add column last_run datetime null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

-- What it looks like afterwards.
select id, name, last_run from screen order by id;
