<?php

	/**
	 * Ask each switched-on Trading 212 key whether it can read orders.
	 *
	 *   php includes/check_key_scopes.php
	 *
	 * Run first by includes/broker_run.sh. For live-read and live-orders - and
	 * demo, which the resolve step also reads - where the environment is on
	 * and its key is set, it makes the two GETs the resolve step depends on
	 * (key_scopes() in t212_client.php), keeps the answer where the Broker
	 * page keeps its status, and prints one line each.
	 *
	 * READ ONLY. It cannot place, amend or cancel anything. With an
	 * environment off or no key set it opens no connection.
	 *
	 * Always exits 0: a refused permission is something for a person to fix in
	 * Trading 212, and not a reason to stop the broker job running.
	 */

	require_once("constants.php");
	require_once("functions.php");
	require_once("share_functions.php");
	require_once(__DIR__ . "/broker.php");

	log_job("check_key_scopes");

	foreach (['demo', 'live-read', 'live-orders'] as $environment) {

		$scopes = key_scopes($environment);

		if (!$scopes['checked']) {
			echo "key_scopes: " . $environment . " - not checked ("
			   . ($scopes['why'] === '' ? 'off' : $scopes['why']) . ")\r\n";
			continue;
		}

		broker_store_key_scopes($scopes);
		write_log("check_key_scopes", $environment . ": " . $scopes['summary']);
		echo "key_scopes: " . $environment . " - " . $scopes['summary']
		   . " (orders " . $scopes['orders'] . " " . $scopes['orders_http']
		   . ", history " . $scopes['history'] . " " . $scopes['history_http'] . ")\r\n";
	}

	exit(0);

/* No closing tag: see the note at the end of constants.php. */
