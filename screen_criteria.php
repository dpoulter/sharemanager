<?php


  // configuration
    require("../includes/config.php");
	include("../includes/share_screen.php");

    // if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST"){
		$screen_id = $_POST["screen_id"];
		write_log("screen_criteria.php","screen_id=".$screen_id);
		if (isset($_POST["btnAdd"]))
		//	render("screen_criteria_form.php",["screen_id="=>$screen_id]);
			render("criteria_form.php",["screen_id" => $_POST["screen_id"]]);
		//Delete checked records
		elseif (isset($_POST["btnDelete"])){
			$deleted=delete_screen_criteria($screen_id,$_POST['delete']);
			//get new screen and criteria list
			$screen=screen($screen_id);
			$criteria=screen_criteria($screen_id);
			render("screen_criteria.php",["screen_id"=>$screen_id, "screen" => $screen["name"],"description"=>$screen["description"], "criterias"=>$criteria]);

		}
		//Buttons from the select screen criteria form
		elseif (isset($_POST["Select"])){
			//insert the criteria selected 
			insert_screen_criteria($screen_id,$_POST['select']);
			$screen=screen($screen_id);
			//Get the screen criteria to be displayed
			$criteria=screen_criteria($screen_id);
			render("screen_criteria.php",["screen_id"=>$screen_id, "screen" => $screen["name"],"description"=>$screen["description"], "criterias"=>$criteria]);
		}
		elseif (isset($_POST["Cancel"])){
			$screen=screen($screen_id);
			$criteria=screen_criteria($screen_id);
			render("screen_criteria.php",["screen_id"=>$screen_id, "screen" => $screen["name"],"description"=>$screen["description"], "criterias"=>$criteria]);
		}
			
		else apologize("No action selected");
    }
    else
	//came in some other way
    {
 			$screen=screen($screen_id);
			$criteria=screen_criteria($screen_id);
			render("screen_criteria.php",["screen_id"=>$screen_id, "screen" => $screen["name"],"description"=>$screen["description"], "criterias"=>$criteria]);

     }
?>
