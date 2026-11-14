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
update screen_indicators set provider_field='Ratios.priceToEarningsRatioTTM' where name='pe';
update screen_indicators set provider_field='Ratios.priceToSalesRatioTTM'    where name='price_sales_ratio';
update screen_indicators set provider_field='Ratios.priceToBookRatioTTM'     where name='price_book_ratio';
update screen_indicators set provider_field='KeyMetrics.evToEBITDATTM'       where name='enterprise_value_to_ebitda';
update screen_indicators set provider_field='KeyMetrics.returnOnEquityTTM'   where name='roe_ttm';
update screen_indicators set provider_field='KeyMetrics.returnOnAssetsTTM'   where name='roa';
update screen_indicators set provider_field='Ratios.netProfitMarginTTM'      where name='profit_margin';
update screen_indicators set provider_field='Ratios.operatingProfitMarginTTM' where name='operating_margin';
update screen_indicators set provider_field='Ratios.dividendYieldTTM'        where name='dividend_yield';

-- Indicators this application calculates itself (the momentum series, the
-- Piotroski and Altman components) have no provider field and keep null, which
-- makes them fall back to matching on name.
