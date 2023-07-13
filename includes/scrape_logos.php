<?php
  require_once("constants.php");
  include("functions.php");
  include("share_functions.php");
	
	//Function get item values
	function getValues($rowdata,$name,$numCols){
		$values=[];
		for ($i=0;$i<count($rowdata);$i++){
		    //echo "value=$rowdata[$i] \r\n";
			$pos=strpos($rowdata[$i],$name);
			if ($pos === false)
				null;
			else {
				for ($j=0;$j<$numCols;$j++){
					$i++;
					$values[$j]=str_replace('-','',str_replace(',','',trim($rowdata[$i],chr(0xC2).chr(0xA0))));
					//echo "value=$values[$j] \r\n";
				}
			}
		}
		return $values;
	}
	
	// Function for scraping content between two strings
	function scrapeBetween($item, $start, $end) {
	  if (($startPos = stripos($item, $start)) === false) {  // If $start string is not found
		return false;  // Return false
	  } 
	  else 
		if (($endPos = stripos($item, $end)) === false) {  // If $end string is not found
			return false;  // Return false
		} 
	  else {
		$substrStart = $startPos + strlen($start);
		// Assigning start position
		echo " Start pos= ".$startPos. " endPos= ".($endPos); 
		return substr($item, $substrStart, $endPos - $substrStart);  // Returning string between start and end positions
	  }
	}

	//Function to make GET request using cURL
	function curlGet($url){
		$ch = curl_init();  //initialising cURL session
		//Setting cURL options
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
		curl_setopt($ch, CURLOPT_URL,$url);
		$results = curl_exec($ch); //Closing cURL session
		return $results; //Return the results
	}
	//Function to return XPath object
	function returnXPathObject($item){
		$xmlPageDom = new DomDocument(); //Instantiating a new DomDocument object
		@$xmlPageDom->loadHTML($item); //Loading the HTML from downloaded page
		$xmlPageXPath = new DOMXPath($xmlPageDom); //Instatiating new XPAth DOM object
		return $xmlPageXPath;  //Returning XPath object
	}



	//
	// Scrape Logo
	//
	function scrape_logo($name){
	
	
		
			
			
			// convert name so spaces are underscores
			$name=str_replace(' ', '_', $name);
			echo "name=$name \r\n";
			
			$packtBook= array(); //Declaring array to store sraped website data
			$packtPage = curlGet("https://en.wikipedia.org".$name);  //Calling function curlGET and storing returned results in $yahooPage variable		
			
			
			$packtPageXpath = returnXPathObject($packtPage); //Instatiating new XPath DOM object
			
			//Get image url
					
			$query='//td[@class="logo"]/a';
			//$query='//a[@class="image"]';
			
			$rowData=$packtPageXpath -> query($query);  
			//print_r($rowData);	
			if ($rowData->length>0){
				//For each row data item
				for ($i=0;$i<$rowData->length;$i++){
							
								$logo=trim($rowData->item($i)->firstChild->getAttribute("src"));
								//$logo=($rowData->item($i));
								echo "logo=$logo \r\n";
							
				}
			}	
			else {
				$logo='';
			}
			
			//Get Company Code
			if ($_SESSION['exchange']=='LON'){
				$html=scrapeBetween($packtPage, 'http://www.londonstockexchange', 'Industry</th>' ) ;
				echo "html=".$html;
				$company=scrapeBetween($html, 'search.html?q=', '">' ) ;
				$company=str_replace('.', '', $company).".L";			
				echo "company=".$company;
			}else if($_SESSION['exchange']=='JSE'){
				$company=scrapeBetween($packtPage, '"https://www.jse.co.za/Search?k=', '#Instruments">' ) ;
				$company=str_replace('.', '', $company);			
				echo "company=".$company;
			}
			/*$packtPageXpath = returnXPathObject($html); //Instatiating new XPath DOM object
			
			$query='//td[@class="category"]/a';
			//$query='//a[@class="image"]';
			
			$rowData=$packtPageXpath -> query($query);  //Querying for Earnings Estimate
			print_r($rowData);	
			if ($rowData->length>0){
				//For each row data item
								$company=trim($rowData->item(1)->nodeValue);
								//$logo=($rowData->item($i));
								echo "company=$company \r\n";
			}	
			else {
				$company='';
			}
			*/

			return ["company"=>$company,"logo"=>$logo];
		
	}
	
		//
	// Scrape ProfilesSearch Yahoo! for
	//
	function scrape_ftse250(){
	

			$packtBook= array(); //Declaring array to store sraped website data
			$packtPage = curlGet("https://en.wikipedia.org/wiki/FTSE_250_Index");  //Calling function curlGET and storing returned results in $yahooPage variable		
			
			
			$packtPageXpath = returnXPathObject($packtPage); //Instatiating new XPath DOM object
			
			$query='//ul/li/a';
			
			$rowData=$packtPageXpath -> query($query);  //Querying for Earnings Estimate
			//print_r($rowData);	
			if ($rowData->length>0){
				for ($i=0;$i<$rowData->length;$i++){
					$company[]=$rowData->item($i)->getAttribute("href");  //Add period ending to 2nd dimension of array
					//Just get the url part
					//$company=scrapeBetween($company, 'href="', '">');
				}
			}	
			else {
				$company='';
			}
			

			return $company;
		
	}
	
		//
	// Scrape ProfilesSearch Yahoo! for
	//
	function scrape_ftse100(){
	

			$packtBook= array(); //Declaring array to store sraped website data
			$packtPage = curlGet("https://en.wikipedia.org/wiki/FTSE_100_Index");  //Calling function curlGET and storing returned results in $yahooPage variable		
			
			
			$packtPageXpath = returnXPathObject($packtPage); //Instatiating new XPath DOM object
			
			$query='//tr/td/a';
			
			$rowData=$packtPageXpath -> query($query);  //Querying for Earnings Estimate
			//print_r($rowData);	
			if ($rowData->length>0){
				for ($i=0;$i<$rowData->length;$i++){
					$company[]=$rowData->item($i)->getAttribute("href");  //Add period ending to 2nd dimension of array
					//Just get the url part
					//$company=scrapeBetween($company, 'href="', '">');
				}
			}	
			else {
				$company='';
			}
			

			return $company;
		
	}

	//
	// Scrape Logos for JSE
	//
	function scrape_jse(){
	

			$packtBook= array(); //Declaring array to store sraped website data
			$packtPage = curlGet("https://en.wikipedia.org/wiki/List_of_companies_traded_on_the_JSE");  //Calling function curlGET and storing returned results in $yahooPage variable		
			
			
			$packtPageXpath = returnXPathObject($packtPage); //Instatiating new XPath DOM object
			
			$query='//tr/td/a';
			
			$rowData=$packtPageXpath -> query($query);  //Querying for Earnings Estimate
			//print_r($rowData);	
			if ($rowData->length>0){
				for ($i=0;$i<$rowData->length;$i=$i+3){
					$company[]=$rowData->item($i)->getAttribute("href");  //Add period ending to 2nd dimension of array
					//Just get the url part
					//$company=scrapeBetween($company, 'href="', '">');
				}
			}	
			else {
				$company='';
			}
			

			return $company;
		
	}
	
	//Set Exhchange
	$_SESSION["exchange"]='JSE';
	
	// Get Logos
	$companies=scrape_jse();
	//print_r($companies);
    //$companies=['/wiki/Adcock_Ingram']	;
	
	foreach ($companies as $company){
		//echo "company=$company \r\n";
		$logo=scrape_logo($company);
		//print("logo=".$logo."\r\n") ;		
			
		$result=query("update stock_symbols set logo=? where symbol=? and exchange=?",$logo["logo"],$logo["company"],$_SESSION["exchange"]);
	
	}
	
	//$logo=scrape_logo('JD_Sports');
			
?>