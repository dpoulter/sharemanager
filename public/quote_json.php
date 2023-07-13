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
	
					
			if (isset($_GET["symbol"]))
					$symbol=$_GET["symbol"];
			
			if (isset($symbol)){
		      
		      //Get Stock Quote
		     // write_log("quote.php","1");
				$share_info = get_share_info($symbol);
				
				//write_log("quote.php","2");
		           
				if ($share_info!==false) {		
					
					//write_log("quote.php","3");
								
					
					print (json_encode($share_info));
					
				
							
				}
				
		    }
		}
	}

	

?>
