<?php

	/**
	 * Work out the market breadth figures and store them.
	 *
	 *   php includes/write_market_breadth.php                 the latest date
	 *   php includes/write_market_breadth.php 2022-09-01 2026-11-16   a range
	 *
	 * Run from refresh_all.sh after get_statistics.php, because that is the
	 * job that writes the close, the two moving averages and the twelve
	 * month return this reads.
	 *
	 * The dashboard asked these questions of statistics on every page load,
	 * pivoting the whole universe twice: 2.6 seconds of a 3.2 second page,
	 * for figures that change once a night. They change once a night, so
	 * they are worked out once a night.
	 *
	 * A range backfills. Dates the statistics job never covered are written
	 * with a zero count rather than skipped, so a gap in the line can be
	 * told from a day nobody asked about.
	 */

	require_once("constants.php");
	require_once("functions.php");
	require_once("share_functions.php");
	require_once("view.php");
	require_once("home_functions.php");

	$exchange = getenv("SM_EXCHANGE") ?: default_exchange();
	$_SESSION["exchange"] = $exchange;

	if (!market_breadth_ready()) {
		fwrite(STDERR, "market_breadth is not there; apply sql/market_breadth.sql\n");
		exit(2);
	}

	$a_date = function ($value) {
		$value = trim((string) $value);
		$parsed = DateTime::createFromFormat('Y-m-d', $value);
		if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
			fwrite(STDERR, "'" . $value . "' is not a date in YYYY-MM-DD form\n");
			exit(2);
		}
		return $value;
	};

	$from = isset($argv[1]) ? $a_date($argv[1]) : null;
	$to   = isset($argv[2]) ? $a_date($argv[2]) : $from;

	if ($from !== null && $to < $from) {
		fwrite(STDERR, "the range starts after it ends\n");
		exit(2);
	}

	/* With no arguments: the newest date the statistics describe. Not today -
	   on a Sunday there is no close and nothing to count. */
	if ($from === null) {
		$rows = query("select max(date) d from statistics where exchange=?", $exchange);
		if (!count($rows) || $rows[0]['d'] === null) {
			write_log("market_breadth", "no statistics for " . $exchange . ", nothing to write");
			print("no statistics for " . $exchange . "\n");
			exit(0);
		}
		$from = $to = substr((string) $rows[0]['d'], 0, 10);
	}

	/* Only the dates the statistics job actually covered: a date with no
	   rows has no breadth, and writing a zero for it would put a false
	   bottom in the line. */
	$dates = query("select distinct date from statistics
	                 where exchange=? and date between ? and ?
	                   and indicator in ('close_price','ma200')
	                 order by date", $exchange, $from, $to);

	$written = 0;
	foreach ($dates as $row) {
		$date = substr((string) $row['date'], 0, 10);
		$figures = market_breadth_write($date, $exchange);
		$written++;
		if (count($dates) <= 5 || $written % 100 === 0 || $written === count($dates)) {
			printf("%s  %s above 200 day, %s with 50 above 200, median %s, over %d shares\n",
			       $date,
			       $figures['pct_above_ma200'] === null ? '-' : $figures['pct_above_ma200'] . '%',
			       $figures['pct_ma50_above_ma200'] === null ? '-' : $figures['pct_ma50_above_ma200'] . '%',
			       $figures['median_12m'] === null ? '-' : $figures['median_12m'],
			       $figures['shares_counted']);
		}
	}

	write_log("market_breadth", "wrote " . $written . " dates for " . $exchange
	                            . " between " . $from . " and " . $to);
	printf("%d dates written for %s\n", $written, $exchange);

	exit(0);

?>
