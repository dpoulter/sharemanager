<?php
/**
 * The screens.
 *
 * One row per screen with its own Run and Edit. It was a radio button per
 * screen and two buttons under the table, so choosing a screen and choosing
 * what to do with it were two decisions in two different places.
 *
 * A screen the builder cannot use gets no Run button and a sentence saying
 * what is wrong with it instead. Running it would not return no shares: it
 * would return every share or none, depending on which rule is missing.
 */
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
	<div>
		<h1>Screens</h1>
		<p class="sm-sub">
			What each one finds in tonight's statistics.
		</p>
	</div>
	<a class="btn btn-primary" href="screen_create.php">New screen</a>
</div>

<div class="card">
	<div class="table-responsive">
		<table class="table table-sm sm-table-sort mb-0">
			<thead>
				<tr>
					<th data-sort="text">Screen</th>
					<th data-sort="text">What it looks for</th>
					<th class="sm-num" data-sort="number">Matches</th>
					<th data-sort="text">Last run</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php if (!count($screens)): ?>
				<tr><td colspan="5" class="text-muted">
					No screens yet. <a href="screen_create.php">Build one</a>.
				</td></tr>
			<?php endif ?>
			<?php foreach ($screens as $screen): ?>
				<tr>
					<td><?= e($screen['name']) ?></td>
					<td>
						<?= e($screen['description']) ?>
						<?php if (count($screen['problems'])): ?>
							<div class="sm-down small">
							<?php foreach ($screen['problems'] as $problem): ?>
								<div><?= e($problem) ?></div>
							<?php endforeach ?>
							</div>
						<?php endif ?>
					</td>
					<td class="sm-num" data-value="<?= e($screen['matches']) ?>">
						<?php if (count($screen['problems'])): ?>
							<span class="sm-badge sm-score-none"
							      data-bs-toggle="tooltip"
							      title="<?= e(implode(' ', $screen['problems'])) ?>">no valid criteria</span>
						<?php else: ?>
							<?= e(fmt_quantity($screen['matches'])) ?>
						<?php endif ?>
					</td>
					<td data-value="<?= e($screen['last_run']) ?>">
						<?= $screen['last_run'] === null
						    ? view_missing_html("this screen has not been run since the run stamp was added")
						    : e(fmt_date($screen['last_run'])) ?>
					</td>
					<td class="text-end sm-nowrap">
						<?php if (!count($screen['problems'])): ?>
							<a class="btn btn-sm btn-outline-primary"
							   href="screen_list.php?screen=<?= (int) $screen['id'] ?>&amp;run=1">Run</a>
						<?php endif ?>
						<a class="btn btn-sm btn-outline-secondary"
						   href="screen_list.php?screen=<?= (int) $screen['id'] ?>&amp;edit=1">Edit</a>
						<?php if (!count($screen['problems'])): ?>
							<a class="btn btn-sm btn-outline-success"
							   href="strategy_create.php?screen=<?= (int) $screen['id'] ?>">Create strategy</a>
						<?php endif ?>
					</td>
				</tr>
			<?php endforeach ?>
			</tbody>
		</table>
	</div>
</div>
