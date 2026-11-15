-- Regulatory announcements: the companies' own words, read in full.
--
--   mysql <database> < sql/rns.sql
--
-- Replaces the Google headline feed as the news source. Of 1,600 scored
-- headlines, 85% scored nought and most were tagged "other"; a good number
-- were not news at all but quote pages and broker profiles. An RNS is the
-- primary source, covers every FTSE 350 company, and says what happened
-- rather than that somebody wrote about it.
--
-- news_articles is left alone. The headline feed stops running, but a year
-- from now the only record of what it said will be that table, and dropping
-- it to save a megabyte would be a poor trade.
--
-- Re-runnable: create table if not exists, and inserts guarded by "not
-- exists". Rows are matched by their pattern or by Investegate's own
-- announcement id, never by an auto-increment id.

-- ---------------------------------------------------------------------------
-- The announcements.
-- ---------------------------------------------------------------------------

-- announcement_id is Investegate's own id, the last path segment of the
-- announcement URL. It is the dedupe key and it is unique on its own rather
-- than per symbol: one announcement is one document, and a joint announcement
-- that appears under two companies is still the same document. A re-run
-- inserts nothing.
--
-- body holds the full text, and holds it for two reasons only: to score it,
-- and to show it inside this application. Investegate's terms of use allow a
-- private investor to keep extracts for personal reference and forbid
-- redistribution, so nothing here may be served outside the login. The app is
-- behind Authelia and reachable over Tailscale only; keep it that way.
--
-- body_hash is of the text, not the URL: an announcement that is corrected
-- and reissued under the same id changes its text, and the hash is how that
-- is noticed.
create table if not exists rns_announcements (
  id              int auto_increment primary key,
  announcement_id varchar(40)  not null,
  symbol          varchar(50)  not null,
  tidm            varchar(20),
  source          varchar(20),
  category        varchar(160),
  title           varchar(500),
  published_at    datetime,
  url             varchar(1000),
  body            mediumtext,
  body_hash       char(40),
  body_truncated  char(1) default 'N',
  skipped         char(1) default 'N',
  skip_rule       varchar(160),
  first_seen      datetime,
  sentiment       decimal(6,4),
  ann_type        varchar(30),
  vs_expectations varchar(12),
  summary         varchar(500),
  key_figures     varchar(600),
  bull_point      varchar(500),
  bear_point      varchar(500),
  scored_model    varchar(60),
  scored_at       datetime,
  input_tokens    int,
  output_tokens   int,
  cost_usd        decimal(12,8),
  unique key rns_announcement (announcement_id),
  key rns_symbol_date (symbol, published_at),
  key rns_scoring (scored_at, published_at),
  key rns_published (published_at)
);

-- Added here rather than in the create so a database that already has the
-- table from an earlier run of this file gains them too.
alter table rns_announcements
  add column if not exists body_truncated char(1) default 'N' after body_hash;
alter table rns_announcements
  add column if not exists cost_usd decimal(12,8) after output_tokens;

-- ---------------------------------------------------------------------------
-- Which ticker Investegate knows a company by.
-- ---------------------------------------------------------------------------

-- A TIDM is not derivable from the ticker this application stores. Most are
-- the same - BARC is BARC - but London gives short codes a trailing dot, so
-- Rolls-Royce is RR here and RR. on Investegate, and BT is BT.A. There is no
-- rule to apply, only a fact to record.
--
-- Left null to mean "not known yet". The fetcher tries the symbol, then the
-- symbol with a trailing dot, and writes back whichever answered - so the
-- column fills itself over the first run or two and every run after that is
-- one request a company. Fill one in by hand if a company is stubborn.
alter table stock_symbols
  add column if not exists tidm varchar(20) after symbol;

-- ---------------------------------------------------------------------------
-- What not to read.
-- ---------------------------------------------------------------------------

-- Most of an RNS feed is housekeeping. "Transaction in Own Shares" is a
-- buyback's daily paperwork, "Total Voting Rights" is a monthly share count,
-- "Holding(s) in Company" is a fund crossing a disclosure threshold. None of
-- it moves a share and all of it would be scored at a penny a time and then
-- averaged into the sentiment figures as noise.
--
-- In a table rather than in code because this list is a judgement that will
-- want tuning once a week of real announcements has been read, and tuning a
-- row is a one-line update where tuning code is a deploy.
--
-- pattern is matched against the title and the category, on word boundaries,
-- case-insensitively. Word boundaries matter: 'NAV' as a plain substring
-- would skip every announcement from a company called Navigator.
--
-- keep_if is the exception. A "Director/PDMR Shareholding" is usually a share
-- award or an option exercise, which says nothing; a director buying or
-- selling with their own money is one of the few genuine signals in the feed.
-- When keep_if matches the title, the skip does not apply.
create table if not exists rns_skip_rules (
  id          int auto_increment primary key,
  pattern     varchar(160) not null,
  keep_if     varchar(400),
  description varchar(255),
  enabled     char(1) default 'Y',
  unique key rns_skip_pattern (pattern)
);

insert into rns_skip_rules (pattern, keep_if, description, enabled)
select 'Transaction in Own Shares', null,
       'A buyback''s daily paperwork. The buyback itself is announced separately.', 'Y'
 where not exists (select 1 from rns_skip_rules where pattern = 'Transaction in Own Shares');

insert into rns_skip_rules (pattern, keep_if, description, enabled)
select 'Total Voting Rights', null, 'Monthly share count.', 'Y'
 where not exists (select 1 from rns_skip_rules where pattern = 'Total Voting Rights');

insert into rns_skip_rules (pattern, keep_if, description, enabled)
select 'Block Listing', null, 'Admission paperwork for shares already authorised.', 'Y'
 where not exists (select 1 from rns_skip_rules where pattern = 'Block Listing');

insert into rns_skip_rules (pattern, keep_if, description, enabled)
select 'Form 8', null, 'Takeover Panel disclosure form.', 'Y'
 where not exists (select 1 from rns_skip_rules where pattern = 'Form 8');

insert into rns_skip_rules (pattern, keep_if, description, enabled)
select 'Rule 8', null, 'Takeover Panel disclosure under Rule 8.', 'Y'
 where not exists (select 1 from rns_skip_rules where pattern = 'Rule 8');

insert into rns_skip_rules (pattern, keep_if, description, enabled)
select 'Holding(s) in Company', null,
       'A holder crossing a disclosure threshold. Says more about the fund than the company.', 'Y'
 where not exists (select 1 from rns_skip_rules where pattern = 'Holding(s) in Company');

-- Both spellings of the same thing. The skip has to be decided from the URL
-- slug, before the document is fetched, and a slug spells "Holding(s)" as
-- "holdings"; the title itself keeps the brackets. One rule each, and
-- rns_normalise() makes both of them readable from either side.
insert into rns_skip_rules (pattern, keep_if, description, enabled)
select 'Holdings in Company', null,
       'The same rule, spelled the way a URL slug spells it.', 'Y'
 where not exists (select 1 from rns_skip_rules where pattern = 'Holdings in Company');

insert into rns_skip_rules (pattern, keep_if, description, enabled)
select 'Net Asset Value', null, 'Investment trust NAV, published daily or weekly.', 'Y'
 where not exists (select 1 from rns_skip_rules where pattern = 'Net Asset Value');

insert into rns_skip_rules (pattern, keep_if, description, enabled)
select 'NAV', null, 'The same, abbreviated. Matched on a word boundary, not as a substring.', 'Y'
 where not exists (select 1 from rns_skip_rules where pattern = 'NAV');

insert into rns_skip_rules (pattern, keep_if, description, enabled)
select 'Publication of Prospectus', null, 'A document becoming available.', 'Y'
 where not exists (select 1 from rns_skip_rules where pattern = 'Publication of Prospectus');

insert into rns_skip_rules (pattern, keep_if, description, enabled)
select 'Notice of AGM', null, 'A meeting being called.', 'Y'
 where not exists (select 1 from rns_skip_rules where pattern = 'Notice of AGM');

insert into rns_skip_rules (pattern, keep_if, description, enabled)
select 'Result of AGM', null, 'Resolutions passing, which they almost always do.', 'Y'
 where not exists (select 1 from rns_skip_rules where pattern = 'Result of AGM');

-- The one rule with an exception. Purchase, sale, bought and sold are the
-- words that distinguish a director spending their own money from a company
-- handing them shares.
insert into rns_skip_rules (pattern, keep_if, description, enabled)
select 'Director/PDMR Shareholding',
       'purchase|purchased|sale|sold|bought|acquisition of shares|disposal of shares',
       'Usually a share award or an option exercise. Kept when a director bought or sold.', 'Y'
 where not exists (select 1 from rns_skip_rules where pattern = 'Director/PDMR Shareholding');

-- ---------------------------------------------------------------------------
-- What the run did.
-- ---------------------------------------------------------------------------

select pattern, ifnull(keep_if,'(no exception)') keep_if, enabled
  from rns_skip_rules order by pattern;

select count(*) announcements,
       sum(skipped='Y') skipped,
       sum(scored_at is not null) scored
  from rns_announcements;

select count(*) symbols, count(tidm) tidm_known
  from stock_symbols where enabled='Y';
