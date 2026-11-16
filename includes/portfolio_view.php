<?php

	/**
	 * portfolio_view.php - what the Portfolio page shows.
	 *
	 * The page was the oldest in the application: four unlabelled numbers in
	 * a <pre> block, a stray "6m" where a variable had been printed by
	 * accident, an empty chart box, and a table header with nothing under
	 * it. The queries behind the rebuilt page are here.
	 *
	 * Prices are pence throughout, as stored. Everything that leaves here
	 * describing money is pounds - a holding worth 1,350.6p a share is worth
	 * pounds, and a portfolio quoted in pence is a portfolio nobody can
	 * read.
	 */

	require_once("portfolio_functions.php");

	/* How close to the 200 day average counts as "about to cross it". The
	   sell rule is a close below the average; a holding 1% above it is a
	   holding that may be sold this week, and the table should say so. */
	define("PORTFOLIO_TREND_WARNING", 0.02);

	/**
	 * The strip at the top.
	 *
	 * invested is what the holdings cost, so gain is value minus cost, and
	 * the percentage is against the cost rather than against the value -
	 * a 50% gain is 100 becoming 150, not 150 of which 50 is gain.
	 */
	function portfolio_summary($session_id, $exchange = null) {

		$exchange = $exchange === null ? session_exchange() : $exchange;

		$summary = ['holdings' => 0, 'value' => 0.0, 'cash' => 0.0, 'invested' => 0.0,
		            'total' => 0.0, 'gain' => null, 'gain_pct' => null,
		            'day_change' => null, 'day_change_pct' => null,
		            'dividends_tax_year' => 0.0, 'tax_year' => null, 'as_of' => null];

		$strip = home_portfolio_strip($session_id, $exchange);
		$summary['holdings']       = $strip['holdings'];
		$summary['value']          = $strip['value'];
		$summary['cash']           = $strip['cash'];
		$summary['total']          = $strip['total'];
		$summary['gain']           = $strip['gain'];
		$summary['day_change']     = $strip['day_change'];
		$summary['day_change_pct'] = $strip['day_change_pct'];
		$summary['as_of']          = $strip['as_of'];

		/* What the holdings cost: the average price paid times the shares
		   still held. Commission is in the gain rather than here, because
		   "invested" is what went into the shares. */
		$invested = 0.0;
		foreach (get_active_positions($session_id) as $position) {
			$held = (int) $position['qty_purchased'] - (int) $position['qty_sold'];
			if ($held <= 0) { continue; }
			$invested += (float) $position['price_paid'] * $held / 100;
		}
		$summary['invested'] = $invested;
		$summary['gain_pct'] = ($invested > 0 && $summary['gain'] !== null)
		                     ? ($summary['gain'] / $invested) * 100 : null;

		[$year_from, $year_to] = portfolio_tax_year();
		$summary['tax_year'] = $year_from;
		$rows = query("select ifnull(sum(amount),0) total from dividends
		                where session_id=? and dividend_date between ? and ?",
		              $session_id, $year_from, $year_to);
		$summary['dividends_tax_year'] = count($rows) ? (float) $rows[0]['total'] : 0.0;

		return $summary;
	}

	/**
	 * The UK tax year containing a date: 6 April to 5 April.
	 *
	 * Not the calendar year. "Dividends received this year" against a
	 * calendar year is a figure that matches nothing on a tax return.
	 */
	function portfolio_tax_year($today = null) {
		$today = $today === null ? date('Y-m-d') : substr((string) $today, 0, 10);
		$year  = (int) substr($today, 0, 4);
		if ($today < sprintf('%04d-04-06', $year)) { $year--; }
		return [sprintf('%04d-04-06', $year), sprintf('%04d-04-05', $year + 1)];
	}

	/**
	 * One row per holding, with everything the table shows.
	 *
	 * The scores and the trend come from the statistics the nightly job
	 * writes, at the date it last wrote them. A holding the job has never
	 * scored gets nulls rather than a neutral 50: "not scored" and "middling"
	 * are different facts, and the first one is about the job.
	 */
	function portfolio_holdings($session_id, $exchange = null) {

		$exchange = $exchange === null ? session_exchange() : $exchange;

		$positions = get_active_positions($session_id);
		if (!count($positions)) { return []; }

		$stats_date = query("select max(date) as_of from statistics where exchange=?", $exchange);
		$as_of = count($stats_date) ? $stats_date[0]['as_of'] : null;

		$total_value = 0.0;
		$rows = [];

		foreach ($positions as $position) {

			$held = (int) $position['qty_purchased'] - (int) $position['qty_sold'];
			if ($held <= 0) { continue; }

			$price = (float) $position['price'];
			$value = (float) $position['value_raw'];
			$total_value += $value;

			$row = [
				'symbol'    => $position['symbol'],
				'name'      => $position['name'],
				'shares'    => $held,
				'avg_cost'  => (float) $position['price_paid'],
				'price'     => $price,
				'value'     => $value,
				'gain'      => (float) $position['profit_raw'],
				'gain_pct'  => is_numeric($position['profit_perc'])
				             ? (float) $position['profit_perc'] : null,
				'weight'    => null,
				'today_pct' => null,
				'momentum'  => null, 'value_score' => null, 'quality' => null,
				'ma200'     => null, 'trend' => 'unknown',
			];

			/* The share's own last two closes, not the exchange's last two
			   dates: a share with a gap in its history would otherwise show
			   no move, which reads as flat rather than as unknown. */
			$move = portfolio_day_move($position['symbol'], $exchange);
			if ($move !== null) {
				$row['today_pct'] = ($move['change'] / $move['previous']) * 100;
			}

			if ($as_of !== null) {
				$stats = query("select indicator, value from statistics
				                 where symbol=? and exchange=? and date=?
				                   and indicator in ('momentum_score','value_score',
				                                     'quality_score','ma200')",
				               $position['symbol'], $exchange, $as_of);
				$found = [];
				foreach ($stats as $stat) { $found[$stat['indicator']] = $stat['value']; }
				$row['momentum']    = isset($found['momentum_score']) ? (float) $found['momentum_score'] : null;
				$row['value_score'] = isset($found['value_score'])    ? (float) $found['value_score'] : null;
				$row['quality']     = isset($found['quality_score'])  ? (float) $found['quality_score'] : null;
				$row['ma200']       = isset($found['ma200'])          ? (float) $found['ma200'] : null;
			}

			/* The sell rule is a close below the 200 day average, so the
			   table says three things rather than two: above it, within 2%
			   of it, or below it. A holding 1% above the average is one that
			   may be sold this week. */
			if ($row['ma200'] !== null && $row['ma200'] > 0) {
				$gap = ($price / $row['ma200']) - 1;
				if ($gap < 0)                             { $row['trend'] = 'below'; }
				else if ($gap <= PORTFOLIO_TREND_WARNING) { $row['trend'] = 'near'; }
				else                                      { $row['trend'] = 'above'; }
			}

			$rows[] = $row;
		}

		/* Weights last, because a weight needs the total. Against the
		   holdings rather than against holdings plus cash: it answers "how
		   much of what I own is this", and cash is not something that was
		   chosen. */
		foreach ($rows as $i => $row) {
			$rows[$i]['weight'] = $total_value > 0 ? ($row['value'] / $total_value) * 100 : null;
		}

		return $rows;
	}

	/** Positions that have been closed, for the Sold tab. */
	function portfolio_sold($session_id) {
		$rows = [];
		foreach (get_inactive_positions($session_id) as $position) {
			$rows[] = [
				'symbol'     => $position['symbol'],
				'name'       => $position['name'],
				'shares'     => (int) $position['qty_sold'],
				'avg_cost'   => (float) $position['price_paid'],
				'price_sold' => is_numeric($position['price_sold'])
				              ? (float) $position['price_sold'] : null,
				'commission' => (float) $position['commission'],
				'dividends'  => (float) $position['dividends'],
				'gain'       => (float) $position['profit_raw'],
				'gain_pct'   => is_numeric($position['profit_perc'])
				              ? (float) $position['profit_perc'] : null,
			];
		}
		return $rows;
	}

	/** Dividends received, newest first, for the Dividends tab. */
	function portfolio_dividends($session_id, $limit = 200) {
		$limit = max(1, (int) $limit);
		return query("select d.dividend_id, d.symbol, ss.name, d.dividend_date, d.amount
		                from dividends d
		                left join stock_symbols ss
		                  on ss.symbol=d.symbol and ss.exchange=?
		               where d.session_id=?
		               order by d.dividend_date desc, d.dividend_id desc
		               limit " . $limit, session_exchange(), $session_id);
	}

	/** Deposits and withdrawals, newest first, for the Cash tab. */
	function portfolio_cash($session_id, $limit = 200) {
		$limit = max(1, (int) $limit);
		return query("select transaction_date date, trx_type type, amount
		                from cash_history where user_id=?
		               order by transaction_date desc, id desc
		               limit " . $limit, $session_id);
	}

	/**
	 * What the account has been worth, night by night.
	 *
	 * From the performance snapshots the nightly job writes. An account the
	 * job has not run for yet has none, and the page says so rather than
	 * drawing an empty box - which is what it used to do.
	 */
	function portfolio_series($session_id, $days = 365) {
		$days = max(1, (int) $days);
		$rows = query("select performance_date date,
		                      total_value, cash, total_profit
		                 from performance
		                where session_id=?
		                  and performance_date >= date_sub(curdate(), interval " . $days . " day)
		                order by performance_date", $session_id);
		$out = [];
		foreach ($rows as $row) {
			$out[] = ['date'  => substr((string) $row['date'], 0, 10),
			          'total' => (float) $row['total_value'] + (float) $row['cash'],
			          'value' => (float) $row['total_value'],
			          'cash'  => (float) $row['cash']];
		}
		return $out;
	}

	/**
	 * The benchmark, rebased so the two lines start together.
	 *
	 * The equal-weight FTSE 350 the strategies are measured against, so the
	 * portfolio is compared with the same thing the paper strategies are. An
	 * absolute level would be a line in the thousands beside a line in the
	 * tens of thousands, which compares nothing.
	 *
	 * Returns [] when the benchmark has not been built, and the chart then
	 * draws one line.
	 */
	function portfolio_benchmark($from, $to, $start_value) {

		if (!function_exists('strategy_rules_ready') || !strategy_rules_ready()) { return []; }
		if (!is_numeric($start_value) || (float) $start_value <= 0) { return []; }

		$rows = query("select date, total from strategy_values
		                where strategy=? and date between ? and ? order by date",
		              STRATEGY_BENCHMARK, $from, $to);
		if (count($rows) < 2) { return []; }

		$base = (float) $rows[0]['total'];
		if ($base <= 0) { return []; }

		$out = [];
		foreach ($rows as $row) {
			$out[] = ['date'  => substr((string) $row['date'], 0, 10),
			          'total' => round(((float) $row['total'] / $base) * (float) $start_value, 2)];
		}
		return $out;
	}

?>
