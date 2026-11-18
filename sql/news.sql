-- Stored headlines, their scores, and the cached "why did it move?" answers.
--
--   mysql <database> < sql/news.sql
--
-- Headlines came from Google News on every quote page load and were thrown
-- away again: the News tab fetched the feed, rendered it and kept nothing. So
-- there was no history to put on a chart, nothing to ask a model about, and
-- no way to see what was being said about a share last month.
--
-- Google returns only recent items and FMP has no news for .L tickers, so
-- there is no backfill to run: history starts the day the nightly job first
-- runs and builds from there.
--
-- Re-runnable: every statement is "if not exists" or an update that sets the
-- same values again.

-- ---------------------------------------------------------------------------
-- The headlines.
-- ---------------------------------------------------------------------------

-- url_hash is sha1 of the link, which is what makes a re-run cheap: the
-- unique key on (symbol, url_hash) turns "have I seen this already" into an
-- insert that fails rather than a select before every insert. Per symbol
-- rather than globally, because one article can legitimately be news about
-- two companies and both should carry it.
--
-- published_at is UTC. Google sends RFC 2822 with a zone on it; the job
-- converts rather than storing the string, so a range query means something.
--
-- The scoring columns are null until the scorer has been round. sentiment is
-- -1 to 1; impact_type is one of a fixed set, which the model is given as an
-- enum and which is checked again on the way in.
create table if not exists news_articles (
  id           bigint auto_increment primary key,
  symbol       varchar(50)  not null,
  published_at datetime     not null,
  title        varchar(500) not null,
  publisher    varchar(200),
  link         varchar(1000) not null,
  url_hash     char(40)     not null,
  first_seen   datetime     not null,
  sentiment    decimal(4,3),
  impact_type  varchar(20),
  summary      varchar(500),
  bull_point   varchar(500),
  bear_point   varchar(500),
  scored_model varchar(60),
  scored_at    datetime,
  unique key news_symbol_url (symbol, url_hash),
  key news_symbol_date (symbol, published_at),
  key news_unscored (scored_at, published_at)
);

-- ---------------------------------------------------------------------------
-- The cached explanations.
-- ---------------------------------------------------------------------------

-- One row per symbol, range and model. The same range serves the cache; the
-- Regenerate button replaces the row rather than adding one. inputs_json is
-- kept so an answer can be read back against what it was given - without it a
-- cached explanation is a paragraph with no provenance.
create table if not exists ai_explanations (
  id           bigint auto_increment primary key,
  symbol       varchar(50) not null,
  from_date    date        not null,
  to_date      date        not null,
  model        varchar(60) not null,
  inputs_json  mediumtext,
  output_json  mediumtext,
  input_tokens  int,
  output_tokens int,
  cost         decimal(12,6),
  created_at   datetime    not null,
  unique key explain_range (symbol, from_date, to_date, model)
);

-- ---------------------------------------------------------------------------
-- The indicators the nightly job writes.
-- ---------------------------------------------------------------------------

-- A category of their own, and calc_rank='N' for all three: they are shown on
-- the quote page, available to the screens and sent to the outlook, and they
-- do not touch the value, quality or overall scores. There is no history yet
-- to say whether a week of good headlines is worth anything, and putting an
-- untested measure into the scores is how a screen quietly becomes a
-- sentiment tracker.
--
-- rank_funds='Y': a trust's discount narrows on news like anything else, and
-- sentiment says nothing about the accounts, which is what funds were
-- excluded from.
insert into indicator_category (category_id, name, description, `order`)
select 14, 'Sentiment', 'Sentiment', 2
 where not exists (select 1 from indicator_category where category_id = 14);

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'news_sentiment_7d', null, 'News sentiment (7 day)', 'Y', 230,
       null, 'Y', 'N', 'value DESC', 'NORMAL', 'Y', 'NONE', 14
 where not exists (select 1 from screen_indicators where name = 'news_sentiment_7d');

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'news_sentiment_30d', null, 'News sentiment (30 day)', 'Y', 231,
       null, 'Y', 'N', 'value DESC', 'NORMAL', 'Y', 'NONE', 14
 where not exists (select 1 from screen_indicators where name = 'news_sentiment_30d');

insert into screen_indicators
       (name, provider_field, description, enabled, order_number,
        screen_function, rank_zero, calc_rank, rank_order, rank_negative,
        rank_funds, fx_adjust, category)
select 'news_count_7d', null, 'Headlines (7 day)', 'Y', 232,
       null, 'Y', 'N', 'value DESC', 'NORMAL', 'Y', 'NONE', 14
 where not exists (select 1 from screen_indicators where name = 'news_count_7d');

-- Set again on a re-run. screen_function stays null: includes/fetch_news.php
-- writes these three directly, rather than being dispatched per symbol by
-- get_statistics.php, because they come from one query over news_articles and
-- not from a calculation per share.
update screen_indicators
   set description='News sentiment (7 day)', enabled='Y', screen_function=null,
       rank_zero='Y', calc_rank='N', rank_order='value DESC',
       rank_negative='NORMAL', rank_funds='Y', fx_adjust='NONE', category=14
 where name='news_sentiment_7d';

update screen_indicators
   set description='News sentiment (30 day)', enabled='Y', screen_function=null,
       rank_zero='Y', calc_rank='N', rank_order='value DESC',
       rank_negative='NORMAL', rank_funds='Y', fx_adjust='NONE', category=14
 where name='news_sentiment_30d';

update screen_indicators
   set description='Headlines (7 day)', enabled='Y', screen_function=null,
       rank_zero='Y', calc_rank='N', rank_order='value DESC',
       rank_negative='NORMAL', rank_funds='Y', fx_adjust='NONE', category=14
 where name='news_count_7d';

-- ---------------------------------------------------------------------------
-- What the run did.
-- ---------------------------------------------------------------------------

select name, description, category, calc_rank, rank_funds
  from screen_indicators where category = 14 order by order_number;

select count(*) stored_headlines,
       sum(scored_at is not null) scored,
       ifnull(min(date(published_at)),'(none)') oldest,
       ifnull(max(date(published_at)),'(none)') newest
  from news_articles;
