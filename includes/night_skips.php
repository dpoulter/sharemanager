<?php

	/**
	 * Which strategies the night run leaves alone, and why.
	 *
	 * A strategy made from a screen has rules and no account until it is
	 * started on practice, and an archived one keeps its rules, runs, orders
	 * and accounts but must never be planned again. Neither is an error: the
	 * night run skips them, says so in one line each, and carries on. A
	 * strategy with no account is not in strategy_strategies() in the first
	 * place - it joins on the account - so what this adds is the line that
	 * says so, and the check that an archived strategy whose account was
	 * left switched on is still not run.
	 *
	 * The backtest copies (bt_) are not strategies and are not mentioned:
	 * they are reserved names, and strategy_strategies() already leaves them
	 * out.
	 *
	 * Reads only.
	 */

	require_once("constants.php");
	require_once(__DIR__ . "/strategy_stage.php");

	/**
	 * Split $strategies - the enabled ones with rules - into those to run and
	 * those to skip, and list the rules rows that have no account.
	 *
	 * Returns ['run' => [names], 'skipped' => [[name, reason], ...]].
	 */
	function night_skips(array $strategies) {

		$run = [];
		$skipped = [];

		foreach ($strategies as $strategy) {
			if (strategy_archived($strategy)) {
				$skipped[] = [$strategy, "archived"];
				continue;
			}
			$run[] = $strategy;
		}

		if (strategy_rules_ready()) {
			foreach (query("select r.strategy from strategy_rules r
			                 left join strategy_accounts a on a.strategy=r.strategy
			                where a.strategy is null order by r.strategy") as $row) {
				if (strategy_reserved($row['strategy'])) { continue; }
				$skipped[] = [(string) $row['strategy'],
				              strategy_archived($row['strategy']) ? "archived, no account" : "no account yet"];
			}
		}

		return ['run' => $run, 'skipped' => $skipped];
	}

/* No closing tag: see the note at the end of constants.php. */
