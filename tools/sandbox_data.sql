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
