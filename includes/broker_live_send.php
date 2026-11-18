<?php

	/**
	 * The live order path: one function that posts to the live account, and
	 * the sender around it.
	 *
	 * Stage A built a live account that could not be sent to. Stage B built
	 * the gate - arming, approval, the caps, the log - and no door. This is
	 * the door, and it is deliberately one door:
	 *
	 *   broker_live_place_order()  the ONLY function in this application
	 *                              that posts to live.trading212.com. The
	 *                              first thing it does is ask
	 *                              broker_live_conditions() whether all six
	 *                              conditions hold, and it opens no socket
	 *                              until they do.
	 *
	 *   broker_live_send_orders()  the run: what is already out there has to
	 *                              be accounted for, the two books have to
	 *                              have been compared, the batch has to have
	 *                              been approved, and each order has to be
	 *                              inside every cap. Then one order at a
	 *                              time, SENT before the call.
	 *
	 * WHY A SECOND FUNCTION rather than a parameter on the practice one.
	 * T212Client::place_market_order() refuses any environment but demo and
	 * builds its URL from the demo constant; this builds its own from the
	 * live constant. Neither can be turned into the other by a caller, a
	 * setting or a mistake, and the suite checks that the live host is used
	 * for a write in exactly one function - this one.
	 *
	 * WHAT STILL CANNOT HAPPEN. With SM_T212_LIVE_ORDERS off, or its key
	 * absent, or the account unarmed, or the batch unapproved, or a cap
	 * breached, or the kill switch on, or the strategy halted, no socket is
	 * opened at all: the refusal is recorded in strategy_live_log and the
	 * order stays exactly as it was.
	 *
	 * Nothing here throws. A strategy that cannot be sent this minute is a
	 * strategy that does nothing this minute.
	 */

	require_once("constants.php");
	/* One direction: this file knows about the broker layer and the gate,
	   and neither of them knows about this. broker_orders.php brings
	   broker.php, strategy_functions.php and broker_live.php with it. */
	require_once(__DIR__ . "/broker_orders.php");

	/**
	 * A client for READING the live account.
	 *
	 * The orders key has Orders - Read as well as Orders - Execute, so when
	 * it is configured it is the one that answers "what became of the order
	 * we sent"; without it the read-only live key does, which is what a host
	 * that only watches the account has. Neither of them can write: this
	 * file is the only thing that can, and it does not go through the
	 * client at all.
	 */
	function broker_live_client($options = []) {

		if (isset($options['client'])) { return $options['client']; }

		$env = (T212_LIVE_ORDERS_ON && trim((string) T212_LIVE_ORDERS_API_KEY) !== '')
		     ? 'live-orders' : 'live-read';

		return new T212Client(array_merge(['env' => $env],
		                      isset($options['client_options']) ? $options['client_options'] : []));
	}

	/**
	 * Where a live order goes, or why it goes nowhere.
	 *
	 * Three locks, the same shape as the practice path's:
	 *
	 *   1. the host is the live constant, or the suite's override;
	 *   2. it is never the practice host - sending a live account's order
	 *      to the practice account is not a smaller mistake than sending it
	 *      to the live one, it is a different wrong trade;
	 *   3. with no override it must be live.trading212.com exactly, so a
	 *      setting cannot point real orders at somebody else's server.
	 */
	function broker_live_host() {

		$override = defined('T212_LIVE_ORDERS_BASE_URL')
		          ? trim((string) T212_LIVE_ORDERS_BASE_URL) : '';

		$base = ($override !== '') ? rtrim($override, '/') : rtrim(T212_LIVE_BASE_URL, '/');
		$host = (string) parse_url($base, PHP_URL_HOST);

		if ($host === '') {
			return ['base' => null, 'host' => '', 'stub' => false,
			        'why' => "'" . $base . "' is not a URL with a host in it, so no order"
			               . " was sent."];
		}
		if (stripos($base, 'demo.trading212.com') !== false) {
			return ['base' => null, 'host' => $host, 'stub' => false,
			        'why' => "refusing to send a live account's order to the practice"
			               . " account at '" . $host . "'. A live order placed on the"
			               . " practice account is not a smaller mistake; it is a trade"
			               . " that did not happen where the book says it did."];
		}
		if ($override === '' && strcasecmp($host, 'live.trading212.com') !== 0) {
			return ['base' => null, 'host' => $host, 'stub' => false,
			        'why' => "refusing to send a live order to '" . $host . "': the live"
			               . " path posts to live.trading212.com and nowhere else."];
		}

		return ['base' => $base, 'host' => $host, 'stub' => ($override !== ''), 'why' => ''];
	}

	/**
	 * Place ONE market order against the live account.
	 *
	 * The only function in this application that posts to the live host, and
	 * the six conditions are the first thing in it - not in its caller. A
	 * condition checked in a caller is a condition the next caller does not
	 * have, and there will be a next caller.
	 *
	 * $order is a strategy_orders row. $options:
	 *   ticker       the broker's ticker, when the caller has looked it up
	 *   value        the order's value in pounds, for the cap check
	 *   run_value    what this run has sent already
	 *   ignore_time  the suite only: send outside the session
	 *   day          the day the caps count against, for the suite
	 *
	 * Returns the same shape as T212Client::get(): ok, or kind + message,
	 * where 'unknown' means the order may or may not have arrived and must
	 * never be sent again blind.
	 */
	function broker_live_place_order($strategy, $order, $options = []) {

		$refuse = function ($kind, $message, $status = 0) use ($strategy) {
			return ['ok' => false, 'kind' => $kind, 'status' => $status,
			        'message' => $message, 'data' => null];
		};

		if (!is_array($order) || !isset($order['symbol'], $order['side'], $order['quantity'])) {
			return $refuse('usage', "that is not an order.");
		}

		/* ---- THE SIX CONDITIONS, before anything else happens ---- */
		$conditions = broker_live_conditions($strategy, array_merge($options, ['order' => $order]));
		if (!$conditions['ok']) {
			return array_merge($refuse('refused', "no live order was sent: " . $conditions['why']),
			                   ['conditions' => $conditions]);
		}

		/* Market orders, in the session only. A market order queued
		   overnight fills at whatever the open does, which is not the price
		   anything was decided at - and on a live account that is real
		   money paid for a decision nobody made. */
		if (empty($options['ignore_time']) && !broker_market_open()) {
			broker_live_log($strategy, 'REFUSED', null,
			                "London is shut, so nothing was sent");
			return $refuse('refused', "London is shut. A live order goes out during the"
			                        . " session and at no other time.");
		}

		/* A sale is this strategy's own to make. Checked here as well as in
		   the conditions: it is the one limit where being wrong means
		   selling somebody else's shares. */
		if (strtoupper((string) $order['side']) === 'SELL') {
			$own = broker_live_sale_allowed($strategy, (string) $order['symbol'],
			                                (float) $order['quantity']);
			if (!$own['ok']) {
				broker_live_log($strategy, 'REFUSED', null, $own['why']);
				return $refuse('refused', $own['why']);
			}
		}

		$ticker = isset($options['ticker']) ? (string) $options['ticker']
		        : (string) broker_ticker_for((string) $order['symbol'], BROKER_T212, 'live');
		if (trim($ticker) === '') {
			return $refuse('refused', "the live account lists no instrument for "
			                        . $order['symbol'] . ", so there is nothing to send an"
			                        . " order for.");
		}

		$quantity = (float) $order['quantity'];
		if (strtoupper((string) $order['side']) === 'SELL') { $quantity = -abs($quantity); }
		if ($quantity === 0.0) {
			return $refuse('refused', "an order for nothing is not an order.");
		}

		$where = broker_live_host();
		if ($where['base'] === null) {
			broker_live_log($strategy, 'REFUSED', null, $where['why']);
			return $refuse('refused', $where['why']);
		}

		$body = json_encode(['ticker' => $ticker, 'quantity' => $quantity]);
		if ($body === false) { return $refuse('refused', "could not build the order."); }

		/* The key with Orders - Execute, and only here. Not through
		   T212Client, which has no write path for this environment at all:
		   the one credential that can move real money is read in one
		   function, used in one request, and masked everywhere else. */
		$key    = (string) T212_LIVE_ORDERS_API_KEY;
		$secret = (string) T212_LIVE_ORDERS_API_SECRET;
		if (trim($key) === '' || trim($secret) === '') {
			return $refuse('not_configured',
			               "no live orders key is configured, so there is nothing to send with.");
		}

		$context = stream_context_create(["http" => [
			"method"  => "POST",
			"header"  => "Authorization: Basic "
			           . base64_encode($key . ':' . $secret) . "\r\n"
			           . "Content-Type: application/json\r\n"
			           . "Accept: application/json\r\n"
			           . "User-Agent: sharemanager/1.0 (private portfolio tool)\r\n"
			           . "Connection: close\r\n",
			"content" => $body,
			"timeout" => max(1, (int) T212_TIMEOUT),
			"ignore_errors" => true,
		]]);

		$http_response_header = [];
		$answer  = @file_get_contents($where['base'] . '/equity/orders/market', false, $context);
		$headers = (array) ($http_response_header ?? []);

		$status = 0;
		foreach ($headers as $line) {
			if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) { $status = (int) $m[1]; }
		}

		/* The two answers that mean "ask the broker what happened" rather
		   than "it did not happen". The order calls are not idempotent, so
		   a request whose answer never arrived must never be sent again
		   blind. */
		if ($answer === false) {
			broker_live_log($strategy, 'UNKNOWN', null,
			                (string) $order['client_order_id'] . ": no answer within "
			              . (int) T212_TIMEOUT . " seconds");
			return $refuse('unknown', "the order was sent and Trading 212 did not answer"
			                        . " within " . (int) T212_TIMEOUT . " seconds. Whether it"
			                        . " arrived is not known from here.", $status);
		}
		if ($status >= 500) {
			broker_live_log($strategy, 'UNKNOWN', null,
			                (string) $order['client_order_id'] . ": Trading 212 answered " . $status);
			return $refuse('unknown', "the order was sent and Trading 212 answered " . $status
			                        . ". Whether it arrived is not known from here.", $status);
		}

		if ($status === 401 || $status === 403) {
			return $refuse($status === 401 ? 'unauthorised' : 'forbidden',
			               "Trading 212 refused the order (" . $status . "). The live orders"
			             . " key does not have Orders - Execute, or it is not the right key.",
			               $status);
		}
		if ($status === 429) {
			return $refuse('rate_limited', "Trading 212 asked for less traffic, so the order"
			                             . " was not placed. It is not retried: the order"
			                             . " calls are not idempotent.", $status);
		}

		$decoded = json_decode((string) $answer, true);

		if ($status >= 400) {
			$why = is_array($decoded) && isset($decoded['code']) ? (string) $decoded['code']
			     : (is_array($decoded) && isset($decoded['message'])
			        ? (string) $decoded['message'] : 'no reason given');
			return $refuse('rejected', "Trading 212 refused the order: " . t212_mask($why), $status);
		}
		if (!is_array($decoded)) {
			return $refuse('unknown', "Trading 212 accepted the order and answered something"
			                        . " that is not JSON, so its id is not known from here.",
			               $status);
		}

		return ['ok' => true, 'status' => $status, 'data' => $decoded];
	}

	/**
	 * Send a live strategy's approved orders.
	 *
	 * $options:
	 *   dry_run      say what would be sent and send nothing
	 *   if_approved  with dry_run only: answer "what would go if the batch
	 *                were approved", which is what the page's dry run asks
	 *   ignore_time  the suite only
	 *   day          the day the caps count against, for the suite
	 */
	function broker_live_send_orders($strategy, $options = []) {

		$out = ['sent' => 0, 'refused' => 0, 'unknown' => 0, 'skipped' => 0, 'held_back' => 0,
		        'value' => 0.0, 'lines' => [], 'errors' => [], 'halted' => false,
		        'would_send' => []];

		$say     = function ($line) use (&$out) { $out['lines'][] = $line; };
		$dry_run = !empty($options['dry_run']);

		if (!broker_trading_ready()) {
			$out['errors'][] = "sql/broker_trading.sql has not been applied";
			return $out;
		}
		if (!broker_live_ready()) {
			$out['errors'][] = "sql/strategy_live.sql has not been applied, so no account can"
			                 . " be armed and nothing can be sent";
			return $out;
		}

		/* Fail closed, before anything is read. */
		if (strategy_halted()) {
			$out['halted'] = true;
			$out['errors'][] = "halted by the kill switch at " . STRATEGY_KILL_SWITCH_FILE;
			return $out;
		}

		$account = strategy_account($strategy);
		if ($account === null) { $out['errors'][] = "no account for " . $strategy; return $out; }

		$mode = strategy_mode_rules($account['mode']);
		if (!strategy_mode_is_live($account['mode'])) {
			$out['errors'][] = $strategy . " is a " . $mode['words'] . " account. This sender"
			                 . " only ever sends to the live account; the practice one is"
			                 . " includes/send_broker_orders.php.";
			return $out;
		}
		if ($account['enabled'] !== 'Y') { $out['errors'][] = "the account is switched off"; return $out; }
		if ($account['halted'] === 'Y') {
			$out['halted'] = true;
			$out['errors'][] = "halted: " . (string) $account['halt_reason'];
			return $out;
		}

		/* The switch and the arming, said once and plainly, because "no
		   orders went out" is a question somebody asks the next morning.
		   Both are checked again per order, inside the order function, and
		   that is the check that matters. */
		/* Not for the page's question. "What would be sent if this batch
		   were approved" is answerable with the switch off - the conditions
		   list says the switch is off - and a preview that refused to look
		   until the key was installed would be of no use on the day it is
		   wanted most: before it is. It opens no socket and writes no row,
		   so there is nothing here for the switch to protect. */
		$preview = $dry_run && !empty($options['if_approved']);
		if (!$preview && (!T212_LIVE_ORDERS_ON || trim((string) T212_LIVE_ORDERS_API_KEY) === '')) {
			$out['errors'][] = "SM_T212_LIVE_ORDERS is off, or its own key is not set, so"
			                 . " nothing can be sent to the live account.";
			return $out;
		}
		if (!broker_live_armed($strategy)) {
			$out['errors'][] = broker_live_words($strategy)
			                 . " Arming is a form on the strategy's page.";
			return $out;
		}

		if (empty($options['ignore_time']) && !broker_market_open()) {
			$out['errors'][] = "London is shut. Live orders go out during the session only:"
			                 . " a market order queued overnight fills at whatever the open"
			                 . " does.";
			return $out;
		}

		$where = broker_live_host();
		if ($where['base'] === null) { $out['errors'][] = $where['why']; return $out; }
		if ($where['stub']) {
			$say("NOT TRADING 212: SM_T212_LIVE_ORDERS_BASE_URL points this run at "
			     . $where['host']);
		}

		/* Nothing new goes out while something already sent is unaccounted
		   for. A market order fills in ten to thirty seconds; one that is
		   still unresolved minutes later is a question, and the answer to a
		   question is not another order. */
		$waiting = query("select client_order_id, status,
		                         timestampdiff(second, coalesce(sent_at, created_at), now()) age
		                    from strategy_orders
		                   where strategy=? and status in ('SENT','UNKNOWN')
		                   order by age desc", $strategy);

		if (count($waiting)) {

			$oldest = (int) $waiting[0]['age'];

			if ($oldest >= (int) T212_STUCK_SECONDS) {
				broker_halt_strategy($strategy,
				    count($waiting) . " live order(s) have been out for " . round($oldest / 60)
				  . " minutes without being resolved, the oldest being "
				  . $waiting[0]['client_order_id']);
				$out['halted'] = true;
				$out['errors'][] = "halted: a live order has been unresolved for "
				                 . round($oldest / 60) . " minutes";
				return $out;
			}
			if ($oldest >= (int) T212_SETTLE_SECONDS) {
				$out['errors'][] = count($waiting) . " live order(s) sent earlier are still"
				                 . " unresolved. Nothing more is sent until the broker has"
				                 . " been asked what became of them.";
				return $out;
			}
			$say(count($waiting) . " order(s) are still settling, which is normal for the"
			     . " first " . (int) T212_SETTLE_SECONDS . " seconds");
		}

		/* And nothing goes out without this strategy's own positions having
		   been compared with the broker's. On a live account that means
		   what it bought: Dale's own holdings are not a difference, and a
		   shortfall in one of ours is. */
		$state = broker_reconciliation_state($strategy, $mode['broker'], $mode['environment']);
		if (!$state['compared']) {
			if (!$state['first_build']) {
				$out['errors'][] = $state['why'] . ", so the two books could not be compared"
				                 . " and nothing was sent.";
				return $out;
			}
			$say($state['why'] . ", and this strategy holds nothing and has never had a fill,"
			     . " so there is nothing to compare: sending the first build");
		}
		else {
			$check = broker_reconcile_positions($strategy);
			if ($check['halted']) {
				$out['halted'] = true;
				$out['errors'][] = "the broker holds less than this strategy bought, so nothing"
				                 . " was sent: " . implode('; ', array_slice($check['differences'], 0, 3));
				return $out;
			}
			foreach ($check['extra'] as $extra) { $say("not ours: " . $extra); }
		}

		$orders = query("select * from strategy_orders where strategy=? and status='PENDING'
		                  order by case when side='SELL' then 0 else 1 end, created_at",
		                $strategy);

		if (!count($orders)) { $say("nothing to send"); return $out; }

		/* How many orders were refused for breaching a cap on their own
		   size. Two in one run is not bad luck: the sizing is wrong, and
		   the right answer is to stop and let somebody look.

		   A run or day limit is not in this count. Reaching the GBP 150 a
		   run may send, or the day's allowance, is the limit working as
		   intended on orders that are each the right size. */
		$sizing_breaches = 0;

		foreach ($orders as $order) {

			/* Re-checked per order, so dropping the kill switch stops a run
			   part way through. */
			if (strategy_halted()) {
				$out['halted'] = true;
				$say("HALTED mid-run");
				break;
			}

			$id = (string) $order['client_order_id'];

			/* The batch. An unapproved batch is not sent, and the dry run
			   on the page asks the other question - what would go if it
			   were approved - which is why if_approved exists and why it
			   is only honoured when nothing is being written. */
			$approved = broker_live_order_approved($order);
			if (!$approved && !($dry_run && !empty($options['if_approved']))) {
				$say("skip " . $id . ": its batch has not been approved");
				$out['skipped']++;
				continue;
			}

			$quantity = (float) $order['quantity'];
			$price    = strategy_price_asof((string) $order['symbol'], (string) $order['exchange'],
			                                (string) $order['as_of_date']);
			$value    = ($price === null) ? 0.0 : $quantity * (float) $price;

			/* Every cap, with what this run has already sent. A buy that
			   breaches one is refused with the reason and left exactly as
			   it was: PENDING, so the next run can send it when the day,
			   the run or the book has room. */
			$cap = broker_live_cap_check($strategy, $order,
			           ['value' => $value, 'run_value' => $out['value'],
			            'day' => isset($options['day']) ? $options['day'] : null]);

			if (!$cap['ok']) {

				$key = isset($cap['key']) ? $cap['key'] : 'caps';
				$out['held_back']++;
				$say("held back " . $id . ": " . $cap['why']);

				if (!$dry_run) {
					broker_live_log($strategy, 'CAP_REFUSED', null, $id . ": " . $cap['why']);
				}

				/* The whole run is over for a run or day limit: every order
				   after this one would be refused for the same reason. */
				if (in_array($key, ['per_run', 'per_day', 'orders_per_day'], true)) {
					$out['errors'][] = $cap['why'] . " The rest are left for the next run.";
					break;
				}

				$sizing_breaches++;
				if ($sizing_breaches >= 2 && !$dry_run) {
					broker_halt_strategy($strategy,
					    "two orders in one run were over a cap on their own size, so the"
					  . " sizing is wrong rather than the cap being tight: " . $cap['why']);
					$out['halted'] = true;
					$out['errors'][] = "halted: two orders in one run breached a size cap.";
					break;
				}
				continue;
			}

			$ticker = broker_ticker_for((string) $order['symbol'], $mode['broker'],
			                            $mode['environment']);
			if ($ticker === null) {
				$why = "the live account lists no instrument for " . $order['symbol'];
				if ($dry_run) { $say("skip " . $order['symbol'] . ": " . $why); $out['skipped']++; continue; }
				broker_order_status($id, 'REJECTED', $why);
				broker_live_log($strategy, 'REFUSED', null, $id . ": " . $why);
				$out['refused']++;
				continue;
			}

			$say(sprintf("%-4s %-8s %10s %s (%s)", $order['side'], $order['symbol'],
			             rtrim(rtrim(number_format($quantity, 8, '.', ''), '0'), '.'),
			             $ticker, round($value, 2)));

			if ($dry_run) {
				/* Counted against the run cap as a real send would be, so a
				   dry run shows what ONE run would do rather than listing
				   every order as if all of them would go. */
				$out['value'] += $value;
				$out['skipped']++;
				$out['would_send'][] = ['client_order_id' => $id, 'side' => (string) $order['side'],
				                        'symbol' => (string) $order['symbol'],
				                        'quantity' => $quantity, 'value' => round($value, 2),
				                        'approved' => $approved];
				continue;
			}

			/* SENT, with the time, BEFORE the call. If this process dies in
			   the next second the order is on the record as having been
			   sent, and the next run asks the broker what became of it
			   rather than sending it again. */
			query("update strategy_orders set status='SENT', broker=?, sent_at=?,
			              attempts=attempts+1 where client_order_id=?",
			      $mode['broker'], date('Y-m-d H:i:s'), $id);

			$answer = broker_live_place_order($strategy, $order,
			              ['ticker' => $ticker, 'value' => $value, 'run_value' => $out['value'],
			               'ignore_time' => !empty($options['ignore_time']),
			               'day' => isset($options['day']) ? $options['day'] : null]);

			$out['value'] += $value;

			if ($answer['ok']) {

				$broker_id = null;
				foreach (['id', 'orderId', 'reference'] as $field) {
					if (isset($answer['data'][$field])) { $broker_id = (string) $answer['data'][$field]; break; }
				}
				[$broker_status] = broker_field($answer['data'], ['status', 'state']);

				broker_order_status($id, 'SENT',
				    "sent to the live account as "
				  . ($broker_id === null ? '(no id given)' : $broker_id),
				    ['broker_order_id' => $broker_id, 'broker_status' => $broker_status,
				     'broker' => $mode['broker']]);

				/* In the live log as well as the order's own row: the log is
				   the record of what real money did, and it is only ever
				   added to. */
				broker_live_log($strategy, 'SENT', null,
				                $id . " " . $order['side'] . " " . $order['symbol'] . " "
				              . rtrim(rtrim(number_format($quantity, 8, '.', ''), '0'), '.')
				              . " at about " . round($value, 2)
				              . " as " . ($broker_id === null ? '(no id given)' : $broker_id));

				$out['sent']++;
				continue;
			}

			$kind = isset($answer['kind']) ? $answer['kind'] : 'refused';

			if ($kind === 'unknown') {
				/* The dangerous one: it may or may not have arrived. Not
				   resent, not written off - resolved against the broker's
				   own records by the next run. */
				broker_order_status($id, 'UNKNOWN', $answer['message'],
				                    ['broker' => $mode['broker']]);
				$out['unknown']++;
				continue;
			}

			if (in_array($kind, ['rate_limited', 'not_configured', 'refused',
			                     'unauthorised', 'forbidden'], true)) {
				/* Not sent, and the reason will be the same for every other
				   order in the queue, so the run stops and the order stays
				   exactly as it was. */
				broker_order_status($id, 'PENDING', $order['note']);
				$out['errors'][] = $answer['message'];
				break;
			}

			/* A refusal of this order in particular. If the broker is
			   objecting to the quantity, the share does not deal in
			   fractions: remember that, so the next plan sizes it in whole
			   shares rather than being refused again. */
			if (preg_match('~quantity|fraction|decimal|lot size|whole~i', (string) $answer['message'])) {
				query("update broker_instruments set whole_shares='Y'
				        where broker=? and environment=? and symbol=?",
				      $mode['broker'], $mode['environment'], $order['symbol']);
				$say($order['symbol'] . " does not deal in fractions; it will be sized in"
				     . " whole shares from now on");
			}

			broker_order_status($id, 'REJECTED', $answer['message']);
			broker_live_log($strategy, 'REFUSED', null, $id . ": " . $answer['message']);
			$out['refused']++;
		}

		if (!$dry_run) {
			write_log("broker_live", $strategy . ": sent=" . $out['sent']
			          . " refused=" . $out['refused'] . " unknown=" . $out['unknown']
			          . " held back=" . $out['held_back'] . " waiting on an approval="
			          . $out['skipped'] . " value=" . round($out['value'], 2));
		}

		return $out;
	}

	/**
	 * What would be sent if the batch were approved, for the page's dry run.
	 *
	 * Writes nothing: the sender's own dry run, with the approval assumed
	 * and the clock ignored, because the question the page is asking is
	 * "what is in this batch and would any of it go", not "would it go in
	 * the next ten seconds".
	 */
	function broker_live_preview($strategy) {

		$run = broker_live_send_orders($strategy,
		           ['dry_run' => true, 'if_approved' => true, 'ignore_time' => true]);

		$state = broker_live_state($strategy);

		return [
			'armed'      => ($state === null) ? false : $state['armed'],
			'words'      => ($state === null) ? '' : $state['words'],
			'approval'   => ($state === null) ? true : $state['approval'],
			'waiting'    => ($state === null) ? false : $state['waiting'],
			'conditions' => ($state === null) ? [] : $state['conditions']['failed'],
			'would_send' => $run['would_send'],
			'value'      => round($run['value'], 2),
			'held_back'  => $run['held_back'],
			'lines'      => $run['lines'],
			'errors'     => $run['errors'],
		];
	}

/* No closing tag: see the note at the end of constants.php. */
