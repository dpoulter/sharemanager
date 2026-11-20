<?php

	/**
	 * Clear the practice account: sell the shares a retired practice strategy
	 * left at the Trading 212 practice account.
	 *
	 * Archive and Stop practice never sell, so the single practice account
	 * keeps a retired strategy's shares, and the next strategy started on it
	 * inherits them. This sells them, in two requests:
	 *
	 *   step 1  lists what would be sold, and to which strategy each share is
	 *           tied. Nothing is sent and nothing is recorded as an order.
	 *   step 2  - a second request, which must be of the list step one showed -
	 *           records the sells against the strategy that left the shares
	 *           and sends them through the practice sender.
	 *
	 * A web POST by a logged in user and nothing else.
	 *
	 * REFUSED, with the reason, when: any strategy is switched on at the
	 * practice account; the broker's figures cannot be read, or were not read
	 * today; there is nothing to sell; or a share is tied to no strategy (or
	 * to one that recorded less of it than the broker holds). That share is
	 * named, and nothing is sold. A running strategy's shares are never on the
	 * list: practice_account_state() leaves them out.
	 *
	 * ONE DOOR. Orders go out only through the practice sender,
	 * broker_send_orders(), on the practice client. This file posts nothing
	 * itself and never touches the live host. broker_send_orders() does not send
	 * for a switched-off account; it does for this, narrowly: only while the
	 * flag below is set by this file's own execute step, and only for the
	 * orders recorded here (ids beginning "clear:").
	 *
	 * Never: sells a running strategy's share, sends in the request that
	 * plans, switches an account on or off, archives, or clears a halt.
	 */

	require_once("constants.php");
	require_once(__DIR__ . "/strategy_functions.php");
	require_once(__DIR__ . "/practice_account_state.php");
	require_once(__DIR__ . "/broker_orders.php");

	/**
	 * Whether this file is in the middle of its own sending step. Read by the
	 * practice sender; set by practice_clear_execute() around its calls and
	 * nowhere else, so an option passed from elsewhere does nothing.
	 */
	function practice_clear_sending($set = null) {
		static $on = false;
		if ($set !== null) { $on = (bool) $set; }
		return $on;
	}

	/**
	 * What would be sold, and whether it may be. Reads only.
	 *
	 * Returns ['ok'=>bool, 'why'=>string, 'shares'=>[...], 'state'=>the state].
	 * Each share is symbol, quantity, tied, exchange, value.
	 */
	function practice_clear_plan() {

		$state = practice_account_state();
		$no = function ($why) use ($state) { return ['ok' => false, 'why' => $why, 'shares' => [], 'state' => $state]; };

		if ($state['holder'] !== null) {
			return $no(strategy_display_name($state['holder']) . " is switched on at the practice account,"
			         . " and its shares are its own. Stop it first");
		}
		if (!$state['readable']) {
			return $no("the practice account cannot be read, so what is left on it is not known: " . $state['why']);
		}
		if (!$state['fresh']) {
			return $no("the practice account was last read on " . $state['as_of'] . ", not today, so"
			         . " what is left on it may have changed");
		}
		if (!count($state['leftover'])) { return $no("there is nothing left on the practice account"); }

		$untied = [];
		foreach ($state['leftover'] as $share) {
			if ($share['symbol'] === null) {
				$untied[] = $share['ticker'] . " (not one of ours)";
			}
			else if ($share['tied'] === null) {
				$untied[] = $share['symbol'] . " (no strategy recorded it)";
			}
			else if ($share['tied_quantity'] + 0.00000001 < $share['quantity']) {
				$untied[] = $share['symbol'] . " (" . rtrim(rtrim(number_format($share['quantity'] - $share['tied_quantity'], 6, '.', ''), '0'), '.')
				          . " more at the broker than " . strategy_display_name($share['tied']) . " recorded)";
			}
		}
		if (count($untied)) {
			return $no("these shares are tied to no strategy, so nothing was sold: " . implode('; ', $untied));
		}

		return ['ok' => true, 'why' => '', 'shares' => $state['leftover'], 'state' => $state];
	}

	/** A short fingerprint of a list, so step two can only be of what step one showed. */
	function practice_clear_hash(array $shares) {
		$rows = [];
		foreach ($shares as $s) { $rows[] = [$s['symbol'], $s['tied'], round($s['quantity'], 6)]; }
		return sha1(json_encode($rows));
	}

	/** The words for a list. */
	function practice_clear_words(array $shares) {
		$out = [];
		foreach ($shares as $s) {
			$out[] = $s['symbol'] . " " . rtrim(rtrim(number_format($s['quantity'], 6, '.', ''), '0'), '.')
			       . " (" . strategy_display_name($s['tied']) . ")";
		}
		return implode('; ', $out);
	}

	/**
	 * Record the sells and send them. Called by practice_clear() after step
	 * one has been shown, and by nothing else. $options: client, ignore_time
	 * (both for the suite).
	 *
	 * Returns ['ok'=>bool, 'why'=>string, 'sent'=>n, 'rejected'=>n, 'unknown'=>n, 'lines'=>[], 'errors'=>[]].
	 */
	function practice_clear_execute(array $shares, $options = []) {

		$out = ['ok' => false, 'why' => '', 'sent' => 0, 'rejected' => 0, 'unknown' => 0, 'lines' => [], 'errors' => []];

		/* The list is re-checked against the broker's figures as they are now:
		   what was shown is what is sold, or nothing is. */
		$plan = practice_clear_plan();
		if (!$plan['ok']) { $out['why'] = $plan['why']; return $out; }
		if (practice_clear_hash($plan['shares']) !== practice_clear_hash($shares)) {
			$out['why'] = "what is left on the practice account changed since it was shown. Show it again";
			return $out;
		}

		$client = isset($options['client']) ? $options['client'] : new T212Client(['env' => 'demo']);
		if ($client->environment() !== 'demo' || !$client->configured()) {
			$out['why'] = "the practice connection is not configured here, so nothing was recorded or sent"
			            . ($client->environment() !== 'demo' ? "" : ": " . $client->why_not());
			return $out;
		}

		/* What an earlier clear sent and has not been resolved is looked at
		   first, so the same shares are not sold twice. */
		$tied = [];
		foreach ($shares as $s) { $tied[$s['tied']] = true; }
		foreach (array_keys($tied) as $strategy) {
			$open = query("select count(*) n from strategy_orders where strategy=? and client_order_id like 'clear:%'
			                and status in ('SENT','UNKNOWN')", $strategy);
			if (count($open) && (int) $open[0]['n'] > 0) {
				broker_resolve_orders($strategy, ['client' => $client]);
				$open = query("select count(*) n from strategy_orders where strategy=? and client_order_id like 'clear:%'
				                and status in ('SENT','UNKNOWN')", $strategy);
				if (count($open) && (int) $open[0]['n'] > 0) {
					$out['why'] = strategy_display_name($strategy) . " has clearing orders still sent and not"
					            . " resolved. Wait for them, then show the list again";
					return $out;
				}
				$plan = practice_clear_plan();
				if (!$plan['ok'] || practice_clear_hash($plan['shares']) !== practice_clear_hash($shares)) {
					$out['why'] = "what is left on the practice account changed. Show it again";
					return $out;
				}
			}
		}

		$day = date('Y-m-d');
		foreach ($shares as $s) {
			$existing = query("select count(*) n from strategy_orders where strategy=? and symbol=?
			                    and client_order_id like 'clear:%' and status='PENDING'", $s['tied'], $s['symbol']);
			if (count($existing) && (int) $existing[0]['n'] > 0) { continue; }

			query("insert into strategy_orders
			         (client_order_id, strategy, as_of_date, symbol, exchange, side, quantity, status, note, created_at)
			       values (?, ?, ?, ?, ?, 'SELL', ?, 'PENDING', 'practice_clear', ?)",
			      'clear:' . substr($s['tied'], 0, 20) . ':' . $s['symbol'] . ':' . date('YmdHis') . bin2hex(random_bytes(3)),
			      $s['tied'], $day, $s['symbol'], $s['exchange'], round($s['quantity'], 8), date('Y-m-d H:i:s'));
		}

		/* Through the practice sender, and only while the flag is up. */
		practice_clear_sending(true);
		try {
			foreach (array_keys($tied) as $strategy) {
				$sent = broker_send_orders($strategy, ['client' => $client, 'practice_clear' => true,
				                                       'ignore_time' => !empty($options['ignore_time'])]);
				$out['sent'] += $sent['sent'];
				$out['rejected'] += $sent['rejected'];
				$out['unknown'] += $sent['unknown'];
				foreach ($sent['lines'] as $line) { $out['lines'][] = $line; }
				foreach ($sent['errors'] as $error) { $out['errors'][] = $error; }
			}
		}
		finally { practice_clear_sending(false); }

		write_log("practice_clear", "sent=" . $out['sent'] . " rejected=" . $out['rejected']
		          . " unknown=" . $out['unknown'] . " shares=" . count($shares));

		$out['ok'] = true;
		return $out;
	}

	/**
	 * $options: user_id, step (1 or 2).
	 *
	 * Returns ['ok'=>bool, 'why'=>string, 'step'=>1|2, 'words'=>what the page says].
	 */
	function practice_clear($options = []) {

		$refuse = function ($why) {
			write_log("practice_clear", "refused: " . $why);
			return ['ok' => false, 'why' => $why, 'step' => 0, 'words' => ''];
		};

		if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
			return $refuse("clearing the practice account is done on the strategies page, in a logged in session.");
		}
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
			return $refuse("clearing the practice account is a form, so it is a POST and nothing else.");
		}

		$user_id = isset($options['user_id']) ? (int) $options['user_id'] : 0;
		$users = $user_id > 0 ? query("select username from users where id=?", $user_id) : [];
		if (!count($users)) { return $refuse("clearing the practice account needs somebody logged in."); }

		$step = (int) ($options['step'] ?? 0);
		if ($step !== 1 && $step !== 2) { return $refuse("step 1 or step 2."); }

		$plan = practice_clear_plan();
		if (!$plan['ok']) { return $refuse($plan['why'] . "."); }

		if ($step === 1) {
			$_SESSION['sm_practice_clear'] = ['hash' => practice_clear_hash($plan['shares']), 'at' => time()];
			return ['ok' => true, 'why' => '', 'step' => 1,
			        'words' => "Would sell, at market, through the practice account: "
			                 . practice_clear_words($plan['shares'])
			                 . ". Nothing was sent or recorded. Confirm to send."];
		}

		/* Step two has to be of the list step one showed, within a quarter of
		   an hour. A crafted POST that never saw step one has no token. */
		$token = isset($_SESSION['sm_practice_clear']) ? $_SESSION['sm_practice_clear'] : null;
		unset($_SESSION['sm_practice_clear']);
		if (!is_array($token) || $token['hash'] !== practice_clear_hash($plan['shares'])
		    || time() - (int) $token['at'] > 900) {
			return $refuse("sending needs the list shown first, in a request of its own, and unchanged:"
			             . " show what would be sold, then confirm");
		}

		$run = practice_clear_execute($plan['shares']);
		if (!$run['ok']) { return $refuse($run['why'] . "."); }

		write_log("practice_clear", "confirmed by " . $users[0]['username']);

		$words = "Sent " . $run['sent'] . " sell order(s) through the practice account"
		       . ($run['rejected'] ? ", " . $run['rejected'] . " refused" : "")
		       . ($run['unknown'] ? ", " . $run['unknown'] . " not answered (checked by the next read)" : "") . ".";
		if (count($run['errors'])) { $words .= " Not sent: " . implode('; ', array_slice($run['errors'], 0, 2)) . "."; }

		$after = practice_account_state();
		$words .= $after['readable']
		        ? " Still left at the last read (" . $after['as_of'] . "): "
		          . (count($after['leftover']) ? practice_clear_words_state($after['leftover']) : "nothing")
		          . ". Account value " . ($after['value'] === null ? "unknown" : number_format($after['value'], 2)) . "."
		        : " The practice account cannot be read now: " . $after['why'] . ".";

		return ['ok' => true, 'why' => '', 'step' => 2, 'words' => $words];
	}

	/** The words for the state's own list, which may name no strategy. */
	function practice_clear_words_state(array $leftover) {
		$out = [];
		foreach ($leftover as $s) {
			$out[] = ($s['symbol'] === null ? $s['ticker'] : $s['symbol']) . " "
			       . rtrim(rtrim(number_format($s['quantity'], 6, '.', ''), '0'), '.');
		}
		return implode('; ', $out);
	}

/* No closing tag: see the note at the end of constants.php. */
