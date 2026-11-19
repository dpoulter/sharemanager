<?php

	/**
	 * Backtest runs: ask for one, and follow it from queued to done or failed.
	 *
	 * A request from the strategy's page only QUEUES a run. Nothing is
	 * replayed inside the web request: a five year replay takes minutes and a
	 * web server is the wrong place to spend them. includes/run_backtests.php
	 * picks queued runs up, one at a time, outside the web server, and the
	 * engine (includes/backtest_strategy.php) fills in the result.
	 *
	 * A run keeps the rules it was asked to test - a snapshot, with the
	 * criteria of its screen - and the fingerprint of that snapshot
	 * (rules_fingerprint.php), so a later edit cannot change what the run says
	 * it tested. The stage reads those; nothing here compares rules.
	 *
	 * Never: writes a copy of a strategy into the rules table, changes a
	 * strategy's rules, touches an account, or calls the broker. It writes
	 * rows in strategy_backtest_runs and nothing else.
	 */

	require_once("constants.php");
	require_once(__DIR__ . "/strategy_functions.php");
	require_once(__DIR__ . "/rules_fingerprint.php");
	require_once(__DIR__ . "/strategy_stage.php");

	/** The dealing models a run may be asked to use. */
	function backtest_cost_models() {
		return ['t212' => 'Trading 212 (no commission, a stamp duty and a spread)',
		        'paper' => 'Paper (GBP 5.95 a trade, GBP 250 minimum)'];
	}

	/** Whether sql/strategy_workflow.sql and sql/backtest_queue.sql are applied. */
	function backtest_runs_ready() {

		static $known = null;
		if ($known !== null) { return $known; }

		if (!strategy_backtest_ready() || !strategy_stage_ready()) { return $known = false; }

		$rows = query("select count(*) n from information_schema.columns
		                where table_schema=database() and table_name='strategy_backtest_runs'
		                  and column_name in ('requested_at','requested_by','cost_model','starting_cash')");
		$known = count($rows) && (int) $rows[0]['n'] === 4;
		return $known;
	}

	/** "Queued" or "Running" if the strategy has such a run, else null. */
	function backtest_run_active($strategy) {

		if (!backtest_runs_ready()) { return null; }

		$rows = query("select status from strategy_backtest_runs
		                where strategy=? and status in ('QUEUED','RUNNING')
		                order by run_id limit 1", (string) $strategy);
		return count($rows) ? ucfirst(strtolower((string) $rows[0]['status'])) : null;
	}

	/** One run, or null. */
	function backtest_run_get($run_id) {
		$rows = query("select * from strategy_backtest_runs where run_id=?", (int) $run_id);
		return count($rows) ? $rows[0] : null;
	}

	/** The latest runs of a strategy, newest first, for the page. */
	function backtest_runs_for($strategy, $limit = 10) {

		if (!backtest_runs_ready()) { return []; }
		return query("select run_id, status, from_date, to_date, requested_at, finished_at,
		                     starting_cash, cost_model, total_return, benchmark_return, note
		                from strategy_backtest_runs where strategy=?
		               order by run_id desc limit " . max(1, min(50, (int) $limit)), (string) $strategy);
	}

	/**
	 * Ask for a backtest. $options: user_id, strategy, from, to, cash,
	 * cost_model. A web POST by a logged in user and nothing else, and the
	 * move has to be open in strategy_stage - the server's answer, not the
	 * page's.
	 *
	 * Returns ['ok'=>bool, 'why'=>string, 'run_id'=>int when ok].
	 */
	function backtest_request($options = []) {

		$refuse = function ($why) {
			write_log("backtest", "request refused: " . $why);
			return ['ok' => false, 'why' => $why];
		};

		if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
			return $refuse("a backtest is asked for on the strategy's page, in a logged in session."
			             . " The command line runs the engine directly.");
		}
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
			return $refuse("a backtest is asked for with a form, so it is a POST and nothing else.");
		}
		if (!backtest_runs_ready()) {
			return $refuse("apply sql/strategy_workflow.sql and sql/backtest_queue.sql first.");
		}

		$user_id = isset($options['user_id']) ? (int) $options['user_id'] : 0;
		$users = $user_id > 0 ? query("select username from users where id=?", $user_id) : [];
		if (!count($users)) { return $refuse("asking for a backtest needs somebody logged in."); }

		$strategy = (string) ($options['strategy'] ?? '');
		if (strategy_reserved($strategy) || strategy_rules($strategy) === null) {
			return $refuse("there is no strategy called that.");
		}

		/* The move itself, asked of the server. */
		$stage = strategy_stage($strategy);
		if (!$stage['moves']['backtest']['open']) {
			return $refuse("it cannot be backtested now: " . $stage['moves']['backtest']['reason'] . ".");
		}

		$from = (string) ($options['from'] ?? '');
		$to   = (string) ($options['to'] ?? '');
		foreach ([$from, $to] as $date) {
			$parsed = date_create_from_format('Y-m-d', $date);
			if ($parsed === false || date_format($parsed, 'Y-m-d') !== $date) {
				return $refuse("'" . $date . "' is not a date; use YYYY-MM-DD.");
			}
		}
		if ($from >= $to) { return $refuse("the start date has to be before the end date."); }

		$data = query("select min(date) first, max(date) last from historical_prices where exchange='XLON'");
		if (!count($data) || $data[0]['first'] === null) { return $refuse("there are no prices to replay."); }
		if ($from < $data[0]['first'] || $to > $data[0]['last']) {
			return $refuse("the dates have to be inside the data, which runs from "
			             . $data[0]['first'] . " to " . $data[0]['last'] . ".");
		}

		$cash_raw = trim((string) ($options['cash'] ?? ''));
		$cash = $cash_raw === '' ? (float) STRATEGY_BACKTEST_CASH : (is_numeric($cash_raw) ? (float) $cash_raw : 0.0);
		if (!($cash > 0) || $cash > 100000000) {
			return $refuse("the starting cash has to be more than nothing, and at most 100,000,000.");
		}

		$model = strtolower(trim((string) ($options['cost_model'] ?? '')));
		if ($model === '') { $model = 't212'; }
		if (!isset(backtest_cost_models()[$model])) {
			return $refuse("the cost model is t212 or paper.");
		}

		$snapshot = rules_snapshot($strategy);
		$earliest = strategy_backtest_earliest(strategy_rules($strategy));
		if (count($earliest['missing'])) {
			return $refuse("it needs " . implode(', ', $earliest['missing']) . ", which has no stored history.");
		}
		if ($earliest['date'] !== null && $from < $earliest['date']) {
			return $refuse("its indicators only exist from " . $earliest['date']
			             . ", and replaying earlier would rank the past on today's figures.");
		}

		/* One queued or running run per strategy, decided by the insert
		   itself so two requests at once cannot both get in. */
		query("insert into strategy_backtest_runs
		         (strategy, account, from_date, to_date, started_at, status, rules_snapshot,
		          fingerprint, starting_cash, cost_model, requested_at, requested_by)
		       select ?, '(queued)', ?, ?, now(), 'QUEUED', ?, ?, ?, ?, now(), ? from dual
		        where not exists (select 1 from strategy_backtest_runs
		                           where strategy=? and status in ('QUEUED','RUNNING'))",
		      $strategy, $from, $to, json_encode($snapshot), rules_fingerprint_of($snapshot),
		      round($cash, 4), $model, (string) $users[0]['username'], $strategy);

		$rows = query("select run_id from strategy_backtest_runs
		                where strategy=? and status='QUEUED' and requested_by=?
		                order by run_id desc limit 1", $strategy, (string) $users[0]['username']);
		if (!count($rows)) {
			return $refuse("a backtest of it is already queued or running.");
		}

		write_log("backtest", "queued run " . $rows[0]['run_id'] . " of " . $strategy
		          . " " . $from . " to " . $to . " by " . $users[0]['username']);
		return ['ok' => true, 'why' => '', 'run_id' => (int) $rows[0]['run_id']];
	}

	/* ---- what the job needs ---- */

	/** The oldest queued run, or null. */
	function backtest_next_queued() {
		$rows = query("select * from strategy_backtest_runs where status='QUEUED' order by run_id limit 1");
		return count($rows) ? $rows[0] : null;
	}

	/** QUEUED to RUNNING. True only if this call is the one that did it. */
	function backtest_claim($run_id) {
		query("update strategy_backtest_runs set status='RUNNING', started_at=now()
		        where run_id=? and status='QUEUED'", (int) $run_id);
		$run = backtest_run_get($run_id);
		return $run !== null && $run['status'] === 'RUNNING';
	}

	/** Mark a run failed, with why; a run already finished is left alone. */
	function backtest_fail($run_id, $why) {
		query("update strategy_backtest_runs set status='FAILED', finished_at=now(), note=?
		        where run_id=? and status in ('QUEUED','RUNNING')", substr((string) $why, 0, 1000), (int) $run_id);
		write_log("backtest", "run " . $run_id . " failed: " . $why);
	}

	/**
	 * Remove what a run's engine leaves under its own reserved name: the
	 * account, its rows, and the transient copy of the rules the engine reads.
	 * Only ever a bt_ name.
	 */
	function backtest_clean_account($account) {

		$account = (string) $account;
		if (strpos($account, STRATEGY_BACKTEST_PREFIX) !== 0) { return; }

		foreach (['strategy_orders', 'strategy_positions', 'strategy_targets', 'strategy_rules',
		          'strategy_accounts'] as $table) {
			query("delete from " . $table . " where strategy=?", $account);
		}
	}

	/**
	 * A run still RUNNING when the job starts was left by a crash - the job
	 * is the only thing that runs one, and only one job runs at a time - so
	 * it is marked failed and its leftovers removed. It is NOT run again.
	 * Returns the run ids.
	 */
	function backtest_recover_interrupted() {

		$out = [];
		foreach (query("select run_id, account from strategy_backtest_runs where status='RUNNING'") as $run) {
			backtest_fail($run['run_id'], "the run was interrupted (the job or the server stopped) and is not run again; queue it again");
			backtest_clean_account($run['account']);
			$out[] = (int) $run['run_id'];
		}
		return $out;
	}

/* No closing tag: see the note at the end of constants.php. */
