<?php
/**
 * Every share with one score, ranked, sortable.
 *
 * The sort is done in the browser rather than by reloading with an order in
 * the query string: 350 rows is a page somebody flicks between columns on,
 * and a round trip per click would make that unpleasant for no gain.
 */
?>

<h1><?= e($scores[$score]['title']) ?> ranking</h1>
<p class="sm-sub">
	<?= e($scores[$score]['blurb']) ?>
	Ranked at the <?= e(fmt_date($as_of)) ?> close.
	<?= score_key_html() ?>
</p>

<ul class="nav nav-pills mb-3">
<?php foreach ($scores as $key => $meta): ?>
	<li class="nav-item">
		<a class="nav-link<?= $key === $score ? ' active' : '' ?>"
		   href="rankings.php?score=<?= urlencode($key) ?>"><?= e($meta['title']) ?></a>
	</li>
<?php endforeach ?>
</ul>

<div class="card">
	<div class="card-header">
		<?= e(fmt_quantity(count($rows))) ?> shares
	</div>
	<div class="table-responsive">
		<table class="table table-sm table-hover sm-table-sort mb-0" id="ranking-table">
			<thead>
				<tr>
					<th class="sm-num" data-sort="number">#</th>
					<th data-sort="text">Share</th>
					<th data-sort="text">Company</th>
					<th class="sm-num" data-sort="number"><?= e($scores[$score]['title']) ?></th>
					<th class="sm-num" data-sort="number">Price</th>
					<th class="sm-num" data-sort="number">6 months</th>
				</tr>
			</thead>
			<tbody>
			<?php if (!count($rows)): ?>
				<tr><td colspan="6" class="text-muted">
					Nothing scored yet. The nightly job writes these.
				</td></tr>
			<?php endif ?>
			<?php foreach ($rows as $i => $row): ?>
				<tr>
					<td class="sm-num" data-value="<?= (int) ($i + 1) ?>"><?= (int) ($i + 1) ?></td>
					<td><a href="quote.php?symbol=<?= urlencode($row['symbol']) ?>"><?= e($row['symbol']) ?></a></td>
					<td><?= e($row['name']) ?></td>
					<td class="sm-num" data-value="<?= e($row['score']) ?>">
						<?= score_badge_html($row['score'], $scores[$score]['title']) ?>
					</td>
					<td class="sm-num" data-value="<?= e($row['price']) ?>"><?= e(fmt_pence($row['price'])) ?></td>
					<td class="sm-num" data-value="<?= e($row['change_6m']) ?>"><?= move_pct_html($row['change_6m']) ?></td>
				</tr>
			<?php endforeach ?>
			</tbody>
		</table>
	</div>
</div>
