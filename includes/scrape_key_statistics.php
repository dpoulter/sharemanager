<?php
  require_once("constants.php");
  include("functions.php");
  include("share_functions.php");

  log_job("scrape_key_statistics");
	
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
	//Function get item values
	function getValues2($rowData,$name){
		//print_r($rowData);
		for ($i=0;$i<$rowData->length;$i++){
			if($rowData->item($i)->getElementsByTagName('th')->length>0)
				if(trim($rowData->item($i)->getElementsByTagName('th')->item(0)->nodeValue)==$name){
					return trim($rowData->item($i)->getElementsByTagName('td')->item(0)->nodeValue);
			}
		}
			//if ($rowdata[$i]==$name)
		//		return $values;
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
	// Scrape Key Statistics
	//
	function scrape_key_statistics($symbol){
		
		   $symbol=substr($symbol,0,stripos($symbol, '.L'));
			if (strlen($symbol)<3){
				$symbol=$symbol.'.';
			}
			echo "symbol=$symbol \r\n";
			$packtBook= array(); //Declaring array to store sraped website data
			//$packtPage = curlGet("https://uk.finance.yahoo.com/q/ks?s=$symbol");  //Calling function curlGET and storing returned results in $yahooPage variable
			$packtPage = curlGet("http://financials.morningstar.com/valuate/current-valuation-list.action?&t=XLON:$symbol");  
			
			
			
			//scrape between Earnings Est and Revenue Est
			//$packtPage = scrapeBetween ($packtPage,'TIDM','Finance Director');
			
			
			
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
					$query='//table/tbody/tr';
					$rowData=$packtPageXpath -> query($query);  //Querying for Earnings Estimate
					print_r($rowData);	
					/*if ($rowData->length>0)
						//For each row data item
						for ($i=0;$i<$rowData->length;$i++)
							if (strlen($rowData->item($i)->nodeValue)>0){
								$packtBook['rowData'][]=trim($rowData->item($i)->nodeValue);  //Add period ending to 2nd dimension of array
								
								}
				*/
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
					
					$statistics=[];
					
					//Get Price/Earnings
					$item_values=getValues2($rowData,'Price/Earnings');
					array_push($statistics,['indicator'=>'pe','value'=>convert_value($item_values)]);
				
					//Get Price/Book ration
					$item_values=getValues2($rowData,'Price/Book');
					array_push($statistics,['indicator'=>'price_book_ratio','value'=>convert_value($item_values)]);
					
					//Get Dividend Yield
					$item_values=getValues2($rowData,'Dividend Yield %');
					array_push($statistics,['indicator'=>'div_yield','value'=>convert_value($item_values)]);
					
					//Get Price/Cash Flow
					$item_values=getValues2($rowData,'Price/Cash Flow');
					array_push($statistics,['indicator'=>'price_free_cash_flow_per_share','value'=>convert_value($item_values)]);
					
					/*
					//Get Enterprise Value/EBITDA (ttm)
					$item_values=getValues($packtBook['rowData'],'Enterprise Value/EBITDA (ttm)',1);
					print_r($item_values);
					array_push($statistics,['indicator'=>'enterprise_value_to_ebitda','value'=>convert_value($item_values[0])/100]);
					
					//Get Return on Equity (ttm)
					$item_values=getValues($packtBook['rowData'],'Return on Equity (ttm)',1);
					print_r($item_values);
					array_push($statistics,['indicator'=>'roe_ttm','value'=>convert_value($item_values[0])]);
					
					//Get Return on Assets (ttm)
					$item_values=getValues($packtBook['rowData'],'Return on Assets (ttm)',1);
					print_r($item_values);
					array_push($statistics,['indicator'=>'roa','value'=>convert_value($item_values[0])]);
					*/
					
					//scrape between Total Equity and Triad Key Personnel
					//$packtPage = scrapeBetween ($packtPage,'Millions','Triad Key Personnel');
					
					//$query='//tr/td';
					//$rowData=$packtPageXpath -> query($query);  //Querying for Earnings Estimate
					//print_r($rowData);	
					//if ($rowData->length>0)
						//For each row data item
						//for ($i=0;$i<$rowData->length;$i++)
							//if (strlen($rowData->item($i)->nodeValue)>0)
								//$packtBook['rowData'][]=trim($rowData->item($i)->nodeValue);  //Add period ending to 2nd dimension of array
			
					//Operating Margin (ttm)
					//$item_values=getValues($packtBook['rowData'],'Operating Margin',1);
					//print_r($item_values);
					//array_push($statistics,['indicator'=>'operating_margin','value'=>convert_value($item_values[0])]);
					
					//Return on Capital Employed
					//$item_values=getValues($packtBook['rowData'],'Return on Capital Employed',1);
					//print_r($item_values);
					//array_push($statistics,['indicator'=>'roce','value'=>convert_value($item_values[0])]);
					
					//Dividend Yield
					//$item_values=getValues($packtBook['rowData'],'Dividend Yield',1);
					//print_r($item_values);
					//array_push($statistics,['indicator'=>'div_yield','value'=>convert_value($item_values[0])]);
					
					/*
					//Profit Margin (ttm)
					$item_values=getValues($packtBook['rowData'],'Profit Margin (ttm)',1);
					print_r($item_values);
					array_push($statistics,['indicator'=>'profit_margin','value'=>convert_value($item_values[0])]);
					
					
					*/
					
					//Got the rowdata and now get the values for eps_est_nxt_year
					//$item_values=getValues($packtBook['rowData'],'Avg. Estimate',4);
					//array_push($estimates,['indicator'=>'eps_est_nxt_year','value'=>$item_values[3]]);
					
					//return stastistics array
					return $statistics;
				
			
			//}
		
	}
	
	//
	// Main
	//
	
	//Delete all data
	/*query("delete from financial_statement_values");
	scrape_fin_stmt('is');  //scrape Income Statement
	scrape_fin_stmt('bs');  //scrape Balance Sheet
	*/
	
    //Get As of Date as yesterdays date
	$asOfDate=new DateTime();
	$asOfDate->sub(new DateInterval('P1D'));
	$asOfDate=date_format($asOfDate,'Y-m-d');
	
	// Clear all  statistics before as of date
	query("delete from statistics where date<=?",$asOfDate);
	
	//Set Exchange
    $_SESSION["exchange"]='LON';
	
	//Get EPS Last year for each symbol
	$symbols=query("select symbol from stock_symbols where enabled='Y' and  exchange=?",$_SESSION["exchange"]);
	foreach ($symbols as $symbol){
			$symbol=$symbol['symbol'];
			$statistics=scrape_key_statistics($symbol); 
			
			foreach ($statistics as $item) {
				
				print_r($item);
				
				
				$rows=query("select symbol from statistics where symbol=? and exchange=? and indicator=? and date=?",$symbol,$_SESSION["exchange"],$item['indicator'],$asOfDate);
				//insert record in statistics
				if (count($rows)==0){
					echo "insert into statistics";
					//echo "insert into statistics (date, indicator, symbol, exchange,value) values (".$asOfDate.",".$item['indicator'].",".$symbol.",".$item['value'].")";
					query("insert into statistics (date, indicator, symbol, exchange, value) values (?,?,?,?,?)",$asOfDate,$item['indicator'],$symbol,$_SESSION["exchange"],$item['value']);
					
				}
				//update record in statistics
				else {
					echo "update statistics";
					query("update statistics set value=? where symbol=? and exchange=? and indicator=? and date=?",$item['value'],$symbol,$_SESSION["exchange"],$item['indicator'],$asOfDate);
				}
			
			}
	}

    //Set Exchange
 /*   $_SESSION["exchange"]='JSE';
	
	//Get EPS Last year for each symbol
	$symbols=query("select symbol from stock_symbols where enabled='Y' and exchange=?",$_SESSION["exchange"]);
	foreach ($symbols as $symbol){
			$symbol=$symbol['symbol'];
			$statistics=scrape_key_statistics($symbol); 
			
			foreach ($statistics as $item) {
				
				print_r($item);
				
				
				$rows=query("select symbol from statistics where symbol=? and exchange=? and indicator=? and date=?",$symbol,$_SESSION["exchange"],$item['indicator'],$asOfDate);
				//insert record in statistics
				if (count($rows)==0){
					echo "insert into statistics";
					//echo "insert into statistics (date, indicator, symbol, exchange,value) values (".$asOfDate.",".$item['indicator'].",".$symbol.",".$item['value'].")";
					query("insert into statistics (date, indicator, symbol, exchange, value) values (?,?,?,?,?)",$asOfDate,$item['indicator'],$symbol,$_SESSION["exchange"],$item['value']);
					
				}
				//update record in statistics
				else {
					echo "update statistics";
					query("update statistics set value=? where symbol=? and exchange=? and indicator=? and date=?",$item['value'],$symbol,$_SESSION["exchange"],$item['indicator'],$asOfDate);
				}
			
			}
	}
	*/
	//scrape_analyst_estimates('Revenue Est');  //Scrape Earnings Estimate
	
?>