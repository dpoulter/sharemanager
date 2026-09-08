-- Test schema for the sharemanager regression suite.
--
-- IMPORTANT: this is a RECONSTRUCTION, inferred from the SQL in the PHP source,
-- not a dump of the production database. It exists so the batch jobs and the
-- dashboard can be exercised without a live database. Column types, lengths,
-- indexes and defaults are best guesses and will differ from production, so do
-- not use this to create or migrate a real database, and do not treat it as
-- authoritative DDL.
--
-- Only the tables the tests touch are defined. Anything the suite does not
-- exercise is absent.

drop table if exists historical_prices, stock_symbols, screen_indicators,
  statistics, statistic_averages, message_log, jobs, users, indicator_category,
  stock_info, strategy_orders, strategy_targets, strategy_positions,
  strategy_accounts, purchases, shares, dividends, cash_history, history,
  portfolio_performance, screen, screen_criteria, screen_build, price_momentum,
  strategy, strategy_shares, backtest_results, performance, price_valuation,
  health_indicators, variables, momentum_ratings, financial_statement_items,
  financial_statement_periods, financial_statement_values;

-- Daily prices, loaded by get_share_prices.php via share_functions.php:319.
-- The exchange column is written from $_SESSION["exchange"] ('XLON').
create table historical_prices (
  symbol   varchar(50),
  exchange varchar(10),
  date     date,
  price    decimal(14,4),
  key (symbol, exchange, date)
);

-- The tradeable universe. indicator_stats() and calc_momentum() filter on
-- enabled='Y' and exchange=$_SESSION["exchange"].
create table stock_symbols (
  symbol   varchar(50),
  name     varchar(255),
  description varchar(255),
  exchange varchar(10),
  enabled  char(1),
  sector   varchar(100),
  -- market, industry_group and logo are read by the quote page
  -- (share_functions.php get_share_info) and by scrape_logos.php. Nothing in
  -- the seeded pipeline writes them, which is why they were missed until a
  -- quote page was actually opened.
  market   varchar(100),
  industry_group varchar(100),
  industry varchar(100),
  -- the company profile tab on the quote page
  employees varchar(50),
  website   varchar(255),
  directors text,
  logo     varchar(255),
  key (symbol, exchange),
  -- search.php runs MATCH(symbol,description) AGAINST(...), which needs a
  -- FULLTEXT index over exactly those columns or MySQL raises error 1191.
  fulltext key (symbol, description)
);

-- Indicator definitions. screen_function names the PHP function indicator_stats
-- dispatches to. rank_order is an ORDER BY expression, not a direction:
-- get_statistics.php interpolates it directly, so 'DESC' alone is a syntax error.
-- name is the application's own key (written to statistics.indicator and hard
-- coded in the scoring SQL); provider_field is the data provider's name for the
-- same number, so changing provider is data rather than code.
create table screen_indicators (
  indicator_id    int auto_increment primary key,
  name            varchar(50),
  provider_field  varchar(100),
  description     varchar(255),
  enabled         char(1),
  order_number    int,
  screen_function varchar(100),
  yahoo_code      varchar(50),
  rank_zero       char(1) default 'N',
  calc_rank       char(1) default 'N',
  rank_order      varchar(30) default 'value DESC',
  category        int
);

-- Calculated indicator values, plus the *_score rows the dashboard reads.
create table statistics (
  symbol     varchar(50),
  date       date,
  indicator  varchar(50),
  value      decimal(20,6),
  exchange   varchar(10),
  stat_rank  int,
  percentile decimal(10,4),
  key (symbol, indicator, date, exchange)
);

create table statistic_averages (
  date      date,
  category  varchar(20),
  indicator varchar(50),
  type      varchar(20),
  value     decimal(20,6),
  sector    varchar(100),
  industry  varchar(100),
  exchange  varchar(10)
);

-- write_log()/debug_log() target. Note there is no rotation anywhere in the
-- application; the suite asserts on row counts here.
create table message_log (
  id           bigint auto_increment primary key,
  module       varchar(100),
  message_text varchar(4000),
  timestamp    datetime
);

-- Two distinct kinds of row live here:
--   job_name = '<script>'            written by log_job(), job_date = run time
--   job_name = 'get_statistics_asof' written by get_statistics.php, the as of
--                                    date the statistics were calculated for
-- Pages that display statistics must resolve the as of marker, not the run row.
create table jobs (
  id       int auto_increment primary key,
  job_name varchar(100),
  job_date datetime
);

create table users (
  id               int auto_increment primary key,
  username         varchar(50),
  hash             varchar(255),
  email            varchar(255),
  cash             decimal(20,4) default 10000,
  default_exchange varchar(10)
);

create table indicator_category (
  category_id int,
  name        varchar(50),
  description varchar(255),
  `order`     int
);

-- Flat landing table for fundamentals, written by the provider loader and read
-- by get_api_stats.py, which joins stock_info.attribute against
-- screen_indicators.provider_field (falling back to name).
create table stock_info (
  symbol   varchar(50),
  asofdate date,
  attribute varchar(50),
  value    varchar(255),
  key (symbol, asofdate, attribute)
);

-- Paper execution layer. See sql/paper_trading.sql for the annotated original;
-- these definitions must stay in step with it.
create table strategy_accounts (
  strategy varchar(50) not null primary key,
  cash decimal(20,4) not null,
  currency char(3) not null default 'GBP',
  mode varchar(10) not null default 'PAPER',
  enabled char(1) not null default 'Y',
  created_at datetime not null,
  note varchar(255)
);

create table strategy_targets (
  strategy varchar(50) not null,
  as_of_date date not null,
  symbol varchar(50) not null,
  exchange varchar(10) not null,
  target_weight decimal(9,6) not null,
  score decimal(20,6),
  created_at datetime not null,
  primary key (strategy, as_of_date, symbol)
);

create table strategy_positions (
  strategy varchar(50) not null,
  symbol varchar(50) not null,
  exchange varchar(10) not null,
  quantity int not null,
  avg_price decimal(14,4) not null,
  updated_at datetime not null,
  primary key (strategy, symbol)
);

create table strategy_orders (
  client_order_id varchar(100) not null primary key,
  strategy varchar(50) not null,
  as_of_date date not null,
  symbol varchar(50) not null,
  exchange varchar(10) not null,
  side varchar(4) not null,
  quantity int not null,
  status varchar(10) not null,
  fill_date date,
  fill_price decimal(14,4),
  consideration decimal(20,4),
  commission decimal(14,4) default 0,
  stamp_duty decimal(14,4) default 0,
  slippage decimal(14,4) default 0,
  total_cost decimal(20,4),
  created_at datetime not null,
  filled_at datetime,
  note varchar(255),
  key (strategy, as_of_date),
  key (strategy, status)
);

-- ---------------------------------------------------------------------------
-- Portfolio, screening and history. Reconstructed from the queries in public/
-- and includes/, same caveat as the rest of this file: inferred, not a dump.
-- ---------------------------------------------------------------------------

-- The trade ledger. buy.php and sell.php append here; templates/index.php
-- aggregates it into positions.
create table purchases (
  id            int auto_increment primary key,
  session_id    int,
  symbol        varchar(50),
  trx_type      varchar(10),          -- BUY or SELL
  shares        int,
  price_paid    decimal(14,4),
  commission    decimal(14,4) default 0,
  purchase_date date,
  key (session_id, symbol)
);

-- Current holdings per user. Note buy.php keys this on (id, symbol) where id is
-- the user id, so a user cannot hold two lots of the same symbol.
create table shares (
  id         int,
  symbol     varchar(50),
  shares     int,
  avg_cost   decimal(14,4),
  -- new_screen.php selects commission and price_paid from this table, but
  -- nothing in the application ever writes them: buy.php inserts only
  -- (id, symbol, shares). Included so the page renders; if the real shares
  -- table does not have these columns then new_screen.php is broken in
  -- production too, and this is the thing to check.
  commission decimal(14,4) default 0,
  price_paid decimal(14,4),
  primary key (id, symbol)
);

create table dividends (
  dividend_id   int auto_increment primary key,
  session_id    int,
  symbol        varchar(50),
  dividend_date date,
  amount        decimal(14,4),
  key (session_id, symbol)
);

create table cash_history (
  id               int auto_increment primary key,
  user_id          int,
  transaction_date date,
  trx_type         varchar(20),
  amount           decimal(20,4),
  key (user_id)
);

create table history (
  id        int auto_increment primary key,
  user_id   int,
  trx_type  varchar(10),
  symbol    varchar(50),
  quantity  int,
  price     decimal(14,4),
  timestamp datetime default current_timestamp,
  key (user_id)
);

-- Whole-portfolio daily totals, written by performance_function.php and read by
-- performance.php. Distinct from portfolio_performance, which is per symbol.
create table performance (
  id               int auto_increment primary key,
  session_id       int,
  performance_date date,
  total_value      decimal(20,4),
  total_profit     decimal(20,4),
  total_holding    decimal(20,4),
  cash             decimal(20,4),
  key (session_id, performance_date)
);

-- Daily valuation snapshots, written by performance_function.php.
create table portfolio_performance (
  id            int auto_increment primary key,
  session_id    int,
  as_of_date    date,
  symbol        varchar(50),
  active        char(1),
  exchange      varchar(10),
  qty_purchased int,
  qty_sold      int,
  price         decimal(14,4),
  price_paid    decimal(14,4),
  price_sold    decimal(14,4),
  commission    decimal(14,4) default 0,
  dividends     decimal(14,4) default 0,
  value         varchar(50),
  value_raw     decimal(20,4),
  profit        varchar(50),
  profit_raw    decimal(20,4),
  profit_perc   decimal(14,4),
  key (session_id, as_of_date)
);

-- Saved screens and their criteria.
create table screen (
  id          int auto_increment primary key,
  name        varchar(100),
  description varchar(255),
  displayed   char(1) default 'Y',
  session_id  int
);

create table screen_criteria (
  id             int auto_increment primary key,
  indicator_id   int,
  description    varchar(255),
  operator       varchar(20),
  first_operand  varchar(100),
  second_operand varchar(100)
);

create table screen_build (
  id          int auto_increment primary key,
  screen_id   int,
  criteria_id int,
  key (screen_id)
);

-- Legacy momentum table. Columns start with digits, so every reference has to
-- quote them; calc_momentum writes to statistics now and this is vestigial.
create table price_momentum (
  symbol       varchar(50) primary key,
  `3mnth`      decimal(14,4),
  `6mnth`      decimal(14,4),
  `12mnth`     decimal(14,4),
  tendayavg    decimal(14,4),
  thirtydayavg decimal(14,4),
  hndrddayavg  decimal(14,4),
  earnings_growth decimal(14,4)
);

create table strategy (
  strategy_id int auto_increment primary key,
  name        varchar(100),
  description varchar(255)
);

create table strategy_shares (
  id          int auto_increment primary key,
  symbol      varchar(50),
  status      varchar(20),
  session_id  int,
  strategy_id int,
  key (session_id, strategy_id)
);

create table backtest_results (
  id                 int auto_increment primary key,
  strategy_id        int,
  start_date         date,
  end_date           date,
  parameter1_name    varchar(50), parameter1_value varchar(50),
  parameter2_name    varchar(50), parameter2_value varchar(50),
  parameter3_name    varchar(50), parameter3_value varchar(50),
  parameter4_name    varchar(50), parameter4_value varchar(50),
  parameter5_name    varchar(50), parameter5_value varchar(50),
  parameter6_name    varchar(50), parameter6_value varchar(50),
  parameter7_name    varchar(50), parameter7_value varchar(50),
  parameter8_name    varchar(50), parameter8_value varchar(50),
  parameter9_name    varchar(50), parameter9_value varchar(50),
  sharpe_ratio       decimal(14,6),
  avg_daily_return   decimal(14,6),
  standard_deviation decimal(14,6)
);

-- Financial statements, shown on the quote page.
create table financial_statement_items (
  name        varchar(100),
  description varchar(255),
  type        varchar(50),
  order_number int,
  key (type)
);

create table financial_statement_periods (
  period_id   int auto_increment primary key,
  end_date    date,
  period_name varchar(50)
);

create table financial_statement_values (
  symbol    varchar(50),
  period_id int,
  item_name varchar(100),
  value     decimal(20,4),
  key (symbol, period_id, item_name)
);

-- ---------------------------------------------------------------------------
-- Read by the quote page. Written by the valuation and rating jobs, which the
-- sandbox does not run, so tools/sandbox_data.sql seeds them instead.
-- ---------------------------------------------------------------------------

create table price_valuation (
  symbol        varchar(50),
  date          date,
  indicator     varchar(50),
  share_stat    decimal(20,6),
  sector_stat   decimal(20,6),
  industry_stat decimal(20,6),
  type          varchar(20),
  value         decimal(20,6),
  key (symbol, date, type)
);

create table health_indicators (
  symbol   varchar(50),
  type     varchar(30),      -- piotroski_fscore, altman_zscore, ...
  date     date,
  variable varchar(50),
  value    decimal(20,6),
  key (symbol, type, date)
);

-- Lookup for the human readable name of a health_indicators.variable.
create table variables (
  name varchar(50),
  text varchar(255),
  key (name)
);

create table momentum_ratings (
  symbol          varchar(50),
  date            date,
  number          int,
  momentum_rating varchar(20),
  growth_rating   varchar(20),
  value_rating    varchar(20),
  quality_rating  varchar(20),
  overall_rating  varchar(20),
  key (symbol, date)
);
