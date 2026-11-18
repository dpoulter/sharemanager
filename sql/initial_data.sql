/*Set screen indicators for statistics used in the opening page of the website*/ 
use sharemanager;
insert into screen_indicators (name, description, enabled) values(
'momentum_score','momentum_score','Y');
insert into screen_indicators (name, description, enabled) values(
'value_score','value_score','Y');
insert into screen_indicators (name, description, enabled) values(
'quality_score','quality_score','Y');
insert into screen_indicators (name, description, enabled) values(
'quality_score','quality_score','Y');insert into screen_indicators (name, description, enabled) values(
'momentum_score','momentum_score','Y');
insert into screen_indicators (name, description, enabled) values(
'value_score','value_score','Y');
insert into screen_indicators (name, description, enabled) values(
'quality_score','quality_score','Y');
insert into screen_indicators (name, description, enabled) values(
'3mnth','3mnth','Y');
insert into screen_indicators (name, description, enabled) values(
'6mnth','6mnth','Y');
insert into screen_indicators (name, description, enabled) values(
'12mnth','12mnth','Y');
/* Matched by name, not by indicator_id. These were 140, 141 and 142, the ids
   they happened to have in the database this file was dumped from; on a fresh
   install auto_increment starts at 1, so the updates matched nothing,
   calc_rank stayed at its 'N' default and the momentum indicators never got a
   percentile. momentum_score sums percentiles, so every share scored the same.

   rank_order was also set to 1, which is not a sort expression: the ranking
   query interpolates this column into an ORDER BY. Momentum ranks highest
   first. */
UPDATE screen_indicators SET screen_function='calc_momentum_3mnth',  calc_rank='Y', rank_zero='N', rank_order='value DESC' WHERE name='3mnth';
UPDATE screen_indicators SET screen_function='calc_momentum_6mnth',  calc_rank='Y', rank_zero='N', rank_order='value DESC' WHERE name='6mnth';
UPDATE screen_indicators SET screen_function='calc_momentum_12mnth', calc_rank='Y', rank_zero='N', rank_order='value DESC' WHERE name='12mnth';

INSERT INTO `sharemanager`.`indicator_category` (`category_id`, `name`, `description`, `order`) VALUES ('10', 'Quality', 'Quality', '1');

UPDATE `sharemanager`.`screen_indicators` SET `category`='10' WHERE `indicator_id`='38';
UPDATE `sharemanager`.`screen_indicators` SET `category`='10' WHERE `indicator_id`='41';
UPDATE `sharemanager`.`screen_indicators` SET `category`='10' WHERE `indicator_id`='43';
UPDATE `sharemanager`.`screen_indicators` SET `category`='10' WHERE `indicator_id`='44';
UPDATE `sharemanager`.`screen_indicators` SET `category`='10' WHERE `indicator_id`='64';
UPDATE `sharemanager`.`screen_indicators` SET `category`='10' WHERE `indicator_id`='84';
UPDATE `sharemanager`.`screen_indicators` SET `category`='10' WHERE `indicator_id`='85';
UPDATE `sharemanager`.`screen_indicators` SET `category`='10' WHERE `indicator_id`='86';
UPDATE `sharemanager`.`screen_indicators` SET `category`='10' WHERE `indicator_id`='135';
