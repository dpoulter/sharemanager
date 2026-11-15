-- Investment funds are left out of the value, quality and overall rankings.
--
--   mysql <database> < sql/fund_sectors.sql
--
-- An investment trust is a company that owns other companies' shares, so the
-- ratios the value and quality scores are built from describe the wrapper
-- rather than a business. A trust's P/E is its holdings' earnings seen through
-- a share price and a discount; its price/book is the discount to net asset
-- value written as a ratio; its return on equity is the portfolio's return,
-- not an operating margin. Ranked against operating companies these read as
-- noise at best: on the live FTSE 350 for 2026-11-13 three of the ten best
-- overall scores and two of the ten best value scores were trusts.
--
-- Momentum is a different matter. A price series is a price series whoever
-- owns the assets behind it, so funds keep their 3, 6 and 12 month momentum,
-- their momentum_score, and their place in the trend following screens.
--
-- Two settings carry this, both matched by name rather than id, because ids
-- differ between databases:
--
--   fund_sectors              which sectors are funds
--   screen_indicators.rank_funds   whether funds are ranked on an indicator
--
-- Nothing in the application's SQL names a sector. get_statistics.php joins
-- to fund_sectors, so adding a sector here is all it takes to leave another
-- kind of fund out, and nothing has to be deployed to do it.
--
-- Re-runnable: every statement is "if not exists", an insert guarded by
-- "where not exists", or an update that sets the same values again.

-- ---------------------------------------------------------------------------
-- Which sectors hold funds.
-- ---------------------------------------------------------------------------

-- sector matches stock_symbols.sector, which is the provider's own text.
create table if not exists fund_sectors (
  sector      varchar(100) not null,
  description varchar(255),
  primary key (sector)
);

-- The three on the live database, covering 78 of the 350 enabled symbols.
-- Real estate is deliberately absent: a REIT is an operating property company
-- with rent, debt and a margin, and its ratios mean what they say.
insert into fund_sectors (sector, description)
select 'Investment Trusts', 'Closed ended listed investment trusts'
 where not exists (select 1 from fund_sectors where sector = 'Investment Trusts');

insert into fund_sectors (sector, description)
select 'Closed End Investments', 'Closed ended investment companies'
 where not exists (select 1 from fund_sectors where sector = 'Closed End Investments');

insert into fund_sectors (sector, description)
select 'Collective Investments', 'Pooled collective investment vehicles'
 where not exists (select 1 from fund_sectors where sector = 'Collective Investments');

-- ---------------------------------------------------------------------------
-- Which indicators funds are ranked on.
-- ---------------------------------------------------------------------------

-- 'N' leaves funds out of the ranking for that indicator entirely: out of the
-- percentiles and out of the denominator the percentiles are worked out
-- against, so a company is ranked against companies and nothing else. The
-- default is 'Y', which is what every momentum indicator keeps.
alter table screen_indicators
  add column if not exists rank_funds char(1) default 'Y' after rank_negative;

-- The four ranked indicators that feed the value and quality scores. The
-- momentum three ('3mnth','6mnth','12mnth') are the other ranked indicators
-- and stay at 'Y'.
update screen_indicators set rank_funds='N'
 where name in ('pe','price_sales_ratio','price_book_ratio','roe_ttm');

update screen_indicators set rank_funds='Y'
 where name in ('3mnth','6mnth','12mnth');

-- ---------------------------------------------------------------------------
-- What the run did, rather than leaving you to count "0 rows affected".
-- ---------------------------------------------------------------------------

select sector, description from fund_sectors order by sector;

select name, calc_rank, rank_order, rank_negative, rank_funds
  from screen_indicators
 where calc_rank = 'Y'
 order by rank_funds, name;
