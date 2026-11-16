<?php

	/**
	 * home_functions.php - what the dashboard shows.
	 *
	 * The home page used to be the market outlook in full, then four lists of
	 * ticker and company name. It answered "what scored well last night" and
	 * nothing else: not what the portfolio is worth, not what the strategies
	 * did, not what was announced this week. These are the queries behind the
	 * page that answers those.
	 *
	 * Everything here reads. Nothing on the dashboard writes, and nothing on
	 * it calls the model - the outlook is whatever was last generated, and
	 * generating a new one is a button.
	 */

	require_once("portfolio_functions.php");

	/* ---------------------------------------------------------------------
	   The portfolio strip.
	   --------------------------------------------------------------------- */

	/**
	 * What the holdings are worth, what they did today, and the cash.
	 *
	 * Today's change is computed from the two most recent stored closes
	 * rather than from the performance snapshots: the snapshot job has to
	 * have run on both days for a difference between snapshots to mean
	 * anything, and when it has not the honest answer is "no change
	 * recorded", not a day's move that is really a week's.
	 *
	 * Prices are pence and holdings are worth pounds, so everything that
	 * leaves here is pounds.
	 */
	function home_portfolio_strip($session_id, $exchange = null) {

		$exchange = $exchange === null ? session_exchange() : $exchange;

		$strip = ['holdings' => 0, 'value' => 0.0, 'cash' => 0.0, 'total' => 0.0,
		          'gain' => null, 'day_change' => null, 'day_change_pct' => null,
		          'as_of' => null];

		/* Cash is the running total of the cash book, which is what every
		   other page on this application means by cash. */
		$cash = query("select sum(case trx_type when 'DEPOSIT' then amount
		                                        when 'BUY'     then -1*amount
		                                        when 'SELL'    then amount
		                                        when 'DELETE'  then -1*amount
		                                        else amount end) cash
		                 from cash_history where user_id=?", $session_id);
		$strip['cash'] = count($cash) && $cash[0]['cash'] !== null
		               ? (float) $cash[0]['cash'] : 0.0;

		$positions = get_active_positions($session_id);
		$strip['holdings'] = count($positions);

		if (!count($positions)) {
			$strip['total'] = $strip['cash'];
			return $strip;
		}

		$value = 0.0; $gain = 0.0;
		foreach ($positions as $position) {
			$value += (float) $position['value_raw'];
			$gain  += (float) $position['profit_raw'];
		}
		$strip['value'] = $value;
		$strip['gain']  = $gain;
		$strip['total'] = $value + $strip['cash'];

		/* The two most recent sessions the market traded. Two dates, not
		   "today and yesterday": a Monday's change is against Friday. */
		$dates = query("select distinct date from historical_prices
		                 where exchange=? order by date desc limit 2", $exchange);
		if (count($dates) < 2) { return $strip; }

		$today     = (string) $dates[0]['date'];
		$yesterday = (string) $dates[1]['date'];
		$strip['as_of'] = $today;

		$change = 0.0; $base = 0.0; $priced = 0;
		foreach ($positions as $position) {
			$held = (int) $position['qty_purchased'] - (int) $position['qty_sold'];
			if ($held <= 0) { continue; }
			$prices = query("select date, price from historical_prices
			                  where symbol=? and exchange=? and date in (?, ?)",
			                $position['symbol'], $exchange, $today, $yesterday);
			$by_date = [];
			foreach ($prices as $price) { $by_date[(string) $price['date']] = (float) $price['price']; }
			if (!isset($by_date[$today]) || !isset($by_date[$yesterday])) { continue; }
			$priced++;
			$change += ($by_date[$today] - $by_date[$yesterday]) * $held / 100;
			$base   += $by_date[$yesterday] * $held / 100;
		}

		/* No holding had a close on both days: the honest answer is that no
		   change is known, not that nothing moved. */
		if ($priced === 0) { return $strip; }

		$strip['day_change']     = $change;
		$strip['day_change_pct'] = $base > 0 ? ($change / $base) * 100 : null;

		return $strip;
	}

	/* ---------------------------------------------------------------------
	   The market state card.
	   --------------------------------------------------------------------- */

	/**
	 * How much of the market is in an uptrend, as a six month line.
	 *
	 * The same rule as outlook_market_inputs() - a close above the 200 day
	 * average - applied to every stored date rather than only to the latest
	 * one, so the figure on the card has a shape behind it. One query: the
	 * per symbol pivot happens in a derived table and the proportion is
	 * counted per date.
	 *
	 * Returns [] when the statistics do not go back far enough, and the card
	 * then shows the figure without a line rather than an empty box.
	 */
	function home_breadth_history($as_of, $months = 6, $exchange = null) {

		$exchange = $exchange === null ? session_exchange() : $exchange;
		$months   = max(1, (int) $months);
		$from     = date('Y-m-d', strtotime($as_of . ' -' . $months . ' month'));

		return query("select d.date, round(sum(d.above)/count(*)*100, 1) pct
		                from (select s.date, s.symbol,
		                             max(case when s.indicator='ma200' then s.value end) ma200,
		                             case when max(case when s.indicator='close_price' then s.value end)
		                                     > max(case when s.indicator='ma200' then s.value end)
		                                  then 1 else 0 end above
		                        from statistics s, stock_symbols ss
		                       where ss.symbol=s.symbol and ss.enabled='Y' and ss.exchange=?
		                         and s.exchange=? and s.indicator in ('close_price','ma200')
		                         and s.date between ? and ?
		                       group by s.date, s.symbol
		                      having ma200 is not null) d
		               group by d.date order by d.date",
		             $exchange, $exchange, $from, $as_of);
	}

	/* ---------------------------------------------------------------------
	   The ranked lists.
	   --------------------------------------------------------------------- */

	/** The four scores the dashboard ranks by, and what to call each one. */
	function home_scores() {
		return [
			'momentum' => ['indicator' => 'momentum_score', 'title' => 'Momentum',
			               'blurb' => 'Shares that have gone up, ranked on 3, 6 and 12 month returns.'],
			'value'    => ['indicator' => 'value_score',    'title' => 'Value',
			               'blurb' => 'Shares that look cheap on earnings, book, sales and cash flow.'],
			'quality'  => ['indicator' => 'quality_score',  'title' => 'Quality',
			               'blurb' => 'Shares that earn well on what they employ, with sound finances.'],
			'overall'  => ['indicator' => 'overall_score',  'title' => 'Overall',
			               'blurb' => 'The three scores together: the ShareRank.'],
		];
	}

	/**
	 * One ranked list: ticker, company, score, last price and six month move.
	 *
	 * Four near-identical copies of this query used to sit in
	 * share_functions.php, one per score, and each returned the ticker, the
	 * name and nothing else - so the dashboard could rank shares without
	 * saying what any of the ranks were.
	 *
	 * $limit null means the whole universe, which is what the full list page
	 * asks for.
	 */
	function home_ranked($score, $limit = 10, $exchange = null) {

		$scores = home_scores();
		if (!isset($scores[$score])) { return []; }
		$indicator = $scores[$score]['indicator'];
		$exchange  = $exchange === null ? session_exchange() : $exchange;

		/* The date the statistics describe, not the day the job ran. */
		$dates = query("select max(date) as_of from statistics
		                 where indicator=? and exchange=?", $indicator, $exchange);
		$as_of = count($dates) ? $dates[0]['as_of'] : null;
		if ($as_of === null) { return []; }

		/* MariaDB will not take a placeholder for LIMIT when the driver is
		   not emulating prepares, so the integer is cast and interpolated. */
		$tail = $limit === null ? '' : ' limit ' . max(1, (int) $limit);

		return query("select ss.symbol, ss.name,
		                     sc.value score,
		                     cp.value price,
		                     mo.value change_6m
		                from statistics sc
		                join stock_symbols ss
		                  on ss.symbol=sc.symbol and ss.enabled='Y' and ss.exchange=?
		                left join statistics cp
		                  on cp.symbol=sc.symbol and cp.exchange=sc.exchange
		                 and cp.date=sc.date and cp.indicator='close_price'
		                left join statistics mo
		                  on mo.symbol=sc.symbol and mo.exchange=sc.exchange
		                 and mo.date=sc.date and mo.indicator='6mnth'
		               where sc.indicator=? and sc.exchange=? and sc.date=?"
		             . not_fund_sql('ss.symbol')
		             . " order by sc.value desc, ss.symbol" . $tail,
		             $exchange, $indicator, $exchange, $as_of);
	}

	/** The date the ranked lists describe, for the heading. */
	function home_ranked_asof($score, $exchange = null) {
		$scores = home_scores();
		if (!isset($scores[$score])) { return null; }
		$exchange = $exchange === null ? session_exchange() : $exchange;
		$dates = query("select max(date) as_of from statistics
		                 where indicator=? and exchange=?",
		               $scores[$score]['indicator'], $exchange);
		return count($dates) ? $dates[0]['as_of'] : null;
	}

	/* ---------------------------------------------------------------------
	   News this week.
	   --------------------------------------------------------------------- */

	/**
	 * The five best and five worst shares by sentiment this week, each with
	 * what the share has done since it announced.
	 *
	 * rns_movers() ranks them; the move comes off the announcement rows,
	 * where the nightly job stores it. A company with several announcements
	 * in the week is one entry, as it is on "In the news", and the move shown
	 * is the one since its strongest announcement - the move since the
	 * weakest of three would be describing a different event.
	 */
	function home_news_week($from, $to, $limit = 5, $exchange = null) {

		$exchange = $exchange === null ? session_exchange() : $exchange;
		$movers   = rns_movers($from, $to, $limit, $exchange);

		foreach (['best', 'worst'] as $side) {
			foreach ($movers[$side] as $i => $row) {
				$strongest = query("select sentiment, price_move, title, published_at
				                      from rns_announcements
				                     where symbol=? and skipped='N' and sentiment is not null
				                       and date(published_at) between ? and ?
				                     order by " . ($side === 'best' ? 'sentiment desc' : 'sentiment asc')
				                   . ", published_at desc limit 1",
				                   $row['symbol'], $from, $to);
				$movers[$side][$i]['price_move'] = count($strongest) && $strongest[0]['price_move'] !== null
				                                 ? (float) $strongest[0]['price_move'] : null;
				$movers[$side][$i]['title']      = count($strongest) ? $strongest[0]['title'] : null;
				$movers[$side][$i]['published']  = count($strongest) ? $strongest[0]['published_at'] : null;
			}
		}

		return $movers;
	}

	/**
	 * The window "this week" means: the last seven trading days, counted off
	 * the stored closes rather than off the calendar, so a bank holiday does
	 * not quietly shorten it.
	 */
	function home_news_window($as_of, $days = 7, $exchange = null) {
		$exchange = $exchange === null ? session_exchange() : $exchange;
		$dates = query("select distinct date from historical_prices
		                 where exchange=? and date<=? order by date desc limit "
		               . (max(1, (int) $days) + 1), $exchange, $as_of);
		return count($dates) ? (string) end($dates)['date']
		                     : date('Y-m-d', strtotime($as_of . ' -' . (int) $days . ' day'));
	}

	/* ---------------------------------------------------------------------
	   The strategies row.
	   --------------------------------------------------------------------- */

	/**
	 * One card per strategy that is switched on.
	 *
	 * strategy_card() does the work; this exists so the dashboard does not
	 * have to know that a disabled strategy is still in strategy_accounts,
	 * or that the tables may not be there at all on a host where the
	 * migration has not been applied.
	 */
	function home_strategy_rows($exchange = null) {
		if (!function_exists('strategy_rules_ready') || !strategy_rules_ready()) { return []; }
		$rows = [];
		foreach (strategy_strategies() as $strategy) {
			$rows[] = strategy_card($strategy, $exchange);
		}
		return $rows;
	}

?>
