<?php

    require_once("constants.php");
    include("functions.php");
	include("share_functions.php");
	
	log_job("get_statistics");

	$asOfDate=new DateTime();
    $asOfDate->sub(new DateInterval('P1D'));
	print_r( "Asofdate=".date_format($asOfDate,'Y-m-d')."\r\n");
	
	//Set Exchanges
	$exchanges = array("LON", "JSE");
	
	foreach ($exchanges as $exchange){
	    
		$_SESSION["exchange"]=$exchange;
	
		//write_log("get_statistics.php","Get statistics to calculate");
		
	    //Get statistics to calculate
		$indicators=get_stats_indicators();
		foreach ($indicators as $indicator){
	
			print_r( $indicator);
			//write_log("get_statistics.php","Indicator=".$indicator["name"]);
			
			//Get statistics for each symbol
			//indicator_stats(date_format($asOfDate,'Y-m-d'),$indicator["name"],null);
			
		}	
		
	
		
		//Calculate Quality Ranking
		write_log("get_statistics.php","Calculate Quality Ranking");
		$sql      =   "SELECT symbol, rank,round(100-(rank/@rownum*100)) percentile FROM (SELECT case when @prev_value=value then @rownum when @prev_value:=value then @rownum := @rownum + 1 else @rownum:=@rownum+1 end AS rank, value, symbol, rank_zero FROM (select round(sum(if(percentile is null,50,percentile))) as value,symbol,si.rank_zero FROM statistics s, screen_indicators si where s.indicator=si.name and date= ? and indicator in ('roe_ttm','roa','operating_margin','profit_margin','roce')  and s.exchange=? group by symbol,si.rank_zero) s, (SELECT @rownum :=0,@prev_value := NULL) r  ORDER BY value DESC) as result";
		
		/* $sql      =   "
		SELECT  symbol, round(sum(if(percentile is null,50,percentile))/1) as percentile
		FROM statistics r where date=? and indicator in ('roe_ttm') 
		group by symbol";
	*/
		$scores = query($sql,date_format($asOfDate,'Y-m-d'),$_SESSION['exchange']);
		write_log("get_statistics.php","Number of scores for quality ranking: ".count($scores));
		foreach($scores as $score){
				query("insert into statistics (symbol,date, indicator,value,exchange) values (?,?,?,?,?)",$score['symbol'],date_format($asOfDate,'Y-m-d'),'quality_score',$score['percentile'],$_SESSION['exchange']);
	
		}
		
		
	
	
		//insert record into jobs
		write_log("get_statistics.php","insert record into jobs");
		
		query("insert into jobs (job_name, job_date) values (?,?)",'get_statistics',date_format($asOfDate,'Y-m-d H:i:s'));
	}
 ?>
