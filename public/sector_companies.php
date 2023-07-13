<?php

    // configuration
    require("../includes/config.php"); 

	
	// if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {
        // validate submission
        if (empty($_POST["sector"]))
        {
            apologize("You must enter a sector.");
        }
		else
			$sector=$_POST["sector"];
	}
	
	//Check if sector was passed to form
	else {
		if (isset($_GET["sector"]))
			$sector=$_GET["sector"];
			
	}
	
	if (isset($sector)){
      			
			//write_log("quote.php","valuation=".$valuation['ratio']);
			$sector_companies=get_sector_companies($sector);

		
			//render form
			 render("sector_companies.php", ["title" => "Companies for $sector","sector_companies"=>$sector_companies,"sector"=>$sector]);
	
	}
?>
