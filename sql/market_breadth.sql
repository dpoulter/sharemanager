-- How much of the market is in an uptrend, worked out once a night.
--
-- The dashboard asked two questions of statistics on every page load:
-- today's breadth, and six months of the same figure for the line beside it.
-- Both pivot the table with max(case when indicator=...) across the whole
-- universe, and both have to read every row in the range to do it: 1.07s and
-- 1.57s of a 3.2 second page, on figures that change once a night.
--
-- They change once a night, so they are computed once a night.
-- includes/write_market_breadth.php fills this from the same rule the page
-- used, and the page reads it. The rule itself has not moved: a close above
-- the 200 day average, counted over the shares that have a 200 day average.
--
-- shares_counted is stored because a proportion without a denominator cannot
-- be checked, and on a day when the statistics job half ran the denominator
-- is the thing that gives it away.
--
-- Safe to apply twice.

create table if not exists market_breadth (
  date                 date        not null,
  exchange             varchar(10) not null,
  pct_above_ma200      decimal(6,2) null,
  pct_ma50_above_ma200 decimal(6,2) null,
  median_12m           decimal(12,4) null,
  shares_counted       int          not null default 0,
  written_at           datetime     null,
  primary key (date, exchange)
);

-- What is in it.
select exchange, count(*) days, min(date) earliest, max(date) latest
  from market_breadth group by exchange;
