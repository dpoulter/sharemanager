-- Extra rows the sandbox wants that the test seed deliberately leaves out.
--
-- tests/seed.php stops short of writing scores, because several tests run
-- get_statistics.php and assert on what it produces; pre-seeding those rows
-- would mask a real failure. The sandbox has no such constraint and wants a
-- populated dashboard on first load, so the scores live here instead.

-- The as-of marker every statistics page resolves. Dated yesterday to match
-- pipeline_asofdate() and what get_statistics.php calculates for.
delete from jobs where job_name = 'get_statistics_asof';
insert into jobs (job_name, job_date)
  values ('get_statistics_asof', date_sub(now(), interval 1 day));
insert into jobs (job_name, job_date) values ('get_statistics', now());

-- Four scores per symbol at that date. Each indicator uses a different
-- multiplier before the modulo, so the four panels rank the symbols
-- differently rather than showing the same order four times, which is what a
-- constant per-indicator offset produced.
delete from statistics where indicator like '%\_score';
insert into statistics (symbol, date, indicator, value, exchange)
select ss.symbol,
       date(date_sub(now(), interval 1 day)),
       i.indicator,
       round(30 + mod((ascii(substring(ss.symbol,1,1)) - 64) * i.spread, 61), 2),
       ss.exchange
  from stock_symbols ss,
       (select 'momentum_score' indicator,  7 spread union all
        select 'value_score',                11 union all
        select 'quality_score',              17 union all
        select 'overall_score',              23) i
 where ss.exchange = 'XLON' and ss.enabled = 'Y';

-- A few trades in the current month. edit.php filters transactions by month and
-- year and defaults to the current one, so without these its first view is
-- empty and looks broken rather than simply out of range.
insert into purchases (session_id, symbol, trx_type, shares, price_paid, commission, purchase_date)
values (1, 'EEE', 'BUY',  400, 96.40,  5.95, date_sub(curdate(), interval 6 day)),
       (1, 'FFF', 'BUY',  150, 210.75, 5.95, date_sub(curdate(), interval 3 day)),
       (1, 'CCC', 'SELL', 200, 47.10,  5.95, date_sub(curdate(), interval 1 day));

insert into history (user_id, trx_type, symbol, quantity, price, timestamp)
values (1, 'BUY',  'EEE', 400, 96.40,  date_sub(now(), interval 6 day)),
       (1, 'BUY',  'FFF', 150, 210.75, date_sub(now(), interval 3 day)),
       (1, 'SELL', 'CCC', 200, 47.10,  date_sub(now(), interval 1 day));

insert into shares (id, symbol, shares, avg_cost, commission, price_paid)
values (1, 'EEE', 400, 96.40, 5.95, 96.40), (1, 'FFF', 150, 210.75, 5.95, 210.75)
  on duplicate key update shares = values(shares);
update shares set shares = shares - 200 where id = 1 and symbol = 'CCC';

-- A funded paper account, so the strategy pages have something to show.
insert into strategy_accounts (strategy, cash, currency, mode, enabled, created_at, note)
  values ('momentum_top10', 100000.0000, 'GBP', 'PAPER', 'Y', now(), 'sandbox demo account')
  on duplicate key update cash = values(cash);

-- ---------------------------------------------------------------------------
-- Quote page data. These come from the valuation and rating jobs, which the
-- sandbox does not run, so the values are generated from the symbol to be
-- stable across rebuilds rather than meaningful.
-- ---------------------------------------------------------------------------

set @asof = date(date_sub(now(), interval 1 day));

-- Piotroski and Altman inputs. The quote page shows the score and its parts.
delete from variables;
insert into variables (name, text) values
  ('roa','Return on assets positive'), ('cfo','Operating cash flow positive'),
  ('accrual','Cash flow exceeds profit'), ('leverage','Leverage falling'),
  ('liquidity','Current ratio rising'), ('shares','No new shares issued'),
  ('margin','Gross margin rising'), ('turnover','Asset turnover rising'),
  ('working_capital','Working capital to assets'), ('retained','Retained earnings to assets');

delete from health_indicators;
insert into health_indicators (symbol, type, date, variable, value)
select ss.symbol, t.type, @asof, v.name,
       round(mod(ascii(substring(ss.symbol,1,1)) + length(v.name), 2), 0)
  from stock_symbols ss,
       (select 'piotroski_fscore' type union all
        select 'altman_zscore' union all
        select 'altman_zscore_nonman') t,
       variables v
 where ss.exchange = 'XLON' and ss.enabled = 'Y';

-- The rating bands shown alongside the scores.
delete from momentum_ratings;
insert into momentum_ratings (symbol, date, number, momentum_rating, growth_rating,
                              value_rating, quality_rating, overall_rating)
select ss.symbol, @asof,
       mod(ascii(substring(ss.symbol,1,1)), 5) + 1,
       elt(mod(ascii(substring(ss.symbol,1,1)),    3) + 1, 'Strong', 'Neutral', 'Weak'),
       elt(mod(ascii(substring(ss.symbol,1,1)) + 1, 3) + 1, 'Strong', 'Neutral', 'Weak'),
       elt(mod(ascii(substring(ss.symbol,1,1)) + 2, 3) + 1, 'Strong', 'Neutral', 'Weak'),
       elt(mod(ascii(substring(ss.symbol,1,1)) + 3, 3) + 1, 'Strong', 'Neutral', 'Weak'),
       elt(mod(ascii(substring(ss.symbol,1,1)) + 4, 3) + 1, 'Strong', 'Neutral', 'Weak')
  from stock_symbols ss
 where ss.exchange = 'XLON' and ss.enabled = 'Y';

-- The raw indicators the quote page groups into momentum, growth, value and
-- quality panels, at the same as of date as the scores above.
insert into statistics (symbol, date, indicator, value, exchange)
select ss.symbol, @asof, i.indicator,
       round(mod(ascii(substring(ss.symbol,1,1)) * i.k, 40) - 10, 2), ss.exchange
  from stock_symbols ss,
       (select '3mnth' indicator, 3 k union all select '6mnth', 5 union all
        select '12mnth', 7 union all select 'pe', 11 union all
        select 'price_sales_ratio', 13 union all select 'price_book_ratio', 17 union all
        select 'roe_ttm', 19 union all select 'earnings_growth', 23) i
 where ss.exchange = 'XLON' and ss.enabled = 'Y'
   and not exists (select 1 from statistics s
                   where s.symbol = ss.symbol and s.date = @asof and s.indicator = i.indicator);

-- Company profile, for the quote page's profile tab.
update stock_symbols
   set employees = 1200 + (ascii(substring(symbol,1,1)) - 65) * 340,
       website   = concat('https://www.', lower(symbol), '-test.example.com'),
       directors = concat('A. Director (CEO), B. Director (CFO), C. Director (Chair) - ', symbol)
 where exchange = 'XLON';

-- ---------------------------------------------------------------------------
-- Fundamentals, in the shape fetch_eodhd_fundamentals.py produces: one row per
-- Section.Field. These fill the market cap, shares, 52 week range and business
-- summary on the quote page, which read stock_info through share_fundamentals().
-- ---------------------------------------------------------------------------

delete from stock_info;

insert into stock_info (symbol, asofdate, attribute, value)
select ss.symbol, date(date_sub(now(), interval 1 day)), a.attribute,
       case a.attribute
         when 'Highlights.MarketCapitalization' then
              cast(round(last.price * (18000000 + (ascii(substring(ss.symbol,1,1)) - 65) * 4200000) / 100) as char)
         when 'SharesStats.SharesOutstanding' then
              cast(18000000 + (ascii(substring(ss.symbol,1,1)) - 65) * 4200000 as char)
         when 'Technicals.52WeekHigh' then cast(round(hi.high, 2) as char)
         when 'Technicals.52WeekLow'  then cast(round(lo.low,  2) as char)
         when 'Valuation.TrailingPE'  then cast(round(9 + mod(ascii(substring(ss.symbol,1,1)) * 3, 19), 2) as char)
         when 'Highlights.ReturnOnEquityTTM'   then cast(round(0.06 + mod(ascii(substring(ss.symbol,1,1)), 22) / 100, 4) as char)
         when 'Highlights.ProfitMargin'        then cast(round(0.04 + mod(ascii(substring(ss.symbol,1,1)), 18) / 100, 4) as char)
         when 'Highlights.OperatingMarginTTM'  then cast(round(0.07 + mod(ascii(substring(ss.symbol,1,1)), 15) / 100, 4) as char)
         when 'Highlights.ReturnOnAssetsTTM'   then cast(round(0.03 + mod(ascii(substring(ss.symbol,1,1)), 11) / 100, 4) as char)
         -- stock_info.value is varchar(255), so this stays inside it. Real
         -- EODHD descriptions are longer and the loader truncates them.
         when 'General.Description' then left(concat(
              ss.name, ' is generated data for exercising this application, in the ',
              ss.industry, ' industry, ', ss.sector, ' sector, listed on the ', ss.market,
              '. Not a real business: prices, statements and ratios are synthetic.'), 255)
         when 'General.CurrencyCode' then 'GBX'
         when 'General.CountryName'  then 'United Kingdom'
       end
  from stock_symbols ss
  join (select symbol, price from historical_prices hp
         where hp.date = (select max(date) from historical_prices h2 where h2.symbol = hp.symbol)) last
    on last.symbol = ss.symbol
  join (select symbol, max(price) high from historical_prices
         where date >= date_sub(curdate(), interval 1 year) group by symbol) hi on hi.symbol = ss.symbol
  join (select symbol, min(price) low  from historical_prices
         where date >= date_sub(curdate(), interval 1 year) group by symbol) lo on lo.symbol = ss.symbol,
       (select 'Highlights.MarketCapitalization' attribute union all
        select 'SharesStats.SharesOutstanding'   union all
        select 'Technicals.52WeekHigh'           union all
        select 'Technicals.52WeekLow'            union all
        select 'Valuation.TrailingPE'            union all
        select 'Highlights.ReturnOnEquityTTM'    union all
        select 'Highlights.ProfitMargin'         union all
        select 'Highlights.OperatingMarginTTM'   union all
        select 'Highlights.ReturnOnAssetsTTM'    union all
        select 'General.Description'             union all
        select 'General.CurrencyCode'            union all
        select 'General.CountryName') a
 where ss.exchange = 'XLON' and ss.enabled = 'Y';

-- The business summary shown on the profile tab reads stock_symbols.description.
update stock_symbols ss
   set description = (select value from stock_info si
                       where si.symbol = ss.symbol and si.attribute = 'General.Description' limit 1)
 where ss.exchange = 'XLON';

-- ---------------------------------------------------------------------------
-- Rating panels. The quote page groups indicators into Momentum, Growth, Value
-- and Quality by joining screen_indicators to screen_criteria/screen_build for
-- the first three and to indicator_category for the fourth. tests/seed.php only
-- creates the three momentum indicators the pipeline actually calculates, so
-- the other three panels had nothing to describe.
--
-- These are enabled='N' with no screen_function, so get_statistics.php still
-- skips them; they exist to give the joins a description to show.
-- ---------------------------------------------------------------------------

delete from screen_build where screen_id between 3 and 17;
delete from screen_criteria where indicator_id in
  (select indicator_id from screen_indicators
    where name in ('3mnth','6mnth','12mnth','earnings_growth','pe',
                   'price_sales_ratio','price_book_ratio','roe_ttm'));
delete from screen_indicators where name in
  ('earnings_growth','pe','price_sales_ratio','price_book_ratio','roe_ttm');

-- The Ratios tab groups by indicator_category, so each indicator needs one.
-- get_quality_statistics() selects categories 10 and 11, so the value ratios
-- stay out of those two.
insert into indicator_category (category_id, name, description, `order`) values
  (12, 'Value',    'Value',    2),
  (13, 'Momentum', 'Momentum', 3),
  (14, 'Growth',   'Growth',   4)
  on duplicate key update description = values(description);

-- enabled='Y' because share_lookup() filters on it. get_statistics.php does not
-- call screen_function; it rolls up whatever statistics rows exist, so an
-- indicator with no function is included in the medians and left alone.
insert into screen_indicators
  (name, description, enabled, order_number, screen_function, calc_rank, rank_zero, rank_order, category)
values
  ('earnings_growth',  'Earnings growth %',      'Y', 1, '', 'N', 'N', 'value DESC', 14),
  ('pe',               'Price / earnings',       'Y', 1, '', 'N', 'N', 'value ASC',  12),
  ('price_sales_ratio','Price / sales',          'Y', 2, '', 'N', 'N', 'value ASC',  12),
  ('price_book_ratio', 'Price / book',           'Y', 3, '', 'N', 'N', 'value ASC',  12),
  ('roe_ttm',          'Return on equity (TTM)', 'Y', 1, '', 'N', 'N', 'value DESC', 10);

update screen_indicators
   set category = 13,
       description = concat(replace(name, 'mnth', ''), ' month price momentum %')
 where name in ('3mnth','6mnth','12mnth');

-- One criterion per indicator, then a screen_build row placing it in the group
-- the quote page reads: 3-7 momentum, 8-12 growth, 13-17 value.
insert into screen_criteria (indicator_id, description, operator, first_operand, second_operand)
select si.indicator_id, si.description, '>', si.name, null
  from screen_indicators si
 where si.name in ('3mnth','6mnth','12mnth','earnings_growth','pe',
                   'price_sales_ratio','price_book_ratio','roe_ttm');

insert into screen_build (screen_id, criteria_id)
select case si.name
         when '3mnth' then 3 when '6mnth' then 4 when '12mnth' then 5
         when 'earnings_growth' then 8
         when 'pe' then 13 when 'price_sales_ratio' then 14
         when 'price_book_ratio' then 15
         else 16
       end,
       sc.id
  from screen_criteria sc
  join screen_indicators si on si.indicator_id = sc.indicator_id
 where si.name in ('3mnth','6mnth','12mnth','earnings_growth','pe',
                   'price_sales_ratio','price_book_ratio','roe_ttm');

-- ---------------------------------------------------------------------------
-- Relative valuation. get_relative_to_sector() and get_relative_to_industry()
-- select price_valuation rows by type='relative_sector' / 'relative_industry'
-- and join screen_indicators on the indicator name, so the earlier VALUE and
-- QUALITY types matched nothing. Write one row of each type per indicator.
-- ---------------------------------------------------------------------------

delete from price_valuation;
insert into price_valuation (symbol, date, indicator, share_stat, sector_stat, industry_stat, type, value)
select ss.symbol, @asof, i.indicator,
       round(8 + mod(ascii(substring(ss.symbol,1,1)) * i.k, 23), 2),
       round(9 + mod(ascii(substring(ss.symbol,1,1)) * i.k, 19), 2),
       round(9 + mod(ascii(substring(ss.symbol,1,1)) * i.k, 17), 2),
       t.type,
       round(100 + mod(ascii(substring(ss.symbol,1,1)) * i.k, 90), 2)
  from stock_symbols ss,
       (select 'pe' indicator, 3 k union all
        select 'price_sales_ratio',  5 union all
        select 'price_book_ratio',   7 union all
        select 'roe_ttm',           11) i,
       (select 'relative_sector' type union all select 'relative_industry') t
 where ss.exchange = 'XLON' and ss.enabled = 'Y';

-- The two Price Valuation badges on the quote page divide these indicators by
-- the share price, so they need a value in statistics rather than in
-- price_valuation. Without them get_valuation() returned null and
-- get_industry_valuation() fell back to zero, which is the "0" badge.
delete from statistics where indicator in ('relative_valuation','relative_industry_valuation');
insert into statistics (symbol, date, indicator, value, exchange)
select ss.symbol, @asof, i.indicator,
       round(hp.price * i.factor, 4), ss.exchange
  from stock_symbols ss
  join (select symbol, price from historical_prices hp
         where hp.date = (select max(date) from historical_prices h2 where h2.symbol = hp.symbol)) hp
    on hp.symbol = ss.symbol,
       (select 'relative_valuation' indicator, 1.12 factor union all
        select 'relative_industry_valuation', 0.93) i
 where ss.exchange = 'XLON' and ss.enabled = 'Y';

-- ---------------------------------------------------------------------------
-- The Ratios tab. share_lookup() joins statistics to statistic_averages twice,
-- once for the market median and once for the sector median, and the template
-- draws a progress bar from statistics.percentile. None of the three were
-- seeded, so every category card came out empty.
-- ---------------------------------------------------------------------------

-- Where the symbol's value sits in the range for that indicator, 0-100.
update statistics s
   set percentile = round(mod(ascii(substring(s.symbol,1,1)) * 7 + length(s.indicator) * 11, 101))
 where s.date = @asof and s.percentile is null;

delete from statistic_averages where date = @asof;

-- Market median: one row per indicator, matched on exchange.
insert into statistic_averages (date, category, indicator, type, value, sector, industry, exchange)
select @asof, 'MARKET', s.indicator, 'MEDIAN',
       round(avg(s.value), 4), null, null, s.exchange
  from statistics s
 where s.date = @asof
   and s.indicator in ('3mnth','6mnth','12mnth','earnings_growth','pe',
                       'price_sales_ratio','price_book_ratio','roe_ttm')
 group by s.indicator, s.exchange;

-- Sector median: one row per indicator per sector, matched on stock_symbols.sector.
insert into statistic_averages (date, category, indicator, type, value, sector, industry, exchange)
select @asof, 'SECTOR', s.indicator, 'MEDIAN',
       round(avg(s.value), 4), ss.sector, null, s.exchange
  from statistics s
  join stock_symbols ss on ss.symbol = s.symbol and ss.exchange = s.exchange
 where s.date = @asof
   and s.indicator in ('3mnth','6mnth','12mnth','earnings_growth','pe',
                       'price_sales_ratio','price_book_ratio','roe_ttm')
 group by s.indicator, ss.sector, s.exchange;
