<?php

	/**
	 * quote_view.php - the facts at the top of the quote page.
	 *
	 * The header used to read get_share_info(), which calls the price API
	 * for the quote and stock_info for the fundamentals. Three of its six
	 * figures - market capitalisation, shares in issue and the 52 week range
	 * - were blank on every share, because they come from a provider
	 * endpoint this plan does not include. They are all derivable from what
	 * is already stored, so they are derived here: the range from the price
	 * history, the capitalisation from the shares in issue and the close.
	 *
	 * Reading the stored close rather than the live quote also means the
	 * page agrees with the rest of the application. A header quoting a live
	 * price above a chart drawn from stored closes is two different prices
	 * on one screen.
	 */

	/**
	 * Everything in the band under the company name.
	 *
	 * Prices are pence, as stored. Market capitalisation is pounds, because
	 * a market capitalisation in pence is a number nobody has ever wanted.
	 */
	function quote_header($symbol, $exchange = null) {

		$exchange = $exchange === null ? session_exchange() : $exchange;

		$head = ['symbol' => $symbol, 'name' => null, 'market' => null,
		         'sector' => null, 'logo' => null,
		         'price' => null, 'as_of' => null,
		         'change' => null, 'change_pct' => null,
		         'low_52w' => null, 'high_52w' => null, 'range_pct' => null,
		         'shares' => null, 'market_cap' => null];

		$rows = query("select name, market, sector, logo from stock_symbols
		                where symbol=? and exchange=?", $symbol, $exchange);
		if (!count($rows)) { return $head; }

		$head['name']   = $rows[0]['name'];
		$head['market'] = $rows[0]['market'];
		$head['sector'] = $rows[0]['sector'];
		/* A logo is shown only if the provider supplied one. The page used to
		   draw an <img> whatever the column held, so a share with no logo got
		   a grey box with the company name as its alt text. */
		$logo = trim((string) $rows[0]['logo']);
		$head['logo'] = ($logo === '' || strtoupper($logo) === 'N/A') ? null : $logo;

		/* The last two closes: the price, and what it did on the day. Two
		   stored dates rather than "today and yesterday", so a Monday is
		   measured against the Friday. */
		$closes = query("select date, price from historical_prices
		                  where symbol=? and exchange=? and price is not null
		                  order by date desc limit 2", $symbol, $exchange);

		if (count($closes)) {
			$head['price'] = (float) $closes[0]['price'];
			$head['as_of'] = (string) $closes[0]['date'];
			if (count($closes) > 1 && (float) $closes[1]['price'] != 0.0) {
				$head['change']     = (float) $closes[0]['price'] - (float) $closes[1]['price'];
				$head['change_pct'] = ($head['change'] / (float) $closes[1]['price']) * 100;
			}
		}

		/* The 52 week range. The nightly job stores both ends as
		   indicators (sql/quote_report.sql), which is a row each rather
		   than a scan of a year of closes on every view; a database where
		   that job has not run yet falls back to the scan, so the page
		   never loses the figure waiting for a migration. */
		if ($head['as_of'] !== null) {

			$ends = query("select s.indicator, s.value
			                 from statistics s
			                 join (select indicator, max(date) d from statistics
			                        where symbol=? and exchange=?
			                          and indicator in ('high_52w','low_52w')
			                        group by indicator) latest
			                   on latest.indicator=s.indicator and latest.d=s.date
			                where s.symbol=? and s.exchange=?",
			              $symbol, $exchange, $symbol, $exchange);

			$stored = [];
			foreach ($ends as $end) {
				if ($end['value'] !== null) { $stored[(string) $end['indicator']] = (float) $end['value']; }
			}

			$range = (isset($stored['high_52w']) && isset($stored['low_52w']))
			       ? [['low' => $stored['low_52w'], 'high' => $stored['high_52w']]]
			       : query("select min(price) low, max(price) high
			                  from historical_prices
			                 where symbol=? and exchange=? and price is not null
			                   and date > date_sub(?, interval 1 year) and date <= ?",
			               $symbol, $exchange, $head['as_of'], $head['as_of']);

			if (count($range) && $range[0]['low'] !== null) {
				$head['low_52w']  = (float) $range[0]['low'];
				$head['high_52w'] = (float) $range[0]['high'];
				$span = $head['high_52w'] - $head['low_52w'];
				/* Where today sits in the range, as a percentage, for the bar.
				   A flat year is 50%: it is neither near the high nor near the
				   low, and dividing by nothing is not an answer. */
				$head['range_pct'] = $span > 0
				                   ? max(0, min(100, (($head['price'] - $head['low_52w']) / $span) * 100))
				                   : 50.0;
			}
		}

		/* Shares in issue, and the capitalisation that follows from it.
		   share_fundamentals() reads the provider's own figures where they
		   exist; the calculated indicator is the fallback, and the
		   capitalisation is worked out rather than taken, because a stored
		   capitalisation goes stale the moment the price moves. */
		$fundamentals = share_fundamentals($symbol);
		$shares = (isset($fundamentals['shares']) && is_numeric($fundamentals['shares']))
		        ? (float) $fundamentals['shares'] : null;
		if ($shares === null && $head['as_of'] !== null) {
			$indicator = get_indicator_value($symbol, 'shares_outstanding', $head['as_of']);
			if (is_numeric($indicator)) { $shares = (float) $indicator; }
		}
		$head['shares'] = $shares;

		if ($shares !== null && $head['price'] !== null) {
			//Pence times shares is pence; the capitalisation is pounds.
			$head['market_cap'] = $shares * $head['price'] / 100;
		}
		else if (isset($fundamentals['market_cap']) && is_numeric($fundamentals['market_cap'])) {
			/* The provider's own figure, in the unit the price is quoted
			   in: python/fmp.py normalises it so the two can be compared,
			   which for a London line means pence. A capitalisation in
			   pence is a number nobody has ever wanted, so it comes back
			   to pounds here - and a share quoted in pounds is already
			   there. */
			$cap = (float) $fundamentals['market_cap'];
			$quote_ccy = query("select quote_ccy from stock_symbols where symbol=? and exchange=?",
			                   $symbol, $exchange);
			/* "GBp" and "GBP" are the same three letters a hundred times
			   apart, so the case is the data - see sql/strategy_prices.sql.
			   A London line with nothing stored is pence, which is what
			   every one of them has turned out to be. */
			$pence = !count($quote_ccy)
			      || trim((string) $quote_ccy[0]['quote_ccy']) === ''
			      || in_array(trim((string) $quote_ccy[0]['quote_ccy']), ['GBp', 'GBX'], true);
			$head['market_cap'] = $pence ? $cap / 100 : $cap;
		}

		return $head;
	}

	/**
	 * One named indicator out of the structure share_lookup() returns.
	 *
	 * The header shows four ratios that are also in the Ratios tab. Pulling
	 * them out of the same structure rather than querying again means the
	 * two cannot disagree about what this share's P/E is, which they would
	 * the first time one of them was given its own as-of date.
	 */
	function quote_indicator($quote, $name) {
		if (!is_array($quote)) { return null; }
		foreach ($quote as $category) {
			foreach ($category['indicators'] as $indicator) {
				if ($indicator['name'] === $name) { return $indicator; }
			}
		}
		return null;
	}

	/** Its value, or null. */
	function quote_indicator_value($quote, $name) {
		$indicator = quote_indicator($quote, $name);
		return ($indicator === null || !is_numeric($indicator['value']))
		     ? null : (float) $indicator['value'];
	}

	/**
	 * What a relative valuation actually says, in words.
	 *
	 * The page showed "Relative to Industry" and a red badge reading 341.
	 * 341 is the price the share would be if it were valued like its peers,
	 * in pence, and the badge was red because that is a quarter of what it
	 * costs - which is to say the badge was red for being a bargain. Neither
	 * the number nor the colour said any of that.
	 *
	 * Returns [words, class] where class is the standard move colouring:
	 * implied above the price is green, below is red.
	 */
	function valuation_words($valuation, $price = null) {

		/* A zero implied price is not a valuation of nothing, it is a
		   valuation that could not be worked out: every peer ratio was
		   missing, or the share's own figure was. Printed as a price it
		   reads "0.0p, 100% below", which is a statement about the company
		   rather than about the data. */
		if (!is_array($valuation) || $valuation['value'] === null
		    || (float) $valuation['value'] <= 0.0) {
			return [null, 'sm-flat'];
		}

		/* The price to compare against is the one the page is showing.
		   get_valuation() reads its own as-of date - yesterday, whatever
		   day that is - so its copy of the price can be a different day's
		   close, and two prices for one share on one page is the confusion
		   this is meant to end. */
		if (!is_numeric($price)) { $price = $valuation['price'] ?? null; }
		if (!is_numeric($price) || (float) $price == 0.0) { return [null, 'sm-flat']; }

		$implied = (float) $valuation['value'];
		$price   = (float) $price;
		$gap     = (($implied / $price) - 1) * 100;

		$words = 'Implied price ' . fmt_pence($implied) . ' against ' . fmt_pence($price)
		       . ', ' . fmt_pct(abs($gap)) . ' ' . ($gap >= 0 ? 'above' : 'below');

		return [$words, move_class($gap)];
	}

	/* ---------------------------------------------------------------------
	   The one-screen report, 18 Nov 2026.

	   Everything below assembles a panel of the quote page out of what is
	   already stored. The page is a research page: every figure is shown
	   against something - a rank, a median, an average, a range - and the
	   panels are built here rather than in the template so the template is
	   markup and the arithmetic can be tested without a browser.
	   --------------------------------------------------------------------- */

	/**
	 * Named statistics for one share, each at its own latest date.
	 *
	 * Per indicator rather than one date for all of them: the momentum
	 * indicators are written monthly and the moving averages nightly, so a
	 * single as-of date would drop whichever was not written last night.
	 *
	 * Returns name => [value, rank, percentile, date, universe], where
	 * universe is how many shares have that indicator on that date - the
	 * "of 350" in "316 of 350", which is a fact about the day rather than
	 * a constant.
	 */
	function quote_stats($symbol, $names, $exchange = null) {

		$exchange = $exchange === null ? session_exchange() : $exchange;
		$names    = array_values(array_unique(array_filter((array) $names)));
		if (!count($names)) { return []; }

		$marks = implode(',', array_fill(0, count($names), '?'));

		$args = array_merge([$symbol, $exchange], $names, [$symbol, $exchange]);
		$rows = query("select s.indicator, s.value, s.stat_rank, s.percentile, s.date
		                 from statistics s
		                 join (select indicator, max(date) d from statistics
		                        where symbol=? and exchange=? and indicator in (" . $marks . ")
		                        group by indicator) latest
		                   on latest.indicator=s.indicator and latest.d=s.date
		                where s.symbol=? and s.exchange=?", ...$args);

		$out = [];
		foreach ($rows as $row) {
			$out[(string) $row['indicator']] = [
				'value'      => $row['value'] === null ? null : (float) $row['value'],
				'rank'       => $row['stat_rank'] === null ? null : (int) $row['stat_rank'],
				'percentile' => $row['percentile'] === null ? null : (float) $row['percentile'],
				'date'       => (string) $row['date'],
				'universe'   => null,
			];
		}

		/* How many shares each of those dates ranked. One query for the
		   lot: four indicators is four round trips otherwise, and the
		   answer is the same shape every time. */
		foreach ($out as $name => $stat) {
			$count = query("select count(*) n from statistics
			                 where exchange=? and indicator=? and date=? and value is not null",
			               $exchange, $name, $stat['date']);
			$out[$name]['universe'] = count($count) ? (int) $count[0]['n'] : null;
		}

		return $out;
	}

	/**
	 * A price change over a number of calendar days, from the stored closes.
	 *
	 * The nightly job writes 3, 6 and 12 month returns as ranked
	 * indicators; one month is not one of them yet, and a reader looking at
	 * a share wants to know what it has done this month. Closes are closes:
	 * the nearest trading day on or before the date a month back.
	 */
	function quote_change_over($symbol, $days, $as_of, $exchange = null) {

		$exchange = $exchange === null ? session_exchange() : $exchange;
		if ($as_of === null) { return null; }

		$rows = query("select price from historical_prices
		                where symbol=? and exchange=? and price is not null
		                  and date <= date_sub(?, interval " . (int) $days . " day)
		                order by date desc limit 1", $symbol, $exchange, $as_of);
		if (!count($rows) || (float) $rows[0]['price'] == 0.0) { return null; }

		$then = (float) $rows[0]['price'];

		$now = query("select price from historical_prices
		               where symbol=? and exchange=? and price is not null and date<=?
		               order by date desc limit 1", $symbol, $exchange, $as_of);
		if (!count($now)) { return null; }

		return ((((float) $now[0]['price']) / $then) - 1) * 100;
	}

	/**
	 * The momentum panel: what the price has done, and where it stands.
	 *
	 * Two blocks, because they answer two questions. The first is the
	 * return over four periods with the share's rank in the index beside
	 * it - a share up 14% in six months has done well or badly depending
	 * on what the other 349 did. The second is the close against the four
	 * levels a trend is read from, each with the level itself, because
	 * "3.3% below" means nothing without knowing below what.
	 */
	function quote_momentum($symbol, $head, $exchange = null) {

		$exchange = $exchange === null ? session_exchange() : $exchange;
		$as_of    = $head['as_of'];
		$price    = $head['price'];

		$stats = quote_stats($symbol, ['1mnth', '3mnth', '6mnth', '12mnth',
		                               'ma50', 'ma200', 'close_price'], $exchange);

		$changes = [];
		foreach (['1mnth' => '1 month', '3mnth' => '3 months',
		          '6mnth' => '6 months', '12mnth' => '12 months'] as $name => $label) {

			$stat = isset($stats[$name]) ? $stats[$name] : null;
			$value = ($stat === null) ? null : $stat['value'];

			/* One month is not a stored indicator yet, so it is worked out
			   from the closes and has no rank to show. A figure with no
			   rank is still worth having; a missing row is not. */
			if ($value === null && $name === '1mnth') {
				$value = quote_change_over($symbol, 30, $as_of, $exchange);
			}

			$changes[] = [
				'label'      => $label,
				'indicator'  => $name,
				'value'      => $value,
				'rank'       => $stat === null ? null : $stat['rank'],
				'universe'   => $stat === null ? null : $stat['universe'],
				'percentile' => $stat === null ? null : $stat['percentile'],
			];
		}

		/* The levels. ma50 and ma200 are stored in pence like every other
		   price; the 52 week ends come from the header, which reads the
		   price history. */
		$against = [];

		foreach (['ma50' => '50-day average', 'ma200' => '200-day average'] as $name => $label) {
			$level = isset($stats[$name]) ? $stats[$name]['value'] : null;
			$pct   = ($level === null || $price === null || $level == 0.0)
			       ? null : (($price / $level) - 1) * 100;
			$against[] = [
				'label' => $label, 'level' => $level, 'percent' => $pct,
				/* Above the average is the good side of it, below is the
				   bad one: that is what the average is read for, and a
				   percentile would say something else entirely. */
				'band'  => ($pct === null) ? null : ($pct >= 0 ? 'high' : 'low'),
			];
		}

		$high = $head['high_52w'];
		$low  = $head['low_52w'];

		$high_pct = ($high === null || $price === null || $high == 0.0)
		          ? null : (($price / $high) - 1) * 100;
		$low_pct  = ($low === null || $price === null || $low == 0.0)
		          ? null : (($price / $low) - 1) * 100;

		/* Near the high is where a share in an uptrend sits, so within 5%
		   of it is good news and a fifth below it is not. The low is the
		   same statement from the other end. */
		$against[] = ['label' => '52-week high', 'level' => $high, 'percent' => $high_pct,
		              'band' => ($high_pct === null) ? null
		                      : (abs($high_pct) <= 5 ? 'high' : (abs($high_pct) <= 20 ? 'mid' : 'low'))];
		$against[] = ['label' => '52-week low', 'level' => $low, 'percent' => $low_pct,
		              'band' => ($low_pct === null) ? null
		                      : ($low_pct >= 20 ? 'high' : ($low_pct >= 5 ? 'mid' : 'low'))];

		return ['changes' => $changes, 'against' => $against,
		        'ma50' => isset($stats['ma50']) ? $stats['ma50']['value'] : null,
		        'ma200' => isset($stats['ma200']) ? $stats['ma200']['value'] : null];
	}

	/**
	 * Dale's investment policy, checked here rather than by the AI.
	 *
	 * One rule is set today: hold only while the close is above the 200-day
	 * average. It is checked in code because a rule a language model is
	 * asked about is a rule that is sometimes wrong, and this one decides
	 * whether a holding is sold.
	 *
	 * Returns one entry per rule that can be checked - [ok, sentence] -
	 * so a second rule is a second sentence and nothing else changes. A
	 * rule that cannot be checked for this share, because the average
	 * needs 200 days of prices and this share has not got them, is left
	 * out rather than reported as passed.
	 */
	function quote_policy($head, $momentum) {

		$out   = [];
		$price = $head['price'];
		$ma200 = $momentum['ma200'];

		if ($price !== null && $ma200 !== null && $ma200 != 0.0) {

			$gap = (($price / $ma200) - 1) * 100;
			/* Exactly on the average passes: the rule is "hold while it is
			   above", and a share that has not fallen below it has not
			   broken it. */
			$ok  = $gap >= 0;

			$out[] = [
				'rule'     => 'trend',
				'ok'       => $ok,
				'sentence' => ($ok ? 'passes the trend rule. The close is '
				                   : 'fails the trend rule. The close is ')
				            . fmt_pct(abs($gap)) . ($ok ? ' above' : ' below')
				            . ' the 200-day average (' . fmt_pence($ma200) . ').',
			];
		}

		return $out;
	}

	/**
	 * Which strategies hold this share, and whether the portfolio does.
	 *
	 * A tag each, linking to the strategy: "held" is the first thing a
	 * reader wants to know about a share they are looking at, and the page
	 * used to say nothing about it at all.
	 */
	function quote_held_by($symbol, $session_id = null) {

		$out = ['portfolio' => false, 'strategies' => []];

		$held = query("select ifnull(sum(case when trx_type='SELL' then -shares else shares end),0) n
		                 from purchases where symbol=?" . ($session_id === null ? '' : ' and session_id=?'),
		              ...($session_id === null ? [$symbol] : [$symbol, $session_id]));
		$out['portfolio'] = count($held) && (float) $held[0]['n'] > 0;

		/* The strategy engine is not loaded by every page that shows a
		   share, and a quote page without it is still a quote page. */
		if (function_exists('strategy_display_name')) {
			foreach (query("select strategy from strategy_positions
			                 where symbol=? and quantity>0 order by strategy", $symbol) as $row) {
				$strategy = (string) $row['strategy'];
				if (function_exists('strategy_reserved') && strategy_reserved($strategy)) { continue; }
				$out['strategies'][] = ['strategy' => $strategy,
				                        'name'     => strategy_display_name($strategy)];
			}
		}

		return $out;
	}

	/**
	 * The measures a panel shows, by name, each against its two medians.
	 *
	 * Read straight from statistics rather than out of share_lookup(),
	 * for two reasons. share_lookup() groups by scoring category, so a
	 * measure that is shown but not scored - return on capital, say -
	 * appears in no category and could not be drawn at all; and it joins
	 * its medians, so an indicator with no sector median stored drops out
	 * of the page entirely rather than showing its own figure with a dash
	 * beside it. A panel of four rows where another share has six reads
	 * as a different panel.
	 *
	 * Returns one row per name asked for, in that order, with nulls where
	 * there is nothing: the dash is the honest answer and the row is part
	 * of the page's shape.
	 */
	function quote_panel_rows($symbol, $wanted, $exchange = null) {

		$exchange = $exchange === null ? session_exchange() : $exchange;
		$names    = array_keys($wanted);
		if (!count($names)) { return []; }

		$marks = implode(',', array_fill(0, count($names), '?'));
		$args  = array_merge([$symbol, $exchange], $names, [$symbol, $exchange]);

		$found = [];
		foreach (query(
			"select s.indicator, s.value, s.percentile, s.date,
			        (select a.value from statistic_averages a
			          where a.indicator=s.indicator and a.date=s.date and a.type='MEDIAN'
			            and a.category='SECTOR' and a.sector=ss.sector
			            and (a.exchange=s.exchange or a.exchange is null) limit 1) sector_value,
			        (select a.value from statistic_averages a
			          where a.indicator=s.indicator and a.date=s.date and a.type='MEDIAN'
			            and a.category='MARKET'
			            and (a.exchange=s.exchange or a.exchange is null) limit 1) market_value
			   from statistics s
			   join (select indicator, max(date) d from statistics
			          where symbol=? and exchange=? and indicator in (" . $marks . ")
			          group by indicator) latest
			     on latest.indicator=s.indicator and latest.d=s.date
			   join stock_symbols ss on ss.symbol=s.symbol and ss.exchange=s.exchange
			  where s.symbol=? and s.exchange=?", ...$args) as $row) {
			$found[(string) $row['indicator']] = $row;
		}

		$rows = [];
		foreach ($wanted as $name => $label) {
			$row = isset($found[$name]) ? $found[$name] : null;
			$rows[] = [
				'name'       => $name,
				'label'      => $label,
				'value'      => ($row === null || $row['value'] === null)
				              ? null : (float) $row['value'],
				'sector'     => ($row === null || $row['sector_value'] === null)
				              ? null : (float) $row['sector_value'],
				'market'     => ($row === null || $row['market_value'] === null)
				              ? null : (float) $row['market_value'],
				'percentile' => ($row === null || $row['percentile'] === null)
				              ? null : (float) $row['percentile'],
			];
		}

		return $rows;
	}

	/** The six measures the Value panel shows, in the order it shows them. */
	function quote_value_wanted() {
		return ['pe' => 'P/E', 'price_book_ratio' => 'Price to book',
		        'ev_ebitda' => 'EV / EBITDA', 'price_cash_flow' => 'Price to cash flow',
		        'price_sales_ratio' => 'Price to sales', 'div_yield' => 'Dividend yield'];
	}

	/** And the Quality panel's. roce and operating_margin arrive with
	    sql/quote_report.sql; a database without them shows two dashes
	    rather than two fewer rows. */
	function quote_quality_wanted() {
		return ['roe_ttm' => 'Return on equity', 'roce' => 'Return on capital',
		        'operating_margin' => 'Operating margin',
		        'piotroski_score' => 'Piotroski F-Score', 'altman_z' => 'Altman Z-Score'];
	}

	/** And the growth panel's. */
	function quote_growth_wanted() {
		return ['revenue_growth' => 'Revenue growth', 'earnings_growth' => 'Earnings growth',
		        'buyback_yield' => 'Buyback yield', 'shareholder_yield' => 'Shareholder yield'];
	}

	/**
	 * Five years of accounts: the periods, and the lines the panel shows.
	 *
	 * Returns ['periods' => [...oldest first...], 'rows' => [...]], or
	 * periods empty where nothing has been loaded for this company - the
	 * panel is not drawn at all then, because an empty table of five
	 * years reads as a company with no accounts rather than as a loader
	 * that has not run.
	 *
	 * Each row carries the figures in the same order as the periods and
	 * the growth from the year before the last to the last, which is the
	 * comparison a reader makes anyway.
	 */
	function quote_accounts($symbol, $years = 5) {

		$out = ['periods' => [], 'rows' => []];

		$periods = query("select fsp.period_id, fsp.end_date, fsp.period_name
		                    from financial_statement_values fsv
		                    join financial_statement_periods fsp
		                      on fsp.period_id = fsv.period_id
		                   where fsv.symbol=?
		                   group by fsp.period_id, fsp.end_date, fsp.period_name
		                   order by fsp.end_date desc limit " . max(1, min(10, (int) $years)),
		                 $symbol);

		if (!count($periods)) { return $out; }

		//Oldest first: a row of years reads left to right.
		$periods = array_reverse($periods);
		foreach ($periods as $period) {
			$out['periods'][] = ['period_id' => (int) $period['period_id'],
			                     'end_date'  => (string) $period['end_date'],
			                     'label'     => ($period['period_name'] === null
			                                     || trim((string) $period['period_name']) === '')
			                                  ? date('Y', strtotime((string) $period['end_date']))
			                                  : (string) $period['period_name']];
		}

		$ids   = array_column($out['periods'], 'period_id');
		$marks = implode(',', array_fill(0, count($ids), '?'));

		$values = [];
		foreach (query("select item_name, period_id, value
		                  from financial_statement_values
		                 where symbol=? and period_id in (" . $marks . ")",
		               ...array_merge([$symbol], $ids)) as $row) {
			$values[(string) $row['item_name']][(int) $row['period_id']]
				= ($row['value'] === null) ? null : (float) $row['value'];
		}

		/* The lines, in the catalogue's own order. The catalogue is the
		   list of what a statement holds; this is the handful of it a
		   reader checks first. */
		$wanted = ['revenue' => 'Revenue', 'operating_income' => 'Operating profit',
		           'net_income' => 'Net profit', 'eps' => 'EPS',
		           'dividend_per_share' => 'Dividend per share',
		           'free_cash_flow' => 'Free cash flow', 'net_debt' => 'Net debt'];

		foreach ($wanted as $name => $label) {

			if (!isset($values[$name])) { continue; }

			$figures = [];
			foreach ($ids as $id) {
				$figures[] = isset($values[$name][$id]) ? $values[$name][$id] : null;
			}

			/* Year on year, last against the one before. Not shown where
			   the earlier year was nought or negative: a growth rate
			   measured from a loss is a number with no meaning. */
			$growth = null;
			$count  = count($figures);
			if ($count > 1 && $figures[$count - 1] !== null
			    && $figures[$count - 2] !== null && $figures[$count - 2] > 0) {
				$growth = (($figures[$count - 1] / $figures[$count - 2]) - 1) * 100;
			}

			$out['rows'][] = ['name' => $name, 'label' => $label,
			                  'figures' => $figures, 'growth' => $growth];
		}

		return $out;
	}

	/**
	 * One figure from the accounts.
	 *
	 * A revenue and an earnings per share are both numbers out of the
	 * same table and nothing else about them is alike: one is billions
	 * and the other is pennies, and one formatter for both prints
	 * "16,486,000,000.00" beside "0.19".
	 */
	function quote_account_figure($name, $value) {

		if (view_blank($value)) { return VIEW_DASH; }

		//Per share, as reported: a few units, to two places.
		if (in_array($name, ['eps', 'dividend_per_share'], true)) {
			return number_format((float) $value, 2);
		}

		return fmt_money_compact((float) $value, '');
	}

	/**
	 * How a measure is printed. The page shows ratios, percentages stored
	 * as fractions, percentages stored as percentages and two scores, and
	 * one formatter for all of them writes a return as a ratio.
	 */
	function quote_measure_html($name, $value) {

		if (view_blank($value)) {
			return view_missing_html('the provider did not supply this figure');
		}

		if (in_array($name, ['close_price', 'ma50', 'ma200'], true)) {
			//Pence, like every other price on the page.
			return htmlspecialchars(fmt_pence($value), ENT_QUOTES);
		}
		if (in_array($name, ['div_yield', 'buyback_yield', 'shareholder_yield'], true)) {
			return htmlspecialchars(fmt_yield((float) $value * 100), ENT_QUOTES);
		}
		/* Fractions, every one of them: FMP reports a growth rate as 0.122
		   for 12.2% and a return on equity as 1.089 for 108.9%, and the
		   loader stores what it is sent. Printed as they stand they come
		   out a hundred times too small - "+1.3%" for an EPS that
		   more than doubled - which is the unit mistake this application
		   has made before with pence. */
		if (in_array($name, ['revenue_growth', 'earnings_growth'], true)) {
			return move_pct_html((float) $value * 100);
		}
		if (substr($name, 0, 15) === 'news_sentiment_') {
			return htmlspecialchars(fmt_sentiment($value), ENT_QUOTES);
		}
		if (substr($name, 0, 11) === 'news_count_') {
			return htmlspecialchars(fmt_quantity($value), ENT_QUOTES);
		}
		if (in_array($name, ['roe', 'roe_ttm', 'roce', 'operating_margin'], true)) {
			return htmlspecialchars(fmt_pct((float) $value * 100), ENT_QUOTES);
		}
		if ($name === 'piotroski_score') {
			return htmlspecialchars(fmt_score($value) . ' of 9', ENT_QUOTES);
		}
		return htmlspecialchars(fmt_ratio($value, ($name === 'altman_z') ? 2 : 1), ENT_QUOTES);
	}

	/** The same, for a median, which is never coloured and never a score. */
	function quote_median_html($name, $value) {

		if (view_blank($value)) { return htmlspecialchars(VIEW_DASH); }

		if (in_array($name, ['close_price', 'ma50', 'ma200'], true)) {
			return htmlspecialchars(fmt_pence($value), ENT_QUOTES);
		}
		if (substr($name, 0, 15) === 'news_sentiment_') {
			return htmlspecialchars(fmt_sentiment($value), ENT_QUOTES);
		}
		if (substr($name, 0, 11) === 'news_count_') {
			return htmlspecialchars(fmt_quantity($value), ENT_QUOTES);
		}
		if (in_array($name, ['div_yield', 'buyback_yield', 'shareholder_yield'], true)) {
			return htmlspecialchars(fmt_yield((float) $value * 100), ENT_QUOTES);
		}
		if (in_array($name, ['revenue_growth', 'earnings_growth', 'roe', 'roe_ttm',
		                     'roce', 'operating_margin'], true)) {
			return htmlspecialchars(fmt_pct((float) $value * 100), ENT_QUOTES);
		}
		return htmlspecialchars(fmt_ratio($value, 1), ENT_QUOTES);
	}

?>