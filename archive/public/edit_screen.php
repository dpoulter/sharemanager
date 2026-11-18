
<?php

    // configuration
    require("../includes/config.php");

    // if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {	
	$screen_id=$_POST["screen"];
	//get screen name and description
	$screen=query("select name, description from screen where id=?",$screen_id);
	//get all screen criteria
        $criteria=query("SELECT sc.id,sc.description FROM screen_build sb, screen_criteria sc WHERE sb.criteria_id=sc.id and sb.screen_id=?",$screen_id);
	render("screen_criteria.php",["screen" => $screen[0]["name"],"description"=>$screen[0]["description"], "criterias"=>$criteria]);
    }
?>
