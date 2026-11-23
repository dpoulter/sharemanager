-- Net payout yield comes from the Mac mini (brief of 23 Nov 2026).
--
--   mysql <database> < sql/net_payout_yield.sql
--
-- (dividends + buybacks - shares issued) over market cap, from the latest
-- annual accounts usable under the 4-month lag (Boudoukh, Michaely,
-- Richardson and Roberts 2007). data_import is its only writer
-- (includes/data_ownership.php); get_api_stats.py skips it by name.
--
-- Ranked like the other value measures (calc_rank and rank_funds copied from
-- price_book_ratio), highest first. rank_negative NORMAL: a company that
-- issues more than it returns has a real, negative yield, and ranks lowest.
-- Value category, enabled so a screen can use it, in_score='N' so the value
-- score is unchanged. shareholder_yield is not touched.
--
-- Re-runnable.

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'net_payout_yield', null, 'Net payout yield', 'Y', 225,
       null, 'Y', coalesce(pb.calc_rank, 'Y'), 'value DESC', 'NORMAL',
       coalesce(pb.rank_funds, 'N'), 'NONE', coalesce(pb.category, 12)
  from (select 1 one) x
  left join screen_indicators pb on pb.name = 'price_book_ratio'
 where not exists (select 1 from screen_indicators where name = 'net_payout_yield');

update screen_indicators n
  left join screen_indicators pb on pb.name = 'price_book_ratio'
   set n.provider_field = null, n.description = 'Net payout yield', n.enabled = 'Y',
       n.screen_function = null, n.calc_rank = coalesce(pb.calc_rank, 'Y'), n.rank_order = 'value DESC',
       n.rank_negative = 'NORMAL', n.rank_funds = coalesce(pb.rank_funds, 'N'),
       n.fx_adjust = 'NONE', n.category = coalesce(pb.category, 12)
 where n.name = 'net_payout_yield';

set @has_in_score = (select count(*) from information_schema.columns
                      where table_schema = database() and table_name = 'screen_indicators'
                        and column_name = 'in_score');
set @sql = if(@has_in_score = 1,
              'update screen_indicators set in_score=''N'' where name=''net_payout_yield''',
              'select 1');
prepare stmt from @sql;
execute stmt;
deallocate prepare stmt;

select name, ifnull(provider_field, '(from the Mac mini)') provider_field, enabled, calc_rank, rank_order,
       rank_negative, rank_funds, category
  from screen_indicators
 where name in ('net_payout_yield', 'shareholder_yield', 'price_book_ratio')
 order by name;
