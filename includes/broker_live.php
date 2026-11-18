<?php

	/**
	 * Live orders: what has to be true before one can exist.
	 *
	 * Dale decided on 18 November 2026 to run the trend following strategy
	 * with the GBP 250 in his Trading 212 Invest account. The sentence this
	 * application used to be able to make - no real order can ever be placed
	 * - is replaced by a narrower one, and everything in this file is what
	 * makes the narrower one true:
	 *
	 *   NO REAL ORDER UNLESS DALE HAS ARMED THAT ACCOUNT.
	 *
	 * Six conditions, all of them, checked by broker_live_conditions() and
	 * called from inside the order function rather than by whatever asked it
	 * to send - a guard in a caller is a guard a second caller does not have:
	 *
	 *   1. the account's mode is T212_LIVE;
	 *   2. SM_T212_LIVE_ORDERS=on and the orders key is present;
	 *   3. the account is armed: a live row in strategy_live_arming;
	 *   4. the order belongs to an approved batch, unless approval has been
	 *      switched off for that account;
	 *   5. every cap holds - capital, per order, per run, per day, orders
	 *      per day;
	 *   6. the kill switch is off and the strategy is not halted.
	 *
	 * ARMING is done by a logged in person on the strategy's own page, with
	 * the app password typed again and the strategy's display name typed in
	 * full. broker_live_arm() is the only function in this application that
	 * writes an armed row, it refuses to run anywhere but in a POST to a web
	 * request, and the suite checks both: no other file inserts into
	 * strategy_live_arming, and the same call from the command line is
	 * refused. Nothing Jarvis can run arms an account.
	 *
	 * DISARMING is one click for anybody logged in, and happens by itself
	 * when the row expires or the strategy halts. Off is the safe direction,
	 * so it is the easy one.
	 *
	 * This file sends nothing. It is deliberately the whole of the gate and
	 * none of the door: the live order function is stage C, and until it
	 * exists an armed, approved, within-caps live account still has its
	 * orders planned and left waiting, because broker_send_orders() refuses
	 * any mode but the practice one.
	 */

	require_once("constants.php");
	/* The engine's own functions - strategy_account(), the mode rules, the
	   display name. One direction: strategy_functions.php does not require
	   this file, so the nightly job can plan a paper account without the
	   broker layer, and reaches what is here through function_exists()
	   where it needs it. */
	require_once(__DIR__ . "/strategy_functions.php");

	/* ---------------------------------------------------------------------
	   Printing, without the view layer.

	   The nightly run and the sending job do not load includes/view.php -
	   they have no pages to draw - and this file is called from both of
	   them and from the pages. So the two shapes it needs are here, in the
	   same spellings view.php uses, rather than a require that makes a cron
	   job depend on the way a table looks.
	   --------------------------------------------------------------------- */

	/** Money in pounds, for a sentence rather than a column. */
	function broker_live_money($pounds, $dp = 2) {
		if ($pounds === null || $pounds === '') { return '-'; }
		return "\xc2\xa3" . number_format((float) $pounds, $dp);
	}

	/** A date a person reads: 20 Nov 2026. */
	function broker_live_day($when) {
		if ($when === null || $when === '') { return 'an unknown date'; }
		$stamp = strtotime((string) $when);
		return $stamp === false ? (string) $when : date('j M Y', $stamp);
	}

	/** A date and a time: 20 Nov 2026 at 14:32. */
	function broker_live_moment($when) {
		if ($when === null || $when === '') { return 'an unknown time'; }
		$stamp = strtotime((string) $when);
		return $stamp === false ? (string) $when : date('j M Y \a\t H:i', $stamp);
	}

	/**
	 * Text on its way into a log, with anything that looks like a key
	 * masked.
	 *
	 * t212_mask() lives with the client, which this file does not require:
	 * nothing here talks to the broker. When the client is loaded - it is,
	 * wherever an order is being sent - its masking is used; when it is
	 * not, there is nothing from the broker in the line to mask.
	 */
	function broker_live_safe($text) {
		$text = (string) $text;
		return function_exists('t212_mask') ? t212_mask($text) : $text;
	}

	/** Whether sql/strategy_live.sql has been applied. */
	function broker_live_ready() {

		static $known = null;
		if ($known !== null) { return $known; }

		$rows = query("select count(*) n from information_schema.tables
		                where table_schema=database()
		                  and table_name in ('strategy_live_arming',
		                                     'strategy_order_batches',
		                                     'strategy_live_log')");
		$tables = count($rows) && (int) $rows[0]['n'] === 3;

		$column = query("select count(*) n from information_schema.columns
		                  where table_schema=database() and table_name='strategy_orders'
		                    and column_name='batch'");

		$known = $tables && count($column) && (int) $column[0]['n'] === 1;
		return $known;
	}

	/**
	 * Add a line to the live log.
	 *
	 * Insert only. There is no function here that deletes or updates a row
	 * in strategy_live_log, and there is not meant to be: it is the record
	 * of who allowed real money to move, and a record somebody can tidy up
	 * is a record of what they wanted to have happened.
	 */
	function broker_live_log($strategy, $event, $who = null, $detail = null) {

		if (!broker_live_ready()) { return false; }

		$written = query("insert into strategy_live_log (at, strategy, event, who, detail)
		                  values (?,?,?,?,?)",
		                 date('Y-m-d H:i:s'), (string) $strategy, strtoupper((string) $event),
		                 $who === null ? null : substr((string) $who, 0, 50),
		                 $detail === null ? null : substr(broker_live_safe($detail), 0, 500));

		/* Also in the ordinary log, so a day's events are readable in one
		   place - and so that a failure to write the row above is visible
		   rather than silent. */
		write_log("broker_live", strtoupper((string) $event) . " " . $strategy
		          . ($who === null ? "" : " by " . $who)
		          . ($detail === null ? "" : ": " . broker_live_safe($detail))
		          . ($written === false ? " (THE LOG ROW COULD NOT BE WRITTEN)" : ""));

		return $written !== false;
	}

	/* ---------------------------------------------------------------------
	   Arming.
	   --------------------------------------------------------------------- */

	/**
	 * The row that says this strategy may trade, or null.
	 *
	 * Live means: not disarmed, and not expired. An account that nobody has
	 * ever armed and an account whose arming ran out last week are the same
	 * answer here, which is the answer that matters - the page says which,
	 * from broker_live_last_arming().
	 */
	function broker_live_arming($strategy) {

		if (!broker_live_ready()) { return null; }

		$rows = query("select * from strategy_live_arming
		                where strategy=? and disarmed_at is null and expires_at > ?
		                order by armed_at desc, id desc limit 1",
		              (string) $strategy, date('Y-m-d H:i:s'));

		return count($rows) ? $rows[0] : null;
	}

	/** The last arming of this strategy whatever became of it, or null. */
	function broker_live_last_arming($strategy) {

		if (!broker_live_ready()) { return null; }

		$rows = query("select * from strategy_live_arming where strategy=?
		                order by armed_at desc, id desc limit 1", (string) $strategy);
		return count($rows) ? $rows[0] : null;
	}

	/** Whether this strategy is armed. */
	function broker_live_armed($strategy) {
		return broker_live_arming($strategy) !== null;
	}

	/** The capital cap arming set, or null when the account is not armed. */
	function broker_live_capital_cap($strategy) {
		$arming = broker_live_arming($strategy);
		return $arming === null ? null : (float) $arming['capital_cap'];
	}

	/**
	 * Whether this strategy could be armed at all, leaving aside who is
	 * asking and what they typed.
	 *
	 * The second rule here is the one worth reading: two live strategies in
	 * one Trading 212 account would both reconcile against the same
	 * positions, and a share they both held would look like a shortfall to
	 * each of them. Until that is designed properly, the second one cannot
	 * be armed.
	 */
	function broker_live_can_arm($strategy) {

		if (!broker_live_ready()) {
			return ['ok' => false, 'why' => "sql/strategy_live.sql has not been applied"];
		}

		$account = strategy_account($strategy);
		if ($account === null) {
			return ['ok' => false, 'why' => "there is no account called " . $strategy];
		}
		if (!strategy_mode_is_live($account['mode'])) {
			return ['ok' => false,
			        'why' => strategy_display_name($strategy) . " is a "
			               . strategy_mode_rules($account['mode'])['words'] . " account,"
			               . " and only a live account can be armed"];
		}
		if ($account['enabled'] !== 'Y') {
			return ['ok' => false, 'why' => "the account is switched off"];
		}
		if ($account['halted'] === 'Y') {
			return ['ok' => false, 'why' => "the account is halted: "
			                              . (string) $account['halt_reason']];
		}

		foreach (query("select strategy from strategy_accounts where mode=? and strategy<>?",
		               STRATEGY_MODE_T212_LIVE, (string) $strategy) as $row) {
			if (broker_live_armed((string) $row['strategy'])) {
				return ['ok' => false,
				        'why' => strategy_display_name((string) $row['strategy'])
				               . " is already armed on the same Trading 212 account."
				               . " Two live strategies there would both reconcile against"
				               . " the same positions, so the second one is refused until"
				               . " that is designed properly. Disarm the first one to swap."];
			}
		}

		return ['ok' => true, 'why' => ""];
	}

	/**
	 * Arm a live account. The only function here that writes an armed row.
	 *
	 * $options:
	 *   user_id      the logged in user ($_SESSION['id'])
	 *   password     the app password, typed again
	 *   typed_name   the strategy's display name, typed in full
	 *   capital_cap  what it may have invested, in pounds
	 *   days         how long for; T212_LIVE_ARM_DAYS when absent
	 *   reason       free text for the log
	 *
	 * Refused outside a POST to a web request. That is not belt and braces
	 * for the password check: it is the difference between a decision a
	 * person made in their own logged in session and a line in a script.
	 * Jarvis deploys and tests; arming is Dale's.
	 */
	function broker_live_arm($strategy, $options = []) {

		$strategy = (string) $strategy;

		$refuse = function ($why) use ($strategy) {
			broker_live_log($strategy, 'REFUSED', null, "arming refused: " . $why);
			return ['ok' => false, 'why' => $why];
		};

		/* Where this is running, before anything else. php_sapi_name() is
		   'cli' for the command line and for cron; a web request is
		   anything else and has a request method with it. */
		if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
			return $refuse("arming is done by a person on the strategy's page, in their own"
			             . " logged in session. The command line cannot arm an account.");
		}
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
			return $refuse("arming is a form on the strategy's page, so it is a POST and"
			             . " nothing else.");
		}

		$can = broker_live_can_arm($strategy);
		if (!$can['ok']) { return $refuse($can['why']); }

		/* Who. The session's user, read by the caller and handed over, so
		   that this function is testable without a session - and so that
		   there is exactly one place that decides what a valid user is. */
		$user_id = isset($options['user_id']) ? (int) $options['user_id'] : 0;
		$users = $user_id > 0
		       ? query("select id, username, hash from users where id=?", $user_id) : [];
		if (!count($users)) {
			return $refuse("arming needs somebody logged in.");
		}
		$user = $users[0];

		$password = (string) ($options['password'] ?? '');
		if ($password === '' || !verify_password($password, (string) $user['hash'])) {
			return $refuse("the password was wrong, so nothing was armed.");
		}

		/* And the name, typed in full. A password is something a browser
		   fills in; the name of the strategy about to trade real money is
		   something a person has to mean. */
		$wanted = strategy_display_name($strategy);
		$typed  = trim((string) ($options['typed_name'] ?? ''));
		if ($typed === '' || strcasecmp($typed, $wanted) !== 0) {
			return $refuse("the strategy's name has to be typed in full. This one is \""
			             . $wanted . "\".");
		}

		$cap = (float) ($options['capital_cap'] ?? 0);
		if (!($cap > 0)) {
			return $refuse("arming states what this strategy may have invested, in pounds.");
		}
		if ($cap > (float) T212_LIVE_CAPITAL_CAP_MAX + 0.0001) {
			return $refuse("the most a live strategy may be armed with is "
			             . number_format((float) T212_LIVE_CAPITAL_CAP_MAX, 2)
			             . ", which is a constant in this application rather than a setting.");
		}

		$days = (int) ($options['days'] ?? T212_LIVE_ARM_DAYS);
		if ($days < 1) { $days = (int) T212_LIVE_ARM_DAYS; }
		if ($days > (int) T212_LIVE_ARM_DAYS_MAX) { $days = (int) T212_LIVE_ARM_DAYS_MAX; }

		$now     = date('Y-m-d H:i:s');
		$expires = date('Y-m-d H:i:s', strtotime($now . ' +' . $days . ' days'));

		/* Re-arming is the same form, so the row that is there is stood
		   down first: one live row per strategy, always. */
		query("update strategy_live_arming set disarmed_at=?, disarmed_by=?,
		              reason=concat(coalesce(reason,''),' (re-armed)')
		        where strategy=? and disarmed_at is null",
		      $now, (string) $user['username'], $strategy);

		$written = query("insert into strategy_live_arming
		                  (strategy, armed_by, armed_user_id, armed_at, capital_cap,
		                   expires_at, reason)
		                  values (?,?,?,?,?,?,?)",
		                 $strategy, (string) $user['username'], (int) $user['id'], $now,
		                 round($cap, 2), $expires,
		                 isset($options['reason']) ? substr((string) $options['reason'], 0, 255) : null);

		if ($written === false) {
			return $refuse("the arming row could not be written, so the account is not armed.");
		}

		broker_live_log($strategy, 'ARM', (string) $user['username'],
		                "armed with a cap of " . number_format($cap, 2)
		              . " until " . $expires);

		return ['ok' => true, 'why' => "",
		        'words' => broker_live_words($strategy),
		        'expires_at' => $expires, 'capital_cap' => round($cap, 2)];
	}

	/**
	 * Stand a live account down.
	 *
	 * One click, no password: disarmed is the safe direction. Called by the
	 * page, and by broker_halt_strategy() - a strategy that has stopped for
	 * a person to look at must not still be armed when they do.
	 */
	function broker_live_disarm($strategy, $who = null, $reason = null) {

		if (!broker_live_ready()) { return ['ok' => false, 'disarmed' => 0]; }

		$live = query("select id from strategy_live_arming
		                where strategy=? and disarmed_at is null", (string) $strategy);
		if (!count($live)) { return ['ok' => true, 'disarmed' => 0]; }

		query("update strategy_live_arming
		          set disarmed_at=?, disarmed_by=?, reason=?
		        where strategy=? and disarmed_at is null",
		      date('Y-m-d H:i:s'),
		      $who === null ? null : substr((string) $who, 0, 50),
		      $reason === null ? null : substr((string) $reason, 0, 255),
		      (string) $strategy);

		broker_live_log($strategy, 'DISARM', $who,
		                $reason === null ? "disarmed" : (string) $reason);

		return ['ok' => true, 'disarmed' => count($live)];
	}

	/**
	 * What the pages say about a live account, in one sentence.
	 *
	 * "Live. Armed by dale on 20 Nov 2026 until 25 Dec 2026, cap GBP 250",
	 * or "Live. Not armed: orders are planned and not sent". The second one
	 * is the normal state and reads like it.
	 */
	function broker_live_words($strategy) {

		$account = strategy_account($strategy);
		if ($account === null || !strategy_mode_is_live($account['mode'])) { return ''; }

		$arming = broker_live_arming($strategy);

		if ($arming === null) {
			$last = broker_live_last_arming($strategy);
			$tail = "";
			if ($last !== null) {
				$tail = ($last['disarmed_at'] !== null)
				      ? " Disarmed on " . broker_live_day($last['disarmed_at'])
				        . (($last['disarmed_by'] === null) ? "" : " by " . $last['disarmed_by']) . "."
				      : " Arming ran out on " . broker_live_day($last['expires_at']) . ".";
			}
			return "Live. Not armed: orders are planned and not sent." . $tail;
		}

		return "Live. Armed by " . $arming['armed_by']
		     . " on " . broker_live_day($arming['armed_at'])
		     . " until " . broker_live_day($arming['expires_at'])
		     . ", cap " . broker_live_money((float) $arming['capital_cap'], 0) . ".";
	}

	/* ---------------------------------------------------------------------
	   Approval.
	   --------------------------------------------------------------------- */

	/** Whether this account's batches wait for an approval. Default yes. */
	function broker_live_approval_required($strategy) {

		if (!broker_live_ready()) { return true; }

		$rows = query("select approval_required from strategy_accounts where strategy=?",
		              (string) $strategy);
		if (!count($rows)) { return true; }

		return strtoupper(trim((string) $rows[0]['approval_required'])) !== 'N';
	}

	/**
	 * Switch approval off, or back on, for one account.
	 *
	 * Off means "send what you plan without asking me", which is a decision
	 * about real money, so it needs the password like arming does - and it
	 * is recorded with who and when.
	 */
	function broker_live_set_approval($strategy, $required, $options = []) {

		$strategy = (string) $strategy;
		$required = (bool) $required;

		if (!broker_live_ready()) {
			return ['ok' => false, 'why' => "sql/strategy_live.sql has not been applied"];
		}

		$user_id = isset($options['user_id']) ? (int) $options['user_id'] : 0;
		$users = $user_id > 0
		       ? query("select id, username, hash from users where id=?", $user_id) : [];
		if (!count($users)) { return ['ok' => false, 'why' => "nobody is logged in."]; }
		$user = $users[0];

		$password = (string) ($options['password'] ?? '');
		if ($password === '' || !verify_password($password, (string) $user['hash'])) {
			broker_live_log($strategy, 'REFUSED', (string) $user['username'],
			                "approval switch refused: the password was wrong");
			return ['ok' => false, 'why' => "the password was wrong, so nothing changed."];
		}

		query("update strategy_accounts set approval_required=?, approval_set_by=?,
		              approval_set_at=? where strategy=?",
		      $required ? 'Y' : 'N', (string) $user['username'], date('Y-m-d H:i:s'), $strategy);

		broker_live_log($strategy, $required ? 'APPROVAL_ON' : 'APPROVAL_OFF',
		                (string) $user['username'],
		                $required ? "every batch waits for an approval"
		                          : "approved batches are no longer asked for");

		return ['ok' => true, 'why' => ""];
	}

	/** The batch a night's orders belong to: one per strategy per night. */
	function broker_live_batch_id($strategy, $as_of) {
		return substr((string) $strategy, 0, 50) . ':' . substr((string) $as_of, 0, 10);
	}

	/**
	 * Open tonight's batch, or hand back the one already open.
	 *
	 * Called as a live account's orders are recorded. Every order of one
	 * night is in one batch, the exits with the buys: an exit rule's sale
	 * waits for the same approval as everything else, because a batch that
	 * could be half approved is a portfolio nobody chose.
	 */
	function broker_live_batch_open($strategy, $as_of) {

		if (!broker_live_ready()) { return null; }

		$batch = broker_live_batch_id($strategy, $as_of);

		$rows = query("select batch from strategy_order_batches where batch=?", $batch);
		if (count($rows)) { return $batch; }

		$written = query("insert into strategy_order_batches
		                  (batch, strategy, as_of_date, status, created_at)
		                  values (?,?,?,'UNAPPROVED',?)",
		                 $batch, (string) $strategy, substr((string) $as_of, 0, 10),
		                 date('Y-m-d H:i:s'));

		return $written === false ? null : $batch;
	}

	/** One batch row, with what is in it, or null. */
	function broker_live_batch($strategy, $as_of = null) {

		if (!broker_live_ready()) { return null; }

		$rows = ($as_of === null)
		      ? query("select * from strategy_order_batches where strategy=?
		                order by as_of_date desc, created_at desc limit 1", (string) $strategy)
		      : query("select * from strategy_order_batches where batch=?",
		              broker_live_batch_id($strategy, $as_of));

		if (!count($rows)) { return null; }

		$batch = $rows[0];
		$batch['orders'] = query("select * from strategy_orders where batch=?
		                           order by case when side='SELL' then 0 else 1 end, symbol",
		                         (string) $batch['batch']);
		$batch['waiting'] = (strtoupper((string) $batch['status']) === 'UNAPPROVED');

		/* Whether anything in it is an exit. A late exit costs money, so the
		   page says so in red rather than leaving it in the list. */
		$batch['exits'] = [];
		foreach ($batch['orders'] as $order) {
			if ((string) $order['side'] === 'SELL') { $batch['exits'][] = (string) $order['symbol']; }
		}

		/* WHAT APPROVING IT WOULD COST.
		 *
		 * An approval is a decision about money, so the figures are on the
		 * same screen as the button: every order at the last close, the
		 * stamp duty a buy pays, and the totals. Estimates, and said to be:
		 * these are market orders and the price they get is the price the
		 * market gives them.
		 *
		 * The mode's own cost model, through strategy_costs(), so the
		 * commission is the live account's (none) rather than the paper
		 * account's GBP 5.95.
		 */
		$account = strategy_account((string) $batch['strategy']);
		$mode = ($account === null) ? STRATEGY_MODE_T212_LIVE : $account['mode'];

		$batch['totals'] = ['buys' => 0.0, 'sells' => 0.0, 'stamp_duty' => 0.0,
		                    'net' => 0.0, 'priced' => 0, 'unpriced' => 0];

		foreach ($batch['orders'] as $i => $order) {

			$price = strategy_price_asof((string) $order['symbol'], (string) $order['exchange'],
			                             (string) $order['as_of_date']);

			$batch['orders'][$i]['price'] = $price;
			$batch['orders'][$i]['value'] = null;
			$batch['orders'][$i]['stamp_duty'] = null;

			if ($price === null) { $batch['totals']['unpriced']++; continue; }

			$costs = strategy_costs((string) $order['side'], (float) $order['quantity'],
			                        (float) $price, $mode);

			$batch['orders'][$i]['value'] = $costs['consideration'];
			$batch['orders'][$i]['stamp_duty'] = $costs['stamp_duty'];

			$batch['totals']['priced']++;
			$batch['totals']['stamp_duty'] += $costs['stamp_duty'];
			$batch['totals']['net'] += $costs['total_cost'];
			if ((string) $order['side'] === 'BUY') { $batch['totals']['buys'] += $costs['consideration']; }
			else                                   { $batch['totals']['sells'] += $costs['consideration']; }
		}

		return $batch;
	}

	/** Whether an order may be sent as far as approval is concerned. */
	function broker_live_order_approved($order) {

		if (!broker_live_ready()) { return false; }

		if (!is_array($order)) {
			$rows = query("select * from strategy_orders where client_order_id=?", (string) $order);
			if (!count($rows)) { return false; }
			$order = $rows[0];
		}

		/* The account may have been told to send without asking. Then the
		   batch is beside the point - but it is still recorded, so what was
		   sent on a night nobody approved anything is still a list. */
		if (!broker_live_approval_required((string) $order['strategy'])) { return true; }

		$batch = isset($order['batch']) ? (string) $order['batch'] : '';
		if ($batch === '') { return false; }

		$rows = query("select status from strategy_order_batches where batch=?", $batch);
		return count($rows) && strtoupper((string) $rows[0]['status']) === 'APPROVED';
	}

	/** Approve one batch: the orders in it may be sent in this session. */
	function broker_live_approve($strategy, $batch, $options = []) {
		return broker_live_decide($strategy, $batch, 'APPROVED', $options);
	}

	/** Reject one batch: nothing in it is ever sent. */
	function broker_live_reject($strategy, $batch, $options = []) {
		return broker_live_decide($strategy, $batch, 'REJECTED', $options);
	}

	/**
	 * Approve or reject, in one place.
	 *
	 * Approving needs the password: it is the moment real money is allowed
	 * to move. Rejecting does not, for the same reason disarming does not -
	 * the safe direction is the easy one - but it does take a reason.
	 */
	function broker_live_decide($strategy, $batch, $status, $options = []) {

		$strategy = (string) $strategy;
		$batch    = (string) $batch;
		$status   = strtoupper((string) $status);

		if (!broker_live_ready()) {
			return ['ok' => false, 'why' => "sql/strategy_live.sql has not been applied"];
		}

		$rows = query("select * from strategy_order_batches where batch=? and strategy=?",
		              $batch, $strategy);
		if (!count($rows)) {
			return ['ok' => false, 'why' => "there is no batch " . $batch . " for " . $strategy];
		}
		$row = $rows[0];

		if (strtoupper((string) $row['status']) !== 'UNAPPROVED') {
			return ['ok' => false,
			        'why' => "that batch was already " . strtolower((string) $row['status'])
			               . " on " . broker_live_moment($row['decided_at']) . "."];
		}

		$user_id = isset($options['user_id']) ? (int) $options['user_id'] : 0;
		$users = $user_id > 0
		       ? query("select id, username, hash from users where id=?", $user_id) : [];
		if (!count($users)) { return ['ok' => false, 'why' => "nobody is logged in."]; }
		$user = $users[0];

		if ($status === 'APPROVED') {

			$password = (string) ($options['password'] ?? '');
			if ($password === '' || !verify_password($password, (string) $user['hash'])) {
				broker_live_log($strategy, 'REFUSED', (string) $user['username'],
				                "approval refused for " . $batch . ": the password was wrong");
				return ['ok' => false, 'why' => "the password was wrong, so nothing was approved."];
			}

			/* An approval is worth nothing if the account is not armed: the
			   batch would sit approved until it expired. Say so now rather
			   than at the close. */
			if (!broker_live_armed($strategy)) {
				return ['ok' => false,
				        'why' => strategy_display_name($strategy) . " is not armed, so an"
				               . " approved batch would still not be sent. Arm it first."];
			}
		}

		query("update strategy_order_batches set status=?, decided_at=?, decided_by=?, reason=?
		        where batch=?",
		      $status, date('Y-m-d H:i:s'), (string) $user['username'],
		      isset($options['reason']) ? substr((string) $options['reason'], 0, 255) : null,
		      $batch);

		/* A rejected batch is cancelled, not left PENDING: an order nobody
		   approved must not be picked up by the next run as though it were
		   waiting for the market to open. */
		if ($status === 'REJECTED') {
			query("update strategy_orders set status='CANCELLED',
			              note=concat('rejected: ',coalesce(?,'no reason given'))
			        where batch=? and status='PENDING'",
			      isset($options['reason']) ? (string) $options['reason'] : null, $batch);
		}

		$count = query("select count(*) n from strategy_orders where batch=?", $batch);
		$count = count($count) ? (int) $count[0]['n'] : 0;

		broker_live_log($strategy, $status === 'APPROVED' ? 'APPROVE' : 'REJECT',
		                (string) $user['username'],
		                $count . " order(s) in " . $batch
		              . (isset($options['reason']) ? ": " . (string) $options['reason'] : ""));

		return ['ok' => true, 'why' => "", 'orders' => $count];
	}

	/**
	 * Expire the batches whose session has gone.
	 *
	 * A batch planned on the night of Thursday is sent during Friday's
	 * session. At Friday's close it is finished, whatever became of it:
	 *
	 *   UNAPPROVED - nobody decided, so nothing was sent. Recorded as
	 *                expired rather than left to be picked up on Monday at
	 *                Monday's prices.
	 *   APPROVED   - approved and, for whatever reason, not sent. The
	 *                approval was for that session. Carrying it over would
	 *                mean orders going out two days after somebody looked
	 *                at them, which is not what they looked at.
	 *
	 * Either way the orders still PENDING are cancelled and tonight plans
	 * afresh - exits are re-checked every night, so a sale that mattered
	 * yesterday is planned again today at today's prices.
	 *
	 * Run from the nightly job, before anything is planned, with
	 * $options['as_of'] as the night's date: a batch is stale when its own
	 * date is older than the night being run. Not run from the sending job,
	 * which works inside exactly the session a batch is good for.
	 */
	function broker_live_expire_batches($options = []) {

		$out = ['expired' => 0, 'cancelled' => 0, 'lines' => []];
		if (!broker_live_ready()) { return $out; }

		$today = isset($options['as_of'])
		       ? substr((string) $options['as_of'], 0, 10) : date('Y-m-d');

		foreach (query("select * from strategy_order_batches
		                 where status in ('UNAPPROVED','APPROVED') and as_of_date < ?
		                 order by as_of_date", $today) as $row) {

			$batch = (string) $row['batch'];
			$was   = strtoupper((string) $row['status']);
			$why   = ($was === 'APPROVED')
			       ? 'approved for that session and not sent in it'
			       : 'nobody approved it before the session ended';

			query("update strategy_order_batches set status='EXPIRED', decided_at=?, reason=?
			        where batch=?", date('Y-m-d H:i:s'), $why, $batch);

			$orders = query("select client_order_id from strategy_orders
			                  where batch=? and status='PENDING'", $batch);

			query("update strategy_orders set status='CANCELLED',
			              note=concat('the batch expired: ', ?)
			        where batch=? and status='PENDING'", $why, $batch);

			$out['expired']++;
			$out['cancelled'] += count($orders);
			$out['lines'][] = $batch . ": expired (" . $why . "), " . count($orders)
			                . " order(s) cancelled";

			broker_live_log((string) $row['strategy'], 'EXPIRE', null,
			                $batch . " expired: " . $why . "; " . count($orders)
			              . " order(s) cancelled");
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	   The caps.
	   --------------------------------------------------------------------- */

	/**
	 * The figures this strategy is held to, from the cap it was armed with.
	 *
	 * Null when it is not armed, because there is no cap to be inside: an
	 * unarmed account is not "allowed a little", it is not allowed.
	 */
	function broker_live_caps($strategy) {

		$cap = broker_live_capital_cap($strategy);
		if ($cap === null) { return null; }

		return [
			'capital'        => $cap,
			'per_order'      => round($cap * (float) T212_LIVE_PER_ORDER_FRACTION, 2),
			'per_run'        => (float) T212_LIVE_MAX_RUN_VALUE,
			'per_day'        => $cap,
			'orders_per_day' => (int) T212_LIVE_ORDERS_PER_DAY,
		];
	}

	/**
	 * What this strategy has invested plus reserved, at cost.
	 *
	 * At cost rather than at market, because the cap is on what was
	 * committed: a holding that has doubled has not spent anything more.
	 * Orders that have gone and not come back are counted as spent - they
	 * may be filling as this is read.
	 */
	function broker_live_committed($strategy) {

		$invested = 0.0;
		foreach (query("select quantity, avg_price from strategy_positions
		                 where strategy=? and quantity>0", (string) $strategy) as $row) {
			$invested += (float) $row['quantity'] * (float) $row['avg_price'];
		}

		$reserved = 0.0;
		foreach (query("select symbol, exchange, side, quantity, as_of_date
		                 from strategy_orders
		                where strategy=? and side='BUY' and status in ('SENT','UNKNOWN')",
		               (string) $strategy) as $row) {
			$price = strategy_price_asof((string) $row['symbol'], (string) $row['exchange'],
			                             (string) $row['as_of_date']);
			if ($price !== null) { $reserved += (float) $row['quantity'] * (float) $price; }
		}

		return ['invested' => $invested, 'reserved' => $reserved,
		        'committed' => $invested + $reserved];
	}

	/** What this strategy has bought today, in pounds, and how many orders. */
	function broker_live_today($strategy, $day = null) {

		$day = ($day === null) ? date('Y-m-d') : substr((string) $day, 0, 10);

		$bought = 0.0;
		$orders = 0;

		foreach (query("select symbol, exchange, side, quantity, as_of_date, consideration
		                 from strategy_orders
		                where strategy=? and status in ('SENT','UNKNOWN','FILLED')
		                  and date(coalesce(sent_at, filled_at, created_at))=?",
		               (string) $strategy, $day) as $row) {

			$orders++;
			if ((string) $row['side'] !== 'BUY') { continue; }

			if ($row['consideration'] !== null) { $bought += abs((float) $row['consideration']); continue; }

			$price = strategy_price_asof((string) $row['symbol'], (string) $row['exchange'],
			                             (string) $row['as_of_date']);
			if ($price !== null) { $bought += (float) $row['quantity'] * (float) $price; }
		}

		return ['bought' => $bought, 'orders' => $orders, 'day' => $day];
	}

	/**
	 * Whether one order is inside every cap.
	 *
	 * $order is a strategy_orders row or anything with symbol, exchange,
	 * side and quantity. $options:
	 *   value       the order's value in pounds, when the caller has it
	 *   run_value   what this run has sent already
	 *   day         the day to count against, for the suite
	 *
	 * A sale is never capped. The caps are there to limit what can be put
	 * at risk, and selling is the direction that reduces it: refusing a sale
	 * because the day's allowance has gone would leave a position the
	 * strategy has decided to be out of.
	 */
	function broker_live_cap_check($strategy, $order, $options = []) {

		$strategy = (string) $strategy;
		$caps = broker_live_caps($strategy);

		if ($caps === null) {
			return ['ok' => false, 'why' => strategy_display_name($strategy)
			                              . " is not armed, so there is no cap to be inside."];
		}

		$side = strtoupper((string) ($order['side'] ?? 'BUY'));

		$value = isset($options['value']) ? (float) $options['value'] : null;
		if ($value === null) {
			$price = strategy_price_asof((string) $order['symbol'],
			                             (string) ($order['exchange'] ?? ''),
			                             (string) ($order['as_of_date'] ?? date('Y-m-d')));
			$value = ($price === null) ? 0.0 : (float) $order['quantity'] * (float) $price;
		}
		$value = abs($value);

		$today = broker_live_today($strategy, $options['day'] ?? null);

		/* Orders per day counts sells as well as buys: it is a cap on how
		   much this application may do in a day, not on how much it may
		   spend, and thirty is already a whole rebalance twice over. */
		if (($today['orders'] + 1) > $caps['orders_per_day']) {
			return ['ok' => false, 'key' => 'orders_per_day',
			        'why' => $today['orders'] . " live order(s) have already gone today, which"
			               . " is the limit of " . $caps['orders_per_day'] . "."];
		}

		if ($side === 'SELL') { return ['ok' => true, 'why' => '', 'value' => $value]; }

		if ($value > $caps['per_order'] + 0.0001) {
			return ['ok' => false, 'key' => 'per_order',
			        'why' => "that order is " . broker_live_money($value, 2) . ", over the "
			               . broker_live_money($caps['per_order'], 2) . " one order may be ("
			               . round((float) T212_LIVE_PER_ORDER_FRACTION * 100)
			               . "% of the " . broker_live_money($caps['capital'], 0) . " cap)."];
		}

		$run = isset($options['run_value']) ? (float) $options['run_value'] : 0.0;
		if (($run + $value) > $caps['per_run'] + 0.0001) {
			return ['ok' => false, 'key' => 'per_run',
			        'why' => "this run would have sent " . broker_live_money($run + $value, 2)
			               . ", over the " . broker_live_money($caps['per_run'], 2)
			               . " a live run may send."];
		}

		if (($today['bought'] + $value) > $caps['per_day'] + 0.0001) {
			return ['ok' => false, 'key' => 'per_day',
			        'why' => "buys today would come to " . broker_live_money($today['bought'] + $value, 2)
			               . ", over the " . broker_live_money($caps['per_day'], 2)
			               . " a day, which is the capital cap."];
		}

		$committed = broker_live_committed($strategy);
		if (($committed['committed'] + $value) > $caps['capital'] + 0.0001) {
			return ['ok' => false, 'key' => 'capital',
			        'why' => "this strategy has " . broker_live_money($committed['committed'], 2)
			               . " invested or reserved and that order would take it to "
			               . broker_live_money($committed['committed'] + $value, 2) . ", over its "
			               . broker_live_money($caps['capital'], 2) . " cap."
			               . " Cash at the broker does not raise it."];
		}

		return ['ok' => true, 'why' => '', 'value' => $value];
	}

	/**
	 * The figures a live account is PLANNED against.
	 *
	 * The caps above are checked when an order is about to be sent, which
	 * is the only place they can be enforced. But planning fifteen orders
	 * and then refusing eleven of them would leave a book nobody chose, so
	 * the plan is sized inside the same limits: the strategy's cash ledger
	 * or the capital cap, whichever is smaller.
	 *
	 * An account nobody has armed is planned against the constant ceiling
	 * instead of against its arming. It is not allowed to send anything, so
	 * the figure is not a permission - it is what the orders waiting for an
	 * arming should look like, rather than a plan that could never be sent
	 * as it stands.
	 */
	function broker_live_plan_limits($strategy) {

		$armed = broker_live_capital_cap($strategy);
		$capital = ($armed === null) ? (float) T212_LIVE_CAPITAL_CAP_MAX : $armed;

		return ['armed' => $armed !== null, 'capital' => $capital,
		        'per_order' => round($capital * (float) T212_LIVE_PER_ORDER_FRACTION, 2)];
	}

	/* ---------------------------------------------------------------------
	   Only its own positions.
	   --------------------------------------------------------------------- */

	/**
	 * Whether this strategy may sell this much of this share.
	 *
	 * Dale's live account holds shares he bought himself. A live strategy
	 * sells what it bought and nothing else: never more of a share than it
	 * holds, and never a share it does not hold at all. The broker's
	 * quantity is not the question - it includes his.
	 */
	function broker_live_sale_allowed($strategy, $symbol, $quantity) {

		$symbol   = (string) $symbol;
		$quantity = (float) $quantity;

		$positions = strategy_positions((string) $strategy);
		$held = isset($positions[$symbol]) ? (float) $positions[$symbol]['quantity'] : 0.0;

		if ($held <= 0) {
			return ['ok' => false,
			        'why' => $symbol . " is not one of this strategy's holdings, so it is not"
			               . " this strategy's to sell. Anything the account holds in it was"
			               . " bought outside this application."];
		}

		if ($quantity > $held + 0.00000001) {
			return ['ok' => false,
			        'why' => "this strategy holds " . rtrim(rtrim(number_format($held, 8, '.', ''), '0'), '.')
			               . " of " . $symbol . " and the order is for "
			               . rtrim(rtrim(number_format($quantity, 8, '.', ''), '0'), '.')
			               . ". It never sells more of a share than it bought."];
		}

		return ['ok' => true, 'why' => ''];
	}

	/* ---------------------------------------------------------------------
	   The six conditions.
	   --------------------------------------------------------------------- */

	/**
	 * Everything that has to hold before a live order can be sent.
	 *
	 * Called from inside the order function, as the first thing it does,
	 * rather than by whoever asked it to send: a condition checked in a
	 * caller is a condition the next caller does not have. The page calls it
	 * too, to say what is missing - with $options['explain'] so that it
	 * reports rather than logs.
	 *
	 * $options:
	 *   order      the order about to be sent, for conditions 4 and 5
	 *   run_value  what this run has sent already
	 *   explain    true to leave nothing in the log
	 *   day        the day to count the caps against, for the suite
	 */
	function broker_live_conditions($strategy, $options = []) {

		$strategy = (string) $strategy;
		$checks = [];
		$add = function ($key, $ok, $words) use (&$checks) {
			$checks[] = ['key' => $key, 'ok' => (bool) $ok, 'words' => $words];
		};

		if (!broker_live_ready()) {
			$add('tables', false, "sql/strategy_live.sql has not been applied");
			return broker_live_verdict($strategy, $checks, $options);
		}

		/* 1. The mode. */
		$account = strategy_account($strategy);
		$is_live = ($account !== null) && strategy_mode_is_live($account['mode']);
		$add('mode', $is_live,
		     $is_live ? "the account is a live one"
		              : ($account === null ? "there is no account called " . $strategy
		                                   : strategy_display_name($strategy) . " is a "
		                                     . strategy_mode_rules($account['mode'])['words']
		                                     . " account, so this is not its path"));

		/* 2. The switch and the key. Off means the live order function does
		   not exist as far as any caller can tell. */
		$switch = (defined('T212_LIVE_ORDERS_ON') && T212_LIVE_ORDERS_ON)
		       && (defined('T212_LIVE_ORDERS_API_KEY') && T212_LIVE_ORDERS_API_KEY !== '');
		$add('switch', $switch,
		     $switch ? "SM_T212_LIVE_ORDERS is on and the orders key is present"
		             : "SM_T212_LIVE_ORDERS is off, or its own key is not set");

		/* 3. Armed. */
		$armed = broker_live_armed($strategy);
		$add('armed', $armed,
		     $armed ? broker_live_words($strategy)
		            : "the account is not armed, so its orders are planned and not sent");

		/* 4. Approved, unless this account has been told to send without
		   asking. */
		$order = isset($options['order']) ? $options['order'] : null;
		if ($order === null) {
			$batch = broker_live_batch($strategy);
			$approved = !broker_live_approval_required($strategy)
			         || ($batch !== null && strtoupper((string) $batch['status']) === 'APPROVED');
		}
		else {
			$approved = broker_live_order_approved($order);
		}
		$add('approved', $approved,
		     $approved
		       ? (broker_live_approval_required($strategy)
		            ? "the batch has been approved"
		            : "this account sends without asking, by Dale's own setting")
		       : "the batch has not been approved");

		/* 5. The caps. Without an order in hand, the question is whether
		   there is any room at all. */
		if ($order === null) {
			$caps = broker_live_caps($strategy);
			if ($caps === null) {
				$add('caps', false, "there is no cap, because the account is not armed");
			}
			else {
				$committed = broker_live_committed($strategy);
				$room = $caps['capital'] - $committed['committed'];
				$add('caps', $room > 0,
				     $room > 0
				       ? "there is " . broker_live_money($room, 2) . " of the "
				         . broker_live_money($caps['capital'], 0) . " cap left"
				       : "the capital cap of " . broker_live_money($caps['capital'], 0)
				         . " is fully committed");
			}
		}
		else {
			$cap = broker_live_cap_check($strategy, $order, $options);
			$add('caps', $cap['ok'], $cap['ok'] ? "every cap holds" : $cap['why']);

			/* And a sale is this strategy's own to make. Part of condition
			   5 rather than a seventh: it is a limit on the order, and an
			   order that fails it is refused the same way. */
			if (strtoupper((string) ($order['side'] ?? 'BUY')) === 'SELL') {
				$own = broker_live_sale_allowed($strategy, (string) $order['symbol'],
				                                (float) $order['quantity']);
				$add('own', $own['ok'], $own['ok'] ? "the strategy holds what it is selling"
				                                   : $own['why']);
			}
		}

		/* 6. The kill switch, and the strategy. */
		$switch_off = !strategy_halted();
		$add('kill_switch', $switch_off,
		     $switch_off ? "the kill switch is off"
		                 : "the kill switch file exists at " . STRATEGY_KILL_SWITCH_FILE);

		$running = ($account !== null) && $account['halted'] !== 'Y' && $account['enabled'] === 'Y';
		$add('running', $running,
		     $running ? "the strategy is running"
		              : ($account === null ? "there is no account"
		                 : ($account['halted'] === 'Y'
		                      ? "the strategy is halted: " . (string) $account['halt_reason']
		                      : "the strategy is switched off")));

		return broker_live_verdict($strategy, $checks, $options);
	}

	/** The answer broker_live_conditions() hands back, and its log line. */
	function broker_live_verdict($strategy, array $checks, array $options = []) {

		$failed = [];
		foreach ($checks as $check) { if (!$check['ok']) { $failed[] = $check['words']; } }

		$out = ['ok' => count($failed) === 0, 'checks' => $checks, 'failed' => $failed,
		        'why' => count($failed) ? implode('; ', $failed) : ''];

		/* A refusal is recorded, because "why did nothing go out last night"
		   is a question somebody asks the next morning. A page asking what
		   the state is writes nothing. */
		if (!$out['ok'] && empty($options['explain'])) {
			broker_live_log($strategy, 'REFUSED', null, $out['why']);
		}

		return $out;
	}

	/**
	 * Everything the pages need about a live account, in one array.
	 *
	 * Null for an account that is not live, so a template can ask without
	 * knowing: the practice and paper cards show nothing of this.
	 */
	function broker_live_state($strategy) {

		$account = strategy_account($strategy);
		if ($account === null || !strategy_mode_is_live($account['mode'])) { return null; }

		$arming = broker_live_arming($strategy);
		$batch  = broker_live_batch($strategy);

		return [
			'strategy'   => (string) $strategy,
			'ready'      => broker_live_ready(),
			'armed'      => $arming !== null,
			'arming'     => $arming,
			'last'       => broker_live_last_arming($strategy),
			'words'      => broker_live_words($strategy),
			'caps'       => broker_live_caps($strategy),
			'committed'  => broker_live_committed($strategy),
			'today'      => broker_live_today($strategy),
			'approval'   => broker_live_approval_required($strategy),
			'batch'      => $batch,
			'waiting'    => ($batch !== null && $batch['waiting']
			                 && broker_live_approval_required($strategy)),
			'conditions' => broker_live_conditions($strategy, ['explain' => true]),
			'name'       => strategy_display_name($strategy),
			'cap_max'    => (float) T212_LIVE_CAPITAL_CAP_MAX,
			'arm_days'   => (int) T212_LIVE_ARM_DAYS,
		];
	}

	/**
	 * Every live account with a batch nobody has decided about.
	 *
	 * Home asks this, so that "live orders waiting for your approval" is on
	 * the page somebody opens first rather than only on the one they would
	 * have to go looking at.
	 */
	function broker_live_waiting() {

		$out = [];
		if (!broker_live_ready()) { return $out; }

		foreach (query("select strategy from strategy_accounts where mode=? and enabled='Y'
		                 order by strategy", STRATEGY_MODE_T212_LIVE) as $row) {

			$strategy = (string) $row['strategy'];
			if (!broker_live_approval_required($strategy)) { continue; }

			$batch = broker_live_batch($strategy);
			if ($batch === null || !$batch['waiting'] || !count($batch['orders'])) { continue; }

			$out[] = ['strategy' => $strategy, 'name' => strategy_display_name($strategy),
			          'batch' => (string) $batch['batch'],
			          'orders' => count($batch['orders']),
			          'exits' => $batch['exits'],
			          'as_of' => (string) $batch['as_of_date'],
			          'armed' => broker_live_armed($strategy)];
		}

		return $out;
	}

/* No closing tag: see the note at the end of constants.php. */
