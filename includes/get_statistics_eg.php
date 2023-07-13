<?php

         require_once("constants.php");
         include("functions.php");
         include("share_functions.php");

	$asOfDate=new DateTime();
	$asOfDate->sub(new DateInterval('P1D'));

        //$asOfDate = new DateTime('2016-01-29');
	print_r( "Asofdate=".date_format($asOfDate,'Y-m-d')."\r\n");
    //Get statistics to calculate
//	$indicators=get_stats_indicators();
//	foreach ($indicators as $indicator){
//	print_r( $indicator);
$indicator='price_book_ratio';
$date=date_format($asOfDate,'Y-m-d');

$rows=query("select symbol from stock_symbols where enabled='Y' and symbol='ALY.L'");
foreach ($rows as $row){
//$symbol='DCG.L';
	
	$symbol=$row['symbol'];
	print_r( "symbol=".$symbol."\r\n");

//get indicator value from statistics for a given date
//$value= get_indicator_value($symbol, $indicator,$date);
//print("Float_shares=$value");


		 indicator_stats(date_format($asOfDate,'Y-m-d'),$indicator,$symbol);
//}	
}
 ?>
