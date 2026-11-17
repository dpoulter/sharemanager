<?php

	/**
	 * Read the broker account and write down what it said.
	 *
	 *   php includes/sync_broker.php                 the nightly sync
	 *   php includes/sync_broker.php --instruments   refresh the mapping too
	 *
	 * Run from refresh_all.sh after the strategies, and by the Sync now
	 * button on the broker page. Both call broker_sync(), so there is one
	 * answer to what a sync does rather than two.
	 *
	 * READ ONLY. Four GETs - the account summary, the positions, the order
	 * history and the dividend history, plus the instrument list once a week.
	 * Nothing in this path can place, amend or cancel an order, in either
	 * environment.
	 *
	 * With SM_T212_ENV=off, or no key, it prints why and exits 0 without
	 * opening a socket: a host nobody has given a key to is the normal state
	 * of this feature, not a failure of the nightly run.
	 *
	 * Exit codes: 0 did what it could, 1 nothing could be read, 2 it was not
	 * asked properly.
	 */

	require_once("constants.php");
	require_once("functions.php");
	require_once("share_functions.php");
	require_once("broker.php");

	log_job("sync_broker");

	$options = [];
	foreach (array_slice($argv, 1) as $argument) {
		if ($argument === '--instruments') { $options['instruments'] = true; continue; }
		fwrite(STDERR, "Usage: php includes/sync_broker.php [--instruments]\r\n");
		exit(2);
	}

	$exchange = getenv("SM_EXCHANGE") ?: default_exchange();
	$_SESSION["exchange"] = $exchange;
	$options['exchange'] = $exchange;

	$result = broker_sync($options);

	if (!$result['ran']) {
		/* Off, or not configured, or the migration has not been applied. Said
		   once, plainly, and not as an error: nothing is wrong. */
		echo "sync_broker: " . $result['message'] . "\r\n";
		exit($result['ok'] ? 0 : 1);
	}

	echo "sync_broker: status=" . $result['status']
	   . " positions=" . $result['positions']
	   . " cash=" . ($result['cash'] === null ? '-' : $result['cash'])
	   . " new orders=" . $result['new_orders']
	   . " new dividends=" . $result['new_dividends']
	   . " price unit=" . ($result['price_unit'] === null ? 'unknown' : $result['price_unit'])
	   . " unmatched symbols=" . $result['unmatched']
	   . " requests=" . $result['requests'] . "\r\n";

	foreach ($result['problems'] as $problem) { echo "  ! " . $problem . "\r\n"; }

	exit($result['status'] === 'FAILED' ? 1 : 0);
