<?php

  require_once("constants.php");
         include("functions.php");
         include("share_functions.php");

	$asOfDate=new DateTime();
    $asOfDate->sub(new DateInterval('P1D'));
	print_r( "Asofdate=".date_format($asOfDate,'Y-m-d')."\r\n");
	
	
		
write_log("get_statistics.php","Calculate Sector Medians for all statistics");
	
	//Calculate Sector Medians for all statistics
	$sectors=query('select distinct sector from stock_symbols');
	foreach ($sectors as $sector){
		$indicators=query("select name from screen_indicators where enabled='Y'");
   		foreach ($indicators as $indicator){
			$avg = query("SELECT x.indicator, x.value, ssx.sector from statistics x, statistics y , stock_symbols ssx, stock_symbols ssy
			where x.date=? and x.indicator=? and ssx.sector=? and x.date=y.date and x.indicator=y.indicator
			and x.symbol=ssx.symbol and y.symbol=ssy.symbol and ssx.sector = ssy.sector 
			GROUP BY x.indicator,x.value, ssx.sector HAVING SUM(SIGN(1-SIGN(y.value-x.value)))/COUNT(*) > .5
			LIMIT 1",date_format($asOfDate,'Y-m-d'),$indicator['name'],$sector['sector']);
			if(count($avg)>0) {
				query("insert into statistic_averages (date, category, indicator,type, value, sector) values (?,?,?,?,?,?)",date_format($asOfDate,'Y-m-d'),'SECTOR',$avg[0]['indicator'],'MEDIAN',$avg[0]['value'],$avg[0]['sector']);
			}
		}
 		
		
	}
	
	//insert record into jobs
	write_log("get_statistics.php","insert record into jobs");
	
	query("insert into jobs (job_name, job_date) values (?,?)",'get_statistics',date_format($asOfDate,'Y-m-d'));
	
 ?>
