<?php

	/**
	 * The rules fingerprint: one value that changes whenever anything that
	 * decides what a strategy holds changes.
	 *
	 * The ONLY place that knows which rule fields, and which criteria of a
	 * screen, count as "the rules". The stage (strategy_stage.php) and the
	 * backtest runs (backtest_runs.php) both ask here whether the rules have
	 * changed since a run, and neither compares rules itself: a second copy
	 * of this list is a second opinion about whether a strategy is the one
	 * that was tested.
	 *
	 * What counts: selection, the screen, the score indicator, how it is
	 * ranked, how many holdings, the hold rank, the rebalance, the exit
	 * rules, whether funds are excluded - and, for a strategy built on a
	 * screen, the criteria of that screen, in the order the builder applies
	 * them. Editing a criterion therefore returns every strategy built on
	 * the screen to Draft, which is the point.
	 *
	 * What does not: the name, whether the strategy is enabled, its account,
	 * its mode, its capital, its note, and the date it may start. None of
	 * them changes which shares it picks.
	 *
	 * Values are read through strategy_rules(), which normalises them, so
	 * "Monthly" and "monthly", or a hold rank below the holdings, give the
	 * same value as the engine would act on.
	 *
	 * Reads only. Never stored as the strategy's status.
	 */

	require_once("constants.php");
	require_once(__DIR__ . "/strategy_functions.php");

	/**
	 * The criteria of a screen, in the order the builder applies them, as
	 * their content rather than their ids: re-adding the same criterion gives
	 * the same fingerprint, and a changed operand does not.
	 */
	function rules_screen_criteria($screen_id) {

		$rows = query("select sc.indicator_id, sc.operator, sc.first_operand, sc.second_operand
		                 from screen_build sb, screen_criteria sc
		                where sb.screen_id=? and sb.criteria_id=sc.id
		                order by sc.id", (int) $screen_id);

		$out = [];
		foreach ($rows as $row) {
			$out[] = [(string) $row['indicator_id'], (string) $row['operator'],
			          (string) $row['first_operand'], (string) $row['second_operand']];
		}
		return $out;
	}

	/**
	 * What the rules are right now, as a plain array: the fields above and,
	 * for a screen strategy, the screen's criteria. This is what a backtest
	 * run keeps as its snapshot. Null if the strategy has no rules.
	 */
	function rules_snapshot($strategy) {

		$rules = strategy_rules((string) $strategy);
		if ($rules === null) { return null; }

		$exits = $rules['exit_list'];
		sort($exits);

		$screen_id = ($rules['selection'] === 'screen' && $rules['screen_id'] !== null)
		           ? (int) $rules['screen_id'] : null;

		return [
			'rules' => [
				'selection'       => (string) $rules['selection'],
				'screen_id'       => $screen_id,
				'score_indicator' => $rules['score_indicator'],
				'rank_by'         => $rules['rank_by'],
				'holdings'        => (int) $rules['holdings'],
				'hold_rank'       => $rules['hold_rank'] === null ? null : (int) $rules['hold_rank'],
				'rebalance'       => (string) $rules['rebalance'],
				'exit_rules'      => implode(',', $exits),
				'exclude_funds'   => (string) $rules['exclude_funds'],
			],
			'criteria' => $screen_id === null ? [] : rules_screen_criteria($screen_id),
		];
	}

	/** The value for a snapshot. Same snapshot, same value. */
	function rules_fingerprint_of(array $snapshot) {
		return sha1(json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/** The value for a strategy's rules as they are now, or null. */
	function rules_fingerprint($strategy) {
		$snapshot = rules_snapshot($strategy);
		return $snapshot === null ? null : rules_fingerprint_of($snapshot);
	}

/* No closing tag: see the note at the end of constants.php. */
