-- Stage 2: the engine's orders, sent to Trading 212's DEMO account.
--
--   mysql <database> < sql/broker_trading.sql
--
-- DEMO ONLY. Nothing in this application sends an order to the live host,
-- and no setting can make it: the order function builds its URL from a
-- constant demo host and refuses to run unless SM_T212_ENV=demo. Real money
-- stays off until Dale decides, after at least three months of paper
-- results.
--
-- Three things change shape here.
--
-- FRACTIONAL QUANTITIES. The engine bought whole shares because it was
-- written for an account paying GBP 5.95 a trade, where a fraction of a
-- share is not worth the commission. Trading 212 charges none and deals from
-- GBP 1, so a GBP 250 account can hold all fifteen names at about GBP 16.67
-- each - but only if a quantity can be 0.44 of a share. int columns would
-- round that to nought and the account would hold nothing at all.
--
-- WHERE AN ORDER IS. A paper order is PENDING and then FILLED, because the
-- paper broker is this database. A real one is also SENT (it has gone and we
-- are waiting), UNKNOWN (the call timed out and we do not know whether it
-- arrived) and CANCELLED. UNKNOWN is the important one: the order calls are
-- not idempotent, so a request that timed out must never be sent again
-- blind - it is resolved against the broker's own records on the next run.
--
-- A STRATEGY CAN BE HALTED. If the engine's positions and the broker's
-- disagree, that strategy stops trading until a person has looked at it. A
-- disagreement about what is held is a disagreement about what everything is
-- worth, and trading through one compounds it.
--
-- Safe to apply twice.

-- ---------------------------------------------------------------------------
-- Fractional quantities.
-- ---------------------------------------------------------------------------

-- Eight decimal places, which is what the price columns already carry. The
-- broker's own precision is smaller and is applied when the order is sized;
-- a column that cannot hold what the broker accepts would be the wrong place
-- to find that out.
alter table strategy_orders    modify column quantity decimal(18,8) not null;
alter table strategy_positions modify column quantity decimal(18,8) not null;

-- ---------------------------------------------------------------------------
-- Where an order is.
-- ---------------------------------------------------------------------------

alter table strategy_orders
  add column if not exists broker          varchar(20) null after exchange,
  add column if not exists broker_order_id varchar(40) null after broker,
  add column if not exists broker_status   varchar(30) null after broker_order_id,
  add column if not exists sent_at         datetime    null after created_at,
  add column if not exists attempts        int         not null default 0 after sent_at;

-- PENDING, SENT, UNKNOWN, FILLED, REJECTED, CANCELLED.
alter table strategy_orders modify column status varchar(12) not null;

-- One broker order is one of ours. A second row claiming the same broker
-- order id would be this application double-counting a fill.
alter table strategy_orders
  add unique key if not exists uq_broker_order (broker, broker_order_id);

-- ---------------------------------------------------------------------------
-- The account.
-- ---------------------------------------------------------------------------

-- PAPER or T212_DEMO. Long enough for a third mode to be a word rather than
-- an abbreviation.
alter table strategy_accounts modify column mode varchar(20) not null default 'PAPER';

alter table strategy_accounts
  add column if not exists halted      char(1)      not null default 'N' after enabled,
  add column if not exists halt_reason varchar(255) null after halted,
  add column if not exists halted_at   datetime     null after halt_reason;

-- ---------------------------------------------------------------------------
-- What the broker will accept.
-- ---------------------------------------------------------------------------

-- Whether this instrument takes a fraction of a share. Null means nobody has
-- found out; 'N' is set when the broker refuses an order for its quantity,
-- so the next order for that share is sized in whole shares instead. Learnt
-- from the broker rather than guessed at: the instruments list carries no
-- field that says.
alter table broker_instruments
  add column if not exists whole_shares char(1) null after venue,
  add column if not exists max_quantity decimal(18,8) null after whole_shares;

select 'broker trading' thing,
       (select column_type from information_schema.columns
         where table_schema=database() and table_name='strategy_orders'
           and column_name='quantity') order_quantity,
       (select count(*) from information_schema.columns
         where table_schema=database() and table_name='strategy_accounts'
           and column_name in ('halted','halt_reason','halted_at')) halt_columns;
