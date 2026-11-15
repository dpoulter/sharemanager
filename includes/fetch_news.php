<?php

	/**
	 * Nightly news: fetch, store, score, and write the sentiment indicators.
	 *
	 *   php includes/fetch_news.php [YYYY-MM-DD]
	 *
	 * Runs after the prices, because the as of date is the newest date in
	 * historical_prices and the indicators are written against it.
	 *
	 * Three jobs rather than three scripts, because they are one chain and
	 * the middle one is the only expensive part: fetching is a second a
	 * symbol against Google, scoring is a few pence against Haiku, and the
	 * indicators are a query each. Splitting them would mean three cron
	 * entries that have to run in order.
	 *
	 * Deliberately gentle with Google. One request a second, a real
	 * User-Agent (get_articles() sets one), and a stop on a run of refusals:
	 * the feed is free and undocumented, and hammering it is how it stops
	 * answering.
	 */

	require_once("constants.php");
	include("functions.php");
	include("share_functions.php");
	include("news.php");

	log_job("fetch_news");

	$requestedDate = (PHP_SAPI === 'cli' && isset($argv[1])) ? $argv[1] : null;

	$exchanges = array("XLON");

	foreach ($exchanges as $exchange) {

		$_SESSION["exchange"] = $exchange;

		$asOf = resolve_asof_date($requestedDate, $exchange);

		if ($asOf === null) {
			$why = ($requestedDate === null)
			     ? "no prices have been loaded for ".$exchange
			     : "there are no ".$exchange." prices for '".$requestedDate."'";
			fwrite(STDERR, "fetch_news: refusing to run, ".$why."\n");
			write_log("fetch_news", "refusing to run: ".$why);
			exit(2);
		}

		print_r("Asofdate=".$asOf."\r\n");

		$symbols = query("select symbol from stock_symbols
		                   where enabled='Y' and exchange=? order by symbol", $exchange);

		$fetched = $stored = $empty = 0;
		$started = microtime(true);

		foreach ($symbols as $row) {

			$symbol = $row['symbol'];

			$turn = microtime(true);
			$articles = get_articles($symbol);
			$fetched++;

			if (!count($articles)) {
				/* Nothing is normal: a small company can go weeks without a
				   headline, and get_articles() also returns nothing when the
				   feed refuses. It logs which, and a run where every symbol
				   is empty is the shape that says the feed has stopped
				   answering rather than that the market went quiet. */
				$empty++;
			} else {
				$stored += news_store($symbol, $articles);
			}

			/* One request a second. The feed has no published limit and
			   answers 429 when it has had enough; get_articles() logs the
			   failure and returns nothing, so a run of them shows up as
			   $empty climbing. */
			$spent = microtime(true) - $turn;
			if ($spent < NEWS_FETCH_INTERVAL) {
				usleep((int) round((NEWS_FETCH_INTERVAL - $spent) * 1000000));
			}

			if ($empty >= 20 && $stored === 0) {
				write_log("fetch_news", "nothing stored after ".$empty
				                       ." symbols; stopping rather than carrying on"
				                       ." against a feed that is not answering");
				fwrite(STDERR, "fetch_news: the feed is not answering; stopped early\n");
				break;
			}
		}

		print_r("fetched ".$fetched." symbols, ".$stored." new articles, "
		        .$empty." with nothing, ".round(microtime(true) - $started)."s\r\n");
		write_log("fetch_news", "fetched=".$fetched." new=".$stored." empty=".$empty);

		/* Scoring. Capped, so a feed that suddenly returns a thousand
		   articles a symbol is a surprise rather than a bill. */
		$scored = news_score_unscored();

		print_r("scored ".$scored['scored']." of ".$scored['considered']." headlines in "
		        .$scored['batches']." batches, $".number_format($scored['cost'], 4)."\r\n");
		write_log("fetch_news", "level=INFO news_scoring"
		                       ." scored=".$scored['scored']
		                       ." considered=".$scored['considered']
		                       ." batches=".$scored['batches']
		                       ." model=".NEWS_SCORE_MODEL
		                       ." input_tokens=".$scored['input_tokens']
		                       ." output_tokens=".$scored['output_tokens']
		                       ." cost_usd=".number_format($scored['cost'], 6)
		                       ." capped=".($scored['capped'] ? 'yes' : 'no'));

		if ($scored['capped']) {
			write_log("fetch_news", "level=WARN more than ".NEWS_SCORE_MAX_PER_RUN
			                       ." headlines were waiting; the rest are left for"
			                       ." the next run");
		}

		/* The indicators, from whatever is scored now. */
		$written = news_write_indicators($asOf, $exchange);
		print_r("wrote ".$written." sentiment statistics for ".$asOf."\r\n");
		write_log("fetch_news", "indicators written=".$written." asof=".$asOf);
	}
