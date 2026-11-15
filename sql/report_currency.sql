-- Reporting currency, daily FX rates, and which ratios need converting.
--
--   mysql <database> < sql/report_currency.sql
--
-- FMP's ratios-ttm and key-metrics-ttm divide the London price by per-share
-- figures taken from the company's accounts, and the two are not always in the
-- same currency. The price is sterling; the accounts are in whatever the
-- company reports in. Nothing converted between them, so every non-sterling
-- reporter looked cheaper than it is by exactly the exchange rate:
--
--   RR    reports GBP   P/E 37.52   correct
--   SHEL  reports USD   P/E  8.43   true about 11.1  (x GBPUSD 1.321)
--   BGEO  reports GEL   P/E  2.38   true about  8.6
--                       P/B  0.59   true about  2.1
--
-- 62 of 272 companies with statements report in something other than sterling
-- (45 USD, 14 EUR, 2 GEL, 1 CAD), and seven of the thirty entries in the
-- value, quality and overall top tens were affected: BGEO TBCG ENQ IAG SHEL
-- HTWS PAF.
--
-- Three pieces of data carry the fix, all matched by name rather than id:
--
--   stock_symbols.report_ccy        what the company reports in
--   fx_rates                        units of each currency per pound, per day
--   screen_indicators.fx_adjust     what to do to that indicator's value
--
-- Re-runnable: every statement is "if not exists" or an update that sets the
-- same values again.

-- ---------------------------------------------------------------------------
-- What each company reports in.
-- ---------------------------------------------------------------------------

-- ISO 4217, from the income statement's reportedCurrency. Null means not yet
-- known, which is treated as "do not correct" rather than as sterling: a
-- wrong correction is worse than a known-stale number.
alter table stock_symbols
  add column if not exists report_ccy varchar(3) after market;

-- When it was last looked up. The loader refreshes a symbol whose currency is
-- null or older than this, rather than spending a call per symbol per night on
-- a figure that changes about never.
alter table stock_symbols
  add column if not exists report_ccy_asof date after report_ccy;

-- ---------------------------------------------------------------------------
-- The rates.
-- ---------------------------------------------------------------------------

-- rate is quote units per one base unit: (GBP, USD, 1.3212) means a pound buys
-- 1.3212 dollars, so a dollar figure divided into a pound price is understated
-- by that factor and the ratio is multiplied by it.
--
-- source records how the rate was arrived at, because a crossed rate is less
-- exact than a quoted one and the difference should be visible rather than
-- inferred. GBPGEL is not quoted on this plan - the endpoint answers [] - so
-- GEL is crossed through the dollar.
create table if not exists fx_rates (
  date       date        not null,
  base       varchar(3)  not null,
  quote_ccy  varchar(3)  not null,
  rate       decimal(20,8) not null,
  source     varchar(20),            -- 'quote' or 'cross:USD'
  primary key (date, base, quote_ccy)
);

-- ---------------------------------------------------------------------------
-- Which indicators need converting, and how.
-- ---------------------------------------------------------------------------

--   NONE      the ratio is one currency over the same currency, so the rate
--             cancels. Return on equity, the margins, anything from the
--             accounts alone.
--   MULTIPLY  a sterling price over a per-share figure from the accounts. Too
--             small by the rate, so multiplied by it.
--   DIVIDE    the other way up - a per-share figure from the accounts over a
--             sterling price, such as a yield. Too large by the rate.
alter table screen_indicators
  add column if not exists fx_adjust varchar(8) default 'NONE' after rank_funds;

update screen_indicators set fx_adjust='MULTIPLY'
 where name in ('pe','price_sales_ratio','price_book_ratio',
                'price_free_cash_flow_per_share','price_eps_ratio_curr_year');

-- A yield is the same ratio inverted.
update screen_indicators set fx_adjust='DIVIDE'
 where name in ('dividend_yield');

-- shareholder_yield is not here on purpose. It is not a provider figure:
-- calc_shareholder_yield() in share_functions.php adds buyback_yield and
-- div_yield together out of statistics, so get_api_stats.py never writes it
-- and a flag on it would say nothing. Those two components are the ones that
-- would need converting, and neither is mapped to a provider field today -
-- nothing writes them, so shareholder_yield comes out as zero for every
-- share. Worth settling before this is relied on either way.

-- Same currency top and bottom.
update screen_indicators set fx_adjust='NONE'
 where name in ('roe_ttm','roa','operating_margin','profit_margin','roce',
                '3mnth','6mnth','12mnth','close_price','ma50','ma200');

-- enterprise_value_to_ebitda is left at NONE deliberately. Enterprise value is
-- market capitalisation plus debt less cash: the first term is sterling and
-- the other two come from the accounts, so one multiplication cannot fix it
-- and whether FMP has already converted anything is not known from the
-- response. The indicator is not enabled in this application today, so nothing
-- reads it; if it is ever turned on, check a live key-metrics-ttm response for
-- a non-sterling reporter before setting this.

-- ---------------------------------------------------------------------------
-- What the run did.
-- ---------------------------------------------------------------------------

select name, provider_field, fx_adjust
  from screen_indicators
 where fx_adjust <> 'NONE'
 order by name;

select ifnull(report_ccy,'(not yet known)') report_ccy, count(*) symbols
  from stock_symbols
 where enabled='Y'
 group by report_ccy
 order by symbols desc;
