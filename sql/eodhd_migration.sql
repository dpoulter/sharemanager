-- Point the indicators at EODHD field names.
--
-- screen_indicators.name was doing two jobs: it is the key written to
-- statistics.indicator (and hard coded in the scoring SQL in
-- get_statistics.php, e.g. indicator in ('pe','price_sales_ratio',...)), and it
-- was also matched directly against stock_info.attribute, which held whatever
-- the data provider happened to call the field.
--
-- Those two roles conflict as soon as the provider changes. provider_field
-- separates them: name stays the application's own key and never changes,
-- provider_field holds the provider's name for the same number. Switching
-- provider, or correcting a single mapping, is now an UPDATE rather than a code
-- change or a rename that would orphan existing statistics rows.
--
-- Rows with provider_field empty or null fall back to matching on name, so
-- anything not listed here keeps its previous behaviour.

alter table screen_indicators
  add column provider_field varchar(100) null after name;

-- VERIFY THESE AGAINST REAL DATA BEFORE RELYING ON THEM.
-- They are EODHD's documented field names for a common stock, but they were not
-- confirmed against a live response. Run fetch_eodhd_fundamentals.py first, then
--   select distinct attribute from stock_info order by attribute;
-- to see what actually comes back, and correct any row below that does not match.

update screen_indicators set provider_field='Valuation.TrailingPE'          where name='pe';
update screen_indicators set provider_field='Valuation.PriceSalesTTM'       where name='price_sales_ratio';
update screen_indicators set provider_field='Valuation.PriceBookMRQ'        where name='price_book_ratio';
update screen_indicators set provider_field='Valuation.EnterpriseValueEbitda' where name='enterprise_value_to_ebitda';
update screen_indicators set provider_field='Highlights.ReturnOnEquityTTM'  where name='roe_ttm';

-- Not mapped, because no provider publishes them directly:
--   shareholder_yield            (dividends paid + net buybacks) / market cap
--   price_free_cash_flow_per_share   free cash flow / shares outstanding
-- Both need the cash flow statement, so they stay derived rather than fetched.
-- Leaving provider_field null means they fall back to matching on name and keep
-- whatever is populating them today.
