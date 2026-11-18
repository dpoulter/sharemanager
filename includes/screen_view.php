<?php

	/**
	 * screen_view.php - the screens list and what a run produced.
	 *
	 * The list was a radio button per screen and two buttons under the
	 * table, so choosing a screen and choosing what to do with it were two
	 * separate decisions in two separate places. It is a row per screen with
	 * its own Run and Edit now, which needs the list to know a few things
	 * about each screen it never used to: how many shares it matches, when it
	 * was last run, and whether it can be run at all.
	 */

	require_once("share_screen.php");

	/* Every operator build_screen() knows what to do with. A criterion with
	   anything else in it is a criterion that silently selects nothing, and
	   a screen made of those runs to an empty page with no explanation. */
	function screen_operators() {
		return ['>', '>=', '<', '<=', '=', '!=', 'top_n', 'top_percent'];
	}

	/**
	 * Why a screen cannot be run, or [] if it can.
	 *
	 * Returns a list of sentences. The list page shows them instead of a Run
	 * button, because a screen with no usable criteria does not return no
	 * shares - it returns every share, or none, depending on which rule is
	 * missing, and either way the reader is being shown an answer to a
	 * question nobody asked.
	 */
	function screen_problems($screen_id) {

		$problems = [];

		$criteria = query("select sc.id, sc.operator, sc.description,
		                          sc.first_operand, sc.second_operand,
		                          si.name indicator
		                     from screen_build sb
		                     join screen_criteria sc on sb.criteria_id=sc.id
		                     left join screen_indicators si on si.indicator_id=sc.indicator_id
		                    where sb.screen_id=? order by sb.criteria_id", $screen_id);

		if (!count($criteria)) { return ['No criteria have been added to this screen.']; }

		foreach ($criteria as $criterion) {

			$label = trim((string) $criterion['description']) !== ''
			       ? $criterion['description'] : ('criterion ' . $criterion['id']);

			if ($criterion['indicator'] === null) {
				$problems[] = $label . ': its indicator no longer exists.';
				continue;
			}
			if (!in_array((string) $criterion['operator'], screen_operators(), true)) {
				$problems[] = $label . ': "' . $criterion['operator'] . '" is not an operator the screen builder knows.';
				continue;
			}
			$first  = trim((string) $criterion['first_operand']);
			$second = trim((string) $criterion['second_operand']);

			if ($first === '' && $second === '') {
				$problems[] = $label . ': it compares against nothing.';
				continue;
			}

			/* What the criterion is compared against.
			 *
			 * build_screen() puts first_operand into the SQL as it stands,
			 * so it has to be a number; when it is empty, second_operand is
			 * looked up as another indicator instead. A first_operand
			 * holding an indicator's name - "3mnth" where "0" belongs -
			 * becomes "AND s.value> 3mnth", which is a column reference to a
			 * column that does not exist, and the page dies with an SQL
			 * error rather than showing a screen. That is what is wrong with
			 * the Quality Momentum screen.
			 */
			if ($criterion['operator'] === 'top_n' || $criterion['operator'] === 'top_percent') {
				if (!is_numeric($first)) {
					$problems[] = $label . ': "' . $first . '" is not a number, and '
					            . $criterion['operator'] . ' needs one.';
				}
				continue;
			}

			if ($first !== '' && !is_numeric($first)) {
				$problems[] = $label . ': it compares against "' . $first
				            . '", which is neither a number nor an empty box with an'
				            . ' indicator chosen beside it.';
				continue;
			}

			if ($first === '') {
				$known = query("select count(*) n from screen_indicators where name=?", $second);
				if (!count($known) || (int) $known[0]['n'] === 0) {
					$problems[] = $label . ': it compares against "' . $second
					            . '", which is not an indicator.';
				}
			}
		}

		return $problems;
	}

	/**
	 * The screens, with what the list page shows about each.
	 *
	 * The match count is the screen actually run. It is the only honest
	 * answer to "how many does this find tonight", and a screen is a handful
	 * of queries rather than a scan.
	 */
	function screen_list_rows($with_counts = true) {

		$rows = [];

		$has_last_run = screen_has_last_run();

		foreach (query("select id, name, description"
		               . ($has_last_run ? ", last_run" : "")
		               . " from screen where displayed='Y' order by name") as $screen) {

			$problems = screen_problems($screen['id']);

			$rows[] = [
				'id'          => (int) $screen['id'],
				'name'        => $screen['name'],
				'description' => $screen['description'],
				'last_run'    => $has_last_run ? $screen['last_run'] : null,
				'problems'    => $problems,
				'matches'     => (count($problems) || !$with_counts)
				               ? null : count(build_screen($screen['id'], null)),
			];
		}

		return $rows;
	}

	/**
	 * Whether the last_run column is there.
	 *
	 * sql/screen_last_run.sql adds it. Guarded so the page reads correctly on
	 * a host that is one deploy behind the migration rather than failing on
	 * an unknown column.
	 */
	function screen_has_last_run() {
		static $known = null;
		if ($known !== null) { return $known; }
		$found = query("select count(*) n from information_schema.columns
		                 where table_schema=database() and table_name='screen'
		                   and column_name='last_run'");
		$known = count($found) && (int) $found[0]['n'] > 0;
		return $known;
	}

	/** Stamp a screen as run. Quiet when the migration has not been applied. */
	function screen_mark_run($screen_id) {
		if (!screen_has_last_run()) { return; }
		query("update screen set last_run=now() where id=?", (int) $screen_id);
	}

	/**
	 * What the screen was ranked by, in words.
	 *
	 * The rows come back in the order of the last top_n criterion, which is
	 * the order the page shows them in - so the page should say so rather
	 * than leave a reader to assume alphabetical.
	 */
	function screen_rank_words($screen_id) {

		$rows = query("select si.description, si.rank_order
		                 from screen_build sb
		                 join screen_criteria sc on sb.criteria_id=sc.id
		                 join screen_indicators si on si.indicator_id=sc.indicator_id
		                where sb.screen_id=? and sc.operator='top_n'
		                order by sb.criteria_id desc limit 1", $screen_id);

		if (!count($rows)) { return null; }

		$ascending = stripos((string) $rows[0]['rank_order'], 'asc') !== false;
		return 'Ranked by ' . strtolower((string) $rows[0]['description'])
		     . ($ascending ? ', lowest first' : ', highest first');
	}

	/**
	 * The results of a run: one row per matching share.
	 *
	 * The screen's own indicators, and then the four figures every screen
	 * should show whatever it was built from - the price, the six month
	 * move and the three scores. A list of tickers against one ratio is not
	 * enough to decide anything with, and following each one to its quote
	 * page to find out was the only way to see them.
	 */
	function screen_result_rows($symbols, $indicators, $as_of, $exchange = null) {

		$exchange = $exchange === null ? session_exchange() : $exchange;
		if (!count($symbols)) { return []; }

		$wanted = array_column($indicators, 'name');
		foreach (array_keys(screen_extra_columns()) as $extra) {
			if (!in_array($extra, $wanted, true)) { $wanted[] = $extra; }
		}

		/* One query for every figure on the page rather than one per share
		   per indicator. The old page ran a statistics query per screen run
		   and then walked it in PHP twice over for every row. */
		$symbol_marks = implode(',', array_fill(0, count($symbols), '?'));
		$ind_marks    = implode(',', array_fill(0, count($wanted), '?'));
		$args = array_merge([$as_of], array_column($symbols, 'symbol'), $wanted);

		$found = [];
		foreach (call_user_func_array('query', array_merge(
		             ["select symbol, indicator, value from statistics
		                where date=? and symbol in ($symbol_marks)
		                  and indicator in ($ind_marks)"], $args)) as $row) {
			$found[$row['symbol']][$row['indicator']] = $row['value'];
		}

		$names = [];
		foreach (call_user_func_array('query', array_merge(
		             ["select symbol, name from stock_symbols
		                where exchange=? and symbol in ($symbol_marks)"],
		             array_merge([$exchange], array_column($symbols, 'symbol')))) as $row) {
			$names[$row['symbol']] = $row['name'];
		}

		/* In the order they arrived, which is the order of the last top_n. */
		$out = [];
		foreach ($symbols as $symbol) {
			$code = $symbol['symbol'];
			$row = ['symbol' => $code,
			        'name'   => isset($names[$code]) ? $names[$code] : null,
			        'values' => []];
			foreach ($indicators as $indicator) {
				$row['values'][$indicator['name']] =
					isset($found[$code][$indicator['name']]) ? $found[$code][$indicator['name']] : null;
			}
			foreach (screen_extra_columns() as $indicator => $key) {
				$row[$key] = isset($found[$code][$indicator]) ? $found[$code][$indicator] : null;
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * The four figures every screen shows whatever it was built from.
	 *
	 * In one place because the table, the CSV and the row builder all have
	 * to agree about them - and because a screen built on close_price was
	 * showing the latest close twice, once as its own column and once as
	 * the Price beside it.
	 */
	function screen_extra_columns() {
		/* ShareRank with the three it is built from: a row of results
		   that shows momentum, value and quality and not the number that
		   puts them together is three quarters of an answer. */
		return ['close_price' => 'price', '6mnth' => 'change_6m',
		        'momentum_score' => 'momentum', 'value_score' => 'value_score',
		        'quality_score' => 'quality', 'overall_score' => 'overall'];
	}

	/** Which of those a screen does not already have a column for. */
	function screen_extras_wanted($columns) {
		$have = array_column($columns, 'name');
		$out  = [];
		foreach (screen_extra_columns() as $indicator => $key) {
			if (!in_array($indicator, $have, true)) { $out[$indicator] = $key; }
		}
		return $out;
	}

	/**
	 * One cell of a screen's own column, formatted for what it is.
	 *
	 * A screen can be built on anything, so the column could be a price in
	 * pence, a percentage return, a score out of 100 or a ratio. Printed
	 * with one formatter they all come out as "196.82", which is a price
	 * written as a ratio and a return written as neither.
	 */
	function screen_cell_html($indicator, $value) {
		if (in_array($indicator, ['close_price', 'ma50', 'ma200'], true)) {
			return htmlspecialchars(fmt_pence($value), ENT_QUOTES);
		}
		if (in_array($indicator, ['1mnth', '3mnth', '6mnth', '12mnth'], true)) {
			//The momentum series is stored in per cent: -15.2 is -15.2%.
			return move_pct_html($value);
		}
		/* The growth rates are not. FMP reports them as fractions - 0.122
		   for 12.2%, 1.300 for an EPS that has more than doubled - and
		   this column printed them as they stood, so every growth figure
		   on a screen was a hundredth of itself and RR's EPS growth read
		   "+1.3%". */
		if (in_array($indicator, ['revenue_growth', 'earnings_growth'], true)) {
			return move_pct_html(view_blank($value) ? null : (float) $value * 100);
		}
		if (substr($indicator, -6) === '_score' || $indicator === 'piotroski_score') {
			return score_badge_html($value, $indicator);
		}
		if (in_array($indicator, ['div_yield', 'buyback_yield', 'shareholder_yield'], true)) {
			//Stored as fractions: 0.0412 is 4.12%.
			return view_blank($value) ? VIEW_DASH
			     : htmlspecialchars(fmt_yield((float) $value * 100), ENT_QUOTES);
		}
		return htmlspecialchars(fmt_ratio($value, 2), ENT_QUOTES);
	}

	/** The indicators a screen is built from, for its columns. */
	function screen_indicator_columns($screen_id) {
		return query("select distinct si.name, si.description
		                from screen_build sb
		                join screen_criteria sc on sb.criteria_id=sc.id
		                join screen_indicators si on si.indicator_id=sc.indicator_id
		               where sb.screen_id=?
		               union
		              select distinct sc.second_operand name, si.description
		                from screen_build sb
		                join screen_criteria sc on sb.criteria_id=sc.id
		                join screen_indicators si on si.name=sc.second_operand
		               where sb.screen_id=? and length(sc.second_operand)>0",
		             $screen_id, $screen_id);
	}

	/** The date a run describes: the statistics the screen was built from. */
	function screen_asof() {
		$rows = query("select date(max(job_date)) job_date from jobs
		                where job_name='get_statistics_asof'");
		return count($rows) ? $rows[0]['job_date'] : null;
	}

?>
