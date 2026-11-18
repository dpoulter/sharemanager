-- Financial Modeling Prep field mapping.
--
--   mysql <database> < sql/fmp_migration.sql
--
-- Unlike sql/eodhd_migration.sql, every provider_field below was read off a
-- real response. python/fmp_probe.py was run against VOD.L on a Premium plan
-- and these are the names it printed, so none of them are guesses from the
-- documentation and there is no "correct this later" step.
--
-- screen_indicators.name is the application's own key. It is written to
-- statistics.indicator and hard-coded in the scoring SQL in get_statistics.php,
-- so it must never change. provider_field is the provider's name for the same
-- number, and switching provider is an update here rather than a code change.
--
-- The Section prefix is this application's, not FMP's: it records which
-- endpoint the number came from, so stock_info stays readable and two
-- providers can live in one table.
--
--   Quote.*      /stable/quote
--   Profile.*    /stable/profile
--   KeyMetrics.* /stable/key-metrics-ttm
--   Ratios.*     /stable/ratios-ttm
--   Derived.*    computed by python/fmp.py, not sent by FMP

-- FMP's longest field name is 52 characters
-- (KeyMetrics.salesGeneralAndAdministrativeToRevenueTTM), which varchar(50)
-- could not hold: the EODHD loader skipped anything over the limit, so the
-- figure would have gone missing with nothing to show it had.
alter table stock_info modify attribute varchar(100) not null;

-- Valuation and quality ratios the screens rank on.
--
-- These four are the indicators the application currently defines, so these
-- are the four that will report a changed row.
update screen_indicators set provider_field='Ratios.priceToEarningsRatioTTM'  where name='pe';
update screen_indicators set provider_field='Ratios.priceToSalesRatioTTM'     where name='price_sales_ratio';
update screen_indicators set provider_field='Ratios.priceToBookRatioTTM'      where name='price_book_ratio';
update screen_indicators set provider_field='KeyMetrics.returnOnEquityTTM'    where name='roe_ttm';

-- The five below match no row today, and that is expected rather than a
-- failure: the application does not define these indicators. FMP returns all
-- of them, so the mapping is recorded here ready for the day one is added, and
-- running this file again will pick it up. They are deliberately not inserted:
-- a new row in screen_indicators is ranked by get_statistics.php and changes
-- what the screens select, which is a decision about the product rather than
-- part of switching data provider.
update screen_indicators set provider_field='KeyMetrics.evToEBITDATTM'        where name='enterprise_value_to_ebitda';
update screen_indicators set provider_field='KeyMetrics.returnOnAssetsTTM'    where name='roa';
update screen_indicators set provider_field='Ratios.netProfitMarginTTM'       where name='profit_margin';
update screen_indicators set provider_field='Ratios.operatingProfitMarginTTM' where name='operating_margin';
update screen_indicators set provider_field='Ratios.dividendYieldTTM'         where name='dividend_yield';

-- Indicators this application calculates itself (the momentum series, the
-- Piotroski and Altman components) have no provider field and keep null, which
-- makes them fall back to matching on name.

-- ---------------------------------------------------------------------------
-- Ranking settings.
-- ---------------------------------------------------------------------------

-- Without calc_rank='Y' an indicator never gets a percentile, and the value
-- and quality rankings sum percentiles: every share scored 0 for both, and
-- overall_score was momentum alone. This was set by hand on the live database
-- and is recorded here so a rebuild does not lose it.
update screen_indicators set calc_rank='Y'
 where name in ('pe','price_sales_ratio','price_book_ratio','roe_ttm');

-- The direction each is ranked in. Cheap is a low ratio; quality is a high
-- return.
update screen_indicators set rank_order='value ASC'
 where name in ('pe','price_sales_ratio','price_book_ratio');
update screen_indicators set rank_order='value DESC' where name='roe_ttm';

-- How a negative value is ranked.
--
-- Ranking value ASC put negatives first, so a loss-making company was scored
-- as the cheapest share in the index: on the FTSE 350 for 2026-11-13 the six
-- best P/E percentiles were all negative - Spire Healthcare at -527, Eurowag
-- at -257 - and Hg Capital Trust reached value_score 99 on negative book
-- value.
--
--   NORMAL   ranked on its value like any other (the column default)
--   EXCLUDE  left unranked, so percentile stays null and the score queries
--            use the neutral 50 they already apply to a missing value
--   LAST     ranked behind every non-negative value
--
-- EXCLUDE for the three valuation ratios. A negative P/E does not mean cheap,
-- and it does not mean expensive either: the ratio has no meaning for a
-- company with no earnings, so it should neither help nor hurt the score.
-- LAST is there for anyone who would rather a loss-maker scored worst.
-- roe_ttm ranks DESC, where a negative return already sorts last, so it keeps
-- NORMAL.
alter table screen_indicators
  add column if not exists rank_negative varchar(8) default 'NORMAL' after rank_order;

update screen_indicators set rank_negative='EXCLUDE'
 where name in ('pe','price_sales_ratio','price_book_ratio');

-- What the mapping looks like afterwards, so the run says what it did rather
-- than leaving you to count "0 rows affected" five times.
select name,
       ifnull(provider_field, '(calculated locally)') as provider_field,
       calc_rank, rank_order, rank_negative
  from screen_indicators
 order by provider_field is null, name;
