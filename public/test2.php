<?php
	include '../includes/share_functions.php';
	$list1=array();
	array_push($list1,["symbol"=>"A"]);
	array_push($list1,["symbol"=>"B"]);
	$list2=array();
	array_push($list2,["symbol"=>"B"]);
	array_push($list2,["symbol"=>"C"]);
	$build_list=array();
	$list3=array();
	array_push($list3,["symbol"=>"B"]);
        array_push($list3,["symbol"=>"D"]);
	array_push($build_list,$list1,$list2,$list3);
	print_r(combine_criteria_lists($build_list));




?>
