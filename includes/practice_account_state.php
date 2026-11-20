<?php

	/**
	 * Who holds the single Trading 212 practice account, and what is left on
	 * it.
	 *
	 * The ONLY place that decides who holds the practice account (the one
	 * strategy whose practice account is switched on), which shares at the
	 * broker belong to no running strategy, and what the account is worth. The
	 * stage, practice_start and practice_clear all ask here; none of them
	 * keeps a copy of any of it.
	 *
	 * Archive and Stop practice never sell, so a retired practice strategy
	 * leaves its shares at the broker, and the next strategy started on the
	 * single account inherits them. This is how that is seen.
	 *
	 * Only the practice account (environment 'demo') is read, and from the
	 * broker's own figures as the last sync stored them: the positions, and
	 * the account value. If the last sync did not finish cleanly, or there has
	 * never been one, the answer says it is unreadable and gives no shares and
	 * no value - it does not guess, and an empty list is never the way of
	 * saying "I could not look".
	 *
	 * Reads only. It never writes, sends, plans or records an order, and it
	 * does not touch the live host.
	 */

	require_once("constants.php");
	require_once(__DIR__ . "/strategy_functions.php");

	/**
	 * The strategy whose practice account is switched on, other than $except,
	 * or null. One strategy per practice account for now: the account is
	 * single and reconciliation covers the whole of it.
	 */
	function practice_account_holder($except = null) {

		foreach (query("select strategy from strategy_accounts where mode=? and enabled='Y'
		                 order by strategy", STRATEGY_MODE_T212_DEMO) as $row) {
			if (strategy_reserved($row['strategy'])) { continue; }
			if ($except !== null && $row['strategy'] === (string) $except) { continue; }
			return (string) $row['strategy'];
		}
		return null;
	}

	/**
	 * The state of the practice account.
	 *
	 * Returns:
	 *   holder    the strategy it is switched on for, or null
	 *   readable  whether the broker's figures could be read (false: leftover
	 *             and value are null, and why says so)
	 *   why       when it is not readable
	 *   as_of     the date of the positions read, and fresh: whether that is
	 *             today
	 *   value     the account's total at the broker, and cash
	 *   leftover  null when unreadable, else a list of the shares at the
	 *             broker that no running strategy holds. Each has symbol (null
	 *             if the broker's share is not matched to one of ours),
	 *             ticker, quantity, value, tied (the strategy that last held
	 *             it, or null), tied_quantity (how much of it that strategy
	 *             recorded) and exchange.
	 */
	function practice_account_state() {

		$out = ['holder' => practice_account_holder(), 'readable' => false, 'why' => '',
		        'as_of' => null, 'fresh' => false, 'value' => null, 'cash' => null, 'leftover' => null];

		$run = query("select status, as_of_date from broker_sync_runs
		               where broker=? and environment='demo' order by run_id desc limit 1", BROKER_T212);
		if (!count($run)) {
			$out['why'] = "the practice account has never been read";
			return $out;
		}
		if ((string) $run[0]['status'] !== 'OK') {
			$out['why'] = "the last read of the practice account did not finish cleanly ("
			            . (string) $run[0]['status'] . ")";
			return $out;
		}

		$latest = query("select max(as_of_date) d from broker_positions where broker=? and environment='demo'", BROKER_T212);
		$as_of = (count($latest) && $latest[0]['d'] !== null) ? (string) $latest[0]['d'] : null;
		if ($as_of === null || ($run[0]['as_of_date'] !== null && (string) $run[0]['as_of_date'] > $as_of)) {
			$as_of = $run[0]['as_of_date'] === null ? $as_of : (string) $run[0]['as_of_date'];
		}

		$out['readable'] = true;
		$out['as_of'] = $as_of;
		$out['fresh'] = ($as_of === date('Y-m-d'));

		$values = query("select total, cash from broker_account_values
		                  where broker=? and environment='demo' order by as_of_date desc limit 1", BROKER_T212);
		if (count($values)) {
			$out['value'] = $values[0]['total'] === null ? null : (float) $values[0]['total'];
			$out['cash']  = $values[0]['cash'] === null ? null : (float) $values[0]['cash'];
		}

		/* What the running strategy holds is not leftover. */
		$running = [];
		if ($out['holder'] !== null) {
			foreach (strategy_positions($out['holder']) as $symbol => $position) {
				$running[(string) $symbol] = (float) $position['quantity'];
			}
		}

		$out['leftover'] = [];
		foreach (query("select broker_ticker, symbol, quantity, value from broker_positions
		                 where broker=? and environment='demo' and as_of_date=? and quantity>0
		                 order by broker_ticker", BROKER_T212, $as_of) as $row) {

			$symbol = $row['symbol'] === null ? null : (string) $row['symbol'];
			$left = (float) $row['quantity'] - ($symbol !== null && isset($running[$symbol]) ? $running[$symbol] : 0.0);
			if ($left < 0.00000001) { continue; }

			/* The strategy that last held it: a practice strategy that is not
			   the running one, with a recorded position in that share, the
			   most recently updated. */
			$tied = null; $tied_quantity = 0.0; $exchange = 'XLON';
			if ($symbol !== null) {
				$holders = query("select p.strategy, p.quantity, p.exchange from strategy_positions p, strategy_accounts a
				                   where p.symbol=? and p.quantity>0 and a.strategy=p.strategy and a.mode=?
				                   order by p.updated_at desc", $symbol, STRATEGY_MODE_T212_DEMO);
				foreach ($holders as $h) {
					if (strategy_reserved($h['strategy'])) { continue; }
					if ($out['holder'] !== null && $h['strategy'] === $out['holder']) { continue; }
					$tied = (string) $h['strategy'];
					$tied_quantity = (float) $h['quantity'];
					$exchange = (string) $h['exchange'];
					break;
				}
			}

			$out['leftover'][] = ['symbol' => $symbol, 'ticker' => (string) $row['broker_ticker'],
			                      'quantity' => $left,
			                      'value' => $row['value'] === null ? null : (float) $row['value'] * ($left / (float) $row['quantity']),
			                      'tied' => $tied, 'tied_quantity' => $tied_quantity, 'exchange' => $exchange];
		}

		return $out;
	}

/* No closing tag: see the note at the end of constants.php. */
