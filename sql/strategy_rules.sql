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
  updated_at      datetime not null
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
       null, 15, 'monthly', '', 'Y', now(),
       'Monthly. No exits between rebalances: the screen is the whole signal.'
 where not exists (select 1 from strategy_rules where strategy = 'trending_value')
   and exists (select 1 from screen where name = 'Trending Value');

-- Trend following: hold what is above its 200 day average, ranked by
-- momentum, rebalanced weekly, and leave a holding that closes below the
-- average twice in a row rather than waiting for the week to end.
insert into strategy_accounts (strategy, cash, currency, mode, enabled, created_at, note)
select 'trend_following', 100000, 'GBP', 'PAPER', 'Y', now(),
       'Paper. Above the 200 day average, weekly.'
 where not exists (select 1 from strategy_accounts where strategy = 'trend_following');

insert into strategy_rules (strategy, selection, screen_id, rank_by, holdings,
                            rebalance, exit_rules, exclude_funds, updated_at, note)
select 'trend_following', 'screen',
       (select id from screen where name = 'Trend following (200-day)'),
       'momentum_score', 15, 'weekly', 'below_ma200_2d', 'Y', now(),
       'Weekly. Sells a holding that closes below its 200 day average twice.'
 where not exists (select 1 from strategy_rules where strategy = 'trend_following')
   and exists (select 1 from screen where name = 'Trend following (200-day)');

-- Converge both rows on the intended configuration, so a re-run after
-- somebody has experimented puts them back. screen_id is set from the name
-- here as well: a database where the screen was created after this migration
-- first ran would otherwise keep a null.
update strategy_rules
   set selection = 'screen',
       screen_id = ifnull((select id from screen where name = 'Trending Value'), screen_id),
       score_indicator = null,
       rank_by = null,
       holdings = 15,
       rebalance = 'monthly',
       exit_rules = '',
       exclude_funds = 'Y',
       updated_at = now()
 where strategy = 'trending_value';

update strategy_rules
   set selection = 'screen',
       screen_id = ifnull((select id from screen where name = 'Trend following (200-day)'), screen_id),
       score_indicator = null,
       rank_by = 'momentum_score',
       holdings = 15,
       rebalance = 'weekly',
       exit_rules = 'below_ma200_2d',
       exclude_funds = 'Y',
       updated_at = now()
 where strategy = 'trend_following';

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
