<?php
 // configuration
    require("../includes/config.php"); 
	include("../includes/backing_winners.php");
	
// if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {
    	$start_date=$_POST['start_date'];
    	$end_date=$_POST['end_date'];
    	$super_purch_gain=$_POST['super_purch_gain'];
    	$mini_purch_gain=$_POST['mini_purch_gain'];
    	$super_gain=$_POST['super_gain'];
    	$super_loss_1=$_POST['super_loss_1'];
    	$super_loss_2=$_POST['super_loss_2'];
    	$mini_loss_1=$_POST['mini_loss_1'];
    	$mini_loss_2=$_POST['mini_loss_2'];
    	$norm_purch_loss=$_POST['norm_purch_loss'];
    	$norm_peak_loss=$_POST['norm_peak_loss'];

     run_backtest($start_date, $end_date,$super_purch_gain,$mini_purch_gain,$super_gain,$super_loss_1,$super_loss_2,$mini_loss_1,$mini_loss_2,$norm_purch_loss,$norm_peak_loss);
	  render("backtest_results.php");
     }
   
   else
   {
	//render form
	render("backtest_form.php");
   }
   
?>
