<?php

	/**
	 * Backtest runs: ask for one, and follow it from queued to done or failed.
	 *
	 * The backtest_queue module (contracts/modules.yaml). A request from the
	 * strategy's page only QUEUES a run. Nothing is replayed on the N100: the
	 * Mac mini research engine claims the run through public/backtest_api.php
	 * (which calls the functions here and writes no table itself), runs it
	 * and posts the result or the failure back. Only one claim can take a
	 * queued run; a run left running past BACKTEST_TIME_LIMIT_MINUTES is
	 * marked failed with that reason and never handed out again.
	 *
	 * The holdout: a request whose end date is on or after
	 * BACKTEST_HOLDOUT_START is refused, here, for the form and the API
	 * alike. Nothing in sharemanager can ask for the final exam.
	 *
	 * A done run keeps its snapshot and fingerprint and cannot be changed
	 * (every write is guarded by status). Earlier runs (engine null) stay as
	 * they are and are shown as N100 runs.
	 *
	 * A run keeps the rules it was asked to test - a snapshot, with the
	 * criteria of its screen - and the fingerprint of that snapshot
	 * (rules_fingerprint.php), so a later edit cannot change what the run says
	 * it tested. The stage reads those; nothing here compares rules.
	 *
	 * Never: writes a copy of a strategy into the rules table, changes a
	 * strategy's rules, touches an account, or calls the broker. It writes
	 * rows in strategy_backtest_runs, and report files in
	 * BACKTEST_REPORTS_DIR, and nothing else.
	 */

	require_once("constants.php");
	require_once(__DIR__ . "/strategy_functions.php");
	require_once(__DIR__ . "/rules_fingerprint.php");
	require_once(__DIR__ . "/strategy_stage.php");
	require_once(__DIR__ . "/backtest_config.php");

	/** The dealing models a run may be asked to use. */
	function backtest_cost_models() {
		return ['t212' => 'Trading 212 (no commission, a stamp duty and a 10 bp spread)',
		        'paper' => 'Paper (GBP 5.95 a trade, GBP 250 minimum, 25 bp spread)'];
	}

	/** Whether sql/strategy_workflow.sql and sql/backtest_queue.sql are applied. */
	function backtest_runs_ready() {

		static $known = null;
		if ($known !== null) { return $known; }

		if (!strategy_backtest_ready() || !strategy_stage_ready()) { return $known = false; }

		$rows = query("select count(*) n from information_schema.columns
		                where table_schema=database() and table_name='strategy_backtest_runs'
		                  and column_name in ('requested_at','requested_by','cost_model','starting_cash')");
		if (!count($rows) || (int) $rows[0]['n'] !== 4) { return $known = false; }

		$rows = query("select count(*) n from information_schema.columns
		                where table_schema=database() and table_name='strategy_backtest_runs'
		                  and column_name in ('engine','claimed_at','research_run_id','engine_commit',
		                                      'cap_weight_return','deflated_sharpe','trial_count',
		                                      'report_file','result_json','deflated_sharpe_prob')");
		$known = count($rows) && (int) $rows[0]['n'] === 10;
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
		return query("select run_id, status, from_date, to_date, requested_at, finished_at, engine,
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
			return $refuse("apply sql/strategy_workflow.sql, sql/backtest_queue.sql, sql/backtest_mac_mini.sql"
			             . " and sql/backtest_dsr.sql first.");
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

		$dates = backtest_dates_refusal($from, $to);
		if ($dates !== null) { return $refuse($dates); }

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

		/* One queued or running run per strategy, decided by the insert
		   itself so two requests at once cannot both get in. */
		query("insert into strategy_backtest_runs
		         (strategy, account, from_date, to_date, started_at, status, rules_snapshot,
		          fingerprint, starting_cash, cost_model, requested_at, requested_by, engine)
		       select ?, '(queued)', ?, ?, now(), 'QUEUED', ?, ?, ?, ?, now(), ?, 'mac_mini' from dual
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

	/**
	 * The oldest queued run for the old N100 job, or null. Every run asked
	 * for now is the Mac mini's (engine 'mac_mini'), so the N100 job finds
	 * nothing to replay; it stays only until the PHP engine is removed.
	 */
	function backtest_next_queued() {
		$rows = query("select * from strategy_backtest_runs where status='QUEUED' and engine is null
		                order by run_id limit 1");
		return count($rows) ? $rows[0] : null;
	}

	/** QUEUED to RUNNING. True only if this call is the one that did it. */
	function backtest_claim($run_id) {
		query("update strategy_backtest_runs set status='RUNNING', started_at=now()
		        where run_id=? and status='QUEUED' and engine is null", (int) $run_id);
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
		foreach (query("select run_id, account from strategy_backtest_runs where status='RUNNING' and engine is null") as $run) {
			backtest_fail($run['run_id'], "the run was interrupted (the job or the server stopped) and is not run again; queue it again");
			backtest_clean_account($run['account']);
			$out[] = (int) $run['run_id'];
		}
		return $out;
	}

	/* ---- the holdout and the dates ---- */

	/**
	 * Why these dates cannot be queued, or null. The one place the holdout
	 * is checked on the sharemanager side, for the form and the API alike.
	 */
	function backtest_dates_refusal($from, $to) {

		foreach ([$from, $to] as $date) {
			$parsed = date_create_from_format('Y-m-d', (string) $date);
			if ($parsed === false || date_format($parsed, 'Y-m-d') !== (string) $date) {
				return "'" . $date . "' is not a date; use YYYY-MM-DD.";
			}
		}
		if ($from >= $to) { return "the start date has to be before the end date."; }
		if ($to >= BACKTEST_HOLDOUT_START) {
			return "the end date has to be before " . BACKTEST_HOLDOUT_START . ", where the 18-month holdout"
			     . " starts. The holdout is the final exam, run by hand on the Mac mini, never from here.";
		}
		if ($from < BACKTEST_EARLIEST) {
			return "the research engine's universe starts on " . BACKTEST_EARLIEST . ".";
		}
		return null;
	}

	/* ---- what the Mac mini asks, through backtest_api ---- */

	/**
	 * A Mac mini run still RUNNING past its time limit is marked failed with
	 * that reason. It is never handed out again. Returns the run ids.
	 */
	function backtest_expire_overdue() {

		$limit = max(1, (int) BACKTEST_TIME_LIMIT_MINUTES);
		$out = [];
		foreach (query("select run_id from strategy_backtest_runs
		                 where status='RUNNING' and engine='mac_mini'
		                   and claimed_at < now() - interval " . $limit . " minute") as $run) {
			query("update strategy_backtest_runs set status='FAILED', finished_at=now(), note=?
			        where run_id=? and status='RUNNING' and engine='mac_mini'",
			      "it ran past its time limit of " . $limit . " minutes and is not run again; queue it again",
			      (int) $run['run_id']);
			write_log("backtest", "run " . $run['run_id'] . " failed: past its time limit of " . $limit . " minutes");
			$out[] = (int) $run['run_id'];
		}
		return $out;
	}

	/** The cost settings a cost model stands for, as the engine is to deal them. */
	function backtest_cost_settings($model) {
		$models = [
			/* As run 9 was dealt (Trading 212, the engine check of 20 Nov 2026). */
			't212'  => ['commission' => 0.0, 'stamp_duty' => STRATEGY_STAMP_DUTY_RATE, 'spread_bp' => 10.0,
			            'minimum_order' => 250.0, 'whole_shares' => true],
			'paper' => ['commission' => STRATEGY_COMMISSION, 'stamp_duty' => STRATEGY_STAMP_DUTY_RATE,
			            'spread_bp' => (float) STRATEGY_SLIPPAGE_BPS,
			            'minimum_order' => 250.0, 'whole_shares' => true],
		];
		return $models[(string) $model] ?? null;
	}

	/**
	 * What the claim hands out for a run: the rules snapshot, its
	 * fingerprint, the dates, the cash and the cost model - and, so the
	 * snapshot's criteria can be read, the name and rank order of each
	 * indicator they refer to. Never an account, a position or a broker detail.
	 */
	function backtest_claim_payload(array $run) {

		$snapshot = json_decode((string) $run['rules_snapshot'], true);
		$ids = [];
		if (is_array($snapshot)) {
			foreach (($snapshot['criteria'] ?? []) as $c) { $ids[] = (int) $c[0]; }
		}
		$indicators = [];
		if (count($ids)) {
			$ids = array_values(array_unique($ids));
			$rows = query("select indicator_id, name, rank_order from screen_indicators where indicator_id in ("
			              . implode(',', array_fill(0, count($ids), '?')) . ")", ...$ids);
			foreach ($rows as $r) {
				$indicators[(string) $r['indicator_id']] = ['name' => (string) $r['name'],
				                                            'rank_order' => (string) $r['rank_order']];
			}
		}
		$rank_orders = [];
		foreach ([$snapshot['rules']['rank_by'] ?? null, $snapshot['rules']['score_indicator'] ?? null] as $name) {
			if ($name === null || $name === '') { continue; }
			$rows = query("select rank_order from screen_indicators where name=? limit 1", (string) $name);
			$rank_orders[(string) $name] = count($rows) ? (string) $rows[0]['rank_order'] : null;
		}

		return [
			'run_id'         => (int) $run['run_id'],
			'strategy'       => (string) $run['strategy'],
			'from'           => (string) $run['from_date'],
			'to'             => (string) $run['to_date'],
			'starting_cash'  => (float) $run['starting_cash'],
			'cost_model'     => (string) $run['cost_model'],
			'cost_settings'  => backtest_cost_settings($run['cost_model']),
			'fingerprint'    => (string) $run['fingerprint'],
			'rules_snapshot' => $snapshot,
			'indicators'     => $indicators,
			'rank_orders'    => $rank_orders,
			'holdout_start'  => BACKTEST_HOLDOUT_START,
		];
	}

	/**
	 * The Mac mini claims the oldest queued run. Only one claim can take a
	 * run: the claim is one guarded update, and a second claim at the same
	 * moment finds the row no longer QUEUED. Overdue runs are failed first.
	 * A queued run that reaches into the holdout (asked for before the check
	 * existed) is refused rather than handed out.
	 *
	 * Returns the payload, or null when nothing is queued.
	 */
	function backtest_claim_next() {

		backtest_expire_overdue();

		for ($tries = 0; $tries < 20; $tries++) {

			$rows = query("select run_id, to_date, from_date from strategy_backtest_runs
			                where status='QUEUED' and engine='mac_mini' order by run_id limit 1");
			if (!count($rows)) { return null; }
			$id = (int) $rows[0]['run_id'];

			$why = backtest_dates_refusal((string) $rows[0]['from_date'], (string) $rows[0]['to_date']);
			if ($why !== null) {
				query("update strategy_backtest_runs set status='REFUSED', finished_at=now(), note=?
				        where run_id=? and status='QUEUED'", substr("not handed out: " . $why, 0, 1000), $id);
				continue;
			}

			$claim = bin2hex(random_bytes(8));
			query("update strategy_backtest_runs set status='RUNNING', started_at=now(), claimed_at=now(),
			              account=?
			        where run_id=? and status='QUEUED' and engine='mac_mini'", 'claim:' . $claim, $id);
			$run = backtest_run_get($id);
			if ($run === null || $run['status'] !== 'RUNNING' || $run['account'] !== 'claim:' . $claim) {
				continue;            /* somebody else's claim took it; look again */
			}
			write_log("backtest", "run " . $id . " claimed by the Mac mini");
			return backtest_claim_payload($run);
		}
		return null;
	}

	/** The run if a result or a failure may be posted for it, else why not. */
	function backtest_postable($run_id, $fingerprint) {

		$run = backtest_run_get((int) $run_id);
		if ($run === null) { return [null, "there is no run " . (int) $run_id . "."]; }
		if ($run['engine'] !== 'mac_mini' || $run['status'] !== 'RUNNING') {
			return [null, "run " . (int) $run_id . " is not running (it is " . strtolower((string) $run['status']) . ")."];
		}
		if (!is_string($fingerprint) || !hash_equals((string) $run['fingerprint'], $fingerprint)) {
			return [null, "the fingerprint does not match run " . (int) $run_id . "'s rules."];
		}
		return [$run, null];
	}

	/** The fields a result has to carry, and the column each goes to. */
	function backtest_result_fields() {
		return [
			'trading_days'       => ['trading_days', 'int'],
			'rebalances'         => ['rebalances', 'int'],
			'fills'              => ['fills', 'int'],
			'start_value'        => ['start_value', 'float'],
			'final_value'        => ['final_value', 'float'],
			'total_return'       => ['total_return', 'float'],
			'annual_return'      => ['annual_return', 'float'],
			'bench_equal_weight_return' => ['benchmark_return', 'float'],
			'bench_cap_weight_return'   => ['cap_weight_return', 'float'],
			'max_drawdown'       => ['max_drawdown', 'float'],
			'costs_paid'         => ['costs_paid', 'float'],
			'sharpe'             => ['sharpe', 'float'],
			'deflated_sharpe'    => ['deflated_sharpe', 'float'],
			'trials'             => ['trial_count', 'int'],
			/* Null when the family has too few distinct trials (metrics.MIN_TRIALS_FOR_DEFLATION). */
			'dsr'                => ['deflated_sharpe_prob', 'float'],
		];
	}

	/**
	 * The Mac mini posts a result. Refused for a run that is not running or
	 * whose fingerprint does not match. The report is written to a file the
	 * server names; the row goes RUNNING to DONE in one guarded update, so a
	 * done run cannot be changed afterwards.
	 *
	 * $result: the fields of backtest_result_fields(), plus research_run_id,
	 * engine_commit and record (the engine's whole record). $report: the
	 * report's bytes (HTML).
	 */
	function backtest_post_result($run_id, $fingerprint, $result, $report) {

		[$run, $why] = backtest_postable($run_id, $fingerprint);
		if ($run === null) { write_log("backtest", "result refused: " . $why); return ['ok' => false, 'why' => $why]; }
		if (!is_array($result)) { return ['ok' => false, 'why' => "the result is not an object."]; }

		$set = []; $args = [];
		foreach (backtest_result_fields() as $key => [$column, $type]) {
			$v = $result[$key] ?? null;
			if ($v !== null && !is_numeric($v)) { return ['ok' => false, 'why' => "the result's " . $key . " is not a number."]; }
			/* The NOT NULL columns, and what a result is for: refused with a reason, never a fatal. */
			if ($v === null && in_array($key, ['trading_days', 'rebalances', 'fills', 'total_return', 'final_value',
			                                   'trials'], true)) {
				return ['ok' => false, 'why' => "the result has no " . $key . "."];
			}
			if ($v !== null && is_float($v + 0) && !is_finite((float) $v)) { $v = null; }
			$set[] = $column . "=?";
			$args[] = $v === null ? null : ($type === 'int' ? (int) $v : round((float) $v, 6));
		}
		foreach (['research_run_id' => 64, 'engine_commit' => 40] as $key => $len) {
			$v = $result[$key] ?? '';
			if (!is_string($v) || $v === '' || strlen($v) > $len || !preg_match('~^[A-Za-z0-9_.+:-]+$~', $v)) {
				return ['ok' => false, 'why' => "the result's " . $key . " is missing or not a plain name."];
			}
			$set[] = $key . "=?"; $args[] = $v;
		}
		if (!is_string($report) || $report === '' || strlen($report) > BACKTEST_REPORT_MAX_BYTES) {
			return ['ok' => false, 'why' => "the report is missing or larger than " . BACKTEST_REPORT_MAX_BYTES . " bytes."];
		}

		/* The report: a name the server chooses, never one the caller gave. */
		$dir = BACKTEST_REPORTS_DIR;
		if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
			return ['ok' => false, 'why' => "the reports folder cannot be written; the run stays running."];
		}
		$name = 'run-' . (int) $run_id . '-' . bin2hex(random_bytes(6)) . '.html';
		if (@file_put_contents($dir . '/' . $name, $report, LOCK_EX) !== strlen($report)) {
			@unlink($dir . '/' . $name);
			return ['ok' => false, 'why' => "the report could not be written; the run stays running."];
		}

		$record = json_encode($result['record'] ?? null, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
		$set[] = "report_file=?"; $args[] = $name;
		$set[] = "result_json=?"; $args[] = $record;
		$args[] = (int) $run_id; $args[] = (string) $fingerprint;

		/* The report is on disk now: every way out that does not end DONE removes it. */
		try {
			query("update strategy_backtest_runs set status='DONE', finished_at=now(), note=null, " . implode(', ', $set)
			      . " where run_id=? and fingerprint=? and status='RUNNING' and engine='mac_mini'", ...$args);
			$after = backtest_run_get($run_id);
		} catch (Throwable $e) {
			@unlink($dir . '/' . $name);
			write_log("backtest", "result for run " . (int) $run_id . " not stored: " . $e->getMessage());
			return ['ok' => false, 'why' => "the result could not be stored (" . substr($e->getMessage(), 0, 200)
			                               . "); the run stays running."];
		}
		if ($after === null || $after['status'] !== 'DONE' || $after['report_file'] !== $name) {
			@unlink($dir . '/' . $name);
			return ['ok' => false, 'why' => "run " . (int) $run_id . " stopped running before the result arrived."];
		}
		write_log("backtest", "run " . (int) $run_id . " done on the Mac mini (" . $after['research_run_id'] . ")");
		return ['ok' => true, 'why' => ''];
	}

	/** The Mac mini posts a failure, with why. Refused as a result is. */
	function backtest_post_failure($run_id, $fingerprint, $why_failed) {

		[$run, $why] = backtest_postable($run_id, $fingerprint);
		if ($run === null) { write_log("backtest", "failure refused: " . $why); return ['ok' => false, 'why' => $why]; }
		$text = trim((string) $why_failed);
		if ($text === '') { return ['ok' => false, 'why' => "a failure has to say why."]; }

		query("update strategy_backtest_runs set status='FAILED', finished_at=now(), note=?
		        where run_id=? and fingerprint=? and status='RUNNING' and engine='mac_mini'",
		      substr("on the Mac mini: " . $text, 0, 1000), (int) $run_id, (string) $fingerprint);
		write_log("backtest", "run " . (int) $run_id . " failed on the Mac mini: " . substr($text, 0, 300));
		return ['ok' => true, 'why' => ''];
	}

	/* ---- what the page shows ---- */

	/** "Mac mini" or "N100": which engine a run came from. */
	function backtest_engine_label(array $run) {
		return ($run['engine'] ?? null) === 'mac_mini' ? 'Mac mini' : 'N100';
	}

	/**
	 * The labels a result is shown with: the survivorship label on every
	 * result that starts before 2022, the restatement caveat on every one.
	 */
	function backtest_result_labels(array $run) {
		$out = [];
		if ((string) $run['from_date'] < '2022-01-01') {
			$out[] = "Survivorship: before 2022 the data holds few companies that later died, so this result"
			       . " is flattered.";
		}
		if ((string) $run['from_date'] < '2016-01-01') {
			$out[] = "Cap-weighted benchmark: before 2016 it rests on unchecked market caps, some known to be"
			       . " wrong (Lloyds and Hammerson shown as the largest companies in 2007).";
		}
		$out[] = "Restatements: company accounts are the latest figures, not those first published, so"
		       . " value and quality signals may see corrections made later.";
		return $out;
	}

	/** The Mac mini runs of a strategy that are done, newest first, for the page. */
	function backtest_mac_mini_results($strategy, $limit = 10) {
		if (!backtest_runs_ready()) { return []; }
		return query("select * from strategy_backtest_runs where strategy=? and engine='mac_mini' and status='DONE'
		               order by run_id desc limit " . max(1, min(50, (int) $limit)), (string) $strategy);
	}

	/**
	 * The deflated Sharpe of a done Mac mini run, in words: the DSR
	 * probability beside the Sharpe, or, when the family had too few
	 * distinct trials for the deflation to mean anything, that.
	 */
	function backtest_dsr_words(array $run) {
		$sharpe = $run['sharpe'] === null ? 'no Sharpe' : 'Sharpe ' . number_format((float) $run['sharpe'], 2);
		if (($run['deflated_sharpe_prob'] ?? null) === null) {
			return $sharpe . '; too few distinct trials in this family (' . (int) $run['trial_count']
			     . ' so far, 5 needed) to deflate it, so no deflated figure is shown';
		}
		return $sharpe . '; probability the true Sharpe is above zero after ' . (int) $run['trial_count'] . ' trial'
		     . ((int) $run['trial_count'] === 1 ? '' : 's') . ': '
		     . number_format((float) $run['deflated_sharpe_prob'] * 100, 1) . '%';
	}

	/** The full path of a done run's report, or null. */
	function backtest_report_path(array $run) {
		$name = (string) ($run['report_file'] ?? '');
		if (!preg_match('~^run-\d+-[0-9a-f]{12}\.html$~', $name)) { return null; }
		$path = BACKTEST_REPORTS_DIR . '/' . $name;
		return is_file($path) ? $path : null;
	}

/* No closing tag: see the note at the end of constants.php. */
