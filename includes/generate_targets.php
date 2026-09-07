<?php

	/**
	 * generate_targets.php — the signal half.
	 *
	 * Reads scores out of statistics and writes target weights. It never looks
	 * at positions, cash or orders, so a bug here cannot place a trade; the
	 * worst it can do is write a target the executor then refuses.
	 *
	 *   php generate_targets.php <strategy> [as_of_date] [top_n]
	 */

	require_once("constants.php");
	include("functions.php");
	include("share_functions.php");
	include("strategy_functions.php");

	$_SESSION["exchange"]='XLON';

	$strategy = isset($argv[1]) ? $argv[1] : 'momentum_top10';
	$top_n    = isset($argv[3]) ? (int)$argv[3] : 10;

	//Default to the date the statistics are actually current as of, rather
	//than today, so targets line up with the data that produced them.
	if (isset($argv[2])) {
		$as_of_date=$argv[2];
		if (date_create_from_format('Y-m-d',$as_of_date)===false
		    ||date_format(date_create_from_format('Y-m-d',$as_of_date),'Y-m-d')!==$as_of_date){
			exit("Invalid as of date '".$as_of_date."', expected Y-m-d\r\n");
		}
	}
	else {
		$rows=query("select date(max(job_date)) d from jobs where job_name='get_statistics_asof'");
		$as_of_date=(count($rows)>0&&$rows[0]['d']!==null) ? $rows[0]['d'] : null;
		if ($as_of_date===null){ exit("No get_statistics_asof marker, cannot pick an as of date\r\n"); }
	}

	if ($top_n<1||$top_n>100){ exit("top_n must be between 1 and 100\r\n"); }

	log_job("generate_targets");

	//Highest overall_score, restricted to names that are still enabled and
	//that we can actually price on the day.
	$scores=query("select s.symbol, s.value
		from statistics s, stock_symbols ss
		where s.symbol=ss.symbol and ss.enabled='Y' and ss.exchange=s.exchange
		  and s.indicator='overall_score' and s.date=? and s.exchange=?
		  and exists (select 1 from historical_prices hp
		              where hp.symbol=s.symbol and hp.exchange=s.exchange and hp.date<=? and hp.price>0)
		order by s.value desc limit ".$top_n,
		$as_of_date,$_SESSION["exchange"],$as_of_date);

	if (count($scores)===0){
		write_log("generate_targets","no scores at ".$as_of_date.", wrote no targets");
		exit("No scores for ".$as_of_date."\r\n");
	}

	//Equal weight. Anything cleverer needs a backtest behind it first.
	$weight=round(1.0/count($scores),6);

	query("delete from strategy_targets where strategy=? and as_of_date=?",$strategy,$as_of_date);
	foreach ($scores as $score) {
		query("insert into strategy_targets (strategy,as_of_date,symbol,exchange,target_weight,score,created_at)
			values (?,?,?,?,?,?,?)",
			$strategy,$as_of_date,$score['symbol'],$_SESSION["exchange"],$weight,$score['value'],date('Y-m-d H:i:s'));
	}

	$message=$strategy." targets for ".$as_of_date.": ".count($scores)." names at ".$weight." each";
	write_log("generate_targets",$message);
	echo $message."\r\n";

?>
