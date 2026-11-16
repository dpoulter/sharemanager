<?php
/**
 * The dashboard.
 *
 * It used to be the market outlook in full, then four lists of ticker and
 * company name. That answered one question - what scored well last night -
 * and left the reader to go and find out what their holdings were worth,
 * what the strategies did and what was announced. Top to bottom now:
 * portfolio, market, news, strategies, ranked lists.
 *
 * Two columns from 1,200px, one below it, in the same order either way.
 * Every figure goes through the formatters in includes/view.php.
 */
?>

<h1>Dashboard</h1>
<p class="sm-sub">
	At the <?= e(fmt_date($as_of)) ?> close.
	<?= score_key_html() ?>
</p>

<?php /* ---------------- 1. the portfolio ---------------- */ ?>
<div class="card">
	<div class="card-header d-flex justify-content-between align-items-center">
		<span>Portfolio</span>
		<a class="small" href="performance.php">Open portfolio</a>
	</div>
	<div class="card-body">
	<?php if ($portfolio['holdings'] === 0): ?>
		<p class="mb-2 text-muted">No holdings.</p>
		<a class="btn btn-sm btn-outline-primary" href="buy.php">Add a purchase</a>
	<?php else: ?>
		<div class="sm-strip">
			<div class="sm-strip-item">
				<div class="sm-strip-label">Total value</div>
				<div class="sm-strip-value"><?= e(fmt_pounds($portfolio['total'])) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Today</div>
				<div class="sm-strip-value">
					<?php if ($portfolio['day_change'] === null): ?>
						<?= view_missing_html("two closes are needed to work out a day's change and only one is stored") ?>
					<?php else: ?>
						<?= move_pounds_html($portfolio['day_change']) ?>
						<span class="sm-strip-label">(<?= move_pct_html($portfolio['day_change_pct']) ?>)</span>
					<?php endif ?>
				</div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Total gain</div>
				<div class="sm-strip-value"><?= move_pounds_html($portfolio['gain']) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Cash</div>
				<div class="sm-strip-value"><?= e(fmt_pounds($portfolio['cash'])) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Holdings</div>
				<div class="sm-strip-value"><?= e(fmt_quantity($portfolio['holdings'])) ?></div>
			</div>
		</div>
	<?php endif ?>
	</div>
</div>

<div class="row">

	<?php /* ---------------- 2. the market ---------------- */ ?>
	<div class="col-12 col-xl-6">
		<div class="card">
			<div class="card-header">Market</div>
			<div class="card-body">

				<div class="sm-strip mb-3">
					<div class="sm-strip-item">
						<div class="sm-strip-label">Above the 200 day</div>
						<div class="sm-strip-value"><?= e(fmt_pct($breadth['pct_above_ma200'])) ?></div>
					</div>
					<div class="sm-strip-item">
						<div class="sm-strip-label">50 day above 200 day</div>
						<div class="sm-strip-value"><?= e(fmt_pct($breadth['pct_ma50_above_ma200'])) ?></div>
					</div>
					<div class="sm-strip-item">
						<div class="sm-strip-label">Median 12 month</div>
						<div class="sm-strip-value"><?= move_pct_html($breadth['median_12m']) ?></div>
					</div>
				</div>

				<?php /* Six months of the first figure, drawn as an SVG rather than
				         with a chart library: one line, no axes, no interaction. A
				         library would be 50kB to draw eleven line segments. Left out
				         entirely when the statistics do not go back far enough,
				         because an empty chart box says less than no chart box. */ ?>
				<?php if (count($breadth_history) >= 2): ?>
					<?php
						$bh_values = array_map(function ($r) { return (float) $r['pct']; }, $breadth_history);
						$bh_low  = min($bh_values);
						$bh_high = max($bh_values);
						$bh_span = ($bh_high - $bh_low) > 0 ? ($bh_high - $bh_low) : 1;
						$bh_n    = count($bh_values);
						$bh_pts  = [];
						foreach ($bh_values as $bh_i => $bh_v) {
							$bh_pts[] = round($bh_i / max(1, $bh_n - 1) * 300, 1) . ','
							          . round(40 - (($bh_v - $bh_low) / $bh_span) * 36, 1);
						}
					?>
					<div class="text-muted small mb-1">
						Above the 200 day, <?= e(fmt_date($breadth_history[0]['date']) ) ?>
						to <?= e(fmt_date($breadth_history[$bh_n - 1]['date'])) ?>
						(<?= e(fmt_pct($bh_low)) ?> to <?= e(fmt_pct($bh_high)) ?>)
					</div>
					<svg viewBox="0 0 300 44" width="100%" height="44" role="img"
					     aria-label="Six months of the share of the market trading above its 200 day average"
					     preserveAspectRatio="none">
						<polyline fill="none" stroke="#1d4ed8" stroke-width="1.5"
						          points="<?= e(implode(' ', $bh_pts)) ?>"></polyline>
					</svg>
				<?php endif ?>

			</div>
		</div>

		<?php
			$outlook_scope    = 'market';
			$outlook_symbol   = null;
			$outlook_title    = 'Market outlook';
			$outlook_collapse = true;
			include(__DIR__ . '/ai_outlook_panel.php');
		?>
	</div>

	<?php /* ---------------- 3. news this week ---------------- */ ?>
	<div class="col-12 col-xl-6">
		<div class="card">
			<div class="card-header d-flex justify-content-between align-items-center">
				<span>News this week</span>
				<a class="small" href="in_the_news.php">In the news</a>
			</div>
			<div class="card-body">
			<?php if (!count($news['best']) && !count($news['worst'])): ?>
				<p class="text-muted mb-0">
					Nothing scored since <?= e(fmt_date($news_from)) ?>.
				</p>
			<?php else: ?>
				<?php foreach ([['best', 'Best received'], ['worst', 'Worst received']] as [$side, $heading]): ?>
					<?php if (count($news[$side])): ?>
						<h2 class="h6 mt-1"><?= e($heading) ?></h2>
						<div class="table-responsive mb-3">
							<table class="table table-sm mb-0">
								<thead>
									<tr>
										<th>Share</th>
										<th>Sentiment</th>
										<th class="sm-num">Since</th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ($news[$side] as $mover): ?>
									<tr>
										<td>
											<a href="quote.php?symbol=<?= urlencode($mover['symbol']) ?>"><?= e($mover['symbol']) ?></a>
											<br><small class="text-muted"><?= e($mover['company']) ?></small>
										</td>
										<td><?= sentiment_html($mover['average']) ?></td>
										<?php /* What the share has done since it announced. The
										         figure is stored by the nightly job; a row without
										         one is a row the job has not reached yet. */ ?>
										<td class="sm-num"><?= move_pct_html($mover['price_move']) ?></td>
									</tr>
								<?php endforeach ?>
								</tbody>
							</table>
						</div>
					<?php endif ?>
				<?php endforeach ?>
			<?php endif ?>
			</div>
		</div>
	</div>
</div>

<?php /* ---------------- 4. strategies ---------------- */ ?>
<?php if (count($strategies)): ?>
<div class="card">
	<div class="card-header d-flex justify-content-between align-items-center">
		<span>Strategies</span>
		<a class="small" href="strategies.php">All strategies</a>
	</div>
	<div class="table-responsive">
		<table class="table table-sm mb-0">
			<thead>
				<tr>
					<th>Strategy</th>
					<th class="sm-num">Value</th>
					<th class="sm-num">Return</th>
					<th class="sm-num">Benchmark</th>
					<th class="sm-num">Holdings</th>
					<th>Next rebalance</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($strategies as $card): ?>
				<tr>
					<td>
						<a href="strategies.php"><?= e(strategy_display_name($card['strategy'])) ?></a>
						<?= status_badge_html('paper') ?>
					</td>
					<td class="sm-num"><?= e(fmt_pounds($card['total'])) ?></td>
					<td class="sm-num"><?= move_pct_html($card['return']) ?></td>
					<td class="sm-num"><?= move_pct_html($card['benchmark_return']) ?></td>
					<td class="sm-num"><?= e(fmt_quantity($card['holdings'])) ?></td>
					<td><?= e(fmt_date($card['next_rebalance'])) ?></td>
				</tr>
			<?php endforeach ?>
			</tbody>
		</table>
	</div>
</div>
<?php endif ?>

<?php /* ---------------- 5. the ranked lists ---------------- */ ?>
<div class="row">
<?php foreach ($ranked as $key => $list): ?>
	<div class="col-12 col-xl-6">
		<div class="card">
			<div class="card-header"><?= e($scores[$key]['title']) ?> top ten</div>
			<div class="table-responsive">
				<table class="table table-sm mb-0">
					<thead>
						<tr>
							<th>Share</th>
							<th>Company</th>
							<th class="sm-num"><?= e($scores[$key]['title']) ?></th>
							<th class="sm-num">Price</th>
							<th class="sm-num">6 months</th>
						</tr>
					</thead>
					<tbody>
					<?php if (!count($list)): ?>
						<tr><td colspan="5" class="text-muted">
							Nothing scored yet. The nightly job writes these.
						</td></tr>
					<?php endif ?>
					<?php foreach ($list as $item): ?>
						<tr>
							<td><a href="quote.php?symbol=<?= urlencode($item['symbol']) ?>"><?= e($item['symbol']) ?></a></td>
							<td><?= e($item['name']) ?></td>
							<td class="sm-num"><?= score_badge_html($item['score'], $scores[$key]['title']) ?></td>
							<td class="sm-num"><?= e(fmt_pence($item['price'])) ?></td>
							<td class="sm-num"><?= move_pct_html($item['change_6m']) ?></td>
						</tr>
					<?php endforeach ?>
					</tbody>
				</table>
			</div>
			<div class="card-body py-2">
				<a class="small" href="rankings.php?score=<?= urlencode($key) ?>">
					The whole list, ranked by <?= e(strtolower($scores[$key]['title'])) ?>
				</a>
			</div>
		</div>
	</div>
<?php endforeach ?>
</div>
