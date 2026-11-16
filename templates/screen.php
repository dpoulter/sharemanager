<?php
/**
 * What a screen found.
 *
 * The heading says which screen, what it looks for, how many it matched and
 * at which date - the old page printed nothing but a title that was never
 * echoed (<?php $title ?> with no echo) and a table of raw statistics.
 *
 * Every screen shows the price, the six month move and the three scores as
 * well as its own columns. A list of tickers against one ratio is not enough
 * to decide anything with, and following each one to its quote page to find
 * out was the only way to see them.
 */
?>

<h1><?= e($screen['name']) ?></h1>
<p class="sm-sub">
	<?= e($screen['description']) ?>
	<a href="screen_list.php">All screens</a> &middot;
	<a href="screen_list.php?screen=<?= (int) $screen_id ?>&amp;edit=1">Edit this screen</a>
	<?= score_key_html() ?>
</p>

<?php if (count($problems)): ?>
	<div class="alert alert-warning">
		<strong>This screen cannot be run.</strong>
		<ul class="mb-0">
		<?php foreach ($problems as $problem): ?>
			<li><?= e($problem) ?></li>
		<?php endforeach ?>
		</ul>
	</div>
<?php else: ?>

<div class="card">
	<div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
		<span>
			<?= e(fmt_quantity(count($rows))) ?>
			<?= count($rows) === 1 ? 'share' : 'shares' ?>
			at the <?= e(fmt_date($as_of)) ?> close.
			<?php if ($rank_words !== null): ?>
				<span class="text-muted"><?= e($rank_words) ?>.</span>
			<?php endif ?>
		</span>
		<?php /* The same rows as the table, as a file. A screen's output is
		         something somebody wants to keep. */ ?>
		<a class="btn btn-sm btn-outline-secondary"
		   href="screen_csv.php?screen=<?= (int) $screen_id ?>">Download CSV</a>
	</div>
	<div class="table-responsive">
		<table class="table table-sm table-hover sm-table-sort mb-0">
			<thead>
				<tr>
					<th class="sm-num" data-sort="number">#</th>
					<th data-sort="text">Share</th>
					<th data-sort="text">Company</th>
					<?php foreach ($columns as $column): ?>
						<th class="sm-num" data-sort="number"><?= e($column['description']) ?></th>
					<?php endforeach ?>
					<?php /* Only the ones the screen has not already got a column
					         for. A screen built on the latest close was showing it
					         twice, once as its own column and once as Price. */ ?>
					<?php if (isset($extras['close_price'])): ?>
						<th class="sm-num" data-sort="number">Price</th>
					<?php endif ?>
					<?php if (isset($extras['6mnth'])): ?>
						<th class="sm-num" data-sort="number">6 months</th>
					<?php endif ?>
					<?php if (isset($extras['momentum_score']) || isset($extras['value_score'])
					          || isset($extras['quality_score'])): ?>
						<th class="sm-badges-tight">Scores</th>
					<?php endif ?>
				</tr>
			</thead>
			<tbody>
			<?php if (!count($rows)): ?>
				<tr><td colspan="<?= 3 + count($columns) + count($extras) ?>" class="text-muted">
					Nothing matched tonight. The criteria are on the Edit page.
				</td></tr>
			<?php endif ?>
			<?php foreach ($rows as $i => $row): ?>
				<tr>
					<td class="sm-num" data-value="<?= (int) ($i + 1) ?>"><?= (int) ($i + 1) ?></td>
					<td><a href="quote.php?symbol=<?= urlencode($row['symbol']) ?>&amp;screen_id=<?= (int) $screen_id ?>"><?= e($row['symbol']) ?></a></td>
					<td><?= e($row['name']) ?></td>
					<?php foreach ($columns as $column): ?>
						<td class="sm-num" data-value="<?= e($row['values'][$column['name']]) ?>">
							<?= screen_cell_html($column['name'], $row['values'][$column['name']]) ?>
						</td>
					<?php endforeach ?>
					<?php if (isset($extras['close_price'])): ?>
						<td class="sm-num" data-value="<?= e($row['price']) ?>"><?= e(fmt_pence($row['price'])) ?></td>
					<?php endif ?>
					<?php if (isset($extras['6mnth'])): ?>
						<td class="sm-num" data-value="<?= e($row['change_6m']) ?>"><?= move_pct_html($row['change_6m']) ?></td>
					<?php endif ?>
					<?php if (isset($extras['momentum_score']) || isset($extras['value_score'])
					          || isset($extras['quality_score'])): ?>
						<td class="sm-badges-tight" style="white-space: nowrap">
							<?php if (isset($extras['momentum_score'])): ?><?= score_badge_html($row['momentum'], 'Momentum') ?><?php endif ?>
							<?php if (isset($extras['value_score'])): ?><?= score_badge_html($row['value_score'], 'Value') ?><?php endif ?>
							<?php if (isset($extras['quality_score'])): ?><?= score_badge_html($row['quality'], 'Quality') ?><?php endif ?>
						</td>
					<?php endif ?>
				</tr>
			<?php endforeach ?>
			</tbody>
		</table>
	</div>
</div>

<?php endif ?>
