-- What currency a share is quoted in, so the engine can size a trade in
-- pounds.
--
--   mysql <database> < sql/strategy_prices.sql
--
-- historical_prices holds London prices as the provider sends them, which for
-- a London line is PENCE. The manual portfolio has always known that -
-- portfolio_functions.php divides by 100 in half a dozen places - but the
-- paper engine did not, so it read 690p as 690 pounds and sized every trade a
-- hundred times too small:
--
--   BUY  EZJ    9 @ ~690      the engine thought 6,210 pounds; it was 62.10
--   BUY  SHEL   1 @ ~3719.5   the engine thought 3,719 pounds; it was 37.20
--
-- A 100,000 pound account therefore invested about 1,000 pounds, the 5.95
-- commission was a tenth of every order, the 250 pound minimum order was
-- really 25,000, and whole-share rounding on a "one share" position made the
-- weights meaningless. Every valuation, return and cost figure was wrong.
--
-- The fix is in includes/strategy_functions.php: strategy_price_asof() and
-- strategy_next_price() return POUNDS, and everything downstream - sizing,
-- fills, average cost, valuations, the page and the backtest - reads pounds
-- because it reads them. This file is the data half: which currency each
-- share is quoted in, so that "divide by 100" is a statement about pence
-- rather than about London.
--
-- Re-runnable.

-- ---------------------------------------------------------------------------
-- The quote currency.
-- ---------------------------------------------------------------------------
--
-- FMP's profile `currency` field: GBp or GBX for a pence-quoted London line,
-- which is nearly all of them, and GBP, USD or EUR for the rest. Written by
-- python/fetch_fmp_fundamentals.py, which already fetches the profile every
-- night for the fund flag, so this costs no extra call.
--
-- Not the same thing as report_ccy, which is what the company's ACCOUNTS are
-- in (sql/report_currency.sql). A company can report in dollars and be quoted
-- in pence - Shell is - and the two corrections are for different sums: one
-- fixes a ratio built from the accounts, this one fixes the price itself.
--
-- Null means "not looked up yet". The engine then assumes pence on XLON,
-- because that is what the exchange quotes in and what the rest of the
-- application already assumes; see strategy_quote_ccy().
alter table stock_symbols
  add column if not exists quote_ccy varchar(3) after report_ccy_asof;

-- When it was last looked up, so the loader can refresh a stale one rather
-- than asking every night about a figure that changes about never.
alter table stock_symbols
  add column if not exists quote_ccy_asof date after quote_ccy;

-- ---------------------------------------------------------------------------
-- Any orders stored before the fix are in the wrong unit.
-- ---------------------------------------------------------------------------
--
-- Deliberately NOT corrected here. On the N100 nothing had filled when this
-- was found - the dry runs placed nothing and the kill switch stopped the
-- nightly run - so there is nothing to convert, and a migration that
-- multiplied fill prices by 100 would be a loaded gun pointed at any
-- database where that is not true. If a run did fill before the fix, the
-- honest repair is to delete that account's orders, positions and values and
-- let it build again from cash:
--
--   delete from strategy_orders    where strategy = '<name>';
--   delete from strategy_positions where strategy = '<name>';
--   delete from strategy_values    where strategy = '<name>';
--   update strategy_accounts set cash = 100000 where strategy = '<name>';
--
-- This query says whether that applies. A fill price over 1,000 for a share
-- whose price in pounds is under 100 is the signature of the fault.
select o.strategy, count(*) suspect_fills,
       min(o.fill_date) first_suspect, max(o.fill_date) last_suspect
  from strategy_orders o
 where o.status = 'FILLED' and o.fill_price > 1000
 group by o.strategy;

-- ---------------------------------------------------------------------------
-- What was applied.
-- ---------------------------------------------------------------------------

select count(*) symbols,
       sum(quote_ccy is not null) quote_ccy_known,
       sum(upper(ifnull(quote_ccy,'GBX')) in ('GBX','GBP')) sterling,
       sum(upper(ifnull(quote_ccy,'GBX')) not in ('GBX','GBP')) other_currency
  from stock_symbols
 where enabled = 'Y' and exchange = 'XLON';
