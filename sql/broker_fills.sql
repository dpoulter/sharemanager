-- What a fill actually cost, from the broker's own figures.
--
--   mysql <database> < sql/broker_fills.sql
--
-- The first orders on the practice account filled and this application could
-- not read them back: the order history nests the order inside an envelope
-- with its fill beside it, and the positions nest the instrument and the
-- money, the same way the account summary nested the cash. The field map
-- reads those paths now; these columns are what there was nowhere to put.
--
--   taxes         the stamp duty Trading 212 charged and itemised, which is
--                 not 0.5% worked out here: B&M is Jersey registered and was
--                 charged nothing at all, and a figure this application
--                 calculated would have invented GBP 1.65 of tax on it.
--   net_value     the cash that actually moved, taxes and all. The fill
--                 price times the quantity is the consideration, not the
--                 cash.
--   cost          what a holding cost in total, from walletImpact.totalCost,
--                 so the gain on the page is the broker's own gain rather
--                 than one worked out from an average price.
--
-- Safe to apply twice.

alter table broker_orders
  add column if not exists taxes     decimal(16,4) null after fill_value,
  add column if not exists net_value decimal(16,4) null after taxes;

alter table broker_positions
  add column if not exists cost decimal(16,4) null after value;

select 'broker fills' thing,
       (select count(*) from information_schema.columns
         where table_schema=database() and table_name='broker_orders'
           and column_name in ('taxes','net_value')) order_columns,
       (select count(*) from information_schema.columns
         where table_schema=database() and table_name='broker_positions'
           and column_name='cost') position_columns;
