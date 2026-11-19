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
</div>
