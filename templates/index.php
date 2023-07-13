<?php

    // configuration
    require("../includes/config.php"); 
    
    //query shares held by user
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
							"profit" => number_format(((($stock["price"] - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"],2) 						
							//"profit_perc" => (((($stock["price"] - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"])/(( $row["price_paid"] * ($row["qty_purchased"]-$row["qty_sold"]))/100)*100
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
							"profit" => number_format(((($stock["price"] - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"],2) 
							//"profit_perc" => (((($stock["price"] - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"])/(( $row["price_paid"] * ($row["qty_purchased"]-$row["qty_sold"]))/100)*100
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
			foreach ($inactive_positions as $position) {
			  $total_profit=$total_profit+$position['profit_raw'];
            }
            //Get cash available
            $rows = query("SELECT cash FROM users WHERE id = ?", $_SESSION["id"]);
            // first (and only) row
            $row = $rows[0];
    
           
            // render portfolio
            render("portfolio.php", ["title" => "Portfolio","positions" => $active_positions,"inactive_positions"=>$inactive_positions,"cash" => $row["cash"],"total_value" => $total_value,"total_profit" => $total_profit]);
        }
        else
            apologize("You dont have any stocks yet.");
?>
