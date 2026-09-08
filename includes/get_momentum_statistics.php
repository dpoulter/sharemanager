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

	//date_create_from_format rolls out of range values over rather than failing,
	//so 2024-13-45 would quietly become 2025-02-14. Round trip the result to
	//reject anything that was not a real date to begin with.
	function parse_date_arg($value,$label){
		$date=date_create_from_format('Y-m-d',$value);
		if ($date===false||date_format($date,'Y-m-d')!==$value){
			exit("Invalid ".$label." '".$value."', expected Y-m-d\r\n");
		}
		return date_format($date,'Y-m-d');
	}

	$from=isset($argv[1])?parse_date_arg($argv[1],'start date'):null;
	$to=isset($argv[2])?parse_date_arg($argv[2],'end date'):null;
	$incremental=($from===null&&$to===null);

	if ($from!==null&&$to!==null&&$from>$to){
		exit("Start date ".$from." is after end date ".$to."\r\n");
	}

	$interval=new DateInterval('P1M');

	$symbol_count=0;
	$calc_count=0;

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

			$calc_count++;
		}
	}

	write_log("get_momentum_statistics",($incremental?"Incremental run":"Backfill ".$from." to ".$to).
		", ".$symbol_count." symbols, ".$calc_count." symbol/date calculations");
?>
