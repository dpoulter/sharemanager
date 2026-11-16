-- What to call a strategy on a page.
--
-- The code name is what the tables are keyed on and what the scripts take on
-- the command line. It is not what a reader should be shown: "trend_following"
-- is a column name, and the page was printing it as a heading.
--
-- Safe to apply twice: the column is added only if it is not there, and the
-- names are set only where nothing has been set already, so a name edited by
-- hand survives a re-run.

set @have := (select count(*) from information_schema.columns
               where table_schema = database()
                 and table_name   = 'strategy_accounts'
                 and column_name  = 'display_name');

set @sql := if(@have = 0,
               'alter table strategy_accounts add column display_name varchar(100) null',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

update strategy_accounts set display_name = 'Trending Value'
 where strategy = 'trending_value' and (display_name is null or display_name = '');

update strategy_accounts set display_name = 'Trend following'
 where strategy = 'trend_following' and (display_name is null or display_name = '');

update strategy_accounts set display_name = 'Momentum top ten'
 where strategy = 'momentum_top10' and (display_name is null or display_name = '');

-- Anything else gets its code name tidied up rather than left blank: the
-- underscores out, the first letter up. A name somebody has chosen is left
-- alone.
update strategy_accounts
   set display_name = concat(upper(substring(replace(strategy, '_', ' '), 1, 1)),
                             substring(replace(strategy, '_', ' '), 2))
 where (display_name is null or display_name = '')
   and strategy not like '\_%';

-- What it looks like afterwards.
select strategy, display_name, enabled from strategy_accounts order by strategy;
