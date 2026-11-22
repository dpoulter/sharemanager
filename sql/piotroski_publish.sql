-- The Piotroski F-score and book-to-market come from the Mac mini (brief of
-- 22 Nov 2026, option 2).
--
--   mysql <database> < sql/piotroski_publish.sql
--
-- piotroski_score (indicator 15 on the N100) was FMP's ready-made figure as
-- of each night: not the paper's score, and with no history a backtest could
-- use. The Mac mini now works out the paper's score (Piotroski 2000, annual
-- accounts with the 4-month publication lag) for every company, with its
-- whole history, and data_import is the only writer of it in statistics
-- (includes/data_ownership.php). So FMP's field is unmapped here: with no
-- provider_field, get_api_stats.py writes no piotroski_score row (it skips
-- every Mac mini statistic by name too), and fetch_fmp_fundamentals.py asks
-- FMP for no field on its behalf.
--
-- It stays in the quality score as now (category 10, in_score as it was).
-- Its values shift: the paper's score reads annual accounts with the lag,
-- FMP's the latest figures. Dale accepted this.
--
-- book_to_market is new on the N100, published by the Mac mini alongside it:
-- book equity over market cap, negative book left out (Fama and French 1992).
-- Ranked like the other value measures (calc_rank, rank_negative, rank_funds
-- and category copied from price_book_ratio), but highest first: a high
-- book-to-market is cheap. Enabled, so a screen can use it. in_score='N':
-- adding it to the value score would change every share's value_score, which
-- nobody has decided; it is there to screen on.
--
-- Re-runnable.

update screen_indicators
   set provider_field = null
 where name = 'piotroski_score';

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'book_to_market', null, 'Book to market', 'Y', 224,
       null, 'Y', coalesce(pb.calc_rank, 'Y'), 'value DESC', coalesce(pb.rank_negative, 'EXCLUDE'),
       coalesce(pb.rank_funds, 'N'), 'NONE', coalesce(pb.category, 12)
  from (select 1 one) x
  left join screen_indicators pb on pb.name = 'price_book_ratio'
 where not exists (select 1 from screen_indicators where name = 'book_to_market');

update screen_indicators b
  left join screen_indicators pb on pb.name = 'price_book_ratio'
   set b.provider_field = null, b.description = 'Book to market', b.enabled = 'Y',
       b.screen_function = null, b.calc_rank = coalesce(pb.calc_rank, 'Y'), b.rank_order = 'value DESC',
       b.rank_negative = coalesce(pb.rank_negative, 'EXCLUDE'), b.rank_funds = coalesce(pb.rank_funds, 'N'),
       b.fx_adjust = 'NONE', b.category = coalesce(pb.category, 12)
 where b.name = 'book_to_market';

-- Kept out of the value score (see above). in_score arrives with
-- sql/quote_report.sql; where it is not there yet, the scores read every
-- ranked indicator in the category and this column is set when it arrives.
set @has_in_score = (select count(*) from information_schema.columns
                      where table_schema = database() and table_name = 'screen_indicators'
                        and column_name = 'in_score');
set @sql = if(@has_in_score = 1,
              'update screen_indicators set in_score=''N'' where name=''book_to_market''',
              'select 1');
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

select name, ifnull(provider_field, '(from the Mac mini)') provider_field, enabled, calc_rank, rank_order,
       rank_negative, category
  from screen_indicators
 where name in ('piotroski_score', 'book_to_market', 'price_book_ratio')
 order by name;
