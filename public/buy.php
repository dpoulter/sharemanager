<?php

    // configuration
    require("../includes/config.php"); 

    // if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {
        // validate submission
        if (empty($_POST["symbol"]))
        {
            apologize("You haven't selected a stock to buy.");
        }
        //check number of shares to sell is valid by ensuring it is greater than 0
        else if (!preg_match("/^\d+$/",$_POST["qty"]))
        {
            apologize("Quantity must be greater than 0 and must be whole number");
        
        }
        else if (!preg_match("~^\d{2}-\d{2}-\d{4}$~",$_POST["buyDate"])) {
            		apologize("Date format must be in DD-MM-YYYY format");
        }
        else 
        {  list($day,$month,$year) = explode('-',$_POST["buyDate"]);
           if (!checkdate($day,$month,$year)) {
	     apologize("Please enter a valid date"); 	
           }
	   else
           {
       
            //get stock quote
             $quote = lookup($_POST["symbol"]);
            
            //if symbol valid then continue to buy
            if ($quote!==false) 
                    {
                        // calculate value of shares to buy
                        $value = number_format($quote["price"] * $_POST["qty"],4);
                       
                        //Get cash available
                        $rows = query("SELECT cash FROM users WHERE id = ?", $_SESSION["id"]);
                        // first (and only) row
                        $row = $rows[0];
                        
                        //Check that there is enough cash
                        if ($row["cash"]>=$value) 
                        {
                            //add shares to portfolio
                            query("INSERT INTO shares (id, symbol, shares) VALUES(?, ?, ?) ON DUPLICATE KEY UPDATE shares = shares + VALUES(shares)",$_SESSION["id"],$quote["symbol"],$_POST["qty"]);
                            //decrease cash
                            query("UPDATE users set cash=cash-? WHERE id=?",$value,$_SESSION["id"]);
                            //record transaction in history
                            record_transaction(["trx_type" => "BUY", "symbol" => $quote["symbol"], "quantity" => $_POST["qty"], "price" => $quote["price"]]);
                        }
                        else
                            apologize("You do not have enough cash available");
                            
                        // redirect to portfolio
                        redirect("/");
                  
                  }
                    else
                        apologize("Invalid Symbol: {$_POST["symbol"]}");
               }       
               
        }    
                  
    }      
    else
    {
        // else render buy form
        render("buy_form.php", ["title" => "Buy Shares"]);
    }

?>
