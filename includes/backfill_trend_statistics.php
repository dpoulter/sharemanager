<?php

	/**
	 * backfill_trend_statistics.php — the indicators that prices alone
	 * decide, over history.
	 *
	 *   php backfill_trend_statistics.php <from> <to> [indicator ...]
	 *
	 * Run by hand, once, before backtesting a trend strategy. Not in
	 * refresh_all.sh: get_statistics.php and get_momentum_statistics.php
	 * write these every night for the current as of date, and this is only
	 * for the dates before the night those jobs first ran.
	 *
	 * Why it exists: a backtest replays the strategy's own screen and its
	 * own ranking, as they stood on each date. The prices go back years; the
	 * statistics only go back to the night each indicator was deployed, and
	 * the momentum ones were only ever written for the monthly as of dates
	 * get_momentum_statistics.php calculates. Without this, a trend strategy
	 * can be backtested over nothing but the last few weeks -
	 * backtest_strategy.php refuses, which is correct and not very useful.
	 *
	 * Only indicators that prices alone decide are here, and that is the
	 * line: a close, a moving average and a return over N months are facts
	 * about a price series, so computing one for an old date invents
	 * nothing. A value or quality score is a statement about figures that
	 * were not published yet, and no amount of SQL makes it honest -
	 * backtest_strategy.php refuses those periods instead.
	 *
	 * No look-ahead: every value is computed from prices on or before its
	 * own date, with the same windows the nightly jobs use. A 200 day
	 * average needs 200 closes, so a date without them gets no row rather
	 * than a shorter average - the same rule, and the reason a backtest's
	 * first months hold nothing.
	 *
	 * One statement per indicator per date, computed in the database rather
	 * than a query per symbol: 350 symbols times 2,500 dates times six
	 * indicators is five million round trips the other way.
	 */

	require_once("constants.php");
	include("functions.php");
	include("share_functions.php");

	$_SESSION["exchange"]='XLON';
	$exchange='XLON';

	/* The price window indicators: the mean of the last N closes, and the
	   latest close as a window of one. Keep these in step with ma50() and
	   ma200() in share_functions.php - they are the same definitions. */
	$windows=['close_price'=>1,'ma50'=>50,'ma200'=>200];

	/* The momentum indicators: the return from N months back to now, as a
	   percentage, which is what calc_momentum() in share_functions.php
	   writes. Same definition, including the two details that are easy to
	   get wrong and that make the difference between a backtest ranking on
	   this and the live strategy ranking on the nightly job's value:

	     the end of the window is the last price on or before the date, not
	     the date itself, so a bank holiday reads the Friday;

	     the start is the first price on or AFTER the date minus N months,
	     because calc_momentum() walks that date forward a day at a time
	     until it finds a price. A symbol whose history is shorter than the
	     window gets the return over what history it has, under the same
	     name - which is the nightly job's behaviour, not an improvement
	     on it. */
	$months=['1mnth'=>1,'3mnth'=>3,'6mnth'=>6,'12mnth'=>12];

	$from=isset($argv[1]) ? $argv[1] : null;
	$to  =isset($argv[2]) ? $argv[2] : null;

	$known=array_merge(array_keys($windows),array_keys($months));

	$wanted=array_slice($argv,3);
	if (!count($wanted)){ $wanted=$known; }

	foreach ($wanted as $indicator) {
		if (!in_array($indicator,$known,true)){
			exit("Unknown indicator '".$indicator."'. One of: ".implode(', ',$known)."\r\n");
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

			/* Replaced rather than added to, so a re-run is idempotent and a
			   value whose prices have since been corrected is corrected too. */
			query("delete from statistics where indicator=? and date=? and exchange=?",
			      $indicator,$date,$exchange);

			if (isset($windows[$indicator])) {

				$window=$windows[$indicator];

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
			}
			else {

				$month=$months[$indicator];

				/* The return over the window, as calc_momentum() writes it:
				   (end - start) / start * 100, from the first price on or
				   after the date minus N months to the last price on or
				   before the date. A window that collapses to one date is
				   not a return, so it is left out rather than written as
				   nought - the nightly job never enters its loop in that
				   case either. */
				query("insert into statistics (symbol,indicator,date,value,exchange)
				       select e.symbol, ?, ?,
				              round((e.price-s.price)/s.price*100,6), ?
				         from (select h.symbol, h.date, h.price
				                 from historical_prices h,
				                      (select h2.symbol, max(h2.date) d
				                         from historical_prices h2
				                        where h2.exchange=? and h2.date<=? and h2.price is not null
				                        group by h2.symbol) last
				                where h.symbol=last.symbol and h.date=last.d and h.exchange=?) e,
				              (select h.symbol, h.date, h.price
				                 from historical_prices h,
				                      (select h2.symbol, min(h2.date) d
				                         from historical_prices h2
				                        where h2.exchange=? and h2.date>=date_sub(?, interval ".$month." month)
				                          and h2.date<=? and h2.price is not null
				                        group by h2.symbol) first
				                where h.symbol=first.symbol and h.date=first.d and h.exchange=?) s,
				              stock_symbols ss
				        where s.symbol=e.symbol and ss.symbol=e.symbol and ss.exchange=?
				          and ss.enabled='Y' and s.price>0 and s.date<e.date",
				      $indicator,$date,$exchange,
				      $exchange,$date,$exchange,
				      $exchange,$date,$date,$exchange,
				      $exchange);
			}

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
