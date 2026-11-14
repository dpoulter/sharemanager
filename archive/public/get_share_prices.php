<?php

	 require_once("constants.php");
	 include("functions.php");
	 include("share_functions.php");
	 
	 log_job("get_share_prices");
	 
	 if (!isset($argv[1])){
	 	//Set start date to yesterdays date and end date to beginning of time
	 	$end_date=new DateTime();
	 	$start_date=new DateTime();
	 	//$start_date->sub(new DateInterval('P5Y'));
		$end_date->sub(new DateInterval('P1D'));
		$start_date->sub(new DateInterval('P1D'));

	 }
	 else {
	 	//Get parameters
	 	$start_date=date_create_from_format('Y-m-d',$argv[1]);
	 	$end_date=date_create_from_format('Y-m-d',$argv[2]);
	 }
	 
	 //Call function to get historical prices
	 echo 'Start date='.date_format($start_date,'d-m-Y');
	 echo 'End date='.date_format($end_date,'d-m-Y');
	 
	 //Set exchange
	 $_SESSION["exchange"]='XLON';
	 get_historical_prices(date_format($start_date,'Y-m-d'),date_format($end_date,'Y-m-d'));
	 
	 //Set default exchange
	 //$_SESSION["exchange"]='JSE';
	 //get_historical_prices(date_format($start_date,'d-m-Y'),date_format($end_date,'d-m-Y'));
?>