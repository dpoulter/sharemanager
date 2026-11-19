<?php
/**
 * Every strategy that has rules, by stage: the ones with no account yet
 * (drafts, which are in no other table on this page) and the archived ones.
 *
 * Expects $stages (strategy_stage_list()), $summary and $show_disabled.
 */
$sm_listed = [];
foreach ($summary as $row) { $sm_listed[$row['strategy']] = true; }
$sm_extra = array_filter($stages, function ($s) use ($sm_listed) { return !isset($sm_listed[$s['strategy']]); });
if (!count($sm_extra)) { return; }
?>
<h2 class="h5">Not trading yet</h2>
<p class="small text-muted">
	Strategies with rules and no account. Nothing here can trade; each is taken
	up the stages from its own page.
</p>
<div class="table-responsive mb-4">
	<table class="table table-sm sm-table-sort sm-stack" id="sm-stage-list">
		<thead>
			<tr>
				<th data-sort="text">Strategy</th>
				<th data-sort="text">Stage</th>
				<th data-sort="text">Screen</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($sm_extra as $s): ?>
			<tr>
				<td data-label="Strategy"><a href="<?= e(s_link(['s' => $s['strategy']], $show_disabled)) ?>"><?= e($s['name']) ?></a></td>
				<td data-label="Stage"><?= e($s['stage']) ?><?= $s['archived'] ? ' (archived)' : '' ?></td>
				<td data-label="Screen"><?= $s['screen'] === null ? VIEW_DASH : e($s['screen']['name']) ?></td>
			</tr>
		<?php endforeach ?>
		</tbody>
	</table>
</div>
