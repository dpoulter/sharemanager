-- What a Trading 212 key can read, and whether the order book could be seen.
--
--   mysql <database> < sql/broker_key_scopes.sql
--
-- On 19 Nov 2026 thirteen live orders filled at the broker and this
-- application never recorded them, because the key that reads the order
-- book answered 403 and nothing said so. The orders stayed SENT, the
-- strategy halted "without being resolved", and nobody could tell from the
-- halt that the cause was a permission rather than a broker that was slow.
-- Two small tables keep that fact where the senders and the pages can read
-- it.
--
-- BROKER_KEY_SCOPES: one row per environment (demo is not checked; live-read
-- and live-orders are), written by includes/check_key_scopes.php at the start
-- of every broker run. orders_status and history_status are the HTTP status
-- of the two GETs the resolve step relies on; lacks says which of them the
-- key was refused, and words is the sentence the Strategies page prints.
-- Never a key, a secret or an Authorization header.
--
-- BROKER_ORDER_BOOK_STATE: one row per strategy, written by
-- broker_resolve_orders() each time it reads the book. blind is Y when the
-- pending-orders or the order-history call was refused with 401 or 403, so
-- the sender that runs after it (and the stuck-order check inside it) can say
-- that the orders may have filled instead of saying nothing about why.
--
-- Safe to apply twice: both are create-if-absent and nothing is altered.

create table if not exists broker_key_scopes (
  environment    varchar(20)  not null primary key,
  checked_at     datetime     null,
  orders_status  int          null,
  history_status int          null,
  lacks          varchar(60)  null,
  words          varchar(255) null
);

create table if not exists broker_order_book_state (
  strategy       varchar(50)  not null primary key,
  environment    varchar(20)  null,
  blind          char(1)      not null default 'N',
  missing        varchar(120) null,
  checked_at     datetime     null
);

select 'key scope tables' thing,
       (select count(*) from information_schema.tables
         where table_schema=database()
           and table_name in ('broker_key_scopes','broker_order_book_state')) present;
