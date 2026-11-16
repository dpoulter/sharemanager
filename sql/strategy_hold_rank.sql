-- Hold a name until it drops out of the top N, rather than out of the book.
--
-- The first backtest of trend following returned +62.8% against a benchmark
-- of +48.3% - and paid GBP53,553 in dealing costs on GBP100,000 of starting
-- capital. Weekly re-ranking churns the fifteen names: a holding that slips
-- from fifteenth to sixteenth is sold and bought back a week later, and the
-- commission, the stamp duty and the spread are paid both ways each time.
--
-- hold_rank is the buffer. A holding is kept while its rank among the
-- selection is at or above it, and replaced only when it falls below, leaves
-- the screen, or an exit fires. Null is the old behaviour: only the top
-- `holdings` are kept.
--
-- Safe to apply twice.

set @have := (select count(*) from information_schema.columns
               where table_schema = database()
                 and table_name   = 'strategy_rules'
                 and column_name  = 'hold_rank');

set @sql := if(@have = 0,
               'alter table strategy_rules add column hold_rank int null after holdings',
               'select 1');
prepare stmt from @sql; execute stmt; deallocate prepare stmt;

-- Left null for both live strategies: Jarvis is running the backtests that
-- decide what it should be, with the --hold-rank override, before anything
-- here changes.

select strategy, holdings, hold_rank, rebalance, rank_by from strategy_rules
 order by strategy;
