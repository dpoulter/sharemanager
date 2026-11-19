<?php

	/**
	 * Create a strategy from a screen: rules and no account.
	 *
	 * The screen's Create strategy button leads to a form and the form posts
	 * here. What it makes is a row in strategy_rules - selection 'screen' and
	 * that screen - and NOTHING ELSE. No account exists, so nothing can trade:
	 * not paper, not practice, not live. The account comes later, from the
	 * strategy's own page, in two steps (practice_start.php), after a backtest
	 * of these rules.
	 *
	 * A web POST by a logged in user and nothing else: never a job, never the
	 * command line, never a GET. Every field is checked here, on the server,
	 * whatever the browser did. The screen is not changed.
	 *
	 * Strategies not built on a screen (selection 'score') keep working and
	 * get no create form.
	 */

	require_once("constants.php");
	require_once(__DIR__ . "/strategy_functions.php");

	/** The ranks a screen strategy may be ordered by. */
	function strategy_create_rank_options() {
		return ['6mnth', '3mnth', '12mnth', 'momentum_score', 'value_score',
		        'quality_score', 'overall_score'];
	}

	/** The exit rules the engine knows. */
	function strategy_create_exit_options() {
		return ['below_ma200_2d', 'delisted'];
	}

	/**
	 * The name turned into the key the tables use: lower case letters,
	 * digits and underscores. Null if nothing is left, or it would be one of
	 * the reserved names (a leading underscore, the backtest prefix).
	 */
	function strategy_create_key($name) {

		$key = strtolower(trim((string) $name));
		$key = trim((string) preg_replace('~[^a-z0-9]+~', '_', $key), '_');

		if ($key === '' || strategy_reserved($key)) { return null; }
		return substr($key, 0, 50);
	}

	/**
	 * $options: user_id, screen_id, name, holdings, rank_by, hold_rank,
	 * rebalance, exit_rules (array or comma string), exclude_funds.
	 *
	 * Returns ['ok'=>bool, 'why'=>string, 'strategy'=>key when ok].
	 */
	function strategy_create_from_screen($options = []) {

		$refuse = function ($why) {
			write_log("strategy_create", "refused: " . $why);
			return ['ok' => false, 'why' => $why];
		};

		if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
			return $refuse("a strategy is created by a person on the screens page, in their own"
			             . " logged in session. The command line cannot create one.");
		}
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
			return $refuse("creating a strategy is a form, so it is a POST and nothing else.");
		}
		if (!strategy_rules_ready()) {
			return $refuse("the strategy tables are not there yet: apply sql/strategy_rules.sql.");
		}

		$user_id = isset($options['user_id']) ? (int) $options['user_id'] : 0;
		$users = $user_id > 0 ? query("select id from users where id=?", $user_id) : [];
		if (!count($users)) { return $refuse("creating a strategy needs somebody logged in."); }

		$screen_id = isset($options['screen_id']) ? (int) $options['screen_id'] : 0;
		$screens = $screen_id > 0 ? query("select id, name from screen where id=?", $screen_id) : [];
		if (!count($screens)) { return $refuse("there is no such screen."); }

		$criteria = query("select count(*) n from screen_build where screen_id=?", $screen_id);
		if (!count($criteria) || (int) $criteria[0]['n'] < 1) {
			return $refuse("that screen has no criteria, so it would select every share.");
		}

		$name = trim((string) ($options['name'] ?? ''));
		if (strlen($name) < 3 || strlen($name) > 50) {
			return $refuse("the name has to be between 3 and 50 characters.");
		}
		$key = strategy_create_key($name);
		if ($key === null) {
			return $refuse("that name cannot be used: it needs letters or digits, and cannot"
			             . " start with an underscore or bt_.");
		}
		if (count(query("select 1 from strategy_rules where strategy=?", $key))
		    || count(query("select 1 from strategy_accounts where strategy=?", $key))) {
			return $refuse("there is already a strategy called \"" . $key . "\".");
		}

		$holdings_raw = trim((string) ($options['holdings'] ?? ''));
		$holdings = $holdings_raw === '' ? 15 : (ctype_digit($holdings_raw) ? (int) $holdings_raw : 0);
		if ($holdings < 1 || $holdings > 50) {
			return $refuse("holdings has to be a whole number from 1 to 50.");
		}

		$rank_by = trim((string) ($options['rank_by'] ?? ''));
		if ($rank_by === '') { $rank_by = '6mnth'; }
		if (!in_array($rank_by, strategy_create_rank_options(), true)) {
			return $refuse("'" . $rank_by . "' is not something a strategy can be ranked by.");
		}

		$hold_raw = trim((string) ($options['hold_rank'] ?? ''));
		$hold_rank = null;
		if ($hold_raw !== '') {
			if (!ctype_digit($hold_raw) || (int) $hold_raw < $holdings || (int) $hold_raw > 200) {
				return $refuse("the hold rank has to be a whole number from the holdings ("
				             . $holdings . ") to 200, or empty.");
			}
			$hold_rank = (int) $hold_raw;
		}

		$rebalance = strtolower(trim((string) ($options['rebalance'] ?? '')));
		if ($rebalance === '') { $rebalance = 'monthly'; }
		if (!in_array($rebalance, ['monthly', 'weekly', 'daily'], true)) {
			return $refuse("rebalance has to be monthly, weekly or daily.");
		}

		$exits = $options['exit_rules'] ?? [];
		if (!is_array($exits)) { $exits = explode(',', (string) $exits); }
		$exits = array_values(array_unique(array_filter(array_map(function ($x) {
			return strtolower(trim((string) $x));
		}, $exits), function ($x) { return $x !== ''; })));
		foreach ($exits as $exit) {
			if (!in_array($exit, strategy_create_exit_options(), true)) {
				return $refuse("'" . $exit . "' is not an exit rule the engine knows.");
			}
		}

		$funds = strtoupper(trim((string) ($options['exclude_funds'] ?? 'Y')));
		if (!in_array($funds, ['Y', 'N'], true)) { return $refuse("exclude funds is Y or N."); }

		$has_hold = count(query("select 1 from information_schema.columns
		                          where table_schema=database() and table_name='strategy_rules'
		                            and column_name='hold_rank'")) > 0;

		query("insert into strategy_rules (strategy, selection, screen_id, rank_by, holdings,"
		      . ($has_hold ? " hold_rank," : "")
		      . " rebalance, exit_rules, exclude_funds, updated_at, note)
		       values (?, 'screen', ?, ?, ?,"
		      . ($has_hold ? " ?," : "")
		      . " ?, ?, ?, now(), ?)",
		      ...array_merge([$key, $screen_id, $rank_by, $holdings],
		                     $has_hold ? [$hold_rank] : [],
		                     [$rebalance, implode(',', $exits), $funds,
		                      "Built from screen '" . $screens[0]['name'] . "'. No account yet."]));

		write_log("strategy_create", "created " . $key . " from screen " . $screen_id);

		return ['ok' => true, 'why' => '', 'strategy' => $key];
	}

/* No closing tag: see the note at the end of constants.php. */
