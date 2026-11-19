<?php

	/**
	 * Practice evidence: have these rules completed a clean rebalance on the
	 * practice account?
	 *
	 * The gate in front of arming (broker_live_arm asks here, on the server,
	 * whatever the browser sent) and the reason behind "Go live" on the stage
	 * stepper. YES only if, under rules with THE SAME FINGERPRINT as this
	 * strategy's:
	 *
	 *   - a rebalance on the practice account (mode T212_DEMO) has every one
	 *     of its orders resolved - none PENDING, SENT or UNKNOWN - and at
	 *     least one of them filled;
	 *   - it was planned after that strategy's rules row last changed;
	 *   - and the practice account now reconciles with the broker: no
	 *     difference, and no fill still waiting to be read back.
	 *
	 * The rebalance may be another strategy's with the same fingerprint
	 * (Dale's decision, 19 Nov 2026: the practice account is single, and the
	 * live strategy is the practice one's twin). A rebalance under other rules
	 * does not count, and the answer says which practice strategies were
	 * passed over for that reason.
	 *
	 * What it cannot see: a criterion of a screen edited AFTER a rebalance
	 * changes the fingerprint but leaves no date behind, so the rebalance is
	 * counted against the criteria as they are now. The practice strategy's
	 * own rules row carries a date; the screen's criteria do not.
	 *
	 * Reads only. It never sends, changes or clears anything, and it does not
	 * call the stage: the stage reads the arming, and arming asks here, so the
	 * other way round would be a cycle.
	 */

	require_once("constants.php");
	require_once(__DIR__ . "/strategy_functions.php");
	require_once(__DIR__ . "/rules_fingerprint.php");
	require_once(__DIR__ . "/broker_orders.php");

	/**
	 * Whether one practice strategy has a completed, clean rebalance under
	 * its present rules. Used by practice_evidence() and nothing else.
	 *
	 * Returns ['ok'=>bool, 'why'=>string, 'as_of'=>date, 'orders'=>int].
	 */
	function practice_evidence_rebalance($practice) {

		$no = function ($why) { return ['ok' => false, 'why' => $why, 'as_of' => null, 'orders' => 0]; };

		$account = strategy_account($practice);
		if ($account === null || strtoupper((string) $account['mode']) !== STRATEGY_MODE_T212_DEMO) {
			return $no("it has no practice account");
		}
		if ($account['halted'] === 'Y') { return $no("it is halted"); }

		$changed = query("select updated_at from strategy_rules where strategy=?", $practice);
		$changed = count($changed) ? (string) $changed[0]['updated_at'] : null;

		$live_now = query("select count(*) n from strategy_orders
		                    where strategy=? and status in ('PENDING','SENT','UNKNOWN')", $practice);
		if (count($live_now) && (int) $live_now[0]['n'] > 0) {
			return $no("it still has orders that are not resolved");
		}

		$found = null;
		foreach (query("select as_of_date, count(*) n, sum(status='FILLED') filled, min(created_at) first
		                  from strategy_orders where strategy=? and note='rebalance'
		                 group by as_of_date order by as_of_date desc", $practice) as $day) {
			if ((int) $day['filled'] < 1) { continue; }
			if ($changed !== null && strtotime((string) $day['first']) < strtotime($changed)) { continue; }
			$found = $day;
			break;
		}
		if ($found === null) {
			return $no("it has no rebalance with a filled order since its rules last changed");
		}

		/* Reconciled with the broker: the last read, whatever day it was, and
		   no fill that has not been read back. Reported, never acted on. */
		$check = broker_reconcile_positions($practice, ['report_only' => true, 'any_date' => true]);
		if ($check['as_of'] === null) { return $no("the broker's positions have never been read"); }
		if (count($check['differences'])) {
			return $no("it does not agree with the broker: " . $check['differences'][0]);
		}
		if (count($check['waiting'])) {
			return $no("a fill is not read back yet: " . $check['waiting'][0]);
		}

		return ['ok' => true, 'why' => '', 'as_of' => (string) $found['as_of_date'], 'orders' => (int) $found['n']];
	}

	/**
	 * Yes or no, with the reason, for one strategy.
	 *
	 * Returns ['ok'=>bool, 'why'=>string, 'strategy'=>the practice strategy
	 * whose rebalance counts, 'as_of'=>its date, 'orders'=>int].
	 */
	function practice_evidence($strategy) {

		$out = ['ok' => false, 'why' => '', 'strategy' => null, 'as_of' => null, 'orders' => 0];

		$mine = rules_fingerprint((string) $strategy);
		if ($mine === null) { $out['why'] = "it has no rules"; return $out; }

		$candidates = [];
		foreach (query("select a.strategy from strategy_accounts a, strategy_rules r
		                 where a.mode=? and r.strategy=a.strategy order by a.strategy",
		               STRATEGY_MODE_T212_DEMO) as $row) {
			if (!strategy_reserved($row['strategy'])) { $candidates[] = (string) $row['strategy']; }
		}
		if (!count($candidates)) {
			$out['why'] = "there is no practice strategy: it needs a clean rebalance on the practice account first";
			return $out;
		}

		$notes = [];
		$other = [];
		foreach ($candidates as $practice) {

			if (rules_fingerprint($practice) !== $mine) { $other[] = strategy_display_name($practice); continue; }

			$result = practice_evidence_rebalance($practice);
			if ($result['ok']) {
				return ['ok' => true, 'why' => '', 'strategy' => $practice,
				        'as_of' => $result['as_of'], 'orders' => $result['orders']];
			}
			$notes[] = strategy_display_name($practice) . ": " . $result['why'];
		}

		$parts = [];
		if (count($notes)) { $parts[] = implode('; ', $notes); }
		if (count($other)) {
			$parts[] = "practice strategy " . implode(', ', $other)
			         . " runs different rules (its fingerprint is not this strategy's), so it does not count";
		}
		$out['why'] = "no clean practice rebalance of these rules: " . implode('. ', $parts);
		return $out;
	}

/* No closing tag: see the note at the end of constants.php. */
