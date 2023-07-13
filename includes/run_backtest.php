<?php
 // configuration
  require_once("../includes/constants.php");
  include("../includes/functions.php");
  //require("../includes/config.php"); 
	include("../includes/backtest.php");
	
	if (isset($argv[3]))
		$symbol=$argv[3];
	else 
		$symbol=null;
		
   $start_date=$argv[1];
   $end_date=$argv[2];

     backtest($start_date, $end_date,4,1,$symbol);
	 // render("backtest_results.php");
   

   
?>
