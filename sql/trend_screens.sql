-- Trend following screens.
--
--   mysql <database> < sql/trend_screens.sql
--
-- Two screens on one rule: hold while the price is above its long term
-- average, and drop the share when it closes below it. The second screen adds
-- the medium term average being above the long term one, which is the usual
-- "golden cross" filter - it keeps a share out while the 50 day average is
-- still falling through the 200 day, even if a single close has popped above
-- the 200.
--
-- Three indicators carry the numbers. They are compared against each other
-- rather than ranked, so calc_rank is 'N': a percentile on a share price is
-- meaningless, and a ranked indicator also feeds the value and quality scores,
-- which these must not.
--
-- Nothing here hard-codes an indicator_id. The ids differ between databases,
-- and sql/initial_data.sql used to set calc_rank by id and silently updated
-- whatever indicator happened to hold that id. Rows are matched by name, which
-- is the application's own key - it is written to statistics.indicator and
-- read by build_screen().
--
-- Re-runnable: every statement is either guarded by "not exists" or is an
-- update that sets the same values again.

-- ---------------------------------------------------------------------------
-- Category.
-- ---------------------------------------------------------------------------

-- 13 is Momentum, which is where a trend signal belongs: these indicators
-- answer "is the price trending up", the same question the momentum series
-- asks over a fixed lookback. A separate Trend category would need an id that
-- is free in every database, and there is nothing for it to hold that
-- Momentum does not already describe.
insert into indicator_category (category_id, name, description, `order`)
select 13, 'Momentum', 'Momentum', 2
 where not exists (select 1 from indicator_category where category_id = 13);

-- ---------------------------------------------------------------------------
-- Indicators.
-- ---------------------------------------------------------------------------

-- screen_function is what makes get_statistics.php calculate these.
-- get_stats_indicators() returns every enabled indicator, indicator_stats()
-- dispatches through the function name, and the as of date it uses is the one
-- get_statistics.php then records as the get_statistics_asof job - which is
-- the date build_screen() reads when no date is passed. So the nightly run
-- already computes them for the right date and no job needed changing.
--
-- get_momentum_statistics.php would have been the wrong place: it steps
-- through monthly as of dates (min_date + 3 months, then P1M), so its dates
-- only coincide with the daily screening date by chance.

insert into screen_indicators (name, description, enabled, order_number, screen_function, calc_rank, rank_zero, category)
select 'close_price', 'Latest close', 'Y', 200, 'close_price', 'N', 'N', 13
 where not exists (select 1 from screen_indicators where name = 'close_price');

insert into screen_indicators (name, description, enabled, order_number, screen_function, calc_rank, rank_zero, category)
select 'ma50', '50 day moving average', 'Y', 201, 'ma50', 'N', 'N', 13
 where not exists (select 1 from screen_indicators where name = 'ma50');

insert into screen_indicators (name, description, enabled, order_number, screen_function, calc_rank, rank_zero, category)
select 'ma200', '200 day moving average', 'Y', 202, 'ma200', 'N', 'N', 13
 where not exists (select 1 from screen_indicators where name = 'ma200');

-- Set the settings on a row that already existed, so running this on a
-- database where one was added by hand converges on the same state.
update screen_indicators
   set enabled = 'Y', calc_rank = 'N', category = 13,
       screen_function = name
 where name in ('close_price', 'ma50', 'ma200');

-- ---------------------------------------------------------------------------
-- Screens.
-- ---------------------------------------------------------------------------

-- screen.session_id is left null on purpose: screen_list.php selects on
-- displayed='Y' with no session filter, so screens are shared rather than
-- per account, and giving these one owner would be misleading.
insert into screen (name, description, displayed)
select 'Trend following (200-day)',
       'Hold while the latest close is above its 200 day average. A close below it is the sell signal.',
       'Y'
 where not exists (select 1 from screen where name = 'Trend following (200-day)');

insert into screen (name, description, displayed)
select 'Trend following (50/200)',
       'As the 200-day screen, and the 50 day average must also be above the 200 day.',
       'Y'
 where not exists (select 1 from screen where name = 'Trend following (50/200)');

-- ---------------------------------------------------------------------------
-- Criteria.
-- ---------------------------------------------------------------------------

-- first_operand is null so build_screen() compares against another
-- indicator's value on the same date rather than against a constant: it uses
-- first_operand when it has one and falls through to second_operand, which
-- holds the indicator name.
--
-- A share with no ma200 row - fewer than 200 prices, see calc_moving_avg() -
-- makes that subquery null, and "value > null" is null, so the share is left
-- out of the screen rather than being compared against a part-window average.

insert into screen_criteria (indicator_id, description, operator, first_operand, second_operand)
select si.indicator_id, 'Close above the 200 day average', '>', null, 'ma200'
  from screen_indicators si
 where si.name = 'close_price'
   and not exists (select 1 from screen_criteria sc
                    where sc.indicator_id = si.indicator_id
                      and sc.operator = '>' and sc.second_operand = 'ma200');

insert into screen_criteria (indicator_id, description, operator, first_operand, second_operand)
select si.indicator_id, '50 day average above the 200 day average', '>', null, 'ma200'
  from screen_indicators si
 where si.name = 'ma50'
   and not exists (select 1 from screen_criteria sc
                    where sc.indicator_id = si.indicator_id
                      and sc.operator = '>' and sc.second_operand = 'ma200');

-- ---------------------------------------------------------------------------
-- Which criteria belong to which screen. build_screen() intersects them.
-- ---------------------------------------------------------------------------

-- Screen 1: close above the 200 day average.
insert into screen_build (screen_id, criteria_id)
select s.id, sc.id
  from screen s, screen_criteria sc, screen_indicators si
 where s.name = 'Trend following (200-day)'
   and si.name = 'close_price'
   and sc.indicator_id = si.indicator_id and sc.operator = '>' and sc.second_operand = 'ma200'
   and not exists (select 1 from screen_build sb
                    where sb.screen_id = s.id and sb.criteria_id = sc.id);

-- Screen 2: the same, plus the 50 day average above the 200 day.
insert into screen_build (screen_id, criteria_id)
select s.id, sc.id
  from screen s, screen_criteria sc, screen_indicators si
 where s.name = 'Trend following (50/200)'
   and si.name in ('close_price', 'ma50')
   and sc.indicator_id = si.indicator_id and sc.operator = '>' and sc.second_operand = 'ma200'
   and not exists (select 1 from screen_build sb
                    where sb.screen_id = s.id and sb.criteria_id = sc.id);

-- ---------------------------------------------------------------------------
-- What the migration did, so the run reports rather than leaving you to count
-- "0 rows affected" a dozen times.
-- ---------------------------------------------------------------------------

select si.name, si.description, si.enabled, si.calc_rank, si.screen_function, si.category
  from screen_indicators si
 where si.name in ('close_price', 'ma50', 'ma200')
 order by si.order_number;

select s.name as screen, sc.description as criteria, si.name as indicator,
       sc.operator, ifnull(sc.first_operand, '(indicator)') as first_operand, sc.second_operand
  from screen s, screen_build sb, screen_criteria sc, screen_indicators si
 where sb.screen_id = s.id and sb.criteria_id = sc.id and sc.indicator_id = si.indicator_id
   and s.name in ('Trend following (200-day)', 'Trend following (50/200)')
 order by s.name, si.name;
