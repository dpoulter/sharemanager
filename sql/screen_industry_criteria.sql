-- Industry criteria on a screen (screen_build, screen_edit): "industry is / is
-- not one of [list]".
--
--   mysql <database> < sql/screen_industry_criteria.sql
--
-- An industry criterion is a row in screen_criteria like any other, with
-- operator 'ind_in' (is) or 'ind_not_in' (is not), indicator_id 0 and no
-- operands. Its industries are here, one row each, by the names in
-- `industries` (sql/industry_map.sql, which is applied first).
--
-- Safe to apply twice.

create table if not exists screen_criteria_industries (
  criteria_id  int not null,
  industry     varchar(50) not null,
  primary key (criteria_id, industry)
);
