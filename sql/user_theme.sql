-- Light, dark or the system setting, per user.
--
-- The page follows the system by default, which is what somebody who has set
-- their machine to dark expects. The preference is here rather than in the
-- browser so that it is the same on the phone and the desktop, and so that
-- the page can be served with the right theme already on it - a theme applied
-- by script after the page has painted is a white flash on every load.
--
-- Safe to apply twice.

set @have := (select count(*) from information_schema.columns
               where table_schema = database()
                 and table_name   = 'users'
                 and column_name  = 'theme');

set @sql := if(@have = 0,
               "alter table users add column theme varchar(10) not null default 'system'",
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

-- Anything that is not one of the three is the system setting.
update users set theme = 'system'
 where theme not in ('system', 'light', 'dark') or theme is null;

select id, username, theme from users order by id;
