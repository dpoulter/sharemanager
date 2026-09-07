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
  stock_info;

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
  exchange varchar(10),
  enabled  char(1),
  sector   varchar(100),
  industry varchar(100),
  key (symbol, exchange)
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
