<?php

	/**
	 * One-off: move the bt_ copies out of strategy_rules and into the runs.
	 *
	 *   php includes/migrate_bt_rules.php [--dry-run]
	 *
	 * Until now every backtest left a copy of its strategy's rules in
	 * strategy_rules under the run's own account name (bt_<strategy>_<run>),
	 * because the engine reads its rules from that table. Those rows are the
	 * only record of what each old run tested. This keeps that record where
	 * it belongs - the run's own rules_snapshot and fingerprint - and then
	 * removes the copy, so that strategy_rules holds strategies and nothing
	 * else.
	 *
	 * A run's snapshot is taken from its copy, with the criteria of the
	 * screen as they are NOW: the copy never recorded the criteria, so a
	 * screen edited since the run is not detectable here, and such a run will
	 * show as having tested the current rules. That is the best the old data
	 * allows, and it is said in the output.
	 *
	 * A copy is removed only after its run has a snapshot. A copy with no run
	 * (the run row was deleted) has nothing to be kept for and is removed, and
	 * counted. The runs' own accounts, values and results are not touched.
	 *
	 * Re-runnable: a run that already has a snapshot is not overwritten. It
	 * changes nothing at the broker and reads none.
	 *
	 * Exit code: 0, or 2 if the workflow columns are not there.
	 */

	require_once("constants.php");
	include("functions.php");
	include("share_functions.php");
	include("strategy_functions.php");
	require_once(__DIR__ . "/rules_fingerprint.php");
	require_once(__DIR__ . "/strategy_stage.php");

	$dry_run=in_array('--dry-run',array_slice($argv,1),true);

	if (!strategy_stage_ready()){
		echo "migrate_bt_rules: sql/strategy_workflow.sql has not been applied\r\n";
		exit(2);
	}

	$moved=0; $kept=0; $orphans=0; $removed=0;

	foreach (query("select strategy from strategy_rules where strategy like ? order by strategy",
	               STRATEGY_BACKTEST_PREFIX.'%') as $row) {

		$copy=(string)$row['strategy'];
		if (strpos($copy,STRATEGY_BACKTEST_PREFIX)!==0){ continue; }

		/* The run that owns a copy is the one whose account IS the copy's
		   name. Not the number at the end of the name: that is a habit of how
		   the names were made, and a copy that was only matched by it, and
		   so missed, would be taken for an orphan and lost. */
		$found=query("select run_id, rules_snapshot from strategy_backtest_runs where account=? order by run_id desc limit 1",
		             $copy);
		$run=count($found) ? $found[0] : null;

		if ($run===null){
			echo "  ".$copy.": no run owns it".($dry_run ? "" : ", removed")."\r\n";
			$orphans++;
		}
		else if ($run['rules_snapshot']!==null&&trim((string)$run['rules_snapshot'])!==''){
			echo "  ".$copy.": run ".$run['run_id']." already has its snapshot".($dry_run ? "" : ", copy removed")."\r\n";
			$kept++;
		}
		else {
			$rules=strategy_rules($copy);
			if ($rules===null){ continue; }
			$snapshot=rules_snapshot_of($rules);
			if (!$dry_run){
				query("update strategy_backtest_runs set rules_snapshot=?, fingerprint=? where run_id=?",
				      json_encode($snapshot),rules_fingerprint_of($snapshot),(int)$run['run_id']);
			}
			echo "  ".$copy.": run ".$run['run_id']." keeps its rules as a snapshot".($dry_run ? "" : ", copy removed")."\r\n";
			$moved++;
		}

		if (!$dry_run){
			query("delete from strategy_rules where strategy=?",$copy);
			$removed++;
		}
	}

	echo "migrate_bt_rules".($dry_run ? " (dry run)" : "").": ".$moved." snapshot(s) taken, "
	     .$kept." already held one, ".$orphans." copy(ies) with no run, ".$removed." row(s) removed\r\n";
	echo "  Old snapshots carry today's screen criteria: an edit to a screen since a run cannot be seen here.\r\n";
	exit(0);

?>
