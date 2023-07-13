<?php

         require_once("constants.php");
         include("functions.php");
         include("share_functions.php");

	$asOfDate=new DateTime();
	$asOfDate->sub(new DateInterval('P1D'));

        //$asOfDate = new DateTime('2016-01-29');
	//print_r( "Asofdate=".date_format($asOfDate,'Y-m-d')."\r\n");
    //Get statistics to calculate
//	$indicators=get_stats_indicators();
//	foreach ($indicators as $indicator){
//	print_r( $indicator);

$symbol='BKG.L';
//$indicator='price_sales_ratio';
//$date=date_format($asOfDate,'Y-m-d');

//get indicator value from statistics for a given date
//$value= get_indicator_value($symbol, $indicator,$date);
//print("Float_shares=$value");


		// calc_roa(date_format($asOfDate,'Y-m-d'),$symbol);
//}	

 $share_info=lookup($symbol);
 print_r($share_info);
 
 ?>
