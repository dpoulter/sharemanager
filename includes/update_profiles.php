 <?php
   require_once("share_functions.php");
   require_once("functions.php");
    
  /**
     * Returns a stock by symbol (case-insensitively) else false if not found.
     */
    function lookup_profile($symbol,$exchange)
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
		if (strpos($symbol,'.')>0){
			$symbol=substr($symbol,0,strpos($symbol,'.'));
		};
		
		
        // open connection to GOOGLE
        $string = file_get_contents("https://finance.google.com/finance?q=$exchange:$symbol&output=json");

        
        // get uncommented json string
		$arrMatches = explode('// ', $string); 
		
		// ensure symbol was found
        if (count($arrMatches)<2)
        {
            return false;
        }
		
		// decode json
		$arrJson = json_decode($arrMatches[1], true)[0]; 
		
		//Overview
		if (isset($arrJson["summary"][0]["overview"]))
			$overview=$arrJson["summary"][0]["overview"];
		else {
			$overview=null;
		}
		
		//Url
		if (isset($arrJson["summary"][0]["url"]))
			$url=$arrJson["summary"][0]["url"];
		else {
			$url=null;
		}
		
		//Management
		if (count($arrJson["management"]>0)){
			foreach($arrJson["management"] as $manager){
				$management[]=$manager["name"];
			}
		}
		else {
			$management=null;
		}
		
		//Employees
		if (isset($arrJson["keyratios"][5]["recent_quarter"]))
			$employees=$arrJson["keyratios"][5]["recent_quarter"];
		else {
			$employees=null;
		}
			
		
				
		
		
		
		$symbol = $arrJson["symbol"];
		
        
        // ensure symbol was found
        if ($arrJson["symbol"] === "")
        {
            return false;
        }
        
        $share_info=[
            "symbol" => $symbol,
            "overview" => $overview,
            "url" => $url,
            "management" => $management,
            "employees" => $employees
        ];
        

        // return stock as an associative array
        return $share_info;
    }

//Main

//Get Default Exchange
$_SESSION["exchange"]='JSE';
$exchange=$_SESSION["exchange"];
//Get symbols
$symbols=get_active_stocks($exchange);
//Update each symbol
foreach ($symbols as $symbol){
	
	$shareinfo=lookup_profile($symbol,$exchange);
	print_r($shareinfo);	
	
	//build managers field as one string
	$directors=null;
	foreach($shareinfo["management"] as $manager){
		$directors=$directors.$manager."<br>";
	} 
	$result = query("update stock_symbols set description=?, website=?, directors=?, employees=? where symbol=? and exchange=?",$shareinfo["overview"],$shareinfo["url"],$directors,$shareinfo["employees"],$symbol,$exchange);
}





 ?>