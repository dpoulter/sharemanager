<?php
/**
 * A strategy that has rules and no account: its stepper, the screen it was
 * built from, and its rules. Nothing on this page can trade.
 *
 * Expects $draft, from strategy_stage(), and $stages.
 */
$stage = $draft;
$sm_rules = strategy_rules($draft['strategy']);
$sm_screen = screen_of_strategy($draft['strategy']);
?>
<div class="card mb-4" id="sm-draft">
	<div class="card-body">
		<h2 class="h5 card-title"><?= e(strategy_display_name($draft['strategy'])) ?>
			<span class="badge text-bg-light border">No account</span></h2>

		<?php include __DIR__ . '/strategy_stepper.php'; ?>

		<p class="small text-muted">
			This strategy has rules and no account, so nothing can trade: not on paper,
			not on practice, not live.
		</p>

		<?php if (function_exists('backtest_runs_ready') && backtest_runs_ready()): ?>
		<?php $bt_strategy = $draft['strategy']; include __DIR__ . '/strategy_backtest_form.php'; ?>
		<?php endif ?>

		<dl class="row small mb-0">
			<dt class="col-sm-3">Built from</dt>
			<dd class="col-sm-9"><?= $sm_screen === null ? 'no screen' : e($sm_screen['name']) ?></dd>
			<dt class="col-sm-3">Holdings</dt>
			<dd class="col-sm-9"><?= e(fmt_quantity($sm_rules['holdings'])) ?><?=
				$sm_rules['hold_rank'] === null ? '' : ', held until it falls out of the top ' . e((string) $sm_rules['hold_rank']) ?></dd>
			<dt class="col-sm-3">Ranked by</dt>
			<dd class="col-sm-9"><?= e((string) strategy_rank_words($sm_rules['rank_by'])) ?></dd>
			<dt class="col-sm-3">Rebalance</dt>
			<dd class="col-sm-9"><?= e((string) $sm_rules['rebalance']) ?></dd>
			<dt class="col-sm-3">Exits</dt>
			<dd class="col-sm-9"><?php
				if (!count($sm_rules['exit_list'])) { echo 'none between rebalances'; }
				foreach ($sm_rules['exit_list'] as $i => $exit) { echo ($i ? '; ' : '') . e(strategy_exit_words($exit)); }
			?></dd>
			<dt class="col-sm-3">Funds</dt>
			<dd class="col-sm-9"><?= $sm_rules['exclude_funds'] === 'Y' ? 'left out' : 'included' ?></dd>
		</dl>
	</div>
</div>
