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
        //check cash is a whole positive number
        else if (preg_match("/^\d+$/",$_POST["amount"]))
        {
            apologize("Amount must be greater than 0 and must be whole number");
        
        }
        else
        {
                                  
           //increase cash
           
           //query("UPDATE users set cash=cash+? WHERE id=?",$_POST["amount"],$_SESSION["id"]);
           update_cash_history($_POST["date"],'DEPOSIT',$_POST["amount"]);                
           // redirect to portfolio
           redirect("/");
        }    
                  
    }      
    else
    {
        // else render Deposit form
        render("deposit.php", ["title" => "Deposit Cash"]);
    }

?>
