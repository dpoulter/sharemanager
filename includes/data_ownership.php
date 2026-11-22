<?php

	/**
	 * Which machine owns which data. One owner per indicator (Dale, 21 Nov 2026).
	 *
	 * THE MAC MINI owns, and sends each night through data_import
	 * (includes/data_import.php, public/data_api.php):
	 *
	 *   historical_prices   the daily raw close, as the N100 stores it (pence for
	 *                       a London line quoted in pence)
	 *   close_price         the latest close on or before the date
	 *   ma50, ma200         the average of the share's own last 50 / 200 closes
	 *   1mnth 3mnth 6mnth 12mnth
	 *                       the calendar-month return, in per cent
	 *   piotroski_score     the Piotroski F-score, 0 to 9, the paper's (annual
	 *                       accounts, 4-month publication lag), every company
	 *                       (brief of 22 Nov 2026), with its whole history
	 *   book_to_market      book equity over market cap, negative book left out
	 *
	 * The two accounts statistics replace FMP's figure (piotroski_score) and
	 * are new (book_to_market); sql/piotroski_publish.sql unmaps FMP's field,
	 * and get_api_stats.py skips every name here (MAC_MINI_STATISTICS there,
	 * kept equal to this list by the suite).
	 *
	 * Statistics for trading days only: the weekend rows the Mac mini keeps for
	 * its backtests are never sent, so the live 200-day exit counts trading days.
	 * Later value_score, once its brief is built; until then it is the N100's.
	 *
	 * THE N100 owns everything else in statistics, and keeps calculating it
	 * nightly: the FMP ratios and fundamentals (fetch_fmp_fundamentals.py,
	 * get_api_stats.py), value_score, Altman, the yields,
	 * high_52w / low_52w and the valuation, news and announcement sentiment,
	 * and every rank and percentile (get_statistics.php ranks the published
	 * values too). Also the quote page fundamentals, live quotes, RNS and news.
	 *
	 * Nothing on the N100 fetches prices or works out a published statistic in
	 * the nightly job: get_share_prices.php and get_momentum_statistics.php
	 * left refresh_all.sh, and get_statistics.php skips these names once the
	 * Mac mini has published (data_mac_mini_publishes()). Before the first
	 * publish - the cutover Jarvis makes with `research publish --full` - the
	 * N100 is still their owner and works them out as it always has.
	 */

	if (!defined('DATA_PUBLISHED_STATISTICS')) {
		define('DATA_PUBLISHED_STATISTICS', ['close_price', 'ma50', 'ma200', '1mnth', '3mnth', '6mnth', '12mnth',
		                                     'piotroski_score', 'book_to_market']);
	}

	/** Whether the Mac mini owns this statistic (so nothing on the N100 may write it but data_import). */
	function data_published_statistic($name) {
		return in_array((string) $name, DATA_PUBLISHED_STATISTICS, true);
	}

	/** Whether the Mac mini has made a publish live (so it, not the N100, owns the published statistics). */
	function data_mac_mini_publishes() {
		static $known = null;
		if ($known !== null) { return $known; }
		$t = query("select count(*) n from information_schema.tables where table_schema=database() and table_name='data_publishes'");
		if (!is_array($t) || (int) $t[0]['n'] === 0) { return $known = false; }
		$r = query("select count(*) n from data_publishes");
		return $known = (is_array($r) && (int) $r[0]['n'] > 0);
	}

/* No closing tag: see the note at the end of constants.php. */
