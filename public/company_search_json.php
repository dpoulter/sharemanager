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
	
					
			if (isset($_GET["search_string"]))
					$search_string=$_GET["search_string"];
			
			if (isset($search_string)){
		      
		      //Get Stock Quote
		       write_log("company_Search_json.php","search_string=".$search_string);
		     
		     	$search_criteria=$search_string."%";
		     
		        $rows=query("SELECT symbol, name FROM stock_symbols WHERE symbol like '".$search_criteria."' or name like '".$search_criteria."'");
				
				$result=[];
				foreach ($rows as $row){
					$share=["symbol"=>$row['symbol'],"name"=>$row['name']];
					array_push($result,$share);
				}
				
				//write_log("quote.php","2");
		           
				if (!empty($result)) {		
					
					//write_log("quote.php","3");
								
					
					print (json_encode($result));
					
				
							
				}
				
		    }
		}
	}

	

?>
