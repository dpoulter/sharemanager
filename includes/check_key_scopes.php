<?php

	/**
	 * What each live Trading 212 key can read, said at the start of a broker run.
	 *
	 *   php includes/check_key_scopes.php
	 *
	 * From includes/broker_run.sh, first. For live-read and live-orders, where
	 * the environment is switched on and its key is set, it makes the two GETs
	 * the order resolve step relies on - the pending orders and the order
	 * history - through key_scopes() in t212_client.php, stores the answer and
	 * prints it. The Strategies page shows the stored answer as "key lacks
	 * Orders - Read" or "key lacks history".
	 *
	 * Nothing here can place, amend or cancel an order, or arm or approve
	 * anything. An environment that is off, or has no key, opens no connection
	 * and writes nothing. It never fails the job: a key that cannot be checked
	 * is reported, and the run goes on.
	 *
	 * Exit code: always 0, except 2 if it was not asked properly.
	 */

	require_once("constants.php");
	require_once("functions.php");
	require_once("share_functions.php");
	require_once(__DIR__ . "/broker.php");

	log_job("check_key_scopes");

	if (count($argv) > 1) {
		fwrite(STDERR, "Usage: php includes/check_key_scopes.php\r\n");
		exit(2);
	}

	foreach (['live-read', 'live-orders'] as $environment) {

		$result = key_scopes($environment);

		if (!$result['checked']) {
			echo "key scopes: " . $environment . ": not checked (" . $result['why'] . ")\r\n";
			continue;
		}

		broker_key_scopes_record($result);

		echo "key scopes: " . $environment
		   . ": orders " . $result['orders']['status']
		   . ", history " . $result['history']['status']
		   . " - " . $result['words'] . "\r\n";

		write_log("key_scopes", $environment . ": " . $result['words']);
	}

	exit(0);

/* No closing tag: see the note at the end of constants.php. */
