<?php

  require_once("constants.php");
  include("functions.php");
  include("share_functions.php");

	//Get parameters
// 	$start_date=date_create_from_format('Y-m-d',$argv[1]);
// 	$end_date=date_create_from_format('Y-m-d',$argv[2]);

	$interval=new DateInterval('P30D');
 	
 	$rows=query("select symbol, min(date) min_date, max(date) max_date from historical_prices group by symbol");
 	foreach($rows as $row){
 		
 		 write_log("get_moving_average_statistics", "min_date=".$row['min_date']);
 		 write_log("get_moving_average_statistics", "max_date=".$row['max_date']);
 		
 		$asOfDate=date_add(date_create($row['min_date']),$interval);
 		$end_date=date_create($row['max_date']);
 		$symbol=$row['symbol'];
 		 	
 		if (isset($end_date)) {	
		 	while(isset($asOfDate)&&($asOfDate <= $end_date)) {
			
			  write_log("get_moving_average_statistics", "Asofdate=".date_format($asOfDate,'Y-m-d')."\r\n");
		  
		    //indicator_stats(date_format($asOfDate,'Y-m-d'),'tendayavg',$symbol);
		   // indicator_stats(date_format($asOfDate,'Y-m-d'),'thirtydayavg',$symbol);
		    indicator_stats(date_format($asOfDate,'Y-m-d'),'hndrddayavg',$symbol);
		     
		     //increment date by 1 month
		     $asOfDate=date_add($asOfDate,$interval);
		     write_log("get_moving_average_statistics", "next_date=".date_format($asOfDate,'Y-m-d')."\r\n");
		  
			}
	}
	}
 ?>
