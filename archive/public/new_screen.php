<?php

    // configuration
    require("../includes/config.php"); 
    
    //query shares held by user
    $rows = query("SELECT symbol, shares, commission, price_paid FROM shares WHERE id = ?", $_SESSION["id"]);
    // if we found stocks
        if (count($rows) >= 1)
        {
            $positions = [];
            foreach ($rows as $row)
            {
            
                //lookup price for each share
                $stock = lookup($row["symbol"]);
                if ($stock !== false)
                {
                    $positions[] = [
                        "name" => $stock["name"],
                        "price" => $stock["price"],
                        "price_paid" => $row["price_paid"], 
                        "commission" => $row["commission"],
                        "shares" => $row["shares"],
                        "symbol" => $row["symbol"],
                        "value" => number_format(($stock["price"] * $row["shares"])/100,4),
                        "profit" => number_format((($stock["price"] - $row["price_paid"] - $row["commission"]) * $row["shares"])/100,4) 
                    ];

                   
                
                }
            }
            //Increment totals
            $total_value=0;
            $total_profit=0;
            foreach ($positions as $position) {
              $total_value=$total_value+$position['value'];
              $total_profit=$total_profit+$position['profit'];
            }
            //Get cash available
            $rows = query("SELECT cash FROM users WHERE id = ?", $_SESSION["id"]);
            // first (and only) row
            $row = $rows[0];
    
           
            // render portfolio
            render("portfolio.php", ["title" => "Portfolio","positions" => $positions,"cash" => $row["cash"],"total_value" => $total_value,"total_profit" => $total_profit]);
        }
        else
            apologize("You dont have any stocks yet.");
?>
