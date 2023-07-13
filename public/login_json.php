<?php

    // configuration
    require("../includes/config_android.php"); 

    // if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {
        // validate submission
        if (empty($_POST["username"]))
        {
            apologize_json("ERR_USERNAME","You must provide your username.");
        }
        else if (empty($_POST["password"]))
        {
            apologize_json("ERR_PASSWORD","You must provide your password.");
        }

        // query database for user
        $rows = query("SELECT * FROM users WHERE username = ?", $_POST["username"]);

        // if we found user, check password
        if (count($rows) == 1)
        {
            // first (and only) row
            $row = $rows[0];

            // compare hash of user's input against hash that's in database
            if (crypt($_POST["password"], $row["hash"]) == $row["hash"])
            {
                // remember that user's now logged in by storing user's ID in session
                $_SESSION["id"] = $row["id"];
			    $result=["code"=>"SUCCESS"];

            }
        }

        // else apologize
        apologize_json("ERR_INVALID_USER_PASS","Invalid username and/or password.");
    }
    else
    {
         apologize_json("ERR_LOGIN_ERROR","Unable to login");
    }

?>
