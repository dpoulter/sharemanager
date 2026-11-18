-- Which account a broker row came from.
--
-- SM_T212_ENV named one environment, so the application read one account at
-- a time and every broker_* row was implicitly "whichever account was
-- configured when the sync ran". Two accounts are read now - the practice
-- one and the live one - so a row has to say which, or a position Dale holds
-- himself is indistinguishable from one the practice strategy bought.
--
-- The value is the ACCOUNT, not the key: 'demo' or 'live'. Both live
-- environments - the read-only key and the one that can trade - read the
-- same account and store 'live'.
--
-- Existing rows are attributed from broker_sync_runs: each row is stamped
-- with the environment of the run that was in flight when it was first seen.
-- That is exact for the order and dividend history, which carries
-- first_seen, and for the positions and the values, which carry synced_at.
-- Where there is nothing to go on, the row is stamped from the latest run,
-- and the report at the end says how many of each there are so that anybody
-- deploying can see what was assumed.
--
-- Safe to apply twice.

-- ---------------------------------------------------------------------------
-- The column, on each of the five tables plus the run log.
-- ---------------------------------------------------------------------------

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'broker_account_values'
                   and column_name = 'environment') = 0,
               "alter table broker_account_values
                  add column environment varchar(10) not null default 'demo' after broker,
                  drop primary key,
                  add primary key (broker, environment, as_of_date)",
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'broker_positions'
                   and column_name = 'environment') = 0,
               "alter table broker_positions
                  add column environment varchar(10) not null default 'demo' after broker,
                  drop primary key,
                  add primary key (broker, environment, as_of_date, broker_ticker)",
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'broker_orders'
                   and column_name = 'environment') = 0,
               "alter table broker_orders
                  add column environment varchar(10) not null default 'demo' after broker,
                  drop primary key,
                  add primary key (broker, environment, broker_order_id)",
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'broker_dividends'
                   and column_name = 'environment') = 0,
               "alter table broker_dividends
                  add column environment varchar(10) not null default 'demo' after broker,
                  drop primary key,
                  add primary key (broker, environment, broker_dividend_id)",
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

-- The instrument list is read per account as well. The same share has the
-- same ticker in both, but this application only knows what it asked for,
-- and "the practice account lists it" is not evidence about the live one.
set @sql := if((select count(*) from information_schema.columns
                 where table_schema = database() and table_name = 'broker_instruments'
                   and column_name = 'environment') = 0,
               "alter table broker_instruments
                  add column environment varchar(10) not null default 'demo' after broker,
                  drop primary key,
                  add primary key (broker, environment, broker_ticker)",
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

-- One share, one instrument - per account now.
set @sql := if((select count(*) from information_schema.statistics
                 where table_schema = database() and table_name = 'broker_instruments'
                   and index_name = 'uq_broker_symbol') > 0,
               'alter table broker_instruments drop index uq_broker_symbol',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

set @sql := if((select count(*) from information_schema.statistics
                 where table_schema = database() and table_name = 'broker_instruments'
                   and index_name = 'broker_env_symbol') = 0,
               'alter table broker_instruments
                  add unique key broker_env_symbol (broker, environment, symbol)',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

-- ---------------------------------------------------------------------------
-- What the runs were.
-- ---------------------------------------------------------------------------

-- broker_sync_runs already records which environment ran. Its values become
-- the account label, so one word means one thing everywhere: 'live-read'
-- read the live account, and so will 'live-orders'.
update broker_sync_runs set environment = 'live'
 where environment in ('live-read', 'live-orders');

-- ---------------------------------------------------------------------------
-- Attributing the rows that are already there.
-- ---------------------------------------------------------------------------

set @latest := ifnull((select r.environment from broker_sync_runs r
                        order by r.run_id desc limit 1), 'demo');

update broker_orders o
   set o.environment = ifnull((select r.environment from broker_sync_runs r
                                where r.broker = o.broker
                                  and o.first_seen is not null
                                  and r.started_at <= o.first_seen
                                order by r.started_at desc limit 1), @latest)
 where o.environment = 'demo';

update broker_dividends d
   set d.environment = ifnull((select r.environment from broker_sync_runs r
                                where r.broker = d.broker
                                  and d.first_seen is not null
                                  and r.started_at <= d.first_seen
                                order by r.started_at desc limit 1), @latest)
 where d.environment = 'demo';

update broker_positions p
   set p.environment = ifnull((select r.environment from broker_sync_runs r
                                where r.broker = p.broker
                                  and p.synced_at is not null
                                  and r.started_at <= p.synced_at
                                order by r.started_at desc limit 1), @latest)
 where p.environment = 'demo';

update broker_account_values v
   set v.environment = ifnull((select r.environment from broker_sync_runs r
                                where r.broker = v.broker
                                  and v.synced_at is not null
                                  and r.started_at <= v.synced_at
                                order by r.started_at desc limit 1), @latest)
 where v.environment = 'demo';

-- The instruments are a cache that the next sync rewrites, so they are
-- stamped with whatever read them last rather than reasoned about.
update broker_instruments set environment = @latest where environment = 'demo';

-- ---------------------------------------------------------------------------
-- What was applied, and what it assumed.
-- ---------------------------------------------------------------------------

select 'broker_orders' as what, environment, count(*) rows_now
  from broker_orders group by environment
union all
select 'broker_dividends', environment, count(*) from broker_dividends group by environment
union all
select 'broker_positions', environment, count(*) from broker_positions group by environment
union all
select 'broker_account_values', environment, count(*) from broker_account_values group by environment
union all
select 'broker_instruments', environment, count(*) from broker_instruments group by environment
union all
select 'broker_sync_runs', environment, count(*) from broker_sync_runs group by environment;
