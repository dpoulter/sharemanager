-- Piotroski, Altman and the growth rates, taken from the provider.
--
--   mysql <database> < sql/fmp_scores.sql
--
-- This application worked Piotroski and Altman out for itself, from
-- financial_statement_values. That table is empty on the live database, so
-- neither score was ever written, the quote page's health card has been
-- commented out for as long as anyone can remember, and the three modals
-- behind it hung off onclick handlers that do not exist. FMP computes both
-- from statements it already holds - RR comes back as 7 and 3.09 - so they
-- are fetched rather than derived, and the arithmetic goes.
--
-- earnings_growth is the same story from the other end: the indicator exists
-- and nothing has ever filled it, because the local calculation needs next
-- year's and this year's EPS estimates and no loader writes either.
--
-- What is NOT moving to the provider: close_price, ma50, ma200 and the 3, 6
-- and 12 month momentum. Those are worked out from historical_prices, which
-- is where the history the screens and the backtests read lives. FMP's
-- 200-day average for RR is 1338.8005, identical to this application's, so
-- there is nothing to gain and a backtest to lose.
--
-- Re-runnable: inserts are guarded by "where not exists" and every update
-- sets the same values again.

-- ---------------------------------------------------------------------------
-- Where the growth rates belong.
-- ---------------------------------------------------------------------------

insert into indicator_category (category_id, name, description, `order`)
select 11, 'Growth', 'Growth', 1
 where not exists (select 1 from indicator_category where category_id = 11);

insert into indicator_category (category_id, name, description, `order`)
select 10, 'Quality', 'Quality', 1
 where not exists (select 1 from indicator_category where category_id = 10);

-- ---------------------------------------------------------------------------
-- The two scores.
-- ---------------------------------------------------------------------------

-- Category 10, so they join roe_ttm in the quality score, which averages its
-- components the way the value score does.
--
-- rank_zero='Y': nought is a real Piotroski score, the worst one, and a
-- company that scores it should be ranked last rather than left out.
-- rank_negative='NORMAL': a negative Altman Z is a real and very bad number,
-- and ranking DESC already sorts it last. No fx_adjust: a count out of nine
-- and a weighted ratio are unitless, so there is no currency in either.

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'piotroski_score', 'Scores.piotroskiScore', 'Piotroski F-Score', 'Y', 220,
       null, 'Y', 'Y', 'value DESC', 'NORMAL', 'N', 'NONE', 10
 where not exists (select 1 from screen_indicators where name = 'piotroski_score');

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'altman_z', 'Scores.altmanZScore', 'Altman Z-Score', 'Y', 221,
       null, 'Y', 'Y', 'value DESC', 'NORMAL', 'N', 'NONE', 10
 where not exists (select 1 from screen_indicators where name = 'altman_z');

update screen_indicators
   set provider_field='Scores.piotroskiScore', description='Piotroski F-Score',
       enabled='Y', screen_function=null, rank_zero='Y', calc_rank='Y',
       rank_order='value DESC', rank_negative='NORMAL', rank_funds='N',
       fx_adjust='NONE', category=10
 where name='piotroski_score';

update screen_indicators
   set provider_field='Scores.altmanZScore', description='Altman Z-Score',
       enabled='Y', screen_function=null, rank_zero='Y', calc_rank='Y',
       rank_order='value DESC', rank_negative='NORMAL', rank_funds='N',
       fx_adjust='NONE', category=10
 where name='altman_z';

-- ---------------------------------------------------------------------------
-- Where a score does not apply.
-- ---------------------------------------------------------------------------

-- Altman's Z was fitted on manufacturers and reads a bank or an insurer as
-- distressed on the strength of its balance sheet alone: leverage is the
-- business, not a warning. Ranking it there would put every financial at the
-- bottom of the quality score for being a financial.
--
-- A row here leaves one indicator unranked for one sector. The sector is
-- matched against stock_symbols.sector, the same column fund_sectors matches,
-- so adding another exclusion is a row rather than a release and no sector
-- name appears in the application's SQL.
create table if not exists indicator_sector_exclusions (
  indicator varchar(50)  not null,
  sector    varchar(100) not null,
  reason    varchar(255),
  primary key (indicator, sector)
);

insert into indicator_sector_exclusions (indicator, sector, reason)
select 'altman_z', 'Financial Services',
       'Altman Z was fitted on manufacturers; a bank''s leverage is its business, not distress'
 where not exists (select 1 from indicator_sector_exclusions
                    where indicator = 'altman_z' and sector = 'Financial Services');

-- ---------------------------------------------------------------------------
-- The growth rates.
-- ---------------------------------------------------------------------------

-- Category 11, and not ranked: they are shown on the quote page and sent to
-- the outlook, and nothing scores on them today. Fractions, like the yields:
-- 0.05 is 5%.
--
-- earnings_growth loses its screen_function. The local calculation could only
-- ever write nothing, and leaving it registered would let it run after
-- get_api_stats.py and overwrite a figure that is now real.

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'revenue_growth', 'Growth.revenueGrowth', 'Revenue growth', 'Y', 222,
       null, 'Y', 'N', 'value DESC', 'NORMAL', 'N', 'NONE', 11
 where not exists (select 1 from screen_indicators where name = 'revenue_growth');

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'earnings_growth', 'Derived.epsGrowth', 'EPS growth', 'Y', 223,
       null, 'Y', 'N', 'value DESC', 'NORMAL', 'N', 'NONE', 11
 where not exists (select 1 from screen_indicators where name = 'earnings_growth');

update screen_indicators
   set provider_field='Growth.revenueGrowth', description='Revenue growth',
       enabled='Y', screen_function=null, rank_zero='Y', calc_rank='N',
       rank_order='value DESC', rank_negative='NORMAL', rank_funds='N',
       fx_adjust='NONE', category=11
 where name='revenue_growth';

update screen_indicators
   set provider_field='Derived.epsGrowth', description='EPS growth',
       enabled='Y', screen_function=null, rank_zero='Y', calc_rank='N',
       rank_order='value DESC', rank_negative='NORMAL', rank_funds='N',
       fx_adjust='NONE', category=11
 where name='earnings_growth';

-- ebitgrowth lands in stock_info with everything else financial-growth
-- returns, ready for the day an indicator wants it. Deliberately not
-- registered: a new row here is ranked and changes what the screens select,
-- which is a decision about the product.

-- ---------------------------------------------------------------------------
-- The scores this application used to work out for itself.
-- ---------------------------------------------------------------------------

-- piotroski_fscore, altman_zscore and altman_zscore_nonman were written by
-- calc_piotroski_fscore() and the two calc_altman_zscore() functions, which
-- are gone. Nothing writes them now, so they are disabled rather than
-- deleted: a row that has been ranked for years is better left visible and
-- off than vanished.
update screen_indicators set enabled='N', screen_function=null
 where name in ('piotroski_fscore','altman_zscore','altman_zscore_nonman');

-- ---------------------------------------------------------------------------
-- What the run did.
-- ---------------------------------------------------------------------------

select name, provider_field, category, calc_rank, rank_order, fx_adjust
  from screen_indicators
 where name in ('piotroski_score','altman_z','revenue_growth','earnings_growth',
                'piotroski_fscore','altman_zscore','altman_zscore_nonman')
 order by enabled desc, category, name;

select indicator, sector, reason from indicator_sector_exclusions order by indicator, sector;
