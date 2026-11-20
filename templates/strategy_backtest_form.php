<?php
/**
 * Ask for a backtest of a strategy, and see the ones asked for.
 *
 * Expects $bt_strategy (the key) and $stage (strategy_stage() for it). The
 * form only QUEUES a run: the Mac mini research engine claims it, runs it and
 * posts the result back. The move is open or closed by strategy_stage() on
 * the server; this shows the reason. The end date stops before the holdout,
 * which backtest_request() checks again on the server.
 */
$bt_move = $stage['moves']['backtest'] ?? ['open' => false, 'reason' => 'not available'];
$bt_first = BACKTEST_EARLIEST;
$bt_last  = date('Y-m-d', strtotime(BACKTEST_HOLDOUT_START . ' -1 day'));
$bt_waiting = function_exists('backtest_runs_for') ? backtest_runs_for($bt_strategy, 5) : [];
$bt_results = function_exists('backtest_mac_mini_results') ? backtest_mac_mini_results($bt_strategy, 5) : [];
$bt_pct = function ($v) { return $v === null ? '&ndash;' : e(number_format((float) $v * 100, 2)) . '%'; };
?>
<div class="sm-backtest-form mb-3" id="sm-backtest-form">
	<h3 class="h6">Backtest these rules</h3>

	<?php foreach ($bt_waiting as $w): ?>
		<?php if (in_array($w['status'], ['QUEUED', 'RUNNING', 'FAILED', 'REFUSED'], true)): ?>
		<div class="small <?= in_array($w['status'], ['FAILED', 'REFUSED'], true) ? 'text-danger' : 'text-muted' ?>"
		     data-run="<?= (int) $w['run_id'] ?>" data-status="<?= e($w['status']) ?>">
			<?= e(backtest_engine_label($w)) ?> run <?= (int) $w['run_id'] ?>, <?= e((string) $w['from_date']) ?> to <?= e((string) $w['to_date']) ?>:
			<strong><?= e(ucfirst(strtolower($w['status']))) ?></strong><?=
				$w['status'] === 'QUEUED' ? ' &mdash; waiting for the Mac mini to claim it' : '' ?><?=
				$w['status'] === 'RUNNING' ? ' &mdash; on the Mac mini' : '' ?><?=
				in_array($w['status'], ['FAILED', 'REFUSED'], true) && trim((string) $w['note']) !== ''
					? ' &mdash; ' . e((string) $w['note']) : '' ?>
		</div>
		<?php endif ?>
	<?php endforeach ?>

	<?php foreach ($bt_results as $r): ?>
		<div class="small mt-2 sm-backtest-result" data-run="<?= (int) $r['run_id'] ?>" data-engine="mac_mini">
			<strong>Mac mini run <?= (int) $r['run_id'] ?></strong>, <?= e((string) $r['from_date']) ?> to <?= e((string) $r['to_date']) ?>:
			return <?= $bt_pct($r['total_return']) ?>
			(<?= $bt_pct($r['annual_return']) ?> a year);
			equal-weight universe <?= $bt_pct($r['benchmark_return']) ?>,
			cap-weighted universe <?= $bt_pct($r['cap_weight_return']) ?>;
			largest fall <?= $bt_pct($r['max_drawdown']) ?>;
			<?= (int) $r['fills'] ?> trades, costs &pound;<?= e(number_format((float) $r['costs_paid'], 2)) ?>;
			<span class="sm-dsr"><?= e(backtest_dsr_words($r)) ?>.</span>
			<span class="text-muted">Engine run <?= e((string) $r['research_run_id']) ?> at commit <?= e((string) $r['engine_commit']) ?>.</span>
			<?php if (backtest_report_path($r) !== null): ?>
				<a href="backtest_report.php?run=<?= (int) $r['run_id'] ?>" target="_blank" rel="noopener">Report</a>
			<?php endif ?>
			<?php foreach (backtest_result_labels($r) as $label): ?>
				<div class="text-muted sm-backtest-label"><?= e($label) ?></div>
			<?php endforeach ?>
		</div>
	<?php endforeach ?>

	<?php if (!$bt_move['open']): ?>
		<p class="small text-muted mb-0">Not available now: <?= e($bt_move['reason']) ?>.</p>
	<?php else: ?>
	<form method="post" action="strategies.php" class="row g-2 align-items-end">
		<input type="hidden" name="do" value="backtest">
		<input type="hidden" name="strategy" value="<?= e($bt_strategy) ?>">
		<div class="col-6 col-md-3">
			<label class="form-label mb-0"><small>From</small></label>
			<input type="date" name="from" class="form-control form-control-sm" required
			       min="<?= e($bt_first) ?>" max="<?= e($bt_last) ?>" value="<?= e($bt_first) ?>">
		</div>
		<div class="col-6 col-md-3">
			<label class="form-label mb-0"><small>To</small></label>
			<input type="date" name="to" class="form-control form-control-sm" required
			       min="<?= e($bt_first) ?>" max="<?= e($bt_last) ?>" value="<?= e($bt_last) ?>">
		</div>
		<div class="col-6 col-md-2">
			<label class="form-label mb-0"><small>Starting cash &pound;</small></label>
			<input type="number" name="cash" class="form-control form-control-sm" min="1" step="any"
			       value="<?= e((string) (int) STRATEGY_BACKTEST_CASH) ?>">
		</div>
		<div class="col-6 col-md-2">
			<label class="form-label mb-0"><small>Costs</small></label>
			<select name="cost_model" class="form-select form-select-sm">
				<?php foreach (backtest_cost_models() as $key => $words): ?>
				<option value="<?= e($key) ?>"><?= e($words) ?></option>
				<?php endforeach ?>
			</select>
		</div>
		<div class="col-12 col-md-2">
			<button type="submit" class="btn btn-sm btn-outline-primary">Queue a backtest</button>
		</div>
		<div class="col-12"><small class="text-muted">
			Queues the run for the Mac mini research engine, which runs one at a time; nothing is
			replayed on this server. The end date stops before <?= e(BACKTEST_HOLDOUT_START) ?>, where
			the 18-month holdout starts.
		</small></div>
	</form>
	<?php endif ?>
</div>
