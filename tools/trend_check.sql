-- Reproduce the trend screen figures straight from historical_prices, so the
-- screens can be checked against an independent sum rather than against
-- themselves.
--
--   mysql <database> < tools/trend_check.sql
--
-- Set the as of date first if you want a date other than the one the nightly
-- job last recorded:
--
--   mysql <database> -e "set @asof := '2026-11-13'; source tools/trend_check.sql"
--
-- On the live FTSE 350 for 2026-11-13 this gives 349 symbols with 200 days of
-- history, 231 with the close above the 200 day average, and 199 of those with
-- the 50 day average also above it. B&M (BME) closes at 351.0 against a 200
-- day average of 210.0.
--
-- Nothing here reads the statistics table, which is the point: these are the
-- numbers the screens should agree with.

set @asof := ifnull(@asof,
  (select date(max(job_date)) from jobs where job_name = 'get_statistics_asof'));
set @exchange := ifnull(@exchange, 'XLON');

select @asof as as_of_date, @exchange as exchange;

-- Rank each symbol's prices backwards from the as of date, then average the
-- first 50 and the first 200. A window function rather than a correlated
-- LIMIT per symbol, so this is one pass.
--
-- count(*) over the whole window is what enforces the full window: a symbol
-- with fewer than 200 prices gets no 200 day average, which is the same rule
-- calc_moving_avg() applies. Were it to divide by 200 regardless, a short
-- history would score far below its price and pass the screen on arithmetic.
with ranked as (
  select symbol,
         price,
         row_number() over (partition by symbol order by date desc) as rn,
         count(*)     over (partition by symbol)                    as n
    from historical_prices
   where exchange = @exchange
     and date <= @asof
),
per_symbol as (
  select r.symbol,
         max(case when r.rn = 1 then r.price end)                      as close_price,
         avg(case when r.rn <= 50  then r.price end)                   as ma50,
         avg(case when r.rn <= 200 then r.price end)                   as ma200,
         max(r.n)                                                      as prices_available
    from ranked r
    join stock_symbols ss
      on ss.symbol = r.symbol and ss.exchange = @exchange and ss.enabled = 'Y'
   group by r.symbol
  having prices_available >= 200
)
select count(*)                                                as with_200_days,
       sum(close_price > ma200)                                as close_above_ma200,
       sum(close_price > ma200 and ma50 > ma200)               as and_ma50_above_ma200
  from per_symbol;

-- The same per symbol, for spot checking a name against the screen output.
with ranked as (
  select symbol, price,
         row_number() over (partition by symbol order by date desc) as rn,
         count(*)     over (partition by symbol)                    as n
    from historical_prices
   where exchange = @exchange and date <= @asof
)
select r.symbol,
       round(max(case when r.rn = 1 then r.price end), 4)   as close_price,
       round(avg(case when r.rn <= 50  then r.price end), 6) as ma50,
       round(avg(case when r.rn <= 200 then r.price end), 6) as ma200,
       max(r.n)                                              as prices_available,
       case when max(r.n) < 200 then 'no ma200: short history'
            when max(case when r.rn = 1 then r.price end) <= avg(case when r.rn <= 200 then r.price end)
                 then 'out: below ma200'
            when avg(case when r.rn <= 50 then r.price end) <= avg(case when r.rn <= 200 then r.price end)
                 then 'screen 1 only: ma50 below ma200'
            else 'both screens' end                          as verdict
  from ranked r
  join stock_symbols ss
    on ss.symbol = r.symbol and ss.exchange = @exchange and ss.enabled = 'Y'
 group by r.symbol
 order by r.symbol;
