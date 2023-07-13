<?php
    require("../includes/config.php"); 
 
// if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {

        //Get POST values
        if (isset($_POST['threeMnth'])){
		$threeMnth = $_POST['threeMnth'];
	}
        if (isset($_POST['sixMnth'])){
		$sixMnth = $_POST['sixMnth'];
	}
	 if (isset($_POST['twelveMnth'])){
                $twelveMnth = $_POST['twelveMnth'];
        }
	if (isset($_POST['tenDayAvg'])){
		$tenDayAvg = $_POST['tenDayAvg'];
	}
	if (isset($_POST['thirtyDayAvg'])){
		$thirtyDayAvg = $_POST['thirtyDayAvg'];
	}
	if (isset($_POST['hndrdDayAvg'])){
		$hndrdDayAvg = $_POST['hndrdDayAvg'];
	}
	if (isset($_POST['EarningsGrowth'])){
		$earningsgrowth = $_POST['EarningsGrowth'];
	}
	$asOfDate = $_POST['asOfDate'];
	$indicators = $_POST['indicators'];

	//Validate as of date
	if (empty($asOfDate)){
		apologize("As of date is required");

	}
	else {
		list($day,$month,$year) = explode('-',$asOfDate);
           	if (!checkdate($month,$day,$year)) {
			apologize("Date is not valid");
		}
		else {
		
			foreach($indicators as $indicator){
			
				//get indicator info
				write_log("statistics.php","call indicator stats- indicator=".$indicator);
				 indicator_stats($asOfDate,$indicator);
 /*
			//Calculate momentum
        		if (isset($threeMnth) && $threeMnth=='Y') {
				calc_momentum($asOfDate,3);
			}
	 		if (isset($sixMnth) && $sixMnth=='Y'){
                		calc_momentum($asOfDate,6);
        		}
			 if (isset($twelveMnth) && $twelveMnth=='Y'){
                                calc_momentum($asOfDate,12);
                        }       		
			//Calculate Moving Averages
			if (isset($tenDayAvg) && $tenDayAvg=='Y') {
                                calc_moving_avg(10,$asOfDate);
                        }
			if (isset($thirtyDayAvg) && $thirtyDayAvg=='Y') {
                                calc_moving_avg(30,$asOfDate);
                        }
			if (isset($hndrdDayAvg) && $hndrdDayAvg=='Y') {
                                calc_moving_avg(100,$asOfDate);
                        }
			//Earnings Growth
			if (isset($earningsgrowth) && $earningsgrowth=='Y'){
				calc_earnings_growth($asOfDate);
			} */
			}
		}
   	}
	//Alert that calculations have completed
?><script type="text/javascript">
    alert("Calculate statistics has completed successfully.");
    history.back();
  </script> 

<?php
   }
   else
   {
    //Get statistics to calculate
	$category_indicators=get_indicators();
	//render form
	render("statistics_form.php",["title" => "Calculate Statistics","category_indicators" => $category_indicators]);
   }
 ?>
</body>
</html>
