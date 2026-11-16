<?php

	/**
	 * backfill_trend_statistics.php — the close and its moving averages,
	 * over history.
	 *
	 *   php backfill_trend_statistics.php <from> <to> [indicator ...]
	 *
	 * Run by hand, once, before backtesting a trend strategy. Not in
	 * refresh_all.sh: get_statistics.php writes these three every night for
	 * the current as of date, and this is only for the dates before the
	 * night that job first ran.
	 *
	 * Why it exists: a backtest replays the strategy's own screen, and that
	 * screen compares close_price with ma200 as they stood on each date. The
	 * prices go back to 2015; the statistics only go back to the night the
	 * trend screens were deployed, so without this a trend strategy cannot
	 * be backtested over anything but the last few weeks -
	 * backtest_strategy.php refuses, which is correct and not very useful.
	 *
	 * No look-ahead: every value is computed from prices on or before its
	 * own date, which is the same window calc_moving_avg() uses. A 200 day
	 * average needs 200 closes, so a date without them gets no row rather
	 * than a shorter average - the same rule the nightly job follows, and
	 * the reason a backtest's first months hold nothing.
	 *
	 * One statement per indicator per date, computed in the database rather
	 * than a query per symbol: 350 symbols times 2,500 dates times three
	 * indicators is two and a half million round trips the other way.
	 */

	require_once("constants.php");
	include("functions.php");
	include("share_functions.php");

	$_SESSION["exchange"]='XLON';
	$exchange='XLON';

	/* The windows, and what each one is. close_price is the latest close, so
	   its window is 1. Keep these in step with ma50() and ma200() in
	   share_functions.php: they are the same definitions. */
	$windows=['close_price'=>1,'ma50'=>50,'ma200'=>200];

	$from=isset($argv[1]) ? $argv[1] : null;
	$to  =isset($argv[2]) ? $argv[2] : null;

	$wanted=array_slice($argv,3);
	if (!count($wanted)){ $wanted=array_keys($windows); }

	foreach ($wanted as $indicator) {
		if (!isset($windows[$indicator])){
			exit("Unknown indicator '".$indicator."'. One of: ".implode(', ',array_keys($windows))."\r\n");
		}
	}

	foreach ([$from,$to] as $date) {
		if ($date===null
		    ||date_create_from_format('Y-m-d',$date)===false
		    ||date_format(date_create_from_format('Y-m-d',$date),'Y-m-d')!==$date){
			exit("Usage: php backfill_trend_statistics.php <from> <to> [indicator ...]\r\n");
		}
	}
	if ($from>$to){ exit("The start date is after the end date\r\n"); }

	log_job("backfill_trend_statistics");

	$dates=query("select distinct date from historical_prices
	               where exchange=? and date>=? and date<=? order by date",$exchange,$from,$to);

	if (!count($dates)){ exit("No ".$exchange." prices between ".$from." and ".$to."\r\n"); }

	print_r("backfilling ".implode(', ',$wanted)." over ".count($dates)." trading days\r\n");

	$written=0;
	$dates_done=0;

	foreach ($dates as $row) {

		$date=substr((string)$row['date'],0,10);

		foreach ($wanted as $indicator) {

			$window=$windows[$indicator];

			/* Replaced rather than added to, so a re-run is idempotent and a
			   value whose prices have since been corrected is corrected too. */
			query("delete from statistics where indicator=? and date=? and exchange=?",
			      $indicator,$date,$exchange);

			/* Enabled symbols only, which is what the nightly job writes
			   for. having count(*)=window is the full window rule: an
			   average of 40 closes is not a 200 day average. */
			query("insert into statistics (symbol,indicator,date,value,exchange)
			       select t.symbol, ?, ?, round(avg(t.price),6), ?
			         from (select h.symbol, h.price,
			                      row_number() over (partition by h.symbol order by h.date desc) rn
			                 from historical_prices h, stock_symbols ss
			                where ss.symbol=h.symbol and ss.exchange=h.exchange
			                  and ss.enabled='Y' and h.exchange=? and h.date<=?
			                  and h.price is not null) t
			        where t.rn<=?
			        group by t.symbol
			       having count(*)=?",
			      $indicator,$date,$exchange,$exchange,$date,$window,$window);

			$count=query("select count(*) n from statistics
			               where indicator=? and date=? and exchange=?",$indicator,$date,$exchange);
			$written += count($count) ? (int)$count[0]['n'] : 0;
		}

		$dates_done++;
		if ($dates_done%100===0){ print_r("  ".$dates_done." days, ".$written." rows\r\n"); }
	}

	$message="backfilled ".$written." rows of ".implode(', ',$wanted)
	        ." over ".$dates_done." trading days to ".$to;
	write_log("backfill_trend_statistics",$message);
	print_r($message."\r\n");

?>
