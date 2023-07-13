<?php

  require_once("constants.php");
         include("functions.php");
         include("share_functions.php");

	// Fill gaps in statistics
	$indicator='tendayavg';

$rows = query("SELECT symbol, indicator, min(date) min_date, max(date) max_date FROM `statistics` WHERE indicator=? group by symbol,indicator order by symbol,indicator",$indicator);
foreach($rows as $row){
	$symbol=$row['symbol'];
	$indicator=$row['indicator'];
	$min_date=date_create_from_format('Y-m-d',$row['min_date']);
	$max_date=date_create_from_format('Y-m-d',$row['max_date']);
	
	//write_log("get_statistics1","symbol=$symbol indicator=$indicator min_date=".date_format($min_date,'Y-m-d')." max_date=".date_format($max_date,'Y-m-d'));
	
	$current_date=$min_date;
	while($current_date <= $max_date) {

		//write_log("get_statistics1","current_date=".date_format($current_date,'Y-m-d'));
		
		//check if we have a value for this date
		$values = query("select value from statistics where symbol=? and indicator=? and date=?",$symbol,$indicator ,date_format($current_date,'Y-m-d'));
		if (count($values)>0){
			//We have a value so store it
			$indicator_value=$values[0]['value'];	
			//write_log("get_statistics1","indicator_value=$indicator_value");
		}
		else {
			//No value so update with previous value
			//write_log("get_statistics1","No value so update with previous value");
			query("insert into statistics(symbol,indicator,date, value) values (?,?,?,?)",$symbol,$indicator ,date_format($current_date,'Y-m-d'),$indicator_value);
	
		}
		//increment_date
		date_add($current_date, date_interval_create_from_date_string('1 day'));
	}
	

}
	
 ?>
