<?php

	/**
	 * Send the live account's approved orders, while London is open.
	 *
	 *   php includes/send_live_orders.php [strategy] [--dry-run] [--any-time]
	 *
	 * From cron, through includes/broker_run.sh and never through php
	 * directly: cron sees no profile and no php-fpm pool, so the switches
	 * and the key would both be unset and this would refuse every time it
	 * fired.
	 *
	 * A SEPARATE JOB from includes/send_broker_orders.php, deliberately.
	 * That one builds a demo client, refuses anything else and is covered by
	 * every stage 2 test; this one cannot send to the practice account at
	 * all. Two jobs, two keys, two hosts: neither can be made into the other
	 * by an argument.
	 *
	 * WHAT THIS DOES NOT DECIDE. It does not arm an account and it cannot:
	 * arming is a form on the strategy's page and includes/broker_live.php
	 * refuses the command line outright. It does not approve a batch either.
	 * All it does is send what Dale has already armed and approved, inside
	 * every cap, during the session.
	 *
	 * Three jobs, in this order, per live strategy:
	 *
	 *   1. resolve what is already out there - fills, refusals, and the
	 *      orders whose answer never arrived - and read the positions again
	 *      if anything was written up;
	 *   2. compare this strategy's own positions with the broker's, and halt
	 *      it if the broker holds less than it bought;
	 *   3. send what is still PENDING and approved.
	 *
	 * Exit codes: 0 did what it could, 1 something was halted or failed, 2
	 * it was not asked properly.
	 */

	require_once("constants.php");
	require_once("functions.php");
	require_once("share_functions.php");
	require_once("broker_live_send.php");

	log_job("send_live_orders");

	$only = null; $dry_run = false; $any_time = false;

	foreach (array_slice($argv, 1) as $argument) {
		if ($argument === '--dry-run') { $dry_run = true; continue; }
		if ($argument === '--any-time') { $any_time = true; continue; }
		if (strpos($argument, '--') === 0) {
			fwrite(STDERR, "Usage: php includes/send_live_orders.php [strategy]"
			             . " [--dry-run] [--any-time]\r\n");
			exit(2);
		}
		$only = $argument;
	}

	$exchange = getenv("SM_EXCHANGE") ?: default_exchange();
	$_SESSION["exchange"] = $exchange;

	if (!broker_trading_ready()) {
		echo "send_live_orders: sql/broker_trading.sql has not been applied\r\n";
		exit(1);
	}
	if (!broker_live_ready()) {
		echo "send_live_orders: sql/strategy_live.sql has not been applied, so no account"
		   . " can be armed and nothing can be sent\r\n";
		exit(1);
	}

	/* Off is the normal state of this feature, and it does not read like an
	   error. Said and done with: nothing below it runs. */
	if (!T212_LIVE_ORDERS_ON) {
		echo "send_live_orders: SM_T212_LIVE_ORDERS is off, so there is nothing to do.\r\n";
		exit(0);
	}
	if (trim((string) T212_LIVE_ORDERS_API_KEY) === ''
	    || trim((string) T212_LIVE_ORDERS_API_SECRET) === '') {
		echo "send_live_orders: SM_T212_LIVE_ORDERS is on and no live orders key is set."
		   . " Set SM_T212_LIVE_ORDERS_API_KEY and SM_T212_LIVE_ORDERS_API_SECRET, or turn"
		   . " the switch off.\r\n";
		exit(1);
	}

	$where = broker_live_host();
	if ($where['base'] === null) {
		echo "send_live_orders: " . $where['why'] . "\r\n";
		exit(1);
	}
	if ($where['stub']) {
		echo "send_live_orders: NOT TRADING 212 - SM_T212_LIVE_ORDERS_BASE_URL points this"
		   . " run at " . $where['host'] . "\r\n";
	}

	/* Said once here as well as checked inside, because a person running
	   this by hand at nine in the evening should be told why nothing
	   happened. Resolving is still worth doing: a fill that happened at
	   half past three is a fill whether or not the market is open now. */
	if (!$any_time && !broker_market_open()) {
		echo "send_live_orders: London is shut, so nothing will be sent. The session is "
		   . T212_MARKET_OPEN . " to " . T212_MARKET_SHUT . " " . T212_MARKET_TZ . ".\r\n";
	}

	$client = broker_live_client();
	$failed = false;
	$found  = 0;

	foreach (query("select strategy from strategy_accounts
	                 where mode=? and enabled='Y' order by strategy",
	               STRATEGY_MODE_T212_LIVE) as $row) {

		$strategy = (string) $row['strategy'];
		if ($only !== null && $strategy !== $only) { continue; }
		$found++;

		echo "\r\n--- " . $strategy . " (live) ---\r\n";
		echo "  " . broker_live_words($strategy) . "\r\n";

		/* 1. What became of what has already gone. The client is the orders
		   key when there is one, else the read-only live key (the line below
		   names which). Whether the orders key may read orders is learned here:
		   on 19 Nov 2026 it could not, and the resolve step says so. */
		if ($client->configured()) {
			$resolved = broker_resolve_orders($strategy, ['client' => $client]);
			echo "  resolved (" . $client->environment() . " key): " . $resolved['filled'] . " filled, " . $resolved['rejected']
			   . " refused, " . $resolved['cancelled'] . " cancelled, " . $resolved['working']
			   . " still working, " . $resolved['adopted'] . " matched up, "
			   . $resolved['written_off'] . " never arrived\r\n";
			foreach ($resolved['lines'] as $line)   { echo "    " . $line . "\r\n"; }
			foreach ($resolved['errors'] as $error) { echo "    ! " . $error . "\r\n"; $failed = true; }

			if ($resolved['halted']) {
				echo "    HALTED. A person has to look at this one.\r\n";
				$failed = true;
				continue;
			}

			/* Anything written up just now has to be read back before the
			   two books can be compared: a fill written after the positions
			   were read looks exactly like a difference. */
			if ($resolved['filled'] > 0 || $resolved['adopted'] > 0) {
				$reread = broker_sync(['client' => $client, 'exchange' => $exchange,
				                       'only' => ['positions']]);
				echo "  read the positions again after the fills: " . $reread['message'] . "\r\n";
				foreach ($reread['problems'] as $problem) { echo "    ! " . $problem . "\r\n"; }
			}
		}
		else {
			echo "  ! " . $client->why_not() . " Nothing already sent can be resolved, so"
			   . " nothing more will be sent either.\r\n";
			$failed = true;
			continue;
		}

		/* 2. Does the broker hold what this strategy bought? Extra holdings
		   of Dale's are not a difference; less of one of ours is. */
		$check = broker_reconcile_positions($strategy);
		foreach ($check['waiting'] as $waiting)        { echo "    . " . $waiting . "\r\n"; }
		foreach ($check['extra'] as $extra)            { echo "    . not ours: " . $extra . "\r\n"; }
		foreach ($check['differences'] as $difference) { echo "    ! " . $difference . "\r\n"; }
		if ($check['halted']) {
			echo "    HALTED: the broker holds less than this strategy bought.\r\n";
			$failed = true;
			continue;
		}

		/* 3. Send what is approved and waiting. */
		$sent = broker_live_send_orders($strategy,
		            ['dry_run' => $dry_run, 'ignore_time' => $any_time]);

		foreach ($sent['lines'] as $line)   { echo "    " . $line . "\r\n"; }
		foreach ($sent['errors'] as $error) { echo "    ! " . $error . "\r\n"; }

		echo "  sent " . $sent['sent'] . ", refused " . $sent['refused']
		   . ", unknown " . $sent['unknown']
		   . ", held back by a cap " . $sent['held_back']
		   . ", waiting on an approval " . $sent['skipped']
		   . ($dry_run ? " (dry run: nothing was sent)" : "")
		   . ", value " . round($sent['value'], 2) . "\r\n";

		if ($sent['halted'] || count($sent['errors'])) { $failed = true; }
	}

	if ($found === 0) {
		echo "send_live_orders: there are no live accounts"
		   . ($only === null ? "" : " called " . $only) . "\r\n";
	}

	exit($failed ? 1 : 0);

/* No closing tag: see the note at the end of constants.php. */
