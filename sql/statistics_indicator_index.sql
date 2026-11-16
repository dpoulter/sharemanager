-- An index for "every share, one indicator, one date".
--
-- statistics had one index, (symbol, indicator, date, exchange), which is the
-- right one for "everything about this share". It is the wrong one for the
-- other question the application asks constantly: the dashboard's breadth
-- figures, the ranked lists, the screens and the strategy engine all want one
-- indicator across the whole universe on one date, and with symbol leading the
-- key none of them can use it.
--
-- That was survivable while statistics held a month. backfill_trend_statistics
-- added 2.3 million rows of history, and the home page went to 9.4 seconds.
--
-- algorithm=inplace, lock=none so it can be added to a live table without
-- taking writes offline; on a table this size it takes a few minutes.
--
-- Safe to apply twice: the index is added only if it is not already there.

set @have := (select count(*) from information_schema.statistics
               where table_schema = database()
                 and table_name   = 'statistics'
                 and index_name   = 'statistics_indicator_date');

set @sql := if(@have = 0,
               'alter table statistics
                  add index statistics_indicator_date (indicator, date, exchange),
                  algorithm=inplace, lock=none',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

-- What it looks like afterwards.
select index_name, seq_in_index, column_name
  from information_schema.statistics
 where table_schema = database() and table_name = 'statistics'
 order by index_name, seq_in_index;
