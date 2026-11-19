<?php

	/**
	 * Stage 2: the engine's orders, sent to Trading 212's DEMO account.
	 *
	 * DEMO ONLY, and that is not a setting. The order call refuses unless
	 * SM_T212_ENV=demo, builds its URL from a constant demo host rather than
	 * from the base URL the reads use, and refuses any host whose name
	 * contains the live one. The live key has no Orders - Execute permission,
	 * so the other end would refuse it too. Real money stays off until Dale
	 * decides, after at least three months of paper results.
	 *
	 * What happens when, and why:
	 *
	 *   THE NIGHTLY RUN plans. A T212_DEMO account's orders are recorded
	 *   PENDING and nothing is sent, because a market order queued overnight
	 *   fills at whatever the open does - which is not the price anything was
	 *   decided at.
	 *
	 *   A DAYTIME RUN sends them, while London is open
	 *   (includes/send_broker_orders.php, from cron). Each order is marked
	 *   SENT, with the time, BEFORE the call. The order calls are not
	 *   idempotent: the same request sent twice can create two orders, so a
	 *   request whose answer never arrived must never be sent again blind.
	 *
	 *   A LATER RUN resolves them. An order that is still pending at the
	 *   broker is left alone; one that has filled is written up from the
	 *   broker's own figures; one that is nowhere, after long enough, never
	 *   arrived. An UNKNOWN order - the call timed out, or answered 500 - is
	 *   matched against the broker's pending orders and history before
	 *   anything is decided about it, and if two could be it, the strategy
	 *   halts for a person.
	 *
	 *   RECONCILIATION compares the engine's positions with the broker's. Any
	 *   difference halts that strategy: a disagreement about what is held is
	 *   a disagreement about what everything is worth, and trading through
	 *   one compounds it.
	 *
	 * Nothing here throws. A strategy that cannot be sent tonight is a
	 * strategy that does nothing tonight.
	 */

	require_once("constants.php");
	/* By path, not by name. "broker.php" on an include path whose first
	   entry is the current directory finds public/broker.php when a page
	   in the docroot requires this file - and that page requires
	   config.php, which defines every constant a second time. The
	   strategies page was the first to pull this file in from the
	   docroot, and it died on "Cannot redeclare apologize()". */
	require_once(__DIR__ . "/broker.php");
	require_once("strategy_functions.php");
	/* Arming, approval, the caps and the live log. Required here, not only
	   by the pages: broker_halt_strategy() below disarms a live account,
	   and a halt that left one armed would be a halt that only stopped the
	   half of the application somebody was looking at. */
	require_once(__DIR__ . "/broker_live.php");

	/** Whether sql/broker_trading.sql has been applied. */
	function broker_trading_ready() {

		static $known = null;
		if ($known !== null) { return $known; }

		$rows = query("select count(*) n from information_schema.columns
		                where table_schema=database() and table_name='strategy_orders'
		                  and column_name in ('broker','broker_order_id','sent_at')");
		$known = count($rows) && (int) $rows[0]['n'] === 3;
		return $known;
	}

	/**
	 * Whether London is open.
	 *
	 * Weekday and inside the session, in London time - which is not the
	 * server's time for half the year. Holidays are not in the calendar
	 * here: an order sent on Boxing Day sits in the broker's queue until the
	 * market opens, which is the thing this is meant to avoid, so the
	 * sending run also checks that today has a price in historical_prices
	 * before it decides the market is open.
	 */
	function broker_market_open($when = null, $exchange = null) {

		$when = $when === null ? time() : (is_numeric($when) ? (int) $when : strtotime((string) $when));
		if ($when === false) { return false; }

		$london = new DateTime('@' . $when);
		$london->setTimezone(new DateTimeZone(T212_MARKET_TZ));

		$day = (int) $london->format('N');
		if ($day >= 6) { return false; }

		$clock = $london->format('H:i');
		return $clock >= T212_MARKET_OPEN && $clock <= T212_MARKET_SHUT;
	}

	/** The broker's ticker for one of our shares, or null if it has none. */
	function broker_ticker_for($symbol, $broker = BROKER_T212, $environment = 'demo') {

		$rows = query("select broker_ticker from broker_instruments
		                where broker=? and environment=? and symbol=? and status='matched'",
		              $broker, $environment, $symbol);
		return count($rows) ? (string) $rows[0]['broker_ticker'] : null;
	}

	/** Stop a strategy trading, with the reason, until a person clears it. */
	function broker_halt_strategy($strategy, $reason) {

		query("update strategy_accounts set halted='Y', halt_reason=?, halted_at=?
		        where strategy=?",
		      substr((string) $reason, 0, 255), date('Y-m-d H:i:s'), $strategy);

		write_log("broker_orders", "HALTED " . $strategy . ": " . $reason);

		/* A halt disarms. A live strategy stops when something about it
		   needs a person, and arming is the permission to trade without
		   one: leaving it armed through a halt would mean the moment the
		   halt was cleared, real orders could go out again without anybody
		   having said so since. Re-arming is the same form it always was. */
		if (function_exists('broker_live_disarm')) {
			broker_live_disarm($strategy, null, "halted: " . substr((string) $reason, 0, 200));
		}
	}

	/**
	 * Mark an order's outcome.
	 *
	 * Every status change goes through here so that the note always says
	 * why, and so the log carries one line per transition. An order's
	 * history is the only record of what was sent on a day nobody was
	 * watching.
	 */
	function broker_order_status($client_order_id, $status, $note = null, $extra = []) {

		$sets = ["status=?"];
		$args = [$status];

		foreach (['broker_order_id', 'broker_status', 'broker'] as $field) {
			if (array_key_exists($field, $extra)) {
				$sets[] = $field . "=?";
				$args[] = $extra[$field];
			}
		}
		if ($note !== null) { $sets[] = "note=?"; $args[] = substr((string) $note, 0, 255); }

		$args[] = $client_order_id;

		query("update strategy_orders set " . implode(', ', $sets) . " where client_order_id=?",
		      ...$args);

		write_log("broker_orders", $client_order_id . " -> " . $status
		          . ($note === null ? "" : ": " . t212_mask((string) $note)));
	}

	/* ---------------------------------------------------------------------
	   Sending.
	   --------------------------------------------------------------------- */

	/**
	 * Send a strategy's PENDING orders to the demo account.
	 *
	 * $options:
	 *   client      an injected client, for the suite
	 *   ignore_time send outside market hours (the suite only)
	 *   dry_run     say what would be sent and send nothing
	 */
	function broker_send_orders($strategy, $options = []) {

		$out = ['sent' => 0, 'rejected' => 0, 'unknown' => 0, 'skipped' => 0,
		        'lines' => [], 'errors' => [], 'halted' => false, 'value' => 0.0];

		$say = function ($line) use (&$out) { $out['lines'][] = $line; };

		if (!broker_trading_ready()) {
			$out['errors'][] = "sql/broker_trading.sql has not been applied";
			return $out;
		}

		/* Fail closed, before anything is read: the kill switch stops
		   everything, including this. */
		if (strategy_halted()) {
			$out['halted'] = true;
			$out['errors'][] = "halted by the kill switch at " . STRATEGY_KILL_SWITCH_FILE;
			write_log("broker_orders", "halted by kill switch");
			return $out;
		}

		$account = strategy_account($strategy);
		if ($account === null) { $out['errors'][] = "no account for " . $strategy; return $out; }
		if ($account['enabled'] !== 'Y') { $out['errors'][] = "account is disabled"; return $out; }
		if ($account['halted'] === 'Y') {
			$out['halted'] = true;
			$out['errors'][] = "halted: " . (string) $account['halt_reason'];
			return $out;
		}

		$mode = strategy_mode_rules($account['mode']);
		if ($mode['broker'] === null) {
			$out['errors'][] = $strategy . " is a " . $mode['words'] . " account; nothing to send";
			return $out;
		}

		/* This path sends to the PRACTICE account and to nothing else.
		   A live account is planned by the nightly run like any other and
		   its orders wait here: sending them needs the live path, which is
		   a different function with a different key and six conditions in
		   front of it. Checked on the MODE rather than on the client,
		   because a client built from the default environment while the
		   practice switch is on would be a demo client - and sending a
		   live account's orders to the practice account is not a smaller
		   mistake than sending them to the live one. */
		if ($mode['mode'] !== STRATEGY_MODE_T212_DEMO) {
			$out['errors'][] = $strategy . " is a " . $mode['words'] . " account."
			                 . " This sender only ever sends to the practice account,"
			                 . " so its orders are planned and left waiting.";
			return $out;
		}

		$client = isset($options['client']) ? $options['client'] : new T212Client();

		if ($client->environment() !== 'demo') {
			$out['errors'][] = "SM_T212_ENV is '" . $client->environment() . "'. Orders are"
			                 . " only ever sent to the demo account, so nothing was sent.";
			return $out;
		}

		/* The key is checked where it is needed - just before the first
		   order - rather than here, so that a dry run works on a host that
		   has none. Seeing what tonight would do should not require being
		   able to do it. */

		if (empty($options['ignore_time']) && !broker_market_open()) {
			$out['errors'][] = "London is shut. Orders are sent during the session only:"
			                 . " a market order queued overnight fills at whatever the open does.";
			return $out;
		}

		/* Nothing new goes out while something already sent is unaccounted
		   for. The rule is stuck_check()'s, below, and the live sender asks the
		   same function: it is written once. */
		$stuck = stuck_check($strategy);
		if ($stuck['halted']) {
			$out['halted'] = true;
			$out['errors'][] = $stuck['error'];
			return $out;
		}
		if ($stuck['hold']) {
			$out['errors'][] = $stuck['error'];
			return $out;
		}
		if ($stuck['say'] !== '') { $say($stuck['say']); }

		/* And nothing goes out without the two books having been compared,
		   unless there is nothing of ours to compare: a strategy that holds
		   nothing and has never had a fill cannot disagree with anybody. */
		$state = broker_reconciliation_state($strategy, $mode['broker'], $mode['environment']);
		if (!$state['compared']) {
			if (!$state['first_build']) {
				$out['errors'][] = $state['why'] . ", so the two books could not be compared"
				                 . " and nothing was sent.";
				return $out;
			}
			$say($state['why'] . ", and this strategy holds nothing and has never had a"
			     . " fill, so there is nothing to compare: sending the first build");
			write_log("broker_orders", $strategy . ": first build, sending without a"
			          . " reconciliation (" . $state['why'] . ")");
		}
		else {
			/* Compared here rather than trusting the caller to have done it.
			   includes/send_broker_orders.php reconciles before it calls
			   this, and the guarantee should not depend on the order a
			   caller happens to do things in. */
			$check = broker_reconcile_positions($strategy);
			if ($check['halted']) {
				$out['halted'] = true;
				$out['errors'][] = "the broker and this application disagree about what is"
				                 . " held, so nothing was sent: "
				                 . implode('; ', array_slice($check['differences'], 0, 3));
				return $out;
			}
		}

		$orders = query("select * from strategy_orders where strategy=? and status='PENDING'
		                  order by case when side='SELL' then 0 else 1 end, created_at",
		                $strategy);

		if (!count($orders)) { $say("nothing to send"); return $out; }

		/* Now the key matters: there is something to send. */
		if (empty($options['dry_run']) && !$client->configured()) {
			$out['errors'][] = $client->why_not();
			return $out;
		}

		/* The live account's run cap is its own, and lower: GBP 150 against
		   the practice account's GBP 1,000. Picked from the mode rather
		   than from a setting, because the figure is the limit on what one
		   run of real money may do. */
		$cap = $mode['live'] ? (float) T212_LIVE_MAX_RUN_VALUE : (float) T212_MAX_RUN_VALUE;

		foreach ($orders as $order) {

			/* Re-checked per order, so dropping the kill switch stops a run
			   part way through. */
			if (strategy_halted()) {
				$out['halted'] = true;
				$say("HALTED mid-run");
				break;
			}

			$quantity = (float) $order['quantity'];
			$price    = strategy_price_asof($order['symbol'], $order['exchange'], $order['as_of_date']);
			$value    = ($price === null) ? 0.0 : $quantity * (float) $price;

			/* The cap is on the whole run, not on one order: a bug that
			   wants to send four figures from a GBP 250 account is wrong
			   about something, and the right answer is to stop. */
			if (($out['value'] + $value) > $cap) {
				$out['errors'][] = "stopping at the cap: this run would have sent "
				                 . round($out['value'] + $value, 2) . ", over the "
				                 . $cap . " allowed in one run. "
				                 . (count($orders) - $out['sent'] - $out['skipped']
				                    - $out['rejected'])
				                 . " order(s) are left for the next run.";
				break;
			}

			/* A share the broker does not list at all. OCI (Oakley Capital
			   Investments) is one: it is in the FTSE 250, so the universe
			   has it and a screen can pick it, and Trading 212 has no
			   instrument to send an order for.

			   On a dry run this is said and nothing is written. It used to
			   be refused in the database before the dry-run branch below,
			   so asking what tonight would do marked the order REJECTED -
			   a dry run that changes an order is not a dry run. */
			$ticker = broker_ticker_for($order['symbol'], $mode['broker'], $mode['environment']);
			if ($ticker === null) {

				$why = "no instrument at the broker for " . $order['symbol'] . ", so there is"
				     . " nothing to send an order for";

				if (!empty($options['dry_run'])) {
					$say("skip " . $order['symbol'] . ": " . $why);
					$out['skipped']++;
					continue;
				}

				broker_order_status($order['client_order_id'], 'REJECTED', $why);
				$out['rejected']++;
				continue;
			}

			$signed = $order['side'] === 'SELL' ? -$quantity : $quantity;

			$say(sprintf("%-4s %-8s %10s %s (%s)", $order['side'], $order['symbol'],
			             rtrim(rtrim(number_format($quantity, 8, '.', ''), '0'), '.'),
			             $ticker, round($value, 2)));

			if (!empty($options['dry_run'])) {
				/* Counted against the cap as a real send would be, so a dry
				   run shows what ONE run would do rather than listing every
				   pending order as if all of them would go. */
				$out['value'] += $value;
				$out['skipped']++;
				continue;
			}

			/* SENT, with the time, BEFORE the call. If this process dies in
			   the next second the order is on the record as having been
			   sent, and the next run asks the broker what became of it
			   rather than sending it again. */
			query("update strategy_orders set status='SENT', broker=?, sent_at=?, attempts=attempts+1
			        where client_order_id=?",
			      $mode['broker'], date('Y-m-d H:i:s'), $order['client_order_id']);

			$answer = $client->place_market_order($ticker, $signed);
			$out['value'] += $value;

			if ($answer['ok']) {
				$id = null;
				foreach (['id', 'orderId', 'reference'] as $field) {
					if (isset($answer['data'][$field])) { $id = (string) $answer['data'][$field]; break; }
				}
				[$status] = broker_field($answer['data'], ['status', 'state']);

				broker_order_status($order['client_order_id'], 'SENT',
				    "sent to the demo account as " . ($id === null ? '(no id given)' : $id),
				    ['broker_order_id' => $id, 'broker_status' => $status,
				     'broker' => $mode['broker']]);
				$out['sent']++;
				continue;
			}

			$kind = isset($answer['kind']) ? $answer['kind'] : 'refused';

			if ($kind === 'unknown') {
				/* The dangerous one: it may or may not have arrived. Not
				   resent, not written off - resolved against the broker's
				   own records by the next run. */
				broker_order_status($order['client_order_id'], 'UNKNOWN', $answer['message'],
				                    ['broker' => $mode['broker']]);
				$out['unknown']++;
				continue;
			}

			if ($kind === 'rate_limited' || $kind === 'refused' || $kind === 'not_configured') {
				/* Not sent. A rate limit refused it before it reached the
				   book; a refusal is this application's own guard - the
				   environment is not demo, or something has pointed the
				   host somewhere it will not go. Either way the order has
				   not been placed and must stay exactly as it was, and the
				   reason will be the same for every other order in the
				   queue, so the run stops. */
				broker_order_status($order['client_order_id'], 'PENDING', $order['note']);
				$out['errors'][] = $answer['message'];
				break;
			}

			/* A refusal. If the broker is objecting to the quantity, the
			   share does not deal in fractions: remember that, so the next
			   plan sizes it in whole shares rather than being refused
			   again. */
			if (preg_match('~quantity|fraction|decimal|lot size|whole~i', (string) $answer['message'])) {
				query("update broker_instruments set whole_shares='Y'
				        where broker=? and environment=? and symbol=?",
				      $mode['broker'], $mode['environment'], $order['symbol']);
				$say($order['symbol'] . " does not deal in fractions; it will be sized in"
				     . " whole shares from now on");
			}

			broker_order_status($order['client_order_id'], 'REJECTED', $answer['message']);
			$out['rejected']++;
		}

		write_log("broker_orders", $strategy . ": sent=" . $out['sent']
		          . " rejected=" . $out['rejected'] . " unknown=" . $out['unknown']
		          . " value=" . round($out['value'], 2));

		return $out;
	}

	/* ---------------------------------------------------------------------
	   Orders that are out and unaccounted for.
	   --------------------------------------------------------------------- */

	/** Whether the table that holds the blind flag exists. */
	function broker_book_state_ready() {

		static $known = null;
		if ($known !== null) { return $known; }

		$rows = query("select count(*) n from information_schema.tables
		                where table_schema=database() and table_name='broker_order_book_state'");
		$known = count($rows) && (int) $rows[0]['n'] === 1;
		return $known;
	}

	/**
	 * Write down whether the broker's order book could be read, for the
	 * strategy that asked.
	 *
	 * Written by broker_resolve_orders() every time it reads the book, blind
	 * or not, so a key that has been fixed clears the flag on the next run
	 * rather than leaving an old halt reason to be repeated. The row is the
	 * only place the sender that runs after the resolve step can find out:
	 * the two are separate calls, and the stuck check can also be reached
	 * from the page's dry run, which resolves nothing.
	 */
	function broker_book_note($strategy, $environment, array $book) {

		if (!broker_book_state_ready()) { return; }

		$blind   = count($book['blind']) > 0;
		$missing = implode(' and ', $book['blind']);

		query("insert into broker_order_book_state (strategy, environment, blind, missing, checked_at)
		       values (?, ?, ?, ?, now())
		       on duplicate key update environment=values(environment), blind=values(blind),
		                               missing=values(missing), checked_at=values(checked_at)",
		      $strategy, (string) $environment, $blind ? 'Y' : 'N', $blind ? $missing : null);
	}

	/**
	 * Was the order book unreadable when this strategy's orders were last
	 * resolved?
	 *
	 * Only a note from the last hour counts. The resolve step runs every ten
	 * minutes, so an older one is a job that has stopped, and a flag nobody
	 * has renewed should not decide what a halt says.
	 */
	function broker_book_blind($strategy) {

		$none = ['blind' => false, 'missing' => '', 'environment' => ''];
		if (!broker_book_state_ready()) { return $none; }

		$rows = query("select blind, missing, environment from broker_order_book_state
		                where strategy=? and timestampdiff(second, checked_at, now()) < 3600",
		              $strategy);
		if (!count($rows) || $rows[0]['blind'] !== 'Y') { return $none; }

		return ['blind' => true, 'missing' => (string) $rows[0]['missing'],
		        'environment' => (string) $rows[0]['environment']];
	}

	/**
	 * The one stuck-order rule, for both senders.
	 *
	 * Called by broker_send_orders() and broker_live_send_orders() before
	 * anything new is sent, and by nothing else. An order that was sent and has
	 * not been accounted for is a question, and the answer to a question is not
	 * another order:
	 *
	 *   settle time   (T212_SETTLE_SECONDS)  nothing more goes out this run;
	 *                 inside it, "still settling" is said and the run carries on;
	 *   stuck time    (T212_STUCK_SECONDS)   the strategy is halted through
	 *                 broker_halt_strategy(), which also disarms a live one.
	 *
	 * If the order book was blind at the last resolve - the key was refused
	 * when it asked for pending orders or the history - the halt says so: the
	 * orders may have filled, and the broker has to be looked at before the
	 * halt is cleared. Without that the halt reads as a slow broker, and
	 * the cause is a permission somebody has to go and change.
	 *
	 * $options: live (the wording says "live order(s)").
	 *
	 * Never sends, resends or cancels an order, and never clears a halt.
	 * Returns waiting, oldest (seconds), halted, hold (stop this run, not
	 * halted), blind, say (a line for the run's log) and error (the sentence
	 * the sender reports).
	 */
	function stuck_check($strategy, $options = []) {

		$live = !empty($options['live']);
		$out  = ['waiting' => 0, 'oldest' => 0, 'halted' => false, 'hold' => false,
		         'blind' => false, 'say' => '', 'error' => ''];

		$waiting = query("select client_order_id, status,
		                         timestampdiff(second, coalesce(sent_at, created_at), now()) age
		                    from strategy_orders
		                   where strategy=? and status in ('SENT','UNKNOWN')
		                   order by age desc", $strategy);

		if (!count($waiting)) { return $out; }

		$count  = count($waiting);
		$oldest = (int) $waiting[0]['age'];
		$mins   = round($oldest / 60);
		$noun   = ($live ? "live order(s)" : "order(s)");
		$book   = broker_book_blind($strategy);

		$out['waiting'] = $count;
		$out['oldest']  = $oldest;
		$out['blind']   = $book['blind'];

		if ($oldest >= (int) T212_STUCK_SECONDS) {

			if ($book['blind']) {
				$reason = "The Trading 212 key cannot read orders (it needs "
				        . $book['missing'] . "), so " . $count . " " . $noun . " out for "
				        . $mins . " minutes could not be checked and may have filled."
				        . " Check the broker before clearing this halt.";
			}
			else {
				$reason = $count . " " . $noun . " have been out for " . $mins
				        . " minutes without being resolved, the oldest being "
				        . $waiting[0]['client_order_id'];
			}

			broker_halt_strategy($strategy, $reason);
			$out['halted'] = true;
			$out['error']  = "halted: " . ($live ? "a live order" : "an order")
			               . " has been unresolved for " . $mins . " minutes"
			               . ($book['blind'] ? ", and the Trading 212 key cannot read orders" : "");
			return $out;
		}

		if ($oldest >= (int) T212_SETTLE_SECONDS) {
			$out['hold']  = true;
			$out['error'] = $count . " " . $noun . " sent earlier are still unresolved, the"
			              . " oldest " . $mins . " minute(s) ago. Nothing more is sent until the"
			              . " broker has been asked what became of them."
			              . ($book['blind']
			                 ? " The Trading 212 key cannot read orders, so it cannot be asked."
			                 : "");
			return $out;
		}

		$out['say'] = $count . " order(s) are still settling, which is normal for the first "
		            . (int) T212_SETTLE_SECONDS . " seconds";
		return $out;
	}

	/* ---------------------------------------------------------------------
	   Finding out what happened.
	   --------------------------------------------------------------------- */

	/**
	 * Mark the book blind when the key was refused the call.
	 *
	 * A 403 is a permission the key does not have and a 401 is a key Trading
	 * 212 did not accept: either way the answer is not "nothing is there", it is
	 * "you may not look". Any other failure - a timeout, a 429, a 500 - leaves
	 * the book incomplete, which the resolve step already treats as unable to
	 * decide, but it is not a statement about the key.
	 */
	function broker_book_missing(array &$book, array $answer, $permission) {

		$status = isset($answer['status']) ? (int) $answer['status'] : 0;
		if ($status === 403) { $needs = $permission; }
		else if ($status === 401) { $needs = "a key Trading 212 accepts"; }
		else { return; }

		if (!in_array($needs, $book['blind'], true)) { $book['blind'][] = $needs; }
	}

	/**
	 * The broker's own view of an order, by its id.
	 *
	 * Both lists are read once per run rather than once per order: the
	 * history call allows twenty a minute, and a run with ten orders out
	 * would spend its whole allowance asking about them one at a time.
	 */
	function broker_order_book($client) {

		$book = ['pending' => [], 'history' => [], 'errors' => [], 'blind' => []];

		$pending = $client->get('orders');
		if ($pending['ok']) {
			$rows = is_array($pending['data']) ? $pending['data'] : [];
			if (isset($rows['items']) && is_array($rows['items'])) { $rows = $rows['items']; }
			foreach ($rows as $row) {
				[$id] = broker_field($row, broker_fields('order_id'));
				if ($id !== null) { $book['pending'][(string) $id] = $row; }
			}
		}
		else {
			$book['errors'][] = "pending orders: " . $pending['message'];
			broker_book_missing($book, $pending, 'Orders - Read');
		}

		$history = $client->get_all('order_history');
		if (!$history['ok']) {
			$book['errors'][] = "order history: " . $history['message'];
			broker_book_missing($book, $history, 'history');
		}
		foreach ($history['items'] as $row) {
			[$id] = broker_field($row, broker_fields('order_id'));
			if ($id !== null) { $book['history'][(string) $id] = $row; }
		}

		return $book;
	}

	/**
	 * Write up a fill from the broker's own figures.
	 *
	 * The paper engine computes a fill from a close and its own cost model.
	 * This does not: the quantity and the price are the broker's, because
	 * they are what happened. The only figure worked out here is the stamp
	 * duty, which Trading 212 charges on a purchase of UK shares and does
	 * not itemise in the order history - half a per cent, whatever the size.
	 * There is no commission and no spread allowance: the fill price already
	 * is the price that was paid.
	 */
	function broker_apply_fill($client_order_id, array $fill) {

		$rows = query("select * from strategy_orders where client_order_id=?", $client_order_id);
		if (!count($rows)) { return false; }
		$order = $rows[0];

		if ($order['status'] === 'FILLED') { return true; }

		$quantity = (float) $fill['quantity'];
		$price    = (float) $fill['price'];
		if ($quantity <= 0 || $price <= 0) { return false; }

		$consideration = $quantity * $price;

		/* The tax the broker charged and itemised, not half a per cent
		   worked out here. B&M is Jersey registered and was charged nothing;
		   a calculated figure would have invented GBP 1.65 of stamp duty on
		   that fill and taken it out of the cash. Null means the response
		   carried no list at all, which is the only case where working it
		   out is better than nothing. */
		$stamp_duty = isset($fill['taxes']) && $fill['taxes'] !== null
		            ? (float) $fill['taxes']
		            : ($order['side'] === 'BUY' ? $consideration * STRATEGY_STAMP_DUTY_RATE : 0.0);

		/* And the cash that actually moved: netValue, taxes and all. The
		   consideration is what the shares cost, which is not the same
		   number and never was. */
		$moved = isset($fill['net_value']) && $fill['net_value'] !== null
		       ? abs((float) $fill['net_value'])
		       : $consideration + ($order['side'] === 'BUY' ? $stamp_duty : -$stamp_duty);

		$total_cost = $order['side'] === 'BUY' ? -$moved : $moved;

		$positions = strategy_positions($order['strategy']);
		$held = isset($positions[$order['symbol']])
		      ? (float) $positions[$order['symbol']]['quantity'] : 0.0;

		if ($order['side'] === 'SELL' && $quantity > $held + 0.00000001) {
			/* The broker says it sold more than the engine thinks it had.
			   That is a disagreement about the book, not an order to write
			   up: the strategy stops until somebody has looked. */
			broker_halt_strategy($order['strategy'],
			    "the broker filled a sale of " . $quantity . " " . $order['symbol']
			  . " against a holding of " . $held);
			return false;
		}

		query("update strategy_accounts set cash=cash+? where strategy=?",
		      round($total_cost, 4), $order['strategy']);

		if ($order['side'] === 'BUY') {
			$new_quantity = $held + $quantity;
			$previous_cost = $held * (isset($positions[$order['symbol']])
			               ? (float) $positions[$order['symbol']]['avg_price'] : 0.0);
			$avg_price = $new_quantity > 0
			           ? ($previous_cost + (-$total_cost)) / $new_quantity : 0.0;
		}
		else {
			$new_quantity = $held - $quantity;
			$avg_price = isset($positions[$order['symbol']])
			           ? (float) $positions[$order['symbol']]['avg_price'] : 0.0;
		}

		query("insert into strategy_positions (strategy,symbol,exchange,quantity,avg_price,updated_at)
		       values (?,?,?,?,?,?)
		       on duplicate key update quantity=values(quantity), avg_price=values(avg_price),
		                               updated_at=values(updated_at)",
		      $order['strategy'], $order['symbol'], $order['exchange'],
		      round($new_quantity, 8), round($avg_price, 4), date('Y-m-d H:i:s'));

		query("update strategy_orders set status='FILLED', quantity=?, fill_date=?, fill_price=?,
		         consideration=?, commission=0, stamp_duty=?, slippage=0, total_cost=?,
		         filled_at=?, broker_status=? where client_order_id=?",
		      round($quantity, 8),
		      isset($fill['date']) ? substr((string) $fill['date'], 0, 10) : date('Y-m-d'),
		      round($price, 4), round($consideration, 4), round($stamp_duty, 4),
		      round($total_cost, 4), date('Y-m-d H:i:s'),
		      isset($fill['status']) ? substr((string) $fill['status'], 0, 30) : null,
		      $client_order_id);

		write_log("broker_orders", $client_order_id . " filled " . $quantity . " at "
		          . $price . ", cash " . round($total_cost, 2));

		return true;
	}

	/**
	 * What the broker's row says became of an order.
	 *
	 * Returns ['outcome' => 'filled'|'working'|'rejected'|'cancelled',
	 *          'quantity' =>, 'price' =>, 'status' =>, 'date' =>].
	 *
	 * The status words are not documented, so the ones that mean "it is
	 * over" are listed and everything else is read as still working. That
	 * way an unfamiliar word leaves the order alone rather than closing it
	 * on a guess.
	 */
	function broker_order_outcome(array $row, $unit = null) {

		[$status] = broker_field($row, broker_fields('order_status'));
		$status   = strtoupper(trim((string) $status));

		$quantity = broker_number($row, broker_fields('order_quantity'));
		$price    = broker_number($row, broker_fields('order_price'));
		$date     = broker_datetime($row, broker_fields('order_filled'));

		/* The money, from the broker rather than from arithmetic here: the
		   tax it charged and the cash that moved. Both are pounds whatever
		   the price is quoted in. */
		$out = ['outcome' => 'working', 'status' => $status,
		        'side' => broker_order_side($row),
		        'quantity' => $quantity === null ? null : abs($quantity),
		        'price' => broker_to_pounds($price, $unit === null ? T212_PRICE_UNIT : $unit),
		        'taxes' => broker_taxes_paid($row),
		        'net_value' => broker_number($row, broker_fields('order_value')),
		        'date' => $date];

		if (in_array($status, ['FILLED', 'EXECUTED', 'COMPLETED'], true)) { $out['outcome'] = 'filled'; }
		else if (in_array($status, ['REJECTED', 'REFUSED', 'FAILED'], true)) { $out['outcome'] = 'rejected'; }
		else if (in_array($status, ['CANCELLED', 'CANCELED', 'EXPIRED'], true)) { $out['outcome'] = 'cancelled'; }
		/* A filled quantity and a price, with a word nobody here knows, is a
		   fill: the figures are the fact and the word is the label. */
		else if ($out['quantity'] > 0 && $out['price'] > 0 && $date !== null) { $out['outcome'] = 'filled'; }

		return $out;
	}

	/**
	 * Resolve what has been sent: fills, refusals, and the unknowns.
	 *
	 * An UNKNOWN order is the one that matters. It was sent and the answer
	 * never arrived, so this application does not know whether it exists.
	 * It is matched against the broker's pending orders and history by
	 * ticker, side and time, and only then decided:
	 *
	 *   exactly one candidate   adopt it and carry on with that order;
	 *   none, after long enough it never arrived, so the order is written
	 *                           off and the next rebalance may plan it again;
	 *   more than one           halt. Two orders either of which could be
	 *                           ours is the case where a wrong guess doubles
	 *                           a position, and no rule here is worth that.
	 */
	function broker_resolve_orders($strategy, $options = []) {

		$out = ['filled' => 0, 'rejected' => 0, 'cancelled' => 0, 'working' => 0,
		        'adopted' => 0, 'written_off' => 0, 'lines' => [], 'errors' => [],
		        'halted' => false, 'blind' => false];

		if (!broker_trading_ready()) {
			$out['errors'][] = "sql/broker_trading.sql has not been applied";
			return $out;
		}

		$account = strategy_account($strategy);
		if ($account === null) { $out['errors'][] = "no account for " . $strategy; return $out; }

		$mode = strategy_mode_rules($account['mode']);
		if ($mode['broker'] === null) { return $out; }

		$waiting = query("select * from strategy_orders where strategy=?
		                   and status in ('SENT','UNKNOWN') order by created_at", $strategy);
		if (!count($waiting)) { return $out; }

		$client = isset($options['client']) ? $options['client'] : new T212Client();
		if (!$client->configured()) { $out['errors'][] = $client->why_not(); return $out; }

		$book = broker_order_book($client);
		foreach ($book['errors'] as $error) { $out['errors'][] = $error; }

		/* BLIND: the key was refused the pending orders or the history. The
		   orders stay as they are - they are not marked filled, refused or
		   missing from a book that could not be read, and nothing is written
		   from the positions alone - and the fact is stored for the sender and
		   the stuck-order check that run after this. Written on every read, so
		   a fixed key clears it. */
		broker_book_note($strategy, $client->environment(), $book);
		if (count($book['blind'])) {
			$out['blind'] = true;
			$out['errors'][] = "BLIND: the " . $client->environment() . " key cannot read orders (it needs "
			                 . implode(' and ', $book['blind']) . "), so what became of the "
			                 . count($waiting) . " order(s) sent earlier is not known. They may have"
			                 . " filled. Check the broker.";
		}

		/* Nothing is decided from an incomplete picture: if the history
		   could not be read, an order that is not in it has not been shown
		   to be missing. */
		$complete = count($book['errors']) === 0;

		foreach ($waiting as $order) {

			$id = $order['broker_order_id'] === null ? null : (string) $order['broker_order_id'];

			/* An order we never got an id for. Look for it by what it would
			   look like if it had arrived. */
			if ($id === null) {

				if (!$complete) { $out['working']++; continue; }

				$ticker = broker_ticker_for($order['symbol'], $mode['broker'],
				                            $mode['environment']);
				$since  = $order['sent_at'] === null
				        ? strtotime((string) $order['created_at'])
				        : strtotime((string) $order['sent_at']);

				$candidates = [];
				foreach ([$book['pending'], $book['history']] as $where) {
					foreach ($where as $broker_id => $row) {

						[$row_ticker] = broker_field($row, broker_fields('order_ticker'));
						if ((string) $row_ticker !== (string) $ticker) { continue; }

						$side = broker_order_side($row);
						if ($side === null || $side !== $order['side']) { continue; }

						/* Ours only if it was created after we sent, which
						   is what rules out last week's order in the same
						   share. */
						$placed = broker_datetime($row, broker_fields('order_placed'));
						if ($placed !== null && $since !== false
						    && strtotime($placed . ' UTC') < $since - 60) { continue; }

						/* And not one already claimed by another of ours. */
						$claimed = query("select count(*) n from strategy_orders
						                   where broker=? and broker_order_id=?",
						                 $mode['broker'], (string) $broker_id);
						if (count($claimed) && (int) $claimed[0]['n'] > 0) { continue; }

						$candidates[(string) $broker_id] = $row;
					}
				}

				if (count($candidates) > 1) {
					broker_halt_strategy($strategy,
					    "cannot tell which Trading 212 order is " . $order['client_order_id']
					  . ": " . implode(', ', array_keys($candidates)));
					$out['halted'] = true;
					return $out;
				}

				if (count($candidates) === 1) {
					$id = (string) array_key_first($candidates);
					query("update strategy_orders set broker_order_id=?, status='SENT'
					        where client_order_id=?", $id, $order['client_order_id']);
					$out['adopted']++;
					$out['lines'][] = $order['client_order_id'] . " is " . $id . " at the broker";
				}
				else {
					/* Nothing at the broker looks like it. Long enough after
					   the attempt, that means it never arrived - which is
					   the only case where re-planning it is safe. */
					$age = $since === false ? 0 : (time() - $since);
					if ($age < (int) T212_UNKNOWN_AFTER_SECONDS) { $out['working']++; continue; }

					broker_order_status($order['client_order_id'], 'REJECTED',
					    "no order like it at the broker " . round($age / 60) . " minutes after"
					  . " it was sent, so it never arrived");
					$out['written_off']++;
					continue;
				}
			}

			$row = isset($book['pending'][$id]) ? $book['pending'][$id]
			     : (isset($book['history'][$id]) ? $book['history'][$id] : null);

			if ($row === null) {
				if (!$complete) { $out['working']++; continue; }
				/* It had an id, so it certainly arrived; it is simply not in
				   either list yet. Left alone: an order that exists is never
				   written off. */
				$out['working']++;
				continue;
			}

			if (isset($book['pending'][$id])) { $out['working']++; continue; }

			$outcome = broker_order_outcome($row,
			                 broker_sync_price_unit($mode['broker'], $mode['environment']));

			if ($outcome['outcome'] === 'filled') {

				/* The broker says which way round it went, and it must be
				   the way this application sent it. A disagreement here is
				   either the wrong order or a bug in the matching, and
				   writing it up either way would move the book the wrong
				   way. */
				if ($outcome['side'] !== null && $outcome['side'] !== $order['side']) {
					broker_halt_strategy($strategy,
					    "the broker says " . $id . " was a " . $outcome['side']
					  . " and this application sent it as a " . $order['side']);
					$out['halted'] = true;
					return $out;
				}

				if (broker_apply_fill($order['client_order_id'],
				        ['quantity' => $outcome['quantity'], 'price' => $outcome['price'],
				         'taxes' => $outcome['taxes'], 'net_value' => $outcome['net_value'],
				         'date' => $outcome['date'], 'status' => $outcome['status']])) {
					$out['filled']++;
				}
				else {
					$out['errors'][] = $order['client_order_id'] . ": the broker's fill could"
					                 . " not be written up";
					$out['halted'] = strategy_account($strategy)['halted'] === 'Y';
				}
				continue;
			}

			if ($outcome['outcome'] === 'rejected') {
				broker_order_status($order['client_order_id'], 'REJECTED',
				    "the broker refused it (" . $outcome['status'] . ")");
				$out['rejected']++;
				continue;
			}

			if ($outcome['outcome'] === 'cancelled') {
				broker_order_status($order['client_order_id'], 'CANCELLED',
				    "cancelled at the broker (" . $outcome['status'] . ")");
				$out['cancelled']++;
				continue;
			}

			$out['working']++;
		}

		return $out;
	}

	/**
	 * The price unit the last sync settled on, for reading the history's
	 * prices. Falls back to the configured one, and to nothing at all -
	 * which leaves a fill unwritten rather than written at a hundred times
	 * the price.
	 */
	function broker_sync_price_unit($broker = BROKER_T212, $environment = null) {

		if (in_array(T212_PRICE_UNIT, ['pounds', 'pence'], true)) { return T212_PRICE_UNIT; }

		$rows = ($environment === null)
		      ? query("select price_unit from broker_sync_runs
		                where broker=? and price_unit is not null
		                order by run_id desc limit 1", $broker)
		      : query("select price_unit from broker_sync_runs
		                where broker=? and environment=? and price_unit is not null
		                order by run_id desc limit 1", $broker, $environment);

		return count($rows) ? (string) $rows[0]['price_unit'] : null;
	}

	/* ---------------------------------------------------------------------
	   Reconciliation.
	   --------------------------------------------------------------------- */

	/**
	 * Whether this strategy's book and the broker's agree, for the page.
	 *
	 * Read only: it halts nothing, writes nothing, and says which of the
	 * three things is true - agreed as at a time, differs and here is what
	 * by, or nobody has asked today. Beside it, the cash on both sides and
	 * when the broker was last read at all, because "agrees" about the
	 * shares while the cash is out by fifty pounds is half an answer.
	 */
	function broker_agreement_for($strategy) {

		$out = ['state' => 'unchecked', 'words' => 'Not checked today',
		        'tone' => 'due', 'as_of' => null, 'read_at' => null,
		        'differences' => [], 'waiting' => [], 'extra' => [], 'own_only' => false,
		        'our_cash' => null, 'their_cash' => null, 'sync' => null];

		$account = strategy_account($strategy);
		if ($account === null) { return $out; }

		$mode = strategy_mode_rules($account['mode']);
		if ($mode['broker'] === null) { return $out; }

		$out['our_cash'] = (float) $account['cash'];

		$check = broker_reconcile_positions($strategy, ['report_only' => true]);

		$out['as_of']       = $check['as_of'];
		$out['read_at']     = $check['read_at'];
		$out['differences'] = $check['differences'];
		$out['waiting']     = $check['waiting'];
		/* What the account holds that is not this strategy's, on a live
		   account where that is a normal thing rather than a difference. */
		$out['extra']       = $check['extra'];
		$out['own_only']    = $check['own_only'];

		/* Whether anybody has asked comes first. The reconciler reports
		   "never been read" and "not read today" in the same list as a real
		   disagreement, because to it they are both reasons the books were
		   not compared - but on a page they are very different things to
		   tell a reader, and "Differs from Trading 212" when nobody has
		   looked would be the page inventing a disagreement. */
		if ($check['as_of'] === null || $check['as_of'] !== date('Y-m-d')) {
			$out['words'] = 'Not checked today'
			              . ($check['as_of'] === null ? '' : ', last read ' . $check['as_of']);
			/* Not a list of disagreements: the reconciler's one line about
			   nobody having looked, which the words above already say. */
			$out['differences'] = [];
		}
		else if (count($check['differences'])) {
			$out['state'] = 'differs';
			$out['tone']  = 'halted';
			$out['words'] = 'Differs from ' . broker_name($mode['broker']);
		}
		else {
			$out['state'] = 'agreed';
			$out['tone']  = 'ok';
			/* "Holds what it bought" rather than "agrees" for a live
			   account: the two books are not meant to be the same, because
			   one of them is Dale's whole account, and saying they agree
			   would be claiming a check that was not made. */
			$when = ($check['read_at'] === null) ? $check['as_of'] : $check['read_at'];
			$out['words'] = $check['own_only']
			              ? ('Holds what it bought, at ' . $when)
			              : ('Agrees with ' . broker_name($mode['broker']) . ' as at ' . $when);
		}

		$values = query("select cash, as_of_date from broker_account_values
		                  where broker=? and environment=?
		                  order by as_of_date desc limit 1",
		                $mode['broker'], $mode['environment']);
		if (count($values)) { $out['their_cash'] = $values[0]['cash'] === null
		                                         ? null : (float) $values[0]['cash']; }

		$runs = query("select status, finished_at, as_of_date from broker_sync_runs
		                where broker=? and environment=?
		                order by run_id desc limit 1", $mode['broker'], $mode['environment']);
		if (count($runs)) { $out['sync'] = $runs[0]; }

		return $out;
	}

	/** The broker's name as a reader knows it. */
	function broker_name($broker) {
		return ((string) $broker === BROKER_T212) ? 'Trading 212' : (string) $broker;
	}

	/**
	 * Whether the two books can be compared at all, and whether there is
	 * anything to compare.
	 *
	 * Asked before sending, because sending without having compared is
	 * sending into the dark: the first stage 2 run sent fifteen orders'
	 * worth of plan in three passes, each one reporting "the broker's
	 * positions have never been read, so there is nothing to compare
	 * against", and nobody would have known if the broker had held
	 * something else entirely.
	 *
	 * A first build is the one exception, and it is a narrow one: this
	 * strategy holds nothing and has never had a fill, so there is nothing
	 * of its own that the two books could disagree about.
	 *
	 * This reads and decides nothing. broker_reconcile_positions() is what
	 * halts.
	 */
	function broker_reconciliation_state($strategy, $broker = BROKER_T212, $environment = 'demo') {

		/* The read is the sync RUN, not the rows it wrote: a broker that
		   holds nothing today writes no position rows, and "no rows" must
		   not be mistaken for "nobody looked". A run that finished OK had
		   its positions call answered - a positions failure makes it
		   PARTIAL - so an OK run for today is a read, with or without rows
		   behind it. */
		$rows = query("select max(as_of_date) d from broker_positions
		                where broker=? and environment=?", $broker, $environment);
		$as_of = (count($rows) && $rows[0]['d'] !== null) ? (string) $rows[0]['d'] : null;

		$runs = query("select max(as_of_date) d from broker_sync_runs
		                where broker=? and environment=? and status='OK'",
		              $broker, $environment);
		$ran  = (count($runs) && $runs[0]['d'] !== null) ? (string) $runs[0]['d'] : null;

		if ($as_of === null || ($ran !== null && $ran > $as_of)) { $as_of = $ran; }

		$held = 0;
		foreach (strategy_positions($strategy) as $position) {
			if ((float) $position['quantity'] != 0.0) { $held++; }
		}
		$filled = query("select count(*) n from strategy_orders
		                  where strategy=? and status='FILLED'", $strategy);
		$filled = count($filled) ? (int) $filled[0]['n'] : 0;

		$first_build = ($held === 0 && $filled === 0);

		if ($as_of === date('Y-m-d')) {
			return ['compared' => true, 'first_build' => $first_build, 'as_of' => $as_of,
			        'why' => "compared against the broker's positions as at " . $as_of];
		}

		return ['compared' => false, 'first_build' => $first_build, 'as_of' => $as_of,
		        'why' => $as_of === null
		               ? "the broker's positions have never been read"
		               : "the broker's positions were last read on " . $as_of . ", not today"];
	}

	/**
	 * The engine's positions against the broker's.
	 *
	 * Any difference halts the strategy. A disagreement about what is held
	 * is a disagreement about what everything is worth, and trading through
	 * one compounds it: the next rebalance would size every order against a
	 * book that does not exist.
	 *
	 * The broker's side is broker_positions, which the sync writes. If it
	 * has not run today this says so and halts nothing: "I do not know" is
	 * not "they disagree".
	 *
	 * The tolerance is a rounding tolerance, not a cushion. A quantity is
	 * stored to eight places on both sides and the broker's is the truth; a
	 * difference bigger than a rounding error is a real one, however small
	 * it looks.
	 */
	function broker_reconcile_positions($strategy, $options = []) {

		/* report_only: ask the question without acting on the answer. The
		   Strategies page shows whether the two books agree, and a page
		   that halts a strategy because somebody loaded it is not a page. */

		$out = ['checked' => 0, 'differences' => [], 'waiting' => [], 'extra' => [],
		        'halted' => false, 'as_of' => null, 'read_at' => null, 'own_only' => false];

		$account = strategy_account($strategy);
		if ($account === null) { return $out; }

		$mode = strategy_mode_rules($account['mode']);
		if ($mode['broker'] === null) { return $out; }

		/* The read is the sync RUN as well as the rows it wrote. A broker
		   that holds nothing writes no position rows, and "no rows" is not
		   "nobody looked" - the same distinction broker_reconciliation_state()
		   makes. Without it, an account that has just sold everything, or
		   one whose first fill has not been read back yet, reads as never
		   having been compared. */
		$latest = query("select max(as_of_date) d from broker_positions
		                  where broker=? and environment=?",
		                $mode['broker'], $mode['environment']);
		$out['as_of'] = (count($latest) && $latest[0]['d'] !== null)
		              ? (string) $latest[0]['d'] : null;

		$runs = query("select max(as_of_date) d from broker_sync_runs
		                where broker=? and environment=? and status='OK'",
		              $mode['broker'], $mode['environment']);
		$ran  = (count($runs) && $runs[0]['d'] !== null) ? (string) $runs[0]['d'] : null;

		if ($out['as_of'] === null || ($ran !== null && $ran > $out['as_of'])) {
			$out['as_of'] = $ran;
		}

		if ($out['as_of'] === null) {
			$out['differences'][] = "the broker's positions have never been read, so there is"
			                      . " nothing to compare against";
			return $out;
		}

		/* Only today's answer is worth comparing against: yesterday's would
		   disagree with anything that has traded since, which is not a
		   disagreement at all. */
		if (empty($options['any_date']) && $out['as_of'] !== date('Y-m-d')) {
			$out['differences'][] = "the broker's positions were last read on " . $out['as_of']
			                      . ", not today, so they were not compared";
			return $out;
		}

		$theirs = [];
		foreach (query("select symbol, quantity from broker_positions
		                 where broker=? and environment=? and as_of_date=?
		                   and symbol is not null",
		               $mode['broker'], $mode['environment'], $out['as_of']) as $row) {
			$theirs[(string) $row['symbol']] = (float) $row['quantity'];
		}

		$ours = [];
		foreach (strategy_positions($strategy) as $symbol => $position) {
			if ((float) $position['quantity'] != 0.0) { $ours[$symbol] = (float) $position['quantity']; }
		}

		/* An order that has been sent and not yet filled explains a
		   difference, so it is not one. Anything else is. */
		$in_flight = [];
		foreach (query("select symbol, side, quantity from strategy_orders
		                 where strategy=? and status in ('SENT','UNKNOWN')", $strategy) as $row) {
			$in_flight[(string) $row['symbol']] = true;
		}

		/* And neither is a fill the broker has not been asked about yet.
		   Run 2 of the first build resolved three fills, wrote them into
		   this application's book, and then compared that book against a
		   read of the broker's positions taken before those fills had
		   happened. Of course they disagreed: "CMCX: this application holds
		   46.86, the broker holds 0" was a true statement about two
		   different moments, and it halted the strategy.

		   So: when the last read of the positions is older than the moment
		   a share's fill was written up, that share is not compared. It is
		   reported as waiting to be read back, and the next run - which
		   re-reads the positions first - compares it properly. */
		$read_at = null;
		foreach ([query("select max(synced_at) t from broker_positions
		                  where broker=? and environment=? and as_of_date=?",
		                $mode['broker'], $mode['environment'], $out['as_of']),
		          query("select max(finished_at) t from broker_sync_runs
		                  where broker=? and environment=? and as_of_date=?
		                    and status in ('OK','PARTIAL')",
		                $mode['broker'], $mode['environment'], $out['as_of'])] as $rows) {
			if (count($rows) && $rows[0]['t'] !== null) {
				$when = (string) $rows[0]['t'];
				if ($read_at === null || strtotime($when) > strtotime($read_at)) { $read_at = $when; }
			}
		}
		$out['read_at'] = $read_at;

		/* When the read's own time is not known, everything is compared as
		   before. Fail towards halting: a reconciliation that quietly stops
		   comparing is worse than one that stops the strategy, because the
		   second is visible and the first is not. */
		$not_read_back = [];
		if ($read_at !== null) {
			foreach (query("select symbol, max(filled_at) t from strategy_orders
			                 where strategy=? and status='FILLED' and filled_at is not null
			                 group by symbol", $strategy) as $row) {
				$when = (string) $row['t'];
				if (strtotime($when) > strtotime($read_at)) {
					$not_read_back[(string) $row['symbol']] = $when;
				}
			}
		}

		/* WHOSE POSITIONS ARE BEING COMPARED.
		 *
		 * The practice account is this application's and nothing else's, so
		 * every difference either way is a difference: a holding at the
		 * broker that the engine does not have is as wrong as one it has
		 * and the broker does not.
		 *
		 * Dale's live account is his. He holds shares he bought himself, and
		 * the practice rule would halt the strategy the moment it looked -
		 * every one of his holdings reading as a position this application
		 * has lost. So a live strategy reconciles its OWN positions: every
		 * share it holds has to be at the broker in at least that quantity.
		 * More of it, or anything else in the account, is his and is
		 * reported rather than halted on. Less of it is a real difference
		 * and halts: somebody has sold our position by hand, and the next
		 * rebalance would size against shares that are not there.
		 */
		$out['own_only'] = (bool) $mode['live'];

		$symbols = $out['own_only']
		         ? array_keys($ours)
		         : array_unique(array_merge(array_keys($ours), array_keys($theirs)));

		foreach ($symbols as $symbol) {

			$out['checked']++;

			$mine  = isset($ours[$symbol]) ? $ours[$symbol] : 0.0;
			$yours = isset($theirs[$symbol]) ? $theirs[$symbol] : 0.0;

			if (abs($mine - $yours) < 0.00000001) { continue; }
			/* A shortfall is a difference; a surplus in a live account is
			   not ours to have an opinion about. */
			if ($out['own_only'] && $yours > $mine) { continue; }
			if (isset($in_flight[$symbol])) { continue; }

			if (isset($not_read_back[$symbol])) {
				$out['waiting'][] = $symbol . ": filled at " . $not_read_back[$symbol]
				                  . ", after the broker's positions were last read at "
				                  . $read_at . ", so the two were not compared";
				continue;
			}

			$out['differences'][] = $out['own_only']
			                      ? ($symbol . ": this strategy holds " . $mine
			                         . " and the broker holds " . $yours
			                         . ", which is less than it bought")
			                      : ($symbol . ": this application holds " . $mine
			                         . ", the broker holds " . $yours);
		}

		/* What is in the account that is not this strategy's. Said, not
		   acted on: it is how a reader tells "the strategy holds nothing in
		   that share" from "the strategy has lost track of it". */
		if ($out['own_only']) {
			foreach ($theirs as $symbol => $quantity) {
				$mine = isset($ours[$symbol]) ? $ours[$symbol] : 0.0;
				if ($quantity - $mine < 0.00000001) { continue; }
				$out['extra'][] = $symbol . ": the account holds " . $quantity
				                . ($mine > 0 ? (", of which this strategy bought " . $mine)
				                             : ", none of it this strategy's");
			}
		}

		/* A difference in a share the strategy holds is a halt, in either
		   account. On the practice account a holding the engine never
		   bought is one too: nothing else trades that key.

		   On the live account it is not - see above - which is why the halt
		   here reads from $out['differences'] rather than deciding anything
		   of its own. */
		if (count($out['differences']) && empty($options['report_only'])) {
			broker_halt_strategy($strategy,
			    "the broker and this application disagree about what is held: "
			  . implode('; ', array_slice($out['differences'], 0, 3)));
			$out['halted'] = true;
		}

		return $out;
	}

/* No closing tag: see the note at the end of constants.php. */
