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
UPDATE `sharemanager`.`screen_indicators` SET `screen_function`='calc_momentum_12mnth',calc_rank='Y', rank_zero='N' , rank_order=1 WHERE `indicator_id`='142';
UPDATE `sharemanager`.`screen_indicators` SET `screen_function`='calc_momentum_3mnth' ,calc_rank='Y', rank_zero='N' , rank_order=1 WHERE `indicator_id`='140';
UPDATE `sharemanager`.`screen_indicators` SET `screen_function`='calc_momentum_6mnth' ,calc_rank='Y', rank_zero='N' , rank_order=1  WHERE `indicator_id`='141';

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
