<?php

    // configuration
    require("../includes/config.php"); 

    // if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {
        // validate submission
        if (empty($_POST["username"]))
        {
            apologize("You must provide your username.");
        }
        else if (empty($_POST["password"]))
        {
            apologize("You must provide your password.");
        }

        // query database for user
        $rows = query("SELECT * FROM users WHERE username = ?", $_POST["username"]);

        // if we found user, check password
        if (count($rows) == 1)
        {
            // first (and only) row
            $row = $rows[0];

            // compare hash of user's input against hash that's in database
            if (crypt($_POST["password"], 'sharemanager') == $row["hash"])
            {
                // remember that user's now logged in by storing user's ID in session
                $_SESSION["id"] = $row["id"];
				//Accounts created before register.php set this have a null
				//exchange, which matches no statistics and shows an empty
				//dashboard. Fall back rather than carrying the null through.
				$_SESSION["exchange"] = (isset($row["default_exchange"]) && $row["default_exchange"] !== "")
				                      ? $row["default_exchange"] : default_exchange();

                // redirect to portfolio
                if (!empty($_POST["response_uri"])){
                	redirect($_POST["response_uri"]);
                }
				else{
                	redirect("performance.php");
				}
            }
            else {

                // else apologize
                apologize("Invalid password.");
            }
        }
        else{

            // else apologize
            apologize("Invalid username.");
        }
    }
    else
    {
        // else render form
        render("login_form.php", ["title" => "Log In"]);
    }

?>
