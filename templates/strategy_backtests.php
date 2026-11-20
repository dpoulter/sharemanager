<?php
	/**
	 * A table of backtest runs.
	 *
	 * Drawn twice: in one strategy's Backtest tab, and in the Backtests
	 * view with every strategy's runs in one table. The same columns both
	 * times, so a run read in one place is the run read in the other.
	 *
	 * Expects $runs (from strategy_backtest_rows()) and
	 * $show_strategy_column to be in scope.
	 *
	 * The caveat is printed once, at the top, rather than under every row:
	 * four copies of the same sentence is four times the words and none of
	 * the emphasis.
	 */
	$current = [];
	$earlier = [];
	foreach ($runs as $run) {
		if ($run['superseded']) { $earlier[] = $run; } else { $current[] = $run; }
	}
	$any_unfinished = false;
	foreach ($runs as $run) { if ($run['status'] !== 'DONE') { $any_unfinished = true; } }
?>

<p class="text-body-secondary">
	<small>
		The same rules replayed over stored history through the same engine.
		<strong>Not a record of trading.</strong>
		Flattered by survivorship: the universe is today's FTSE 350, so the
		companies that left the index are missing.
	</small>
</p>

<?php
	/* The two tables are the same markup, so it is written once and called
	   twice: the current runs, then the ones a later run with the same
	   settings has superseded. */
	$draw = function ($rows) use ($show_strategy_column, $any_unfinished) {
?>
<div class="table-responsive mb-3">
	<table class="table table-sm sm-table-sort sm-table-wide">
		<thead>
			<tr>
				<?php if ($show_strategy_column): ?><th data-sort="text">Strategy</th><?php endif ?>
				<th data-sort="text">Settings</th>
				<th data-sort="text">Period</th>
				<th class="sm-num" data-sort="number">Return</th>
				<th class="sm-num" data-sort="number">A year</th>
				<th class="sm-num" data-sort="number">Benchmark</th>
				<th class="sm-num" data-sort="number">Difference</th>
				<th class="sm-num" data-sort="number">Worst fall</th>
				<th class="sm-num" data-sort="number">Trades</th>
				<th class="sm-num" data-sort="number">Costs</th>
				<th class="sm-num" data-sort="number">Refused</th>
				<?php if ($any_unfinished): ?><th data-sort="text">Status</th><?php endif ?>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($rows as $run): ?>
			<?php
				$difference = ($run['total_return'] === null || $run['benchmark_return'] === null)
				            ? null : (float) $run['total_return'] - (float) $run['benchmark_return'];
				/* The costs set against the money that went in: GBP53,553
				   means nothing until it is read against GBP100,000. The
				   start is the account's starting cash, not the first
				   night's value, which is already down by the first
				   build's costs. */
				$bt_start = (array_key_exists('starting_cash', $run) && $run['starting_cash'] !== null)
				          ? (float) $run['starting_cash'] : (float) $run['start_value'];
			?>
			<tr>
				<?php if ($show_strategy_column): ?>
				<td title="<?= e((string) $run['strategy']) ?>" data-bs-toggle="tooltip"><?=
					e(strategy_display_name((string) $run['strategy'])) ?></td>
				<?php endif ?>
				<td class="sm-col-wide">
					<span class="badge bg-secondary sm-engine" data-engine="<?= ($run['engine'] ?? null) === 'mac_mini' ? 'mac_mini' : 'n100' ?>"><?=
						($run['engine'] ?? null) === 'mac_mini' ? 'Mac mini run' : 'N100 run' ?></span>
					<?= e((string) $run['label']) ?>
					<?php if ($run['matches_live']): ?>
						<span class="badge bg-success">matches live settings</span>
					<?php endif ?>
				</td>
				<td class="sm-nowrap"><?= s_date($run['from_date']) ?> to <?= s_date($run['to_date']) ?></td>
				<td class="sm-num<?= s_colour($run['total_return']) ?>" data-value="<?= e((string) $run['total_return']) ?>"><?= s_percent($run['total_return']) ?></td>
				<td class="sm-num<?= s_colour($run['a_year']) ?>" data-value="<?= e((string) $run['a_year']) ?>"><?= s_percent($run['a_year']) ?></td>
				<td class="sm-num<?= s_colour($run['benchmark_return']) ?>" data-value="<?= e((string) $run['benchmark_return']) ?>"><?= s_percent($run['benchmark_return']) ?></td>
				<td class="sm-num<?= s_colour($difference) ?>" data-value="<?= e((string) $difference) ?>"><?= s_percent($difference) ?></td>
				<td class="sm-num" data-value="<?= e((string) $run['max_drawdown']) ?>"><?= $run['max_drawdown'] === null
					? VIEW_DASH : move_pct_html(-(float) $run['max_drawdown'] * 100) ?></td>
				<td class="sm-num" data-value="<?= e((string) $run['fills']) ?>"><?= e(fmt_quantity($run['fills'])) ?></td>
				<td class="sm-num" data-value="<?= e((string) $run['costs_paid']) ?>">
					<?= s_money($run['costs_paid'], 2) ?>
					<?php if ($bt_start > 0 && $run['costs_paid'] !== null): ?>
					<br><small class="text-body-secondary"><?= e(fmt_pct(
						(float) $run['costs_paid'] / $bt_start * 100, 1)) ?> of the start</small>
					<?php endif ?>
				</td>
				<td class="sm-num" data-value="<?= e((string) ($run['turnover_refusals'] ?? 0)) ?>"><?=
					e(fmt_quantity($run['turnover_refusals'] ?? 0)) ?></td>
				<?php if ($any_unfinished): ?>
				<td><?= htmlspecialchars((string) $run['status']) ?></td>
				<?php endif ?>
			</tr>
			<?php if ($run['note'] !== null && trim((string) $run['note']) !== ''): ?>
			<tr>
				<td colspan="<?= 11 + ($show_strategy_column ? 1 : 0) + ($any_unfinished ? 1 : 0) ?>"
				    class="text-body-secondary"><small><?= htmlspecialchars((string) $run['note']) ?></small></td>
			</tr>
			<?php endif ?>
		<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php
	};
?>

<?php if (!count($current) && !count($earlier)): ?>
	<p class="text-body-secondary">No backtest has been run yet.</p>
<?php endif ?>

<?php if (count($current)) { $draw($current); } ?>

<?php if (count($earlier)): ?>
<details>
	<summary>Earlier runs (<?= count($earlier) ?>)</summary>
	<p class="text-body-secondary mt-2 mb-1"><small>
		A later run with the same settings has answered the same question since.
	</small></p>
	<?php $draw($earlier); ?>
</details>
<?php endif ?>
