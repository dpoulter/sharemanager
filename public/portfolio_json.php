

	
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

             	 //query shares held by user
    			$rows = query("SELECT symbol FROM purchases WHERE session_id = ? ORDER BY id", $_SESSION["id"]);
   		 // if we found history
        if (count($rows) >= 1)
        {
        	
        	foreach ($rows as $row){
        		$output[]=$row;	
			}		
       

     		print (json_encode($output));
		
		}
	}
		}
    ?>	