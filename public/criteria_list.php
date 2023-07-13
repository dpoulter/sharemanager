<?php


  // configuration
    require("../includes/config.php");
	include("../includes/share_screen.php");

    // if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST"){
    	
		if (isset($_POST["New"])||isset($_POST["btnAdd"]))
			if(isset($_POST["screen_id"]))
				render("criteria_form.php",["screen_id" => $_POST["screen_id"]]);
			else
				render("criteria_form.php");
				
		//Delete checked records
		elseif (isset($_POST["Delete"])){
			$deleted=delete_criteria($_POST['delete']);
			render("criteria_list.php");
	}
		
	else apologize("No action selected");
    }
    elseif (isset($_GET["screen_id"])&&isset($_GET["id"]))
		render("criteria_form.php",["id" => $_GET["id"],"screen_id" => $_GET["screen_id"]]);
	else
    {
        // render criteria list
        render("criteria_list.php", ["title" => "Criteria"]);
     }
?>
