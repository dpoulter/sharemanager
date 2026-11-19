<?php
/**
 * The form behind a screen's Create strategy button.
 *
 * Makes rules and no account. Defaults are the ones the existing strategies
 * use: 15 holdings, ranked by the 6 month return, monthly, funds excluded.
 */
$val = function ($name, $default = '') use ($posted) {
	return isset($posted[$name]) && is_string($posted[$name]) ? $posted[$name] : $default;
};
?>

<h1>Create a strategy</h1>
<p class="sm-sub">
	From the screen <strong><?= e($screen['name']) ?></strong>
	<?php if (trim((string) $screen['description']) !== ''): ?>
		&mdash; <?= e($screen['description']) ?>
	<?php endif ?>
</p>

<?php if ($message !== null): ?>
<div class="alert alert-danger py-2">Not created: <?= e($message) ?></div>
<?php endif ?>

<div class="card">
	<div class="card-body">
		<p class="small text-muted">
			This makes the rules and nothing else. There is no account, so nothing can trade
			&mdash; not on paper, not on practice, not live. Backtest it first, then start it on
			practice from its own page.
		</p>
		<form method="post" action="strategy_create.php" class="row g-3">
			<input type="hidden" name="screen" value="<?= (int) $screen['id'] ?>">

			<div class="col-12 col-md-6">
				<label class="form-label" for="sc-name">Name</label>
				<input type="text" class="form-control" id="sc-name" name="name" required
				       minlength="3" maxlength="50" value="<?= e($val('name')) ?>">
			</div>
			<div class="col-6 col-md-3">
				<label class="form-label" for="sc-holdings">Holdings</label>
				<input type="number" class="form-control" id="sc-holdings" name="holdings"
				       min="1" max="50" value="<?= e($val('holdings', '15')) ?>">
			</div>
			<div class="col-6 col-md-3">
				<label class="form-label" for="sc-hold">Hold rank <small class="text-muted">(optional)</small></label>
				<input type="number" class="form-control" id="sc-hold" name="hold_rank"
				       min="1" max="200" value="<?= e($val('hold_rank')) ?>">
			</div>
			<div class="col-6 col-md-3">
				<label class="form-label" for="sc-rank">Rank by</label>
				<select class="form-select" id="sc-rank" name="rank_by">
					<?php foreach ($ranks as $rank): ?>
					<option value="<?= e($rank) ?>"<?= $val('rank_by', '6mnth') === $rank ? ' selected' : '' ?>><?=
						e($rank) ?></option>
					<?php endforeach ?>
				</select>
			</div>
			<div class="col-6 col-md-3">
				<label class="form-label" for="sc-rebalance">Rebalance</label>
				<select class="form-select" id="sc-rebalance" name="rebalance">
					<?php foreach (['monthly', 'weekly', 'daily'] as $when): ?>
					<option value="<?= e($when) ?>"<?= $val('rebalance', 'monthly') === $when ? ' selected' : '' ?>><?=
						e($when) ?></option>
					<?php endforeach ?>
				</select>
			</div>
			<div class="col-12 col-md-6">
				<span class="form-label d-block">Exit rules</span>
				<?php foreach ($exits as $exit): ?>
				<div class="form-check">
					<input class="form-check-input" type="checkbox" name="exit_rules[]"
					       id="sc-exit-<?= e($exit) ?>" value="<?= e($exit) ?>"
					       <?= in_array($exit, (array) ($posted['exit_rules'] ?? []), true) ? 'checked' : '' ?>>
					<label class="form-check-label" for="sc-exit-<?= e($exit) ?>"><?= e(strategy_exit_words($exit)) ?></label>
				</div>
				<?php endforeach ?>
			</div>
			<div class="col-12">
				<div class="form-check">
					<input class="form-check-input" type="checkbox" id="sc-funds" name="exclude_funds" value="Y"
					       <?= ($posted === [] || $val('exclude_funds') === 'Y') ? 'checked' : '' ?>>
					<label class="form-check-label" for="sc-funds">Leave funds out</label>
				</div>
			</div>
			<div class="col-12">
				<button type="submit" class="btn btn-primary">Create strategy</button>
				<a class="btn btn-outline-secondary" href="screen_list.php">Cancel</a>
			</div>
		</form>
	</div>
</div>
