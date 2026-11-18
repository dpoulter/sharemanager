<?php
/**
 * One company, on one screen.
 *
 * The house style of 18 Nov 2026: a header panel that says who it is, what
 * it costs, how it scores and whether it breaks Dale's policy, and then
 * three columns - the chart and what has been said on the left, what the
 * price itself has done in the middle, what it is worth on the right.
 *
 * Every figure is shown against something: a rank in the index, a sector
 * median, a market median, a 200-day average, the ends of a year's range.
 * A number on its own is the thing this page was rebuilt to stop printing.
 *
 * The arithmetic is in includes/quote_view.php; this file is markup. The
 * header reads stored closes rather than the live quote, so the price above
 * the chart is the price the chart ends on.
 */

/* The page's own small helpers. Closures rather than functions: a template
   is included once per request, but a function declared in one is declared
   for the whole process and a second include is a fatal. */

$q_link = function ($tab) use ($symbol) {
	return 'quote.php?symbol=' . urlencode($symbol)
	     . ($tab === 'report' ? '' : '&tab=' . urlencode($tab));
};

/* A signed percentage with its colour, and the level it is measured
   against beside it. */
$q_move = function ($percent) {
	return move_pct_html($percent);
};

$price_ccy = ($head['price'] === null) ? null : fmt_pence($head['price']);
$code      = strtoupper(preg_replace('/\..*$/', '', (string) $symbol));
?>

<?php /* ============================ the header ============================ */ ?>
<section class="sm-panel mb-3" aria-label="<?= e((string) $head['name']) ?>">

	<div class="sm-head">

		<div class="sm-head-who">
			<div class="sm-logo-box" aria-hidden="true"><?php
				if ($head['logo'] !== null): ?><img src="<?= e($head['logo']) ?>" alt=""><?php
				else: echo e(substr($code, 0, 4)); endif ?></div>
			<div class="sm-head-name">
				<h1 class="mb-0"><?= e($head['name']) ?>
					<span class="sm-muted"><?= e($symbol) ?></span></h1>
				<div class="sm-price-line">
					<span class="sm-price"><?= e($price_ccy === null ? VIEW_DASH : $price_ccy) ?></span>
					<?php if ($head['change'] !== null): ?>
						<span class="sm-price-move <?= e(move_class($head['change'])) ?>">
							<?= e(($head['change'] >= 0 ? '+' : '-') . fmt_pence(abs($head['change']))) ?>
							&nbsp;<?= e(fmt_change_pct($head['change_pct'])) ?>
						</span>
					<?php endif ?>
					<span class="sm-muted">close, <?= e(fmt_date($head['as_of'])) ?></span>
				</div>
				<div class="sm-tags">
					<?php if (trim((string) $head['market']) !== ''): ?>
						<?= view_tag_html($head['market']) ?>
					<?php endif ?>
					<?php if (trim((string) $share_info['industry_group']) !== ''): ?>
						<?= view_tag_html($share_info['industry_group']) ?>
					<?php endif ?>
					<?php if (trim((string) $share_info['industry']) !== ''): ?>
						<?= view_tag_html($share_info['industry']) ?>
					<?php endif ?>
					<?php /* Held, and by what. The first thing a reader wants to
					         know about a share in front of them, and the page
					         used to say nothing about it at all. */ ?>
					<?php if ($held['portfolio']): ?><?= view_tag_html('Held') ?><?php endif ?>
					<?php foreach ($held['strategies'] as $holder): ?>
						<?= view_tag_html('Held by ' . $holder['name'],
						                  'strategies.php?s=' . urlencode($holder['strategy'])) ?>
					<?php endforeach ?>
					<?php if (!$held['portfolio'] && !count($held['strategies'])): ?>
						<?= view_tag_html('Not held') ?>
					<?php endif ?>
				</div>
			</div>
		</div>

		<div class="sm-facts">
			<div class="sm-fact"><span class="sm-muted">P/E</span>
				<span><?= e(fmt_ratio($pe)) ?></span></div>
			<div class="sm-fact"><span class="sm-muted">Dividend yield</span>
				<?php /* Stored as a fraction: 0.0083 is 0.83%. */ ?>
				<span><?= $div_yield === null ? e(VIEW_DASH) : e(fmt_yield($div_yield * 100)) ?></span></div>
			<div class="sm-fact"><span class="sm-muted">Market cap</span>
				<span><?= $head['market_cap'] === null
					? view_missing_html('the provider has not supplied the shares in issue')
					: e(fmt_money_compact($head['market_cap'])) ?></span></div>
			<div>
				<div class="sm-fact"><span class="sm-muted">52 weeks</span>
					<span><?= $head['low_52w'] === null ? e(VIEW_DASH)
						: e(fmt_pence($head['low_52w']) . ' to ' . fmt_pence($head['high_52w'])) ?></span></div>
				<?php if ($head['low_52w'] !== null): ?>
					<div class="sm-range" title="<?= e('The close is at ' . fmt_pct($head['range_pct'])
					                                   . ' of the year\'s range') ?>"
					     data-bs-toggle="tooltip">
						<span class="sm-range-mark" style="left: <?= e(number_format($head['range_pct'], 1)) ?>%"></span>
					</div>
				<?php endif ?>
			</div>
		</div>

		<?php /* The scores, as bars rather than coloured blocks: a bar says
		         where 49 sits between 0 and 100, which is what a score is,
		         and the block only ever said "amber". */ ?>
		<div class="sm-scorebox">
			<?= view_bar_row_html('Momentum', $scores['momentum'], ['href' => '#MomentumModal']) ?>
			<?php if (!empty($scores['is_fund'])): ?>
				<?php /* An investment fund has no value, quality or overall
				         score: the ratios they are built from describe a
				         wrapper rather than a business, so the nightly job
				         writes no score row for a fund at all. */ ?>
				<div class="sm-muted">Value and Quality are not scored for an investment fund.</div>
				<div class="sm-scorebox-total sm-muted">ShareRank needs them, so it is not scored either.</div>
			<?php else: ?>
				<?= view_bar_row_html('Value', $scores['value'], ['href' => '#ValueModal']) ?>
				<?= view_bar_row_html('Quality', $scores['quality'], ['href' => '#QualityModal']) ?>
				<?= view_bar_row_html('ShareRank', $scores['overall'], ['total' => true]) ?>
			<?php endif ?>
		</div>
	</div>

	<?php /* The policy, checked in code rather than asked of the AI: this
	         rule decides whether a holding is sold. One strip per rule, so
	         a second rule is a second sentence. */ ?>
	<?php foreach ($policy as $rule): ?>
		<?= view_alert_html($rule['ok'], $rule['sentence'], 'Your policy:') ?>
	<?php endforeach ?>

	<nav class="sm-tabs" aria-label="This share">
		<?php foreach ($tabs as $tab_key => $tab_label): ?>
			<a href="<?= e($q_link($tab_key)) ?>"<?= $tab === $tab_key
				? ' class="sm-tab-on" aria-current="page"' : '' ?>><?= e($tab_label) ?></a>
		<?php endforeach ?>
	</nav>
</section>

<?php if ($tab === 'report'): ?>
<div class="sm-cols">

	<?php /* ======================= the left column ======================= */ ?>
	<div class="sm-col">

		<section class="sm-panel qp-chart">
			<h2 class="sm-panel-title">Price, one year
				<span class="btn-group btn-group-sm" role="group" aria-label="Timespan">
					<button type="button" class="btn btn-outline-secondary" data-chart-span="30">1M</button>
					<button type="button" class="btn btn-outline-secondary" data-chart-span="182">6M</button>
					<button type="button" class="btn btn-outline-secondary active" data-chart-span="365">1Y</button>
					<button type="button" class="btn btn-outline-secondary" data-chart-span="1825">5Y</button>
				</span>
			</h2>
			<div class="sm-panel-body">
				<div id="price-chart" class="sm-chart" data-symbol="<?= e($symbol) ?>"></div>
				<small id="chart-status" class="sm-muted d-block mt-1"></small>
				<div id="price-chart-fallback">
					<noscript><p class="sm-muted">The interactive chart needs JavaScript; this is the static one.</p></noscript>
					<img src="stockgraph.php?symbol=<?= urlencode($symbol) ?>&timespan=<?= urlencode($timespan) ?>"
					     class="img-fluid" alt="Price chart">
				</div>

				<?php /* The three lines and where each of them is now. A chart
				         with no levels under it is a shape; with them it is a
				         statement about the price. */ ?>
				<div class="sm-legend">
					<span><span class="sm-legend-mark sm-legend-close" aria-hidden="true"></span>
						Close <?= e($price_ccy === null ? VIEW_DASH : $price_ccy) ?></span>
					<span><span class="sm-legend-mark sm-legend-ma50" aria-hidden="true"></span>
						50-day <?= e($momentum['ma50'] === null ? VIEW_DASH : fmt_pence($momentum['ma50'])) ?></span>
					<span><span class="sm-legend-mark sm-legend-ma200" aria-hidden="true"></span>
						200-day <?= e($momentum['ma200'] === null ? VIEW_DASH : fmt_pence($momentum['ma200'])) ?></span>
				</div>

				<?php /* Why did it move? Nothing happens until the button is
				         pressed, the answer is cached per range, and
				         Regenerate is the way to pay for it again. */ ?>
				<div class="d-flex flex-wrap align-items-end gap-2 mt-3">
					<div>
						<label class="form-label mb-0" for="explain-from"><small>From</small></label>
						<input type="date" id="explain-from" class="form-control form-control-sm">
					</div>
					<div>
						<label class="form-label mb-0" for="explain-to"><small>To</small></label>
						<input type="date" id="explain-to" class="form-control form-control-sm">
					</div>
					<button type="button" id="explain-go" class="sm-btn"
					        data-symbol="<?= e($symbol) ?>">Explain this move</button>
					<button type="button" id="explain-again" class="sm-btn"
					        style="display:none">Regenerate</button>
					<div class="ms-auto">
						<label class="visually-hidden" for="news-filter">Kind of news</label>
						<select id="news-filter" class="form-select form-select-sm">
							<option value="">All news</option>
							<option value="market">Market</option>
							<option value="earnings">Earnings</option>
							<option value="product">Product</option>
							<option value="policy">Policy</option>
							<option value="competition">Competition</option>
							<option value="management">Management</option>
							<option value="other">Other</option>
						</select>
					</div>
				</div>
				<div id="explain-panel" class="mt-2"></div>
				<div id="news-panel" class="mt-2"></div>

				<?php /* After the container, so the script finds it. Only on
				         this page: a charting library on every page is a
				         download nobody else needs. */ ?>
				<script src="<?= asset_url('/js/lightweight-charts.standalone.production.js') ?>"></script>
				<script src="<?= asset_url('/js/chart.js') ?>"></script>
			</div>
		</section>

		<div class="qp-outlook">
		<?php
			require_once(__DIR__ . '/../includes/ai_outlook.php');
			$outlook_scope    = 'share';
			$outlook_symbol   = $symbol;
			$outlook_title    = 'AI outlook';
			$outlook_collapse = true;
			include(__DIR__ . '/ai_outlook_panel.php');
		?>
		</div>

		<?php /* The company's own account of what has happened to it, which
		         is worth more than the ratios two clicks away. The same rows
		         as "In the news": the two must never disagree about what a
		         day's news was. */ ?>
		<section class="sm-panel qp-news">
			<h2 class="sm-panel-title">Recent announcements
				<a href="in_the_news.php">In the news</a></h2>
			<?php if (empty($announcements)): ?>
				<div class="sm-panel-body sm-muted">
					No regulatory announcements stored for <?= e($symbol) ?>.
					The nightly job collects them; routine filings are left out.
				</div>
			<?php else: ?>
				<table class="sm-data">
					<thead><tr><th>Date</th><th>Sentiment</th><th>What was said</th></tr></thead>
					<tbody>
					<?php foreach (array_slice($announcements, 0, 5) as $announcement): ?>
						<tr>
							<td class="sm-nowrap"><?= e(fmt_date_short($announcement["published"])) ?></td>
							<td class="sm-nowrap"><?= sentiment_html($announcement["sentiment"]) ?></td>
							<td>
								<details class="sm-reveal">
									<summary><?= e($announcement["summary"] ?: $announcement["title"]) ?></summary>
									<div class="sm-reveal-body mt-2">
										<div><strong><?= e($announcement["title"]) ?></strong></div>
										<?php foreach (rns_key_figures($announcement["key_figures"]) as $figure): ?>
											<div><?= e($figure) ?></div>
										<?php endforeach ?>
										<?php if (trim((string) $announcement["bull_point"]) !== ''): ?>
											<div><span class="sm-up">&plus;</span> <?= e($announcement["bull_point"]) ?></div>
										<?php endif ?>
										<?php if (trim((string) $announcement["bear_point"]) !== ''): ?>
											<div><span class="sm-down">&minus;</span> <?= e($announcement["bear_point"]) ?></div>
										<?php endif ?>
										<div class="mt-1">
											<a href="<?= e($announcement["url"]) ?>" target="_blank" rel="noopener noreferrer">The announcement on Investegate</a>
										</div>
									</div>
								</details>
							</td>
						</tr>
					<?php endforeach ?>
					</tbody>
				</table>
				<div class="sm-strip-note">
					<small>Announcement text is from Investegate and is kept for personal reference only.</small>
				</div>
			<?php endif ?>
		</section>
	</div>

	<?php /* ====================== the middle column ====================== */ ?>
	<div class="sm-col">

		<section class="sm-panel qp-momentum">
			<h2 class="sm-panel-title">Momentum</h2>
			<div class="sm-subhead"><span>Price change</span><span>Rank in the index</span></div>
			<table class="sm-data">
				<tbody>
				<?php foreach ($momentum['changes'] as $row): ?>
					<tr>
						<th scope="row"><?= e($row['label']) ?></th>
						<td class="sm-num"><?= $q_move($row['value']) ?></td>
						<td class="sm-note"><?= ($row['rank'] === null || $row['universe'] === null)
							? '' : e($row['rank'] . ' of ' . $row['universe']) ?></td>
						<td class="sm-dot-cell"><?= view_dot_html($row['percentile'],
							$row['percentile'] === null ? null
							: 'In the ' . fmt_score($row['percentile']) . 'th percentile of the index') ?></td>
					</tr>
				<?php endforeach ?>
				</tbody>
			</table>
			<div class="sm-subhead"><span>Close against</span><span>Level</span></div>
			<table class="sm-data">
				<tbody>
				<?php foreach ($momentum['against'] as $row): ?>
					<tr>
						<th scope="row"><?= e($row['label']) ?></th>
						<td class="sm-num"><?= $q_move($row['percent']) ?></td>
						<td class="sm-note"><?= $row['level'] === null
							? e(VIEW_DASH) : e(fmt_pence($row['level'])) ?></td>
						<td class="sm-dot-cell"><?= $row['band'] === null ? '' : view_dot_html($row['band']) ?></td>
					</tr>
				<?php endforeach ?>
				</tbody>
			</table>
		</section>

		<?php /* Two scores whose meaning is where they sit between two ends
		         rather than a rank against other shares. */ ?>
		<?php
			/* get_health_scores() is keyed by the indicator's own name. */
			$piotroski = isset($health_scores['piotroski_score']) ? $health_scores['piotroski_score'] : null;
			$altman    = isset($health_scores['altman_z']) ? $health_scores['altman_z'] : null;
		?>
		<?php if ($piotroski !== null || $altman !== null): ?>
		<section class="sm-panel qp-health">
			<h2 class="sm-panel-title">Financial health</h2>
			<div class="sm-panel-body d-flex flex-column gap-3">
				<?php if ($piotroski !== null): ?>
				<div>
					<div class="sm-fact mb-1"><span>Piotroski F-Score</span>
						<strong><?= $piotroski['value'] === null
							? view_missing_html('the provider did not supply this score')
							: e(fmt_score($piotroski['value']) . ' of 9') ?></strong></div>
					<?= view_scale_html($piotroski['value'], 0, 9, '0 weak', '9 strong') ?>
				</div>
				<?php endif ?>
				<?php if ($altman !== null): ?>
				<div>
					<div class="sm-fact mb-1"><span>Altman Z-Score</span>
						<strong><?= $altman['value'] === null
							? view_missing_html('the provider did not supply this score')
							: e(fmt_ratio($altman['value'], 2)) ?></strong></div>
					<?php /* Clamped to 0 to 5: a Z of 12 is off the end of the
					         scale rather than a reason to draw outside it. */ ?>
					<?= view_scale_html($altman['value'] === null ? null
					                    : max(0, min(5, (float) $altman['value'])),
					                    0, 5, 'Distress below 1.8', 'Safe above 3.0') ?>
				</div>
				<?php endif ?>
			</div>
		</section>
		<?php endif ?>

		<section class="sm-panel qp-growth">
			<h2 class="sm-panel-title">Growth and returns to shareholders</h2>
			<table class="sm-data">
				<tbody>
				<?php foreach ($growth_rows as $row): ?>
					<tr>
						<th scope="row"><?= e($row['label']) ?></th>
						<td class="sm-num"><?= quote_measure_html($row['name'], $row['value']) ?></td>
					</tr>
				<?php endforeach ?>
				</tbody>
			</table>
		</section>
	</div>

	<?php /* ======================= the right column ======================= */ ?>
	<div class="sm-col">

		<?php
			/* The implied price, said as a sentence rather than as a red
			   badge reading 341. The alert colours only when the share is
			   more than a fifth above what its industry's ratios imply:
			   below that it is a difference, not a warning. */
			[$imp_words, $imp_class] = valuation_words($industry_valuation, $head['price']);
			$imp_value = (is_array($industry_valuation) && is_numeric($industry_valuation['value']))
			           ? (float) $industry_valuation['value'] : null;
			$imp_gap   = ($imp_value === null || $imp_value <= 0 || $head['price'] === null)
			           ? null : ((($head['price'] / $imp_value) - 1) * 100);
		?>
		<section class="sm-panel qp-value">
			<h2 class="sm-panel-title">Value</h2>
			<table class="sm-data">
				<thead>
					<tr><th>Trailing</th>
						<th class="sm-num"><?= e($code) ?></th>
						<th class="sm-num" title="Sector median" data-bs-toggle="tooltip">Sector</th>
						<th class="sm-num" title="Market median" data-bs-toggle="tooltip">Market</th>
						<th colspan="2">Cheapness, 0 to 100</th></tr>
				</thead>
				<tbody>
				<?php foreach ($value_rows as $row): ?>
					<tr>
						<th scope="row"><?= e($row['label']) ?></th>
						<td class="sm-num"><?= quote_measure_html($row['name'], $row['value']) ?></td>
						<td class="sm-num sm-against"><?= quote_median_html($row['name'], $row['sector']) ?></td>
						<td class="sm-num sm-against"><?= quote_median_html($row['name'], $row['market']) ?></td>
						<td class="sm-bar-cell"><?= view_bar_html($row['percentile']) ?></td>
						<td class="sm-num sm-against sm-bar-number"><?= $row['percentile'] === null
							? e(VIEW_DASH) : e(fmt_score($row['percentile'])) ?></td>
					</tr>
				<?php endforeach ?>
				</tbody>
			</table>
			<?php if (!empty($currency_note)): ?>
				<?php /* A figure that has been adjusted should say so. */ ?>
				<div class="sm-strip-note"><small>Reports in <?= e($currency_note['currency']) ?>;
					<?php if ($currency_note['rate'] !== null): ?>
						ratios converted at <?= e(fmt_ratio($currency_note['rate'], 4)) ?>.
					<?php else: ?>
						no rate was available, so its price ratios are not shown or ranked today.
					<?php endif ?></small></div>
			<?php endif ?>
			<?php if (!empty($scores['is_fund'])): ?>
				<div class="sm-strip-note">An investment fund is not valued on these ratios:
					they describe a wrapper rather than a business.</div>
			<?php elseif ($imp_words !== null): ?>
				<div class="sm-strip-note<?= ($imp_gap !== null && $imp_gap > 20) ? ' sm-strip-note-alert' : '' ?>">
					Priced like its industry it would be <?= e(fmt_pence($imp_value)) ?>,
					<?= e(fmt_pct(abs($imp_gap))) ?> <?= $imp_gap >= 0 ? 'below' : 'above' ?> the close.
				</div>
			<?php endif ?>
		</section>

		<section class="sm-panel qp-quality">
			<h2 class="sm-panel-title">Quality</h2>
			<table class="sm-data">
				<thead>
					<tr><th>Measure</th>
						<th class="sm-num"><?= e($code) ?></th>
						<th class="sm-num" title="Sector median" data-bs-toggle="tooltip">Sector</th>
						<th class="sm-num" title="Market median" data-bs-toggle="tooltip">Market</th>
						<th colspan="2">Rank, 0 to 100</th></tr>
				</thead>
				<tbody>
				<?php foreach ($quality_rows as $row): ?>
					<tr>
						<th scope="row"><?= e($row['label']) ?></th>
						<td class="sm-num"><?= quote_measure_html($row['name'], $row['value']) ?></td>
						<td class="sm-num sm-against"><?= quote_median_html($row['name'], $row['sector']) ?></td>
						<td class="sm-num sm-against"><?= quote_median_html($row['name'], $row['market']) ?></td>
						<td class="sm-bar-cell"><?= view_bar_html($row['percentile']) ?></td>
						<td class="sm-num sm-against sm-bar-number"><?= $row['percentile'] === null
							? e(VIEW_DASH) : e(fmt_score($row['percentile'])) ?></td>
					</tr>
				<?php endforeach ?>
				</tbody>
			</table>
			<?php if (!empty($scores['is_fund'])): ?>
				<div class="sm-strip-note">An investment fund is not scored on these measures either.</div>
			<?php endif ?>
			<?php foreach ($health_scores as $health): ?>
				<?php if ($health['not_ranked'] !== null): ?>
					<div class="sm-strip-note"><small><?= e($health['label']) ?>:
						<?= e($health['not_ranked']) ?>, so it is shown but not ranked and
						does not count towards the quality score.</small></div>
				<?php endif ?>
			<?php endforeach ?>
		</section>

		<?php /* The five year accounts, where they have been loaded. No
		         panel until there are figures: an empty table of five
		         years reads as a company with no accounts rather than as
		         a loader that has not run. */ ?>
		<?php if (count($accounts['periods'])): ?>
		<section class="sm-panel qp-summary">
			<h2 class="sm-panel-title">Financial summary
				<a href="<?= e($q_link('accounts')) ?>">Five years</a></h2>
			<table class="sm-data">
				<thead>
					<tr><th>Year</th>
						<?php foreach ($accounts['periods'] as $period): ?>
							<th class="sm-num"><?= e($period['label']) ?></th>
						<?php endforeach ?>
						<th class="sm-num">Change</th></tr>
				</thead>
				<tbody>
				<?php foreach ($accounts['rows'] as $row): ?>
					<tr>
						<th scope="row"><?= e($row['label']) ?></th>
						<?php foreach ($row['figures'] as $figure): ?>
							<td class="sm-num"><?= $figure === null ? e(VIEW_DASH)
								: e(quote_account_figure($row['name'], $figure)) ?></td>
						<?php endforeach ?>
						<td class="sm-num"><?= $row['growth'] === null
							? e(VIEW_DASH) : move_pct_html($row['growth']) ?></td>
					</tr>
				<?php endforeach ?>
				</tbody>
			</table>
			<div class="sm-strip-note"><small>As reported, in the currency the
				company keeps its accounts in. Change is the last year against the
				one before it.</small></div>
		</section>
		<?php endif ?>
	</div>
</div>

<?php elseif ($tab === 'announcements'): ?>

	<section class="sm-panel">
		<h2 class="sm-panel-title">Every stored announcement
			<a href="in_the_news.php">In the news</a></h2>
		<?php if (empty($announcements)): ?>
			<div class="sm-panel-body sm-muted">
				No regulatory announcements stored for <?= e($symbol) ?>.
			</div>
		<?php else: ?>
			<table class="sm-data">
				<thead><tr><th>Date</th><th>Sentiment</th><th>Type</th><th>What was said</th></tr></thead>
				<tbody>
				<?php foreach ($announcements as $announcement): ?>
					<tr>
						<td class="sm-nowrap"><?= e(fmt_date_short($announcement["published"])) ?></td>
						<td class="sm-nowrap"><?= sentiment_html($announcement["sentiment"]) ?></td>
						<td class="sm-muted"><?= e($announcement["ann_type"]) ?></td>
						<td>
							<details class="sm-reveal">
								<summary><?= e($announcement["summary"] ?: $announcement["title"]) ?></summary>
								<div class="sm-reveal-body mt-2">
									<div><strong><?= e($announcement["title"]) ?></strong></div>
									<?php foreach (rns_key_figures($announcement["key_figures"]) as $figure): ?>
										<div><?= e($figure) ?></div>
									<?php endforeach ?>
									<?php if (trim((string) $announcement["vs_expectations"]) !== ''
									          && $announcement["vs_expectations"] !== 'not stated'): ?>
										<div>Against expectations: <?= e($announcement["vs_expectations"]) ?></div>
									<?php endif ?>
									<div class="mt-1">
										<a href="<?= e($announcement["url"]) ?>" target="_blank" rel="noopener noreferrer">The announcement on Investegate</a>
									</div>
								</div>
							</details>
						</td>
					</tr>
				<?php endforeach ?>
				</tbody>
			</table>
			<div class="sm-strip-note">
				<small>Announcement text is from Investegate and is kept for personal reference only.</small>
			</div>
		<?php endif ?>
	</section>

<?php elseif ($tab === 'outlook'): ?>

	<?php
		require_once(__DIR__ . '/../includes/ai_outlook.php');
		$outlook_scope    = 'share';
		$outlook_symbol   = $symbol;
		$outlook_title    = 'AI outlook';
		$outlook_collapse = false;
		include(__DIR__ . '/ai_outlook_panel.php');
	?>

<?php elseif ($tab === 'strategies'): ?>

	<?php
		/* Which strategies hold it now, and which have held it. A share's
		   own page should say whether the engine has an opinion about it
		   without a reader going to look. */
		$qs_now  = $held['strategies'];
		$qs_past = [];
		foreach (query("select strategy, min(fill_date) first_fill, max(fill_date) last_fill,
		                       count(*) fills
		                  from strategy_orders
		                 where symbol=? and status='FILLED' group by strategy
		                 order by strategy", $symbol) as $qs_row) {
			if (function_exists('strategy_reserved')
			    && strategy_reserved((string) $qs_row['strategy'])) { continue; }
			$qs_past[] = $qs_row;
		}
	?>
	<section class="sm-panel">
		<h2 class="sm-panel-title">Strategies <a href="strategies.php">All strategies</a></h2>
		<?php if (!count($qs_past) && !count($qs_now)): ?>
			<div class="sm-panel-body sm-muted">
				No strategy has held <?= e($symbol) ?>.
			</div>
		<?php else: ?>
			<table class="sm-data">
				<thead><tr><th>Strategy</th><th>Holding now</th>
					<th class="sm-num">Fills</th><th>First</th><th>Last</th></tr></thead>
				<tbody>
				<?php foreach ($qs_past as $qs_row): ?>
					<?php
						$qs_name = strategy_display_name((string) $qs_row['strategy']);
						$qs_held = false;
						foreach ($qs_now as $holder) {
							if ($holder['strategy'] === (string) $qs_row['strategy']) { $qs_held = true; }
						}
					?>
					<tr>
						<th scope="row"><a href="strategies.php?s=<?= urlencode((string) $qs_row['strategy']) ?>"><?=
							e($qs_name) ?></a></th>
						<td><?= $qs_held ? 'Yes' : 'No' ?></td>
						<td class="sm-num"><?= e(fmt_quantity($qs_row['fills'])) ?></td>
						<td><?= e(fmt_date($qs_row['first_fill'])) ?></td>
						<td><?= e(fmt_date($qs_row['last_fill'])) ?></td>
					</tr>
				<?php endforeach ?>
				</tbody>
			</table>
		<?php endif ?>
	</section>

<?php elseif ($tab === 'accounts'): ?>

	<?php /* The same five years, with room for every line the loader
	         writes rather than the handful the report has space for. */ ?>
	<section class="sm-panel">
		<h2 class="sm-panel-title">Five years of accounts</h2>
		<?php if (!count($accounts['periods'])): ?>
			<div class="sm-panel-body sm-muted">
				No accounts have been loaded for <?= e($symbol) ?>.
			</div>
		<?php else: ?>
			<table class="sm-data">
				<thead>
					<tr><th>Line</th>
						<?php foreach ($accounts['periods'] as $period): ?>
							<th class="sm-num"><?= e($period['label']) ?>
								<span class="sm-muted"><?= e(fmt_date($period['end_date'])) ?></span></th>
						<?php endforeach ?>
						<th class="sm-num">Change</th></tr>
				</thead>
				<tbody>
				<?php foreach ($accounts['rows'] as $row): ?>
					<tr>
						<th scope="row"><?= e($row['label']) ?></th>
						<?php foreach ($row['figures'] as $figure): ?>
							<td class="sm-num"><?= $figure === null ? e(VIEW_DASH)
								: e(quote_account_figure($row['name'], $figure)) ?></td>
						<?php endforeach ?>
						<td class="sm-num"><?= $row['growth'] === null
							? e(VIEW_DASH) : move_pct_html($row['growth']) ?></td>
					</tr>
				<?php endforeach ?>
				</tbody>
			</table>
			<div class="sm-strip-note"><small>As reported, in the currency the company
				keeps its accounts in; per-share figures as reported. Change is the last
				year against the one before it.</small></div>
		<?php endif ?>
	</section>

<?php elseif ($tab === 'company'): ?>

	<?php $profile = get_profile($symbol); ?>
	<?php $absent = function ($value) {
		/* get_profile() returns the string "N/A" where the provider sent
		   nothing. Printed as it stands, three rows of a six row table
		   read N/A, which looks like a value. */
		return $value === null || trim((string) $value) === ''
		    || strtoupper(trim((string) $value)) === 'N/A'; } ?>

	<div class="sm-cols">
		<div class="sm-col">
			<section class="sm-panel">
				<h2 class="sm-panel-title">Business summary</h2>
				<div class="sm-panel-body"><?= e($profile['description']) ?></div>
			</section>
		</div>
		<div class="sm-col">
			<section class="sm-panel">
				<h2 class="sm-panel-title">The company</h2>
				<table class="sm-data">
					<tbody>
						<tr><th scope="row">Sector</th>
							<td><a href="sector_companies.php?sector=<?= urlencode((string) $head['sector']) ?>"><?=
								e($head['sector']) ?></a></td></tr>
						<tr><th scope="row">Industry group</th><td><?= e($share_info["industry_group"]) ?></td></tr>
						<tr><th scope="row">Industry</th><td><?= e($share_info["industry"]) ?></td></tr>
						<tr><th scope="row">Index</th><td><?= trim((string) $head['market']) === ''
							? view_missing_html("the index this share is in has not been loaded")
							: e($head['market']) ?></td></tr>
						<tr><th scope="row">Shares in issue</th>
							<td class="sm-num"><?= e(fmt_quantity($head['shares'])) ?></td></tr>
						<tr><th scope="row">Website</th>
							<td><?php if (!$absent($profile['website'])): ?>
								<a target="_blank" rel="noopener noreferrer" href="<?= e($profile['website']) ?>"><?=
									e($profile['website']) ?></a>
							<?php else: ?><?= view_missing_html("the provider did not supply a website") ?><?php endif ?></td></tr>
						<tr><th scope="row">Employees</th><td class="sm-num"><?= $absent($profile['employees'])
							? view_missing_html("the provider did not supply a headcount")
							: e(is_numeric($profile['employees']) ? fmt_quantity($profile['employees']) : $profile['employees']) ?></td></tr>
						<tr><th scope="row">Directors</th><td><?= $absent($profile['directors'])
							? view_missing_html("the provider did not supply a board list")
							: e($profile['directors']) ?></td></tr>
					</tbody>
				</table>
			</section>
		</div>
		<div class="sm-col">
			<?php /* What the peers imply, both ways round. The Value panel
			         on the report says the industry's answer in a sentence;
			         this is the sector's beside it, for a reader comparing
			         the two. */ ?>
			<section class="sm-panel">
				<h2 class="sm-panel-title">Against its peers</h2>
				<table class="sm-data">
					<tbody>
					<?php foreach ([['the sector', $valuation], ['the industry', $industry_valuation]]
					               as [$v_label, $v_data]): ?>
						<?php [$v_words, $v_class] = valuation_words($v_data, $head['price']); ?>
						<tr>
							<th scope="row">Priced like <?= e($v_label) ?></th>
							<td class="<?= e($v_class) ?>"><?= $v_words === null
								? view_missing_html("the peer median for this share could not be worked out")
								: e($v_words) ?></td>
						</tr>
					<?php endforeach ?>
					</tbody>
				</table>
				<?php /* Not pedantry: a median that included this share would
				         move towards it, and the figure is here to say what
				         the others are doing. */ ?>
				<div class="sm-strip-note"><small>Each peer median leaves this share out
					of it, so it says what the others are priced at.</small></div>
			</section>
		</div>
	</div>

	<?php /* Every measure the nightly job stores for this share, including
	         the categories the report does not have a panel for. */ ?>
	<div class="sm-cols mt-3">
	<?php foreach ($quote as $category_indicator): ?>
		<?php if ($category_indicator["category"]["name"] === 'other'
		          || !count($category_indicator["indicators"])) { continue; } ?>
		<div class="sm-col">
			<section class="sm-panel">
				<h2 class="sm-panel-title"><?= e($category_indicator["category"]["description"]) ?>
					<?php if (!empty($currency_note) && $currency_note['rate'] !== null): ?>
					<span class="sm-muted">Reports in <?= e($currency_note['currency']) ?>,
						converted at <?= e(fmt_ratio($currency_note['rate'], 4)) ?></span>
					<?php endif ?>
				</h2>
				<table class="sm-data">
					<thead>
						<tr><th>Measure</th><th class="sm-num">Share</th>
							<th class="sm-num">Sector</th><th class="sm-num">Market</th>
							<th colspan="2">Rank, 0 to 100</th></tr>
					</thead>
					<tbody>
					<?php foreach ($category_indicator["indicators"] as $indicator): ?>
						<tr>
							<th scope="row"><?= e($indicator["description"]) ?></th>
							<td class="sm-num"><?= quote_measure_html($indicator["name"], $indicator["value"]) ?></td>
							<td class="sm-num sm-against"><?= quote_median_html($indicator["name"], $indicator["sector_average"]) ?></td>
							<td class="sm-num sm-against"><?= quote_median_html($indicator["name"], $indicator["market_average"]) ?></td>
							<td class="sm-bar-cell"><?= view_bar_html($indicator["percentile"]) ?></td>
							<td class="sm-num sm-against sm-bar-number"><?= $indicator["percentile"] === null
								? e(VIEW_DASH) : e(fmt_score($indicator["percentile"])) ?></td>
						</tr>
					<?php endforeach ?>
					</tbody>
				</table>
			</section>
		</div>
	<?php endforeach ?>
	</div>

<?php endif ?>

<?php /* ---------------- what each score is built from ----------------

   Three modals with one shape between them, generated from a list rather
   than copied three times. They are the components of each score - the
   ratios it was built from, the share's figure, the market median and its
   rank - which is what a reader who clicks a score name is asking for.  */ ?>
<?php
	$rank_modals = [
		['id' => 'MomentumModal', 'title' => 'Momentum', 'rows' => $momentumranks],
		['id' => 'QualityModal',  'title' => 'Quality',  'rows' => $qualityranks],
		['id' => 'ValueModal',    'title' => 'Value',    'rows' => $valueranks],
	];
?>
<?php foreach ($rank_modals as $modal): ?>
<div id="<?= e($modal['id']) ?>" class="modal fade" tabindex="-1" role="dialog"
     aria-labelledby="<?= e($modal['id']) ?>Label" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<h2 class="modal-title h5" id="<?= e($modal['id']) ?>Label">What <?= e($modal['title']) ?> is built from</h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<table class="sm-data">
					<thead>
						<tr><th>Ratio</th><th class="sm-num">Share</th>
						    <th class="sm-num">Market median</th>
						    <th colspan="2">Rank, 0 to 100</th></tr>
					</thead>
					<tbody>
					<?php if (!count($modal['rows'])): ?>
						<tr><td colspan="5" class="sm-muted">Nothing calculated for this share yet.</td></tr>
					<?php endif ?>
					<?php foreach ($modal['rows'] as $row): ?>
						<tr>
							<th scope="row"><?= e($row["description"]) ?></th>
							<td class="sm-num"><?= e(fmt_ratio($row["share_value"], 2)) ?></td>
							<td class="sm-num sm-against"><?= e(fmt_ratio($row["market_value"], 2)) ?></td>
							<td class="sm-bar-cell"><?= view_bar_html($row["percentile"]) ?></td>
							<td class="sm-num sm-against sm-bar-number"><?= $row["percentile"] === null
								? e(VIEW_DASH) : e(fmt_score($row["percentile"])) ?></td>
						</tr>
					<?php endforeach ?>
					</tbody>
				</table>
			</div>
			<div class="modal-footer">
				<button type="button" class="sm-btn" data-bs-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>
<?php endforeach ?>
