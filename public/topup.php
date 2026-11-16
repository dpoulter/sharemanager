<?php

    // configuration
    require("../includes/config.php"); 

    // if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {
        // validate submission
        if (empty($_POST["amount"]))
        {
            apologize("You haven't entered any amount of funds to deposit.");
        }
        /* The check was inverted: it apologised when the amount WAS a
           number, so "5000" was refused and "abc" was accepted and written
           to the cash book as nought. An amount here is money, so it takes
           pence as well - a deposit of 1234.56 is a deposit. */
        else if (!preg_match("/^\d+(\.\d{1,2})?$/", (string) $_POST["amount"])
                 || (float) $_POST["amount"] <= 0)
        {
            apologize("Amount must be a number greater than 0, in pounds and pence.");
        }
        else if (!preg_match("~^\d{4}-\d{2}-\d{2}$~", (string) ($_POST["date"] ?? ''))
                 || !checkdate((int) substr($_POST["date"], 5, 2),
                               (int) substr($_POST["date"], 8, 2),
                               (int) substr($_POST["date"], 0, 4)))
        {
            apologize("Please enter a valid date.");
        }
        else
        {
           /* The cash book, which is what every page means by cash.
              users.cash was a second balance that two pages maintained and
              nothing read. */
           update_cash_history($_POST["date"], 'DEPOSIT', round((float) $_POST["amount"], 4));
           redirect("performance.php");
        }    
                  
    }      
    else
    {
        // else render Deposit form
        render("deposit.php", ["title" => "Deposit Cash"]);
    }

?>
