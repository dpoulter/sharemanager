<?php

  require_once("constants.php");
         include("functions.php");
         include("share_functions.php");

	// Fill gaps in statistics

$rows = query("SELECT symbol, min(date) min_date, max(date) max_date FROM `historical_prices` WHERE 1 group by symbol");
foreach($rows as $row){
	$symbol=$row['symbol'];
   $min_date=date_create_from_format('Y-m-d',$row['min_date']);
	$max_date=date_create_from_format('Y-m-d',$row['max_date']);
	
	//write_log("get_statistics1","symbol=$symbol indicator=$indicator min_date=".date_format($min_date,'Y-m-d')." max_date=".date_format($max_date,'Y-m-d'));
	
	$current_date=$min_date;
	while($current_date <= $max_date) {

		//write_log("get_statistics1","current_date=".date_format($current_date,'Y-m-d'));
		
		//check if we have a value for this date
		$values = query("select price from historical_prices where symbol=? and date=?",$symbol,date_format($current_date,'Y-m-d'));
		if (count($values)>0){
			//We have a value so store it
			$price=$values[0]['price'];	
			//write_log("get_statistics1","indicator_value=$indicator_value");
		}
		else {
			//No value so update with previous value
			//write_log("get_statistics1","No value so update with previous value");
			query("insert into historical_prices(symbol,date, price) values (?,?,?)",$symbol,date_format($current_date,'Y-m-d'),$price);
	
		}
		//increment_date
		date_add($current_date, date_interval_create_from_date_string('1 day'));
	}
	

}
	
 ?>
