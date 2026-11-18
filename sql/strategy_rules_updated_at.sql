-- ---------------------------------------------------------------------------
-- strategy_rules.updated_at: give it a default.
--
-- The column is "not null" with no default, so a plain insert of a new
-- strategy - one written by hand, or by anything that does not know to pass
-- now() - fails outright:
--
--   insert into strategy_rules (strategy, selection, score_indicator, holdings)
--   values ('a_new_one', 'score', 'momentum_score', 15);
--   ERROR 1364: Field 'updated_at' doesn't have a default value
--
-- It is a timestamp of when the rules last changed, which is exactly what
-- the server can keep for itself. On update as well as on insert, so a rule
-- edited through any path stamps itself.
--
-- Re-runnable: modify column is the same statement whatever the column is
-- now.
-- ---------------------------------------------------------------------------

alter table strategy_rules
  modify column updated_at datetime not null
    default current_timestamp on update current_timestamp;

select 'strategy_rules.updated_at now defaults to the current time' as done;
