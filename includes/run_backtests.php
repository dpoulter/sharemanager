<?php

	/**
	 * Run the backtests that were asked for from the strategy's page.
	 *
	 *   php includes/run_backtests.php
	 *
	 * RETIRING. Every run asked for now is the Mac mini's (engine 'mac_mini',
	 * claimed through public/backtest_api.php), and this job only ever picks
	 * up a run with no engine, so it replays nothing. It, its cron entry and
	 * includes/backtest_strategy.php are removed in a separate commit once
	 * the Mac mini path is deployed and checked.
	 *
	 * From cron, every minute, through includes/backtest_run.sh. A request
	 * only QUEUES a run (includes/backtest_runs.php); this is what replays it,
	 * outside the web server, one run at a time.
	 *
	 *   - Only one of these runs at once: a lock file, and a second copy that
	 *     cannot take it says so and exits 0.
	 *   - A run still RUNNING when it starts was left by a crash. It is marked
	 *     failed and its leftovers removed; it is not run again.
	 *   - Each queued run is claimed (QUEUED to RUNNING) and handed to the
	 *     engine, includes/backtest_strategy.php, as a separate process with
	 *     --run=<id>. The engine is the same one the command line uses, so the
	 *     result is the same one. A run that fails does not stop the next.
	 *
	 * It never sends an order, reads the broker or touches an account other
	 * than the engine's own reserved bt_ one.
	 *
	 * Exit code: 0 whatever the runs did (a failed run is on its row, not in
	 * the exit code), 2 if the tables are not there.
	 */

	require_once("constants.php");
	include("functions.php");
	include("share_functions.php");
	include("strategy_functions.php");
	require_once(__DIR__ . "/backtest_runs.php");

	log_job("run_backtests");

	if (!backtest_runs_ready()){
		echo "run_backtests: sql/strategy_workflow.sql and sql/backtest_queue.sql have not been applied\r\n";
		exit(2);
	}

	$lock=fopen(sys_get_temp_dir()."/sharemanager_run_backtests.lock",'c');
	if ($lock===false||!flock($lock,LOCK_EX|LOCK_NB)){
		echo "run_backtests: another copy is running, so this one does nothing\r\n";
		exit(0);
	}

	foreach (backtest_recover_interrupted() as $id){
		echo "run ".$id." was left running by a crash: marked failed, not run again\r\n";
	}

	$done=0;
	while (($run=backtest_next_queued())!==null){

		$id=(int)$run['run_id'];

		if (!backtest_claim($id)){ continue; }

		echo "run ".$id.": ".$run['strategy']." ".$run['from_date']." to ".$run['to_date']."\r\n";

		$command=escapeshellarg(PHP_BINARY)
		        ." -d include_path=".escapeshellarg(ini_get('include_path'))
		        ." ".escapeshellarg(__DIR__."/backtest_strategy.php")
		        ." ".escapeshellarg((string)$run['strategy'])
		        ." ".escapeshellarg((string)$run['from_date'])
		        ." ".escapeshellarg((string)$run['to_date'])
		        ." --run=".$id;

		$output=[];
		exec($command." 2>&1",$output,$rc);

		/* The engine finishes its own row. One that is still RUNNING - a
		   fatal, a usage refusal, a kill - is failed here, with the end of
		   what it said. */
		$after=backtest_run_get($id);
		if ($after!==null&&$after['status']==='RUNNING'){
			backtest_fail($id,"the engine stopped without finishing (exit ".$rc."): "
			              .trim(implode(' | ',array_slice($output,-3))));
			backtest_clean_account($after['account']);
		}

		$after=backtest_run_get($id);
		echo "  ".($after===null ? 'gone' : $after['status'])."\r\n";
		$done++;
	}

	echo "run_backtests: ".$done." run(s)\r\n";
	exit(0);

?>
