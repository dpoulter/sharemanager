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
	// Scrape ProfilesSearch Yahoo! for
	//
	function scrape_profiles($symbol){
	
	
		
			echo "symbol=$symbol \r\n";
			$packtBook= array(); //Declaring array to store sraped website data
			$packtPage = curlGet("http://finance.yahoo.com/q/pr?s=$symbol+Profile");  //Calling function curlGET and storing returned results in $yahooPage variable		
			
			
			//Description
			$description = scrapeBetween ($packtPage,'<span class="yfi-module-title">Business Summary</span>','<div class="footer_copyright">');	
			print_r($description);	
			$packtPageXpath = returnXPathObject($description); //Instatiating new XPath DOM object
			$query='//p';
			$rowData=$packtPageXpath -> query($query);  //Querying for Earnings Estimate
			//print_r($rowData);	
			if ($rowData->length>0){
				//For each row data item
				$profile['description']=$rowData->item(0)->nodeValue;  //Add period ending to 2nd dimension of array
				
			}	
			else {
				$profile['description']='N/A';
			}
			//Website
			echo "Website";
			$website = scrapeBetween ($packtPage,'Company Websites','Search Yahoo! for');	
			//print($website);	
			$profile['website'] = scrapeBetween ($website,'href="','">Home Page');	
			//print("website=".$profile['website']."\r\n");		
			if (strlen($profile['website'])==0){
				$profile['website']='N/A';			
			}


			//Employees
			
			echo "Employees";
			$employees = scrapeBetween ($packtPage,'Full Time Employees','<span class="yfi-module-title">Business Summary</span>');	
			print_r($employees);	
			$packtPageXpath = returnXPathObject($employees); //Instatiating new XPath DOM object
			$query='//td';
			$rowData=$packtPageXpath -> query($query);  //Querying for Earnings Estimate
			print_r($rowData);	
			if ($rowData->length>0){
				//For each row data item
				$profile['employees']=$rowData->item(0)->nodeValue;  //Add period ending to 2nd dimension of array
				
			}	
			else {
				$profile['employees']='N/A';
			}
			
			//Executives
			
			echo "Executives";
			$executives = scrapeBetween ($packtPage,'<span class="yfi-module-title">Key Executives','<div class="footer_copyright">');	
			print_r($executives);	
			$packtPageXpath = returnXPathObject($executives); //Instatiating new XPath DOM object
			$query='//tr/td/b';
			$rowData=$packtPageXpath -> query($query);  //Querying for Earnings Estimate
			print_r($rowData);	
			if ($rowData->length>0){
						$profile['executives']='';
						//For each row data item
						for ($i=0;$i<$rowData->length;$i++)
							//For each row data item
							$profile['executives']=$profile['executives'].$rowData->item($i)->nodeValue.'</br>';  //Add period ending to 2nd dimension of array
				
			}	
			else {
				$profile['executives']='N/A';
			}

			return $profile;
		
	}
	

	//Get each symbol
	$symbols=query("select symbol from stock_symbols where enabled='Y' and symbol='ECWO.L'");
	foreach ($symbols as $symbol){
			$symbol=$symbol['symbol'];
			$profile=scrape_profiles($symbol); 
			$result=query("update stock_symbols set description=?, website=?,  employees=?, directors=? where symbol=?",$profile['description'],$profile['website'],$profile['employees'],$profile['executives'],$symbol);
	}
			
?>