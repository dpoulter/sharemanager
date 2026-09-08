<?php

    // configuration
    require("../includes/config.php");
    
    // if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {
        //If $_POST["username"] or $_POST["password"] is empty or 
        //if $_POST["password"] does not equal $_POST["confirmation"], 
        //you’ll want to inform registrants of their error.
        
        //if username is empty
        if ($_POST["username"]=="")
             apologize("You must enter your username dumbass!");
        //if password is empty
        else if ($_POST["password"]=="")
            apologize("You must enter your password dumbass!");
        // password and confirmation are not the same
        else if ($_POST["password"]!=$_POST["confirmation"])
            apologize("Password and confirmation do not match.");
        //else all ok so Insert a new user into your database
        else 
        {
            //default_exchange must be set here. Without it the account has a
            //null exchange, every statistics query matches nothing, and the
            //dashboard comes up empty for the life of the account.
            $result = query("INSERT INTO users (username, hash, cash, email, default_exchange) VALUES (?,?,10000.00,?,?)",
            $_POST["username"], crypt($_POST["password"],'sharemanager'), $_POST["useremail"], default_exchange());
            
            //check if insert was successfull
            if ($result===false) 
            
                apologize("Error trying to create username.");
            else
            {  
                //get Id
                $rows = query("SELECT LAST_INSERT_ID() AS id");
                // check if we can get the last inserted id
                if (count($rows) == 1)
                {
                    // first (and only) row
                    $row = $rows[0];

                    // remember that user's now logged in by storing user's ID in session
                    $_SESSION["id"] = $row["id"];

                    //login.php sets this too. Registering logs the user straight
                    //in without going through login.php, so leaving it out here
                    //left $_SESSION["exchange"] undefined and every page that
                    //reads it raised a warning.
                    $_SESSION["exchange"] = default_exchange();

                    // redirect to portfolio
                    redirect("/");
                }
                //we have a problem
                else
                    apologize("Error trying to create username.");
             }
         }
    }
    else
    {
        //else render form
        render("register_form.php", ["title" => "Register"]);
    }
    
    ?>
