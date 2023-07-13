<?php

    // configuration
    require("../includes/config.php"); 
    include "../includes/share_screen.php";

    // if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {
	//find which button pressed
	if (isset($_POST["create"])){
        // validate submission
		write_log("criteria.php","validate submission");
        if (empty($_POST["first_operand"])&&empty($_POST["second_operand"]))
        {
            apologize("You must enter an operand.");
        }
        else
        //Insert or update criteria
        {   
			if (!empty($_POST["id"])){
				write_log("criteria.php","id=".$_POST["id"].". call update_criteria");
				$result=update_criteria($_POST);
            
				//if ($result==false) 
					//apologize("Error trying to update criteria.");
			
			}
				
			else {
				write_log("criteria.php","call insert_criteria. screen_id=".$_POST["screen_id"]);
				$criteria_id[0]=insert_criteria($_POST);
				insert_screen_criteria($_POST["screen_id"],$criteria_id);
            
				//if ($result==false) 
					//apologize("Error trying to insert new criteria.");
			}
			
			 // Go back to screen if screen_id is set
			 if (isset($_POST["screen_id"])){
				//get screen record
				$screen=query("select id,name, description from screen where id=?",$_POST["screen_id"]);
				
				//get all screen criteria
				$criteria=query("SELECT sb.screen_id,sc.id,sc.description FROM  screen_build sb, screen_criteria sc WHERE sb.criteria_id=sc.id and sb.screen_id=?",$_POST["screen_id"]);
				render("screen_criteria.php",["screen_id"=>$screen[0]["id"], "screen" => $screen[0]["name"],"description"=>$screen[0]["description"], "criterias"=>$criteria]);
			 }
			 else
				render("criteria_list.php", ["title" => "Criteria List"]);
        }

		}
		elseif (isset($_POST["cancel"])){
		
			 // Go back to screen if screen_id is set
			 if (isset($_POST["screen_id"])){
				//get screen record
				$screen=query("select id,name, description from screen where id=?",$_POST["screen_id"]);
				
				//get all screen criteria
				$criteria=query("SELECT sb.screen_id,sc.id,sc.description FROM  screen_build sb, screen_criteria sc WHERE sb.criteria_id=sc.id and sb.screen_id=?",$_POST["screen_id"]);
				render("screen_criteria.php",["screen_id"=>$screen[0]["id"], "screen" => $screen[0]["name"],"description"=>$screen[0]["description"], "criterias"=>$criteria]);
			 }
			 else
				render("criteria_list.php", ["title" => "Criteria List"]);
		}
	 }
    else
    {   //get indicators
        $indicators = query("SELECT indicator_id, name, description FROM screen_indicators");
        // else render form
        render("criteria_form.php", ["title" => "Criteria","indicators" => $indicators]);
    }

?>
