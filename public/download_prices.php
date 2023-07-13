<?php
    require("../includes/config.php"); 
 
// if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {

     //validate start date
     if (empty($_POST["start_date"]))
     {
	apologize("You must enter a valid start date");
     }
     else if (empty($_POST["end_date"]))
     //validate end date
     {
        apologize("You must enter a valid end date");
     }
     else
     {
        //Get POST values
        $start_date = $_POST["start_date"];
        $end_date = $_POST["end_date"];
 
	get_historical_prices($start_date,$end_date);

     }
   }
   else
   {
	//render form
	render("download_prices_form.php",["title" => "Download Prices"]);
   }
 ?>
</body>
</html>
