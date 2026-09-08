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

-- Per indicator valuation, share against industry.
delete from price_valuation;
insert into price_valuation (symbol, date, indicator, share_stat, sector_stat, industry_stat, type, value)
select ss.symbol, @asof, i.indicator,
       round(8 + mod(ascii(substring(ss.symbol,1,1)) * i.k, 23), 2),
       round(9 + mod(ascii(substring(ss.symbol,1,1)) * i.k, 19), 2),
       round(9 + mod(ascii(substring(ss.symbol,1,1)) * i.k, 17), 2),
       i.type,
       round(100 + mod(ascii(substring(ss.symbol,1,1)) * i.k, 90), 2)
  from stock_symbols ss,
       (select 'pe' indicator, 3 k, 'VALUE' type union all
        select 'price_sales_ratio',  5, 'VALUE' union all
        select 'price_book_ratio',   7, 'VALUE' union all
        select 'roe_ttm',           11, 'QUALITY') i
 where ss.exchange = 'XLON' and ss.enabled = 'Y';

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
