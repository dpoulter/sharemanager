-- Dividends for the paper engine.
--
--   mysql <database> < sql/dividends.sql
--
-- historical_prices holds RAW closes - the price that was quoted on the day,
-- not one restated for later payments (BGEO on 1 Nov 2022: 2,245p raw
-- against 1,813.69p adjusted). A raw series leaves dividends out: an account
-- that held a share through an ex-date shows the fall and never the cash, so
-- every paper account and every backtest has been a price return that
-- understated what holding the shares paid. This is what puts it back.
--
-- symbol_dividends   what each share paid: the ex-date, the pay date where
--                    the provider has one, and the amount per share IN THE
--                    UNIT HISTORICAL_PRICES USES FOR THAT SHARE - pence for
--                    a London line - so it divides by the price on the same
--                    scale and the engine converts it to pounds the way it
--                    converts a price. python/fetch_fmp_dividends.py loads
--                    it and refuses an amount it cannot put in that unit.
--
-- strategy_dividends what an account was credited, one row per account,
--                    share and ex-date. The primary key is what makes
--                    crediting safe to repeat: a night that runs twice, or
--                    a backtest re-run over the same dates, cannot pay the
--                    same dividend twice.
--
-- strategy_backtest_runs.dividends records whether a run credited them:
--                    'on' or 'off'. NULL is every run made before this
--                    file existed, which is a price return and is labelled
--                    "price return, no dividends".
--
-- Safe to apply twice.

create table if not exists symbol_dividends (
  symbol     varchar(50)   not null,
  exchange   varchar(10)   not null,
  ex_date    date          not null,
  pay_date   date          null,
  amount     decimal(18,6) not null,
  source     varchar(20)   null,
  loaded_at  datetime      null,
  primary key (symbol, exchange, ex_date),
  key symbol_dividends_ex (exchange, ex_date)
) engine=InnoDB default charset=utf8mb4;

create table if not exists strategy_dividends (
  strategy    varchar(50)   not null,
  symbol      varchar(50)   not null,
  exchange    varchar(10)   not null,
  ex_date     date          not null,
  pay_date    date          null,
  quantity    decimal(18,8) not null,
  per_share   decimal(18,6) not null,
  amount      decimal(18,4) not null,
  credited_on date          not null,
  primary key (strategy, symbol, ex_date),
  key strategy_dividends_strategy (strategy, credited_on)
) engine=InnoDB default charset=utf8mb4;

alter table strategy_backtest_runs
  add column if not exists dividends varchar(3) null after cost_settings;

select 'symbol_dividends' t, count(*) n from symbol_dividends
union all select 'strategy_dividends', count(*) from strategy_dividends;
