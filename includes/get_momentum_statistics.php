<?php

	require_once("constants.php");
	include("functions.php");
	include("share_functions.php");

	//Set Exchange Session variable. This must match the value stock_symbols and
	//historical_prices are loaded under - see get_share_prices.php and
	//python/fetch_stock_info.py, which both use XLON.
	$_SESSION["exchange"]='XLON';

	//Get parameters. With no arguments only the most recent as of date per symbol
	//is calculated, so the nightly run in refresh_all.sh stays incremental. Pass a
	//start and end date (Y-m-d) to recalculate a wider range, e.g.
	//    php get_momentum_statistics.php 2015-01-01 2025-12-31
	$range_start=isset($argv[1])?date_create_from_format('Y-m-d',$argv[1]):false;
	$range_end=isset($argv[2])?date_create_from_format('Y-m-d',$argv[2]):false;

	if (isset($argv[1])&&$range_start===false){
		exit("Invalid start date '".$argv[1]."', expected Y-m-d\r\n");
	}
	if (isset($argv[2])&&$range_end===false){
		exit("Invalid end date '".$argv[2]."', expected Y-m-d\r\n");
	}

	$from=($range_start===false)?null:date_format($range_start,'Y-m-d');
	$to=($range_end===false)?null:date_format($range_end,'Y-m-d');
	$incremental=($from===null&&$to===null);

	$interval=new DateInterval('P1M');

	$symbol_count=0;
	$date_count=0;

	$rows=query("select symbol, min(date) min_date, max(date) max_date from historical_prices where exchange=? group by symbol",$_SESSION["exchange"]);
	foreach($rows as $row){

		$symbol=$row['symbol'];

		debug_log("get_momentum_statistics", "min_date=".$row['min_date']);
		debug_log("get_momentum_statistics", "max_date=".$row['max_date']);

		$asOfDate=date_create($row['min_date']);
		$end_date=date_create($row['max_date']);

		if ($asOfDate===false||$end_date===false){
			write_log("get_momentum_statistics", "Skipping ".$symbol.", unusable date range");
			continue;
		}

		//Momentum needs at least 3 months of prices behind the as of date
		date_add($asOfDate,new DateInterval('P3M'));

		//Build the monthly as of dates for this symbol. This is just date
		//arithmetic, the expensive work is indicator_stats below.
		$as_of_dates=array();
		while($asOfDate<=$end_date){
			$as_of_dates[]=date_format($asOfDate,'Y-m-d');
			date_add($asOfDate,$interval);
		}

		if ($incremental){
			//Nightly run: most recent as of date only
			$as_of_dates=array_slice($as_of_dates,-1);
		}
		else {
			//Backfill: keep the dates inside the requested range. Y-m-d strings
			//compare chronologically.
			$as_of_dates=array_filter($as_of_dates,function($as_of_date) use ($from,$to){
				return ($from===null||$as_of_date>=$from)&&($to===null||$as_of_date<=$to);
			});
		}

		$symbol_count++;

		foreach($as_of_dates as $as_of_date){

			debug_log("get_momentum_statistics", "Asofdate=".$as_of_date."\r\n");

			indicator_stats($as_of_date,'3mnth',$symbol);
			indicator_stats($as_of_date,'6mnth',$symbol);
			indicator_stats($as_of_date,'12mnth',$symbol);

			$date_count++;
		}
	}

	write_log("get_momentum_statistics",($incremental?"Incremental run":"Backfill ".$from." to ".$to).
		", ".$symbol_count." symbols, ".$date_count." as of dates");
?>
