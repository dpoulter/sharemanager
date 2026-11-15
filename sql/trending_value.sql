-- Trending Value.
--
--   mysql <database> < sql/trending_value.sql
--
-- James O'Shaughnessy, What Works on Wall Street (4th edition): rank the
-- universe on six value measures, average the ranks, keep the cheapest tenth,
-- and of those hold the ones with the strongest six month price momentum.
--
-- Four of the six were already here. value_score in get_statistics.php is
-- already the average of every enabled category 12 indicator with
-- calc_rank='Y' - pe, price_sales_ratio, price_book_ratio and
-- shareholder_yield - so adding the missing two to that category is the whole
-- of the change on the scoring side. Nothing in get_statistics.php needed
-- touching.
--
-- This moves the Value score on the home page and the quote page as well.
-- That is intended: the score was always meant to hold all six, and the
-- commented-out query it replaced named exactly these six.
--
-- Rows are matched by name, never by indicator_id: the ids differ between
-- databases. Re-runnable - every statement is either guarded by "not exists"
-- or is an update that sets the same values again.

-- ---------------------------------------------------------------------------
-- The two missing value measures.
-- ---------------------------------------------------------------------------

-- rank_order 'value ASC': cheap is a low ratio.
--
-- rank_negative 'EXCLUDE', which is what pe, price_sales_ratio and
-- price_book_ratio do. A negative P/E or a negative EV/EBITDA is not a cheap
-- share, and EXCLUDE stops it being ranked as one: the row is left unranked,
-- percentile stays null, and the score query falls back to the neutral 50.
-- Note what that does and does not say - a loss-maker scores neutral on the
-- measure, not worst. LAST is the setting for "worst"; see sql/fmp_migration.sql.
--
-- rank_funds 'N': a valuation ratio for an investment trust is not comparable
-- with one for a trading company, the same reason the other four carry it.
--
-- provider_field: Ratios and KeyMetrics are the sections python/fmp.py
-- flattens, and get_api_stats.py joins stock_info.attribute on this string.
-- KeyMetrics.evToEBITDATTM is in a real VOD.L key-metrics-ttm response (see
-- the sample in tests/test_fmp.py). Ratios.priceToOperatingCashFlowRatioTTM
-- follows the naming of the three ratios-ttm fields that same response
-- confirms - priceToEarningsRatioTTM, priceToBookRatioTTM,
-- priceToSalesRatioTTM - but it has NOT been seen in a live response: this
-- repository has no FMP key, so python/fmp_probe.py could not be run. Check
-- it against a live /stable/ratios-ttm before the first nightly run. A wrong
-- name is not an error anywhere - the join simply matches nothing and the
-- indicator is quietly absent - so the way you find out is the count at the
-- bottom of this file being zero after a pipeline run.
--
-- fx_adjust:
--   price_cash_flow MULTIPLY, the treatment pe and price_book_ratio get. A
--   sterling price over a per-share figure from the accounts is too small by
--   the rate for a company that reports in dollars.
--
--   ev_ebitda NONE, which is a placeholder and not a finding. Enterprise
--   value is market capitalisation plus debt less cash: the first term is a
--   market number and the other two come from the accounts, so if FMP mixes
--   currencies here no single multiplication fixes it, and the response does
--   not say what it did. sql/report_currency.sql left the old
--   enterprise_value_to_ebitda at NONE for this reason and said to check a
--   live response before changing it. That check still has not happened.
--   NONE means "pass the provider's number through", which is right for the
--   280-odd sterling reporters and unverified for the other 70.

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'price_cash_flow', 'Ratios.priceToOperatingCashFlowRatioTTM',
       'Price to Cash Flow', 'Y', 230,
       null, 'N', 'Y', 'value ASC', 'EXCLUDE', 'N', 'MULTIPLY', 12
 where not exists (select 1 from screen_indicators where name = 'price_cash_flow');

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'ev_ebitda', 'KeyMetrics.evToEBITDATTM',
       'EV to EBITDA', 'Y', 231,
       null, 'N', 'Y', 'value ASC', 'EXCLUDE', 'N', 'NONE', 12
 where not exists (select 1 from screen_indicators where name = 'ev_ebitda');

-- Converge a row that already existed on the same settings.
update screen_indicators
   set provider_field='Ratios.priceToOperatingCashFlowRatioTTM',
       description='Price to Cash Flow', enabled='Y', screen_function=null,
       rank_zero='N', calc_rank='Y', rank_order='value ASC',
       rank_negative='EXCLUDE', rank_funds='N', fx_adjust='MULTIPLY', category=12
 where name='price_cash_flow';

update screen_indicators
   set provider_field='KeyMetrics.evToEBITDATTM',
       description='EV to EBITDA', enabled='Y', screen_function=null,
       rank_zero='N', calc_rank='Y', rank_order='value ASC',
       rank_negative='EXCLUDE', rank_funds='N', fx_adjust='NONE', category=12
 where name='ev_ebitda';

-- ---------------------------------------------------------------------------
-- EV/EBITDA does not apply to banks and insurers.
-- ---------------------------------------------------------------------------

-- A bank's balance sheet is its business: "debt less cash" is not a capital
-- structure adjustment there, it is the trading book, and EBITDA is not a
-- measure anybody uses for one. FMP files both banks and insurers under the
-- sector 'Financial Services', which is the same sector altman_z is already
-- excluded for (sql/fmp_scores.sql).
--
-- An excluded share gets no rank for the indicator AND is left out of the
-- average, so a bank is scored on the five measures that mean something
-- rather than on six with a hole in it. See not_sector_excluded_sql().

insert into indicator_sector_exclusions (indicator, sector)
select 'ev_ebitda', 'Financial Services'
 where not exists (select 1 from indicator_sector_exclusions
                    where indicator = 'ev_ebitda' and sector = 'Financial Services');

-- ---------------------------------------------------------------------------
-- The scores, so a criterion can be written against one.
-- ---------------------------------------------------------------------------

-- value_score and the other three are written to statistics by
-- get_statistics.php but were not rows in screen_indicators, so build_screen()
-- could not name them: it joins screen_criteria to screen_indicators to get
-- the indicator's name.
--
-- enabled='N' is what keeps the nightly job off them, and it has to be 'N'
-- rather than 'Y' for three separate reasons, all of which bite:
--
--   get_stats_indicators() returns every enabled indicator, both the ones
--   with a screen_function and the ones without, and get_statistics.php then
--   calculates each. An enabled value_score would be looked up as a provider
--   field that does not exist.
--
--   the ranking loop takes enabled='Y' and calc_rank='Y', so an enabled
--   score would get a percentile of its own.
--
--   the value and quality score queries take enabled='Y' and a category. A
--   category 12 value_score would be averaged into the next value_score: the
--   score would eat itself, a little more each night.
--
-- category is left null for the same reason, and calc_rank='N' as well.
-- Nothing that reads these rows for screening filters on enabled:
-- build_screen() joins on indicator_id, screen_list.php lists the columns a
-- screen uses, and criteria_form.php offers every indicator in the dropdown.
--
-- rank_order is what a top_n criterion sorts by. All four scores are
-- percentiles where 100 is best, so all four are 'value DESC'.

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'value_score', null, 'Value Score', 'N', 240,
       null, 'N', 'N', 'value DESC', 'NORMAL', 'Y', 'NONE', null
 where not exists (select 1 from screen_indicators where name = 'value_score');

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'momentum_score', null, 'Momentum Score', 'N', 241,
       null, 'N', 'N', 'value DESC', 'NORMAL', 'Y', 'NONE', null
 where not exists (select 1 from screen_indicators where name = 'momentum_score');

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'quality_score', null, 'Quality Score', 'N', 242,
       null, 'N', 'N', 'value DESC', 'NORMAL', 'Y', 'NONE', null
 where not exists (select 1 from screen_indicators where name = 'quality_score');

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'overall_score', null, 'Overall Score', 'N', 243,
       null, 'N', 'N', 'value DESC', 'NORMAL', 'Y', 'NONE', null
 where not exists (select 1 from screen_indicators where name = 'overall_score');

update screen_indicators
   set provider_field=null, enabled='N', screen_function=null,
       calc_rank='N', rank_order='value DESC', category=null
 where name in ('value_score','momentum_score','quality_score','overall_score');

-- ---------------------------------------------------------------------------
-- The screen.
-- ---------------------------------------------------------------------------

-- session_id null and displayed='Y', as for the trend screens: screen_list.php
-- selects on displayed with no session filter, so screens are shared.
insert into screen (name, description, displayed)
select 'Trending Value',
       'O''Shaughnessy''s Trending Value: the cheapest tenth of the FTSE 350 on six value measures, then the strongest 6 month momentum.',
       'Y'
 where not exists (select 1 from screen where name = 'Trending Value');

-- ---------------------------------------------------------------------------
-- Criteria.
-- ---------------------------------------------------------------------------

-- build_screen() intersects the comparison criteria first and then applies
-- each top_n to what is left, in criteria id order. So these three read as:
-- of the shares whose six month momentum is positive, the cheapest 26, and of
-- those the 15 with the strongest momentum.
--
-- Note the order that gives: the momentum filter applies before the value
-- cut, not after it. The cheapest 26 are the cheapest 26 that are not still
-- falling, so the screen always has 26 to choose from rather than 26 less
-- however many were dropped. The book has no momentum filter at all - it is
-- here because a universe of 256 scored shares makes the cheapest tenth small
-- enough that the falling ones crowd out the list.

-- 26 is a tenth of the 256 non-fund shares that get a value_score. A fixed
-- number rather than a percentage: build_screen() has no way to say "a tenth
-- of whatever was scored tonight", and a count that changes with the universe
-- would change the screen without anybody editing it. Revisit if the universe
-- moves much - 350 shares less 94 funds is where 26 comes from.
insert into screen_criteria (indicator_id, description, operator, first_operand, second_operand)
select si.indicator_id, 'The cheapest 26 on the value score', 'top_n', '26', null
  from screen_indicators si
 where si.name = 'value_score'
   and not exists (select 1 from screen_criteria sc
                    where sc.indicator_id = si.indicator_id
                      and sc.operator = 'top_n' and sc.first_operand = '26');

insert into screen_criteria (indicator_id, description, operator, first_operand, second_operand)
select si.indicator_id, 'Six month momentum is positive', '>', '0', null
  from screen_indicators si
 where si.name = '6mnth'
   and not exists (select 1 from screen_criteria sc
                    where sc.indicator_id = si.indicator_id
                      and sc.operator = '>' and sc.first_operand = '0');

insert into screen_criteria (indicator_id, description, operator, first_operand, second_operand)
select si.indicator_id, 'The best 15 on six month momentum', 'top_n', '15', null
  from screen_indicators si
 where si.name = '6mnth'
   and not exists (select 1 from screen_criteria sc
                    where sc.indicator_id = si.indicator_id
                      and sc.operator = 'top_n' and sc.first_operand = '15');

-- ---------------------------------------------------------------------------
-- Which criteria belong to the screen.
-- ---------------------------------------------------------------------------

-- Inserted one at a time so the criteria ids go in ascending order of the
-- order the criteria are meant to apply in. screen_build has no column for
-- "which comes first", and build_screen() orders on criteria_id, so the order
-- the rows were created in is the order they run in.

insert into screen_build (screen_id, criteria_id)
select s.id, sc.id
  from screen s, screen_criteria sc, screen_indicators si
 where s.name = 'Trending Value'
   and si.name = 'value_score' and sc.indicator_id = si.indicator_id
   and sc.operator = 'top_n' and sc.first_operand = '26'
   and not exists (select 1 from screen_build sb
                    where sb.screen_id = s.id and sb.criteria_id = sc.id);

insert into screen_build (screen_id, criteria_id)
select s.id, sc.id
  from screen s, screen_criteria sc, screen_indicators si
 where s.name = 'Trending Value'
   and si.name = '6mnth' and sc.indicator_id = si.indicator_id
   and sc.operator = '>' and sc.first_operand = '0'
   and not exists (select 1 from screen_build sb
                    where sb.screen_id = s.id and sb.criteria_id = sc.id);

insert into screen_build (screen_id, criteria_id)
select s.id, sc.id
  from screen s, screen_criteria sc, screen_indicators si
 where s.name = 'Trending Value'
   and si.name = '6mnth' and sc.indicator_id = si.indicator_id
   and sc.operator = 'top_n' and sc.first_operand = '15'
   and not exists (select 1 from screen_build sb
                    where sb.screen_id = s.id and sb.criteria_id = sc.id);

-- ---------------------------------------------------------------------------
-- What the run did.
-- ---------------------------------------------------------------------------

select name, ifnull(provider_field,'(not from a provider)') provider_field,
       enabled, calc_rank, rank_order, rank_negative, rank_funds, fx_adjust,
       ifnull(category,0) category
  from screen_indicators
 where name in ('pe','price_sales_ratio','price_book_ratio','shareholder_yield',
                'price_cash_flow','ev_ebitda',
                'value_score','momentum_score','quality_score','overall_score')
 order by category is null, category, order_number;

select indicator, sector from indicator_sector_exclusions order by indicator, sector;

select s.name screen, sb.criteria_id, sc.description criteria, si.name indicator,
       sc.operator, sc.first_operand
  from screen s, screen_build sb, screen_criteria sc, screen_indicators si
 where sb.screen_id = s.id and sb.criteria_id = sc.id and sc.indicator_id = si.indicator_id
   and s.name = 'Trending Value'
 order by sb.criteria_id;

-- After a pipeline run, this should report six rows with a non-zero count.
-- A zero for price_cash_flow or ev_ebitda means the provider_field above does
-- not match anything FMP sent, which is the one thing in this file that has
-- not been checked against a live response.
select si.name, count(st.symbol) written
  from screen_indicators si
  left join statistics st
    on st.indicator = si.name
   and st.date = (select max(date) from statistics)
 where si.category = 12 and si.calc_rank = 'Y' and si.enabled = 'Y'
 group by si.name
 order by si.order_number;
