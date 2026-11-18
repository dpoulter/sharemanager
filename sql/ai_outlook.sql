-- The AI outlook panel.
--
--   mysql <database> < sql/ai_outlook.sql
--
-- One row per generation, never overwritten: the panel shows the most recent
-- for a scope and symbol, and the rest is history. Keeping it means a later
-- outlook can be compared with what the numbers looked like at the time,
-- which is the only way to tell whether any of this was worth reading - so
-- inputs_json holds exactly what the model was shown, not a summary of it.
--
-- Nothing writes here on a schedule. Only public/ai_outlook.php, from the
-- Generate button.

create table if not exists ai_outlook (
  id            int auto_increment primary key,

  -- 'share' for one company, 'market' for the index panel on the dashboard.
  scope         varchar(10)  not null,
  -- null for scope='market'.
  symbol        varchar(50)      null,

  -- The statistics as of date the inputs came from, so an outlook can be
  -- matched to the figures behind it rather than to the clock.
  as_of_date    date             null,

  model         varchar(100) not null,

  -- Which vault notes went in, comma separated, for the "notes used" line.
  -- Empty when the vault is missing, which is a normal state.
  notes_used    text             null,

  inputs_json   mediumtext       null,
  output_json   mediumtext       null,

  -- Usage as the API reported it. cached_tokens is the cache read count: the
  -- vault and system prompt are sent with cache_control, so a second click
  -- within the cache window pays the read rate for them rather than the input
  -- rate, and the two have to be counted apart to price it.
  input_tokens  int default 0,
  cached_tokens int default 0,
  output_tokens int default 0,

  -- Priced from the per-million rates in constants.php at the time of the
  -- call. Stored rather than derived so changing the rates later does not
  -- silently rewrite what past generations cost.
  cost_usd      decimal(10,6) default 0,

  created_at    datetime not null,

  key (scope, symbol, created_at)
);
