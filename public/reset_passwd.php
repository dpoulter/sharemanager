<?php
 // configuration

    require("../includes/config.php");
	require("../includes/PHPMailerAutoload.php");
	
      // if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST"){
    	
		if (!empty($_POST["action"]))
        {
        	$encrypt      = $_POST['action'];
    		$password     = $_POST['password'];
			$password2     =$_POST['password2'];
			
			//Check passwords match
			if ($password!=$password2){
				apologize("Passwords do not match");
			}
			else{
	    		update_password($encrypt,$password);
			}
        }
		else {
    	
		// validate submission
	        if (empty($_POST["email"]))
	        {
	            apologize("You must provide your email address.");
	        }
			else{
				//Send reset email
				$email=$_POST["email"];
				send_reset_email($email);
			}	
	   }
        
    }
	else if ($_SERVER["REQUEST_METHOD"] == "GET"){
		if (!empty($_GET["action"])&&$_GET["action"]=="reset"){		
			$encrypt = $_GET['encrypt'];
        	$Results = query("SELECT id FROM users where md5(90*13+id)=?",$encrypt);
        	if(count($Results)>=1)
        	{
 				render("password_form.php",["title" => "New Password","encrypt"=> $encrypt]);	
 				//echo "Reset password";
        	}
        	else
        	{
            	apologize  ('Invalid key please try again');
        	}
			
		}
		else

		render("reset_form.php", ["title" => "Stock Quote" ]);
		
	}

	?>