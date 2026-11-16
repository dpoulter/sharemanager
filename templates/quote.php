<?php
/**
 * One share.
 *
 * Top to bottom: who it is and what it costs, the figures a reader checks
 * first, the chart, the scores, the outlook, what the company has announced,
 * and then the detail in tabs.
 *
 * The header reads stored closes rather than the live quote, so the price
 * above the chart is the price the chart ends on. Three of its figures -
 * market capitalisation, shares in issue and the 52 week range - were blank
 * on every share because they come from a provider endpoint this plan does
 * not include; includes/quote_view.php derives them from the price history.
 */

$price_ccy = ($head['price'] === null) ? null : fmt_pence($head['price']);
?>

<div class="d-flex flex-wrap align-items-center gap-3 mb-1">
	<?php if ($head['logo'] !== null): ?>
		<?php /* Only when the provider supplied one. The page used to draw an
		         <img> whatever the column held, so a share with no logo got a
		         grey box with the company name written across it. */ ?>
		<img src="<?= e($head['logo']) ?>" alt="" height="36" class="sm-logo">
	<?php endif ?>
	<h1 class="mb-0"><?= e($head['name']) ?> <span class="text-muted">(<?= e($symbol) ?>)</span></h1>
</div>

<p class="sm-sub">
	<span class="h4"><?= e($price_ccy === null ? VIEW_DASH : $price_ccy) ?></span>
	<?php if ($head['change'] !== null): ?>
		<span class="<?= move_class($head['change']) ?>">
			<?= e(($head['change'] >= 0 ? '+' : '-') . fmt_pence(abs($head['change']))) ?>
		</span>
		<?= move_pct_html($head['change_pct']) ?>
	<?php endif ?>
	at the <?= e(fmt_date($head['as_of'])) ?> close.
	<?= score_key_html() ?>
</p>

<?php /* ---------------- the figures a reader checks first ---------------- */ ?>
<div class="card">
	<div class="card-body">
		<div class="sm-strip">
			<div class="sm-strip-item">
				<div class="sm-strip-label">Market cap</div>
				<div class="sm-strip-value"><?= e(fmt_money_compact($head['market_cap'])) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">P/E</div>
				<div class="sm-strip-value"><?= e(fmt_ratio($pe)) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Dividend yield</div>
				<?php /* div_yield is stored as a fraction - 0.0412 is 4.12% -
				         which is why this one is multiplied and the ratios
				         beside it are not. */ ?>
				<div class="sm-strip-value"><?= e($div_yield === null ? VIEW_DASH : fmt_yield($div_yield * 100)) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">P/B</div>
				<div class="sm-strip-value"><?= e(fmt_ratio($price_book)) ?></div>
			</div>
			<div class="sm-strip-item" style="min-width: 12rem">
				<div class="sm-strip-label">52 week range</div>
				<?php if ($head['low_52w'] === null): ?>
					<div class="sm-strip-value"><?= view_missing_html("no price history for the last year") ?></div>
				<?php else: ?>
					<div class="sm-strip-value" style="font-size:1rem">
						<?= e(fmt_pence($head['low_52w'])) ?> &ndash; <?= e(fmt_pence($head['high_52w'])) ?>
					</div>
					<div class="sm-range" title="<?= e('Today at ' . fmt_pct($head['range_pct']) . ' of the range') ?>"
					     data-bs-toggle="tooltip">
						<span class="sm-range-mark" style="left: <?= e(number_format($head['range_pct'], 1)) ?>%"></span>
					</div>
				<?php endif ?>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Index</div>
				<div class="sm-strip-value" style="font-size:1rem">
					<?= $head['market'] === null || trim((string) $head['market']) === ''
					    ? view_missing_html("the index this share is in has not been loaded")
					    : e($head['market']) ?>
				</div>
			</div>
		</div>
	</div>
</div>

<div class="row">

	<div class="col-12 col-lg-8">

		<?php /* ---------------- the chart ---------------- */ ?>
		<div class="card">
			<div class="card-body">
				<?php /* templates/chart.js draws it; the old stockgraph.php image
				         stays underneath as the fallback and is hidden by the
				         script once the library has loaded, so a browser with
				         JavaScript off - or a server where fetch_assets.sh has
				         not run - still gets a chart.

				         The Timespan / Type / Go form that used to sit under it
				         is gone. It was a second set of controls for the same
				         thing: it reloaded the whole page to redraw a chart the
				         buttons above redraw without one, and its Type never did
				         anything, because the chart draws a line whatever is
				         chosen. */ ?>
				<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
					<div class="btn-group btn-group-sm" role="group" aria-label="Timespan">
						<button type="button" class="btn btn-outline-secondary" data-chart-span="30">1M</button>
						<button type="button" class="btn btn-outline-secondary" data-chart-span="91">3M</button>
						<button type="button" class="btn btn-outline-secondary" data-chart-span="182">6M</button>
						<button type="button" class="btn btn-outline-secondary active" data-chart-span="365">1Y</button>
						<button type="button" class="btn btn-outline-secondary" data-chart-span="1825">5Y</button>
					</div>
					<div>
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
				<div id="price-chart" class="sm-chart" data-symbol="<?= e($symbol) ?>"></div>
				<small id="chart-status" class="text-body-secondary d-block mt-1"></small>
				<div id="price-chart-fallback">
					<noscript><p class="text-body-secondary">The interactive chart needs JavaScript; this is the static one.</p></noscript>
					<img src="stockgraph.php?symbol=<?= urlencode($symbol) ?>&timespan=<?= urlencode($timespan) ?>"
					     class="img-fluid" alt="Price chart">
				</div>

				<?php /* Why did it move? Nothing happens until the button is
				         pressed, the answer is cached per range, and Regenerate
				         is the way to pay for it again. */ ?>
				<div class="d-flex flex-wrap align-items-end gap-2 mt-2">
					<div>
						<label class="form-label mb-0" for="explain-from"><small>From</small></label>
						<input type="date" id="explain-from" class="form-control form-control-sm">
					</div>
					<div>
						<label class="form-label mb-0" for="explain-to"><small>To</small></label>
						<input type="date" id="explain-to" class="form-control form-control-sm">
					</div>
					<button type="button" id="explain-go" class="btn btn-sm btn-outline-primary"
					        data-symbol="<?= e($symbol) ?>">Explain this move</button>
					<button type="button" id="explain-again" class="btn btn-sm btn-outline-secondary"
					        style="display:none">Regenerate</button>
				</div>
				<div id="explain-panel" class="mt-2"></div>
				<div id="news-panel" class="mt-2"></div>

				<?php /* After the container, so the script finds it. Only on this
				         page: a charting library on every page is a download
				         nobody else needs. */ ?>
				<script src="/js/lightweight-charts.standalone.production.js"></script>
				<script src="/js/chart.js"></script>
			</div>
		</div>

		<?php /* ---------------- the AI outlook ---------------- */ ?>
		<?php
			require_once(__DIR__ . '/../includes/ai_outlook.php');
			$outlook_scope    = 'share';
			$outlook_symbol   = $symbol;
			$outlook_title    = 'AI outlook';
			$outlook_collapse = true;
			include(__DIR__ . '/ai_outlook_panel.php');
		?>

		<?php /* ---------------- what the company has announced ---------------- */ ?>
		<?php /* Above the tabs rather than inside one. An announcement is the
		         company's own account of what has happened to it, which is
		         worth more than the ratios two clicks away - and the reader had
		         no way of knowing the News tab held anything. Same rows as the
		         "In the news" page: the two must never disagree about what a
		         day's news was. */ ?>
		<div class="card">
			<div class="card-header d-flex justify-content-between align-items-center">
				<span>Recent announcements</span>
				<a class="small" href="in_the_news.php">In the news</a>
			</div>
			<?php if (empty($announcements)): ?>
				<div class="card-body">
					<p class="text-muted mb-0">
						No regulatory announcements stored for <?= e($symbol) ?>.
						The nightly job collects them; routine filings are left out.
					</p>
				</div>
			<?php else: ?>
				<div class="table-responsive">
					<table class="table table-sm mb-0">
						<thead>
							<tr>
								<th>Date</th>
								<th>Sentiment</th>
								<th>Type</th>
								<th>What was said</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach (array_slice($announcements, 0, 5) as $announcement): ?>
							<tr>
								<td style="white-space: nowrap"><?= e(fmt_date_short($announcement["published"])) ?></td>
								<td style="white-space: nowrap"><?= sentiment_html($announcement["sentiment"]) ?></td>
								<td><small class="text-muted"><?= e($announcement["ann_type"]) ?></small></td>
								<td>
									<details>
										<summary><?= e($announcement["summary"] ?: $announcement["title"]) ?></summary>
										<div class="mt-2">
											<div><strong><?= e($announcement["title"]) ?></strong></div>
											<?php foreach (rns_key_figures($announcement["key_figures"]) as $figure): ?>
												<div><?= e($figure) ?></div>
											<?php endforeach ?>
											<?php if (trim((string) $announcement["vs_expectations"]) !== ''
											          && $announcement["vs_expectations"] !== 'not stated'): ?>
												<div>Against expectations: <?= e($announcement["vs_expectations"]) ?></div>
											<?php endif ?>
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
				</div>
				<div class="card-body py-2">
					<small class="text-muted">
						Announcement text is from Investegate and is kept for personal reference only.
					</small>
				</div>
			<?php endif ?>
		</div>

	</div>

	<div class="col-12 col-lg-4">

		<?php /* ---------------- the scores ---------------- */ ?>
		<div class="card">
			<div class="card-header">Scores</div>
			<div class="card-body">
				<?php /* One scale, the one on every other page. These were four
				         different colour schemes - danger, warning, info, success
				         on four different boundaries - so a 60 here and a 60 on
				         the dashboard were different colours. */ ?>
				<table class="table table-sm table-borderless mb-0">
					<tr>
						<td><a data-bs-toggle="modal" href="#MomentumModal">Momentum</a></td>
						<td class="sm-num"><?= score_badge_html($scores['momentum'], 'Momentum') ?></td>
					</tr>
					<?php if (!empty($scores['is_fund'])): ?>
						<?php /* An investment fund has no value, quality or overall
						         score: the ratios they are built from describe a
						         wrapper rather than a business, so the nightly job
						         writes no score row for a fund at all. Said twice
						         because they are two absences - the two ratio
						         scores, and the ShareRank built from them - and a
						         reader who scrolls to the bottom line should not
						         have to scroll back to find out why it is missing. */ ?>
						<tr><td colspan="2" class="text-muted">Not scored: investment fund</td></tr>
						<tr class="sm-total-row">
							<td colspan="2" class="text-muted">Not scored: investment fund</td>
						</tr>
					<?php else: ?>
						<tr>
							<td><a data-bs-toggle="modal" href="#ValueModal">Value</a></td>
							<td class="sm-num"><?= score_badge_html($scores['value'], 'Value') ?></td>
						</tr>
						<tr>
							<td><a data-bs-toggle="modal" href="#QualityModal">Quality</a></td>
							<td class="sm-num"><?= score_badge_html($scores['quality'], 'Quality') ?></td>
						</tr>
						<tr class="sm-total-row">
							<td>ShareRank</td>
							<td class="sm-num"><?= score_badge_html($scores['overall'], 'ShareRank') ?></td>
						</tr>
					<?php endif ?>
				</table>
			</div>
		</div>

		<?php /* ---------------- financial health ---------------- */ ?>
		<div class="card">
			<div class="card-header">
				Financial health
				<small class="text-body-secondary d-block">From the provider's own figures</small>
			</div>
			<div class="card-body">
				<table class="table table-borderless table-sm mb-0">
				<?php foreach ($health_scores as $health): ?>
					<tr>
						<td><?= e($health['label']) ?></td>
						<td class="sm-num">
							<?= $health['value'] === null
							    ? view_missing_html("the provider did not supply this score")
							    : e(fmt_ratio($health['value'], 2)) ?>
						</td>
						<td class="sm-num">
							<?php if ($health['not_ranked'] !== null): ?>
								<span class="sm-badge sm-score-none" data-bs-toggle="tooltip"
								      title="<?= e($health['not_ranked']) ?>">not ranked</span>
							<?php elseif ($health['percentile'] === null): ?>
								<span class="sm-muted">&nbsp;</span>
							<?php else: ?>
								<?= score_badge_html($health['percentile'], $health['label'] . ' percentile') ?>
							<?php endif ?>
						</td>
					</tr>
				<?php endforeach ?>
				</table>
				<?php foreach ($health_scores as $health): ?>
					<?php if ($health['not_ranked'] !== null): ?>
					<small class="text-body-secondary d-block"><?= e($health['label']) ?>:
						<?= e($health['not_ranked']) ?>, so it is shown but not ranked and
						does not count towards the quality score.</small>
					<?php endif ?>
				<?php endforeach ?>
			</div>
		</div>

		<?php /* ---------------- what the peers imply ---------------- */ ?>
		<div class="card">
			<div class="card-header">
				Price valuation
				<small class="text-body-secondary d-block">What the share would cost if it were valued like its peers</small>
				<?php if (!empty($currency_note)): ?>
				<small class="text-body-secondary d-block">
					Reports in <?= e($currency_note['currency']) ?>;
					<?php if ($currency_note['rate'] !== null): ?>
						ratios converted at <?= e(fmt_ratio($currency_note['rate'], 4)) ?>
					<?php else: ?>
						no rate was available, so its price ratios are not shown or ranked today
					<?php endif ?>
				</small>
				<?php endif ?>
			</div>
			<div class="card-body">
				<?php /* A red badge reading 341. 341 is the price the share would
				         be if it were valued like its peers, in pence, and the
				         badge was red because that is a quarter of what it costs
				         - which is to say it was red for being a bargain.
				         Neither the number nor the colour said any of that, and
				         "Relative to Sector" showed no figure at all. */ ?>
				<?php foreach ([['Sector', $valuation, 'RelativeSectorModal'],
				                ['Industry', $industry_valuation, 'RelativeIndustryModal']]
				               as [$v_label, $v_data, $v_modal]): ?>
					<?php [$v_words, $v_class] = valuation_words($v_data, $head['price']); ?>
					<div class="mb-2">
						<div><a data-bs-toggle="modal" href="#<?= e($v_modal) ?>">Against the <?= e(strtolower($v_label)) ?></a></div>
						<div class="<?= e($v_class) ?>">
							<?= $v_words === null
							    ? view_missing_html("the peer median for this share could not be worked out")
							    : e($v_words) ?>
						</div>
					</div>
				<?php endforeach ?>
			</div>
		</div>

	</div>
</div>

<?php /* ---------------- the detail ---------------- */ ?>
<ul class="nav nav-tabs" id="quoteTabs" role="tablist">
	<li class="nav-item"><a class="nav-link active" id="profile-tab" data-bs-toggle="tab" role="tab"
	                        aria-controls="profile" aria-selected="true" href="#profile">Profile</a></li>
	<li class="nav-item"><a class="nav-link" data-bs-toggle="tab" role="tab" href="#news">News</a></li>
	<li class="nav-item"><a class="nav-link" data-bs-toggle="tab" role="tab" href="#statistics">Ratios</a></li>
</ul>

<div class="tab-content pt-3">

	<div class="tab-pane active" id="profile" role="tabpanel" aria-labelledby="profile-tab">
		<?php $profile = get_profile($symbol); ?>
		<div class="row">
			<div class="col-12 col-lg-6">
				<div class="card">
					<div class="card-header">Business summary</div>
					<div class="card-body"><?= e($profile['description']) ?></div>
				</div>
			</div>
			<div class="col-12 col-lg-6">
				<div class="card">
					<div class="card-header">The company</div>
					<div class="card-body">
						<table class="table table-borderless table-sm mb-0">
							<tr><td>Sector</td>
								<td><a href="sector_companies.php?sector=<?= urlencode((string) $head['sector']) ?>"><?= e($head['sector']) ?></a></td></tr>
							<tr><td>Industry group</td><td><?= e($share_info["industry_group"]) ?></td></tr>
							<tr><td>Industry</td><td><?= e($share_info["industry"]) ?></td></tr>
							<tr><td>Index</td><td><?= trim((string) $head['market']) === ''
								? view_missing_html("the index this share is in has not been loaded")
								: e($head['market']) ?></td></tr>
							<tr><td>Shares in issue</td><td class="sm-num"><?= e(fmt_quantity($head['shares'])) ?></td></tr>
							<?php /* get_profile() returns the string "N/A" where the
							         provider sent nothing. Printed as it stands, three
							         rows of a six row table read N/A, which looks like
							         a value. */ ?>
							<?php $absent = function ($value) {
								return $value === null || trim((string) $value) === ''
								    || strtoupper(trim((string) $value)) === 'N/A'; } ?>
							<tr><td>Website</td>
								<td><?php if (!$absent($profile['website'])): ?>
									<a target="_blank" rel="noopener noreferrer" href="<?= e($profile['website']) ?>"><?= e($profile['website']) ?></a>
								<?php else: ?><?= view_missing_html("the provider did not supply a website") ?><?php endif ?></td></tr>
							<tr><td>Employees</td><td class="sm-num"><?= $absent($profile['employees'])
								? view_missing_html("the provider did not supply a headcount")
								: e(is_numeric($profile['employees']) ? fmt_quantity($profile['employees']) : $profile['employees']) ?></td></tr>
							<tr><td>Directors</td><td><?= $absent($profile['directors'])
								? view_missing_html("the provider did not supply a board list")
								: e($profile['directors']) ?></td></tr>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="tab-pane" id="news" role="tabpanel">
		<div class="card">
			<div class="card-header">Every stored announcement</div>
			<?php if (empty($announcements)): ?>
				<div class="card-body"><p class="text-muted mb-0">
					No regulatory announcements stored for <?= e($symbol) ?>.
				</p></div>
			<?php else: ?>
			<div class="table-responsive">
				<table class="table table-sm mb-0">
					<thead>
						<tr><th>Date</th><th>Sentiment</th><th>Type</th><th>What was said</th></tr>
					</thead>
					<tbody>
					<?php foreach ($announcements as $announcement): ?>
						<tr>
							<td style="white-space: nowrap"><?= e(fmt_date_short($announcement["published"])) ?></td>
							<td style="white-space: nowrap"><?= sentiment_html($announcement["sentiment"]) ?></td>
							<td><small class="text-muted"><?= e($announcement["ann_type"]) ?></small></td>
							<td>
								<details>
									<summary><?= e($announcement["summary"] ?: $announcement["title"]) ?></summary>
									<div class="mt-2">
										<div><strong><?= e($announcement["title"]) ?></strong></div>
										<?php foreach (rns_key_figures($announcement["key_figures"]) as $figure): ?>
											<div><?= e($figure) ?></div>
										<?php endforeach ?>
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
			</div>
			<div class="card-body py-2">
				<small class="text-muted">
					Announcement text is from Investegate and is kept for personal reference only.
				</small>
			</div>
			<?php endif ?>
		</div>
	</div>

	<div class="tab-pane" id="statistics" role="tabpanel">
		<div class="row">
		<?php foreach ($quote as $category_indicator): ?>
			<?php if ($category_indicator["category"]["name"] === 'other') { continue; } ?>
			<div class="col-12 col-xl-6">
				<div class="card">
					<div class="card-header"><?= e($category_indicator["category"]["description"]) ?>
						<?php if (!empty($currency_note) && $currency_note['rate'] !== null): ?>
						<small class="text-body-secondary d-block">Reports in <?= e($currency_note['currency']) ?>;
							ratios converted at <?= e(fmt_ratio($currency_note['rate'], 4)) ?></small>
						<?php endif ?>
					</div>
					<div class="table-responsive">
						<table class="table table-sm table-striped sm-table-sort mb-0">
							<thead>
								<tr>
									<th data-sort="text">Ratio</th>
									<th class="sm-num" data-sort="number">Share</th>
									<th class="sm-num" data-sort="number">Sector median</th>
									<th class="sm-num" data-sort="number">Market median</th>
									<th class="sm-num" data-sort="number">Rank</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($category_indicator["indicators"] as $indicator): ?>
								<tr>
									<td><?= e($indicator["description"]) ?></td>
									<td class="sm-num" data-value="<?= e($indicator["value"]) ?>"><?= e(fmt_ratio($indicator["value"], 2)) ?></td>
									<td class="sm-num" data-value="<?= e($indicator["sector_average"]) ?>"><?= e(fmt_ratio($indicator["sector_average"], 2)) ?></td>
									<td class="sm-num" data-value="<?= e($indicator["market_average"]) ?>"><?= e(fmt_ratio($indicator["market_average"], 2)) ?></td>
									<td class="sm-num" data-value="<?= e($indicator["percentile"]) ?>"><?= score_badge_html($indicator["percentile"], $indicator["description"] . ' percentile') ?></td>
								</tr>
							<?php endforeach ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		<?php endforeach ?>
		</div>
	</div>
</div>

<?php /* ---------------- modals ----------------

   Five modals with one shape between them, generated from a list rather
   than copied five times. One of the copies had an unterminated </d where
   a </div></td> belonged, which the browser closed wherever it felt like.

   The Piotroski, Altman and Altman2 modals that used to be here listed the
   components of each score out of health_indicators, which nothing writes,
   behind links calling onclick handlers that do not exist. The scores
   themselves are on the Financial Health card.                           */ ?>
<?php
	$rank_modals = [
		['id' => 'MomentumModal', 'title' => 'Momentum',
		 'rows' => $momentumranks, 'kind' => 'rank'],
		['id' => 'QualityModal', 'title' => 'Quality',
		 'rows' => $qualityranks, 'kind' => 'rank'],
		['id' => 'ValueModal', 'title' => 'Value',
		 'rows' => $valueranks, 'kind' => 'rank'],
		['id' => 'RelativeSectorModal', 'title' => 'Against the sector',
		 'rows' => $relative_sector_valuations, 'kind' => 'sector',
		 'implied' => $valuation['value']],
		['id' => 'RelativeIndustryModal', 'title' => 'Against the industry',
		 'rows' => $relative_industry_valuations, 'kind' => 'industry',
		 'implied' => $industry_valuation['value']],
	];
?>
<?php foreach ($rank_modals as $modal): ?>
<div id="<?= e($modal['id']) ?>" class="modal fade" tabindex="-1" role="dialog"
     aria-labelledby="<?= e($modal['id']) ?>Label" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<h2 class="modal-title h5" id="<?= e($modal['id']) ?>Label"><?= e($modal['title']) ?></h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div class="table-responsive">
				<table class="table table-sm mb-0">
					<thead>
					<?php if ($modal['kind'] === 'rank'): ?>
						<tr><th>Ratio</th><th class="sm-num">Share</th>
						    <th class="sm-num">Market median</th><th class="sm-num">Rank</th></tr>
					<?php else: ?>
						<?php /* "excl. this share" is not pedantry: a median that
						         included the share would move towards it, and the
						         figure is there to say what the others are doing. */ ?>
						<tr><th>Ratio</th><th class="sm-num">Share</th>
						    <th class="sm-num"><?= e($modal['kind'] === 'sector'
						        ? 'Sector peer median (excl. this share)'
						        : 'Industry peer median (excl. this share)') ?></th>
						    <th class="sm-num">Implied price at <?= e($modal['kind']) ?> median</th></tr>
					<?php endif ?>
					</thead>
					<tbody>
					<?php if (!count($modal['rows'])): ?>
						<tr><td colspan="4" class="text-muted">Nothing calculated for this share yet.</td></tr>
					<?php endif ?>
					<?php foreach ($modal['rows'] as $row): ?>
						<?php if ($modal['kind'] === 'rank'): ?>
							<tr>
								<td><?= e($row["description"]) ?></td>
								<td class="sm-num"><?= e(fmt_ratio($row["share_value"], 2)) ?></td>
								<td class="sm-num"><?= e(fmt_ratio($row["market_value"], 2)) ?></td>
								<td class="sm-num"><?= score_badge_html($row["percentile"], $row["description"] . ' percentile') ?></td>
							</tr>
						<?php else: ?>
							<tr>
								<td><?= e($row["indicator"]) ?></td>
								<td class="sm-num"><?= e(fmt_ratio($row["share_stat"], 2)) ?></td>
								<td class="sm-num"><?= e(fmt_ratio($modal['kind'] === 'sector' ? $row["sector_stat"] : $row["industry_stat"], 2)) ?></td>
								<td class="sm-num"><?= e(fmt_pence($row["value"])) ?></td>
							</tr>
						<?php endif ?>
					<?php endforeach ?>
					<?php if ($modal['kind'] !== 'rank'): ?>
						<tr class="sm-total-row">
							<td>Average</td><td class="sm-num"></td><td class="sm-num"></td>
							<td class="sm-num"><?= e(fmt_pence($modal['implied'])) ?></td>
						</tr>
					<?php endif ?>
					</tbody>
				</table>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>
<?php endforeach ?>
