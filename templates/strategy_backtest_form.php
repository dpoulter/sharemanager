<?php
/**
 * Ask for a backtest of a strategy, and see the ones asked for.
 *
 * Expects $bt_strategy (the key) and $stage (strategy_stage() for it). The
 * form only QUEUES a run: a job outside the web server replays it, one at a
 * time, and the result appears in the table. The move is open or closed by
 * strategy_stage() on the server; this shows the reason.
 */
$bt_move = $stage['moves']['backtest'] ?? ['open' => false, 'reason' => 'not available'];
$bt_data = query("select min(date) first, max(date) last from historical_prices where exchange='XLON'");
$bt_first = count($bt_data) ? (string) $bt_data[0]['first'] : '';
$bt_last  = count($bt_data) ? (string) $bt_data[0]['last'] : '';
$bt_waiting = function_exists('backtest_runs_for') ? backtest_runs_for($bt_strategy, 5) : [];
?>
<div class="sm-backtest-form mb-3" id="sm-backtest-form">
	<h3 class="h6">Backtest these rules</h3>

	<?php foreach ($bt_waiting as $w): ?>
		<?php if (in_array($w['status'], ['QUEUED', 'RUNNING', 'FAILED', 'REFUSED'], true)): ?>
		<div class="small <?= in_array($w['status'], ['FAILED', 'REFUSED'], true) ? 'text-danger' : 'text-muted' ?>"
		     data-run="<?= (int) $w['run_id'] ?>" data-status="<?= e($w['status']) ?>">
			Run <?= (int) $w['run_id'] ?>, <?= e((string) $w['from_date']) ?> to <?= e((string) $w['to_date']) ?>:
			<strong><?= e(ucfirst(strtolower($w['status']))) ?></strong><?=
				$w['status'] === 'QUEUED' ? ' &mdash; waiting for the job, which looks every minute' : '' ?><?=
				in_array($w['status'], ['FAILED', 'REFUSED'], true) && trim((string) $w['note']) !== ''
					? ' &mdash; ' . e((string) $w['note']) : '' ?>
		</div>
		<?php endif ?>
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
			Queues the run. A job outside the web server replays it, one at a time; nothing is
			replayed while you wait.
		</small></div>
	</form>
	<?php endif ?>
</div>
