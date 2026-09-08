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
		//$exchange=session_exchange();
		
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
			//print_r needs its second argument to return a string. Without it the
			//array is printed straight onto the page and the log records "1",
			//so the quote page carried a dump of the API response.
			write_log("functions","API returned: ".print_r($arrJson, true));
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
		query("insert into message_log(module,message_text,timestamp) values (?,?,?)",$module,substr($text,0,4000),date_format(new DateTime(),'Y-m-d H:i:s'));
	}

	//Per row tracing. Off unless DEBUG_LOG is explicitly enabled in constants.php,
	//so a deployment carrying an older constants.php stays quiet rather than
	//filling message_log. Use write_log() for anything worth keeping.
	function debug_log($module,$text){
		if (defined('DEBUG_LOG')&&DEBUG_LOG==='Y'){
			write_log($module,$text);
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
	/**
	 * Latest quote for one symbol, from EODHD.
	 *
	 * Returns the response normalised into the {"data":[{...}]} envelope the
	 * previous provider used, so lookup() is unchanged. EODHD returns a flat
	 * object keyed on "code" rather than a list.
	 */
	/**
	 * EODHD settings, read defensively.
	 *
	 * public/constants.php is gitignored, so a deployment can be running a copy
	 * of constants.php that predates these constants. Referencing them directly
	 * makes that a fatal error on every page that touches a quote; this way the
	 * feature degrades and the rest of the site stays up.
	 */
	function eodhd_api_key() {
		return defined('EODHD_API_KEY') ? EODHD_API_KEY : '';
	}

	function eodhd_base_url() {
		return rtrim(defined('EODHD_BASE_URL') ? EODHD_BASE_URL : 'https://eodhd.com/api', '/');
	}

	function eodhd_exchange() {
		return defined('EODHD_EXCHANGE') ? EODHD_EXCHANGE : 'LSE';
	}

	/**
	 * The exchange an account works in, for accounts that have no explicit one.
	 *
	 * register.php never set users.default_exchange, so every account created
	 * through it had a null exchange: the dashboard queries matched nothing and
	 * the top ten panels came back empty.
	 */
	function default_exchange() {
		return defined('DEFAULT_EXCHANGE') ? DEFAULT_EXCHANGE : 'XLON';
	}

	/**
	 * The exchange the current request works in.
	 *
	 * Every page read session_exchange() directly, so a session without it
	 * produced an "Undefined array key" warning and a query matching nothing.
	 * Registering used to create exactly that, but so does any session that
	 * predates a fix, and the batch scripts set the key themselves. Reading it
	 * through here means a missing key degrades to the default instead of
	 * breaking the page.
	 */
	function session_exchange() {
		return (isset($_SESSION["exchange"]) && $_SESSION["exchange"] !== "")
		     ? $_SESSION["exchange"] : default_exchange();
	}

	/**
	 * Last close from the price history, in the shape lookup() reads.
	 *
	 * Used when no live quote is available. Reporting "Invalid Symbol" because
	 * the quote API is unreachable is wrong: the symbol is fine, the price is
	 * merely stale, and a stale price is far more useful than an error.
	 */
	function last_close_rows($symbol) {

		$rows = query("select date, price from historical_prices
		               where symbol=? and exchange=? and price is not null
		               order by date desc limit 1",
		              $symbol, session_exchange());

		if (count($rows) === 0) {
			return [];
		}

		return [[
			"symbol" => $symbol,
			"close"  => $rows[0]["price"],
			"date"   => $rows[0]["date"],
			"stale"  => true,
		]];
	}

	function call_stock_api($symbol) {

		write_log('call_stock_api',"symbol=$symbol");

		if (eodhd_api_key()===''){
			debug_log('call_stock_api','no EODHD key, using the last close');
			return json_encode(["data" => last_close_rows($symbol)]);
		}

		//stock_symbols holds the bare code; EODHD wants CODE.LSE
		$eodhd_symbol=(strpos($symbol,'.')===false) ? $symbol.'.'.eodhd_exchange() : $symbol;

		//Never log the URL: it carries the API key.
		$url=eodhd_base_url()."/real-time/".rawurlencode($eodhd_symbol)
		    ."?fmt=json&api_token=".rawurlencode(eodhd_api_key());

		$body=@file_get_contents($url);
		if ($body===false){
			write_log('call_stock_api',"request failed for $eodhd_symbol, using the last close");
			return json_encode(["data" => last_close_rows($symbol)]);
		}

		$rows = eodhd_quote_to_rows(json_decode($body,true),$symbol);
		if (count($rows) === 0) {
			write_log('call_stock_api',"no quote for $eodhd_symbol, using the last close");
			$rows = last_close_rows($symbol);
		}

		return json_encode(["data" => $rows]);
	}

	/**
	 * Normalise an EODHD real-time quote into the rows lookup() reads.
	 *
	 * Separated from the HTTP call so the mapping can be tested without a key.
	 * EODHD reports "NA" for a field it has no value for, which must not be
	 * mistaken for a price.
	 */
	function eodhd_quote_to_rows($quote,$symbol) {

		if (!is_array($quote)||!isset($quote['close'])){
			return [];
		}

		$close=$quote['close'];
		if ($close===null||$close===''||$close==='NA'||!is_numeric($close)){
			return [];
		}

		return [[
			"symbol" => $symbol,
			"close"  => $close,
			"date"   => isset($quote['timestamp'])&&is_numeric($quote['timestamp'])
			            ? date('Y-m-d',(int)$quote['timestamp']) : null,
		]];
	}
    
    //Get Last Update
    function get_last_update(){
        $data = query("SELECT job_date FROM jobs where job_name='get_statistics_asof' and job_date=(select max(job_date) from jobs where job_name='get_statistics_asof')");
        return $data[0]['job_date'];
    }

    //Log job
    function log_job ($job_name){
        $current_date_time = date('Y-m-d H:i:s'); // Get the current date and time    
        $result=query("insert into jobs (job_name, job_date) values (?,?)",$job_name,$current_date_time);
    } 
?>
