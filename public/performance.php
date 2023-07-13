<?php

    // configuration
    require("../includes/config.php"); 
	require("../includes/portfolio_functions.php");
	
	// if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {
       
			//timespan
			if (isset($_POST["selected_timespan"]))
				$timespan=$_POST["selected_timespan"];
			else
				$timespan="6m";
				
			if (isset($_POST["type"]))
				$type=$_POST["type"];
			else
				$type="l";
	}
	else {
		if (isset($_GET["selected_timespan"]))
				$timespan=$_GET["selected_timespan"];
			else
				$timespan="6m";
				
			if (isset($_GET["type"]))
				$type=$_GET["type"];
			else
				$type="l";
			

	}
    
    //query shares held by user
    $inactive_positions =get_inactive_positions($_SESSION["id"]);
    $active_positions= get_active_positions($_SESSION["id"]);
	
	/*
    $rows = query("SELECT symbol, sum( IF( trx_type =  'BUY', shares, 0 )) as qty_purchased, sum( IF( trx_type =  'SELL', shares, 0 )) as qty_sold,sum(commission) as commission, round(sum(price_paid*IF( trx_type =  'BUY', shares, 0 ))/sum(IF( trx_type =  'BUY', shares, 0 )),2) as price_paid, round(sum(price_paid*IF( trx_type =  'SELL', shares, 0 ))/sum(IF( trx_type =  'SELL', shares, 0 )),2) price_sold FROM purchases where session_id = ? group by symbol ", $_SESSION["id"]);
    // if we found stocks
        if (count($rows) >= 1)
        {
            $positions = [];
            foreach ($rows as $row)
            {
					//dividends
					$dividends=query("select ifnull(sum(amount),0) as dividends from dividends where session_id=? and symbol=?",$_SESSION["id"],$row["symbol"]);
	                //lookup price for each share
	               
	               
	                $stock = lookup($row["symbol"]);
					if ($stock !== false&&$row["qty_purchased"]>0)
	                {
						//two arrays - one for active stocks and the other for inactive. Inactive have a value of 0
						if (($row["qty_purchased"]-$row["qty_sold"])>0) {  //we have an active position
						
							$active_positions[] = [
								"name" => $stock["name"],
								"price" => $stock["price"],
								"price_paid" => $row["price_paid"], 
								"commission" => $row["commission"],
								"dividends" => $dividends[0]["dividends"],
								"qty_purchased" => $row["qty_purchased"],
								"qty_sold" => $row["qty_sold"],
								"price_sold" => $row["price_sold"], 
								"symbol" => $row["symbol"],
								"value_raw" => ($stock["price"] * ($row["qty_purchased"]-$row["qty_sold"]))/100,
								"value" => number_format(($stock["price"] * ($row["qty_purchased"]-$row["qty_sold"]))/100,2),
								"profit_raw" => ((($stock["price"] - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"],
								"profit" => number_format(((($stock["price"] - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"],2) 						,
								"profit_perc" => number_format((((($stock["price"] - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"])/(( $row["price_paid"] * ($row["qty_purchased"]))/100)*100,1)
							];            
						}
						//else we have an inactive position
						else {
					
								$inactive_positions[]=[
									"name" => $stock["name"],
									"price" => $stock["price"],
									"price_paid" => $row["price_paid"], 
									"commission" => $row["commission"],
									"dividends" => $dividends[0]["dividends"],
									"qty_purchased" => $row["qty_purchased"],
									"qty_sold" => $row["qty_sold"],
									"price_sold" => $row["price_sold"], 
									"symbol" => $row["symbol"],
									"value_raw" => ($stock["price"] * ($row["qty_purchased"]-$row["qty_sold"]))/100,
									"value" => number_format(($stock["price"] * ($row["qty_purchased"]-$row["qty_sold"]))/100,2),
									"profit_raw" => ((($stock["price"] - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"],
									"profit" => number_format(((($stock["price"] - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"],2) ,
									"profit_perc" => number_format((((($stock["price"] - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"])/(( $row["price_paid"] * ($row["qty_purchased"]))/100)*100,1)
								];
	                }
	            }
	            
	            //Increment totals
	            $total_value=0;
	            $total_profit=0;
	            foreach ($active_positions as $position) {
					  $total_value=$total_value+$position['value_raw'];
					  $total_profit=$total_profit+$position['profit_raw'];
	            }
	            if(isset($inactive_positions)) 
						foreach ($inactive_positions as $position) {
				  			$total_profit=$total_profit+$position['profit_raw'];
	            	}
	            //Get cash available
	            //$rows = query("SELECT cash FROM users WHERE id = ?", $_SESSION["id"]);
	            $rows = query("SELECT sum(CASE  trx_type 	WHEN 'DEPOSIT' THEN amount	WHEN 'BUY' THEN -1*amount	WHEN 'SELL' then amount	WHEN 'DELETE' then -1*amount ELSE amount  END) cash FROM `cash_history` WHERE user_id=?",$_SESSION["id"]);
	            // first (and only) row
	            $row = $rows[0];
	    	
	    		
	         } 
	        */ 
			 //Get portfolio Overview
	    	$portfolio = get_portfolio_overview($_SESSION["id"]);
            
            // render portfolio
            render("portfolio.php", ["title" => "Portfolio","positions" => $active_positions,"inactive_positions"=>$inactive_positions
            						,"cash" => $portfolio["cash"],"total_value" => $portfolio['total_value'],"total_profit" => $portfolio['total_profit'], "timespan"=>$timespan]);      
   
        /*}
        else
            apologize("You dont have any stocks yet.");
		 */
?>
