<?php

    require_once("constants.php");
    include("functions.php");
    include("share_functions.php");
	include("Statistic.php");

	log_job("test_get_key_ratios");

	
	//Set exchange
	$_SESSION["exchange"]='LON';
	
	//write_log("get_statistics.php","Get statistics to calculate");
	
	$from_time = strtotime('now');
	
	//Get symbols
	$rows = query("select symbol from stock_symbols where symbol='WTB' and enabled='Y' and exchange=?  order by symbol ",'X'.$_SESSION["exchange"]);
			
	foreach($rows as $row) {
			
		$symbol=$row['symbol'];
		
		//remove .L from symbol
		if (strpos($symbol,'.')>0){
			$symbol=substr($symbol,0,strpos($symbol,'.'));
		};
		
	//	write_log("get_api_stats","symbol: ".$symbol);
			
		$share_info=get_key_ratios($_SESSION["exchange"],$symbol);
		
		if (count($share_info)>0){
		
			print_r($share_info);
				
			/*$indicators=query("select * from screen_indicators where enabled='Y' order by order_number");
				
			if(count($indicators)>0) {
				foreach($indicators as $indicator ){
					
					//write_log("get_api_stats","indicator: ".$indicator["name"]);
					
					if (is_array($share_info) && array_key_exists($indicator["description"], $share_info))	{
							
						$value=convert_number($share_info[$indicator["description"]]);
						
						//write_log("get_api_stats","value: ".$value);
						
						$statistic = new Statistic();
						
						$statistic->insert($symbol.'.L',$_SESSION["exchange"],$indicator["name"],$date,$value);
					}
				}
							
			}
		*/
		}
		$to_time = strtotime('now');
			//write_log("indicator_stats","Time taken: ".round(abs($to_time - $from_time),2). " seconds");
	}
	
	
	
?>
