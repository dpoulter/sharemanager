<?php

    // configuration
    require("../includes/config.php"); 
    
	
    // query database for user
	$rows = query("SELECT * FROM users WHERE username = ?", 'dale');

    // if we found user, check password
    if (count($rows) == 1)
    {
        // first (and only) row
        $row = $rows[0];

        // compare hash of user's input against hash that's in database
        if (crypt('dale1973', $row["hash"]) == $row["hash"])
        {
            // remember that user's now logged in by storing user's ID in session
            $_SESSION["id"] = $row["id"];

	        $cash=$row["cash"];
			$total_value=$row["total_value"];
            $total_portfolio=$row["total_portfolio"];
            $total_profit=$row["total_profit"];	

			// render portfolio
	        $arr=["cash" => $cash,"total_value" => $total_value,"total_portfolio"=> $total_portfolio,"total_profit" => $total_profit];      
	   
		    print (json_encode($arr));

        }
					
	}
		
?>
