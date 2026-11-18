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
	require_once(__DIR__ . "/broker.php");

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

	/* Each account that is switched on, in turn. The practice account and
	   the live one are read independently now, so turning the live read on
	   no longer stops the practice twin - and every row either of them
	   writes says which account it came from.

	   One failing does not stop the other: a 403 on the live key is a
	   permission to go and change, not a reason to lose tonight's practice
	   positions. */
	$environments = t212_environments_to_read();

	if (!count($environments)) {

		/* A typo in the old variable is not read as one of the three. It
		   used to be the only way to pick an environment, so a host that
		   still sets it to something misspelt gets told, rather than
		   quietly syncing nothing and looking switched off. */
		if (T212_ENV !== '' && !T212_ENV_LEGACY) {
			echo "sync_broker: SM_T212_ENV is '" . T212_ENV . "', which is not one of"
			   . " demo or live-read, so nothing is connected. It is the old"
			   . " single-environment setting in any case: use SM_T212_DEMO and"
			   . " SM_T212_LIVE_READ.\r\n";
			exit(0);
		}

		/* Off is the normal state of a host nobody has given a key to. */
		echo "sync_broker: no account is switched on (SM_T212_DEMO and"
		   . " SM_T212_LIVE_READ are both off), so nothing was read.\r\n";
		exit(0);
	}

	if (T212_ENV_LEGACY) {
		/* For one release. A host whose php-fpm pool or env file still has
		   the old variable keeps working, and says what was assumed rather
		   than quietly reading one account instead of two. */
		echo "sync_broker: SM_T212_ENV='" . T212_ENV . "' is the old single-environment"
		   . " setting. Read as " . implode(" and ", $environments) . "."
		   . " Set SM_T212_DEMO and SM_T212_LIVE_READ instead.\r\n";
		write_log("sync_broker", "SM_T212_ENV=" . T212_ENV . " mapped onto "
		          . implode(",", $environments));
	}

	$failed = false;

	foreach ($environments as $environment) {

		$result = broker_sync(array_merge($options, ['environment' => $environment]));

		echo "\r\n--- " . t212_label_words($result['label'])
		   . " (" . $environment . ") ---\r\n";

		if (!$result['ran']) {
			/* Not configured, or the migration has not been applied. Said
			   plainly, and not as an error: nothing is wrong. */
			echo "  " . $result['message'] . "\r\n";
			if (!$result['ok']) { $failed = true; }
			continue;
		}

		echo "  status=" . $result['status']
		   . " positions=" . $result['positions']
		   . " cash=" . ($result['cash'] === null ? '-' : $result['cash'])
		   . " new orders=" . $result['new_orders']
		   . " new dividends=" . $result['new_dividends']
		   . " price unit=" . ($result['price_unit'] === null ? 'unknown' : $result['price_unit'])
		   . " unmatched symbols=" . $result['unmatched']
		   . " requests=" . $result['requests'] . "\r\n";

		foreach ($result['problems'] as $problem) { echo "  ! " . $problem . "\r\n"; }

		if ($result['status'] === 'FAILED') { $failed = true; }
	}

	exit($failed ? 1 : 0);
