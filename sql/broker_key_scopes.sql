-- What a Trading 212 key can read, and whether the order book could be seen.
--
--   mysql <database> < sql/broker_key_scopes.sql
--
-- On 19 Nov 2026 thirteen live orders filled at the broker and this
-- application never recorded them, because the key that reads the order
-- book answered 403 and nothing said so. The orders stayed SENT, the
-- strategy halted "without being resolved", and nobody could tell from the
-- halt that the cause was a permission rather than a broker that was slow.
-- One small table keeps that fact where the senders can read it.
--
-- BROKER_ORDER_BOOK_STATE: one row per strategy, written by
-- broker_resolve_orders() each time it reads the book. blind is Y when the
-- pending-orders or the order-history call was refused with 401 or 403, so
-- the sender that runs after it (and the stuck-order check inside it) can say
-- that the orders may have filled instead of saying nothing about why.
--
-- Safe to apply twice: create-if-absent, nothing is altered. (An earlier version
-- of this file also created broker_key_scopes, which nothing uses any more.)

create table if not exists broker_order_book_state (
  strategy       varchar(50)  not null primary key,
  environment    varchar(20)  null,
  blind          char(1)      not null default 'N',
  missing        varchar(120) null,
  checked_at     datetime     null
);

select 'order book state table' thing,
       (select count(*) from information_schema.tables
         where table_schema=database()
           and table_name = 'broker_order_book_state') present;
