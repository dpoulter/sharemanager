<?php

	/**
	 * What Trading 212 actually answers.
	 *
	 *   php tools/t212_probe.php [--dir=/var/lib/sharemanager/probe]
	 *
	 * Run before the mapping is written, and again after any change at the
	 * other end. The API is version 0 and its documentation gives no response
	 * fields, no price units and no ticker format for London shares - the
	 * only example is AAPL_US_EQ - so these are the three things the sync
	 * cannot assume and this is what settles them:
	 *
	 *   1. the field names for cash, invested value and total;
	 *   2. the ticker format for London shares, and whether the instruments
	 *      carry an ISIN;
	 *   3. whether prices are pounds or pence. historical_prices is pence and
	 *      the strategy engine is pounds. Get this wrong and every figure is
	 *      out by a hundred.
	 *
	 * READ ONLY, like everything else in stage 1: three GETs and a file.
	 *
	 * The raw JSON is written outside the web root, with every credential
	 * masked, so it can be read and sent back. It is still the account's
	 * positions: it is written mode 600 and belongs on the server, not in a
	 * chat window, unless somebody has looked at it first.
	 */

	/* The application's own includes, found the way the application finds
	   them: by include path, not by absolute path. The suite puts its
	   fixtures ahead of includes/ so that a relative require("constants.php")
	   lands on the test settings, and an absolute require here would load
	   the real constants.php as well - two sets of constants, a page of
	   "already defined" warnings, and the production database credentials in
	   a test run. Appended rather than prepended, so anything already on the
	   path still wins. */
	set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__ . "/../includes");

	require_once("constants.php");
	require_once("functions.php");
	require_once("broker.php");

	$dir = T212_PROBE_DIR;
	foreach (array_slice($argv, 1) as $argument) {
		if (strpos($argument, '--dir=') === 0) { $dir = substr($argument, 6); }
		else {
			fwrite(STDERR, "Usage: php tools/t212_probe.php [--dir=<directory>]\r\n");
			exit(2);
		}
	}

	$client = new T212Client();

	echo "environment: " . $client->environment() . "\r\n";
	echo "base url:    " . $client->base_url() . "\r\n";

	if (!$client->configured()) {
		echo $client->why_not() . "\r\n";
		echo "Nothing was requested.\r\n";
		exit(1);
	}

	if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
		fwrite(STDERR, "cannot create " . $dir . "\r\n");
		exit(2);
	}

	$out = ['taken_at'    => gmdate('c'),
	        'environment' => $client->environment(),
	        'base_url'    => $client->base_url(),
	        'calls'       => []];

	$exit = 0;

	foreach (['summary' => [], 'positions' => [], 'instruments' => []] as $call => $query) {

		$answer = $client->get($call, $query);

		$out['calls'][$call] = [
			'ok'      => $answer['ok'],
			'status'  => $answer['status'],
			'message' => $answer['ok'] ? null : $answer['message'],
			'data'    => $answer['ok'] ? $answer['data'] : null,
		];

		echo "\r\n--- " . $call . " ---\r\n";

		if (!$answer['ok']) {
			echo "  " . $answer['message'] . "\r\n";
			$exit = 1;
			continue;
		}

		$data = $answer['data'];

		if ($call === 'summary') {
			/* Point 1: the field names, printed with their values, because
			   only the figures say which name carries which number.
			   Nested, because the summary nests: the first version printed
			   "cash = Array" with a warning, which is the shape of the
			   response saying something the reader could not hear. */
			$walk = function ($node, $prefix = '') use (&$walk) {
				foreach ((array) $node as $name => $value) {
					$path = $prefix === '' ? (string) $name : $prefix . '.' . $name;
					if (is_array($value)) { $walk($value, $path); }
					else if (is_bool($value)) { echo "    " . $path . " = "
					                                . ($value ? 'true' : 'false') . "\r\n"; }
					else { echo "    " . $path . " = " . (string) $value . "\r\n"; }
				}
			};
			echo "  fields: " . implode(', ', array_keys((array) $data)) . "\r\n";
			$walk($data);

			foreach (['summary_cash'       => 'cash',
			          'summary_invested'   => 'invested value',
			          'summary_total'      => 'total value',
			          'summary_reserved'   => 'reserved for orders',
			          'summary_in_pies'    => 'in pies',
			          'summary_cost'       => 'cost of holdings',
			          'summary_realised'   => 'realised profit',
			          'summary_unrealised' => 'unrealised profit'] as $want => $label) {
				[$value, $found] = broker_field((array) $data, broker_fields($want));
				echo "  " . str_pad($want, 20)
				   . ($value === null
				      ? "NOT FOUND (" . implode(', ', broker_fields($want))
				        . ") - add its path to broker_fields()"
				      : $found . " = " . (string) $value) . "\r\n";
			}
		}

		if ($call === 'positions') {

			$rows = is_array($data) ? $data : [];
			if (isset($rows['items'])) { $rows = $rows['items']; }
			echo "  " . count($rows) . " position(s)\r\n";

			if (count($rows)) {
				echo "  fields: " . implode(', ', array_keys((array) $rows[0])) . "\r\n";
			}

			/* Point 3, and the reason this script exists: the broker's price
			   for a share beside our own close for it. One is a hundred
			   times the other, and which way round is the whole question. */
			$samples = [];
			foreach ($rows as $row) {
				[$ticker] = broker_field($row, broker_fields('position_ticker'));
				$price    = broker_number($row, broker_fields('position_current'));
				if ($ticker === null) { continue; }

				$parts  = broker_ticker_code($ticker);
				$key    = broker_code_key($parts['code']);
				$symbol = null;
				foreach (broker_symbol_keys(default_exchange()) as $k => $symbols) {
					if ($k === $key && count($symbols) === 1) { $symbol = $symbols[0]; }
				}
				$close = $symbol === null ? null : broker_our_close($symbol, default_exchange());

				echo "    " . str_pad((string) $ticker, 14)
				   . " code=" . str_pad($parts['code'], 8)
				   . " market=" . str_pad((string) $parts['market'], 5)
				   . " ours=" . str_pad((string) $symbol, 8)
				   . " broker price=" . str_pad((string) $price, 12)
				   . " our close (pence)=" . ($close === null ? '-' : $close) . "\r\n";

				if ($symbol !== null && $price !== null && $close !== null) {
					$samples[] = ['symbol' => $symbol, 'price' => $price, 'close' => $close];
				}
			}

			$unit = broker_price_unit($samples, 'auto');
			echo "  price unit: " . ($unit['unit'] === null ? 'UNDECIDED' : $unit['unit'])
			   . " - " . $unit['why'] . "\r\n";
			$out['price_unit'] = $unit;
		}

		if ($call === 'instruments') {

			$rows = is_array($data) ? $data : [];
			echo "  " . count($rows) . " instrument(s)\r\n";
			if (count($rows)) {
				echo "  fields: " . implode(', ', array_keys((array) $rows[0])) . "\r\n";
			}

			$with_isin = 0;
			foreach ($rows as $row) {
				[$isin] = broker_field($row, broker_fields('instrument_isin'));
				if ($isin !== null) { $with_isin++; }
			}
			echo "  carrying an ISIN: " . $with_isin . " of " . count($rows) . "\r\n";

			/* Point 2: what a London share's ticker looks like, shown as the
			   matcher sees it. */
			$matched = broker_match_instruments($rows, default_exchange());
			echo "  matched to one of our symbols: " . $matched['matched']
			   . ", ambiguous: " . $matched['ambiguous']
			   . ", not ours: " . $matched['unknown'] . "\r\n";

			$shown = 0;
			foreach ($matched['rows'] as $row) {
				if ($row['status'] !== 'matched' || $shown >= 10) { continue; }
				echo "    " . str_pad((string) $row['broker_ticker'], 14)
				   . " short=" . str_pad((string) $row['short_name'], 8)
				   . " -> " . str_pad((string) $row['symbol'], 8)
				   . " by " . str_pad((string) $row['matched_on'], 11)
				   . " ccy=" . (string) $row['currency'] . "\r\n";
				$shown++;
			}

			/* The ones it could not tell apart, in full: this is the list
			   somebody has to look at, and it is short. */
			foreach ($matched['rows'] as $row) {
				if ($row['status'] !== 'ambiguous') { continue; }
				echo "    AMBIGUOUS " . str_pad((string) $row['broker_ticker'], 14)
				   . " short=" . str_pad((string) $row['short_name'], 8)
				   . " ccy=" . str_pad((string) $row['currency'], 5)
				   . " isin=" . str_pad((string) $row['isin'], 13)
				   . " " . (string) $row['note'] . "\r\n";
			}

			/* And the shares the broker does not list at all, which is a
			   different thing from one this could not match. */
			$not_listed = broker_isins_not_listed($matched['isins'], default_exchange());
			echo "  our shares whose ISIN Trading 212 does not list: "
			   . (count($not_listed) ? implode(', ', $not_listed) : 'none')
			   . (count(broker_symbol_isins(default_exchange())) ? ''
			      : ' (none of our shares has an ISIN yet, so nothing could be checked)')
			   . "\r\n";
			$out['matching'] = ['matched' => $matched['matched'],
			                    'ambiguous' => $matched['ambiguous'],
			                    'unknown' => $matched['unknown']];
		}
	}

	/* The file. Masked twice over: the client never puts a credential in a
	   message, and this re-reads the whole document for one anyway, because
	   the cost of being wrong here is a key in a file somebody emails. */
	$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	$json = t212_mask((string) $json);

	$file = rtrim($dir, '/') . '/t212_probe_' . gmdate('Ymd_His') . '.json';
	$old  = umask(0077);
	$written = @file_put_contents($file, $json);
	umask($old);
	@chmod($file, 0600);

	if ($written === false) {
		fwrite(STDERR, "could not write " . $file . "\r\n");
		exit(2);
	}

	echo "\r\nwrote " . $file . " (" . $written . " bytes, mode 600)\r\n";
	echo "requests made: " . $client->requests() . "\r\n";
	foreach ($client->problems() as $problem) { echo "  ! " . $problem . "\r\n"; }

	exit($exit);
