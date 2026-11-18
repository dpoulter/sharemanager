<?php

	/**
	 * generate_targets.php — the signal half.
	 *
	 * Reads the strategy's rules, works out what it should hold on a date,
	 * and writes target weights. It never looks at positions, cash or
	 * orders, so a bug here cannot place a trade; the worst it can do is
	 * write a target the executor then refuses.
	 *
	 *   php generate_targets.php <strategy> [as_of_date]
	 *
	 * What to select, how many, and whether funds are excluded are a row in
	 * strategy_rules rather than arguments: a strategy somebody can run with
	 * different numbers from the ones it is measured on is not a strategy.
	 * See sql/strategy_rules.sql.
	 *
	 * The third argument this used to take (top_n) is gone with the hard
	 * coded "best N by overall_score" it went with. A strategy's number of
	 * holdings is strategy_rules.holdings.
	 */

	require_once("constants.php");
	include("functions.php");
	include("share_functions.php");
	/* share_functions.php already requires share_screen.php, which is where
	   build_screen() and top_n_list() live; including it here as well is a
	   plain include of a file that is already loaded, and the second copy
	   of screen() is a fatal. */
	include("strategy_functions.php");

	$_SESSION["exchange"]='XLON';

	$strategy = isset($argv[1]) ? $argv[1] : 'momentum_top10';

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

	if (!strategy_rules_ready()){
		exit("sql/strategy_rules.sql has not been applied; no strategy has any rules\r\n");
	}

	$rules=strategy_rules($strategy);
	if ($rules===null){
		exit("No rules for '".$strategy."'. Add a strategy_rules row; see sql/strategy_rules.sql\r\n");
	}

	log_job("generate_targets");

	$written=strategy_write_targets($strategy,$as_of_date);

	foreach ($written['errors'] as $error) { echo "  ! ".$error."\r\n"; }

	if (!count($written['targets'])){
		write_log("generate_targets",$strategy." wrote no targets for ".$as_of_date
		          .": ".implode("; ",$written['errors']));
		exit("No targets for ".$as_of_date."\r\n");
	}

	$message=$strategy." targets for ".$as_of_date.": ".count($written['targets'])
	        ." names at ".$written['weight']." each";
	write_log("generate_targets",$message);
	echo $message."\r\n";

	foreach ($written['targets'] as $target) {
		echo "  ".$target['symbol']."\r\n";
	}

?>
