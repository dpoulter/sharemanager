<?php

    // configuration
    require("../includes/config.php"); 

	
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
			$symbol=substr($_POST["symbol"],0,strpos($_POST["symbol"],' '));
			//echo "Symbol=$symbol";
			//timespan
			if (isset($_POST["timespan"]))
				$timespan=$_POST["timespan"];
			else
				$timespan="6m";
				
			if (isset($_POST["type"]))
				$type=$_POST["type"];
			else
				$type="l";
	}
	
	//Check if symbol was passed to form
	else {
			
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
     // write_log("quote.php","1");
		$share_info = get_share_info($symbol);
		
		//write_log("quote.php","2");
           
		if ($share_info!==false) {		
			$chart=null; //"http://chart.finance.yahoo.com/z?s=$symbol&t=$timespan&q=$type&l=on&z=l&p=m20,m50,m100,m200";	
			
			write_log("quote.php","3");
						
			$quote = share_lookup($symbol);  
			
			write_log("quote.php","4");
			
			
			//$income_statement=income_statement($symbol);
			$income_statement=null;
			write_log("quote.php","5");
			//$balance_sheet=balance_sheet($symbol);
			$balance_sheet=null;
			write_log("quote.php","6");
			//$cash_flow_statement=cash_flow_statement($symbol);
			$cash_flow_statement=null;
			write_log("quote.php","7");
			//$ratings=ratings($symbol);
			$ratings=null;
			$valuation=get_valuation($symbol);
			$industry_valuation=get_industry_valuation($symbol);
			write_log("quote.php","8");
			$momentum_statistics=get_momentum_statistics($symbol);
			//$momentum_statistics=null;
			write_log("quote.php","9");
			//$growth_statistics=get_growth_statistics($symbol);
			$growth_statistics=null;
			write_log("quote.php","10");
			//$value_statistics=get_value_statistics($symbol);
			$value_statistics=null;
			write_log("quote.php","11");
			$quality_statistics=get_quality_statistics($symbol);
			//$quality_statistics=null;
			write_log("quote.php","12");
			$scores = get_scores($symbol);
			//$scores = null;
			write_log("quote.php","13");
			$valueranks=get_value_rank($symbol);
			//$valueranks=null;
			write_log("quote.php","14");
			$momentumranks=get_momentum_rank($symbol);
			//$momentumranks=null;
			write_log("quote.php","15");
			$qualityranks=get_quality_rank($symbol);
			//$qualityranks=null;
			write_log("quote.php","16");
			//$piotroski_fscore=get_piotroski_fscore($symbol);
			$piotroski_fscore=null;
			//$piotroski_variables=get_piotroski_variables($symbol);
			$piotroski_variables=null;
			write_log("quote.php","17");
			//$altman_zscore=get_altman_zscore($symbol);
			$altman_zscore=null;
			
			//$altman_variables=get_altman_variables($symbol);
			$altman_variables=null;
			//$altman_nonman_variables=get_altman_nonman_variables($symbol);
			$altman_nonman_variables=null;
			//$altman_zscore_nonman=get_altman_zscore_nonman($symbol);
			$altman_zscore_nonman=null;

			write_log("quote.php","Get relative_sector_valuations");
			$relative_sector_valuations=get_relative_to_sector($symbol); 

			write_log("quote.php","Get get_relative_to_industry");
			$relative_industry_valuations=get_relative_to_industry($symbol);
			
			
			write_log("quote.php","Get category_indicators");
			$category_indicators=get_indicators();


			//render form
			write_log("quote.php","Start render form");

			 if(isset($screen_id))
				render("quote.php", ["title" => $_SESSION['exchange'].":".$symbol,"symbol"=>$symbol,"valuation"=>$valuation,"industry_valuation"=>$industry_valuation
					,"piotroski_variables"=>$piotroski_variables,"altman_variables"=>$altman_variables,"altman_nonman_variables"=>$altman_nonman_variables
					,"relative_sector_valuations"=>$relative_sector_valuations,"relative_industry_valuations"=>$relative_industry_valuations,"share_info"=> $share_info
					, "quote" => $quote,"chart"=>$chart,"timespan"=>$timespan,"type"=>$type,"screen_id"=>$screen_id,"incomestatement"=>$income_statement
					,"balancesheet"=>$balance_sheet,"cashflowstatement"=>$cash_flow_statement,"momentum_statistics"=>$momentum_statistics,"growth_statistics"=>$growth_statistics
					,"value_statistics"=>$value_statistics,"quality_statistics"=>$quality_statistics,"scores"=>$scores,"valueranks"=>$valueranks,"momentumranks"=>$momentumranks
					,"qualityranks"=>$qualityranks,"piotroski_fscore"=>$piotroski_fscore,"altman_zscore"=>$altman_zscore,"altman_zscore_nonman"=>$altman_zscore_nonman]);
			 else
				render("quote.php", ["title" => $_SESSION['exchange'].":".$symbol,"symbol"=>$symbol,"valuation"=>$valuation,"industry_valuation"=>$industry_valuation
					,"piotroski_variables"=>$piotroski_variables,"altman_variables"=>$altman_variables,"altman_nonman_variables"=>$altman_nonman_variables
					,"relative_sector_valuations"=>$relative_sector_valuations,"relative_industry_valuations"=>$relative_industry_valuations
					,"share_info"=> $share_info, "quote" => $quote,"chart"=>$chart,"timespan"=>$timespan,"type"=>$type,"incomestatement"=>$income_statement
					,"balancesheet"=>$balance_sheet,"cashflowstatement"=>$cash_flow_statement,"momentum_statistics"=>$momentum_statistics
					,"growth_statistics"=>$growth_statistics,"value_statistics"=>$value_statistics,"quality_statistics"=>$quality_statistics
					,"scores"=>$scores,"valueranks"=>$valueranks,"momentumranks"=>$momentumranks,"qualityranks"=>$qualityranks
					,"piotroski_fscore"=>$piotroski_fscore,"altman_zscore"=>$altman_zscore,"altman_zscore_nonman"=>$altman_zscore_nonman]);

			
			write_log("quote.php","End render form");
		
		}
		else
			render("quote_form.php", ["title" => "Stock Quote"]);
    }

	else {  
		 //get stock symbols
        //$stock_symbols = query("SELECT symbol, description FROM stock_symbols where enabled='Y'");
        // else render form
        //render("quote_form.php", ["title" => "Stock Quote","symbols" => $stock_symbols]);
		  render("quote_form.php", ["title" => "Stock Quote"]);
    }

?>
