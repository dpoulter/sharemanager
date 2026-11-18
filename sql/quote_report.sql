-- The figures the one-screen quote page shows and the database did not have.
--
--   mysql <database> < sql/quote_report.sql
--
-- The report rebuilt on 18 Nov 2026 shows a share against its sector, its
-- market, its own year and its own trend. Four of the rows it draws had
-- nothing behind them:
--
--   1mnth              a one month return, ranked like the other three. The
--                      page worked it out from the closes and could show no
--                      rank for it, because a rank needs the whole index.
--   high_52w, low_52w  the ends of the year. The page scanned a year of
--                      historical_prices on every view to find them.
--   roce               return on capital employed, the quality measure the
--                      panel is missing beside return on equity.
--   operating_margin   mapped in sql/fmp_migration.sql against the day a row
--                      was added for it. This is that day.
--
-- Nothing here changes what an existing indicator does: every insert is
-- guarded by "where not exists", and the updates set the same values again,
-- so the file is re-runnable and a row somebody has tuned by hand keeps its
-- settings except where this file is the thing that defines them.
--
-- The quality score is the average percentile of the indicators in category
-- 10 with calc_rank='Y', and the value score does the same with category 12.
-- A new measure in either category would join the score it is in and change
-- every share's ShareRank overnight, which is a decision about the product
-- rather than part of showing a figure on a page.
--
-- So membership is a column of its own now. in_score='Y' is what the score
-- sums; a measure can be ranked - which is what the page draws its bar from
-- - without being part of the score. Every indicator that was in a score
-- before this file ran is still in it, because the column defaults to 'Y'
-- and the two new rows are the only ones set to 'N'. Dale turns either on
-- with one update when he wants it counted.

alter table screen_indicators
  add column if not exists in_score char(1) default 'Y' after rank_funds;

update screen_indicators set in_score='Y' where in_score is null;

-- ---------------------------------------------------------------------------
-- The momentum series: one month, and the two ends of the year.
-- ---------------------------------------------------------------------------

-- Category 13 is Momentum, which is where close_price, ma50 and ma200 already
-- live.
insert into indicator_category (category_id, name, description, `order`)
select 13, 'Momentum', 'Momentum', 2
 where not exists (select 1 from indicator_category where category_id = 13);

-- calc_momentum_1mnth() is the same calculation as the other three, one month
-- back; get_statistics.php dispatches through screen_function every night and
-- includes/get_momentum_statistics.php writes the monthly series.
--
-- calc_rank='Y' so the page can say "316 of 350": a return with no rank is a
-- number with nothing to read it against, which is the thing this page was
-- rebuilt to stop printing. rank_zero='N' - a share that has not moved in a
-- month has moved nought per cent, and that is an answer, not a gap.
insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select '1mnth', null, '1 month return', 'Y', 300,
       'calc_momentum_1mnth', 'N', 'Y', 'value DESC', 'NORMAL', 'Y', 'NONE', 13
 where not exists (select 1 from screen_indicators where name = '1mnth');

update screen_indicators
   set description='1 month return', enabled='Y',
       screen_function='calc_momentum_1mnth', calc_rank='Y',
       rank_order='value DESC', fx_adjust='NONE', category=13
 where name='1mnth';

-- The ends of the year, stored rather than scanned. A price in pence like
-- close_price, ma50 and ma200, and not ranked: "the highest close of the
-- year" is not a quantity one company can have more of than another.
insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'high_52w', null, '52 week high', 'Y', 301,
       'high_52w', 'N', 'N', 'value DESC', 'NORMAL', 'Y', 'NONE', 13
 where not exists (select 1 from screen_indicators where name = 'high_52w');

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'low_52w', null, '52 week low', 'Y', 302,
       'low_52w', 'N', 'N', 'value DESC', 'NORMAL', 'Y', 'NONE', 13
 where not exists (select 1 from screen_indicators where name = 'low_52w');

update screen_indicators
   set description='52 week high', enabled='Y', screen_function='high_52w',
       calc_rank='N', fx_adjust='NONE', category=13
 where name='high_52w';

update screen_indicators
   set description='52 week low', enabled='Y', screen_function='low_52w',
       calc_rank='N', fx_adjust='NONE', category=13
 where name='low_52w';

-- ---------------------------------------------------------------------------
-- The two quality measures.
-- ---------------------------------------------------------------------------

insert into indicator_category (category_id, name, description, `order`)
select 10, 'Quality', 'Quality', 1
 where not exists (select 1 from indicator_category where category_id = 10);

-- Both are a return or a margin: two figures from the same set of accounts,
-- in the same currency, so there is nothing for the exchange rate to do and
-- fx_adjust stays NONE. See sql/report_currency.sql for the ones that do mix
-- a sterling price with foreign earnings.
--
-- The provider field names are FMP's, in the spelling the rest of the
-- mapping uses. If either comes back empty on the first night, the loader
-- now says so by name - "roce: no KeyMetrics.returnOnCapitalEmployedTTM in
-- any response" - rather than leaving a column of dashes to be noticed
-- later; python/fmp_probe.py prints what a response actually carries.
insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'roce', 'KeyMetrics.returnOnCapitalEmployedTTM', 'Return on capital',
       'Y', 310, null, 'Y', 'Y', 'value DESC', 'NORMAL', 'N', 'NONE', 10
 where not exists (select 1 from screen_indicators where name = 'roce');

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'operating_margin', 'Ratios.operatingProfitMarginTTM', 'Operating margin',
       'Y', 311, null, 'Y', 'Y', 'value DESC', 'NORMAL', 'N', 'NONE', 10
 where not exists (select 1 from screen_indicators where name = 'operating_margin');

update screen_indicators
   set provider_field='KeyMetrics.returnOnCapitalEmployedTTM',
       description='Return on capital', enabled='Y', calc_rank='Y',
       rank_order='value DESC', fx_adjust='NONE', category=10, in_score='N'
 where name='roce';

update screen_indicators
   set provider_field='Ratios.operatingProfitMarginTTM',
       description='Operating margin', enabled='Y', calc_rank='Y',
       rank_order='value DESC', fx_adjust='NONE', category=10, in_score='N'
 where name='operating_margin';

-- Return on equity is already loaded under roe_ttm. Its category is left
-- exactly as it is for the same reason as above: category 10 with
-- calc_rank='Y' is the quality score's membership, and the page reads the
-- measure by name rather than by category, so it needs no change here.

-- ---------------------------------------------------------------------------
-- The yearly accounts.
-- ---------------------------------------------------------------------------

-- financial_statement_items is the catalogue the Financial summary panel
-- reads, and it already holds most of what the panel needs under the three
-- statement types financial_statement() asks for. Three lines are missing,
-- and they are added under the same types rather than under a fourth name
-- for the same thing: a catalogue with both operating_income and
-- operating_profit in it is a catalogue where half the companies are filed
-- under each.
--
--   eps                 earnings per share, as reported
--   dividend_per_share  the dividend the year's accounts paid, which FMP
--                       does not report as a line: the loader works it out
--                       from the dividends paid in the cash flow statement
--                       over the weighted average share count
--   net_debt            borrowings less cash, from the balance sheet

-- Anything this file inserted under a type of its own on an earlier run,
-- before the existing catalogue was noticed. Named one by one rather than
-- by type, so a row somebody else added as 'annual' is left alone.
delete from financial_statement_items
 where type='annual' and name in ('revenue','operating_profit','net_profit','eps',
                                  'dividend_per_share','free_cash_flow','net_debt');

insert into financial_statement_items (name, description, type, order_number)
select 'eps', 'Earnings per share', 'income_statement', 4
 where not exists (select 1 from financial_statement_items where name = 'eps');

insert into financial_statement_items (name, description, type, order_number)
select 'dividend_per_share', 'Dividend per share', 'income_statement', 5
 where not exists (select 1 from financial_statement_items where name = 'dividend_per_share');

insert into financial_statement_items (name, description, type, order_number)
select 'net_debt', 'Net debt', 'balance_sheet', 3
 where not exists (select 1 from financial_statement_items where name = 'net_debt');

-- financial_statement_periods is keyed by its auto-increment id and has no
-- unique key on the date, so a loader that inserted a period every night
-- would collect five rows a year per run. One row per end date, shared by
-- every company that reports to it.
create index if not exists fsp_end_date on financial_statement_periods (end_date);
create index if not exists fsv_symbol_period on financial_statement_values (symbol, period_id);

-- ---------------------------------------------------------------------------
-- What the run did.
-- ---------------------------------------------------------------------------

select name, ifnull(provider_field, ifnull(screen_function,'(none)')) source,
       category, enabled, calc_rank, in_score, rank_order, fx_adjust
  from screen_indicators
 where name in ('1mnth','high_52w','low_52w','roce','operating_margin',
                'roe_ttm','pe','price_book_ratio','price_sales_ratio',
                'ev_ebitda','price_cash_flow','div_yield')
 order by category, name;

-- Whatever each score is built from after this file, which should be the
-- same list as before it.
select si.category, group_concat(si.name order by si.name) in_the_score
  from screen_indicators si
 where si.enabled='Y' and si.calc_rank='Y' and si.in_score='Y'
   and si.category in (10, 12)
 group by si.category;

select name, description, type, order_number
  from financial_statement_items order by type, order_number, name;
