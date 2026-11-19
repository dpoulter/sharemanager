-- What a Trading 212 key can read, and whether the last order book was blind.
--
-- 19 Nov 2026: the live key could place orders but was refused (403) on the
-- pending-orders and order-history reads. Thirteen filled orders stayed SENT,
-- and the strategy halted with a reason that did not mention the key. Two
-- small tables so that the next time says what is wrong.
--
--   broker_key_scopes      one row per environment (demo, live-read,
--                          live-orders): whether its key was refused the two
--                          reads the resolve step depends on. Written by
--                          includes/check_key_scopes.php at the start of each
--                          broker run; shown on the Strategies page.
--
--   broker_order_book_state  one row per strategy: whether the last time its
--                          orders were resolved, Trading 212 refused (401 or
--                          403) one of the two lists. Written by
--                          broker_resolve_orders(); read by stuck_check(),
--                          which runs in the sender after it.
--
-- Safe to apply twice: create table if not exists, nothing is dropped, no
-- existing table is changed.

create table if not exists broker_key_scopes (
  environment   varchar(20)  not null primary key,
  orders        varchar(14)  null,
  history       varchar(14)  null,
  orders_http   int          null,
  history_http  int          null,
  summary       varchar(200) null,
  checked_at    datetime     null
);

create table if not exists broker_order_book_state (
  strategy      varchar(50)  not null primary key,
  environment   varchar(20)  null,
  blind         char(1)      not null default 'N',
  missing       varchar(200) null,
  checked_at    datetime     null
);

select 'key scope tables' thing,
       (select count(*) from information_schema.tables
         where table_schema=database()
           and table_name in ('broker_key_scopes','broker_order_book_state')) present;
