-- The account summary, as Trading 212 really answers it.
--
--   mysql <database> < sql/broker_account_fields.sql
--
-- The first live sync stored a correct total of GBP250.00 beside a blank
-- cash balance, because the response nests and the reader only knew about
-- top-level keys:
--
--   {"id":...,"currency":"GBP","totalValue":250.00,
--    "cash":{"availableToTrade":250.00,"reservedForOrders":0,"inPies":0},
--    "investments":{"currentValue":0,"totalCost":0,
--                   "realizedProfitLoss":0,"unrealizedProfitLoss":0}}
--
-- cash.availableToTrade is the cash and investments.currentValue is the
-- invested value; the other five figures are worth keeping now they are
-- known, rather than being thrown away and missed later.
--
-- free and result go. free was a second name for the cash, and result was a
-- guess at a field that turns out to be investments.unrealizedProfitLoss,
-- which now has a column of its own. Nothing had ever been written to
-- either but nulls.
--
-- A notices column for broker_sync_runs: a run that could not match six
-- instruments has something worth saying and nothing wrong with its data.
-- Counting that as a problem made every run FAILED.
--
-- Safe to apply twice.

alter table broker_account_values
  add column if not exists reserved      decimal(16,4) null after cash,
  add column if not exists in_pies       decimal(16,4) null after reserved,
  add column if not exists invested_cost decimal(16,4) null after invested,
  add column if not exists realised      decimal(16,4) null after invested_cost,
  add column if not exists unrealised    decimal(16,4) null after realised;

alter table broker_account_values drop column if exists free;
alter table broker_account_values drop column if exists result;

alter table broker_sync_runs
  add column if not exists notices text null after problems;

-- shortName, so the page can say which name a holding was matched on, and
-- so a rename is visible rather than only its effect.
alter table broker_instruments
  add column if not exists short_name varchar(60) null after symbol;

select 'broker account fields' thing,
       (select count(*) from information_schema.columns
         where table_schema=database() and table_name='broker_account_values'
           and column_name in ('reserved','in_pies','invested_cost','realised','unrealised')) added,
       (select count(*) from information_schema.columns
         where table_schema=database() and table_name='broker_account_values'
           and column_name in ('free','result')) removed;
