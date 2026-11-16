<?php
 // configuration
    require("../includes/config.php"); 
	include("../includes/strategies.php");
	
// if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {

     backing_winners();
	 render("strategies.php");
     }
   
   else
   {
   	//Get shares for the strategy
   	$shares=get_strategy_shares(1);
		//render form
		render("strategy_form.php",["shares"=>$shares]);
   }
 
  
?>
