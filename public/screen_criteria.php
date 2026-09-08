<?php


  // configuration
    require("../includes/config.php");
	include_once("../includes/share_screen.php");

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
		//Buttons from the Edit Screen Critera form
		elseif (isset($_POST["btnClose"])){
			//query screens
			$rows = query("SELECT id,name, description from screen where displayed='Y'");
			// if we found history
				if (count($rows) >= 1)
				{
					$screens = [];
					foreach ($rows as $row)
					{
						{
							$screens[] = [
								"id" => $row["id"],
								"name" => $row["name"],
								"description" => $row["description"]
							];
						}
					}
		
		
					// render history
					render("screens.php", ["title" => "Screens","screens" => $screens]);
				}
				else
					apologize("You dont have any Screens yet.");
				}
    }
    else
	//Came in some other way. $screen_id is only set on the branches above, so
	//reaching here without one used to render the template with three
	//undefined variables. There is nothing to show without a screen, and
	//screen_list.php is where screens are chosen.
    {
			if (!isset($screen_id)||$screen_id===""){
				redirect("screen_list.php");
			}

 			$screen=screen($screen_id);
			$criteria=screen_criteria($screen_id);
			render("screen_criteria.php",["screen_id"=>$screen_id, "screen" => $screen["name"],"description"=>$screen["description"], "criterias"=>$criteria]);

     }
?>
