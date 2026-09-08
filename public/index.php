<?php

    // configuration
    require("../includes/config.php"); 

	//redirect("https://sharemanager.eu/performance.php");
	
	// if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {
        // validate submission
        if (empty($_POST["symbol"]))
        {
            apologize("You must enter a symbol.");
        }
		else
			//symbol
			$symbol=$_POST["symbol"];
			//timespan
			if (isset($_POST["timespan"]))
				$timespan=$_POST["timespan"];
			else
				$timespan="1d";
				
			if (isset($_POST["type"]))
				$type=$_POST["type"];
			else
				$type="l";
	}
	
	//Check if symbol was passed to form
	else
		
	{
	
		if (isset($_GET["symbol"]))
			$symbol=$_GET["symbol"];
			
		if (isset($_GET["screen_id"]))
			$screen_id=$_GET["screen_id"];
			
		if (isset($_GET["timespan"]))
			$timespan=$_GET["timespan"];
		else
			$timespan="6m";
			
		if (isset($_GET["type"]))
			$type=$_GET["type"];
		else
			$type="l";
	}		
	
	if (isset($symbol)){
        //Get Stock Quote
        
        write_log("index","symbol=$symbol");
		
		//templates/quote.php needs around thirty variables and this passed six,
		//so every panel it did not know about rendered undefined - 74 warnings
		//for one symbol. quote.php is the page that builds them all, so hand
		//the symbol to it rather than keeping a second, half wired copy.
		$query = ["symbol" => $symbol, "timespan" => $timespan, "type" => $type];
		if (isset($screen_id)) { $query["screen_id"] = $screen_id; }
		redirect("quote.php?" . http_build_query($query));
        
        
        

       
    }

	else
    {   //get stock symbols
       // $stock_symbols = query("SELECT symbol, description FROM stock_symbols where enabled='Y'");
        // else render form
//        render("quote_form.php", ["title" => "Stock Quote","symbols" => $stock_symbols]);

		//Get top Ten Momentum stocks
		$momentum_topten = get_momentum_topten(); 
		$value_topten = get_value_topten(); 
		$quality_topten = get_quality_topten(); 
		$overall_topten = get_overall_topten(); 
		$last_update = get_last_update();
        render("quote_form.php", ["title" => "Stock Quote","momentum_topten"=> $momentum_topten,"value_topten"=> $value_topten,"quality_topten"=> $quality_topten,"overall_topten"=> $overall_topten,"last_update"=> $last_update]);
    }

?>
