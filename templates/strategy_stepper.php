<?php
/**
 * The stage stepper: Draft, Backtested, Practice, Live, with where this
 * strategy is, and the moves open or closed with the reason.
 *
 * Expects $stage, from strategy_stage(). It only SHOWS what the server
 * derived; every handler of a move asks strategy_stage() again, so a button
 * here is a convenience and never the check.
 */
if (empty($stage)) { return; }
$sm_steps = strategy_stages();
?>
<div class="sm-stepper mb-3" data-stage="<?= e($stage['stage']) ?>">
	<ol class="list-inline mb-1">
		<?php foreach ($sm_steps as $i => $step): ?>
		<li class="list-inline-item">
			<span class="badge <?= $i === $stage['index'] ? 'text-bg-primary'
			                      : ($i < $stage['index'] ? 'text-bg-secondary' : 'text-bg-light border') ?>"
			      <?= $i === $stage['index'] ? 'aria-current="step"' : '' ?>><?= e($step) ?></span>
		</li>
		<?php if ($i < count($sm_steps) - 1): ?><li class="list-inline-item text-muted" aria-hidden="true">&rarr;</li><?php endif ?>
		<?php endforeach ?>
		<?php if ($stage['archived']): ?>
		<li class="list-inline-item"><span class="badge text-bg-dark">Archived</span></li>
		<?php endif ?>
	</ol>
	<ul class="small text-muted list-unstyled mb-0 sm-moves">
		<?php foreach (strategy_stage_moves() as $move => $label): ?>
		<?php $m = $stage['moves'][$move]; ?>
		<li data-move="<?= e($move) ?>" data-open="<?= $m['open'] ? 'yes' : 'no' ?>">
			<strong><?= e($label) ?>:</strong>
			<?= $m['open'] ? 'open' : 'closed &mdash; ' . e($m['reason']) ?>
		</li>
		<?php endforeach ?>
	</ul>

	<?php /* The moves that are open, as forms. The server asks strategy_stage()
	         again when one is posted: a button here is a convenience. */ ?>
	<div class="sm-move-forms d-flex flex-wrap gap-2 mt-2">
		<?php $sm_mv = $stage['moves']; $sm_key = $stage['strategy']; ?>

		<?php if ($sm_mv['start_practice']['open']): ?>
		<form method="post" action="strategies.php" class="d-flex gap-1 align-items-end">
			<input type="hidden" name="do" value="practice_start">
			<input type="hidden" name="step" value="1">
			<input type="hidden" name="strategy" value="<?= e($sm_key) ?>">
			<div>
				<label class="form-label mb-0"><small>Practice capital &pound;</small></label>
				<input type="number" name="capital" class="form-control form-control-sm" min="1" step="any" required>
			</div>
			<button type="submit" class="btn btn-sm btn-outline-primary">Start on practice</button>
		</form>
		<?php endif ?>

		<?php if ($stage['mode'] === 'T212_DEMO' && empty($stage['account_enabled']) && !$stage['archived']): ?>
		<form method="post" action="strategies.php">
			<input type="hidden" name="do" value="practice_start">
			<input type="hidden" name="step" value="2">
			<input type="hidden" name="strategy" value="<?= e($sm_key) ?>">
			<button type="submit" class="btn btn-sm btn-primary">Switch on the practice account</button>
		</form>
		<?php endif ?>

		<?php foreach (['stop_practice' => 'Stop practice', 'stop_live' => 'Stop live (disarm)', 'archive' => 'Archive'] as $sm_step => $sm_label): ?>
		<?php if ($sm_mv[$sm_step]['open']): ?>
		<form method="post" action="strategies.php">
			<input type="hidden" name="do" value="stepdown">
			<input type="hidden" name="step" value="<?= e($sm_step) ?>">
			<input type="hidden" name="strategy" value="<?= e($sm_key) ?>">
			<button type="submit" class="btn btn-sm btn-outline-secondary"><?= e($sm_label) ?></button>
		</form>
		<?php endif ?>
		<?php endforeach ?>
	</div>
</div>
