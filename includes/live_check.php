<?php

	/**
	 * live_check.php - what a live account could and could not do, right now.
	 *
	 *   php includes/live_check.php [strategy]
	 *
	 * READS AND REPORTS. It arms nothing, approves nothing, sends nothing
	 * and writes nothing: it is the answer to "is this account armed, what
	 * are its caps, and what is standing between it and an order", for
	 * whoever has just deployed and wants to see that the answer is the one
	 * they expected.
	 *
	 * Written for the line in the brief that says Jarvis deploys, tests and
	 * reports - and does not arm. There is nothing here that could: arming
	 * is a form on the strategy's page, and includes/broker_live.php refuses
	 * the command line outright.
	 *
	 * Exit codes: 0 it said what it found, 2 the tables are not there.
	 */

	require_once("constants.php");
	require_once("functions.php");
	require_once("share_functions.php");
	require_once("broker_live.php");

	$only = null;
	foreach (array_slice($argv, 1) as $argument) {
		if (strpos($argument, '--') === 0) {
			fwrite(STDERR, "Usage: php includes/live_check.php [strategy]\r\n");
			exit(2);
		}
		$only = $argument;
	}

	$_SESSION["exchange"] = getenv("SM_EXCHANGE") ?: default_exchange();

	echo "live orders: SM_T212_LIVE_ORDERS is "
	   . (T212_LIVE_ORDERS_ON ? "on" : "off")
	   . ", its own key is " . (T212_LIVE_ORDERS_API_KEY === '' ? "not set" : "set")
	   . "\r\n";

	/* Said every time, because it is the most important sentence here until
	   stage C exists: the conditions below are the gate, and the door is not
	   built. */
	echo "this application has no live order path yet: an armed, approved,"
	   . " within-caps live account still has its orders planned and left"
	   . " waiting\r\n";

	if (!broker_live_ready()) {
		echo "sql/strategy_live.sql has not been applied, so nothing is armed and"
		   . " nothing can be\r\n";
		exit(2);
	}

	$found = 0;

	foreach (query("select strategy from strategy_accounts where mode=? order by strategy",
	               STRATEGY_MODE_T212_LIVE) as $row) {

		$strategy = (string) $row['strategy'];
		if ($only !== null && $strategy !== $only) { continue; }
		$found++;

		$state = broker_live_state($strategy);

		echo "\r\n--- " . $strategy . " (" . $state['name'] . ") ---\r\n";
		echo "  " . $state['words'] . "\r\n";
		echo "  approval: " . ($state['approval'] ? "every batch waits for one"
		                                          : "sends without asking") . "\r\n";

		if ($state['caps'] !== null) {
			echo "  caps: " . $state['caps']['capital'] . " capital, "
			   . $state['caps']['per_order'] . " an order, "
			   . $state['caps']['per_run'] . " a run, "
			   . $state['caps']['per_day'] . " of buys a day, "
			   . $state['caps']['orders_per_day'] . " orders a day\r\n";
			echo "  committed: " . round($state['committed']['committed'], 2)
			   . " (" . round($state['committed']['invested'], 2) . " invested, "
			   . round($state['committed']['reserved'], 2) . " reserved)\r\n";
		}

		if ($state['batch'] !== null) {
			echo "  batch " . $state['batch']['batch'] . ": "
			   . strtolower((string) $state['batch']['status']) . ", "
			   . count($state['batch']['orders']) . " order(s)\r\n";
		}

		foreach ($state['conditions']['checks'] as $check) {
			echo "  [" . ($check['ok'] ? 'yes' : 'NO ') . "] "
			   . $check['key'] . ": " . $check['words'] . "\r\n";
		}

		echo "  would an order be sent: "
		   . ($state['conditions']['ok']
		        ? "every condition holds, and there is still no path that sends one"
		        : "no") . "\r\n";
	}

	if ($found === 0) {
		echo "\r\nthere are no live accounts"
		   . ($only === null ? "" : " called " . $only) . "\r\n";
	}

	exit(0);

/* No closing tag: see the note at the end of constants.php. */
