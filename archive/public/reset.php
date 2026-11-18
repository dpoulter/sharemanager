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
             apologize("You must enter your username");
		//if email address is empty
		else if ($_POST["email"]=="")
             apologize("You must enter your email address");
        //if password is empty
        else if ($_POST["password"]=="")
            apologize("You must enter your password");
        // password and confirmation are not the same
        else if ($_POST["password"]!=$_POST["confirmation"])
            apologize("Password and confirmation do not match");
        //else all ok so Insert a new user into your database
        else 
        {
            $result = query("INSERT INTO users (username,email,hash,cash) VALUES (?,?,?,0.00)",
            $_POST["username"], $_POST["email"],crypt($_POST["password"]));
            
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
