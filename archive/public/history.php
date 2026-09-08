<?php

    // configuration
    require("../includes/config.php"); 
    
    //query shares held by user
    $rows = query("SELECT trx_type, price,quantity, timestamp FROM history WHERE user_id = ? ORDER BY id", $_SESSION["id"]);
    // if we found history
        if (count($rows) >= 1)
        {
            $transactions = [];
            foreach ($rows as $row)
            {
                {
                    $transactions[] = [
                        "trx_type" => $row["trx_type"],
                        "price" => $row["price"],
                        "quantity" => $row["quantity"],
                        "timestamp" => $row["timestamp"]
                    ];
                }
            }
      
           
            // render history
            render("history.php", ["title" => "Transaction History","transactions" => $transactions]);
        }
        else
            apologize("You dont have any transaction history yet.");
?>
