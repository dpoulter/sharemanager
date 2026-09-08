-- Paper execution layer.
--
-- Deliberately separate from the manual portfolio (shares, purchases,
-- users.cash) so a strategy run can never touch hand-entered holdings, and so
-- paper and real can be compared side by side.
--
-- The design goal is that a run which dies halfway is safe to re-run:
--   * every order carries a deterministic client_order_id, unique in the table
--   * the order row is written PENDING before the broker is called
--   * a re-run finds the existing row and resumes it instead of placing again
-- Nothing here is idempotent by luck; it is idempotent because the id is
-- derived from (strategy, as_of_date, symbol, side) and the table refuses
-- duplicates.

drop table if exists strategy_orders, strategy_targets, strategy_positions, strategy_accounts;

-- One paper account per strategy.
create table strategy_accounts (
  strategy    varchar(50) not null primary key,
  cash        decimal(20,4) not null,
  currency    char(3) not null default 'GBP',
  mode        varchar(10) not null default 'PAPER',   -- PAPER or LIVE; nothing supports LIVE yet
  enabled     char(1) not null default 'Y',
  created_at  datetime not null,
  note        varchar(255)
);

-- What the signal wants to hold. Written by generate_targets.php, read by the
-- executor. The signal never places orders and never sees positions.
create table strategy_targets (
  strategy      varchar(50) not null,
  as_of_date    date not null,
  symbol        varchar(50) not null,
  exchange      varchar(10) not null,
  target_weight decimal(9,6) not null,     -- fraction of account value, 0..1
  score         decimal(20,6),             -- the signal value, kept for audit
  created_at    datetime not null,
  primary key (strategy, as_of_date, symbol)
);

-- Current paper holdings.
create table strategy_positions (
  strategy   varchar(50) not null,
  symbol     varchar(50) not null,
  exchange   varchar(10) not null,
  quantity   int not null,
  avg_price  decimal(14,4) not null,       -- average cost including costs
  updated_at datetime not null,
  primary key (strategy, symbol)
);

-- The audit trail. Every intent is recorded here before anything happens, and
-- nothing is ever deleted: a rejected or cancelled order keeps its row.
create table strategy_orders (
  client_order_id varchar(100) not null primary key,
  strategy        varchar(50) not null,
  as_of_date      date not null,
  symbol          varchar(50) not null,
  exchange        varchar(10) not null,
  side            varchar(4) not null,       -- BUY or SELL
  quantity        int not null,
  status          varchar(10) not null,      -- PENDING, FILLED, REJECTED, CANCELLED
  fill_date       date,
  fill_price      decimal(14,4),             -- price per share before costs
  consideration   decimal(20,4),             -- quantity * fill_price
  commission      decimal(14,4) default 0,
  stamp_duty      decimal(14,4) default 0,
  slippage        decimal(14,4) default 0,
  total_cost      decimal(20,4),             -- signed cash effect including costs
  created_at      datetime not null,
  filled_at       datetime,
  note            varchar(255),
  key (strategy, as_of_date),
  key (strategy, status)
);
