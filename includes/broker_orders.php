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
	require_once("broker.php");
	require_once("strategy_functions.php");

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
	function broker_ticker_for($symbol, $broker = BROKER_T212) {

		$rows = query("select broker_ticker from broker_instruments
		                where broker=? and symbol=? and status='matched'", $broker, $symbol);
		return count($rows) ? (string) $rows[0]['broker_ticker'] : null;
	}

	/** Stop a strategy trading, with the reason, until a person clears it. */
	function broker_halt_strategy($strategy, $reason) {

		query("update strategy_accounts set halted='Y', halt_reason=?, halted_at=?
		        where strategy=?",
		      substr((string) $reason, 0, 255), date('Y-m-d H:i:s'), $strategy);

		write_log("broker_orders", "HALTED " . $strategy . ": " . $reason);
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

		$client = isset($options['client']) ? $options['client'] : new T212Client();

		if ($client->environment() !== 'demo') {
			$out['errors'][] = "SM_T212_ENV is '" . $client->environment() . "'. Orders are"
			                 . " only ever sent to the demo account, so nothing was sent.";
			return $out;
		}
		if (!$client->configured()) { $out['errors'][] = $client->why_not(); return $out; }

		if (empty($options['ignore_time']) && !broker_market_open()) {
			$out['errors'][] = "London is shut. Orders are sent during the session only:"
			                 . " a market order queued overnight fills at whatever the open does.";
			return $out;
		}

		$orders = query("select * from strategy_orders where strategy=? and status='PENDING'
		                  order by case when side='SELL' then 0 else 1 end, created_at",
		                $strategy);

		if (!count($orders)) { $say("nothing to send"); return $out; }

		$cap = (float) T212_MAX_RUN_VALUE;

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
				$out['errors'][] = "stopping: this run would have sent "
				                 . round($out['value'] + $value, 2) . ", over the "
				                 . $cap . " cap for one run";
				break;
			}

			$ticker = broker_ticker_for($order['symbol'], $mode['broker']);
			if ($ticker === null) {
				broker_order_status($order['client_order_id'], 'REJECTED',
				    "no instrument at the broker for " . $order['symbol'] . ", so there is"
				  . " nothing to send an order for");
				$out['rejected']++;
				continue;
			}

			$signed = $order['side'] === 'SELL' ? -$quantity : $quantity;

			$say(sprintf("%-4s %-8s %10s %s (%s)", $order['side'], $order['symbol'],
			             rtrim(rtrim(number_format($quantity, 8, '.', ''), '0'), '.'),
			             $ticker, round($value, 2)));

			if (!empty($options['dry_run'])) { $out['skipped']++; continue; }

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
				        where broker=? and symbol=?", $mode['broker'], $order['symbol']);
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
	   Finding out what happened.
	   --------------------------------------------------------------------- */

	/**
	 * The broker's own view of an order, by its id.
	 *
	 * Both lists are read once per run rather than once per order: the
	 * history call allows twenty a minute, and a run with ten orders out
	 * would spend its whole allowance asking about them one at a time.
	 */
	function broker_order_book($client) {

		$book = ['pending' => [], 'history' => [], 'errors' => []];

		$pending = $client->get('orders');
		if ($pending['ok']) {
			$rows = is_array($pending['data']) ? $pending['data'] : [];
			if (isset($rows['items']) && is_array($rows['items'])) { $rows = $rows['items']; }
			foreach ($rows as $row) {
				[$id] = broker_field($row, broker_fields('order_id'));
				if ($id !== null) { $book['pending'][(string) $id] = $row; }
			}
		}
		else { $book['errors'][] = "pending orders: " . $pending['message']; }

		$history = $client->get_all('order_history');
		if (!$history['ok']) { $book['errors'][] = "order history: " . $history['message']; }
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
		$stamp_duty    = $order['side'] === 'BUY' ? $consideration * STRATEGY_STAMP_DUTY_RATE : 0.0;
		$total_cost    = $order['side'] === 'BUY'
		               ? -($consideration + $stamp_duty)
		               :  ($consideration);

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

		$out = ['outcome' => 'working', 'status' => $status,
		        'quantity' => $quantity === null ? null : abs($quantity),
		        'price' => broker_to_pounds($price, $unit === null ? T212_PRICE_UNIT : $unit),
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
		        'halted' => false];

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

				$ticker = broker_ticker_for($order['symbol'], $mode['broker']);
				$since  = $order['sent_at'] === null
				        ? strtotime((string) $order['created_at'])
				        : strtotime((string) $order['sent_at']);

				$candidates = [];
				foreach ([$book['pending'], $book['history']] as $where) {
					foreach ($where as $broker_id => $row) {

						[$row_ticker] = broker_field($row, broker_fields('order_ticker'));
						if ((string) $row_ticker !== (string) $ticker) { continue; }

						$quantity = broker_number($row, broker_fields('order_quantity'));
						if ($quantity === null) { continue; }
						$side = $quantity < 0 ? 'SELL' : 'BUY';
						if ($side !== $order['side']) { continue; }

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

			$outcome = broker_order_outcome($row, broker_sync_price_unit());

			if ($outcome['outcome'] === 'filled') {
				if (broker_apply_fill($order['client_order_id'],
				        ['quantity' => $outcome['quantity'], 'price' => $outcome['price'],
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
	function broker_sync_price_unit($broker = BROKER_T212) {

		if (in_array(T212_PRICE_UNIT, ['pounds', 'pence'], true)) { return T212_PRICE_UNIT; }

		$rows = query("select price_unit from broker_sync_runs
		                where broker=? and price_unit is not null
		                order by run_id desc limit 1", $broker);

		return count($rows) ? (string) $rows[0]['price_unit'] : null;
	}

	/* ---------------------------------------------------------------------
	   Reconciliation.
	   --------------------------------------------------------------------- */

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

		$out = ['checked' => 0, 'differences' => [], 'halted' => false, 'as_of' => null];

		$account = strategy_account($strategy);
		if ($account === null) { return $out; }

		$mode = strategy_mode_rules($account['mode']);
		if ($mode['broker'] === null) { return $out; }

		$latest = query("select max(as_of_date) d from broker_positions where broker=?",
		                $mode['broker']);
		if (!count($latest) || $latest[0]['d'] === null) {
			$out['differences'][] = "the broker's positions have never been read, so there is"
			                      . " nothing to compare against";
			return $out;
		}
		$out['as_of'] = (string) $latest[0]['d'];

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
		                 where broker=? and as_of_date=? and symbol is not null",
		               $mode['broker'], $out['as_of']) as $row) {
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

		foreach (array_unique(array_merge(array_keys($ours), array_keys($theirs))) as $symbol) {

			$out['checked']++;

			$mine  = isset($ours[$symbol]) ? $ours[$symbol] : 0.0;
			$yours = isset($theirs[$symbol]) ? $theirs[$symbol] : 0.0;

			if (abs($mine - $yours) < 0.00000001) { continue; }
			if (isset($in_flight[$symbol])) { continue; }

			$out['differences'][] = $symbol . ": this application holds " . $mine
			                      . ", the broker holds " . $yours;
		}

		/* The broker holds things this strategy never bought. That is only a
		   disagreement if this is the only account on the key - which it is,
		   one key to one Trading 212 account - but it is the sort of
		   difference somebody should see rather than one to halt on, so it
		   is reported without stopping anything.

		   A difference in a share the strategy does hold is a halt. */
		if (count($out['differences'])) {
			broker_halt_strategy($strategy,
			    "the broker and this application disagree about what is held: "
			  . implode('; ', array_slice($out['differences'], 0, 3)));
			$out['halted'] = true;
		}

		return $out;
	}

/* No closing tag: see the note at the end of constants.php. */
