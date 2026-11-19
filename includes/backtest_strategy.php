<?php

	/**
	 * backtest_strategy.php — a strategy's own rules, replayed over stored
	 * history through the same engine.
	 *
	 *   php backtest_strategy.php <strategy> <from> <to> [options]
	 *   php backtest_strategy.php --compare [strategy]
	 *
	 * Options, each overriding the strategy's stored rules for this run only:
	 *
	 *   --rebalance=weekly|monthly|daily
	 *   --hold-rank=N     hold a name until it falls out of the top N
	 *   --holdings=N      how many positions
	 *   --keep            leave the orders and positions behind
	 *
	 * The overrides are recorded on the run and shown in its label, because
	 * four runs of the same strategy with four different answers and one
	 * label between them is not a comparison.
	 *
	 * --compare prints one table across the runs: return, return a year,
	 * the benchmark, the worst fall, fills, dealing costs, costs as a share
	 * of the starting capital, and the rebalances the turnover cap refused.
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
	/* What a run tested: the snapshot of its rules and the fingerprint of it. */
	require_once(__DIR__ . "/rules_fingerprint.php");

	/**
	 * Whether sql/strategy_backtest_overrides.sql has been applied.
	 *
	 * Guarded rather than assumed so the script runs on a host one deploy
	 * behind: it loses the override label, the refusal count and the
	 * starting cash, and does the backtest.
	 */
	function backtest_has_overrides() {
		static $known=null;
		if ($known!==null){ return $known; }
		$found=query("select count(*) n from information_schema.columns
		               where table_schema=database() and table_name='strategy_backtest_runs'
		                 and column_name='overrides'");
		$known=count($found)&&(int)$found[0]['n']>0;
		return $known;
	}

	/**
	 * Whether sql/strategy_backtest_costs.sql has been applied.
	 *
	 * The other half of what makes two runs of the same rules different.
	 * Guarded the same way: a host one deploy behind loses the label, not
	 * the backtest.
	 */
	function backtest_has_cost_settings() {
		static $known=null;
		if ($known!==null){ return $known; }
		$found=query("select count(*) n from information_schema.columns
		               where table_schema=database() and table_name='strategy_backtest_runs'
		                 and column_name='cost_settings'");
		$known=count($found)&&(int)$found[0]['n']>0;
		return $known;
	}

	/** Remove the transient copy of the rules the engine reads. Only ever a bt_ name. */
	function backtest_drop_rules_copy($account) {
		if (strpos((string)$account,STRATEGY_BACKTEST_PREFIX)!==0){ return; }
		query("delete from strategy_rules where strategy=?",(string)$account);
	}

	/**
	 * One table across the runs.
	 *
	 * The question these runs are being done to answer is which settings to
	 * run live, and that is a comparison - four results in four terminals
	 * is not one. Costs are shown as a share of the money that went in,
	 * because GBP53,553 means nothing until it is set against GBP100,000.
	 */
	function backtest_compare($strategy=null) {

		$extra=backtest_has_overrides();

		$sql="select run_id, strategy, from_date, to_date, status, trading_days,
		             rebalances, exits, fills, start_value, final_value, total_return,
		             benchmark_return, max_drawdown, costs_paid"
		    .($extra ? ", overrides, turnover_refusals, starting_cash" : "")
		    ." from strategy_backtest_runs";

		$rows=($strategy===null)
		    ? query($sql." order by run_id")
		    : query($sql." where strategy=? order by run_id",$strategy);

		if (!count($rows)){
			echo "no backtest runs".($strategy===null ? "" : " for ".$strategy)." yet\r\n";
			return;
		}

		$pct=function($value,$places=1) {
			return $value===null ? '-' : number_format((float)$value*100,$places).'%';
		};

		printf("%-5s %-18s %-24s %-10s %8s %8s %8s %7s %7s %10s %7s %5s\r\n",
		       'run','strategy','settings','period','return','a year','bench','fall',
		       'fills','costs','costs%','ref');

		foreach ($rows as $row) {

			/* Annualised, so a four year run and a one year run can be read
			   against each other. Trading days rather than calendar days,
			   because trading days are what the run actually covered. */
			$years=((int)$row['trading_days'])/252;
			$a_year=($row['total_return']===null||$years<=0)
			      ? null
			      : pow(1+(float)$row['total_return'],1/$years)-1;

			$start=$extra&&$row['starting_cash']!==null
			     ? (float)$row['starting_cash'] : (float)$row['start_value'];
			$costs_share=($start>0&&$row['costs_paid']!==null)
			           ? (float)$row['costs_paid']/$start : null;

			$settings=$extra&&trim((string)$row['overrides'])!==''
			        ? (string)$row['overrides'] : 'as stored';

			printf("%-5d %-18s %-24s %-10s %8s %8s %8s %7s %7d %10s %7s %5s\r\n",
			       (int)$row['run_id'],
			       substr((string)$row['strategy'],0,18),
			       substr($settings,0,24),
			       substr((string)$row['from_date'],2,8),
			       $row['status']==='DONE' ? $pct($row['total_return']) : $row['status'],
			       $pct($a_year),
			       $pct($row['benchmark_return']),
			       $row['max_drawdown']===null ? '-' : '-'.$pct($row['max_drawdown']),
			       (int)$row['fills'],
			       $row['costs_paid']===null ? '-' : number_format((float)$row['costs_paid'],0),
			       $pct($costs_share),
			       $extra ? (string)(int)$row['turnover_refusals'] : '?');
		}

		echo "\r\n  return   over the whole period, from the money that went in\r\n";
		echo "  a year   the same return annualised over the trading days covered\r\n";
		echo "  costs%   dealing costs as a share of the starting capital\r\n";
		echo "  ref      rebalances the turnover limit refused\r\n";
		echo "\r\n  Every row is flattered by survivorship: the universe is today's FTSE 350.\r\n";
	}

	$_SESSION["exchange"]='XLON';
	$exchange='XLON';

	/* Options are anything starting with --; everything else is positional.
	   Parsed before the usage check so that a mistyped option is named
	   rather than silently read as a date. */
	$options=[]; $args=[];
	foreach (array_slice($argv,1) as $argument) {
		if (strpos($argument,'--')!==0){ $args[]=$argument; continue; }
		$body=substr($argument,2);
		$equals=strpos($body,'=');
		if ($equals===false){ $options[$body]=true; }
		else { $options[substr($body,0,$equals)]=substr($body,$equals+1); }
	}
	$args=array_values($args);
	$keep=isset($options['keep']);
	$compare=isset($options['compare']);

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

	$known_options=['keep','compare','rebalance','hold-rank','holdings','run','cash','costs'];
	foreach (array_keys($options) as $given) {
		if (!in_array($given,$known_options,true)){
			$refuse_usage("Unknown option '--".$given."'. Known: --"
			              .implode(', --',$known_options));
		}
	}

	/* --compare reads what previous runs produced and runs nothing. */
	if ($compare){
		backtest_compare($strategy);
		exit(0);
	}

	if ($strategy===null||$from===null||$to===null){
		$refuse_usage("Usage: php backtest_strategy.php <strategy> <from> <to>"
		              ." [--rebalance=weekly|monthly|daily] [--hold-rank=N] [--holdings=N] [--keep]"
		              ." [--cash=N] [--costs=t212|paper] [--run=ID]"
		              ."\r\n   or: php backtest_strategy.php --compare [strategy]");
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

	/* A run asked for from the strategy's page (--run=ID) is one the job has
	   already claimed: its row says RUNNING, and it carries the rules it was
	   queued with, the cash and the cost model. Those are what is replayed,
	   not whatever the strategy says now, so an edit made while it waited
	   cannot change what the run says it tested. Nothing but the job passes
	   it, and the overrides below are not allowed with it. */
	$queued=null;
	if (isset($options['run'])){
		foreach (['rebalance','holdings','hold-rank'] as $not_with_run) {
			if (isset($options[$not_with_run])){
				$refuse_usage("--".$not_with_run." cannot be used with --run: the run carries the rules it was queued with");
			}
		}
		$found=query("select * from strategy_backtest_runs where run_id=?",(int)$options['run']);
		$queued=count($found) ? $found[0] : null;
		if ($queued===null||$queued['status']!=='RUNNING'||$queued['strategy']!==$strategy
		    ||(string)$queued['from_date']!==$from||(string)$queued['to_date']!==$to){
			$refuse_usage("--run=".$options['run']." is not a run the job has claimed for "
			              .$strategy." ".$from." to ".$to);
		}
	}

	/* The rules are the live strategy's, or the snapshot a queued run
	   carries. Nothing else about the strategy is read, and nothing about it
	   is written. */
	if ($queued!==null){
		$snapshot=json_decode((string)$queued['rules_snapshot'],true);
		if (!is_array($snapshot)||!isset($snapshot['rules'],$snapshot['criteria'])){
			query("update strategy_backtest_runs set status='FAILED', finished_at=?, note=? where run_id=?",
			      date('Y-m-d H:i:s'),'the run carries no rules snapshot',(int)$queued['run_id']);
			$refuse_usage("run ".$queued['run_id']." carries no rules snapshot");
		}
		$rules=rules_from_snapshot($snapshot);

		/* The screen's criteria are read when the screen is built, so they
		   have to be what they were when the run was queued. An edit since
		   would make the replay a different strategy from the one the
		   snapshot describes. */
		$criteria_now=$rules['screen_id']===null ? [] : rules_screen_criteria($rules['screen_id']);
		if ($criteria_now!==$snapshot['criteria']){
			query("update strategy_backtest_runs set status='FAILED', finished_at=?, note=? where run_id=?",
			      date('Y-m-d H:i:s'),
			      'the screen was edited after this run was queued, so it would not test the rules it was asked for; queue it again',
			      (int)$queued['run_id']);
			echo "FAILED: the screen was edited after this run was queued\r\n";
			exit(3);
		}
	}
	else {
		$rules=strategy_rules($strategy);
		if ($rules===null){ $refuse_usage("No rules for '".$strategy."'; see sql/strategy_rules.sql"); }
	}

	/* The cash and the dealing model, from the run or from the command line.
	   Unset, they are what they always were: GBP 100,000, and the dealing
	   rules of the account being replayed. */
	$cash_asked=(float)STRATEGY_BACKTEST_CASH;
	$cost_model=null;
	if ($queued!==null){
		if ($queued['starting_cash']!==null&&(float)$queued['starting_cash']>0){ $cash_asked=(float)$queued['starting_cash']; }
		$cost_model=$queued['cost_model'];
	}
	else {
		if (isset($options['cash'])){
			if (!is_numeric($options['cash'])||(float)$options['cash']<=0){
				$refuse_usage("--cash must be a number above zero");
			}
			$cash_asked=(float)$options['cash'];
		}
		if (isset($options['costs'])){ $cost_model=strtolower(trim((string)$options['costs'])); }
	}
	if ($cost_model!==null&&!in_array($cost_model,['t212','paper'],true)){
		$refuse_usage("--costs must be t212 or paper");
	}

	/* The overrides. Checked against the same values the stored rules may
	   hold, so a backtest cannot be run on a configuration the live engine
	   would refuse. */
	$overrides=[];

	if (isset($options['rebalance'])){
		$wanted=strtolower(trim((string)$options['rebalance']));
		if (!in_array($wanted,['daily','weekly','monthly'],true)){
			$refuse_usage("--rebalance must be daily, weekly or monthly");
		}
		$rules['rebalance']=$wanted;
		$overrides['rebalance']=$wanted;
	}

	if (isset($options['holdings'])){
		$wanted=(int)$options['holdings'];
		if ((string)$wanted!==trim((string)$options['holdings'])||$wanted<1){
			$refuse_usage("--holdings must be a whole number of 1 or more");
		}
		$rules['holdings']=$wanted;
		$overrides['holdings']=$wanted;
	}

	if (isset($options['hold-rank'])){
		$raw=trim((string)$options['hold-rank']);
		if (strtolower($raw)==='none'||$raw==='0'){
			$rules['hold_rank']=null;
			$overrides['hold-rank']='none';
		}
		else {
			$wanted=(int)$raw;
			if ((string)$wanted!==$raw||$wanted<1){
				$refuse_usage("--hold-rank must be a whole number of 1 or more, or 'none'");
			}
			/* The same floor strategy_rules() applies: a buffer narrower
			   than the book is stricter than no buffer at all. */
			$rules['hold_rank']=max($wanted,$rules['holdings']);
			$overrides['hold-rank']=$rules['hold_rank'];
		}
	}

	$override_label=count($overrides)
	              ? implode(', ',array_map(function($k,$v){ return $k.'='.$v; },
	                                       array_keys($overrides),array_values($overrides)))
	              : null;

	$account_before=strategy_account($strategy);
	$cash_before=($account_before===null) ? null : (float)$account_before['cash'];

	/* Record the run first, so a refusal is on the record with its reason
	   rather than only in somebody's terminal. */
	if ($queued!==null){
		$run_id=(int)$queued['run_id'];
		query("update strategy_backtest_runs set account=?, started_at=? where run_id=?",
		      '(pending)',date('Y-m-d H:i:s'),$run_id);
	}
	else {
		query("insert into strategy_backtest_runs
		         (strategy,account,from_date,to_date,started_at,status)
		       values (?,?,?,?,?,'RUNNING')",
		      $strategy,'(pending)',$from,$to,date('Y-m-d H:i:s'));

		$run=query("select max(run_id) id from strategy_backtest_runs where strategy=?",$strategy);
		$run_id=(int)$run[0]['id'];

		/* What this run tested, so the stage can say whether a completed run
		   is of the rules as they are now. The rules as overridden, so a run
		   with an override is not mistaken for one of the stored rules. A
		   queued run was given its snapshot when it was asked for. */
		$has_snapshot=count(query("select 1 from information_schema.columns
		                            where table_schema=database() and table_name='strategy_backtest_runs'
		                              and column_name='fingerprint'"))>0;
		if ($has_snapshot){
			$taken=rules_snapshot_of($rules);
			query("update strategy_backtest_runs set rules_snapshot=?, fingerprint=? where run_id=?",
			      json_encode($taken),rules_fingerprint_of($taken),$run_id);
		}
	}
	$bt=STRATEGY_BACKTEST_PREFIX.substr($strategy,0,30).'_'.$run_id;

	/* Belt and braces: the engine is about to be pointed at this name, so
	   prove it is a backtest account before anything is written. */
	if (strpos($bt,STRATEGY_BACKTEST_PREFIX)!==0){
		$refuse_usage("refusing to run: '".$bt."' is not a backtest account name");
	}

	query("update strategy_backtest_runs set account=? where run_id=?",$bt,$run_id);

	/* The overrides go on the run, so a table of four runs of the same
	   strategy is four different labels rather than one. Guarded, so the
	   script still works on a host that has not applied
	   sql/strategy_backtest_overrides.sql - it just cannot say what was
	   overridden. */
	if (backtest_has_overrides()){
		query("update strategy_backtest_runs set overrides=? where run_id=?",
		      $override_label,$run_id);
	}
	else if ($override_label!==null){
		echo "  ! sql/strategy_backtest_overrides.sql has not been applied, so this run's\r\n"
		    ."    overrides (".$override_label.") cannot be recorded against it\r\n";
	}

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
	     .($rules['hold_rank']===null ? "" : ", hold to rank ".$rules['hold_rank'])
	     .(count($rules['exit_list']) ? ", exits: ".implode(',',$rules['exit_list']) : "")."\r\n";
	if ($override_label!==null){
		echo "  overridden for this run: ".$override_label."\r\n";
	}

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
	/* The dealing rules of the account being replayed, without its broker.
	   A replay of the practice strategy used to deal as a paper account -
	   whole shares, GBP 5.95 a trade, a GBP 250 minimum - none of which
	   the practice account pays, so the figures were a different
	   strategy's: GBP 250 cannot hold fifteen names in whole shares, and
	   every rebalance paid commission the real account would not have.
	   strategy_backtest_mode() maps a broker account onto
	   STRATEGY_MODE_PAPER_T212, which is those dealing rules and no
	   broker, so the replay still fills from stored prices and nothing can
	   send one of its orders anywhere. */
	$bt_mode=strategy_backtest_mode($account_before===null
	                                ? STRATEGY_MODE_PAPER : $account_before['mode']);

	/* A model asked for - on the command line or by the run - overrides the
	   mapping: Trading 212's dealing rules with no broker, or the paper
	   account's. */
	if ($cost_model==='t212'){ $bt_mode=STRATEGY_MODE_PAPER_T212; }
	else if ($cost_model==='paper'){ $bt_mode=STRATEGY_MODE_PAPER; }

	query("insert into strategy_accounts (strategy,cash,currency,mode,enabled,created_at,note)
	       values (?,?,?,?,'Y',?,?)",
	      $bt,$cash_asked,
	      $account_before===null ? 'GBP' : $account_before['currency'],
	      $bt_mode,
	      date('Y-m-d H:i:s'),
	      'Backtest of '.$strategy.' from '.$from.' to '.$to);

	echo "  dealing as: ".strategy_mode_rules($bt_mode)['words']."\r\n";

	/* What this run paid to deal, recorded with it, field by field. Two
	   runs of the same rules a month apart can be two different
	   experiments - the cost settings moved between them - and until now
	   the only thing that said so was the return. The settings are the
	   ones this run actually dealt under, which are the replayed
	   account's: a replay of the practice strategy records no commission,
	   and that is what makes it the run that matches the live settings on
	   the page. The text is recorded rather than the model's name because
	   the spread and the minimum can move inside a model. */
	if (backtest_has_cost_settings()){
		query("update strategy_backtest_runs set cost_settings=? where run_id=?",
		      strategy_backtest_costs_now($bt_mode)['text'],$run_id);
	}

	/* $rules already carries the overrides, so the copy the engine reads
	   for this account is the overridden configuration - and the live
	   strategy's own row is untouched. hold_rank only goes in where the
	   column exists. */
	$has_hold_rank=count(query("select count(*) n from information_schema.columns
	                             where table_schema=database() and table_name='strategy_rules'
	                               and column_name='hold_rank'"))
	             && (int)query("select count(*) n from information_schema.columns
	                             where table_schema=database() and table_name='strategy_rules'
	                               and column_name='hold_rank'")[0]['n']>0;

	/* The engine reads its rules back from the rules table under the
	   account's own name, so the copy has to exist while it runs. It does not
	   outlive the run: it is removed on every way out, including a refusal
	   and a crash that reaches PHP's shutdown, and what remains of the run is
	   its snapshot. */
	register_shutdown_function(function() use ($bt){ backtest_drop_rules_copy($bt); });

	if ($has_hold_rank){
		query("insert into strategy_rules
		         (strategy,selection,screen_id,score_indicator,rank_by,holdings,hold_rank,
		          rebalance,exit_rules,exclude_funds,start_date,updated_at,note)
		       values (?,?,?,?,?,?,?,?,?,?,?,?,?)",
		      $bt,$rules['selection'],$rules['screen_id'],$rules['score_indicator'],$rules['rank_by'],
		      $rules['holdings'],$rules['hold_rank'],$rules['rebalance'],$rules['exit_rules'],
		      $rules['exclude_funds'],
		      $from,date('Y-m-d H:i:s'),'Copied from '.$strategy.' for backtest '.$run_id);
	}
	else {
		query("insert into strategy_rules
		         (strategy,selection,screen_id,score_indicator,rank_by,holdings,rebalance,
		          exit_rules,exclude_funds,start_date,updated_at,note)
		       values (?,?,?,?,?,?,?,?,?,?,?,?)",
		      $bt,$rules['selection'],$rules['screen_id'],$rules['score_indicator'],$rules['rank_by'],
		      $rules['holdings'],$rules['rebalance'],$rules['exit_rules'],$rules['exclude_funds'],
		      $from,date('Y-m-d H:i:s'),'Copied from '.$strategy.' for backtest '.$run_id);
	}

	$rebalances=0;
	$exits=0;
	$turnover_refusals=0;
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
			/* "no targets" on a night that is not a rebalance used to be
			   filtered out here by matching on its text. The engine does
			   not say it any more: it knows whether tonight is a rebalance
			   and only calls missing targets a problem when they are one. */
			/* A rebalance the turnover cap refused is a fact about the
			   result rather than a problem with the run, and it is the
			   fact the first backtest could not report: it printed the
			   first ten problems and a count of the rest, so "10 or more"
			   was as precise as it got. Counted, and kept out of the
			   problem list. */
			if (strpos($error,'turnover ')===0){ $turnover_refusals++; continue; }
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

	/* The money that went in, not what was left after the first build paid
	   for itself. The first run reported "start 98,487.63" - the value on
	   the first night, already down by the first rebalance's dealing costs
	   - so the return was measured from a number the costs had already come
	   out of, and the costs were understated by exactly that much. */
	$starting_cash=$cash_asked;
	$first_night=count($series) ? $series[0]['total'] : null;
	$start_value=$starting_cash;
	$final_value=count($series) ? $series[count($series)-1]['total'] : null;
	$total_return=($start_value>0&&$final_value!==null)
	            ? ($final_value/$start_value)-1 : null;
	$drawdown=strategy_drawdown($series);
	$benchmark=strategy_benchmark_return($from,$to,$exchange);
	$costs=strategy_costs_paid($bt);
	$fills=$costs['fills'];

	/* Only what is true of THIS run. The survivorship and look-ahead
	   caveat used to be written in here as well, so a table of six runs
	   printed it six times under the copy at the top of the page and
	   nobody read any of them. It is a property of the method, so the
	   page says it once; strategy_backtest_note() strips it from the runs
	   that already carry it. */
	$note="";

	if ($turnover_refusals>0){
		$note .= $turnover_refusals." rebalance".($turnover_refusals===1?"":"s")
		      ." refused by the turnover limit.";
	}

	if (count($errors)){
		$note .= ($note===""?"":" ")."Problems: ".implode('; ',array_slice($errors,0,5))
		      .(count($errors)>5 ? " (+".(count($errors)-5)." more)" : "");
	}

	query("update strategy_backtest_runs
	          set status=?, finished_at=?, trading_days=?, rebalances=?, exits=?, fills=?,
	              start_value=?, final_value=?, total_return=?, benchmark_return=?,
	              max_drawdown=?, costs_paid=?, note=?
	        where run_id=?",
	      'DONE',date('Y-m-d H:i:s'),count($dates),$rebalances,$exits,$fills,
	      round($start_value,4),
	      $final_value===null ? null : round($final_value,4),
	      $total_return===null ? null : round($total_return,6),
	      $benchmark===null ? null : round($benchmark,6),
	      round($drawdown,6),round($costs['total'],4),
	      $note==="" ? null : substr($note,0,1000),$run_id);

	if (backtest_has_overrides()){
		query("update strategy_backtest_runs set turnover_refusals=?, starting_cash=?
		        where run_id=?",$turnover_refusals,round($starting_cash,4),$run_id);
	}

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
	echo "  started with ".round($starting_cash,2)
	     .($first_night===null ? "" : " (worth ".round($first_night,2)." after the first build)")
	     ." final ".($final_value===null?'?':round($final_value,2))
	     ." return ".($total_return===null?'?':round($total_return*100,2)."%")."\r\n";
	echo "  benchmark over the same period: "
	     .($benchmark===null?'?':round($benchmark*100,2)."%")."\r\n";
	echo "  worst fall ".round($drawdown*100,2)."%, dealing costs "
	     .round($costs['total'],2)
	     .($starting_cash>0 ? " (".round($costs['total']/$starting_cash*100,1)
	                          ."% of what went in)" : "")."\r\n";
	if ($turnover_refusals>0){
		echo "  ".$turnover_refusals." rebalance".($turnover_refusals===1?"":"s")
		     ." refused by the turnover limit of ".(STRATEGY_MAX_TURNOVER*100)."%\r\n";
	}
	foreach (array_slice($errors,0,10) as $error) { echo "  ! ".$error."\r\n"; }
	if (count($errors)>10){ echo "  ! and ".(count($errors)-10)." more\r\n"; }
	echo "\r\n  READ THIS WITH THE RESULT:\r\n";
	echo "  survivorship - the universe is today's FTSE 350, so the companies that left it are\r\n";
	echo "                 missing. Every result here is flattered by that.\r\n";
	echo "  look-ahead   - each date used only the statistics stored for that date.\r\n";

	write_log("backtest",$bt." done days=".count($dates)." rebalances=".$rebalances
	          ." refused=".$turnover_refusals
	          ." exits=".$exits." fills=".$fills
	          ." return=".($total_return===null?'?':round($total_return,4))
	          ." benchmark=".($benchmark===null?'?':round($benchmark,4))
	          ." drawdown=".round($drawdown,4)
	          ." errors=".count($errors));

	exit(count($errors)>0 ? 1 : 0);

?>
