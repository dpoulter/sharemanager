<?php

	/**
	 * run_paper_execution.php — the execution half, for one strategy.
	 *
	 * Reconciles, plans, then places and fills. Refuses to trade on anything
	 * it does not understand rather than trading approximately.
	 *
	 *   php run_paper_execution.php <strategy> [as_of_date] [--dry-run]
	 *
	 * --dry-run prints the plan and writes nothing, which is how you check a
	 * rebalance before letting it run unattended.
	 *
	 * The work is strategy_execute() in strategy_functions.php. This file is
	 * the command line around it: the nightly run (run_strategies.php) and
	 * the strategy page's dry run call the same function, so all three
	 * cannot drift apart. It plans from targets that already exist and does
	 * not write any; generate_targets.php or the nightly run does that.
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

	$args=array_values(array_filter(array_slice($argv,1),function($a){ return $a!=='--dry-run'; }));
	$dry_run=in_array('--dry-run',$argv,true);

	$strategy=isset($args[0]) ? $args[0] : 'momentum_top10';

	if (isset($args[1])) {
		$as_of_date=$args[1];
		if (date_create_from_format('Y-m-d',$as_of_date)===false
		    ||date_format(date_create_from_format('Y-m-d',$as_of_date),'Y-m-d')!==$as_of_date){
			exit("Invalid as of date '".$as_of_date."', expected Y-m-d\r\n");
		}
	}
	else {
		$rows=query("select max(as_of_date) d from strategy_targets where strategy=?",$strategy);
		$as_of_date=(count($rows)>0) ? $rows[0]['d'] : null;
		if ($as_of_date===null){ exit("No targets for ".$strategy."\r\n"); }
	}

	//Fail closed: the kill switch wins over everything, including a dry run,
	//so its behaviour is never in doubt.
	if (strategy_halted()){
		write_log("strategy","halted by kill switch at ".STRATEGY_KILL_SWITCH_FILE);
		exit("HALTED: ".STRATEGY_KILL_SWITCH_FILE." exists\r\n");
	}

	$report=strategy_execute($strategy,$as_of_date,$dry_run);

	echo $strategy." @ ".$as_of_date.($dry_run?" (dry run)":"")."\r\n";
	echo "  account value: "
	     .($report['account_value']===null?"unknown":round($report['account_value'],2))."\r\n";

	/* The banner the operator reads, and the string the suite looks for: a
	   refusal to trade because the books disagree is not one warning among
	   others, and it used to be the loudest line in the output. */
	$reconciliation=[];
	foreach ($report['errors'] as $error){
		if (strpos($error,'reconciliation: ')===0){ $reconciliation[]=substr($error,strlen('reconciliation: ')); }
	}
	if (count($reconciliation)){
		echo "RECONCILIATION FAILED, not trading:\r\n";
		foreach ($reconciliation as $problem){ echo "  - ".$problem."\r\n"; }
	}

	foreach ($report['errors'] as $error){ echo "  ! ".$error."\r\n"; }
	foreach ($report['lines']  as $line){  echo "  ".$line."\r\n"; }

	/* The exit code is what cron and the nightly script read, and it is the
	   same rule as before this file became a wrapper: a rejected order
	   fails, and so does a run that did nothing because something was
	   wrong. A run that placed its orders and skipped one unpriceable name
	   succeeded - the warning is in the output and in the log, and a
	   strategy that holds fourteen names because the fifteenth had no price
	   is not a failed night. */
	if ($report['rejected']>0){ exit(1); }
	if (count($report['orders'])===0&&count($report['errors'])>0){ exit(1); }

	/* An order left waiting for tomorrow's close is the normal outcome of a
	   rebalance decided tonight, so it is not a failure - the next run
	   resumes it. */

	exit(0);

?>
