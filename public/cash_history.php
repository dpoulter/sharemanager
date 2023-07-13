<?php

    // configuration
    require("../includes/config.php");
  
		
			//query cash history 
			$rows= query("SELECT transaction_date date,trx_type type,amount from cash_history where  user_id = ? order by date asc", $_SESSION["id"]);
			// render edit Cash History form 
			render("cash_history.php", ["title" => "Cash History", "cash_history" => $rows]);
	
?>
