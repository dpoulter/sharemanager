<?php

	/**
	 * Send the strategies' orders to Trading 212's DEMO account, while
	 * London is open.
	 *
	 *   php includes/send_broker_orders.php [strategy] [--dry-run] [--any-time]
	 *
	 * From cron, every ten minutes through the session (the minute field is
	 * written out rather than as a step, because a step in a block comment
	 * ends the comment):
	 *
	 *   0,10,20,30,40,50 8-16 * * 1-5 \
	 *     /usr/bin/php /var/www/sharemanager/includes/send_broker_orders.php
	 *
	 * DEMO ONLY. This refuses to run unless SM_T212_ENV=demo, and the order
	 * call underneath it refuses any host but the demo one. Real money stays
	 * off until Dale decides, after at least three months of paper results.
	 *
	 * Three jobs, in this order:
	 *
	 *   1. resolve what is already out there - fills, refusals, and the
	 *      orders whose answer never arrived - because sending more while
	 *      the last lot is unaccounted for is how a position gets doubled;
	 *   2. reconcile the engine's book against the broker's, and halt the
	 *      strategy if they disagree;
	 *   3. send what is still PENDING.
	 *
	 * The nightly run plans; this sends. A market order queued overnight
	 * fills at whatever the open does, which is not the price anything was
	 * decided at.
	 *
	 * Exit codes: 0 did what it could, 1 something was halted or failed, 2
	 * it was not asked properly.
	 */

	require_once("constants.php");
	require_once("functions.php");
	require_once("share_functions.php");
	require_once("broker_orders.php");

	log_job("send_broker_orders");

	$only = null; $dry_run = false; $any_time = false;

	foreach (array_slice($argv, 1) as $argument) {
		if ($argument === '--dry-run') { $dry_run = true; continue; }
		if ($argument === '--any-time') { $any_time = true; continue; }
		if (strpos($argument, '--') === 0) {
			fwrite(STDERR, "Usage: php includes/send_broker_orders.php [strategy]"
			             . " [--dry-run] [--any-time]\r\n");
			exit(2);
		}
		$only = $argument;
	}

	$exchange = getenv("SM_EXCHANGE") ?: default_exchange();
	$_SESSION["exchange"] = $exchange;

	if (!broker_trading_ready()) {
		echo "send_broker_orders: sql/broker_trading.sql has not been applied\r\n";
		exit(1);
	}

	$client = new T212Client();

	if ($client->environment() !== 'demo') {
		echo "send_broker_orders: SM_T212_ENV is '" . $client->environment() . "'."
		   . " Orders are only ever sent to the demo account, so there is nothing to do.\r\n";
		exit(0);
	}
	if (!$client->configured()) {
		echo "send_broker_orders: " . $client->why_not() . "\r\n";
		exit(0);
	}

	/* Said once here as well as checked inside, because a person running this
	   by hand at nine in the evening should be told why nothing happened. */
	if (!$any_time && !broker_market_open()) {
		echo "send_broker_orders: London is shut, so nothing was sent."
		   . " The session is " . T212_MARKET_OPEN . " to " . T212_MARKET_SHUT
		   . " " . T212_MARKET_TZ . ".\r\n";
		/* Resolving is still worth doing: a fill that happened at half past
		   three is a fill whether or not the market is open now. */
	}

	$failed = false;

	foreach (strategy_strategies() as $strategy) {

		if ($only !== null && $strategy !== $only) { continue; }

		$account = strategy_account($strategy);
		if ($account === null) { continue; }

		$mode = strategy_mode_rules($account['mode']);
		if ($mode['broker'] === null) { continue; }

		echo "\r\n--- " . $strategy . " (" . $mode['words'] . ") ---\r\n";

		/* 1. What became of what has already gone. */
		$resolved = broker_resolve_orders($strategy, ['client' => $client]);
		echo "  resolved: " . $resolved['filled'] . " filled, " . $resolved['rejected']
		   . " refused, " . $resolved['cancelled'] . " cancelled, " . $resolved['working']
		   . " still working, " . $resolved['adopted'] . " matched up, "
		   . $resolved['written_off'] . " never arrived\r\n";
		foreach ($resolved['lines'] as $line)  { echo "    " . $line . "\r\n"; }
		foreach ($resolved['errors'] as $error) { echo "    ! " . $error . "\r\n"; $failed = true; }

		if ($resolved['halted']) {
			echo "    HALTED. A person has to look at this one.\r\n";
			$failed = true;
			continue;
		}

		/* 2. Do the two sides agree about what is held? */
		$check = broker_reconcile_positions($strategy);
		foreach ($check['differences'] as $difference) { echo "    ! " . $difference . "\r\n"; }
		if ($check['halted']) {
			echo "    HALTED: the broker and this application disagree about the book.\r\n";
			$failed = true;
			continue;
		}

		/* 3. Send what is waiting. */
		$sent = broker_send_orders($strategy,
		                           ['client' => $client, 'dry_run' => $dry_run,
		                            'ignore_time' => $any_time]);

		foreach ($sent['lines'] as $line)   { echo "    " . $line . "\r\n"; }
		foreach ($sent['errors'] as $error) { echo "    ! " . $error . "\r\n"; }

		echo "  sent " . $sent['sent'] . ", refused " . $sent['rejected']
		   . ", unknown " . $sent['unknown']
		   . ($dry_run ? ", " . $sent['skipped'] . " not sent (dry run)" : "")
		   . ", value " . round($sent['value'], 2) . "\r\n";

		if ($sent['halted'] || count($sent['errors'])) { $failed = true; }
	}

	exit($failed ? 1 : 0);
