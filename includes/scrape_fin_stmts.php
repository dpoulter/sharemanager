<?php
  require_once("constants.php");
  include("functions.php");
  include("share_functions.php");
	
	//Function get item values
	function getValues($rowdata,$name){
		for ($i=0;$i<count($rowdata);$i++){
			if ($rowdata[$i]==$name){
				for ($j=0;$j<4;$j++){
					$i++;
					//Remove invalid characters
					$values[$j]=str_replace('-','',str_replace(',','',trim($rowdata[$i],chr(0xC2).chr(0xA0))));
					
					//Replace brackets for negative numbers with negative sign
					$values[$j]=str_replace(array("(",")"),array("-",""),$values[$j]);
					
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
	// Scrape Financial Statement
	//
	// Parameters: type  Valid values are "is" for Income statement or "bs" for Balance Sheet
	//
	function scrape_fin_stmt($type){
	
	
		//Get income statement for each symbol
		$symbols=query("select symbol from stock_symbols where enabled='Y'");
		foreach ($symbols as $symbol){
			$symbol=$symbol['symbol'];
			echo "symbol=$symbol";
			$packtBook= array(); //Declaring array to store sraped website data

			$packtPage = curlGet("https://uk.finance.yahoo.com/q/$type?s=$symbol&annual");  //Calling function curlGET and storing returned results in $yahooPage variable
			
			//echo $packtPage ;
			
			$packtPageXpath = returnXPathObject($packtPage); //Instatiating new XPath DOM object
			
			
			//Period Ending
			if ($type=='is')
					$query='//tr[@class="yfnc_modtitle1"]/th';
				else
					$query='//td[@class="yfnc_modtitle1"]/b';
			$period_ending=$packtPageXpath -> query($query);  //Querying for Period Ending titles
			//print_r($period_ending);
			if ($period_ending->length>0){
				//For each periond ending
				for ($i=0;$i<$period_ending->length;$i++)
					$packtBook['period_ending'][]=$period_ending->item($i)->nodeValue;  //Add period ending to 2nd dimension of array
					
				$i=0;
				foreach ($packtBook['period_ending'] as $period_ending){
					$timestamp=strtotime($period_ending);
					$end_date=date('Y-m-t',$timestamp);
					echo $end_date;
					$period=query("select period_id from financial_statement_periods where end_date = ?",$end_date);
					$periods[$i]=$period[0]['period_id'];
					$i++;
				}
				echo "period id";
				//print_r($periods);
			

			
				//Row data
				$rowData=$packtPageXpath -> query('//tr/td');  //Querying for Total Revenue
				if ($rowData->length>0)
					//For each row data item
					for ($i=0;$i<$rowData->length;$i++)
						if (strlen($rowData->item($i)->nodeValue)>0)
							$packtBook['rowData'][]=trim($rowData->item($i)->nodeValue);  //Add period ending to 2nd dimension of array
						
				//print_r($packtBook);
				
				
				
				//Get items
				if ($type=='is')
					$fin_stmt_type='income_statement';
				elseif ($type=='bs')
					$fin_stmt_type='balance_sheet';
				elseif ($type=='cf')
					$fin_stmt_type='cash_flow_statement';
				
				//echo "Type is $type";
				//echo "Financial Statment Statement is $fin_stmt_type";
				
				
					
				$items=query("select name, description from financial_statement_items where type='$fin_stmt_type'");
				foreach ($items as $item){
					//echo "Get values for item: ".$item['description'];
					$item_values=getValues($packtBook['rowData'],$item['description']);
					//print_r($item_values);
					for($i=0;$i<count($item_values);$i++){
						query("insert into financial_statement_values (symbol,item_name,value,period_id) values (?,?,?,?)",$symbol,$item['name'],$item_values[$i],$periods[$i]);
					}
				}
			
			}
		}
	}

	//
	// Scrape Analyst Estimates
	//
	// Parameters: type  
	//
	function scrape_analyst_estimates($symbol){
	
	
		
			//echo "symbol=$symbol \r\n";
			$packtBook= array(); //Declaring array to store sraped website data

			$packtPage = curlGet("https://uk.finance.yahoo.com/q/ae?s=$symbol");  //Calling function curlGET and storing returned results in $yahooPage variable
			
			//echo $packtPage ;
			
			//scrape between Earnings Est and Revenue Est
			$packtPage = scrapeBetween ($packtPage,'Earnings Est','Revenue Est');
			
			$packtPageXpath = returnXPathObject($packtPage); //Instatiating new XPath DOM object
			
			
			//Earnings Estimate			
			/*$query='//tr/th[@class="yfnc_tablehead1"]/strong';
			$category=$packtPageXpath -> query($query);  //Querying for Earnings Estimate
			print_r($category);
			if ($category->length>0){
				//For each category - search for Earnings est and then stop
				/*$i=0;
				$found=false;
				while ($i<$category->length&&$found==false){ 
					if ($category->item($i)->nodeValue==$type)  //Check if this category is Earnings Est
						$found=true;  									
					$i++;
				}	
				if ($found){     //Earnings est found so get values
					*/
					$query='//tr/td';
					$rowData=$packtPageXpath -> query($query);  //Querying for Earnings Estimate
					//print_r($rowData);	
					if ($rowData->length>0)
						//For each row data item
						for ($i=0;$i<$rowData->length;$i++)
							if (strlen($rowData->item($i)->nodeValue)>0)
								$packtBook['rowData'][]=trim($rowData->item($i)->nodeValue);  //Add period ending to 2nd dimension of array
				
					//Got the rowdata but we only interested in Year ago EPS in column 4 and 5
					//return ["current"=>$packtBook['rowData'][3],"next_year"=>$packtBook['rowData'][4]];
				//}
				
				/*    $query='//tr/td[@class="yfnc_tablehead1"]';
					$rowData=$packtPageXpath -> query($query);  //Querying for Earnings Estimate
					print_r($rowData);	
					if ($rowData->length>0)
						//For each row data item
						for ($i=0;$i<$rowData->length;$i++)
							if (strlen($rowData->item($i)->nodeValue)>0)
								$packtBook['rowData'][]=trim($rowData->item($i)->nodeValue);  //Add period ending to 2nd dimension of array
				*/
					
					//Got the rowdata and now get the values for last_eps
					$estimates=[];
					$item_values=getValues($packtBook['rowData'],'Year Ago EPS');
					array_push($estimates,['indicator'=>'last_eps','value'=>$item_values[2]]);
					
					//Got the rowdata and now get the values for eps_est_nxt_year
					$item_values=getValues($packtBook['rowData'],'Avg. Estimate');
					array_push($estimates,['indicator'=>'eps_est_nxt_year','value'=>$item_values[3]]);
					
					//return EPS Estimates
					return $estimates;
				
			
			//}
		
	}
	
	//
	// Main
	//
	
	//Delete all data
	query("delete from financial_statement_values");
	
	echo "scrape Income Statement";
	scrape_fin_stmt('is');  //scrape Income Statement
	
	echo "scrape Balance Sheet";
	scrape_fin_stmt('bs');  //scrape Balance Sheet
	
	echo "scrape Cash Flow Statement";
	scrape_fin_stmt('cf');  //scrape Cash Flow Statement
	
	
	//Get As of Date as yesterdays date
	$asOfDate=new DateTime();
	$asOfDate->sub(new DateInterval('P1D'));
	$asOfDate=date_format($asOfDate,'Y-m-d');
	
	//Delete earnings estimates from statistics for asofdate
	query("delete from statistics where date=? and indicator in ('last_eps','eps_est_nxt_year')",$asOfDate);  
	
	//Get EPS Last year for each symbol
	$symbols=query("select symbol from stock_symbols where enabled='Y' and symbol='JD.L'");
	foreach ($symbols as $symbol){
			$symbol=$symbol['symbol'];
			$estimates=scrape_analyst_estimates($symbol); 
			print_r($estimates);
			foreach ($estimates as $item)
				query("insert into statistics (date, indicator, symbol, value) values (?,?,?,?)",$asOfDate,$item['indicator'],$symbol,$item['value']);
	}
	//scrape_analyst_estimates('Revenue Est');  //Scrape Earnings Estimate	
?>
