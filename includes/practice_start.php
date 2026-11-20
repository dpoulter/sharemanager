<?php

	/**
	 * Start a strategy on practice, in two steps.
	 *
	 *   step 1  creates the strategy's practice account, SWITCHED OFF, with the
	 *           capital stated, and shows the plan tonight's run would make
	 *           for it. Nothing is sent and nothing is recorded as an order.
	 *   step 2  - a second request - switches it on.
	 *
	 * Never both in one request: the plan is shown between them so that what
	 * is being switched on has been looked at. A web POST by a logged in user
	 * and nothing else.
	 *
	 * Step 1 needs the move to be open in strategy_stage(), asked on the
	 * server: the rules have a completed backtest of their current
	 * fingerprint, and the single practice account is not held by another
	 * strategy - if it is, the refusal names the holder. Who holds it is
	 * practice_account_state.php's to say. It also refuses over shares left on
	 * the account by a retired strategy (naming them), and over a capital above
	 * what the account is worth at the broker. Reconciliation
	 * covers the whole practice account, so for now it is one strategy per
	 * account.
	 *
	 * Nothing here touches the live host or a live account, sends an order or
	 * records one. Writes strategy_accounts.
	 *
	 * THE PLAN OF A SWITCHED-OFF ACCOUNT. The planner refuses an account that
	 * is switched off, so to show the plan the account is switched on INSIDE
	 * A TRANSACTION that is rolled back: the dry run sees it enabled, no other
	 * connection ever does, and it is switched off again however the
	 * transaction ended.
	 */

	require_once("constants.php");
	require_once(__DIR__ . "/strategy_functions.php");
	require_once(__DIR__ . "/strategy_stage.php");
	require_once(__DIR__ . "/practice_account_state.php");

	/**
	 * $options: user_id, strategy, step (1 or 2), capital (step 1).
	 *
	 * Returns ['ok'=>bool, 'why'=>string, 'step'=>1|2, 'plan'=>[lines]].
	 */
	function practice_start($options = []) {

		$refuse = function ($why) {
			write_log("practice_start", "refused: " . $why);
			return ['ok' => false, 'why' => $why, 'step' => 0, 'plan' => []];
		};

		if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
			return $refuse("starting on practice is done on the strategy's page, in a logged in session.");
		}
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
			return $refuse("starting on practice is a form, so it is a POST and nothing else.");
		}

		$user_id = isset($options['user_id']) ? (int) $options['user_id'] : 0;
		$users = $user_id > 0 ? query("select username from users where id=?", $user_id) : [];
		if (!count($users)) { return $refuse("starting on practice needs somebody logged in."); }

		$strategy = (string) ($options['strategy'] ?? '');
		$stage = strategy_stage($strategy);
		if ($stage === null) { return $refuse("there is no strategy called that."); }
		if ($stage['archived']) { return $refuse("it is archived."); }

		$step = (int) ($options['step'] ?? 0);

		/* ---- step 2: switch it on ---- */
		if ($step === 2) {

			$account = strategy_account($strategy);
			if ($account === null || strtoupper((string) $account['mode']) !== STRATEGY_MODE_T212_DEMO) {
				return $refuse("it has no practice account to switch on; step 1 makes one.");
			}
			if ($account['enabled'] === 'Y') { return $refuse("its practice account is already switched on."); }
			if ($account['halted'] === 'Y') { return $refuse("its practice account is halted; clear that first."); }

			$holder = practice_account_holder($strategy);
			if ($holder !== null) {
				return $refuse("the practice account is in use by " . strategy_display_name($holder) . ".");
			}

			query("update strategy_accounts set enabled='Y' where strategy=? and mode=?",
			      $strategy, STRATEGY_MODE_T212_DEMO);
			write_log("practice_start", $strategy . " switched on by " . $users[0]['username']);
			return ['ok' => true, 'why' => '', 'step' => 2, 'plan' => []];
		}

		/* ---- step 1: make the account, switched off, and show the plan ---- */
		if ($step !== 1) { return $refuse("step 1 or step 2."); }

		if (!$stage['moves']['start_practice']['open']) {
			return $refuse("it cannot be started on practice now: " . $stage['moves']['start_practice']['reason'] . ".");
		}

		$raw = trim((string) ($options['capital'] ?? ''));
		$capital = is_numeric($raw) ? (float) $raw : 0.0;
		if (!($capital > 0) || $capital > 10000000) {
			return $refuse("the capital has to be more than nothing, and at most 10,000,000.");
		}

		if (strategy_account($strategy) !== null) { return $refuse("it already has an account."); }

		/* What is on the practice account now, from the broker's own figures.
		   Another strategy's shares left behind would be this one's the moment
		   it started, and a capital above what the account is worth is a
		   capital that cannot be invested. If the broker cannot be read, that
		   is the answer: nothing is guessed. */
		$state = practice_account_state();
		if (!$state['readable']) {
			return $refuse("the practice account cannot be read, so what is on it is not known: " . $state['why'] . ".");
		}
		if (count($state['leftover'])) {
			$names = [];
			foreach ($state['leftover'] as $share) {
				$names[] = ($share['symbol'] === null ? $share['ticker'] : $share['symbol']) . " "
				         . rtrim(rtrim(number_format($share['quantity'], 6, '.', ''), '0'), '.');
			}
			return $refuse("the practice account still holds shares that belong to no running strategy ("
			             . implode(', ', $names) . "). Use Clear the practice account first.");
		}
		if ($state['value'] === null) {
			return $refuse("the practice account's value is not known, so the capital cannot be checked.");
		}
		if ($capital > $state['value'] + 0.005) {
			return $refuse("the capital (" . number_format($capital, 2) . ") is more than the practice account is worth ("
			             . number_format($state['value'], 2) . ").");
		}

		query("insert into strategy_accounts (strategy, cash, currency, mode, enabled, created_at, note)
		       values (?, ?, 'GBP', ?, 'N', now(), ?)",
		      $strategy, round($capital, 2), STRATEGY_MODE_T212_DEMO,
		      "Practice account started from the strategy's page. Switched off until confirmed.");

		/* The plan, from the dry run, with the account enabled only inside a
		   transaction that is rolled back. */
		$plan = [];
		$as_of = last_trading_date('XLON');
		if ($as_of === null) { $as_of = date('Y-m-d'); }
		$_SESSION['exchange'] = 'XLON';

		query("start transaction");
		query("update strategy_accounts set enabled='Y' where strategy=?", $strategy);
		$report = strategy_dry_run($strategy, $as_of, 'XLON');
		query("rollback");
		/* Whatever the storage engine did with that, the account is off. */
		query("update strategy_accounts set enabled='N' where strategy=?", $strategy);

		foreach ((array) ($report['trades'] ?? []) as $trade) {
			$plan[] = strtoupper((string) $trade['side']) . " " . $trade['symbol'] . " "
			        . rtrim(rtrim(number_format((float) $trade['quantity'], 4, '.', ''), '0'), '.');
		}
		if (!count($plan)) {
			$plan[] = "nothing to trade tonight"
			        . (count($report['errors'] ?? []) ? " (" . implode('; ', array_slice($report['errors'], 0, 2)) . ")" : "")
			        . (isset($report['next_rebalance']) && $report['next_rebalance'] !== null
			           ? ", next rebalance " . $report['next_rebalance'] : "");
		}

		write_log("practice_start", $strategy . " practice account made, switched off, capital "
		          . round($capital, 2) . ", by " . $users[0]['username']);

		return ['ok' => true, 'why' => '', 'step' => 1, 'plan' => $plan];
	}

/* No closing tag: see the note at the end of constants.php. */
