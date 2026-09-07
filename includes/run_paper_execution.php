<?php

	/**
	 * run_paper_execution.php — the execution half.
	 *
	 * Reconciles, plans, then places and fills. Refuses to trade on anything
	 * it does not understand rather than trading approximately.
	 *
	 *   php run_paper_execution.php <strategy> [as_of_date] [--dry-run]
	 *
	 * --dry-run prints the plan and writes nothing, which is how you check a
	 * rebalance before letting it run unattended.
	 */

	require_once("constants.php");
	include("functions.php");
	include("share_functions.php");
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

	//Resolve anything a previous run left half done BEFORE reconciling.
	//A PENDING order is a known, recoverable state; reconciling first would
	//see it as a discrepancy and refuse to trade, and since the resume used to
	//run after the reconciliation gate, a crashed run could never recover on
	//its own. Finish the old work, then check the books.
	$resume=strategy_resume_pending($strategy);
	if ($resume['halted']){
		echo "  pending orders exist and the account is not in PAPER mode; a human must resolve them\r\n";
		exit(1);
	}
	if ($resume['resumed']>0||$resume['failed']>0){
		echo "  resumed ".$resume['resumed']." pending order(s), ".$resume['failed']." could not be filled\r\n";
	}

	//Reconcile before deciding anything. Any disagreement stops the run: the
	//correct response to an unknown position is never to trade more.
	$problems=strategy_reconcile($strategy);
	if (count($problems)>0){
		write_log("strategy","reconciliation failed: ".implode("; ",$problems));
		echo "RECONCILIATION FAILED, not trading:\r\n";
		foreach ($problems as $problem){ echo "  - ".$problem."\r\n"; }
		exit(1);
	}

	$plan=strategy_plan_orders($strategy,$as_of_date);

	echo $strategy." @ ".$as_of_date.($dry_run?" (dry run)":"")."\r\n";
	echo "  account value: ".($plan['account_value']===null?"unknown":round($plan['account_value'],2))."\r\n";

	foreach ($plan['errors'] as $error){ echo "  ! ".$error."\r\n"; }

	if (count($plan['orders'])===0){
		echo "  no orders\r\n";
		write_log("strategy",$strategy." ".$as_of_date.": no orders");
		exit(count($plan['errors'])>0 ? 1 : 0);
	}

	foreach ($plan['orders'] as $order){
		printf("  %-4s %-8s %6d @ ~%s\r\n",$order['side'],$order['symbol'],$order['quantity'],
			$order['reference_price']===null?'?':round($order['reference_price'],2));
	}

	if ($dry_run){
		echo "  dry run, nothing written\r\n";
		exit(0);
	}

	//Sells first, so the cash they raise is available to the buys.
	usort($plan['orders'],function($a,$b){
		if ($a['side']===$b['side']){ return 0; }
		return $a['side']==='SELL' ? -1 : 1;
	});

	$filled=$rejected=$skipped=0;

	foreach ($plan['orders'] as $order) {

		//Re-checked per order so dropping the kill switch stops a run midway.
		if (strategy_halted()){
			write_log("strategy","kill switch appeared mid-run, stopping");
			echo "  HALTED mid-run\r\n";
			break;
		}

		$client_order_id=strategy_record_order($strategy,$as_of_date,$order);
		if ($client_order_id===null){ $skipped++; continue; }

		if (strategy_fill_order($client_order_id)){ $filled++; } else { $rejected++; }
	}

	$account=strategy_account($strategy);
	$summary=$strategy." ".$as_of_date.": ".$filled." filled, ".$rejected." rejected, "
		.$skipped." already placed, cash ".round((float)$account['cash'],2);
	write_log("strategy",$summary);
	echo "  ".$summary."\r\n";

	exit($rejected>0 ? 1 : 0);

?>
