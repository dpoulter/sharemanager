<?php
	/**
	 * Every scored announcement in the window, one row each, expandable.
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
?>
<div class="container-fluid">

	<h3><?= htmlspecialchars($title) ?></h3>

	<p class="text-muted">
		Regulatory announcements from <?= htmlspecialchars($from) ?>
		to <?= htmlspecialchars($as_of) ?>,
		read and scored for a shareholder.
		Routine filings - buyback paperwork, voting rights, holdings notices -
		are left out.
		<br>
		<small>Announcement text is from Investegate and is kept for personal reference only. Not financial advice.</small>
	</p>

	<!-- ------------------------------------------------------------------ -->
	<!-- The week in one line each way.                                      -->
	<!-- ------------------------------------------------------------------ -->
	<div class="row mb-3">
		<?php foreach ([["Worst news", $movers['worst'], '#b91c1c'],
		                ["Best news",  $movers['best'],  '#15803d']] as [$heading, $list, $colour]): ?>
		<div class="col-md-6">
			<div class="card mb-2">
				<div class="card-body py-2">
					<h6 class="card-title mb-2" style="color: <?= $colour ?>">
						<?= $heading ?> this period
					</h6>
					<?php if (!count($list)): ?>
						<span class="text-muted">Nothing scored either way yet.</span>
					<?php else: ?>
					<table class="table table-sm mb-0">
						<?php foreach ($list as $mover): ?>
						<tr>
							<td><a href="quote.php?symbol=<?= urlencode($mover['symbol']) ?>"><?= htmlspecialchars($mover['symbol']) ?></a></td>
							<td><?= htmlspecialchars($mover['company']) ?></td>
							<td class="text-end"><?= (int) $mover['announcements'] ?></td>
							<td class="text-end"><?= htmlspecialchars((string) $mover['total']) ?></td>
						</tr>
						<?php endforeach ?>
					</table>
					<?php endif ?>
				</div>
			</div>
		</div>
		<?php endforeach ?>
	</div>

	<!-- ------------------------------------------------------------------ -->
	<!-- Filters. A GET form, so a view can be bookmarked and shared.        -->
	<!-- ------------------------------------------------------------------ -->
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
					<option value="<?= htmlspecialchars($t['ann_type']) ?>"
						<?= $type === $t['ann_type'] ? 'selected' : '' ?>>
						<?= htmlspecialchars($t['ann_type']) ?></option>
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
					<option value="<?= htmlspecialchars($m['market']) ?>"
						<?= $market === $m['market'] ? 'selected' : '' ?>>
						<?= htmlspecialchars($m['market']) ?></option>
				<?php endforeach ?>
			</select>
		</div>

		<input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">

		<div class="col-auto">
			<button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>
			<a class="btn btn-sm btn-outline-secondary" href="in_the_news.php">Clear</a>
		</div>
	</form>

	<!-- ------------------------------------------------------------------ -->
	<!-- The announcements.                                                  -->
	<!-- ------------------------------------------------------------------ -->
	<?php
		$base = ["days" => $days, "type" => $type, "tone" => $tone, "market" => $market];
		$heads = [["date", "Date"], ["symbol", "Share"], [null, "Type"],
		          ["sentiment", "Sentiment"], [null, "Vs expectations"],
		          [null, "What they said"], ["move", "Move since"]];
	?>
	<p><strong><?= count($rows) ?></strong> announcement<?= count($rows) === 1 ? '' : 's' ?>.</p>

	<table class="table table-sm table-hover" id="news-table">
		<thead>
			<tr>
			<?php foreach ($heads as [$key, $label]): ?>
				<th>
					<?php if ($key === null): ?>
						<?= $label ?>
					<?php else: ?>
						<a href="<?= htmlspecialchars(news_link($base + ["sort" => $key])) ?>"
						   <?= $sort === $key ? 'class="fw-bold"' : '' ?>><?= $label ?></a>
					<?php endif ?>
				</th>
			<?php endforeach ?>
			</tr>
		</thead>
		<tbody>
		<?php if (!count($rows)): ?>
			<tr><td colspan="7" class="text-muted">
				No scored announcements in this period. The nightly job stores them;
				if this is the first run, give it a night.
			</td></tr>
		<?php endif ?>

		<?php foreach ($rows as $row): ?>
			<?php [$colour, $word] = rns_band($row['sentiment']); ?>
			<tr>
				<td style="white-space: nowrap"><?= htmlspecialchars($row['published']) ?></td>
				<td style="white-space: nowrap">
					<a href="quote.php?symbol=<?= urlencode($row['symbol']) ?>"><?= htmlspecialchars($row['symbol']) ?></a>
					<br><small class="text-muted"><?= htmlspecialchars($row['company']) ?></small>
				</td>
				<td><?= htmlspecialchars((string) $row['ann_type']) ?></td>
				<td style="white-space: nowrap">
					<span title="<?= htmlspecialchars((string) $row['sentiment']) ?>"
					      style="display:inline-block;width:46px;height:12px;border-radius:3px;background:<?= $colour ?>"></span>
					<small><?= $word ?></small>
				</td>
				<td><?= htmlspecialchars((string) $row['vs_expectations']) ?></td>
				<td>
					<details>
						<summary><?= htmlspecialchars((string) $row['summary']) ?></summary>
						<div class="mt-2">
							<div><strong><?= htmlspecialchars((string) $row['title']) ?></strong>
								<?php if ($row['body_truncated'] === 'Y'): ?>
									<span class="badge bg-secondary">read in part</span>
								<?php endif ?>
							</div>
							<?php if (count($row['figures'])): ?>
								<ul class="mb-1 mt-1">
								<?php foreach ($row['figures'] as $figure): ?>
									<li><?= htmlspecialchars($figure) ?></li>
								<?php endforeach ?>
								</ul>
							<?php endif ?>
							<?php if (trim((string) $row['bull_point']) !== ''): ?>
								<div><span style="color:#15803d">&plus;</span>
									<?= htmlspecialchars((string) $row['bull_point']) ?></div>
							<?php endif ?>
							<?php if (trim((string) $row['bear_point']) !== ''): ?>
								<div><span style="color:#b91c1c">&minus;</span>
									<?= htmlspecialchars((string) $row['bear_point']) ?></div>
							<?php endif ?>
							<div class="mt-1">
								<a href="<?= htmlspecialchars((string) $row['url']) ?>"
								   target="_blank" rel="noopener noreferrer">
									The announcement on Investegate</a>
								&middot;
								<a href="quote.php?symbol=<?= urlencode($row['symbol']) ?>">Quote page</a>
								<?php if (trim((string) $row['source']) !== ''): ?>
									&middot; <small class="text-muted"><?= htmlspecialchars((string) $row['source']) ?></small>
								<?php endif ?>
							</div>
						</div>
					</details>
				</td>
				<td class="text-end" style="white-space: nowrap">
					<?php if ($row['price_move'] === null): ?>
						<span class="text-muted" title="No close before the announcement to measure from">&ndash;</span>
					<?php else: ?>
						<span style="color: <?= $row['price_move'] >= 0 ? '#15803d' : '#b91c1c' ?>">
							<?= number_format((float) $row['price_move'], 1) ?>%
						</span>
					<?php endif ?>
				</td>
			</tr>
		<?php endforeach ?>
		</tbody>
	</table>
</div>
