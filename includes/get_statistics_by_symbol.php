<?php

  require_once("constants.php");
         include("functions.php");
         include("share_functions.php");

	$asOfDate=new DateTime();
        $asOfDate->sub(new DateInterval('P1D'));
	$symbol=$argv[1];
	//$indicator='tendayavg';
	print_r( "Asofdate=".date_format($asOfDate,'Y-m-d')."\r\n");
    //Get statistics to calculate
	$indicators=get_stats_indicators();
		foreach ($indicators as $indicator){
		print_r( $indicator);
		 indicator_stats(date_format($asOfDate,'Y-m-d'),$indicator["name"],$symbol);

}
	
// indicator_stats(date_format($asOfDate,'Y-m-d'),$indicator,$symbol);
 ?>
