-- The broker connection: what Trading 212 says we hold, read only.
--
--   mysql <database> < sql/broker.sql
--
-- Stage 1 of the Trading 212 brief. Nothing here is written by anything that
-- can place an order: the sync job makes GET requests and these tables are a
-- record of the answers. The manual portfolio (purchases, cash_history,
-- dividends) is untouched and is still the portfolio the Portfolio pages
-- show; these tables sit beside it.
--
-- Everything is keyed on the broker's own ids so a re-run adds nothing
-- twice. "broker" is a column rather than an assumption: the tables say
-- t212 today and would say something else beside it later without a
-- migration.
--
-- Safe to apply twice.

-- ---------------------------------------------------------------------------
-- The instrument mapping.
-- ---------------------------------------------------------------------------

-- The broker's ticker is not our symbol. Trading 212's documented example is
-- AAPL_US_EQ; the format for London shares is not documented and is settled
-- by tools/t212_probe.php against real responses rather than guessed at here.
--
-- status says how the row was matched, and is the whole point of the table:
--
--   matched    one of our symbols, by ISIN or by code
--   ambiguous  the code fits more than one of our symbols, so nothing is
--              assumed and the page says so
--
-- A symbol with no row here is unmatched, which the page lists. Never guess a
-- match: a wrong one values the wrong company's holding.
create table if not exists broker_instruments (
  broker        varchar(20)  not null,
  broker_ticker varchar(40)  not null,
  symbol        varchar(50)  null,
  short_name    varchar(60)  null,
  isin          varchar(12)  null,
  currency      varchar(8)   null,
  venue         varchar(10)  null,
  name          varchar(255) null,
  status        varchar(20)  not null default 'matched',
  matched_on    varchar(20)  null,
  note          varchar(255) null,
  last_seen     date         null,
  primary key (broker, broker_ticker),
  -- One share, one instrument. Ambiguous rows have a null symbol and do not
  -- collide: many nulls are allowed in a unique index.
  unique key uq_broker_symbol (broker, symbol),
  key (isin)
);

-- Our side of an ISIN match. Nothing fills this yet - no job this application
-- runs has an ISIN to write - so in practice the mapping is by code until
-- something does. The column exists because the match rule is "ISIN if both
-- sides have one", and a rule that cannot be exercised is a rule nobody can
-- trust.
alter table stock_symbols
  add column if not exists isin varchar(12) null after tidm;

-- ---------------------------------------------------------------------------
-- What the account is worth.
-- ---------------------------------------------------------------------------

-- One row per day, replaced if the sync runs twice in a day: the figures are
-- the account's current state, not a movement, so the second answer is the
-- better one.
--
-- Pounds. Trading 212 returns the account's primary currency, which is GBP
-- for this account, and the brief says so; the one thing that is NOT assumed
-- is the unit prices come back in - see broker_positions.
create table if not exists broker_account_values (
  broker        varchar(20) not null,
  as_of_date    date        not null,
  cash          decimal(16,4) null,
  reserved      decimal(16,4) null,
  in_pies       decimal(16,4) null,
  invested      decimal(16,4) null,
  invested_cost decimal(16,4) null,
  realised      decimal(16,4) null,
  unrealised    decimal(16,4) null,
  total         decimal(16,4) null,
  currency      varchar(8)  null,
  synced_at     datetime    null,
  primary key (broker, as_of_date)
);

-- ---------------------------------------------------------------------------
-- The positions.
-- ---------------------------------------------------------------------------

-- average_price, current_price and value are POUNDS, whatever the broker
-- sent. historical_prices is pence and the strategy engine is pounds, so a
-- column that could be either is a column that is wrong by a factor of 100
-- half the time.
--
-- Which the broker sent is decided per sync by comparing its price against
-- our own close for the same share (includes/broker.php, broker_price_unit),
-- not assumed; price_unit records the answer, and a position whose unit could
-- not be decided stores null rather than a number that might be out by 100.
create table if not exists broker_positions (
  broker        varchar(20)  not null,
  as_of_date    date         not null,
  broker_ticker varchar(40)  not null,
  symbol        varchar(50)  null,
  quantity      decimal(18,8) null,
  average_price decimal(16,4) null,
  current_price decimal(16,4) null,
  value         decimal(16,4) null,
  gain          decimal(16,4) null,
  price_unit    varchar(10)  null,
  currency      varchar(8)   null,
  synced_at     datetime     null,
  primary key (broker, as_of_date, broker_ticker),
  key (symbol)
);

-- ---------------------------------------------------------------------------
-- The history: orders and dividends.
-- ---------------------------------------------------------------------------

-- broker_order_id is Trading 212's own id and the dedupe key. A re-run of the
-- sync inserts nothing: the same order read twice is one order.
create table if not exists broker_orders (
  broker          varchar(20)  not null,
  broker_order_id varchar(40)  not null,
  broker_ticker   varchar(40)  null,
  symbol          varchar(50)  null,
  side            varchar(10)  null,
  quantity        decimal(18,8) null,
  fill_price      decimal(16,4) null,
  fill_value      decimal(16,4) null,
  status          varchar(30)  null,
  order_type      varchar(30)  null,
  placed_at       datetime     null,
  filled_at       datetime     null,
  price_unit      varchar(10)  null,
  first_seen      datetime     null,
  primary key (broker, broker_order_id),
  key (symbol),
  key (filled_at)
);

create table if not exists broker_dividends (
  broker             varchar(20)  not null,
  broker_dividend_id varchar(40)  not null,
  broker_ticker      varchar(40)  null,
  symbol             varchar(50)  null,
  amount             decimal(16,4) null,
  amount_per_share   decimal(16,6) null,
  quantity           decimal(18,8) null,
  paid_on            datetime     null,
  dividend_type      varchar(30)  null,
  first_seen         datetime     null,
  primary key (broker, broker_dividend_id),
  key (symbol),
  key (paid_on)
);

-- ---------------------------------------------------------------------------
-- The runs.
-- ---------------------------------------------------------------------------

-- What the page reads to say when it last synced and what happened. A failed
-- run is a row too: "it has not synced since Tuesday" is only answerable if
-- the attempts are recorded, not just the successes.
--
-- problems holds the sentences a person can act on - a 403 from a key without
-- the permission, a field the response did not carry, a price unit that could
-- not be decided. Never a key, a secret or an Authorization header: see
-- t212_mask().
create table if not exists broker_sync_runs (
  run_id        int auto_increment primary key,
  broker        varchar(20)  not null,
  environment   varchar(20)  null,
  started_at    datetime     null,
  finished_at   datetime     null,
  status        varchar(20)  null,
  as_of_date    date         null,
  price_unit    varchar(10)  null,
  positions     int          null,
  cash          decimal(16,4) null,
  new_orders    int          null,
  new_dividends int          null,
  instruments   int          null,
  unmatched     int          null,
  requests      int          null,
  problems      text         null,
  notices       text         null,
  key (broker, started_at)
);

select 'broker tables' thing,
       (select count(*) from information_schema.tables
         where table_schema=database()
           and table_name in ('broker_instruments','broker_account_values',
                              'broker_positions','broker_orders',
                              'broker_dividends','broker_sync_runs')) present;
