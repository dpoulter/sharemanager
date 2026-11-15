-- Shareholder yield, and a value score built from what actually exists.
--
--   mysql <database> < sql/shareholder_yield.sql
--
-- calc_shareholder_yield() adds buyback_yield and div_yield together out of
-- statistics. Neither was mapped to anything, so nothing ever wrote them, so
-- shareholder_yield was a constant zero for every share - and it was in the
-- value score's hard-coded list, where a row with no percentile counts as the
-- neutral 50. It diluted every share's value score equally and told nobody
-- anything.
--
-- The same list named enterprise_value_to_ebitda and
-- price_free_cash_flow_per_share, which are not rows in screen_indicators at
-- all, so the value score was in fact P/E, price/sales and price/book and a
-- constant.
--
-- This file registers the two components and the total, and puts the value
-- score's membership in the database rather than in the query: category 12
-- with calc_rank='Y', the same shape the quality score already uses with
-- category 10.
--
-- Re-runnable: inserts are guarded by "where not exists" and every update
-- sets the same values again.

-- ---------------------------------------------------------------------------
-- The category the value score is built from.
-- ---------------------------------------------------------------------------

insert into indicator_category (category_id, name, description, `order`)
select 12, 'Value', 'Value', 1
 where not exists (select 1 from indicator_category where category_id = 12);

-- The three price ratios that were in the hard-coded list and are still the
-- backbone of the score. Matched by name: the ids differ between databases.
update screen_indicators set category=12
 where name in ('pe','price_sales_ratio','price_book_ratio');

-- ---------------------------------------------------------------------------
-- The two components.
-- ---------------------------------------------------------------------------

-- Neither is ranked on its own: a company that pays a dividend and one that
-- buys back stock are doing the same thing by different means, and ranking
-- the halves separately would count the choice rather than the amount. They
-- are summed into shareholder_yield, which is what gets a percentile.
--
-- Only one of the two needs converting, and it is not the obvious one.
--
-- div_yield comes from FMP's own ratios-ttm, where the dividend and the price
-- are already in the same currency: on the live run for 2026-11-13 SHEL came
-- back at 3.11%, which is $1.43 against a price near $49 and matches its
-- published 2.9-3.1%. Dividing that by GBPUSD made it 2.35%, and BGEO's
-- 2.31% became 0.68%. So fx_adjust='NONE': the figure arrives right and the
-- correction was the error. (LGEN, a sterling reporter, was 6.96% either way,
-- which is why this did not show up until a non-sterling one was checked.)
--
-- buyback_yield is this application's own arithmetic, and it does mix: the
-- repurchases come from the cash flow statement in the reporting currency and
-- the market capitalisation is sterling. So it keeps fx_adjust='DIVIDE' - too
-- large by the rate, the opposite way round from a P/E. Live SHEL comes out
-- at 4.87%, which is about $14bn a year against about $284bn.
-- See sql/report_currency.sql.
--
-- Values are fractions, not percentages: FMP's dividendYieldTTM comes back as
-- 0.0339 for 3.39%, and Derived.buybackYieldTTM is computed to match.

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'div_yield', 'Ratios.dividendYieldTTM', 'Dividend yield', 'Y', 210,
       null, 'Y', 'N', 'value DESC', 'NORMAL', 'N', 'NONE', 12
 where not exists (select 1 from screen_indicators where name = 'div_yield');

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'buyback_yield', 'Derived.buybackYieldTTM', 'Buyback yield', 'Y', 211,
       null, 'Y', 'N', 'value DESC', 'NORMAL', 'N', 'DIVIDE', 12
 where not exists (select 1 from screen_indicators where name = 'buyback_yield');

-- Set again on a re-run, so a database that already had one of these rows
-- under different settings is brought into line.
update screen_indicators
   set provider_field='Ratios.dividendYieldTTM', description='Dividend yield',
       enabled='Y', screen_function=null, rank_zero='Y', calc_rank='N',
       rank_order='value DESC', rank_negative='NORMAL', rank_funds='N',
       fx_adjust='NONE', category=12
 where name='div_yield';

update screen_indicators
   set provider_field='Derived.buybackYieldTTM', description='Buyback yield',
       enabled='Y', screen_function=null, rank_zero='Y', calc_rank='N',
       rank_order='value DESC', rank_negative='NORMAL', rank_funds='N',
       fx_adjust='DIVIDE', category=12
 where name='buyback_yield';

-- ---------------------------------------------------------------------------
-- The total.
-- ---------------------------------------------------------------------------

-- screen_function is what makes get_statistics.php calculate it:
-- indicator_stats() dispatches through the name, per symbol, for the as of
-- date. The components are already in statistics by then - get_api_stats.py
-- writes them, and refresh_all.sh runs it before get_statistics.php.
--
-- No fx_adjust: it is added up from components that have already been
-- converted, so converting it again would apply the rate twice.
--
-- rank_zero='Y' because a zero here is a real answer. A company that pays no
-- dividend and buys back no stock has a shareholder yield of nought, and that
-- is a fact about the company rather than a missing figure.

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'shareholder_yield', null, 'Shareholder yield', 'Y', 212,
       'calc_shareholder_yield', 'Y', 'Y', 'value DESC', 'NORMAL', 'N', 'NONE', 12
 where not exists (select 1 from screen_indicators where name = 'shareholder_yield');

update screen_indicators
   set provider_field=null, description='Shareholder yield', enabled='Y',
       screen_function='calc_shareholder_yield', rank_zero='Y', calc_rank='Y',
       rank_order='value DESC', rank_negative='NORMAL', rank_funds='N',
       fx_adjust='NONE', category=12
 where name='shareholder_yield';

-- ---------------------------------------------------------------------------
-- What the run did.
-- ---------------------------------------------------------------------------

select name, ifnull(provider_field, ifnull(screen_function,'(none)')) source,
       category, calc_rank, rank_order, rank_funds, fx_adjust
  from screen_indicators
 where category = 12
 order by calc_rank desc, name;
