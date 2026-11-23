-- Each strategy's own turnover limit (brief of 23 Nov 2026, partial turnover).
--
--   mysql <database> < sql/strategy_max_turnover.sql
--
-- strategy_plan_orders() refused a whole rebalance whose turnover,
-- min(buys, sells) / account value, was over STRATEGY_MAX_TURNOVER (0.50 for
-- every strategy), so a strategy whose targets change a lot froze. It now
-- trades the rebalance in part, up to this limit, and holds the rest back to
-- the next rebalance. The limit is the strategy's: 0.10 to 1.00, default
-- 0.50, the old constant, which is now the default only. Written by the New
-- strategy form (strategy_create.php) and by this default; nothing else.
--
-- At the default the limit is left out of the rules fingerprint, so every
-- existing strategy keeps its fingerprint and its backtests.
--
-- Re-runnable.

alter table strategy_rules
  add column if not exists max_turnover decimal(4,2) not null default 0.50 after hold_rank;

select strategy, max_turnover from strategy_rules order by strategy;
