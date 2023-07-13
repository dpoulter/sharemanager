<?php

    // configuration
    require("../includes/config.php"); 

    // if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {
        // validate submission
        if (empty($_POST["symbol"]))
        {
            apologize("You haven't selected a stock to sell.");
        }
        //check number of shares to sell is valid by ensuring it is greater than 0
        else if (!preg_match("/^\d+$/",$_POST["qty"]))
        {
            apologize("Quantity must be greater than 0 and must be whole number");
        
        }
        else
        {
            // Get number of shares held by the user
            $rows = query("SELECT * FROM shares WHERE id = ? and symbol=?", $_SESSION["id"],$_POST["symbol"]);

            // check that we could find the share
            if (count($rows) == 1)
            {
                // first (and only) row
                $row = $rows[0];

                // check quantity held by user is less than quantity requested to sell.
                if ($_POST["qty"]>$row["shares"])
                {
                    apologize("You cannot sell more than {$row["shares"]} shares");
                }
                else
                    //update balance of shares held
                {   $new_shares = $row["shares"] - $_POST["qty"];
                    query("UPDATE shares set shares=? WHERE id=? AND symbol=?",$new_shares,$_SESSION["id"],$_POST["symbol"]);
                                    
                    //get price of stock
                    $quote = lookup($_POST["symbol"]);
            
                    if ($quote!==false) 
                    {
                        //record transaction in history
                        record_transaction(["trx_type" => "SELL", "symbol" => $quote["symbol"], "quantity" => $_POST["qty"], "price" => $quote["price"]]);

                        // calculate value of shares sold
                        $value_sold = number_format($quote["price"] * $_POST["qty"],4);
                        query("UPDATE users set cash=cash+? WHERE id=?",$value_sold,$_SESSION["id"]);
                    
                        // redirect to portfolio
                        redirect("/");
                    }
                    else
                        apologize("Invalid Symbol: {$_POST["symbol"]}");
                }
            }
            else
                apologize("You do not have any shares of {$_POST["symbol"]}");
        }
    }
    else
    {
        //query all shares held by user
        $rows = query("SELECT symbol, shares FROM shares WHERE id = ?", $_SESSION["id"]);
        // if we found stocks
        if (count($rows) >= 1)
        {   $portfolio = [];
            foreach ($rows as $row)
            {
                //lookup price for each share
                $stock = lookup($row["symbol"]);
                if ($stock !== false)
                {
                    $portfolio[] = [
                        "name" => $stock["name"],
                        "price" => $stock["price"],
                        "shares" => $row["shares"],
                        "symbol" => $row["symbol"]
                    ];
                    
                }
            }
            //render sell form
            render("sell_form.php", ["title" => "Sell Shares","portfolio" => $portfolio]);
            
        }
        else
            apologize("You dont have any stocks yet.");
    }
?>
