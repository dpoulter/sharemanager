<?php

	/**
	 * run_strategies.php — every enabled strategy, one night.
	 *
	 *   php run_strategies.php [as_of_date] [--dry-run] [--strategy=name]
	 *
	 * Called by refresh_all.sh after get_statistics.php, because a strategy
	 * selects on statistics that have to be written first. For each enabled
	 * strategy, in this order:
	 *
	 *   1. exit checks, on every holding;
	 *   2. the rebalance, if tonight is a rebalance date for that strategy;
	 *   3. the execution, which fills at the next trading day's price the
	 *      way the engine always has - so orders planned tonight fill when
	 *      that price exists, which is tomorrow night.
	 *
	 * Then the daily valuation, for every strategy and for the benchmark, so
	 * the page can draw a line.
	 *
	 * THIS PLANS; IT DOES NOT SEND. A broker account's orders are recorded
	 * PENDING and go out during the session, and a live account's are put in
	 * a batch that waits for Dale to approve it on the page. Nothing here
	 * places an order anywhere.
	 *
	 * One strategy failing must not stop the others, and none of them may
	 * stop the rest of the nightly job: every strategy is run inside
	 * strategy_run_night(), which catches, and the exit code is 1 if any of
	 * them had a problem - which refresh_all.sh reports without aborting.
	 */

	require_once("constants.php");
	include("functions.php");
	include("share_functions.php");
	/* share_functions.php already requires share_screen.php, which is where
	   build_screen() and top_n_list() live; including it here as well is a
	   plain include of a file that is already loaded, and the second copy
	   of screen() is a fatal. */
	include("strategy_functions.php");
	/* Arming, approval and the live caps. The engine does not need the
	   broker layer to plan a paper account, so strategy_functions.php does
	   not require this - but a night that plans a live account's orders has
	   to put them in a batch for Dale to approve, and has to stand down the
	   batch nobody approved yesterday. */
	include("broker_live.php");
	/* Which strategies are left alone and why: no account yet, or archived. */
	include("night_skips.php");

	$_SESSION["exchange"]='XLON';
	$exchange='XLON';

	$requested=null;
	$dry_run=false;
	$only=null;

	foreach (array_slice($argv,1) as $arg) {
		if ($arg==='--dry-run'){ $dry_run=true; continue; }
		if (strpos($arg,'--strategy=')===0){ $only=substr($arg,strlen('--strategy=')); continue; }
		$requested=$arg;
	}

	$as_of=resolve_asof_date($requested,$exchange);

	if ($as_of===null){
		$why=($requested===null)
		   ? "no prices have been loaded for ".$exchange
		   : "there are no ".$exchange." prices for '".$requested."'";
		fwrite(STDERR,"run_strategies: refusing to run, ".$why."\n");
		write_log("strategy","refusing to run: ".$why);
		exit(2);
	}

	if (!strategy_rules_ready()){
		fwrite(STDERR,"run_strategies: sql/strategy_rules.sql has not been applied; nothing to run\n");
		exit(3);
	}

	/* Fail closed, before anything is selected: the kill switch stops the
	   dry run too, so what it does is never in doubt. */
	if (strategy_halted()){
		write_log("strategy","halted by kill switch at ".STRATEGY_KILL_SWITCH_FILE);
		echo "HALTED: ".STRATEGY_KILL_SWITCH_FILE." exists\r\n";
		exit(4);
	}

	if (!$dry_run){ log_job("run_strategies"); }

	$strategies=strategy_strategies();
	if ($only!==null){
		$strategies=array_values(array_filter($strategies,function($s) use ($only){ return $s===$only; }));
		if (!count($strategies)){
			fwrite(STDERR,"run_strategies: '".$only."' is not an enabled PAPER strategy with rules\n");
			exit(3);
		}
	}

	/* A strategy with rules and no account, or an archived one, is skipped
	   and is not an error: one line each, and nothing is planned for it. */
	$left_alone=night_skips($strategies);
	$strategies=$left_alone['run'];

	echo "run_strategies ".$as_of.($dry_run?" (dry run)":"")
	     .": ".count($strategies)." strategy(ies)\r\n";

	foreach ($left_alone['skipped'] as $skip){
		echo "  skipped ".$skip[0].": ".$skip[1]."\r\n";
	}

	/* Yesterday's unapproved batch, stood down before tonight is planned.
	   An unapproved batch is good for the session it was planned for and no
	   longer: tomorrow's selection is a different selection, and sending
	   yesterday's at today's prices is sending orders nobody looked at. */
	if (!$dry_run&&function_exists('broker_live_expire_batches')){
		$expired=broker_live_expire_batches(['as_of'=>$as_of]);
		foreach ($expired['lines'] as $line){ echo "  ".$line."\r\n"; }
	}

	$problems=0;

	foreach ($strategies as $strategy) {

		$night=strategy_run_night($strategy,$as_of,$dry_run,$exchange);

		echo "\r\n".$strategy.($night['rebalance']?" REBALANCE":"")."\r\n";
		echo "  account value: "
		     .($night['account_value']===null?"unknown":round($night['account_value'],2))."\r\n";

		foreach ($night['errors'] as $error){ echo "  ! ".$error."\r\n"; $problems++; }
		foreach ($night['lines']  as $line){  echo "  ".$line."\r\n"; }

		if (!$dry_run){

			$value=strategy_write_values($strategy,$as_of,$exchange);

			if ($value!==null){
				echo "  value ".round($value['total'],2)
				     ." (".$value['holdings']." holdings, cash ".round($value['cash'],2).")\r\n";
				if ($value['note']!==null){ echo "  ! ".$value['note']."\r\n"; }
			}

			/* One line per strategy in the log, whatever happened, so a
			   month of nights can be read without the page. */
			write_log("strategy","night ".$as_of." ".$strategy
			          ." rebalance=".($night['rebalance']?'yes':'no')
			          ." exits=".count($night['exits'])
			          ." filled=".$night['filled']
			          ." pending=".$night['pending']
			          ." rejected=".$night['rejected']
			          ." skipped=".$night['skipped']
			          ." value=".($value===null?'unknown':round($value['total'],2))
			          ." cash=".($value===null?'unknown':round($value['cash'],2))
			          ." errors=".count($night['errors']));
		}
	}

	if (!$dry_run){
		/* The benchmark last, and once: it is a property of the market
		   rather than of any strategy, and writing it per strategy would
		   compound the same day's return as many times as there are
		   strategies. */
		$benchmark=strategy_benchmark_update($as_of,$exchange);
		echo "\r\nbenchmark ".$benchmark['date']." level ".round($benchmark['level'],2)
		     .($benchmark['return']===null?" (base)":" (".round($benchmark['return']*100,2)."%)")."\r\n";
	}

	echo "\r\nrun_strategies done, ".$problems." problem(s)\r\n";

	exit($problems>0 ? 1 : 0);

?>
