<?php

    /**
     * functions.php
     *
     * Computer Science 50
     * Problem Set 7
     *
     * Helper functions.
     */

    require_once("constants.php");

    /**
     * Apologizes to user with message.
     */
    function apologize($message)
    {
        render("apology.php", ["message" => $message]);
        exit;
    }

    /**
     * Facilitates debugging by dumping contents of variable
     * to browser.
     */
    function dump($variable)
    {
        require("../templates/dump.php");
        exit;
    }

    /**
     * Logs out current user, if any.  Based on Example #1 at
     * http://us.php.net/manual/en/function.session-destroy.php.
     */
    function logout()
    {
        // unset any session variables
        $_SESSION = [];

        // expire cookie
        if (!empty($_COOKIE[session_name()]))
        {
            setcookie(session_name(), "", time() - 42000);
        }

        // destroy session
        session_destroy();
    }

    /**
     * Returns a stock by symbol (case-insensitively) else false if not found.
     */
    function lookup($symbol)
    {
        // reject symbols that start with ^
        if (preg_match("/^\^/", $symbol))
        {
            return false;
        }

        // reject symbols that contain commas
        if (preg_match("/,/", $symbol))
        {
            return false;
        }
		
		//remove .L from symbol
		/*if (strpos($symbol,'.')>0){
			$symbol=substr($symbol,0,strpos($symbol,'.'));
		};*/
		
		//Get Default Exchange
		//$exchange=$_SESSION["exchange"];
		
		//echo "symbol=$symbol";
		

        // open connection to GOOGLE
        $string = call_stock_api($symbol);
        //$string = file_get_contents("https://www.worldtradingdata.com/api/v1/stock?symbol=$symbol&api_token=ALFvINqaRaN1WSsJqL5CA6BGG79Hooi0siMCcHi1G5PUWm16f6eMa8MYD8Bi");

        
        // get uncommented json string
		//$arrMatches = explode('// ', $string); 
		
		
		// ensure symbol was found
       // if (count($arrMatches)<2)
        //{
         //   return false;
       // }
       
       
		// decode json
		//$arrJson = json_decode($arrMatches[1], true)[0]; 
		
		$arrJson = json_decode($string, true);
		
		write_log("functions","symbol=$symbol");
		
		//Symbol
	//	if (!isset($arrJson["data"][0]["symbol"])){
		    //try without .L
		    //$symbol=substr($symbol,0,strpos($symbol,'.')) ;
			write_log("functions","Trying symbol=$symbol");
    //    	$string = file_get_contents("https://www.worldtradingdata.com/api/v1/stock?symbol=$symbol&api_token=ALFvINqaRaN1WSsJqL5CA6BGG79Hooi0siMCcHi1G5PUWm16f6eMa8MYD8Bi");
	//		$arrJson = json_decode($string, true);
			write_log("functions","API returned: ".print_r($arrJson));
//		}
		
		if (!isset($arrJson["data"][0]["symbol"]))
        {
        	write_log("functions","symbol $symbol not found -  return false");
            return false;
        }
		
		
		//Name
		/*if (isset($arrJson["data"][0]["name"]))
			$name=$arrJson["data"][0]["name"];
		else {
			$name=null;
		}
		*/
		//Price
		/*if (isset($arrJson["data"][0]["price"]))
			$price=$arrJson["data"][0]["price"];
		else {
			$price=null;
		}
		*/
		//Change
		/*if (isset($arrJson["data"][0]["day_change"]))
			$change=$arrJson["data"][0]["day_change"];
		else {
			$change=null;
		}
		
		//52 Week Low
		if (isset($arrJson["data"][0]["52_week_low"]))
			$fifty_two_week_low=$arrJson["data"][0]["52_week_low"];
		else {
			$fifty_two_week_low=null;
		}
				
		//52 Week High
		if (isset($arrJson["data"][0]["52_week_high"]))
			$fifty_two_week_high=$arrJson["data"][0]["52_week_high"];
		else {
			$fifty_two_week_high=null;
		}
		
		//Market Cap
		if (isset($arrJson["data"][0]["market_cap"]))
			$market_cap=$arrJson["data"][0]["market_cap"];
		else {
			$market_cap=null;
		}
		*/
		
		
		/*
		//print_r($arrJson);
		if (isset($arrJson["keyratios"][0]["ttm"]))
			$net_profit_margin=$arrJson["keyratios"][0]["ttm"];
		else {
			$net_profit_margin=null;
		}
		
		if (isset($arrJson["keyratios"][1]["ttm"]))
			$operating_margin=$arrJson["keyratios"][1]["ttm"];
		else {
			$operating_margin=null;
		}
		
		if (isset($arrJson["keyratios"][3]["ttm"]))
			$roa=$arrJson["keyratios"][3]["ttm"];
		else {
			$roa=null;
		}
			
		
		if (isset($arrJson["keyratios"][4]["ttm"]))
			$roe_ttm=$arrJson["keyratios"][4]["ttm"];
		else 
			$roe_ttm=null
			;
		
		//print("net profit margin".$net_profit_margin);

		$symbol = $arrJson["symbol"];
		$price = $arrJson["l"];
		*/
        // download first line of CSV file
       // $data = fgetcsv($handle, 1000, ",");
       // if ($data === false )
       // {
       //     return false;
       // }
        
        // ensure symbol was found
        write_log("functions.php", "symbol=".$arrJson["data"][0]["symbol"]);
        
        //Market Cap
		if (isset($arrJson["data"][0]["close"]))
        $price=$arrJson["data"][0]["close"];
        else {
        $price=null;
        }
    
        
        $share_info=[
            "symbol" => $symbol,
            "name" => [],//$name,
            "price" => convert_value($price),
            "shares" => [],//convert_value($arrJson["shares"]),
            "change" => [],//convert_value($change),
            "day_range" => [],
            "52w_low" => [],//convert_value($fifty_two_week_low),
            "52w_high" => [],//convert_value($fifty_two_week_high),
            "pe" => [],//$arrJson["pe"],
            "profit_margin" => [],//convert_value($net_profit_margin),
            "operating_margin"=>[],//convert_value($operating_margin),
            "roa"=>[],//convert_value($roa),
            "roe_ttm"=>[],//convert_value($roe_ttm)
            "market_cap"=>[],//change_number($market_cap)
        ];
        

        // return stock as an associative array
        return $share_info;
    }

    /**
     * Executes SQL statement, possibly with parameters, returning
     * an array of all rows in result set or false on (non-fatal) error.
     */
    function query(/* $sql [, ... ] */)
    {
        // SQL statement
        $sql = func_get_arg(0);

        // parameters, if any
        $parameters = array_slice(func_get_args(), 1);

        // try to connect to database
        static $handle;
        if (!isset($handle))
        {
            try
            {
                // connect to database
                $handle = new PDO("mysql:dbname=" . DATABASE . ";host=" . SERVER, USERNAME, PASSWORD);

                // ensure that PDO::prepare returns false when passed invalid SQL
                $handle->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); 
                //$handle->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
            }
            catch (Exception $e)
            {
                // trigger (big, orange) error
                trigger_error($e->getMessage(), E_USER_ERROR);
                exit;
            }
        }

        // prepare SQL statement
        $statement = $handle->prepare($sql);
        if ($statement === false)
        {
            // trigger (big, orange) error
            trigger_error($handle->errorInfo()[2], E_USER_ERROR);
            exit;
        }

        // execute SQL statement
        $results = $statement->execute($parameters);

        // return result set's rows, if any
        if ($results !== false)
        {
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        else
        {
            return false;
        }
    }

    /**
     * Redirects user to destination, which can be
     * a URL or a relative path on the local host.
     *
     * Because this function outputs an HTTP header, it
     * must be called before caller outputs any HTML.
     */
    function redirect($destination)
    {
        // handle URL
        if (preg_match("/^https?:\/\//", $destination))
        {
            header("Location: " . $destination);
        }

        // handle absolute path
        else if (preg_match("/^\//", $destination))
        {
            $protocol = (isset($_SERVER["HTTPS"])) ? "https" : "http";
            $host = $_SERVER["HTTP_HOST"];
            header("Location: $protocol://$host$destination");
        }

        // handle relative path
        else
        {
            // adapted from http://www.php.net/header
            $protocol = (isset($_SERVER["HTTPS"])) ? "https" : "http";
            $host = $_SERVER["HTTP_HOST"];
            $path = rtrim(dirname($_SERVER["PHP_SELF"]), "/\\");
            header("Location: $protocol://$host$path/$destination");
        }

        // exit immediately since we're redirecting anyway
        exit;
    }

    /**
     * Renders template, passing in values.
     */
    function render($template, $values = [])
    {
        write_log("functions.php","Enter function render");

        // if template exists, render it
        if (file_exists("../templates/$template"))
        {
            // extract variables into local scope
            write_log("functions.php","extract variables into local scope");

            extract($values);

            // render header
            write_log("functions.php","render header");


            require("../templates/header.php");

            // render template
            write_log("functions.php","render template");
            require("../templates/$template");

            // render footer
            write_log("functions.php","render footer");
            require("../templates/footer.php");
        }

        // else err
        else
        {
            trigger_error("Invalid template: $template", E_USER_ERROR);
        }

        write_log("functions.php","LEaving function render");
    }
    /**
     * Insert transaction into history
     */
     function record_transaction($record)
     {
     
        //insert record into history table
        query("INSERT INTO history (trx_type,symbol,quantity,price,user_id) values (?,?,?,?,?)",$record["trx_type"],$record["symbol"],$record["quantity"],$record["price"],$_SESSION["id"]);
        
     
     
     }
	/**
	* Insert message into log table
	*/
	function write_log($module,$text){
        if (TRUE){
		    query("insert into message_log(module,message_text,timestamp) values (?,?,?)",$module,substr($text,0,4000),date_format(new DateTime(),'Y-m-d H:i:s'));
        }
	}
	
	//Update Password
	function update_password($encrypt,$password){
		
		$Results =query ("SELECT id FROM users where md5(90*13+id)=?",$encrypt);
		if(count($Results)>=1)
		{
    		query ( "update users set hash=? where id=?",password_hash($password,PASSWORD_DEFAULT),$Results[0]['id']);
			$message = "Password has been reset";
			echo "<script type='text/javascript'>alert('$message');</script>";
			render("login_form.php", ["title" => "Login"]);
		}
	    else
	    {
	        apologize ( 'Invalid key please try again');
	    }
	}
	
	//Send Reset Email
	function send_reset_email($email){
				
			// query database for user
	        $rows = query("SELECT * FROM users WHERE email = ?", $email);
	
	        // if we found user, check password
	        if (count($rows) == 1)
	        {
	            // first (and only) row
	            $row = $rows[0];
	
	           //send email
	           
	        
	           
	           $mail = new PHPMailer;
	
				//$mail->SMTPDebug = 3;                               // Enable verbose debug output
				
				$mail->isSMTP();                                      // Set mailer to use SMTP
				$mail->Host = SMTP_HOST;  					  // Specify main and backup SMTP servers
				$mail->SMTPAuth = true;                               // Enable SMTP authentication
				$mail->Username = SMTP_USERNAME;                 // SMTP username
				$mail->Password = SMTP_PASSWORD;                           // SMTP password
				$mail->SMTPSecure = 'tls';                            // Enable TLS encryption, `ssl` also accepted
				$mail->Port = SMTP_PORT;                                    // TCP port to connect to
				
				$mail->setFrom('dale.poulter@yandex.com', 'Share Portfolio Manager');
				$mail->addAddress($email, $row['username']);     // Add a recipient
				$mail->isHTML(true);                                  // Set email format to HTML
				
				$encrypt = md5(90*13+$row['id']);
				$site_url = SITE_URL;
				$mail->Subject = 'Forget Username or Password';
				$mail->Body    = 'Hi, <br/> <br/>Your username is '.$row['username'].' <br><br>Click here to reset your password '.$site_url.'/reset_passwd.php?encrypt='.$encrypt.'&action=reset   <br/> <br/>';
				$mail->AltBody = 'Hi, <br/> <br/>Your username is '.$row['username'].' <br><br>Click here to reset your password '.$site_url.'/reset_passwd.php?encrypt='.$encrypt.'&action=reset   <br/> <br/>';
				
				if(!$mail->send()) {
				    echo 'Message could not be sent.';
				    echo 'Mailer Error: ' . $mail->ErrorInfo;
				} else {
				    echo 'Message has been sent';
				}
	
	
	            
			
	            // render login form
	            render("login_form.php", ["title" => "Login"]);
	        }
			else
				{// else apologize
	        apologize("Invalid email address.");	
				}
		
		
	}

	//Convert negative number with brackets to number format 
	function convert_number($value) {
                //remove commas and spaces
                $new_value=str_replace(',','',trim($value));
                //echo $new_value."\r\n";
                //echo "strpos=".strpos($new_value,'(')."\r\n";
                if (strpos($new_value,'(')===0){
                       // echo "convert bracketed negative \r\n";
                        $new_value=('-'.substr($new_value,1,strpos($new_value,')')-1));
                
                }
                return $new_value;
                
        }
	
	//Convert string or number if null to the replace parameter 
	function nvl($value,$replace) {
               if (is_null($value))
               	return $replace;
			   else
			   	return $value;
	}
	
	//Call Stock API
	function call_stock_api($symbol) {
		
		write_log ('call_stock_api',"symbol=$symbol"); 
		$string = file_get_contents("http://api.marketstack.com/v1/eod/latest?symbols=$symbol&access_key=b0ac70e8c036442832769c69fbc619cb");
        write_log("call_stock_api",$string);
		return $string;
    }
    
    //Get Last Update
    function get_last_update(){
        $data = query("SELECT job_date FROM jobs where job_name='get_statistics' and job_date=(select max(job_date) from jobs where job_name='get_statistics')");
        return $data[0]['job_date'];
    }

    //Log job
    function log_job ($job_name){
        $current_date_time = date('Y-m-d H:i:s'); // Get the current date and time    
        $result=query("insert into jobs (job_name, job_date) values (?,?)",$job_name,$current_date_time);
    } 
?>
