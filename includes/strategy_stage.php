<?php

	/**
	 * Which stage a strategy is at, and which moves are open.
	 *
	 *   Draft        the rules exist and no completed backtest has the
	 *                fingerprint of the current rules;
	 *   Backtested   a completed run has the fingerprint of the current
	 *                rules, and there is no practice or live account;
	 *   Practice     the strategy has a practice account (or a live account
	 *                that is not armed: stopping live steps down to here);
	 *   Live         the strategy has an armed live account, and only while
	 *                it is armed.
	 *
	 * DERIVED, NEVER STORED. Every call works the stage out from what exists:
	 * the rules, the runs, the accounts and the arming. A stored stage would
	 * be a second opinion that could disagree with them, and it is exactly
	 * the kind of thing that goes wrong on the one night it matters.
	 *
	 * Reads only, and safe to call from a page. It changes nothing. It is
	 * also not a check the browser can stand in for: every handler of a move
	 * (backtest, start practice, step down) asks here on the server, so a
	 * crafted POST for a closed move fails there. The page only shows the
	 * reason.
	 *
	 * Whether the rules have changed since a run lives only in
	 * rules_fingerprint.php. Whether practice evidence exists lives only in
	 * practice_evidence.php, which this asks; it does not look at the
	 * practice orders itself. live_arming.php does NOT call this file - it
	 * asks practice_evidence directly - because this one reads the arming and
	 * the two would otherwise call each other.
	 */

	require_once("constants.php");
	require_once(__DIR__ . "/strategy_functions.php");
	require_once(__DIR__ . "/rules_fingerprint.php");
	require_once(__DIR__ . "/screen_links.php");
	require_once(__DIR__ . "/broker_live.php");

	/** The four stages, in order. */
	function strategy_stages() { return ['Draft', 'Backtested', 'Practice', 'Live']; }

	/** The moves, in the order the page lists them. */
	function strategy_stage_moves() {
		return ['backtest' => 'Backtest', 'start_practice' => 'Start on practice',
		        'go_live' => 'Go live', 'stop_practice' => 'Stop practice',
		        'stop_live' => 'Stop live', 'archive' => 'Archive'];
	}

	/** Whether sql/strategy_workflow.sql has been applied. */
	function strategy_stage_ready() {

		static $known = null;
		if ($known !== null) { return $known; }

		$rows = query("select count(*) n from information_schema.columns
		                where table_schema=database()
		                  and ((table_name='strategy_rules' and column_name='archived_at')
		                    or (table_name='strategy_backtest_runs'
		                        and column_name in ('rules_snapshot','fingerprint')))");
		$known = count($rows) && (int) $rows[0]['n'] === 3;
		return $known;
	}

	/** Whether a strategy has been archived. False where that is not known. */
	function strategy_archived($strategy) {

		if (!strategy_stage_ready()) { return false; }

		$rows = query("select archived_at from strategy_rules where strategy=?", (string) $strategy);
		return count($rows) && $rows[0]['archived_at'] !== null;
	}

	/**
	 * The strategy that holds the single practice account, other than this
	 * one, or null. One strategy per practice account for now: the account
	 * is single and reconciliation covers the whole of it.
	 */
	function strategy_practice_holder($except = null) {

		foreach (query("select strategy from strategy_accounts where mode=? and enabled='Y'
		                 order by strategy", STRATEGY_MODE_T212_DEMO) as $row) {
			if (strategy_reserved($row['strategy'])) { continue; }
			if ($except !== null && $row['strategy'] === (string) $except) { continue; }
			return (string) $row['strategy'];
		}
		return null;
	}

	/**
	 * The stage and the moves for one strategy; null if it has no rules.
	 *
	 * Returns strategy, stage, index (0 to 3), archived, fingerprint,
	 * backtest (the completed run that matches, or null), has_account, mode,
	 * armed, moves (name => ['open' => bool, 'reason' => string]).
	 */
	function strategy_stage($strategy) {

		$strategy = (string) $strategy;
		if (strategy_reserved($strategy) || !strategy_rules_ready()) { return null; }
		if (strategy_rules($strategy) === null) { return null; }

		$archived = strategy_archived($strategy);
		$account  = strategy_account($strategy);
		$mode     = $account === null ? null : strtoupper(trim((string) $account['mode']));
		$enabled  = $account !== null && $account['enabled'] === 'Y';

		$practice = ($mode === STRATEGY_MODE_T212_DEMO);
		$is_live  = ($mode === STRATEGY_MODE_T212_LIVE);
		$armed    = $is_live && function_exists('broker_live_armed') && broker_live_armed($strategy);

		$fingerprint = rules_fingerprint($strategy);

		/* A completed run that tested THESE rules. Runs that predate the
		   fingerprint carry none and are not known to match. */
		$run = null;
		if (strategy_stage_ready() && $fingerprint !== null && strategy_backtest_ready()) {
			$rows = query("select * from strategy_backtest_runs
			                where strategy=? and status='DONE' and fingerprint=?
			                order by finished_at desc, run_id desc limit 1",
			              $strategy, $fingerprint);
			$run = count($rows) ? $rows[0] : null;
		}

		if ($armed)                    { $stage = 'Live'; }
		else if ($practice || $is_live) { $stage = 'Practice'; }
		else if ($run !== null)         { $stage = 'Backtested'; }
		else                            { $stage = 'Draft'; }

		$moves = [];
		$open  = function ($name, $ok, $reason = '') use (&$moves) {
			$moves[$name] = ['open' => (bool) $ok, 'reason' => $ok ? '' : (string) $reason];
		};

		/* Backtest. */
		if ($archived) { $open('backtest', false, "it is archived"); }
		else if (!function_exists('backtest_run_active')) {
			$open('backtest', false, "backtests are not queued from the page yet");
		}
		else {
			$active = backtest_run_active($strategy);
			$open('backtest', $active === null,
			      "a backtest of it is already " . strtolower((string) $active));
		}

		/* Start on practice: at Backtested, with the practice account free. */
		if ($archived)                    { $open('start_practice', false, "it is archived"); }
		else if ($stage === 'Live')       { $open('start_practice', false, "it is already live"); }
		else if ($stage === 'Practice')   { $open('start_practice', false, "it is already on practice"); }
		else if ($stage === 'Draft')      {
			$open('start_practice', false, "it needs a completed backtest of its current rules first");
		}
		else {
			$holder = strategy_practice_holder($strategy);
			$open('start_practice', $holder === null,
			      "the practice account is in use by " . ($holder === null ? '' : strategy_display_name($holder)));
		}

		/* Go live: at Practice, and only if practice_evidence says yes. */
		if ($archived)                  { $open('go_live', false, "it is archived"); }
		else if ($stage === 'Live')     { $open('go_live', false, "it is already live"); }
		else if ($stage !== 'Practice') { $open('go_live', false, "it has to run on practice first"); }
		else if (!function_exists('practice_evidence')) {
			$open('go_live', false, "the practice check is not installed yet");
		}
		else {
			$evidence = practice_evidence($strategy);
			$open('go_live', !empty($evidence['ok']), (string) ($evidence['why'] ?? ''));
		}

		/* The way back down. */
		$open('stop_practice', $practice && $enabled,
		      $practice ? "its practice account is already switched off" : "it has no practice account");
		$open('stop_live', $armed, "it is not armed");

		if ($archived)             { $open('archive', false, "it is already archived"); }
		else if ($enabled)         { $open('archive', false, "its account is switched on"); }
		else if ($armed)           { $open('archive', false, "its live account is armed"); }
		else                       { $open('archive', true); }

		return ['strategy' => $strategy, 'stage' => $stage,
		        'index' => array_search($stage, strategy_stages(), true),
		        'archived' => $archived, 'fingerprint' => $fingerprint, 'backtest' => $run,
		        'has_account' => $account !== null, 'mode' => $mode, 'armed' => $armed,
		        'account_enabled' => $enabled,
		        'moves' => $moves];
	}

	/**
	 * Every strategy that has rules - with an account or without, archived or
	 * not - with its stage, name and screen, for the page's list. The
	 * backtest accounts are not strategies and are left out.
	 */
	function strategy_stage_list() {

		if (!strategy_rules_ready()) { return []; }

		$out = [];
		foreach (query("select strategy from strategy_rules order by strategy") as $row) {
			$stage = strategy_stage($row['strategy']);
			if ($stage === null) { continue; }
			$stage['name']   = strategy_display_name($row['strategy']);
			$stage['screen'] = screen_of_strategy($row['strategy']);
			$out[$row['strategy']] = $stage;
		}
		return $out;
	}

/* No closing tag: see the note at the end of constants.php. */
