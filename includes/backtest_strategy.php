<?php

	/**
	 * backtest_strategy.php — a strategy's own rules, replayed over stored
	 * history through the same engine.
	 *
	 *   php backtest_strategy.php <strategy> <from> <to> [--keep]
	 *
	 * Same selection, same rebalance calendar, same exit rules, same dealing
	 * costs and the same limits as the paper account: the point of a
	 * backtest that shares the engine is that it cannot quietly be kinder
	 * than the real thing.
	 *
	 * It trades its own account, named bt_<strategy>_<run_id>. That prefix
	 * is reserved (STRATEGY_BACKTEST_PREFIX): the nightly run skips it, and
	 * this script refuses to write to any account without it. The live paper
	 * account is never read for anything but its rules and never written to
	 * at all.
	 *
	 * --keep leaves the orders and positions behind for inspection. Without
	 * it the account's rows are removed when the run finishes and only the
	 * nightly values and the summary in strategy_backtest_runs are kept,
	 * which is what the page draws.
	 *
	 * TWO THINGS TO READ ANY RESULT WITH, both printed with it:
	 *
	 *   Survivorship. The universe is today's FTSE 350. The companies that
	 *   left the index - taken over, demoted, failed - are not in
	 *   stock_symbols, so a backtest cannot buy them and cannot be hurt by
	 *   them. That flatters every result here, and nothing in the data can
	 *   fix it: it would need the index's historical membership.
	 *
	 *   Look-ahead. Each date uses only the statistics stored for that date,
	 *   which is why a strategy is refused for a period where its indicators
	 *   do not exist rather than being run on today's figures. The prices
	 *   are adjusted closes, so they carry later dividends and splits - the
	 *   same approximation the paper account's fills make.
	 */

	require_once("constants.php");
	include("functions.php");
	include("share_functions.php");
	include("strategy_functions.php");

	$_SESSION["exchange"]='XLON';
	$exchange='XLON';

	$args=array_values(array_filter(array_slice($argv,1),function($a){ return $a!=='--keep'; }));
	$keep=in_array('--keep',$argv,true);

	$strategy=isset($args[0]) ? $args[0] : null;
	$from=isset($args[1]) ? $args[1] : null;
	$to  =isset($args[2]) ? $args[2] : null;

	/* A usage refusal exits non-zero. Most of the scripts here exit(0) with
	   a message, which is fine for something a person is watching; this one
	   is also run from a script that checks, so "I did not do it" has to be
	   distinguishable from "I did it". */
	$refuse_usage=function($message) {
		fwrite(STDERR,$message."\r\n");
		echo $message."\r\n";
		exit(2);
	};

	if ($strategy===null||$from===null||$to===null){
		$refuse_usage("Usage: php backtest_strategy.php <strategy> <from> <to> [--keep]");
	}

	foreach ([$from,$to] as $date) {
		if (date_create_from_format('Y-m-d',$date)===false
		    ||date_format(date_create_from_format('Y-m-d',$date),'Y-m-d')!==$date){
			$refuse_usage("Invalid date '".$date."', expected Y-m-d");
		}
	}
	if ($from>$to){ $refuse_usage("The start date is after the end date"); }

	if (!strategy_rules_ready()){ $refuse_usage("sql/strategy_rules.sql has not been applied"); }
	if (!strategy_backtest_ready()){ $refuse_usage("sql/strategy_backtest.sql has not been applied"); }

	/* Before anything else, including before asking whether it has rules: a
	   reserved name is refused for being one. Backtesting a backtest is a
	   mistake however it is spelled, and the answer should not depend on
	   whether that particular run still exists. */
	if (strategy_reserved($strategy)){
		$refuse_usage("'".$strategy."' is a reserved name; backtest the strategy it came from");
	}

	/* The rules are the live strategy's. Nothing else about it is read, and
	   nothing about it is written. */
	$rules=strategy_rules($strategy);
	if ($rules===null){ $refuse_usage("No rules for '".$strategy."'; see sql/strategy_rules.sql"); }

	$account_before=strategy_account($strategy);
	$cash_before=($account_before===null) ? null : (float)$account_before['cash'];

	/* Record the run first, so a refusal is on the record with its reason
	   rather than only in somebody's terminal. */
	query("insert into strategy_backtest_runs
	         (strategy,account,from_date,to_date,started_at,status)
	       values (?,?,?,?,?,'RUNNING')",
	      $strategy,'(pending)',$from,$to,date('Y-m-d H:i:s'));

	$run=query("select max(run_id) id from strategy_backtest_runs where strategy=?",$strategy);
	$run_id=(int)$run[0]['id'];
	$bt=STRATEGY_BACKTEST_PREFIX.substr($strategy,0,30).'_'.$run_id;

	/* Belt and braces: the engine is about to be pointed at this name, so
	   prove it is a backtest account before anything is written. */
	if (strpos($bt,STRATEGY_BACKTEST_PREFIX)!==0){
		$refuse_usage("refusing to run: '".$bt."' is not a backtest account name");
	}

	query("update strategy_backtest_runs set account=? where run_id=?",$bt,$run_id);

	$refuse=function($why) use ($run_id,$bt) {
		query("update strategy_backtest_runs set status='REFUSED', finished_at=?, note=? where run_id=?",
		      date('Y-m-d H:i:s'),substr($why,0,1000),$run_id);
		write_log("backtest",$bt." refused: ".$why);
		echo "REFUSED: ".$why."\r\n";
		exit(3);
	};

	/* ------------------------------------------------------------------
	   The look-ahead gate.
	   ------------------------------------------------------------------ */

	$earliest=strategy_backtest_earliest($rules);

	if (count($earliest['missing'])){
		$refuse($strategy." needs ".implode(', ',$earliest['missing'])
		        .", which has no stored history at all. A trend strategy needs"
		        ." backfill_trend_statistics.php run first; a value strategy cannot be"
		        ." backtested before its fundamentals were first loaded.");
	}

	if ($earliest['date']!==null&&$from<$earliest['date']){
		$detail=[];
		foreach ($earliest['indicators'] as $indicator => $first) {
			$detail[]=$indicator." from ".$first;
		}
		$refuse($strategy." cannot be backtested from ".$from.": its indicators only exist from "
		        .$earliest['date']." (".implode('; ',$detail)."). Running it earlier would rank the"
		        ." past on today's figures, which is not a backtest.");
	}

	$dates=query("select distinct date from historical_prices
	               where exchange=? and date>=? and date<=? order by date",$exchange,$from,$to);

	if (count($dates)<2){
		$refuse("there are fewer than two ".$exchange." trading days between ".$from." and ".$to);
	}

	/* ------------------------------------------------------------------
	   The replay.
	   ------------------------------------------------------------------ */

	echo "backtest ".$strategy." as ".$bt." from ".$from." to ".$to
	     ." (".count($dates)." trading days)\r\n";
	echo "  rules: ".$rules['selection']." selection, ".$rules['holdings']." holdings, "
	     .$rules['rebalance']." rebalance"
	     .(count($rules['exit_list']) ? ", exits: ".implode(',',$rules['exit_list']) : "")."\r\n";

	/* A clean account under the backtest's own name, and a copy of the live
	   rules so the engine reads them for this name. start_date is the first
	   day of the period: without it the first build would be due on the
	   first night, which is what we want, but the rules row should say so. */
	foreach (['strategy_orders','strategy_positions','strategy_targets','strategy_values'] as $table) {
		query("delete from ".$table." where strategy=?",$bt);
	}
	query("delete from strategy_accounts where strategy=?",$bt);
	query("delete from strategy_rules where strategy=?",$bt);

	/* enabled='Y', which looks wrong and is not: strategy_plan_orders()
	   refuses a disabled account, so a backtest on one does nothing at all
	   and reports a flat line, which is worse than useless - it looks like
	   a result. What keeps the nightly run away from it is the reserved
	   prefix: strategy_strategies() skips every bt_ name, and so does the
	   strategy page's list of disabled accounts. */
	query("insert into strategy_accounts (strategy,cash,currency,mode,enabled,created_at,note)
	       values (?,?,?,'PAPER','Y',?,?)",
	      $bt,STRATEGY_BACKTEST_CASH,
	      $account_before===null ? 'GBP' : $account_before['currency'],
	      date('Y-m-d H:i:s'),
	      'Backtest of '.$strategy.' from '.$from.' to '.$to);

	query("insert into strategy_rules
	         (strategy,selection,screen_id,score_indicator,rank_by,holdings,rebalance,
	          exit_rules,exclude_funds,start_date,updated_at,note)
	       values (?,?,?,?,?,?,?,?,?,?,?,?)",
	      $bt,$rules['selection'],$rules['screen_id'],$rules['score_indicator'],$rules['rank_by'],
	      $rules['holdings'],$rules['rebalance'],$rules['exit_rules'],$rules['exclude_funds'],
	      $from,date('Y-m-d H:i:s'),'Copied from '.$strategy.' for backtest '.$run_id);

	$rebalances=0;
	$exits=0;
	$errors=[];
	$day=0;

	foreach ($dates as $row) {

		$date=substr((string)$row['date'],0,10);
		$day++;

		if (strategy_halted()){
			$errors[]="stopped at ".$date.": the kill switch is set";
			break;
		}

		$night=strategy_run_night($bt,$date,false,$exchange);

		if ($night['rebalance']){ $rebalances++; }
		$exits += count($night['exits']);

		foreach ($night['errors'] as $error) {
			/* "no targets" on a night that is not a rebalance is the normal
			   state of a monthly strategy, not a problem. */
			if (strpos($error,'no targets for')===0){ continue; }
			$errors[]=$date.": ".$error;
		}

		strategy_write_values($bt,$date,$exchange);

		if ($day%50===0){
			echo "  ".$day." of ".count($dates)." days, ".$rebalances." rebalances, "
			     .$exits." exits\r\n";
		}
	}

	/* ------------------------------------------------------------------
	   What happened.
	   ------------------------------------------------------------------ */

	$series=strategy_series($bt);
	$start_value=count($series) ? $series[0]['total'] : null;
	$final_value=count($series) ? $series[count($series)-1]['total'] : null;
	$total_return=($start_value!==null&&$start_value>0&&$final_value!==null)
	            ? ($final_value/$start_value)-1 : null;
	$drawdown=strategy_drawdown($series);
	$benchmark=strategy_benchmark_return($from,$to,$exchange);
	$costs=strategy_costs_paid($bt);
	$fills=$costs['fills'];

	$note="Survivorship: the universe is today's FTSE 350, so companies that left the index are"
	     ." missing and the result is flattered. Look-ahead: each date used only the statistics"
	     ." stored for it. Prices are adjusted closes.";

	if (count($errors)){
		$note .= " Problems: ".implode('; ',array_slice($errors,0,5))
		      .(count($errors)>5 ? " (+".(count($errors)-5)." more)" : "");
	}

	query("update strategy_backtest_runs
	          set status=?, finished_at=?, trading_days=?, rebalances=?, exits=?, fills=?,
	              start_value=?, final_value=?, total_return=?, benchmark_return=?,
	              max_drawdown=?, costs_paid=?, note=?
	        where run_id=?",
	      'DONE',date('Y-m-d H:i:s'),count($dates),$rebalances,$exits,$fills,
	      $start_value===null ? null : round($start_value,4),
	      $final_value===null ? null : round($final_value,4),
	      $total_return===null ? null : round($total_return,6),
	      $benchmark===null ? null : round($benchmark,6),
	      round($drawdown,6),round($costs['total'],4),substr($note,0,1000),$run_id);

	if (!$keep){
		/* The orders and positions were the mechanism; the nightly values and
		   the summary are the result. Kept with --keep when the mechanism is
		   what is being checked. */
		query("delete from strategy_orders where strategy=?",$bt);
		query("delete from strategy_positions where strategy=?",$bt);
		query("delete from strategy_targets where strategy=?",$bt);
	}

	/* The guarantee, checked rather than asserted: the live account's cash
	   is exactly what it was before this ran. */
	$account_after=strategy_account($strategy);
	$cash_after=($account_after===null) ? null : (float)$account_after['cash'];

	if ($cash_before!==$cash_after){
		write_log("backtest","THE LIVE ACCOUNT MOVED: ".$strategy." cash ".$cash_before
		          ." -> ".$cash_after." during backtest ".$run_id);
		echo "! the live account's cash changed during the backtest, which must never happen\r\n";
	}

	echo "\r\n";
	echo "  run ".$run_id." as ".$bt."\r\n";
	echo "  ".count($dates)." trading days, ".$rebalances." rebalances, ".$exits." exits, "
	     .$fills." fills\r\n";
	echo "  start ".($start_value===null?'?':round($start_value,2))
	     ." final ".($final_value===null?'?':round($final_value,2))
	     ." return ".($total_return===null?'?':round($total_return*100,2)."%")."\r\n";
	echo "  benchmark over the same period: "
	     .($benchmark===null?'?':round($benchmark*100,2)."%")."\r\n";
	echo "  worst fall ".round($drawdown*100,2)."%, dealing costs "
	     .round($costs['total'],2)."\r\n";
	foreach (array_slice($errors,0,10) as $error) { echo "  ! ".$error."\r\n"; }
	echo "\r\n  READ THIS WITH THE RESULT:\r\n";
	echo "  survivorship - the universe is today's FTSE 350, so the companies that left it are\r\n";
	echo "                 missing. Every result here is flattered by that.\r\n";
	echo "  look-ahead   - each date used only the statistics stored for that date.\r\n";

	write_log("backtest",$bt." done days=".count($dates)." rebalances=".$rebalances
	          ." exits=".$exits." fills=".$fills
	          ." return=".($total_return===null?'?':round($total_return,4))
	          ." benchmark=".($benchmark===null?'?':round($benchmark,4))
	          ." drawdown=".round($drawdown,4)
	          ." errors=".count($errors));

	exit(count($errors)>0 ? 1 : 0);

?>
