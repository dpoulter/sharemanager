<?php
	/**
	 * Every scored announcement in the window, one row per company per day,
	 * expandable.
	 *
	 * Everything on this page that came from an announcement goes out through
	 * htmlspecialchars(): the title, the summary, the figures and the two
	 * points are a company's words relayed by a model, which makes them
	 * somebody else's text twice over.
	 *
	 * The expand is a <details> element rather than JavaScript, so the page
	 * works with scripting off and there is nothing to go wrong between the
	 * row and what it opens.
	 */

	function news_link($params) {
		return 'in_the_news.php?' . http_build_query($params);
	}

	/* The detail behind one announcement: the title, the figures, the two
	   points and the links. Drawn the same way whether it is a day with one
	   announcement or one of several inside a group. */
	function news_detail($row) { ?>
		<div class="mt-2">
			<div><strong><?= e($row['title']) ?></strong>
				<?php if ($row['body_truncated'] === 'Y'): ?>
					<span class="sm-badge sm-score-none">read in part</span>
				<?php endif ?>
			</div>
			<?php $figures = rns_key_figures($row['key_figures']); ?>
			<?php if (count($figures)): ?>
				<ul class="mb-1 mt-1">
				<?php foreach ($figures as $figure): ?>
					<li><?= e($figure) ?></li>
				<?php endforeach ?>
				</ul>
			<?php endif ?>
			<?php if (trim((string) $row['bull_point']) !== ''): ?>
				<div><span class="sm-up">&plus;</span> <?= e($row['bull_point']) ?></div>
			<?php endif ?>
			<?php if (trim((string) $row['bear_point']) !== ''): ?>
				<div><span class="sm-down">&minus;</span> <?= e($row['bear_point']) ?></div>
			<?php endif ?>
			<div class="mt-1">
				<a href="<?= e($row['url']) ?>" target="_blank" rel="noopener noreferrer">The announcement on Investegate</a>
				&middot;
				<a href="quote.php?symbol=<?= urlencode($row['symbol']) ?>">Quote page</a>
				<?php if (trim((string) $row['source']) !== ''): ?>
					&middot; <small class="text-muted"><?= e($row['source']) ?></small>
				<?php endif ?>
			</div>
		</div>
	<?php }
?>

<h1><?= e($title) ?></h1>
<p class="sm-sub">
	Regulatory announcements from <?= e(fmt_date($from)) ?>
	to <?= e(fmt_date($as_of)) ?>, read and scored for a shareholder.
	Routine filings - buyback paperwork, voting rights, holdings notices -
	are left out before they are ever stored.
	<br>
	<small>Announcement text is from Investegate and is kept for personal reference only. Not financial advice.</small>
</p>

<?php /* ---------------- the week, one line each way ---------------- */ ?>
<div class="row">
	<?php foreach ([["Worst received", $movers['worst'], 'sm-down'],
	                ["Best received",  $movers['best'],  'sm-up']] as [$heading, $list, $colour]): ?>
	<div class="col-12 col-lg-6">
		<div class="card">
			<div class="card-header <?= e($colour) ?>"><?= e($heading) ?> this period</div>
			<?php if (!count($list)): ?>
				<div class="card-body"><span class="text-muted">Nothing scored either way yet.</span></div>
			<?php else: ?>
			<div class="table-responsive">
				<table class="table table-sm mb-0">
					<thead>
						<tr><th>Share</th><th>Company</th>
						    <th class="sm-num">Items</th>
						    <th>Sentiment</th>
						    <th class="sm-num">Since</th></tr>
					</thead>
					<tbody>
					<?php foreach ($list as $mover): ?>
					<tr>
						<td><a href="quote.php?symbol=<?= urlencode($mover['symbol']) ?>"><?= e($mover['symbol']) ?></a></td>
						<td><?= e($mover['company']) ?></td>
						<td class="sm-num"><?= e(fmt_quantity($mover['announcements'])) ?></td>
						<td style="white-space: nowrap"><?= sentiment_html($mover['average']) ?></td>
						<?php /* What the share has done since it announced. The
						         score says how the news read; this says what the
						         market did about it, and they are not the same
						         thing. */ ?>
						<td class="sm-num"><?= move_pct_html($mover['price_move'] ?? null) ?></td>
					</tr>
					<?php endforeach ?>
					</tbody>
				</table>
			</div>
			<?php endif ?>
		</div>
	</div>
	<?php endforeach ?>
</div>

<?php /* ---------------- filters ---------------- */ ?>
<form method="get" action="in_the_news.php" class="row g-2 align-items-end mb-3">

	<div class="col-auto">
		<label class="form-label mb-0" for="days">Period</label>
		<select class="form-select form-select-sm" id="days" name="days">
			<option value="7"  <?= $days === 7  ? 'selected' : '' ?>>Last 7 trading days</option>
			<option value="30" <?= $days === 30 ? 'selected' : '' ?>>Last 30 trading days</option>
		</select>
	</div>

	<div class="col-auto">
		<label class="form-label mb-0" for="type">Type</label>
		<select class="form-select form-select-sm" id="type" name="type">
			<option value="">All types</option>
			<?php foreach ($types as $t): ?>
				<option value="<?= e($t['ann_type']) ?>"
					<?= $type === $t['ann_type'] ? 'selected' : '' ?>><?= e($t['ann_type']) ?></option>
			<?php endforeach ?>
		</select>
	</div>

	<div class="col-auto">
		<label class="form-label mb-0" for="tone">Tone</label>
		<select class="form-select form-select-sm" id="tone" name="tone">
			<option value="">Good and bad</option>
			<option value="positive" <?= $tone === 'positive' ? 'selected' : '' ?>>Positive only</option>
			<option value="negative" <?= $tone === 'negative' ? 'selected' : '' ?>>Negative only</option>
		</select>
	</div>

	<div class="col-auto">
		<label class="form-label mb-0" for="market">Index</label>
		<select class="form-select form-select-sm" id="market" name="market">
			<option value="">All</option>
			<?php foreach ($markets as $m): ?>
				<option value="<?= e($m['market']) ?>"
					<?= $market === $m['market'] ? 'selected' : '' ?>><?= e($m['market']) ?></option>
			<?php endforeach ?>
		</select>
	</div>

	<input type="hidden" name="sort" value="<?= e($sort) ?>">

	<div class="col-auto">
		<button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>
		<a class="btn btn-sm btn-outline-secondary" href="in_the_news.php">Clear</a>
	</div>
</form>

<?php
	$base  = ["days" => $days, "type" => $type, "tone" => $tone, "market" => $market];
	$heads = [["date", "Date"], ["symbol", "Share"], [null, "Type"],
	          ["sentiment", "Sentiment"], [null, "Vs expectations"],
	          [null, "What they said"], ["move", "Move since"]];

	/* One table of company-days. Written once and used twice - the main one
	   and the routine one behind the fold show the same thing, and a second
	   copy would be a second thing to keep in step. The sort links are only
	   on the main table: sorting the fold would reload the page and close
	   it. */
	function news_table($groups, $heads, $base, $sort, $sortable = true) { ?>
	<div class="table-responsive">
		<table class="table table-sm table-hover mb-0">
			<thead>
				<tr>
				<?php foreach ($heads as [$key, $label]): ?>
					<th<?= $label === 'Move since' ? ' class="sm-num"' : '' ?>>
						<?php if ($key === null || !$sortable): ?>
							<?= e($label) ?>
						<?php else: ?>
							<a href="<?= e(news_link($base + ["sort" => $key])) ?>"
							   <?= $sort === $key ? 'class="fw-bold"' : '' ?>><?= e($label) ?></a>
						<?php endif ?>
					</th>
				<?php endforeach ?>
				</tr>
			</thead>
			<tbody>
			<?php if (!count($groups)): ?>
				<tr><td colspan="7" class="text-muted">
					No scored announcements in this period. The nightly job stores them;
					if this is the first run, give it a night.
				</td></tr>
			<?php endif ?>

			<?php foreach ($groups as $group): ?>
				<?php $lead = $group['strongest']; ?>
				<tr>
					<td style="white-space: nowrap"><?= e(fmt_date_short($group['published'])) ?></td>
					<td style="white-space: nowrap">
						<a href="quote.php?symbol=<?= urlencode($group['symbol']) ?>"><?= e($group['symbol']) ?></a>
						<br><small class="text-muted"><?= e($group['company']) ?></small>
					</td>
					<td>
						<?= e($lead['ann_type']) ?>
						<?php if ($group['count'] > 1): ?>
							<span class="sm-badge sm-score-none" data-bs-toggle="tooltip"
							      title="<?= e($group['count'] . ' announcements on this day; the strongest is shown') ?>">
								<?= e(fmt_quantity($group['count'])) ?> items
							</span>
						<?php endif ?>
					</td>
					<td style="white-space: nowrap"><?= sentiment_html($lead['sentiment']) ?></td>
					<td><?= e($lead['vs_expectations']) ?></td>
					<td>
						<details class="sm-reveal">
							<summary><?= e($lead['summary']) ?></summary>
							<div class="sm-reveal-body">
							<?php if ($group['count'] === 1): ?>
								<?php news_detail($lead) ?>
							<?php else: ?>
								<?php /* Each announcement of the day in turn, strongest
								         first, so opening the row answers "what else did
								         they say". */ ?>
								<?php
									$items = $group['items'];
									usort($items, function ($a, $b) {
										return abs((float) $b['sentiment']) <=> abs((float) $a['sentiment']);
									});
								?>
								<?php foreach ($items as $item): ?>
									<div class="border-top pt-2 mt-2">
										<div><?= sentiment_html($item['sentiment']) ?></div>
										<div><?= e($item['summary']) ?></div>
										<?php news_detail($item) ?>
									</div>
								<?php endforeach ?>
							<?php endif ?>
							</div>
						</details>
					</td>
					<td class="sm-num"><?= move_pct_html($lead['price_move']) ?></td>
				</tr>
			<?php endforeach ?>
			</tbody>
		</table>
	</div>
	<?php }
?>

<p class="mb-2">
	<strong><?= e(fmt_quantity(count($groups))) ?></strong>
	<?= count($groups) === 1 ? 'company-day' : 'company-days' ?>,
	<strong><?= e(fmt_quantity(count($rows))) ?></strong>
	announcement<?= count($rows) === 1 ? '' : 's' ?>.
</p>

<div class="card">
	<?php news_table($groups, $heads, $base, $sort) ?>
</div>

<?php /* The routine filings - type "other" and neutral in tone - folded away
         rather than left out. A <details> the browser opens: no round trip,
         and the count on the summary says what is behind it, so nobody has
         to wonder whether the page is complete. */ ?>
<?php if ($routine_count > 0): ?>
<details class="sm-reveal sm-routine">
	<summary>Show routine items (<?= e(fmt_quantity($routine_count)) ?>)</summary>
	<div class="sm-reveal-body">
		<p class="text-muted small mb-2">
			Filings typed "other" that scored neutral: nobody is going to act on
			these, but they are here rather than dropped.
		</p>
		<div class="card">
			<?php news_table($routine_groups, $heads, $base, $sort, false) ?>
		</div>
	</div>
</details>
<?php endif ?>
