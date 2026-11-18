-- Strategy definitions as configuration, and the daily valuation they are
-- measured by.
--
--   mysql <database> < sql/strategy_rules.sql
--
-- The paper engine already knew how to reconcile, plan, place and fill
-- (sql/paper_trading.sql, includes/strategy_functions.php). What it did not
-- know was what to hold: generate_targets.php could only ask for "the best N
-- by overall_score". These two tables are the answer to that - what to select,
-- how many, when to rebalance, and when to leave before the next rebalance -
-- so a strategy is a row rather than a release.
--
-- PAPER ONLY. Nothing here, and nothing that reads it, can place a real
-- order: strategy_accounts.mode is checked against 'PAPER' before any fill
-- and no code path supports 'LIVE'.
--
-- What is deliberately NOT configuration: the engine's limits (maximum
-- position weight, orders per run, turnover, minimum order value) and the
-- dealing costs. They stay constants in includes/strategy_functions.php, so a
-- bad row cannot widen them and a change shows up in a diff.
--
-- Re-runnable: every statement is "if not exists", an insert guarded by
-- "where not exists", or an update that sets the same values again. Screens
-- are matched by name, never by id - ids differ between databases, and the
-- brief's "screen 4" is only screen 4 on the N100.

-- ---------------------------------------------------------------------------
-- What each strategy holds.
-- ---------------------------------------------------------------------------

create table if not exists strategy_rules (
  strategy        varchar(50) not null primary key,
  -- 'screen' (with screen_id) or 'score' (with score_indicator)
  selection       varchar(10) not null default 'score',
  screen_id       int,
  score_indicator varchar(50),
  -- How to pick and order when the selection gives more names than holdings.
  -- Null means "the order the selection returned", which for a screen whose
  -- last criterion is a top_n is already a ranking.
  rank_by         varchar(50),
  holdings        int not null default 15,
  -- 'monthly' (first as of date of the month), 'weekly' (first of the week)
  -- or 'daily'
  rebalance       varchar(10) not null default 'monthly',
  -- Comma separated rule names, checked every night between rebalances.
  -- Empty means the strategy only ever changes at a rebalance.
  exit_rules      varchar(255) not null default '',
  exclude_funds   char(1) not null default 'Y',
  -- The first as of date it may trade. Null means "whenever it next runs",
  -- which is what a strategy created by this migration wants: it starts on
  -- the first nightly run after deploy rather than waiting for a month
  -- boundary.
  start_date      date,
  note            varchar(255),
  -- When the rules last changed. Defaulted and kept by the server, so an
  -- insert that does not know to pass now() is not rejected outright and an
  -- edit through any path stamps itself. sql/strategy_rules_updated_at.sql
  -- is the same change for a database created before this line.
  updated_at      datetime not null
                  default current_timestamp on update current_timestamp
);

-- ---------------------------------------------------------------------------
-- What each strategy was worth, every night.
-- ---------------------------------------------------------------------------
--
-- Written by includes/run_strategies.php after the orders are in, one row per
-- strategy per as of date, so the page can draw a line rather than a number.
-- The benchmark lives in the same table under a reserved name (see
-- STRATEGY_BENCHMARK in includes/strategy_functions.php): it is the same
-- shape of fact, and keeping it here means the page reads one table and the
-- comparison can never be against a different period.

create table if not exists strategy_values (
  strategy       varchar(50) not null,
  date           date not null,
  cash           decimal(20,4) not null,
  holdings_value decimal(20,4) not null,
  total          decimal(20,4) not null,
  holdings       int not null,
  note           varchar(255),
  primary key (strategy, date),
  key (date)
);

-- ---------------------------------------------------------------------------
-- The two strategies.
-- ---------------------------------------------------------------------------

-- Trending Value: the cheapest names by the six value measures, then the best
-- of those by six month momentum, rebalanced monthly and held through
-- whatever happens in between. The screen already says all of that
-- (sql/trending_value.sql), so the rule is "hold screen 'Trending Value'".
insert into strategy_accounts (strategy, cash, currency, mode, enabled, created_at, note)
select 'trending_value', 100000, 'GBP', 'PAPER', 'Y', now(),
       'Paper. O''Shaughnessy Trending Value, monthly.'
 where not exists (select 1 from strategy_accounts where strategy = 'trending_value');

insert into strategy_rules (strategy, selection, screen_id, rank_by, holdings,
                            rebalance, exit_rules, exclude_funds, updated_at, note)
select 'trending_value', 'screen',
       (select id from screen where name = 'Trending Value'),
       '6mnth', 15, 'monthly', '', 'Y', now(),
       'Monthly, ranked by the six month return. No exits between rebalances:'
       ' the screen is the whole signal.'
 where not exists (select 1 from strategy_rules where strategy = 'trending_value')
   and exists (select 1 from screen where name = 'Trending Value');

-- Trend following: hold what is above its 200 day average, ranked by the six
-- month return, rebalanced weekly, and leave a holding that closes below the
-- average twice in a row rather than waiting for the week to end.
--
-- Ranked by 6mnth rather than by momentum_score, which is what this first
-- said. Two reasons, and the second is the one that decided it:
--
--   momentum_score is a composite of the ranks of the three momentum
--   indicators, so it is a statement about where a share sits in today's
--   cross-section. 6mnth is the share's own return over six months, which
--   is what "trending" means on its own terms;
--
--   momentum_score has only ever been stored on the monthly as of dates
--   get_momentum_statistics.php calculates, so a backtest over daily
--   history would find no value on almost every rebalance date, select
--   nothing, and draw a flat line that looked like a result. A six month
--   return is computable from prices alone, so it can be backfilled
--   honestly over the whole price history - see
--   includes/backfill_trend_statistics.php.
insert into strategy_accounts (strategy, cash, currency, mode, enabled, created_at, note)
select 'trend_following', 100000, 'GBP', 'PAPER', 'Y', now(),
       'Paper. Above the 200 day average, monthly.'
 where not exists (select 1 from strategy_accounts where strategy = 'trend_following');

insert into strategy_rules (strategy, selection, screen_id, rank_by, holdings,
                            rebalance, exit_rules, exclude_funds, updated_at, note)
select 'trend_following', 'screen',
       (select id from screen where name = 'Trend following (200-day)'),
       '6mnth', 15, 'monthly', 'below_ma200_2d', 'Y', now(),
       'Monthly. Best six month return of what is above its 200 day average.'
 where not exists (select 1 from strategy_rules where strategy = 'trend_following')
   and exists (select 1 from screen where name = 'Trend following (200-day)');

-- ---------------------------------------------------------------------------
-- What this file does NOT do any more.
-- ---------------------------------------------------------------------------
--
-- It used to converge both rows on the values above, on every run, so that
-- a strategy somebody had experimented on went back to what the file said.
-- That is a trap for whoever deploys. Dale decided on 16 Nov 2026 that both
-- strategies rebalance monthly, rank by 6mnth and hold to rank 30 and 26;
-- this file was in the 17 Nov deploy, was re-run as deploys re-run
-- migrations, and set trend_following back to weekly and trending_value's
-- rank_by back to null. Nothing ran in the minute before it was put back by
-- hand, which is the only reason it cost nothing.
--
-- A migration that silently reverts a live decision is worse than one that
-- does nothing, because the person who deploys has no reason to look. So:
-- the inserts above seed a database that has no row, and the only thing
-- touched on a row that already exists is a screen_id that is null, which is
-- a value nobody chose.
--
-- Changing a live strategy's rules is a decision, and a decision belongs in
-- an update written for that decision - a new file, run once, saying what it
-- changes and why - not in a file that runs again every time anybody
-- deploys.

update strategy_rules
   set screen_id = (select id from screen where name = 'Trending Value'),
       updated_at = now()
 where strategy = 'trending_value'
   and selection = 'screen'
   and screen_id is null
   and exists (select 1 from screen where name = 'Trending Value');

update strategy_rules
   set screen_id = (select id from screen where name = 'Trend following (200-day)'),
       updated_at = now()
 where strategy = 'trend_following'
   and selection = 'screen'
   and screen_id is null
   and exists (select 1 from screen where name = 'Trend following (200-day)');

-- ---------------------------------------------------------------------------
-- The account that was never used.
-- ---------------------------------------------------------------------------
--
-- momentum_top10 was written in September 2026 to exercise the engine and has
-- never run. Disabled rather than deleted: its row is the only record that
-- the engine was tested against a real signal, and an account with no orders
-- costs nothing to keep. Nothing selects a disabled account.

update strategy_accounts
   set enabled = 'N',
       note = concat(ifnull(note, ''), ' Disabled by sql/strategy_rules.sql: superseded by trending_value and trend_following.')
 where strategy = 'momentum_top10'
   and enabled = 'Y';

-- A rules row for it all the same, matching what generate_targets.php used to
-- do on its own, so the one account that predates this table is not the odd
-- one out and re-enabling it needs no code.
insert into strategy_rules (strategy, selection, score_indicator, rank_by, holdings,
                            rebalance, exit_rules, exclude_funds, updated_at, note)
select 'momentum_top10', 'score', 'overall_score', 'overall_score', 10,
       'monthly', '', 'Y', now(),
       'Disabled. What generate_targets.php did before strategy_rules existed.'
 where not exists (select 1 from strategy_rules where strategy = 'momentum_top10')
   and exists (select 1 from strategy_accounts where strategy = 'momentum_top10');

-- ---------------------------------------------------------------------------
-- What was applied.
-- ---------------------------------------------------------------------------

select r.strategy, r.selection, ifnull(s.name, r.score_indicator) as selects,
       r.rank_by, r.holdings, r.rebalance, r.exit_rules, r.exclude_funds,
       a.cash, a.mode, a.enabled
  from strategy_rules r
  left join screen s on s.id = r.screen_id
  left join strategy_accounts a on a.strategy = r.strategy
 order by r.strategy;
