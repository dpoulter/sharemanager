<?php

/* Share Functions */

// function get_historical_prices($start_date,$end_date)

//Calculate indicator Stats
//function indicator_stats($asOfDate,$name)

///Calculate 3mnth Momentum
// function calc_momentum_3mnth($asOfDate)

//Calculate 6mnth Momentum
//function calc_momentum_6mnth($asOfDate)

//Calculate 12mnth Momentum
//function calc_momentum_12mnth($asOfDate){

//Calculate Price Momentum
//function calc_momentum($asOfDate,$mnth){

//Ten Day Moving Average
//	function tendayavg ($asOfDate)
	
//Thirty Day Moving Average
//function thirtydayavg ($asOfDate)

//Hundred Day Moving Average
//function hndrddayavg ($asOfDate)

// Calculate Earnings Growth forecast for next financial year 
//function calc_earnings_growth($asofdate)

//Get Share Statistics from Yahoo for the given Yahoo code to be assigned to the indicator.
//function get_share_statistics($asofdate,$indicator,$code)

//function symbol_exists($symbol, $list)

//function get_momentum_screen($threeMnthList,$sixMnthList,$twelveMnthList)

//function get_trend_screen($list)

//function get_momentum_screen($threeMnthList,$sixMnthList,$twelveMnthList)

//function get_earnings_growth($list)

//Get list of all indicators
//function get_indicators()

//get indicators to calculate statistics 
//function get_stats_indicators()
	
/* Returns a stock by symbol (case-insensitively) else false if not found.*/
//function share_lookup($symbol)

//function get_historical_prices($start_date,$end_date)

//Get News articles for the share symbol
//function get_articles($symbol)

//update cash balance
// function update_cash($amount) 

//Convert values containing B and k to actual number value
//function convert_value($value)

 function get_historical_prices($start_date,$end_date){
        require_once("constants.php");

	 //Get symbols
        $symbols = query("select symbol from stock_symbols where enabled='Y' order by symbol");

        for ($i=0;$i<count($symbols);$i++ )
        {
                $symbol=$symbols[$i]['symbol'] ;
                write_log("get_historical_prices", "Get historical prices for symbol " . $symbol . "<br/>");

       		//explode dates
		list($start_day,$start_month,$start_year) = explode('-',$start_date);
		$start_month=$start_month-1;
		list($end_day,$end_month,$end_year) = explode('-',$end_date);
		$end_month=$end_month-1;
		
		write_log("get_historical_prices","start_day=".$start_day.", start_month=".$start_month.", start_year=".$start_year);
      //  write_log("get_historical_prices","end_day=".$end_day.", end_month=".$end_month.", end_year=".$end_year);
		
		// open connection to Yahoo
		$url="http://real-chart.finance.yahoo.com/table.csv?s=$symbol&a=$start_month&b=$start_day&c=$start_year&d=$end_month&e=$end_day&f=$end_year&g=d&ignore=.csv";
		write_log("get_historical_prices", "URL: " . $url);
        	$handle = @fopen($url,"r");


        	if ($handle === false){
            		// trigger (big, orange) error
            		write_log("get_historical_prices", "Could not connect to Yahoo! for symbol " . $symbol);
          
        	}
        	else{
        		// download first line of CSV file
        		$data = fgetcsv($handle);
        		if ($data === false || count($data) == 1){
            			write_log("get_historical_prices", "No data for symbol " . $symbol . "<br>");
        		}
        		else{  
           			$history=array();
           			while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
              				array_push($history, ["symbol" => $symbol,
                                    				"date" => $data[0],
                                    				"price" => $data[6]]);
           			}    
        		}

	 		// close connection to Yahoo
        		fclose($handle);

        		if ($history ==false){
             			write_log("get_historical_prices", "No data for symbol " . $symbol);
        		}
        		else {       //delete prices
            			query("delete from historical_prices where symbol=? and date >= ? and date <=?",$symbol,$start_date,$end_date);
            			//insert prices
            			foreach ($history as $line){
                			query("insert into historical_prices(symbol,date,price) values (?,?,?)",$line['symbol'],$line['date'], $line['price']);
            			}
       			}
      		}
	}
 }
 
 /**
 * Convert time into decimal time.
 *
 * @param string $time The time to convert
 *
 * @return integer The time as a decimal value.
 */
function time_to_decimal($time) {
    $timeArr = explode(':', $time);
    $decTime = ($timeArr[0]*60) + ($timeArr[1]) + ($timeArr[2]/60);
 
    return $decTime;
}
 
    //Calculate indicator Stats
	function indicator_stats($asOfDate,$name,$symbol){
		$indicator=query("select * from screen_indicators where name=? and enabled='Y' order by order_number",$name);
		if(count($indicator)>0) {		
		
				$from_time = strtotime('now');
	
				if ($indicator[0]["function"]!=''){
					
		   		//Get symbols
					if (isset($symbol))
						$rows = query("select symbol from stock_symbols where enabled='Y' and symbol=?",$symbol);
					else
						$rows = query("select symbol from stock_symbols where enabled='Y' order by symbol");
						
					foreach($rows as $row) {
						
						$symbol=$row['symbol'];

						$function=$indicator[0]["function"] ;
						write_log("indicator_stats","function=".$function);
						call_user_func($function,$asOfDate,$symbol );
					}
				}
				elseif ($indicator[0]["yahoo_code"]!=''){
					get_share_statistics($asOfDate,$name,$indicator[0]["yahoo_code"],$symbol);
				}
				$to_time = strtotime('now');
				write_log("indicator_stats","Time taken: ".round(abs($to_time - $from_time),2). " seconds");
			
			}	
	}
	
	//Calculate 3mnth Momentum
    function calc_momentum_3mnth($asOfDate,$symbol){
		write_log("calc_momentum_3mnth","Call calc_momentum");
		calc_momentum($asOfDate,3,$symbol);
	}
	
   //Calculate 6mnth Momentum
    function calc_momentum_6mnth($asOfDate,$symbol ){
		 calc_momentum($asOfDate,6,$symbol );
	}

   //Calculate 12mnth Momentum
    function calc_momentum_12mnth($asOfDate,$symbol ){
		calc_momentum($asOfDate,12,$symbol );
	}

     //Calculate Price Momentum
	function calc_momentum($asOfDate,$mnth,$symbol ){
		 //Get symbols
			if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=?",$symbol);
			else
				$symbols = query("select symbol from stock_symbols where enabled='Y' order by symbol");

       		 for ($i=0;$i<count($symbols);$i++ ){
       		 	
       		 	//initialise
       		 	$max_date_prices=null;
					$min_date_prices=null;
	 				$max_date_price=null;
					$min_date_price=null;
					
               $symbol=$symbols[$i]['symbol'];
					
					//write_log("calc_momentum", "Symbol: $symbol, As of Date: $asOfDate , Months: $mnth");
					//write_log("calc_momentum", "SELECT DATE_SUB($asOfDate, INTERVAL $mnth MONTH) min_date, date max_date FROM historical_prices WHERE symbol=$symbol and date =$asOfDate");
			
		 	//get min and max date
    			//$dates = query("SELECT min( date ) min_date, max( date ) max_date FROM historical_prices WHERE symbol=? and date >= DATE_SUB(STR_TO_DATE(?, '%d-%m-%Y'), INTERVAL ? MONTH)",$symbol,$asOfDate,$mnth);
				//$dates = query("SELECT min( date ) min_date, max( date ) max_date FROM historical_prices WHERE symbol=? and date >= DATE_SUB(?, INTERVAL ? MONTH) and date <= ?" ,$symbol,$asOfDate,$mnth,$asOfDate);
				$date= query("select max(hp1.date) max_date from historical_prices hp1 where hp1.symbol=? and hp1.date<=?",$symbol,$asOfDate);
				$dates = query("SELECT DATE_SUB(?, INTERVAL ? MONTH) min_date, date max_date FROM historical_prices hp WHERE symbol=? and date =?",$asOfDate,$mnth,$symbol,$date[0]['max_date']);    			
    			if (count($dates>0)&&isset($dates[0]['min_date'])&&isset($dates[0]['max_date'])){
			//foreach ($dates as $date){
      				$min_date = $dates[0]['min_date'];
      				$max_date = $dates[0]['max_date'];
      				 //write_log("calc_momentum", " Min date: " . $min_date . "</br>");
				       //write_log("calc_momentum", " Max date: " . $max_date . "</br>");
    			
			
    			//get price at min and max date
    			while((date_create_from_format('Y-m-d', $min_date)< date_create_from_format('Y-m-d', $max_date))&&((count($max_date_prices)==0)||(count($min_date_prices)==0))) {
    				
    				//write_log("calc_momentum"," Count max date prices: " .count($max_date_prices));
    				//write_log("calc_momentum"," Count Min date prices: " .count($min_date_prices));
    				
    				
    				//write_log("calc_momentum"," Max Date : " . $max_date);
    			
    				$max_date_prices=query("select price from historical_prices where symbol=? and date=?",$symbol,$max_date);
    				
    				foreach ($max_date_prices as $price){
      				$max_date_price=$price['price'];
				  		//write_log("calc_momentum"," Max Date Price: " . $max_date_price . "</br>");
    				}
    				
					//$min_date=date_create_from_format('Y-m-d', $max_date);  
					
					

					$min_date_prices=query("select price from historical_prices where symbol=? and date=?",$symbol,$min_date);
    				foreach ($min_date_prices as $price){
      				$min_date_price=$price['price'];
      				 //write_log("calc_momentum", " Min Price: " . $min_date_price . "</br>");
    				}
    			
    				if ((count($max_date_prices)==0)||(count($min_date_prices)==0)){
    					//$max_date=date_create_from_format('Y-m-d', $max_date);  
    					//$max_date->sub(new DateInterval('P1D'));
						//$max_date=date_format($max_date, 'Y-m-d');
				
						//Set date interval
						$min_date=date_create_from_format('Y-m-d', $min_date);  
						$interval="P1D";				
	    				$min_date->add(new DateInterval($interval));
	    				$min_date=date_format($min_date, 'Y-m-d');
						//write_log("calc_momentum"," Min Date : " . $min_date);
					
					}
					
    			}
    			
    			if (isset($max_date_price)&&isset($min_date_price)){
    				
    			//calculate perc change
    			if ($min_date_price!=0){
				$perc_change=($max_date_price -  $min_date_price)/$min_date_price*100;
			}
			else {
				$perc_change=0;
			}
    			 //write_log("calc_momentum", "Perc change: " . $perc_change . "</br>");

    			//update price momentum
				 //write_log("calc_momentum", "update price momentum");
			    //write_log("calc_momentum", "symbol=$symbol, date=$max_date, indicator=$mnth");
			if ($mnth==3){
    				//$rows=query("select 3mnth from price_momentum where symbol=?",$symbol);
					$rows=query("select 1 from statistics where symbol=? and indicator='3mnth' and date=?",$symbol,$asOfDate);
				if (count($rows)==0){
					//query("insert into price_momentum (symbol, 3mnth) values (?,?)",$symbol,$perc_change);
					query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$symbol,"3mnth",$perc_change,$asOfDate);
					
				}
				else {
					//query("update price_momentum set 3mnth=? where symbol=?",$perc_change, $symbol);
					query("update statistics set value=? where symbol=? and indicator='3mnth' and date=? ",$perc_change, $symbol,$asOfDate);
				}
			}
			elseif ($mnth==6){
				//$rows=query("select 6mnth from price_momentum where symbol=?",$symbol);
				  $rows=query("select 1 from statistics where symbol=? and indicator='6mnth' and date=?",$symbol,$asOfDate);
				  if (count($rows)==0){
                                        //query("insert into price_momentum (symbol, 6mnth) values (?,?)",$symbol,$perc_change);
										query("insert into statistics (symbol, indicator, value, date) values (?,?,?,?)",$symbol,"6mnth",$perc_change,$asOfDate);
                                }
                                else {
                                        //query("update price_momentum set 6mnth=? where symbol=?",$perc_change, $symbol);
										query("update statistics set value=? where symbol=? and indicator='6mnth' and date=?",$perc_change, $symbol,$asOfDate);
                                }
			}
			elseif ($mnth==12){
                                //$rows=query("select 12mnth from price_momentum where symbol=?",$symbol);
								$rows=query("select 1 from statistics where symbol=? and indicator='12mnth' and date=?",$symbol,$asOfDate);
								//write_log("calc_momentum", "No of rows=".count($rows));
                                if (count($rows)==0){
                                       // query("insert into price_momentum (symbol, 12mnth) values (?,?)",$symbol,$perc_change);
									   			//write_log("calc_momentum", "insert into statistics (symbol, indicator, value,date) values ($symbol,12mnth,$perc_change,$asOfDate)");
									   query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$symbol,"12mnth",$perc_change,$asOfDate);
                                }
                                else {
                                        //query("update price_momentum set 12mnth=? where symbol=?",$perc_change, $symbol);
										query("update statistics set value=? where symbol=? and indicator='12mnth' and date=?",$perc_change, $symbol,$asOfDate);
                                }
                        }
	 		}
	 		
	 	}
	 	
	 }
	}
	
	//Ten Day Moving Average
	function tendayavg ($asOfDate,$symbol){
		calc_moving_avg("tendayavg",10,$asOfDate,$symbol);
	}
	
	//Thirty Day Moving Average
	function thirtydayavg ($asOfDate,$symbol){
		calc_moving_avg("thirtydayavg",30,$asOfDate,$symbol);
	}
	//Hundred Day Moving Average
	function hndrddayavg ($asOfDate,$symbol){
		calc_moving_avg("hndrddayavg",100,$asOfDate,$symbol);
	}
	//Calculate Moving Average
	function calc_moving_avg($indicator,$numDays,$asOfDate,$symbol){
		 write_log("calc_moving_avg", "$indicator: " . $indicator );
		  //Get symbols
		  if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=?",$symbol);
			else
                $symbols = query("select symbol from stock_symbols where enabled='Y' order by symbol limit 1000");

                 for ($i=0;$i<count($symbols);$i++ ){
                        $symbol=$symbols[$i]['symbol'];
						
                         write_log("calc_moving_avg", "Symbol: $symbol");
                         write_log("calc_moving_avg", "As of Date: $asOfDate");

			//reset avg
			$avg=0;
			//Get last  numDays prices 
			//$prices=query("select price from historical_prices where symbol=? and date<=STR_TO_DATE(?, '%d-%m-%Y') ORDER BY date DESC LIMIT ?",$symbol,$asOfDate,$numDays);
			$prices=query("select price from historical_prices where symbol=? and date<=? ORDER BY date DESC LIMIT ?",$symbol,$asOfDate,$numDays);
                        //sum prices
			foreach ($prices as $price){
                                $avg=$avg+$price['price'];
                        }
			//divide by Numdays to get moving average
			$avg=$avg/$numDays;

			//insert or update moving average
			
			//check if symbol exists in statistics 
		        //$rows=query("select symbol from price_momentum where symbol=?",$symbol);
				$rows=query("select symbol from statistics where symbol=? and indicator=? and date=?",$symbol,$indicator,$asOfDate);
                        //insert record in statistics
			if (count($rows)==0){
                                       
					//query("insert into price_momentum (symbol, tendayavg) values (?,?)",$symbol,$avg);
					write_log("calc_moving_avg", "insert into statistics (symbol, indicator, value, date) values ($symbol,$indicator,$avg,$asOfDate)");
					query("insert into statistics (symbol, indicator, value, date) values (?,?,?,?)",$symbol,$indicator,$avg,$asOfDate);
                                				
			}
			//update record in statistics
			else {
				 //query("update price_momentum set tendayavg=? where symbol=?",$avg, $symbol);
				query("update statistics set value=? where symbol=? and indicator=? and date=?",$avg, $symbol,$indicator,$asOfDate);
                               
             }
		}
	}

	/* 
	  Calculate Earnings Growth forecast for next financial year  
	*/
	
	function calc_earnings_growth($asofdate,$symbol){
		 //Get symbols
		 write_log("calc_earnings_growth","symbol=$symbol");
		 if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=?",$symbol);
			else
                $symbols = query("select symbol from stock_symbols where enabled='Y' order by symbol LIMIT 1000");
		 write_log("calc_earnings_growth","No of symbols=".count($symbols));
         for ($i=0;$i<count($symbols);$i++){
			write_log("calc_earnings_growth","symbol=".$symbols[$i]['symbol']);
			write_log("calc_earnings_growth","asofdate=$asofdate");
			
			$statistics=query("select indicator,value from statistics where date=? and indicator in ('last_year_eps', 'eps_est_nxt_year','eps_est_current_year','price_eps_ratio_curr_year') and symbol=?",$asofdate,$symbols[$i]['symbol']);
			if (count($statistics)>0){
				foreach($statistics as $statistic){
					if ($statistic['indicator']=='last_eps')
						$earnings_share=$statistic['value'];
					elseif ($statistic['indicator']=='eps_est_nxt_year')
						$eps_est_nxt_year=$statistic['value'];
					elseif ($statistic['indicator']=='eps_est_current_year')
						$eps_est_current_year=$statistic['value'];
					elseif ($statistic['indicator']=='price_eps_ratio_curr_year')
						$pe=$statistic['value'];
						
					write_log("calc_earnings_growth","statistic=".$statistic['indicator']);
				
				}		
			}
			//calculate percentage growth forecast
			if (isset($eps_est_nxt_year)&&isset($eps_est_current_year)&&$eps_est_current_year!=0){
				$earnings_growth=round(($eps_est_nxt_year - $eps_est_current_year)/$eps_est_current_year*100,1);
				write_log("calc_earnings_growth","earnings_growth=".$earnings_growth);
				//check if symbol for indicator exists in statistics
				$rows=query("select symbol from statistics where symbol=? and indicator=? and date=?",$symbols[$i]['symbol'],'earnings_growth',$asofdate);
				//insert record in statistics
				if (count($rows)==0){
					//query("insert into price_momentum (symbol, peg,pe,earnings_growth) values (?,?,?,?)",$symbol,$peg,$pe,$earnings_growth);
					query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$symbols[$i]['symbol'],'earnings_growth',$earnings_growth,$asofdate);
				}
				//update record in statistics
				else {
					query("update statistics set value=? where symbol=? and indicator=? and date=?",$earnings_growth,$symbols[$i]['symbol'],'earnings_growth',$asofdate);
				}
				//Calculate PEG based on Earnings Growth Current Year
				write_log("calc_earnings_growth","Calculate PEG based on Earnings Growth Current Year");
				
				if ((isset($earnings_growth) && $earnings_growth>0) && (isset($pe) && $pe > 0))
					$peg = round($pe/$earnings_growth,1);
				else 
					$peg = 0;
					
				write_log("calc_earnings_growth","PEG Current Year=$peg");
					
				//check if symbol for indicator exists in statistics
				write_log("calc_earnings_growth","check if symbol for indicator exists in statistics");
				$rows=query("select symbol from statistics where symbol=? and indicator=? and date=?",$symbols[$i]['symbol'],'peg_current_year',$asofdate);
				
				//insert record in statistics
				write_log("calc_earnings_growth","insert record in statistics");
				
				if (count($rows)==0){
					query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$symbols[$i]['symbol'],'peg_current_year',$peg,$asofdate);
				}
					
				else {
					//update record in statistics
					write_log("calc_earnings_growth","update record in statistics");
					query("update statistics set value=? where symbol=? and indicator=? and date=?",$peg,$symbols[$i]['symbol'],'peg_current_year',$asofdate);
				}
			}
		 }
	
	}
	//get 5 year forecast growth average using PEG (5 years) ratio for each share and current PE ration
	
	function calc_earnings_growth_5yr($asofdate,$symbol){
		 //Get symbols
		 write_log("calc_earnings_growth_5yr","symbol=$symbol");
		 if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=?",$symbol);
			else
                $symbols = query("select symbol from stock_symbols where enabled='Y' order by symbol LIMIT 1000");
		$i=0;
		 write_log("calc_earnings_growth_5yr","No of symbols=".count($symbols));
         while ($i<count($symbols)){
			$list_count=0;
			$symbol_list="LSE.L";
			while ($list_count<=100 && $i<count($symbols)){
                        	$symbol_list=$symbol_list . "+" . $symbols[$i]['symbol'];
				$list_count=$list_count+1;
				$i=$i + 1;
			}
        		// open connection to Yahoo
			$url="http://finance.yahoo.com/d/quotes.csv?s=$symbol_list&f=sr5r";
        		$handle = @fopen($url,'r');
		        if ($handle === false){
            			// trigger (big, orange) error
            			 write_log("calc_earnings_growth_5yr", "Could not connect to Yahoo! with url: " . $url . "<br>");
        		}
        		else {
        			// download first line of CSV file
        			$data = fgetcsv($handle);
       			 	if ($data === false || count($data) == 1){
            				write_log("calc_earnings_growth_5yr",  "No data for symbol list" . $symbol_list . "<br>");
				}
        			else {
           				$ratios=array();
           				while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
              					array_push($ratios, ["symbol" => $data[0],
                                    				     "peg" => $data[1],
                                                		     "pe" => $data[2]]);
					}
		                        //insert ratios into table
                		        foreach($ratios as $ratio){
                                		//calculate  Earnings Growth
                                		$symbol=$ratio['symbol'];
                                		$peg = $ratio['peg'];
                                		$pe = $ratio['pe'];
						write_log("calc_earnings_growth_5yr",  "peg= " . $peg . "<br>");
						write_log("calc_earnings_growth_5yr",  "pe= " . $pe . "<br>");
                                		if ((isset($peg) && $peg>0) && (isset($pe) && $pe > 0)){
                                        		$earnings_growth=$pe/$peg;

							write_log("calc_earnings_growth_5yr",  "Got earnings growth for symbol ". $symbol. " = " . $earnings_growth . "<br>");
                                		}
                                		else {
                                        		$earnings_growth=0;
                                		}
										
										$statistics=[["symbol"=>$symbol,"indicator"=>"peg","value"=>$peg],["symbol"=>$symbol,"indicator"=>"pe","value"=>$pe],["symbol"=>$symbol,"indicator"=>"earnings_growth_5yr","value"=>$earnings_growth]];
										
										foreach($statistics as $statistic){
											//check if symbol for indicator exists in statistics
											//$rows=query("select symbol from price_momentum where symbol=?",$symbol);
											$rows=query("select symbol from statistics where symbol=? and indicator=? and date=?",$statistic["symbol"],$statistic["indicator"],$asofdate);
											//insert record in statistics
											if (count($rows)==0){
                                        		//query("insert into price_momentum (symbol, peg,pe,earnings_growth) values (?,?,?,?)",$symbol,$peg,$pe,$earnings_growth);
												query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$statistic["symbol"],$statistic["indicator"],$statistic["value"],$asofdate);
											}
											//update record in statistics
											else {
                                        		query("update statistics set value=? where symbol=? and indicator=? and date=?",$statistic["value"],$statistic["symbol"],$statistic["indicator"],$asofdate);
											}
										}
                        		}

				}
           		}

		}
	}
	//Calculate Dividend per Share
	function dividend_share($asofdate,$symbol){
		 //Get symbols
		 
		 //write_log("dividend_share","symbol=$symbol");

		 if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=?",$symbol);
			else
          $symbols = query("select symbol from stock_symbols where enabled='Y' order by symbol LIMIT 1000");
		 	//write_log("dividend_share","No of symbols=".count($symbols));
         for ($i=0;$i<count($symbols);$i++){
			//write_log("dividend_share","symbol=".$symbols[$i]['symbol']);
			//write_log("dividend_share","asofdate=$asofdate");
			
			$statistics=query("select indicator,value from statistics where date=? and indicator in ('div_yield','previous_close') and symbol=?",$asofdate,$symbols[$i]['symbol']);
			if (count($statistics)>0){
				foreach($statistics as $statistic){
					if ($statistic['indicator']=='div_yield')
						$div_yield=$statistic['value'];
					elseif ($statistic['indicator']=='previous_close')
						$previous_close=$statistic['value'];
						
					//write_log("dividend_share","statistic=".$statistic['indicator']);
				
				}		
			}
			//calculate Dividend per share
			
			//write_log("dividend_share","previous_close=".$previous_close);
			
			if (isset($previous_close)&&isset($div_yield)){
				
				//write_log("dividend_share","div_yield=".$div_yield);
				
				$dividend_share=round($previous_close*$div_yield/100,1);
				
				//write_log("dividend_share","dividend_share=".$dividend_share);
				
				//check if symbol for indicator exists in statistics
				$rows=query("select symbol from statistics where symbol=? and indicator=? and date=?",$symbols[$i]['symbol'],'dividend_share',$asofdate);
				//insert record in statistics
				if (count($rows)==0){
					query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$symbols[$i]['symbol'],'dividend_share',$dividend_share,$asofdate);
				}
				//update record in statistics
				else {
					query("update statistics set value=? where symbol=? and indicator=? and date=?",$dividend_share,$symbols[$i]['symbol'],'dividend_share',$asofdate);
				}
			}
			}
	}
	//Calculate Dividend Cover
	function dividend_cover($asofdate,$symbol){
		 //Get symbols
		 //write_log("dividend_cover","symbol=$symbol");
		 
		 if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=?",$symbol);
			else
                $symbols = query("select symbol from stock_symbols where enabled='Y' order by symbol LIMIT 1000");
		   //write_log("dividend_cover","No of symbols=".count($symbols));
         
         for ($i=0;$i<count($symbols);$i++){
			//write_log("dividend_cover","symbol=".$symbols[$i]['symbol']);
			//write_log("dividend_cover","asofdate=$asofdate");
			
			$statistics=query("select indicator,value from statistics where date=? and indicator in ('earnings_share','dividend_share') and symbol=?",$asofdate,$symbols[$i]['symbol']);
			if (count($statistics)>0){
				foreach($statistics as $statistic){
					if ($statistic['indicator']=='earnings_share')
						$earnings_share=$statistic['value'];
					elseif ($statistic['indicator']=='dividend_share')
						$dividend_share=$statistic['value'];
						
					//write_log("dividend_cover","statistic=".$statistic['indicator']);
				
				}		
			}
			//calculate Dividend Cover
			//inwrite_log("dividend_cover","earnings_share=".$earnings_share);
			//write_log("dividend_cover","dividend_share=".$dividend_share);
			if (isset($earnings_share)&&isset($dividend_share)&&($dividend_share>0)){
				$dividend_cover=round($earnings_share/$dividend_share,1);
				//write_log("dividend_cover","dividend_cover=".$dividend_cover);
				//check if symbol for indicator exists in statistics
				$rows=query("select symbol from statistics where symbol=? and indicator=? and date=?",$symbols[$i]['symbol'],'dividend_cover',$asofdate);
				//insert record in statistics
				if (count($rows)==0){
					query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$symbols[$i]['symbol'],'dividend_cover',$dividend_cover,$asofdate);
				}
				//update record in statistics
				else {
					query("update statistics set value=? where symbol=? and indicator=? and date=?",$dividend_cover,$symbols[$i]['symbol'],'dividend_cover',$asofdate);
				}
			}
			}
	}
	
	//Calculate Bearbull Dividend Yield to Cover 
	function dividend_yield_cover($asofdate,$symbol){
		 //Get symbols
		 write_log("dividend_yield_cover","symbol=$symbol");
		 if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=?",$symbol);
			else
                $symbols = query("select symbol from stock_symbols where enabled='Y' order by symbol LIMIT 1000");
		 	//write_log("dividend_yield_cover","No of symbols=".count($symbols));
         for ($i=0;$i<count($symbols);$i++){
			//write_log("dividend_yield_cover","symbol=".$symbols[$i]['symbol']);
			//write_log("dividend_yield_cover","asofdate=$asofdate");
			
			$statistics=query("select indicator,value from statistics where date=? and indicator in ('dividend_cover','div_yield') and symbol=?",$asofdate,$symbols[$i]['symbol']);
			if (count($statistics)>0){
				foreach($statistics as $statistic){
					if ($statistic['indicator']=='div_yield')
						$div_yield=$statistic['value'];
					elseif ($statistic['indicator']=='dividend_cover')
						$dividend_cover=$statistic['value'];
						
					//write_log("dividend_yield_cover","statistic=".$statistic['indicator']);
				
				}		
			}
			//calculate Dividend Yield to Dividend Cover Ratio
			if (isset($div_yield)&&isset($dividend_cover)&&($dividend_cover>0)){
				$yield_cover=round($dividend_cover/$div_yield*10,1);
				//write_log("dividend_yield_cover","yield_cover=".$yield_cover);
				//check if symbol for indicator exists in statistics
				$rows=query("select symbol from statistics where symbol=? and indicator=? and date=?",$symbols[$i]['symbol'],'yield_cover',$asofdate);
				//insert record in statistics
				if (count($rows)==0){
					query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$symbols[$i]['symbol'],'yield_cover',$yield_cover,$asofdate);
				}
				//update record in statistics
				else {
					query("update statistics set value=? where symbol=? and indicator=? and date=?",$yield_cover,$symbols[$i]['symbol'],'yield_cover',$asofdate);
				}
			}
			}
	}


	//Get Share Statistics from Yahoo for the given Yahoo code to be assigned to the indicator.
	function get_share_statistics($asofdate,$indicator,$code, $symbol){
	
	//write_log("get_share_statistics","date=$asofdate");
		 //Get symbols
            if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=?",$symbol);
			else
				$symbols = query("select symbol from stock_symbols where enabled='Y' order by symbol LIMIT 1000");
			
			//Create list of symbols			
			$i=0;
         while ($i<count($symbols)){
			$list_count=0;
			$symbol_list="LSE.L";
			while ($list_count<=50 && $i<count($symbols)){
                     	$symbol_list=$symbol_list . "+" . $symbols[$i]['symbol'];
						$list_count=$list_count+1;
						$i=$i + 1;
			}
			
        		// open connection to Yahoo
				$url="http://finance.yahoo.com/d/quotes.csv?s=$symbol_list&f=s".$code;
				//write_log("get_share_statistics","url=".$url);
        		$handle = @fopen($url,'r');
		        if ($handle === false){
            			// trigger (big, orange) error
            			 //write_log("get_share_statistics", "Could not connect to Yahoo! with url: " . $url . "<br>");
        		}
        		else {
        			// download first line of CSV file
        			$data = fgetcsv($handle);
       			 	if ($data === false || count($data) == 0){
            				//write_log("get_share_statistics",  "No data for symbol list" . $symbol_list . "<br>");
					}
        			else {
						//write_log("get_share_statistics","count data=".count($data));
						//write_log("get_share_statistics","symbol="  .$data[0]);
           				$statistics=array();
						//$j=1;
           				while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
              					array_push($statistics, ["symbol" => $data[0],
														"indicator" => $indicator ,
														"value"=> convert_value($data[1])]);
								//$j++;
						}
		                foreach($statistics as $statistic){
							//check if symbol for indicator exists in statistics
							$rows=query("select symbol from statistics where symbol=? and indicator=? and date=?",$statistic["symbol"],$statistic["indicator"],$asofdate);
							//insert record in statistics
							if (count($rows)==0){
                              		//query("insert into price_momentum (symbol, peg,pe,earnings_growth) values (?,?,?,?)",$symbol,$peg,$pe,$earnings_growth);
									query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$statistic["symbol"],$statistic["indicator"],$statistic["value"],$asofdate);
							}
							//update record in statistics
							else {
                              		query("update statistics set value=? where symbol=? and indicator=? and date=?",$statistic["value"],$statistic["symbol"],$statistic["indicator"],$asofdate);
							}
						}
               		}

				}
			}
	}
	function symbol_exists($symbol, $list){
		foreach($list as $item){
			if ($symbol==$item["symbol"])
				return true;
		}
	}

/*
	function get_momentum_screen($threeMnthList,$sixMnthList,$twelveMnthList){
		$list=array();
		foreach($threeMnthList as $threeMnth){
			$symbol=$threeMnth["symbol"];
			$symbol_exists = symbol_exists($symbol,$sixMnthList);
			if($symbol_exists){
				$symbol_exists = symbol_exists($symbol,$twelveMnthList);
				if ($symbol_exists){
					$momentum=query("select 3mnth,6mnth,12mnth from price_momentum where symbol=?",$symbol); 
					array_push($list,["symbol"=>$symbol,"threeMnth"=>$momentum[0]["3mnth"],"sixMnth"=>$momentum[0]["6mnth"],"twelveMnth"=>$momentum[0]["12mnth"]]);
					
				}
			}	
		}
		return $list;
	}	
*/
	function get_momentum_screen($threeMnthList,$sixMnthList,$twelveMnthList){
		$build_list=array();
		$threemnth=array();
		$sixmnth=array();
		$twelvemnth=array();
		$momentum_list=array();
		foreach($threeMnthList as $list)
			array_push($threemnth,["symbol"=>$list["symbol"]]);
		array_push($build_list,$threemnth);
		foreach($sixMnthList as $list)
                        array_push($sixmnth,["symbol"=>$list["symbol"]]);
//print_r($sixmnth);
		array_push($build_list,$sixmnth);
		foreach($twelveMnthList as $list)
                        array_push($twelvemnth,["symbol"=>$list["symbol"]]);
                array_push($build_list,$twelvemnth);
		//print_r($twelvemnth);
		$final_list= combine_criteria_lists($build_list);
		//print_r($final_list);
		foreach($final_list as $symbol){
			$momentum=query("select 3mnth,6mnth,12mnth from price_momentum where symbol=?",$symbol["symbol"]);
                        array_push($momentum_list,["symbol"=>$symbol["symbol"],"threeMnth"=>$momentum[0]["3mnth"],"sixMnth"=>$momentum[0]["6mnth"],"twelveMnth"=>$momentum[0]["12mnth"]]);
		}
		//print_r($momentum_list);
		return $momentum_list;

	}

	function get_trend_screen($list){
		$trend_list=array();
		foreach ($list as $share){
			//Get Moving Averages
			$averages=query("select symbol,tendayavg, thirtydayavg, hndrddayavg from price_momentum where symbol=?",$share['symbol']);
			//screen moving avg
			if ($averages[0]["tendayavg"] > $averages[0]["thirtydayavg"] && $averages[0]["thirtydayavg"] > $averages[0]["hndrddayavg"]){
				array_push($trend_list,["symbol"=>$averages[0]["symbol"],"tenday" =>$averages[0]["tendayavg"],"thirtyday"=>$averages[0]["thirtydayavg"],"hundredday"=>$averages[0]["hndrddayavg"] ]);
			}
		}
		return  $trend_list;

	}

	function get_earnings_growth($list){
		$earnings_list=array();
		//Get count of shares
		$row=query("select count(1) total from  stock_symbols where enabled='Y'");
		$count=$row[0]["total"];
		$rows=query("select symbol, 3mnth,6mnth,12mnth,tendayavg, thirtydayavg, hndrddayavg, earnings_growth from price_momentum order by earnings_growth desc limit ?",$count/4);
		foreach ($rows as $row){
			if (symbol_exists($row["symbol"],$list)){
				array_push($earnings_list,["symbol"=>$row["symbol"],"earningsgrowth"=>$row["earnings_growth"],"3mnth"=>$row["3mnth"],"6mnth"=>$row["6mnth"],"12mnth"=>$row["12mnth"],"tendayavg"=>$row["tendayavg"],"thirtydayavg"=>$row["thirtydayavg"],"hndrddayavg"=>$row["hndrddayavg"]]);
			}
		}
		return $earnings_list;
  	}

	//Get list of all indicators
	function get_indicators(){
		$category_indicators=array();
		//Get indicator categories
		$categories=query("select category_id, name, description from indicator_category");
		foreach($categories as $category){
			$indicators=query("select indicator_id, name, description from screen_indicators where category=?",$category["category_id"]);
			array_push($category_indicators,["category"=>$category,"indicators"=>$indicators]);
		}
		return $category_indicators;
	}
	
	//get indicators to calculate statistics 
	function get_stats_indicators(){
		$stats_indicators=array();
	

	//get stats pulled from yahoo
		$indicators=query("select indicator_id, name from screen_indicators where enabled='Y' and length(yahoo_code)>0");
			for ($i=0;$i<count($indicators);$i++)
				array_push($stats_indicators,$indicators[$i]);
	
	
		//get indicators defined by functions
		$indicators=query("select indicator_id, name from screen_indicators where enabled='Y' and length(function)>0");
		for ($i=0;$i<count($indicators);$i++)
				array_push($stats_indicators,$indicators[$i]);	
		return $stats_indicators;
	
	}
	
	//
	
   /* Returns a stock by symbol (case-insensitively) else false if not found.
     */
    function share_lookup($symbol)
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
		

		//initialize the share quote
		$share_quote=array();
		
		//Get As of Date as yesterdays date
		$date=new DateTime();
		$date->sub(new DateInterval('P1D'));
		$date=date_format($date,'Y-m-d');
		
		//write_log("share_lookup","date=$date");

	
		//get indicator categories .
		$categories=query("select distinct ic.category_id,ic.name,ic.description from indicator_category ic, screen_indicators si where si.category=ic.category_id order by ic.order");
		foreach ($categories as $category){
			
			//Get latest share statistics from Yahoo
		/*	if (isset($category["yahoo_code"])) {
				get_share_statistics($date,$category["name"],$category["yahoo_code"],$symbol);
			}
	*/		
			//initialize
			$indicators=array();
			
			$rows = query("SELECT
						      MAX(s2.date) max_date,
						      s2.indicator
						    FROM
						      statistics s2,
						      screen_indicators si 
						    WHERE
						      s2.symbol     =?
						    and s2.indicator    =si.name
						    AND si.category  =?
						    AND s2.date    <=?
						    group by s2.indicator",$symbol,$category["category_id"],$date);
    
    		foreach($rows as $row){

			//Get all stats for category that are non yahoo and calculated internally.
				//write_log("share_lookup","category_id=".$category["category_id"]);
				$statistics=query("select si.name, si.description, s.value, sec.value sector_average,sa.value market_average from statistics s, screen_indicators si , statistic_averages sec,statistic_averages sa, stock_symbols ss where s.indicator=si.name and sa.indicator=si.name and sec.indicator=si.name and sa.date=s.date and sec.date=s.date and sa.type='MEDIAN' and sec.type='MEDIAN' and sa.category='MARKET' and sec.category='SECTOR' and si.category=? and s.symbol=ss.symbol and ss.sector=sec.sector and s.symbol=? and s.date=? and si.name=?",$category["category_id"],$symbol,$row['max_date'],$row['indicator']);
				
				//write_log("share_lookup","No of rows =".count($statistics));
				
				//Add the indicators values to the indicator array
				foreach ($statistics as $statistic)
					array_push($indicators,["name"=>$statistic["name"],"description"=>$statistic["description"],"value"=>$statistic["value"],"market_average"=>$statistic["market_average"],"sector_average"=>$statistic["sector_average"]]);
			}
			
			//Add the indicators values to the category array
			array_push($share_quote,["category"=>["name"=>$category["name"],"description"=>$category["description"]],"indicators"=>$indicators]);

		}
		
		
		//finished processing all categories so now return the share indicators
		return $share_quote;
    }
	
	//function Get_Share_Info
	function get_share_info ($symbol){
		
		$share_query =query ("select name, market, sector from stock_symbols where symbol = ?",$symbol);
		
		if(count($share_query)>0){ 
			
			// Get Share info
			$yahoo_info = lookup($symbol);
	
			if($yahoo_info!==false) {
			
				$share_info=["symbol"=>$symbol,"name" => $share_query[0]["name"],"market" => $share_query[0]["market"],"sector" => $share_query[0]["sector"],"price" => $yahoo_info["price"],"capital" => $yahoo_info["capital"],"float" => $yahoo_info["float"],"change"=>  $yahoo_info["change"],"day_range"=>  $yahoo_info["day_range"],"52w_low"=>  $yahoo_info["52w_low"],"52w_high"=>  $yahoo_info["52w_high"]];
		
				//echo print_r($share_info);
				return $share_info;
			}
			else 
				return false;
		}
		else 
			return false;
	}
	
	//Get Profile for the share symbol
	function get_profile($symbol){
		$profile=query('SELECT description, ifnull(employees,"N/A") employees, ifnull(website,"N/A") website, ifnull(directors,"N/A") directors, logo FROM stock_symbols WHERE symbol=?',$symbol);
		//write_log("get_profile","Count = ".count($profile));
		if (count($profile)>0)
			return $profile[0];
	}
	
	//Get News articles for the share symbol
	function get_articles($symbol){
	
		 // numerically indexed array of articles
		$articles = [];
			
		//Stock Exchange Codes
		$exchanges=['LSE','LON'];
		
		//break up symbol to news format
		$share=explode(".",$symbol);
		
		//get news articles for each exchange
		foreach ($exchanges as $exchange){
			$symbol=$exchange.':'.$share[0];
		
			// headers for proxy servers
			$headers = [
				"Accept" => "*/*",
				"Connection" => "Keep-Alive",
				"User-Agent" => sprintf("curl/%s", curl_version()["version"])
			];

			// download RSS from Google News
			$context = stream_context_create([
				"http" => [
					"header" => implode(array_map(function($value, $key) { return sprintf("%s: %s\r\n", $key, $value); }, $headers, array_keys($headers))),
					"method" => "GET"
				]
			]);
			$contents = @file_get_contents("https://news.google.com/news/section?pz=1&cf=all&hl=en&q=$symbol&scoring=n&output=RSS", false, $context);
			if ($contents === false)
			{
				http_response_code(503);
				exit;
			}

			// parse RSS
			$rss = @simplexml_load_string($contents);
			@fclose($handle);
			if ($rss === false)
			{
				http_response_code(500);
				exit;
			}

			// iterate over items in channel
			foreach ($rss->channel->item as $item)
			{
				// add article to array
				$articles[] = [
					"link" => (string) $item->link,
					"title" => (string) $item->title,
					"pubdate" => (string) $item->pubDate,
					"description" => (string) $item->description
				];
			}
		}
		return $articles;
	
	}

	//Calculate average cost of share
	function avg_share_cost($symbol){
	
//	history table
//for each transaction of share
//add up all buy transactions cost or qty and price
//add up all sell transactions amout or qty and price
//substract sum of sell from sum of buy transactions
//take amount and divide by numbers of buy shares minus number of sell shares
	$avg_share_cost = query("select shares.symbol,(SELECT sum(price_paid)/sum(shares) FROM `purchases` WHERE symbol=shares.symbol and trx_type='BUY')/ (select sum(case when trx_type='BUY' then shares 
                                                                                                  when 'SELL' then -1*shares end) from purchases where symbol=shares.symbol) as avg_cost from (select distinct symbol as symbol from purchases) as shares where symbol=?",$symbol);
	 if (count($avg_share_cost)>0)
		return $avg_share_cost[0]["avg_cost"];
	}
	
	//Update Portfolio 
	function update_portfolio($symbol){
		$shares=query("select distinct symbol as symbol from purchases where symbol=?",$symbol);
		foreach ($shares as $share){
			$avg_cost = avg_share_cost($share["symbol"]);
			//check if share exists in portfolio
			$share_symbol = query("select symbol from shares where symbol=?",$share["symbol"]);
			if (count($share_symbol)>0) 
				query("update shares set avg_cost=? where symbol=?",$avg_cost, $share["symbol"]);
			else
				query("insert into shares (symbol,avg_cost) values (?,?)",$share["symbol"],$avg_cost);
		}
	
	}
	
	//Financial Statement
	function financial_statement($symbol,$type){
	
		$income_statement=array();
		$period_items=array();
	
		//Get the financial statement items
		$items=query("SELECT name, description FROM financial_statement_items WHERE type='$type'");
		//Get the periods for the symbol
		$periods=query("SELECT fsp.period_id, fsp.end_date,fsp.period_name FROM `financial_statement_values` fsv, financial_statement_items fsi, financial_statement_periods fsp   WHERE fsi.name=fsv.item_name and fsv.period_id=fsp.period_id  and fsv.symbol=?  and fsi.type=? group by fsp.period_id, fsp.end_date,fsp.period_name order by fsp.end_date desc limit 5 ",$symbol,$type);
			
		//for each item get the last 5 years maximum values
		foreach($items as $item) {
			$period_values=[];
			foreach ($periods as $period){
				//write_log('financial_statement',$symbol.' '.$period["period_id"].' '.$item["name"].' '.$item["description"]);
				$values=query("SELECT fsv.value FROM `financial_statement_values` fsv WHERE fsv.symbol=? and fsv.period_id=? and fsv.item_name=?",$symbol,$period["period_id"],$item["name"]);
				if (count($values)>0)
					$period_values[]=$values[0]["value"];
			}
			array_push($period_items,["item_name"=> $item["name"],"description"=>$item["description"],"values"=>$period_values]);
		
		}
		
		return ["periods"=> $periods,"period_items"=>$period_items];
	
	
	
	
	}
		
	
	//Income Statement
	function income_statement($symbol){
			return financial_statement($symbol,'income_statement');
	}
	
	//Balance Sheet
	function balance_sheet($symbol){
			return financial_statement($symbol,'balance_sheet');
	}
	
	function update_cash($amount) {
		query("update users set cash=cash+? where id=?",$amount,$_SESSION["id"]);
	}
	
	function update_cash_history($transaction_date, $trx_type, $amount) {
		query("insert into cash_history (transaction_date,trx_type,amount,user_id) values (?, ?, ?, ?)",$transaction_date, $trx_type, $amount,$_SESSION["id"]);
	}
	
	//Ratings
	function ratings($symbol){

		//Get As of Date as yesterdays date
		$date=new DateTime();
		$date->sub(new DateInterval('P1D'));
		$date=date_format($date,'Y-m-d');

		$rows=query("select number momentum_rating, growth_rating, value_rating, quality_rating,overall_rating from momentum_ratings where symbol=? and date=?",$symbol,$date);
		
		if (count($rows)>0){
			return $rows[0];
		}
	
	}
	
//Get momentum statistics for the Momentum Rating
function get_momentum_statistics($symbol){
	
//Get As of Date as yesterdays date
$asOfDate=new DateTime();
$asOfDate->sub(new DateInterval('P1D'));
$asOfDate=date_format($asOfDate,'Y-m-d');

write_log("ratings.php","asOfDate=$asOfDate");

$query="SELECT si.description, s.value,order_number
FROM  `screen_criteria` sc, screen_build sb, screen_indicators si, statistics s
WHERE sb.criteria_id = sc.id
AND si.indicator_id = sc.indicator_id
AND si.name = s.indicator
AND s.symbol =  '$symbol'
AND sb.screen_id
IN ( 3, 4, 5, 6, 7 )
and s.date='$asOfDate'
union
SELECT si.description, s.value,order_number
FROM  `screen_criteria` sc, screen_build sb, screen_indicators si, statistics s
WHERE sb.criteria_id = sc.id
AND si.name = sc.second_operand
AND si.name = s.indicator
AND s.symbol =  '$symbol'
AND sb.screen_id
IN ( 3, 4, 5, 6, 7 )
and s.date='$asOfDate'
order by order_number";

write_log("ratings.php","query=$query");


	$rows = query($query);

return $rows;

}

//Get statistics for the Growth Rating
function get_growth_statistics($symbol){
	
//Get As of Date as yesterdays date
$asOfDate=new DateTime();
$asOfDate->sub(new DateInterval('P1D'));
$asOfDate=date_format($asOfDate,'Y-m-d');

//write_log("ratings.php","asOfDate=$asOfDate");

	$rows = query("SELECT si.description, s.value,order_number
FROM  `screen_criteria` sc, screen_build sb, screen_indicators si, statistics s
WHERE sb.criteria_id = sc.id
AND si.indicator_id = sc.indicator_id
AND si.name = s.indicator
AND s.symbol =  ?
AND sb.screen_id
IN ( 8,9,10,11,12 )
and s.date=?
union
SELECT si.description, s.value,order_number
FROM  `screen_criteria` sc, screen_build sb, screen_indicators si, statistics s
WHERE sb.criteria_id = sc.id
AND si.name = sc.second_operand
AND si.name = s.indicator
AND s.symbol =  ?
AND sb.screen_id
IN ( 8,9,10,11,12 )
and s.date=?
order by order_number",$symbol,$asOfDate,$symbol,$asOfDate);

return $rows;

}

//Get statistics for the Value Rating
function get_value_statistics($symbol){
	
//Get As of Date as yesterdays date
$asOfDate=new DateTime();
$asOfDate->sub(new DateInterval('P1D'));
$asOfDate=date_format($asOfDate,'Y-m-d');

//write_log("ratings.php","asOfDate=$asOfDate");

	$rows = query("SELECT si.description, s.value,order_number
FROM  `screen_criteria` sc, screen_build sb, screen_indicators si, statistics s
WHERE sb.criteria_id = sc.id
AND si.indicator_id = sc.indicator_id
AND si.name = s.indicator
AND s.symbol =  ?
AND sb.screen_id
IN ( 13,14,15,16,17 )
and s.date=?
union
SELECT si.description, s.value,order_number
FROM  `screen_criteria` sc, screen_build sb, screen_indicators si, statistics s
WHERE sb.criteria_id = sc.id
AND si.name = sc.second_operand
AND si.name = s.indicator
AND s.symbol =  ?
AND sb.screen_id
IN ( 13,14,15,16,17 )
and s.date=?
order by order_number",$symbol,$asOfDate,$symbol,$asOfDate);

return $rows;

}

//Get statistics for the Quality Rating
function get_quality_statistics($symbol){
	
//Get As of Date as yesterdays date
$asOfDate=new DateTime();
$asOfDate->sub(new DateInterval('P1D'));
$asOfDate=date_format($asOfDate,'Y-m-d');

//write_log("ratings.php","asOfDate=$asOfDate");

	$rows = query("SELECT si.description, s.value,order_number
FROM  `screen_criteria` sc, screen_build sb, screen_indicators si, statistics s
WHERE sb.criteria_id = sc.id
AND si.indicator_id = sc.indicator_id
AND si.name = s.indicator
AND s.symbol =  ?
AND sb.screen_id
IN ( 18,19,20,21,22 )
and s.date=?
union
SELECT si.description, s.value,order_number
FROM  `screen_criteria` sc, screen_build sb, screen_indicators si, statistics s
WHERE sb.criteria_id = sc.id
AND si.name = sc.second_operand
AND si.name = s.indicator
AND s.symbol =  ?
AND sb.screen_id
IN ( 18,19,20,21,22)
and s.date=?
order by order_number",$symbol,$asOfDate,$symbol,$asOfDate);

return $rows;

}

//Get Momentum and Value Score
function get_scores($symbol){
	
//Get As of Date as yesterdays date
$asOfDate=new DateTime();
$asOfDate->sub(new DateInterval('P1D'));
$asOfDate=date_format($asOfDate,'Y-m-d');

//write_log("get_scores.php","asOfDate=$asOfDate");

	///init score
	$score ['momentum']=50;
	$score ['value']=50;
	
	$rows = query("SELECT indicator,value from statistics where symbol=? and date=(select max(date) from statistics where symbol=? and indicator in ('momentum_score','value_score') and date<=?) and indicator in ('momentum_score','value_score')",$symbol,$symbol,$asOfDate);
   foreach($rows as $row){
		if ($row['indicator']=='momentum_score')
			$score['momentum']=$row['value'];
		else 
			$score['value']=$row['value'];	   
   }	
   
   	//write_log("get_scores.php","value score=".$score['value']);
		
		return $score;

}
	
//Convert values containing B, K and M to actual number value
function convert_value($value){
	if (strpos($value,'B')>0)
		return 1000000000 * substr($value,0,strpos($value,'B'));
	elseif (strpos($value,'K')>0)
		return 1000 * substr($value,0,strpos($value,'K'));
	elseif (strpos($value,'k')>0)
		return 1000 * substr($value,0,strpos($value,'k'));
	elseif (strpos($value,'m')>0)
		return 1000000 * substr($value,0,strpos($value,'m'));
	elseif (strpos($value,'M')>0)
		return 1000000 * substr($value,0,strpos($value,'M'));
	else return $value;
}

//Insert or update statistic value
function update_statistic($symbol, $indicator, $value, $date){
	//check if symbol for indicator exists in statistics
	$rows=query("select symbol from statistics where symbol=? and indicator=? and date=?",$symbol,$indicator,$date);
	//insert record in statistics
	if (count($rows)==0){
		query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$symbol,$indicator,$value,$date);
	}
	//update record in statistics
	else {
		query("update statistics set value=? where symbol=? and indicator=? and date=?",$value,$symbol,$indicator,$date);
	}
}

//get value for symbol, item and date from financial statement
function get_val_from_fin_stmt($symbol, $item, $date) {
	
	//write_log("get_val_from_fin_stmt","symbol=".$symbol."Item=".$item." Date=".$date);
	
	$row=query("SELECT value * 1000 value
FROM 
financial_statement_values fsv, 
financial_statement_items fsi,
financial_statement_periods fsp 
WHERE fsv.item_name=fsi.name 
and fsp.period_id=fsv.period_id
and fsi.name=?
and fsv.symbol=?
and fsp.end_date=(
SELECT max(end_date)
FROM 
financial_statement_values fsv, 
financial_statement_items fsi ,
financial_statement_periods fsp
WHERE fsv.item_name=fsi.name 
and fsp.period_id=fsv.period_id
and fsi.name=?
and fsv.symbol=?
and fsp.end_date<=?)",$item,$symbol,$item,$symbol,$date);

 if (count($row)>0)
//We only expect one row
return $row[0]['value'];
	
}

//get indicator value from statistics for a given date
function get_indicator_value($symbol, $indicator,$date){
	$row=query("select value from statistics where symbol=? and indicator=? and date=(select max(date) max_date from statistics where symbol=? and indicator=? and date<=? and date is not null)",$symbol,$indicator,$symbol,$indicator,$date );
	if (count($row)>0)
		return  $row[0]['value'];
}

//Calculate Revenue Per Share
function calc_revenue_per_share($date,$symbol){

	//write_log("calc_revenue_per_share","symbol=".$symbol." Date=".$date);
	
	//get total_revenue from financial_statement
	$revenue=get_val_from_fin_stmt($symbol,'total_revenue',$date);
	
	//write_log("calc_revenue_per_share","revenue=".$revenue);
	
	//get number of shares
	$num_shares=get_indicator_value($symbol, 'shares_outstanding',$date);
	
	//write_log("calc_revenue_per_share","num_shares=".$num_shares);
	
	if ($num_shares>0){
		//calculate revenue per share in cents per share
		$rev_per_share=$revenue*100/$num_shares;
	
		//write_log("calc_revenue_per_share","rev_per_share=".$rev_per_share);
	
		//update statistics
		update_statistic($symbol, 'revenue_per_share', $rev_per_share, $date);
	}
}

//Calculate Price to Sales Ratio
function calc_price_to_sales($date,$symbol){

	//write_log("calc_price_to_sales","symbol=".$symbol." Date=".$date);
	
	//Revenue per share
	$rev_per_share=get_indicator_value($symbol, 'revenue_per_share',$date);
	
	//write_log("calc_price_to_sales","rev_per_share=".$rev_per_share);
	
	//get share price
	$price=get_share_price($symbol,$date);
	
	if ($rev_per_share>0){
		//calculate price sales ratio
		$price_sales_ratio=$price/$rev_per_share;
	
		//write_log("calc_price_to_sales","price_sales_ratio".$price_sales_ratio);
	
		//update statistics
		update_statistic($symbol, 'price_sales_ratio', $price_sales_ratio, $date);
	}
}
	
function get_share_price($symbol, $date){
	$rows=query("SELECT price FROM `historical_prices` WHERE symbol=? and date=(select max(date) from historical_prices where symbol=? and date <= ?)",$symbol,$symbol,$date );
	
	if (count($rows)>0)
		return $rows[0]['price'];
}
	
?>
