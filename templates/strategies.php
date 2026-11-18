<?php
	/**
	 * The strategies.
	 *
	 * A menu on the left, one view on the right: the overview with the
	 * summary table and the chart, one strategy with its tabs, all the
	 * backtests in one table, or the explanation. Every strategy stacked
	 * down the page meant scrolling past two of them to reach the third.
	 *
	 * Everything that came out of the database goes out through
	 * htmlspecialchars(), including the strategy's own note and the reason
	 * on a trade: they are configuration somebody can edit, which makes
	 * them input.
	 *
	 * PAPER AND PRACTICE ONLY, and the page says so where somebody can see
	 * it. The one button here runs a dry run, which writes nothing.
	 */

	/* Money, percentages and dates, through the shared formatters from
	   includes/view.php so this page writes a figure the way every other
	   page does. Null is "not known yet" rather than zero: a strategy that
	   has not run has no return, and printing 0.00% would be a claim.

	   The returns on this page are stored as fractions - 0.124 is 12.4% -
	   which is why they go through fmt_percent_of() and the figures on the
	   dashboard, which are already percentages, do not. */
	function s_money($value, $decimals = 0) { return e(fmt_pounds($value, $decimals)); }
	/* Anything that can come out negative - a gain, a difference - goes
	   through this one, so a loss reads "-GBP4" and not "GBP-4" with the
	   minus sign buried in the middle of the figure. */
	function s_money_signed($value, $decimals = 0) { return e(fmt_pounds_signed($value, $decimals)); }
	/* Quantities to what the account can actually deal in: a fractional
	   account shows two places on every row, a whole-share account none. */
	function s_shares($value, $card) {
		$dp = ($card['mode'] === null) ? 0
		    : (int) strategy_mode_rules($card['mode'])['decimals'];
		return e(fmt_shares($value, $dp, false));
	}
	function s_percent($value, $decimals = 1) { return e(fmt_percent_of($value, $decimals)); }
	function s_colour($value) {
		return $value === null ? '' : ' ' . move_class($value);
	}
	function s_date($value) { return e(fmt_date($value)); }
	function s_when($value) { return e(fmt_when($value)); }

	/* A difference in basis points, signed: +10 bp. A hundredth of a per
	   cent, which is the unit a fill price difference is argued in. */
	function s_bp($value) {
		if ($value === null) { return e(VIEW_DASH); }
		$number = (float) $value;
		$sign   = $number > 0 ? '+' : ($number < 0 ? '-' : '');
		return e($sign . number_format(abs($number), 1) . ' bp');
	}

	/* A link into this page. The disabled flag survives every move, so a
	   reader who opened the switched-off ones does not lose them by
	   clicking a strategy. */
	function s_link($params, $show_disabled) {
		if ($show_disabled) { $params['disabled'] = '1'; }
		return 'strategies.php' . (count($params) ? '?' . http_build_query($params) : '');
	}

	/* The mode, as a reader should see it. "PAPER" and "T212_DEMO" are
	   column values; the badge says Paper and Practice. */
	function s_mode_badge($card) {
		return status_badge_html($card['broker'] === null ? 'paper' : 'due',
		                         strategy_mode_name($card['mode']));
	}

	/* The status, worst-first, with the reason in a tooltip. */
	function s_status_badge($status) {
		$tone = ['halted' => 'halted', 'due' => 'due', 'ok' => 'ok'];
		$kind = isset($tone[$status['tone']]) ? $tone[$status['tone']] : 'off';
		return '<span title="' . htmlspecialchars((string) $status['reason'], ENT_QUOTES)
		     . '" data-bs-toggle="tooltip">'
		     . status_badge_html($kind, $status['words']) . '</span>';
	}

	/* A coloured dot for the menu, where a badge would not fit. */
	function s_status_dot($status) {
		return '<span class="sm-dot sm-dot-' . htmlspecialchars($status['tone'], ENT_QUOTES)
		     . '" title="' . htmlspecialchars($status['words'] . '. ' . $status['reason'], ENT_QUOTES)
		     . '" data-bs-toggle="tooltip" aria-hidden="true"></span>';
	}
?>

	<h1><?= e($title) ?></h1>

	<?php /* One line, and the explanation behind a menu entry rather than a
	         paragraph and a fold at the top of every view. */ ?>
	<p class="sm-sub mb-3">
		Strategies run by the nightly job.
		<strong>No real money is ever at risk</strong>: nothing here can place an
		order against a live account.
		<a href="<?= e(s_link(['s' => '_how'], $show_disabled)) ?>">How this works</a>
	</p>

	<?php if ($halted): ?>
	<div class="alert alert-danger">
		<strong>Trading halted.</strong>
		The kill switch file exists, so the nightly run, the command line and
		the dry run below all place nothing for any strategy.
		<br><small>Remove <code><?= htmlspecialchars((string) $switch_file) ?></code> to resume.</small>
	</div>
	<?php elseif (!$switch_readable): ?>
	<div class="alert alert-warning">
		<strong>The kill switch cannot be read.</strong>
		<code><?= htmlspecialchars((string) $switch_file) ?></code> is not
		somewhere this page can look - the directory is missing, or it is
		outside php-fpm's <code>open_basedir</code> - so "not halted" here is
		an assumption rather than an answer. The nightly job may still see
		it.
		<br><small>Create the directory and add it to <code>open_basedir</code>, or set
		<code>SM_KILL_SWITCH_FILE</code> to a path both can read.</small>
	</div>
	<?php endif ?>

	<?php /* A halted strategy, said at the top of the page as well as on its
	         own card: somebody who opened the overview should not have to
	         find the card to learn that one of them has stopped. */ ?>
	<?php foreach ($summary as $row): ?>
		<?php if ($row['halted'] === 'Y'): ?>
		<div class="alert alert-danger py-2">
			<strong><?= e(strategy_display_name($row['strategy'])) ?> is stopped.</strong>
			<?= e((string) $row['halt_reason']) ?>
			<a href="<?= e(s_link(['s' => $row['strategy']], $show_disabled)) ?>">Open it</a>.
		</div>
		<?php endif ?>
	<?php endforeach ?>

	<?php if (!$ready): ?>
	<div class="alert alert-info">
		The strategy tables are not there yet. Apply
		<code>sql/strategy_rules.sql</code> and the nightly job will start
		filling this page in.
	</div>
	<?php return ?>
	<?php endif ?>

	<?php if (!count($summary)): ?>
	<div class="alert alert-info">
		No strategy has any rules yet. See <code>sql/strategy_rules.sql</code>.
	</div>
	<?php return ?>
	<?php endif ?>

	<?php
		/* The menu's entries, in the order they are shown. A halted
		   strategy goes to the top of its group: it is the one somebody
		   needs to look at. */
		/* $summary is already in the one order the page uses - see
		   strategy_order() - so the menu groups it without re-sorting:
		   two sorts are two orders, and the reader finds the same
		   strategy in two places. */
		$groups = ['Paper' => [], 'Practice' => [], 'Live' => []];
		foreach ($summary as $row) {
			$group = strategy_group_name($row['mode']);
			if (!isset($groups[$group])) { $groups[$group] = []; }
			$groups[$group][] = $row;
		}
		/* Headings only once there are enough entries for them to help.
		   Two strategies under two headings is more furniture than list. */
		$headings = count($summary) > 4;
	?>

	<div class="row g-4">

		<!-- ---------------------------------------------------------- -->
		<!-- The menu.                                                  -->
		<!-- ---------------------------------------------------------- -->
		<div class="col-12 col-md-auto">

			<?php /* On a phone the menu is one control above the content.
			         A form with a select and a button, so it works with
			         JavaScript off; scripts.js submits it on change and
			         hides the button when it can. */ ?>
			<form method="get" action="strategies.php" class="d-md-none mb-3 sm-menu-phone">
				<?php if ($show_disabled): ?><input type="hidden" name="disabled" value="1"><?php endif ?>
				<label class="form-label mb-1" for="sm-menu-select">Show</label>
				<div class="input-group">
					<select class="form-select" name="s" id="sm-menu-select">
						<option value="_overview"<?= $view === '_overview' ? ' selected' : '' ?>>Overview</option>
						<?php foreach ($groups as $group_name => $rows): ?>
							<?php if (!count($rows)) continue ?>
							<optgroup label="<?= e($group_name) ?>">
							<?php foreach ($rows as $row): ?>
								<option value="<?= e($row['strategy']) ?>"<?= $chosen === $row['strategy'] ? ' selected' : '' ?>>
									<?= e(strategy_display_name($row['strategy'])) ?><?php
										if ($row['halted'] === 'Y') { echo ' (stopped)'; } ?>
								</option>
							<?php endforeach ?>
							</optgroup>
						<?php endforeach ?>
						<option value="_backtests"<?= $view === '_backtests' ? ' selected' : '' ?>>Backtests</option>
						<option value="_how"<?= $view === '_how' ? ' selected' : '' ?>>How this works</option>
					</select>
					<button class="btn btn-outline-secondary sm-menu-go" type="submit">Go</button>
				</div>
			</form>

			<nav class="sm-side-menu d-none d-md-block" aria-label="Strategies">
				<ul class="nav flex-column">

					<li class="nav-item">
						<a class="nav-link<?= $view === '_overview' ? ' active' : '' ?>"
						   <?= $view === '_overview' ? 'aria-current="page"' : '' ?>
						   href="<?= e(s_link([], $show_disabled)) ?>">Overview</a>
					</li>

					<?php foreach ($groups as $group_name => $rows): ?>
						<?php if (!count($rows)) continue ?>
						<?php if ($headings): ?>
						<li class="sm-menu-heading"><?= e($group_name) ?></li>
						<?php endif ?>
						<?php foreach ($rows as $row): ?>
						<li class="nav-item">
							<a class="nav-link sm-menu-strategy<?= $chosen === $row['strategy'] ? ' active' : '' ?><?= $row['halted'] === 'Y' ? ' sm-menu-halted' : '' ?>"
							   <?= $chosen === $row['strategy'] ? 'aria-current="page"' : '' ?>
							   title="<?= e($row['strategy']) ?>"
							   href="<?= e(s_link(['s' => $row['strategy']], $show_disabled)) ?>">
								<span class="sm-menu-line">
									<?= s_status_dot($row['status']) ?>
									<span class="sm-menu-name"><?= e(strategy_display_name($row['strategy'])) ?></span>
								</span>
								<span class="sm-menu-line sm-menu-meta">
									<span class="sm-badge sm-status-<?= $row['broker'] === null ? 'paper' : 'due' ?>"><?=
										e(strategy_mode_name($row['mode'])) ?></span>
									<span class="sm-menu-return<?= s_colour($row['return']) ?>"><?= s_percent($row['return']) ?></span>
								</span>
							</a>
						</li>
						<?php endforeach ?>
					<?php endforeach ?>

					<li class="nav-item">
						<a class="nav-link<?= $view === '_backtests' ? ' active' : '' ?>"
						   <?= $view === '_backtests' ? 'aria-current="page"' : '' ?>
						   href="<?= e(s_link(['s' => '_backtests'], $show_disabled)) ?>">Backtests</a>
					</li>
					<li class="nav-item">
						<a class="nav-link<?= $view === '_how' ? ' active' : '' ?>"
						   <?= $view === '_how' ? 'aria-current="page"' : '' ?>
						   href="<?= e(s_link(['s' => '_how'], $show_disabled)) ?>">How this works</a>
					</li>

					<?php /* The switched-off ones, at the bottom and muted. A
					         card that will never trade again looks exactly
					         like one that trades tomorrow. */ ?>
					<?php if ($disabled_count > 0 || $show_disabled): ?>
					<li class="nav-item">
						<a class="nav-link text-body-secondary sm-menu-disabled"
						   href="<?= $show_disabled ? 'strategies.php' : 'strategies.php?disabled=1' ?>">
							<?= $show_disabled
							    ? 'Hide switched-off'
							    : 'Disabled (' . e(fmt_quantity($disabled_count)) . ')' ?>
						</a>
					</li>
					<?php endif ?>
				</ul>
			</nav>
		</div>

		<!-- ---------------------------------------------------------- -->
		<!-- The view.                                                  -->
		<!-- ---------------------------------------------------------- -->
		<div class="col-12 col-md sm-view-col">

		<?php /* ============================================================
		         The overview: the summary table, the chart, and the paper
		         against practice panels.
		         ============================================================ */ ?>
		<?php if ($view === '_overview'): ?>

			<h2 class="h5">Every strategy, side by side</h2>

			<div class="table-responsive mb-4">
				<?php /* sm-stack: below 768px the rows become one block per
				         strategy, each cell labelled, rather than a table
				         eleven columns wide that has to be scrolled
				         sideways to read. */ ?>
				<table class="table table-sm table-striped sm-table-sort sm-stack sm-table-wide align-middle">
					<thead>
						<tr>
							<th data-sort="text">Strategy</th>
							<?php /* Second, not eleventh. It is the thing to look at
							         first, and on a laptop the eleventh column is off
							         the right-hand edge. */ ?>
							<th data-sort="text">Status</th>
							<th data-sort="text">Mode</th>
							<th class="sm-num" data-sort="number">Value</th>
							<th class="sm-num" data-sort="number">Return</th>
							<th class="sm-num" data-sort="number">Benchmark</th>
							<th class="sm-num" data-sort="number">Difference</th>
							<th class="sm-num" data-sort="number">Worst fall</th>
							<th class="sm-num" data-sort="number">Holdings</th>
							<th class="sm-num" data-sort="number">Cash</th>
							<th data-sort="text">Next rebalance</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($summary as $row): ?>
						<?php /* The difference is the return less the benchmark, in
						         points: "up 5% while the market was up 2%" is the
						         thing being measured, and a reader should not have
						         to do the subtraction. */
						   $difference = ($row['return'] === null || $row['benchmark_return'] === null)
						               ? null : $row['return'] - $row['benchmark_return']; ?>
						<tr<?= $row['halted'] === 'Y' ? ' class="table-danger"' : '' ?>>
							<td data-label="Strategy"><a href="<?= e(s_link(['s' => $row['strategy']], $show_disabled)) ?>"
							       title="<?= e($row['strategy']) ?>" data-bs-toggle="tooltip"><?=
								e(strategy_display_name($row['strategy'])) ?></a></td>
							<td data-label="Status"><?= s_status_badge($row['status']) ?></td>
							<td data-label="Mode"><?= s_mode_badge($row) ?></td>
							<td class="sm-num" data-label="Value" data-value="<?= e((string) $row['total']) ?>"><?= s_money($row['total'], 0) ?></td>
							<td class="sm-num<?= s_colour($row['return']) ?>" data-label="Return" data-value="<?= e((string) $row['return']) ?>"><?= s_percent($row['return']) ?></td>
							<td class="sm-num<?= s_colour($row['benchmark_return']) ?>" data-label="Benchmark" data-value="<?= e((string) $row['benchmark_return']) ?>"><?= s_percent($row['benchmark_return']) ?></td>
							<td class="sm-num<?= s_colour($difference) ?>" data-label="Difference" data-value="<?= e((string) $difference) ?>"><?= s_percent($difference) ?></td>
							<?php /* A drawdown is stored as a positive fraction and is
							         a fall, so it is printed negative. "8.5%" in the
							         column headed "Worst fall" is the right number
							         pointing the wrong way. */ ?>
							<td class="sm-num" data-label="Worst fall" data-value="<?= e((string) $row['drawdown']) ?>"><?= $row['drawdown'] === null
								? VIEW_DASH : move_pct_html(-$row['drawdown'] * 100) ?></td>
							<td class="sm-num" data-label="Holdings"><?= e(fmt_quantity($row['holdings'])) ?></td>
							<td class="sm-num" data-label="Cash" data-value="<?= e((string) $row['cash']) ?>"><?= s_money($row['cash'], 0) ?></td>
							<td data-label="Next rebalance" data-value="<?= e((string) ($row['next_rebalance'] === null
								? $row['starts_on'] ?? '' : $row['next_rebalance'])) ?>"><?= s_date($row['next_rebalance']) ?></td>
						</tr>
					<?php endforeach ?>
					<?php
						/* The benchmark, named for what it is. Equal weighted is
						   a choice somebody made, not a fact about the index. */
						$bench_return = null;
						if (count($benchmark) > 1) {
							$bench_first = (float) $benchmark[0]['total'];
							$bench_last  = (float) $benchmark[count($benchmark) - 1]['total'];
							if ($bench_first > 0) { $bench_return = ($bench_last / $bench_first) - 1; }
						}
					?>
					<?php /* On a phone the cells with no label are left out of the
					         stacked block: the benchmark has a name and a return
					         and nine dashes, and nine dashes is not worth a
					         screenful. */ ?>
					<?php /* Ten dashes said nothing. Until the benchmark has two
					         nights there is no return to show, so the row says when
					         there will be one. */ ?>
					<?php
						/* The level itself, from the first night to the latest:
						   a dash in the Value column of the one row that has a
						   level says nothing, and "100 -> 98.6" is the figure
						   the return beside it was worked out from. */
						$bench_level = function ($value) {
							$number = number_format((float) $value, 1);
							return substr($number, -2) === '.0' ? substr($number, 0, -2) : $number;
						};
						$bench_value = VIEW_DASH;
						if (count($benchmark) === 1) {
							$bench_value = $bench_level($benchmark[0]['total']);
						}
						elseif (count($benchmark) > 1) {
							$bench_value = $bench_level($benchmark[0]['total']) . ' → '
							             . $bench_level($benchmark[count($benchmark) - 1]['total']);
						}
					?>
					<tr class="sm-total-row text-body-secondary">
						<td data-label="Strategy">FTSE 350 (equal weighted)</td>
						<td data-label="Status">benchmark</td>
						<td><?= e(VIEW_DASH) ?></td>
						<td class="sm-num" data-label="Value"><?= e($bench_value) ?></td>
						<?php if ($bench_return === null): ?>
						<td colspan="7" class="text-body-secondary" data-label="Return"><small>
							<?php if (count($benchmark)): ?>
								The first return is from <?= s_date($benchmark[0]['date']) ?>,
								once there is a second night to measure against.
							<?php else: ?>
								Nothing has been measured yet: the nightly job writes the
								first level tonight.
							<?php endif ?>
						</small></td>
						<?php else: ?>
						<td class="sm-num<?= s_colour($bench_return) ?>" data-label="Return"><?= s_percent($bench_return) ?></td>
						<td class="sm-num"><?= e(VIEW_DASH) ?></td>
						<td class="sm-num"><?= e(VIEW_DASH) ?></td>
						<td class="sm-num"><?= e(VIEW_DASH) ?></td>
						<td class="sm-num"><?= e(VIEW_DASH) ?></td>
						<td class="sm-num"><?= e(VIEW_DASH) ?></td>
						<td><?= e(VIEW_DASH) ?></td>
						<?php endif ?>
					</tr>
					</tbody>
				</table>
			</div>

			<!-- The chart -->
			<div class="card mb-4">
				<div class="card-body">
					<div class="d-flex flex-wrap align-items-center justify-content-between mb-2">
						<h2 class="h6 card-title mb-0">Value over time</h2>
						<div class="form-check form-switch ms-auto me-3">
							<input class="form-check-input" type="checkbox" id="chart-common-base">
							<label class="form-check-label" for="chart-common-base">
								<small>Rebase all to one date</small>
							</label>
						</div>
						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" id="chart-backtests">
							<label class="form-check-label" for="chart-backtests">
								<small>Show backtests</small>
							</label>
						</div>
					</div>
					<?php if (!count($chart)): ?>
						<p class="text-body-secondary mb-0"><small>
							The first line appears after two nightly runs; there is
							<?= count($summary) ? 'one night' : 'nothing' ?> so far.
						</small></p>
					<?php else: ?>
						<div id="strategy-chart"></div>
						<small id="strategy-chart-status" class="text-body-secondary d-block mt-1"></small>
						<noscript><p class="text-body-secondary">The chart needs JavaScript; the figures above do not.</p></noscript>
						<?php /* The data as JSON rather than as generated JavaScript: the
						         strategy names are configuration somebody can edit, and
						         the hex flags leave no way out of the element. */ ?>
						<script type="application/json" id="strategy-chart-data"><?=
							json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
						?></script>
						<script src="<?= asset_url('/js/lightweight-charts.standalone.production.js') ?>"></script>
						<script src="<?= asset_url('/js/strategy_chart.js') ?>"></script>
					<?php endif ?>
				</div>
			</div>

			<!-- Paper against practice -->
			<?php foreach ($pairs as $pair): ?>
			<?php
				[$one, $two] = $pair['strategies'];
				$name_one = strategy_display_name($one);
				$name_two = strategy_display_name($two);
			?>
			<div class="card mb-4">
				<div class="card-body">
					<h2 class="h6 card-title">
						<?= e($name_one) ?> against <?= e($name_two) ?>
					</h2>
					<p class="text-body-secondary"><small>
						The same rules, one on paper and one at the broker. The difference
						between them is the evidence for or against real money.
						<?php foreach ($pair['notes'] as $note): ?><?= e($note) ?> <?php endforeach ?>
					</small></p>

					<div class="sm-strip mb-3">
						<div class="sm-strip-item">
							<div class="sm-strip-label">Return since <?= s_date($pair['from']) ?></div>
							<div class="sm-strip-value<?= s_colour($pair['returns'][$one] ?? null) ?>">
								<?= s_percent($pair['returns'][$one] ?? null) ?>
							</div>
							<div class="sm-strip-label"><?= e($name_one) ?></div>
						</div>
						<div class="sm-strip-item">
							<div class="sm-strip-label">Return since <?= s_date($pair['from']) ?></div>
							<div class="sm-strip-value<?= s_colour($pair['returns'][$two] ?? null) ?>">
								<?= s_percent($pair['returns'][$two] ?? null) ?>
							</div>
							<div class="sm-strip-label"><?= e($name_two) ?></div>
						</div>
						<div class="sm-strip-item">
							<div class="sm-strip-label">Gap</div>
							<div class="sm-strip-value<?= s_colour($pair['gap']) ?>"><?= s_percent($pair['gap']) ?></div>
							<div class="sm-strip-label">points</div>
						</div>
						<div class="sm-strip-item">
							<div class="sm-strip-label">Fill prices</div>
							<div class="sm-strip-value<?= s_colour($pair['average_bp']) ?>">
								<?= s_bp($pair['average_bp']) ?>
							</div>
							<div class="sm-strip-label">practice against paper</div>
						</div>
					</div>

					<div class="row">
						<div class="col-md-6">
							<h3 class="h6">Held by one and not the other</h3>
							<p class="mb-3"><small>
								<?php foreach ([$one, $two] as $side): ?>
									<strong><?= e(strategy_display_name($side)) ?> only:</strong>
									<?= count($pair['only_in'][$side])
										? e(implode(', ', $pair['only_in'][$side])) : 'nothing' ?><br>
								<?php endforeach ?>
							</small></p>

							<h3 class="h6">Costs against what was traded</h3>
							<p class="mb-0"><small>
								<?php foreach ([$one, $two] as $side): ?>
									<strong><?= e(strategy_display_name($side)) ?>:</strong>
									<?= s_money($pair['costs'][$side]['paid'] ?? null, 2) ?>
									on <?= s_money($pair['costs'][$side]['traded'] ?? null, 0) ?> traded
									(<?= $pair['costs'][$side]['percent'] === null ? e(VIEW_DASH)
										: e(fmt_pct(($pair['costs'][$side]['percent']) * 100, 2)) ?>)<br>
								<?php endforeach ?>
							</small></p>
						</div>
						<div class="col-md-6">
							<h3 class="h6">The same share on the same rebalance</h3>
							<?php if (!count($pair['fills'])): ?>
								<p class="text-body-secondary"><small>
									Neither has filled the same share on the same rebalance yet, so
									there is nothing to compare the two cost models on.
								</small></p>
							<?php else: ?>
							<div class="table-responsive">
								<table class="table table-sm">
									<thead>
										<tr>
											<th>Date</th><th>Share</th><th>Side</th>
											<th class="sm-num">Paper</th>
											<th class="sm-num">Practice</th>
											<th class="sm-num">Difference</th>
										</tr>
									</thead>
									<tbody>
									<?php foreach ($pair['fills'] as $fill): ?>
										<tr>
											<td><?= s_date($fill['date']) ?></td>
											<td><?= htmlspecialchars($fill['symbol']) ?></td>
											<td><?= htmlspecialchars($fill['side']) ?></td>
											<td class="sm-num"><?= e(fmt_pounds($fill['one'])) ?></td>
											<td class="sm-num"><?= e(fmt_pounds($fill['two'])) ?></td>
											<td class="sm-num<?= s_colour($fill['bp']) ?>"><?= s_bp($fill['bp']) ?></td>
										</tr>
									<?php endforeach ?>
									</tbody>
								</table>
							</div>
							<p class="text-body-secondary mb-0"><small>
								Paper fills at the next close with an allowance for the spread;
								the practice account fills at the market during the session. If
								the two are close, the paper record's cost model is fair.
							</small></p>
							<?php endif ?>
						</div>
					</div>
				</div>
			</div>
			<?php endforeach ?>

		<?php endif ?>

		<?php /* ============================================================
		         One strategy: its header, and its tabs.
		         ============================================================ */ ?>
		<?php if ($view === '_strategy' && $card !== null): ?>

			<div class="card mb-4">
				<div class="card-body">

					<h2 class="h5 card-title">
						<span title="<?= e($card['strategy']) ?>" data-bs-toggle="tooltip"><?=
							e(strategy_display_name($card['strategy'])) ?></span>
						<?= s_mode_badge($card) ?>
						<?= s_status_badge($card['status']) ?>
						<?php if ($card['enabled'] !== 'Y'): ?><?= status_badge_html('off') ?><?php endif ?>
					</h2>

					<?php if ($card['halted'] === 'Y'): ?>
					<div class="alert alert-danger py-2">
						<strong>Stopped.</strong>
						<?= e((string) $card['halt_reason']) ?>
						This strategy places no orders until somebody has looked at it and
						cleared the halt.
					</div>
					<?php endif ?>

					<?php /* What the broker says, for an account that has one. The
					         figures above are this application's; the line below is
					         whether the broker agrees with them. */ ?>
					<?php if ($card['agreement'] !== null): ?>
					<?php $agree = $card['agreement']; ?>
					<p class="mb-2">
						<span class="sm-dot sm-dot-<?= e($agree['tone']) ?>"></span>
						<strong><?= e($agree['words']) ?></strong>
						<?php if (count($agree['differences'])): ?>
						<br><small class="text-danger">
							<?php foreach ($agree['differences'] as $difference): ?>
								<?= e($difference) ?><br>
							<?php endforeach ?>
						</small>
						<?php endif ?>
						<?php if (count($agree['waiting'])): ?>
						<br><small class="text-body-secondary">
							<?php foreach ($agree['waiting'] as $waiting): ?>
								<?= e($waiting) ?><br>
							<?php endforeach ?>
						</small>
						<?php endif ?>
						<br><small class="text-body-secondary">
							Cash here <?= s_money($agree['our_cash'], 2) ?>,
							at the broker <?= $agree['their_cash'] === null
								? e(VIEW_DASH) : s_money($agree['their_cash'], 2) ?>.
							<?php if ($agree['sync'] !== null): ?>
								Last read <?= s_when($agree['sync']['finished_at']) ?>
								(<?= e((string) $agree['sync']['status']) ?>).
							<?php else: ?>
								The broker has never been read.
							<?php endif ?>
							<a href="broker.php">Broker page</a>.
						</small>
					</p>
					<?php endif ?>

					<?php if ($card['start'] === null && (int) $card['holdings'] === 0): ?>
						<?php /* A row of dashes reads as a strategy that lost
						         everything rather than one that has not started. */ ?>
						<div class="sm-strip mb-3">
							<div class="sm-strip-item">
								<div class="sm-strip-label">Not started</div>
								<div class="sm-strip-value" style="font-size:1.1rem">
									<?php if ($card['enabled'] !== 'Y'): ?>
										Switched off, so it never will
									<?php elseif ($card['starts_on'] !== null): ?>
										Starts on <?= s_date($card['starts_on']) ?>
									<?php else: ?>
										Starts at the next rebalance
									<?php endif ?>
								</div>
							</div>
							<div class="sm-strip-item">
								<div class="sm-strip-label">Opening cash</div>
								<div class="sm-strip-value"><?= s_money($card['cash'], 0) ?></div>
							</div>
						</div>
					<?php else: ?>
					<div class="row text-center mb-3">
						<div class="col">
							<div class="text-muted"><small>Value</small></div>
							<div class="h5 mb-0"><?= s_money($card['total'], 0) ?></div>
							<?php /* Cash plus holdings at the last close. When a fill is
							         newer than that close - an order filled at the
							         market this morning, and the last close is
							         yesterday's - the value is today's book at
							         yesterday's prices, so say which day it is. */ ?>
							<?php if (!empty($card['prices_behind'])): ?>
								<div class="text-muted"><small>prices <?= s_date($card['prices_as_of']) ?></small></div>
							<?php endif ?>
							<?php if (!empty($card['unpriced'])): ?>
								<div class="text-muted"><small><?= (int) $card['unpriced'] ?>
									at cost, no price</small></div>
							<?php endif ?>
						</div>
						<div class="col">
							<div class="text-muted"><small><?= $card['start'] === null
								? 'Return' : 'Return since ' . s_date($card['start']) ?></small></div>
							<div class="h5 mb-0<?= s_colour($card['return']) ?>"><?= s_percent($card['return']) ?></div>
						</div>
						<div class="col">
							<div class="text-muted"><small>Benchmark, same period</small></div>
							<div class="h5 mb-0<?= s_colour($card['benchmark_return']) ?>"><?= s_percent($card['benchmark_return']) ?></div>
						</div>
						<div class="col">
							<div class="text-muted"><small>Worst fall</small></div>
							<div class="h5 mb-0"><?= $card['drawdown'] === null
								? VIEW_DASH : move_pct_html(-$card['drawdown'] * 100) ?></div>
						</div>
						<div class="col">
							<div class="text-muted"><small>Cash</small></div>
							<div class="h5 mb-0"><?= s_money($card['cash'], 0) ?></div>
						</div>
						<div class="col">
							<div class="text-muted"><small>Holdings</small></div>
							<div class="h5 mb-0"><?= e(fmt_quantity($card['holdings'])) ?></div>
						</div>
					</div>
					<?php endif ?>

					<p class="text-body-secondary">
						<small>
							Last rebalance <?= s_date($card['last_rebalance']) ?>.
							Next on or after <?= s_date($card['next_rebalance'] === null
								? $card['starts_on'] : $card['next_rebalance']) ?>.
						</small>
					</p>

					<?php if ($card['stale'] !== null): ?>
					<div class="alert alert-warning py-2">
						<small><?= htmlspecialchars((string) $card['stale']) ?></small>
					</div>
					<?php endif ?>

					<?php /* The dry run opens over the page rather than reloading it, and
					         the plan arrives as data rather than as the engine's own
					         printed lines - a table of trades with reasons answers "does
					         tonight look right", where a <pre> block of text did not.
					         templates/strategy_dry_run.js does the asking. */ ?>
					<div class="mb-3">
						<button type="button" class="btn btn-sm btn-outline-primary"
						        data-dry-run="<?= htmlspecialchars($card['strategy']) ?>"
						        data-dry-run-name="<?= e(strategy_display_name($card['strategy'])) ?>"
						        data-dry-run-title="<?= $card['broker'] === null
						            ? 'What tonight&#39;s run would do'
						            : 'What the next run would plan, and what the next send would send' ?>">
							Dry run
						</button>
						<small class="text-muted ms-2"><?= $card['broker'] === null
							? 'Shows what tonight\'s run would do. Writes nothing.'
							: 'Shows what the next run would plan and what the next send would send. Writes nothing.' ?></small>
					</div>

					<?php
						$panes = ['holdings' => 'Holdings', 'orders' => 'Orders', 'trades' => 'Trades'];
						if (count($card['backtests'])) { $panes['backtest'] = 'Backtest'; }
						$panes['rules'] = 'Rules';
						if (!isset($panes[$tab])) { $tab = 'holdings'; }
					?>
					<ul class="nav nav-tabs" role="tablist">
						<?php foreach ($panes as $key => $label): ?>
						<li class="nav-item" role="presentation">
							<a class="nav-link<?= $tab === $key ? ' active' : '' ?>"
							   id="tab-<?= e($key) ?>"
							   data-bs-toggle="tab" data-bs-target="#pane-<?= e($key) ?>"
							   role="tab" aria-controls="pane-<?= e($key) ?>"
							   aria-selected="<?= $tab === $key ? 'true' : 'false' ?>"
							   href="<?= e(s_link(['s' => $card['strategy'], 'tab' => $key], $show_disabled)) ?>"><?=
								e($label) ?></a>
						</li>
						<?php endforeach ?>
					</ul>

					<div class="tab-content pt-3">

						<!-- Holdings -->
						<div class="tab-pane fade<?= $tab === 'holdings' ? ' show active' : '' ?>"
						     id="pane-holdings" role="tabpanel" aria-labelledby="tab-holdings">
							<?php if (!count($card['holdings_rows'])): ?>
								<p class="text-body-secondary">Nothing held.
									<?= $card['enabled'] === 'Y' && !count($card['pending'])
									    ? 'The next rebalance will build the book.' : '' ?></p>
							<?php else: ?>
							<?php
								$total_value = 0.0; $total_cost = 0.0; $total_gain = 0.0;
								foreach ($card['holdings_rows'] as $holding) {
									if ($holding['value'] !== null) { $total_value += $holding['value']; }
									$total_cost += $holding['cost'];
									if ($holding['gain'] !== null) { $total_gain += $holding['gain']; }
								}
								/* Weights are a share of the account, the same thing
								   Target weight is a share of, so the cash sits in the
								   table as a row of its own and the column still adds
								   up to a hundred. The basis is the holdings on this
								   page plus the cash, not the stored total, so the
								   column and the total it is divided by are the same
								   arithmetic. */
								$cash_held     = $card['cash'] === null ? 0.0 : (float) $card['cash'];
								$account_value = $total_value + $cash_held;
								$cash_weight   = $account_value > 0 ? $cash_held / $account_value : null;
							?>
							<div class="table-responsive">
								<table class="table table-sm table-striped sm-table-sort">
									<thead>
										<tr>
											<th data-sort="text">Share</th>
											<th data-sort="text">Company</th>
											<th class="sm-num" data-sort="number">Quantity</th>
											<?php /* Pounds, both of them. The engine converts the stored pence
											         once, at strategy_price_asof(), so everything from the
											         sizing to this column is in the currency the costs are in. */ ?>
											<th class="sm-num" data-sort="number">Average cost (&pound;)</th>
											<th class="sm-num" data-sort="number">Last price (&pound;)</th>
											<th class="sm-num" data-sort="number">Value</th>
											<th class="sm-num" data-sort="number">Weight</th>
											<th class="sm-num" data-sort="number">Target weight</th>
											<th class="sm-num" data-sort="number">Gain</th>
											<th class="sm-num" data-sort="number">Days held</th>
										</tr>
									</thead>
									<tbody>
									<?php foreach ($card['holdings_rows'] as $holding): ?>
										<?php $target = $card['targets'][$holding['symbol']] ?? null; ?>
										<tr>
											<td><a href="quote.php?symbol=<?= urlencode($holding['symbol']) ?>"><?= htmlspecialchars($holding['symbol']) ?></a></td>
											<td><?= htmlspecialchars((string) $holding['name']) ?><?php
												if ($holding['stale']): ?> <span class="badge bg-warning text-dark">not trading</span><?php endif ?><?php
												/* One close below the 200 day average: one more and the
												   exit rule sells it. Worth seeing in the book rather
												   than finding out from tomorrow's trades. */
												if (in_array($holding['symbol'], $card['exit_warnings'], true)): ?>
												<span class="badge bg-warning text-dark"
												      title="Closed below its 200 day average once. One more close and the exit rule sells it."
												      data-bs-toggle="tooltip">one close below</span><?php endif ?></td>
											<td class="sm-num" data-value="<?= e((string) $holding['quantity']) ?>"><?= s_shares($holding['quantity'], $card) ?></td>
											<td class="sm-num" data-value="<?= e((string) $holding['avg_price']) ?>"><?= e(fmt_pounds($holding['avg_price'])) ?></td>
											<td class="sm-num" data-value="<?= e((string) $holding['price']) ?>"><?= e(fmt_pounds($holding['price'])) ?></td>
											<td class="sm-num" data-value="<?= e((string) $holding['value']) ?>"><?= s_money($holding['value'], 0) ?></td>
											<td class="sm-num" data-value="<?= e((string) $holding['weight']) ?>"><?= $holding['weight'] === null
												? VIEW_DASH : e(fmt_pct($holding['weight'] * 100)) ?></td>
											<td class="sm-num" data-value="<?= e((string) $target) ?>"><?= $target === null
												? VIEW_DASH : e(fmt_pct($target * 100)) ?></td>
											<td class="sm-num<?= s_colour($holding['gain']) ?>" data-value="<?= e((string) $holding['gain']) ?>">
												<?= s_money_signed($holding['gain'], 0) ?>
												<?= $holding['gain_percent'] === null ? '' : '(' . s_percent($holding['gain_percent']) . ')' ?>
											</td>
											<td class="sm-num" data-value="<?= e((string) $holding['days_held']) ?>"><?= $holding['days_held'] === null ? VIEW_DASH : (int) $holding['days_held'] ?></td>
										</tr>
									<?php endforeach ?>
									</tbody>
									<tfoot>
										<tr>
											<td colspan="5">Cash</td>
											<td class="sm-num"><?= s_money($cash_held, 0) ?></td>
											<td class="sm-num"><?= $cash_weight === null
												? VIEW_DASH : e(fmt_pct($cash_weight * 100)) ?></td>
											<td class="sm-num"></td>
											<td class="sm-num"></td>
											<td class="sm-num"></td>
										</tr>
										<tr class="sm-total-row">
											<th colspan="5">Total</th>
											<th class="sm-num"><?= s_money($account_value, 0) ?></th>
											<th class="sm-num"><?= e(fmt_pct(100, 1)) ?></th>
											<th class="sm-num"></th>
											<th class="sm-num<?= s_colour($total_gain) ?>">
												<?= s_money_signed($total_gain, 0) ?>
												<?= $total_cost > 0 ? '(' . s_percent($total_gain / $total_cost) . ')' : '' ?>
											</th>
											<th class="sm-num"></th>
										</tr>
									</tfoot>
								</table>
							</div>
							<?php endif ?>
						</div>

						<!-- Orders -->
						<div class="tab-pane fade<?= $tab === 'orders' ? ' show active' : '' ?>"
						     id="pane-orders" role="tabpanel" aria-labelledby="tab-orders">
							<?php
								/* Everything not yet finished, then today's. An order
								   that filled an hour ago is still what somebody came
								   to this tab to read about. */
								$today_orders = [];
								foreach ($card['trades'] as $trade) {
									$when = $trade['fill_date'] === null ? $trade['as_of_date'] : $trade['fill_date'];
									if (substr((string) $when, 0, 10) === date('Y-m-d')
									    && !in_array((string) $trade['status'], ['PENDING', 'SENT', 'UNKNOWN'], true)) {
										$today_orders[] = $trade;
									}
								}
							?>
							<?php if (!count($card['pending']) && !count($today_orders)): ?>
								<p class="text-body-secondary">No orders outstanding.</p>
							<?php else: ?>

							<?php if (count($card['pending'])): ?>
							<?php
								/* Counted by what each one is waiting for, not by the
								   first one: a run that sent nine of fifteen has two
								   different waits in the same list. */
								$waits = [];
								foreach ($card['pending'] as $order) {
									$wait = strategy_order_wait($order['status'], $order['broker']);
									$waits[$wait] = ($waits[$wait] ?? 0) + 1;
								}
							?>
							<p class="mb-1">
								<?php foreach ($waits as $wait => $count): ?>
									<strong><?= e(fmt_quantity($count)) ?></strong>
									order<?= $count === 1 ? ' is' : 's are' ?>
									<?= e(strtolower($wait)) ?>.
								<?php endforeach ?>
							</p>
							<div class="table-responsive mb-4">
								<table class="table table-sm table-striped">
									<thead>
										<tr>
											<th>Side</th><th>Share</th><th></th>
											<th class="sm-num">Quantity</th>
											<th class="sm-num">Planned value</th>
											<th>Status</th><th>Reason</th>
											<?php if ($card['broker'] !== null): ?>
											<th>Order</th><th>Sent</th>
											<?php endif ?>
										</tr>
									</thead>
									<tbody>
									<?php foreach ($card['pending'] as $order): ?>
										<?php
											/* Priced from the one query the controller made
											   for every outstanding order, not one per row. */
											$order_price = $card['pending_prices'][$order['symbol']] ?? null;
											$planned = $order_price === null
											         ? null : (float) $order['quantity'] * $order_price;
										?>
										<tr>
											<td><?= htmlspecialchars((string) $order['side']) ?></td>
											<td><a href="quote.php?symbol=<?= urlencode($order['symbol']) ?>"><?= htmlspecialchars($order['symbol']) ?></a></td>
											<td><?= htmlspecialchars((string) $order['name']) ?></td>
											<td class="sm-num"><?= s_shares($order['quantity'], $card) ?></td>
											<td class="sm-num"><?= s_money($planned, 0) ?></td>
											<td><?= e(strategy_order_wait($order['status'], $order['broker'])) ?></td>
											<td><?= htmlspecialchars((string) $order['note']) ?></td>
											<?php if ($card['broker'] !== null): ?>
											<td><small><?= e((string) $order['broker_order_id']) ?></small></td>
											<td><small><?= s_when($order["sent_at"]) ?></small></td>
											<?php endif ?>
										</tr>
									<?php endforeach ?>
									</tbody>
								</table>
							</div>
							<?php endif ?>

							<?php if (count($today_orders)): ?>
							<h3 class="h6">Finished today</h3>
							<div class="table-responsive">
								<table class="table table-sm table-striped">
									<thead>
										<tr>
											<th>Side</th><th>Share</th>
											<th class="sm-num">Quantity</th>
											<th>Status</th><th>Reason</th>
											<?php if ($card['broker'] !== null): ?>
											<th>Order</th>
											<th class="sm-num">Fill price (&pound;)</th>
											<th class="sm-num">Stamp duty</th>
											<th class="sm-num">Total</th>
											<?php endif ?>
										</tr>
									</thead>
									<tbody>
									<?php foreach ($today_orders as $order): ?>
										<tr>
											<td><?= htmlspecialchars((string) $order['side']) ?></td>
											<td><a href="quote.php?symbol=<?= urlencode($order['symbol']) ?>"><?= htmlspecialchars($order['symbol']) ?></a></td>
											<td class="sm-num"><?= s_shares($order['quantity'], $card) ?></td>
											<td><?= e(strategy_order_outcome($order['status'])) ?></td>
											<td><?= htmlspecialchars((string) $order['note']) ?></td>
											<?php if ($card['broker'] !== null): ?>
											<td><small><?= e((string) $order['broker_order_id']) ?></small></td>
											<td class="sm-num"><?= e(fmt_pounds($order['fill_price'])) ?></td>
											<td class="sm-num"><?= s_money($order['stamp_duty'], 2) ?></td>
											<td class="sm-num"><?= s_money_signed($order['total_cost'], 2) ?></td>
											<?php endif ?>
										</tr>
									<?php endforeach ?>
									</tbody>
								</table>
							</div>
							<?php endif ?>
							<?php endif ?>
						</div>

						<!-- Trades -->
						<div class="tab-pane fade<?= $tab === 'trades' ? ' show active' : '' ?>"
						     id="pane-trades" role="tabpanel" aria-labelledby="tab-trades">
							<?php
								$fills = [];
								foreach ($card['trades'] as $trade) {
									if ($trade['status'] === 'FILLED') { $fills[] = $trade; }
								}
							?>
							<p class="mb-3">
								<small>
									Dealing costs paid to date
									<strong><?= s_money($card['costs']['total'], 2) ?></strong>
									over <?= (int) $card['costs']['fills'] ?> fills
									(commission <?= s_money($card['costs']['commission'], 2) ?>,
									stamp duty <?= s_money($card['costs']['stamp_duty'], 2) ?>,
									spread <?= s_money($card['costs']['slippage'], 2) ?>).
								</small>
							</p>
							<?php if (!count($fills)): ?>
								<p class="text-body-secondary">No trades yet.</p>
							<?php else: ?>
							<div class="table-responsive">
								<table class="table table-sm table-striped sm-table-sort">
									<thead>
										<tr>
											<th data-sort="text">Date</th>
											<th data-sort="text">Side</th>
											<th data-sort="text">Share</th>
											<th class="sm-num" data-sort="number">Quantity</th>
											<th class="sm-num" data-sort="number">Price (&pound;)</th>
											<th class="sm-num" data-sort="number">Consideration</th>
											<th class="sm-num" data-sort="number">Costs</th>
											<th class="sm-num" data-sort="number">Total</th>
											<th data-sort="text">Reason</th>
										</tr>
									</thead>
									<tbody>
									<?php foreach (array_slice($fills, 0, 15) as $trade): ?>
										<?php include __DIR__ . '/strategy_trade_row.php' ?>
									<?php endforeach ?>
									</tbody>
								</table>
							</div>
							<?php if (count($fills) > 15): ?>
							<details>
								<summary>Show all <?= count($fills) ?></summary>
								<div class="table-responsive mt-2">
									<table class="table table-sm table-striped">
										<thead>
											<tr>
												<th>Date</th><th>Side</th><th>Share</th>
												<th class="sm-num">Quantity</th>
												<th class="sm-num">Price (&pound;)</th>
												<th class="sm-num">Consideration</th>
												<th class="sm-num">Costs</th>
												<th class="sm-num">Total</th>
												<th>Reason</th>
											</tr>
										</thead>
										<tbody>
										<?php foreach (array_slice($fills, 15) as $trade): ?>
											<?php include __DIR__ . '/strategy_trade_row.php' ?>
										<?php endforeach ?>
										</tbody>
									</table>
								</div>
							</details>
							<?php endif ?>
							<?php endif ?>
						</div>

						<!-- Backtest -->
						<?php if (count($card['backtests'])): ?>
						<div class="tab-pane fade<?= $tab === 'backtest' ? ' show active' : '' ?>"
						     id="pane-backtest" role="tabpanel" aria-labelledby="tab-backtest">
							<?php $runs = $card['backtests']; $show_strategy_column = false; ?>
							<?php include __DIR__ . '/strategy_backtests.php' ?>
						</div>
						<?php endif ?>

						<!-- Rules -->
						<div class="tab-pane fade<?= $tab === 'rules' ? ' show active' : '' ?>"
						     id="pane-rules" role="tabpanel" aria-labelledby="tab-rules">
							<div class="row">
								<div class="col-md-6">
									<h3 class="h6">What it holds</h3>
									<ul class="mb-3">
										<?php if ($card['screen'] !== null): ?>
											<li>Holds the <strong><?= e($card['screen']) ?></strong> screen.</li>
										<?php elseif ($card['rules'] !== null && $card['rules']['score_indicator'] !== null): ?>
											<li>Holds the best by <strong><?= e($card['rules']['score_indicator']) ?></strong>.</li>
										<?php endif ?>
										<?php if ($card['rules'] !== null): ?>
											<li><?= e(fmt_quantity($card['rules']['holdings'])) ?> equally weighted positions.</li>
											<li><?= e(strategy_rebalance_words($card['rules']['rebalance'])) ?>.</li>
											<?php $rank = strategy_rank_words($card['rules']['rank_by']); ?>
											<?php if ($rank !== null): ?><li><?= e($rank) ?>.</li><?php endif ?>
											<?php if (!empty($card['rules']['hold_rank'])): ?>
											<li>Held until it falls out of the top
												<?= e(fmt_quantity($card['rules']['hold_rank'])) ?>.</li>
											<?php endif ?>
											<?php foreach ($card['rules']['exit_list'] as $exit): ?>
											<li><?= e(strategy_exit_words($exit)) ?>.</li>
											<?php endforeach ?>
											<?php if (!count($card['rules']['exit_list'])): ?>
											<li>No exits between rebalances.</li>
											<?php endif ?>
											<li><?= $card['rules']['exclude_funds'] === 'Y'
												? 'Funds are excluded.' : 'Funds are not excluded.' ?></li>
										<?php endif ?>
									</ul>

									<h3 class="h6">Dealing</h3>
									<ul class="mb-3">
										<?php foreach ($card['dealing'] as $line): ?>
										<li><?= e($line) ?></li>
										<?php endforeach ?>
									</ul>
								</div>
								<div class="col-md-6">
									<h3 class="h6">Limits</h3>
									<ul class="mb-3">
										<li>At most <?= e(fmt_pct(STRATEGY_MAX_POSITION_WEIGHT * 100, 0)) ?> of the
											account in one share.</li>
										<li>At most <?= e(fmt_quantity(STRATEGY_MAX_ORDERS_PER_RUN)) ?> orders in one run.</li>
										<li>A rebalance that would turn over more than
											<?= e(fmt_pct(STRATEGY_MAX_TURNOVER * 100, 0)) ?> of the account is refused.</li>
										<?php if ($card['broker'] !== null): ?>
										<li>At most <?= s_money(T212_MAX_RUN_VALUE, 0) ?> sent to the broker in one run.</li>
										<?php endif ?>
										<li>The kill switch file stops every strategy, including this one.</li>
									</ul>

									<h3 class="h6">This account</h3>
									<ul class="mb-0">
										<li>Started <?= $card['start'] === null
											? 'not yet' : s_date($card['start']) ?>.</li>
										<li>Mode <?= e(strategy_mode_name($card['mode'])) ?>.</li>
										<?php if ($card['note'] !== null && trim((string) $card['note']) !== ''): ?>
										<li>Note: <?= e($card['note']) ?></li>
										<?php endif ?>
									</ul>
								</div>
							</div>
						</div>

					</div><!-- /tab-content -->
				</div>
			</div>

		<?php endif ?>

		<?php /* ============================================================
		         Every backtest run, across every strategy.
		         ============================================================ */ ?>
		<?php if ($view === '_backtests'): ?>
			<h2 class="h5">Backtests</h2>
			<p class="text-body-secondary">
				Every replay, whichever strategy it was of, so two runs of the same
				rules under different costs can be read against each other. Each
				strategy's own Backtest tab shows only its own.
			</p>
			<?php $runs = $backtests; $show_strategy_column = true; ?>
			<?php include __DIR__ . '/strategy_backtests.php' ?>
		<?php endif ?>

		<?php /* ============================================================
		         What all of this means.
		         ============================================================ */ ?>
		<?php if ($view === '_how'): ?>
			<h2 class="h5">How this works</h2>
			<div class="card">
				<div class="card-body">
					<p>
						Each strategy holds equally weighted positions chosen by its own
						rules, and the nightly job decides on tonight's close.
					</p>
					<p>
						A <strong>paper</strong> strategy fills at the next day's close
						after dealing costs - commission, stamp duty and an allowance for
						the spread - and never leaves this database. The figures are
						after costs, not before them.
					</p>
					<p>
						A <strong>practice</strong> strategy sends its orders to a Trading
						212 practice account during the London session, and fills at
						whatever the market gives it. The money there is not real either.
						<strong>No real money is ever at risk</strong>: nothing in this
						application can place an order against a live account, and the
						part of it that sends orders refuses to run unless it is pointed
						at the practice account.
					</p>
					<p>
						The difference between a paper strategy and a practice one running
						the same rules is the point of having both: it is the evidence for
						or against the paper cost model, and the Overview compares them
						share by share where they bought the same thing on the same day.
					</p>
					<p>
						The benchmark is the FTSE 350 equal weighted, built from the
						constituents' own prices and rebased to 100 - there is no index
						level in the data. It is today's constituents, so like any
						backtest it leaves out the companies that have left the index.
					</p>
					<p>
						A <strong>backtest</strong> replays the same rules over stored
						history through the same engine. It is not a record of trading,
						and it is flattered by survivorship for the same reason the
						benchmark is.
					</p>
					<p class="mb-0">
						Valued at <?= s_date($as_of) ?>. Not financial advice.
					</p>
				</div>
			</div>
		<?php endif ?>

		</div><!-- /the view -->
	</div><!-- /row -->

	<?php /* One popup, reused by every button: the content is replaced each
	         time. Bootstrap's bundle is already loaded by header.php. */ ?>
	<div class="modal fade" id="dry-run-modal" tabindex="-1" aria-hidden="true"
	     aria-labelledby="dry-run-title">
		<div class="modal-dialog modal-xl modal-dialog-scrollable">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="dry-run-title">Dry run</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"
					        aria-label="Close"></button>
				</div>
				<div class="modal-body" id="dry-run-body">
					<div class="d-flex align-items-center">
						<span class="spinner-border spinner-border-sm me-2" role="status"
						      aria-hidden="true"></span>
						<span>Working out what the next run would do&hellip;</span>
					</div>
				</div>
				<div class="modal-footer">
					<small class="text-muted me-auto" id="dry-run-footnote"></small>
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>

	<script src="<?= asset_url('/js/strategy_dry_run.js') ?>"></script>
