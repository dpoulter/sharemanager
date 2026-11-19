<?php

	/**
	 * Which screen a strategy was built from, and which strategies a screen
	 * feeds.
	 *
	 * Read only, and safe to call from any page. The link is the screen id on
	 * the strategy's rules row: nothing here stores anything of its own, so
	 * the link cannot disagree with the rules.
	 *
	 * The screen's edit page uses strategies_for_screen() to name every
	 * strategy an edit would send back to Draft, archived ones marked.
	 */

	require_once("constants.php");
	require_once(__DIR__ . "/strategy_functions.php");

	/** Whether strategy_rules has the archived column (sql/strategy_workflow.sql). */
	function screen_links_archive_known() {

		static $known = null;
		if ($known !== null) { return $known; }

		$rows = query("select count(*) n from information_schema.columns
		                where table_schema=database() and table_name='strategy_rules'
		                  and column_name='archived_at'");
		$known = count($rows) && (int) $rows[0]['n'] > 0;
		return $known;
	}

	/** The screen a strategy is built on: ['id'=>, 'name'=>], or null. */
	function screen_of_strategy($strategy) {

		if (!strategy_rules_ready()) { return null; }

		$rows = query("select s.id, s.name from strategy_rules r, screen s
		                where r.strategy=? and r.selection='screen' and r.screen_id=s.id",
		              (string) $strategy);
		return count($rows) ? ['id' => (int) $rows[0]['id'], 'name' => (string) $rows[0]['name']] : null;
	}

	/**
	 * The strategies built on a screen, by name, each with whether it is
	 * archived. The backtest accounts (bt_) are not strategies and are left
	 * out.
	 */
	function strategies_for_screen($screen_id) {

		if (!strategy_rules_ready()) { return []; }

		$archived = screen_links_archive_known() ? "archived_at is not null" : "0";
		$rows = query("select strategy, (" . $archived . ") archived from strategy_rules
		                where selection='screen' and screen_id=? order by strategy", (int) $screen_id);

		$out = [];
		foreach ($rows as $row) {
			if (strategy_reserved($row['strategy'])) { continue; }
			$out[] = ['strategy' => (string) $row['strategy'],
			          'name'     => strategy_display_name($row['strategy']),
			          'archived' => (bool) $row['archived']];
		}
		return $out;
	}

/* No closing tag: see the note at the end of constants.php. */
