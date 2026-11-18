-- Live orders, stage B: arming, approval, the caps and the log.
--
--   mysql <database> < sql/strategy_live.sql
--
-- Stage A built a T212_LIVE account that plans its orders and cannot send
-- one. This file builds what has to be in place before sending can exist at
-- all, and none of it sends anything either: the conditions an order will
-- have to satisfy, and the record of who allowed it.
--
-- Four things.
--
-- ARMING. strategy_live_arming is the row that says a live account may
-- trade: who armed it, when, the capital cap they set, and when it expires.
-- An account with no live row is not armed, which is the state of every
-- account the moment this file is applied and the state it returns to when
-- the row expires, is disarmed, or the strategy halts. Arming is done from
-- the strategy's page, with the app password typed again and the strategy's
-- name typed in full; nothing else writes here - see
-- includes/broker_live.php.
--
-- APPROVAL. A live account's orders are planned into a batch
-- (strategy_order_batches, one per strategy per night) and the batch starts
-- UNAPPROVED. Dale approves or rejects it on the page; an unapproved batch
-- expires at the close and is recorded as expired rather than sent, and the
-- next night plans afresh. strategy_orders.batch carries which batch an
-- order belongs to, so "was this order approved" is a question with an
-- answer years later.
--
-- SENDING WITHOUT ASKING. Per account, approval can be switched off, with
-- the password, by somebody who is logged in. Default on, and the column
-- records who switched it and when.
--
-- THE LOG. strategy_live_log holds every arm, disarm, approval, rejection,
-- expiry, refused cap and live order. The application only ever inserts
-- into it: there is no code path that deletes or updates a row, and the
-- suite checks that there is none.
--
-- Safe to apply twice.

-- ---------------------------------------------------------------------------
-- Arming.
-- ---------------------------------------------------------------------------

create table if not exists strategy_live_arming (
  id            int unsigned not null auto_increment primary key,
  strategy      varchar(50)  not null,
  -- The username, not the id: the page says "Armed by dale", and a user row
  -- that is later renamed must not rewrite what the record says happened.
  armed_by      varchar(50)  not null,
  armed_user_id int          null,
  armed_at      datetime     not null,
  -- What this strategy may ever have invested plus reserved. At most
  -- T212_LIVE_CAPITAL_CAP_MAX, which is a literal in constants.php.
  capital_cap   decimal(12,2) not null,
  expires_at    datetime     not null,
  disarmed_at   datetime     null,
  disarmed_by   varchar(50)  null,
  reason        varchar(255) null,
  key strategy_live_arming_strategy (strategy, disarmed_at),
  key strategy_live_arming_expires (expires_at)
) engine=InnoDB default charset=utf8mb4;

-- ---------------------------------------------------------------------------
-- Approval.
-- ---------------------------------------------------------------------------

create table if not exists strategy_order_batches (
  batch       varchar(100) not null primary key,
  strategy    varchar(50)  not null,
  as_of_date  date         not null,
  -- UNAPPROVED, APPROVED, REJECTED, EXPIRED.
  status      varchar(12)  not null default 'UNAPPROVED',
  created_at  datetime     not null,
  decided_at  datetime     null,
  decided_by  varchar(50)  null,
  reason      varchar(255) null,
  key strategy_order_batches_strategy (strategy, as_of_date),
  key strategy_order_batches_status (status)
) engine=InnoDB default charset=utf8mb4;

alter table strategy_orders
  add column if not exists batch varchar(100) null after as_of_date;

create index if not exists strategy_orders_batch on strategy_orders (batch);

-- Sending without asking, per account. 'Y' means every batch waits for an
-- approval; 'N' means Dale has said this account may send what it plans.
alter table strategy_accounts
  add column if not exists approval_required char(1) not null default 'Y',
  add column if not exists approval_set_by   varchar(50) null,
  add column if not exists approval_set_at   datetime    null;

-- ---------------------------------------------------------------------------
-- The log that is only ever added to.
-- ---------------------------------------------------------------------------

create table if not exists strategy_live_log (
  id       bigint unsigned not null auto_increment primary key,
  at       datetime     not null,
  strategy varchar(50)  not null,
  -- ARM, DISARM, APPROVE, REJECT, EXPIRE, APPROVAL_ON, APPROVAL_OFF,
  -- CAP_REFUSED, SENT, REFUSED.
  event    varchar(20)  not null,
  who      varchar(50)  null,
  detail   varchar(500) null,
  key strategy_live_log_strategy (strategy, at),
  key strategy_live_log_event (event, at)
) engine=InnoDB default charset=utf8mb4;

-- ---------------------------------------------------------------------------
-- What the run did.
-- ---------------------------------------------------------------------------

select table_name, 'exists' state
  from information_schema.tables
 where table_schema = database()
   and table_name in ('strategy_live_arming','strategy_order_batches','strategy_live_log')
 order by table_name;

select count(*) armed_rows from strategy_live_arming;

select strategy, mode, approval_required
  from strategy_accounts
 where mode = 'T212_LIVE'
 order by strategy;
