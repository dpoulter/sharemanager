-- The data_import module (includes/data_import.php): the Mac mini's nightly
-- publish of prices and the statistics it owns.
--
--   mysql <database> < sql/data_import.sql
--
-- data_import_batches     one row per batch received, by publish
-- data_import_prices      staged prices: nothing reads these but `close`
-- data_import_statistics  staged statistics: likewise
-- data_publishes          one row per publish made live: the data version,
--                         when, the rows, the symbols missing, the report
--
-- A publish that never closes leaves its rows here and nowhere else, so the
-- strategies (which read historical_prices and statistics) never see it.
--
-- Safe to apply twice.

create table if not exists data_import_batches (
  publish_id   varchar(64) not null,
  seq          int not null,
  price_rows   int not null,
  stat_rows    int not null,
  received_at  datetime not null,
  primary key (publish_id, seq)
);

create table if not exists data_import_prices (
  publish_id  varchar(64) not null,
  symbol      varchar(50) not null,
  date        date not null,
  price       decimal(14,4) not null,
  primary key (publish_id, symbol, date)
);

create table if not exists data_import_statistics (
  publish_id  varchar(64) not null,
  symbol      varchar(50) not null,
  date        date not null,
  indicator   varchar(50) not null,
  value       decimal(20,6) not null,
  stat_rank   int null,
  percentile  decimal(10,4) null,
  primary key (publish_id, symbol, indicator, date)
);

create table if not exists data_publishes (
  id                   int auto_increment primary key,
  publish_id           varchar(64) not null,
  data_version         int not null,
  full_publish         char(1) not null default 'N',
  latest_date          date null,
  closed_at            datetime not null,
  batches              int not null,
  price_rows           int not null,
  statistic_rows       int not null,
  prices_replaced      int not null,
  statistics_replaced  int not null,
  symbols_sent         int not null,
  symbols_missing      text null,
  report               mediumtext null,
  unique key (publish_id),
  key (latest_date)
);
