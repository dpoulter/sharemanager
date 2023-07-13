<?php

//Get Portfolio Overview
function get_portfolio_overview($session_id){    
	
    // query database for user
	$rows = query("SELECT * FROM performance WHERE session_id = ? and performance_date=(select max(performance_date) from performance where session_id=?)", $session_id,$session_id);

    // if we found user
    if (count($rows) == 1)
    {
        // first (and only) row
        $row = $rows[0];
		
        $cash=$row["cash"];
		$total_value=$row["total_value"];
        $total_portfolio=$row["total_holding"];
        $total_profit=$row["total_profit"];	

		// create array for portfolio
        return ["cash" => $cash,"total_value" => $total_value,"total_portfolio"=> $total_portfolio,"total_profit" => $total_profit];      
		
	}
	
}

//Get Portfolio Active Positions
function get_active_positions($session_id){
	$positions=query("select ss.symbol,ss.name, commission,dividends,price,price_paid,price_sold,profit,profit_perc,profit_raw,qty_purchased,qty_sold,value,value_raw from portfolio_performance pp,stock_symbols ss where ss.symbol=pp.symbol and active=? and session_id=? and ss.exchange=? and as_of_date = (select max(as_of_date) from portfolio_performance where session_id=?)"
					,'Y',$session_id,$_SESSION["exchange"],$session_id);
	$active_positions = [];
	foreach($positions as $position){
		$active_positions[]=[
			"name" => $position["name"],
			"price" => $position["price"],
			"price_paid" => $position["price_paid"], 
			"commission" => $position["commission"],
			"dividends" => $position["dividends"],
			"qty_purchased" => $position["qty_purchased"],
			"qty_sold" => $position["qty_sold"],
			"price_sold" => $position["price_sold"], 
			"symbol" => $position["symbol"],
			"value_raw" => $position["value_raw"],
			"value" => $position["value"],
			"profit_raw" => $position["profit_raw"],
			"profit" => $position["profit"]	,
			"profit_perc" => $position["profit_perc"] 
		] ;
	}
	return $active_positions;				
}

//Get Portfolio Inactive Positions
function get_inactive_positions($session_id){
	$positions=query("select ss.symbol,ss.name, commission,dividends,price,price_paid,price_sold,profit,profit_perc,profit_raw,qty_purchased,qty_sold,value,value_raw from portfolio_performance pp,stock_symbols ss where ss.symbol=pp.symbol and active=? and session_id=? and ss.exchange=? and as_of_date = (select max(as_of_date) from portfolio_performance where session_id=?)"
					,'N',$session_id,$_SESSION["exchange"],$session_id);
	$inactive_positions = [];
	foreach($positions as $position){
		$inactive_positions[]=[
			"name" => $position["name"],
			"price" => $position["price"],
			"price_paid" => $position["price_paid"], 
			"commission" => $position["commission"],
			"dividends" => $position["dividends"],
			"qty_purchased" => $position["qty_purchased"],
			"qty_sold" => $position["qty_sold"],
			"price_sold" => $position["price_sold"], 
			"symbol" => $position["symbol"],
			"value_raw" => $position["value_raw"],
			"value" => $position["value"],
			"profit_raw" => $position["profit_raw"],
			"profit" => $position["profit"]	,
			"profit_perc" => $position["profit_perc"] 
		] ;
	}
	return $inactive_positions;				
}


function calc_performance($asOfDate,$session_id){

 	write_log('portfolio_functions.calc_performance',"asOfDate= ".$asOfDate.", session_id= ".$session_id);
 	
	
    //query shares held by user
    $inactive_positions =[];
    $active_positions=[];
    $rows = query("SELECT symbol, sum( IF( trx_type =  'BUY', shares, 0 )) as qty_purchased, sum( IF( trx_type =  'SELL', shares, 0 )) as qty_sold,sum(commission) as commission, round(sum(price_paid*IF( trx_type =  'BUY', shares, 0 ))/sum(IF( trx_type =  'BUY', shares, 0 )),2) as price_paid, round(sum(price_paid*IF( trx_type =  'SELL', shares, 0 ))/sum(IF( trx_type =  'SELL', shares, 0 )),2) price_sold FROM purchases where purchase_date <=? and session_id = ? group by symbol", $asOfDate, $session_id);
    // if we found stocks
        if (count($rows) >= 1)
        {	write_log('portfolio_functions.calc_performance',"we found stocks numbering= ".count($rows));
		
            $positions = [];
            foreach ($rows as $row)
            {
					//dividends
					$dividends=query("select ifnull(sum(amount),0) as dividends from dividends where dividend_date<=? and session_id=? and symbol=?",$asOfDate,$session_id,$row["symbol"]);
	                //lookup price for each share
	                //$price = get_share_price($row["symbol"],$asOfDate);
	                
					if ($row["qty_purchased"]>0)
	                {
	                	
						//two arrays - one for active stocks and the other for inactive. Inactive have a value of 0
						if (($row["qty_purchased"]-$row["qty_sold"])>0) {  //we have an active position
						
							write_log('portfolio_functions.calc_performance',"insert active share = ".$row["symbol"]);
							
							$quote=lookup($row["symbol"]);
							$price=$quote["price"];
							write_log('portfolio_functions.calc_performance',"price = ".$price);
							
							if ($price !== false) {
							
								$active_positions[] = [
									"price" => $price,
									"price_paid" => $row["price_paid"], 
									"commission" => $row["commission"],
									"dividends" => $dividends[0]["dividends"],
									"qty_purchased" => $row["qty_purchased"],
									"qty_sold" => nvl($row["qty_sold"],0),
									"price_sold" => nvl($row["price_sold"],0), 
									"symbol" => $row["symbol"],
									"value_raw" => ($price * ($row["qty_purchased"]-$row["qty_sold"]))/100,
									"value" => number_format(($price * ($row["qty_purchased"]-$row["qty_sold"]))/100,2),
									"profit_raw" => (((          $price - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"],
									"profit" => number_format(((($price - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"],2) 						,
									"profit_perc" => number_format((((($price - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"])/(( $row["price_paid"] * ($row["qty_purchased"]))/100)*100,1)
								];            
							}
						}
						//else we have an inactive position
						else {
							
								write_log('portfolio_functions.calc_performance',"insert inactive share = ".$row["symbol"]);
								
								$price = get_share_price($row["symbol"],$asOfDate);
							    write_log('portfolio_functions.calc_performance',"price = ".$price);
							
				 			    if ($price !== false) {
					
									$inactive_positions[]=[
										"price" => $price,
										"price_paid" => $row["price_paid"], 
										"commission" => $row["commission"],
										"dividends" => $dividends[0]["dividends"],
										"qty_purchased" => $row["qty_purchased"],
										"qty_sold" => $row["qty_sold"],
										"price_sold" => $row["price_sold"], 
										"symbol" => $row["symbol"],
										"value_raw" => ($price * ($row["qty_purchased"]-$row["qty_sold"]))/100,
										"value" => number_format(($price * ($row["qty_purchased"]-$row["qty_sold"]))/100,2),
										"profit_raw" => (((          $price - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"],
										"profit" => number_format(((($price - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"],2) ,
										"profit_perc" => number_format((((($price - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"])/(( $row["price_paid"] * ($row["qty_purchased"]))/100)*100,1)
									];
								}
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
	            $rows = query("SELECT sum(CASE  trx_type 	WHEN 'DEPOSIT' THEN amount	WHEN 'BUY' THEN -1*amount	WHEN 'SELL' then amount	WHEN 'DELETE' then -1*amount ELSE amount  END) cash FROM cash_history WHERE transaction_date<=? and user_id=?",$asOfDate, $session_id);
	            // first (and only) row
	            $row = $rows[0];
	    
	         } 

            // render portfolio
            return  ["title" => "Portfolio","active_positions" => $active_positions,"inactive_positions"=>$inactive_positions,"cash" => $row["cash"],"total_value" => $total_value,"total_profit" => $total_profit];      
   
        }
   	}

?>