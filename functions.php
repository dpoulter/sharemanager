<?php

    /**
     * functions.php
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

        // open connection to Yahoo
        $handle = @fopen("http://download.finance.yahoo.com/d/quotes.csv?f=snl1j3s1c1mjk&s=$symbol", "r");
        if ($handle === false)
        {
            // trigger (big, orange) error
            trigger_error("Could not connect to Yahoo!", E_USER_ERROR);
            exit;
        }

        // download first line of CSV file
        $data = fgetcsv($handle, 1000, ",");
        if ($data === false )
        {
            return false;
        }
        
        // ensure symbol was found
        if ($data[2] === "0.00")
        {
            return false;
        }
        
        $share_info=[
            "symbol" => $symbol,
            "name" => $data[1],
            "price" => $data[2],
            "shares" => change_number($data[4]),
            "change" => $data[5],
            "day_range" => $data[6],
            "52w_low" => $data[7],
            "52w_high" => $data[8]
        ];
        
               
        // close connection to Yahoo
        fclose($handle);

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
        // if template exists, render it
        if (file_exists("../templates/$template"))
        {
            // extract variables into local scope
            extract($values);

            // render header
            require("../templates/header.php");

            // render template
            require("../templates/$template");

            // render footer
            require("../templates/footer.php");
        }

        // else err
        else
        {
            trigger_error("Invalid template: $template", E_USER_ERROR);
        }
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
		query("insert into message_log(module,message_text) values (?,?)",$module,$text);
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
				
				$mail->setFrom('dp@yandex.com', 'Share Portfolio Manager');
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

?>
