<?php
	/**
	 * The paper strategies.
	 *
	 * One card per strategy: what it is worth, what it has done against the
	 * market, what it holds and what it has traded. Then a chart of all of
	 * them and the benchmark on one axis.
	 *
	 * Everything that came out of the database goes out through
	 * htmlspecialchars(), including the strategy's own note and the reason
	 * on a trade: they are configuration somebody can edit, which makes them
	 * input.
	 *
	 * PAPER ONLY, and the page says so where somebody can see it. The one
	 * button here runs a dry run, which writes nothing.
	 */

	/* Money, percentages and dates, written the same way everywhere on the
	   page. Null is "not known yet" rather than zero: a strategy that has
	   not run has no return, and printing 0.00% would be a claim. */
	function s_money($value, $decimals = 0) {
		if ($value === null) { return '&ndash;'; }
		return '&pound;' . number_format((float) $value, $decimals);
	}
	function s_percent($value, $decimals = 1) {
		if ($value === null) { return '&ndash;'; }
		$text = number_format((float) $value * 100, $decimals) . '%';
		return ((float) $value > 0 ? '+' : '') . $text;
	}
	function s_colour($value) {
		if ($value === null) { return ''; }
		return (float) $value < 0 ? ' text-danger' : ((float) $value > 0 ? ' text-success' : '');
	}
	function s_date($value) {
		return $value === null ? '&ndash;' : htmlspecialchars((string) $value);
	}
?>
<div class="container-fluid">

	<h3><?= htmlspecialchars($title) ?></h3>

	<p class="text-muted">
		Paper strategies, run by the nightly job and filled against the
		following day's closing prices after dealing costs.
		<strong>No real orders are ever placed</strong> and nothing here is
		connected to a broker.
		<br>
		<small>
			Valued at <?= s_date($as_of) ?>.
			The benchmark is the FTSE 350 equal weighted, built from the
			constituents' own prices and rebased to 100 - there is no index
			level in the data. It is today's constituents, so like any
			backtest it leaves out the companies that have left the index.
			Not financial advice.
		</small>
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

	<?php if (!$ready): ?>
	<div class="alert alert-info">
		The strategy tables are not there yet. Apply
		<code>sql/strategy_rules.sql</code> and the nightly job will start
		filling this page in.
	</div>
	<?php endif ?>

	<?php if ($ready && !count($cards)): ?>
	<div class="alert alert-info">
		No strategy has any rules yet. See <code>sql/strategy_rules.sql</code>.
	</div>
	<?php endif ?>

	<?php if ($dry_run !== null): ?>
	<!-- What tonight's run would do. Nothing was written to produce this. -->
	<div class="card mb-3 border-primary">
		<div class="card-body">
			<h5 class="card-title">
				Dry run: <?= htmlspecialchars($dry_run['strategy']) ?>
				at <?= s_date($dry_run['as_of']) ?>
				<?= $dry_run['rebalance'] ? '<span class="badge bg-primary">rebalance</span>' : '' ?>
			</h5>
			<p class="text-muted mb-2"><small>Nothing was written: no targets, no orders, no cash moved.</small></p>
			<?php if (count($dry_run['errors'])): ?>
			<ul class="mb-2">
				<?php foreach ($dry_run['errors'] as $error): ?>
				<li class="text-danger"><?= htmlspecialchars((string) $error) ?></li>
				<?php endforeach ?>
			</ul>
			<?php endif ?>
			<?php if (count($dry_run['lines'])): ?>
			<pre class="mb-0"><?php foreach ($dry_run['lines'] as $line): ?><?= htmlspecialchars((string) $line) ?>
<?php endforeach ?></pre>
			<?php else: ?>
			<p class="mb-0">Nothing to do tonight.</p>
			<?php endif ?>
		</div>
	</div>
	<?php endif ?>

	<!-- ------------------------------------------------------------------ -->
	<!-- One card per strategy.                                             -->
	<!-- ------------------------------------------------------------------ -->
	<?php foreach ($cards as $card): ?>
	<div class="card mb-4">
		<div class="card-body">

			<h5 class="card-title">
				<?= htmlspecialchars($card['strategy']) ?>
				<?php if ($card['enabled'] !== 'Y'): ?>
					<span class="badge bg-secondary">disabled</span>
				<?php endif ?>
				<span class="badge bg-info text-dark"><?= htmlspecialchars((string) $card['mode']) ?></span>
			</h5>

			<p class="text-muted mb-3">
				<?php if ($card['screen'] !== null): ?>
					Holds the <strong><?= htmlspecialchars($card['screen']) ?></strong> screen,
				<?php elseif ($card['rules'] !== null && $card['rules']['score_indicator'] !== null): ?>
					Holds the best by <strong><?= htmlspecialchars($card['rules']['score_indicator']) ?></strong>,
				<?php endif ?>
				<?php if ($card['rules'] !== null): ?>
					<?= (int) $card['rules']['holdings'] ?> equally weighted positions,
					rebalanced <?= htmlspecialchars($card['rules']['rebalance']) ?><?php
					if ($card['rules']['rank_by'] !== null): ?>,
					ranked by <?= htmlspecialchars($card['rules']['rank_by']) ?><?php endif ?><?php
					if (count($card['rules']['exit_list'])): ?>,
					leaving on <?= htmlspecialchars(implode(', ', $card['rules']['exit_list'])) ?><?php endif ?>.
				<?php endif ?>
				<?php if ($card['note'] !== null): ?>
					<br><small><?= htmlspecialchars((string) $card['note']) ?></small>
				<?php endif ?>
			</p>

			<div class="row text-center mb-3">
				<div class="col">
					<div class="text-muted"><small>Value</small></div>
					<div class="h5 mb-0"><?= s_money($card['total'], 0) ?></div>
				</div>
				<div class="col">
					<div class="text-muted"><small>Return since <?= s_date($card['start']) ?></small></div>
					<div class="h5 mb-0<?= s_colour($card['return']) ?>"><?= s_percent($card['return']) ?></div>
				</div>
				<div class="col">
					<div class="text-muted"><small>Benchmark, same period</small></div>
					<div class="h5 mb-0<?= s_colour($card['benchmark_return']) ?>"><?= s_percent($card['benchmark_return']) ?></div>
				</div>
				<div class="col">
					<div class="text-muted"><small>Worst fall</small></div>
					<div class="h5 mb-0"><?= $card['drawdown'] === null ? '&ndash;' : '-' . number_format($card['drawdown'] * 100, 1) . '%' ?></div>
				</div>
				<div class="col">
					<div class="text-muted"><small>Cash</small></div>
					<div class="h5 mb-0"><?= s_money($card['cash'], 0) ?></div>
				</div>
				<div class="col">
					<div class="text-muted"><small>Holdings</small></div>
					<div class="h5 mb-0"><?= (int) $card['holdings'] ?></div>
				</div>
			</div>

			<p class="text-muted">
				<small>
					Last rebalance <?= s_date($card['last_rebalance']) ?>.
					Next on or after <?= s_date($card['next_rebalance']) ?>.
					Dealing costs paid to date
					<?= s_money($card['costs']['total'], 2) ?>
					over <?= (int) $card['costs']['fills'] ?> fills
					(commission <?= s_money($card['costs']['commission'], 2) ?>,
					stamp duty <?= s_money($card['costs']['stamp_duty'], 2) ?>,
					spread <?= s_money($card['costs']['slippage'], 2) ?>).
				</small>
			</p>

			<?php if ($card['stale'] !== null): ?>
			<div class="alert alert-warning py-2">
				<small><?= htmlspecialchars((string) $card['stale']) ?></small>
			</div>
			<?php endif ?>

			<!-- The dry run. A POST because it is an action, not a view. -->
			<form method="post" action="strategies.php" class="mb-3">
				<input type="hidden" name="dry_run" value="<?= htmlspecialchars($card['strategy']) ?>">
				<button type="submit" class="btn btn-sm btn-outline-primary">Dry run tonight</button>
				<small class="text-muted ms-2">Shows what the next run would do. Writes nothing.</small>
			</form>

			<!-- Holdings -->
			<h6>Holdings</h6>
			<?php if (!count($card['holdings_rows'])): ?>
				<p class="text-muted">Nothing held. <?= $card['enabled'] === 'Y' ? 'The next rebalance will build the book.' : '' ?></p>
			<?php else: ?>
			<div class="table-responsive mb-3">
				<table class="table table-sm table-striped">
					<thead>
						<tr>
							<th>Share</th><th></th>
							<th class="text-end">Quantity</th>
							<?php /* Pounds, both of them. The engine converts the stored pence
							         once, at strategy_price_asof(), so everything from the
							         sizing to this column is in the currency the costs are in. */ ?>
							<th class="text-end">Average cost (&pound;)</th>
							<th class="text-end">Last price (&pound;)</th>
							<th class="text-end">Value</th>
							<th class="text-end">Weight</th>
							<th class="text-end">Gain</th>
							<th class="text-end">Days held</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($card['holdings_rows'] as $holding): ?>
						<tr>
							<td><a href="quote.php?symbol=<?= urlencode($holding['symbol']) ?>"><?= htmlspecialchars($holding['symbol']) ?></a></td>
							<td><?= htmlspecialchars((string) $holding['name']) ?><?= $holding['stale'] ? ' <span class="badge bg-warning text-dark">not trading</span>' : '' ?></td>
							<td class="text-end"><?= number_format($holding['quantity']) ?></td>
							<td class="text-end"><?= number_format($holding['avg_price'], 2) ?></td>
							<td class="text-end"><?= $holding['price'] === null ? '&ndash;' : number_format($holding['price'], 2) ?></td>
							<td class="text-end"><?= s_money($holding['value'], 0) ?></td>
							<td class="text-end"><?= $holding['weight'] === null ? '&ndash;' : number_format($holding['weight'] * 100, 1) . '%' ?></td>
							<td class="text-end<?= s_colour($holding['gain']) ?>">
								<?= s_money($holding['gain'], 0) ?>
								<?= $holding['gain_percent'] === null ? '' : '(' . s_percent($holding['gain_percent']) . ')' ?>
							</td>
							<td class="text-end"><?= $holding['days_held'] === null ? '&ndash;' : (int) $holding['days_held'] ?></td>
						</tr>
					<?php endforeach ?>
					</tbody>
				</table>
			</div>
			<?php endif ?>

			<!-- Backtests of the same rules, clearly labelled as backtests -->
			<?php if (count($card['backtests'])): ?>
			<h6>Backtest</h6>
			<p class="text-muted mb-1">
				<small>
					The same rules replayed over stored history through the same
					engine. <strong>Not a record of trading.</strong>
					Flattered by survivorship: the universe is today's FTSE 350,
					so the companies that left the index are missing.
				</small>
			</p>
			<div class="table-responsive mb-3">
				<table class="table table-sm">
					<thead>
						<tr>
							<th>Period</th><th>Status</th>
							<th class="text-end">Days</th>
							<th class="text-end">Rebalances</th>
							<th class="text-end">Exits</th>
							<th class="text-end">Return</th>
							<th class="text-end">Benchmark</th>
							<th class="text-end">Worst fall</th>
							<th class="text-end">Costs</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($card['backtests'] as $run): ?>
						<tr>
							<td><?= s_date($run['from_date']) ?> to <?= s_date($run['to_date']) ?></td>
							<td>
								<span class="badge bg-secondary">backtest</span>
								<?= htmlspecialchars((string) $run['status']) ?>
							</td>
							<td class="text-end"><?= (int) $run['trading_days'] ?></td>
							<td class="text-end"><?= (int) $run['rebalances'] ?></td>
							<td class="text-end"><?= (int) $run['exits'] ?></td>
							<td class="text-end<?= s_colour($run['total_return']) ?>"><?= s_percent($run['total_return']) ?></td>
							<td class="text-end<?= s_colour($run['benchmark_return']) ?>"><?= s_percent($run['benchmark_return']) ?></td>
							<td class="text-end"><?= $run['max_drawdown'] === null ? '&ndash;' : '-' . number_format((float) $run['max_drawdown'] * 100, 1) . '%' ?></td>
							<td class="text-end"><?= s_money($run['costs_paid'], 2) ?></td>
						</tr>
						<?php if ($run['note'] !== null): ?>
						<tr>
							<td colspan="9" class="text-muted"><small><?= htmlspecialchars((string) $run['note']) ?></small></td>
						</tr>
						<?php endif ?>
					<?php endforeach ?>
					</tbody>
				</table>
			</div>
			<?php endif ?>

			<!-- Trades -->
			<h6>Trades</h6>
			<?php if (!count($card['trades'])): ?>
				<p class="text-muted">No trades yet.</p>
			<?php else: ?>
			<details>
				<summary><?= count($card['trades']) ?> most recent</summary>
				<div class="table-responsive mt-2">
					<table class="table table-sm">
						<thead>
							<tr>
								<th>Date</th><th>Side</th><th>Share</th>
								<th class="text-end">Quantity</th>
								<th class="text-end">Price (&pound;)</th>
								<th class="text-end">Costs</th>
								<th>Reason</th><th>Status</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($card['trades'] as $trade): ?>
							<tr>
								<td><?= s_date($trade['fill_date'] === null ? $trade['as_of_date'] : $trade['fill_date']) ?></td>
								<td><?= htmlspecialchars((string) $trade['side']) ?></td>
								<td><a href="quote.php?symbol=<?= urlencode($trade['symbol']) ?>"><?= htmlspecialchars($trade['symbol']) ?></a></td>
								<td class="text-end"><?= number_format((int) $trade['quantity']) ?></td>
								<td class="text-end"><?= $trade['fill_price'] === null ? '&ndash;' : number_format((float) $trade['fill_price'], 2) ?></td>
								<td class="text-end"><?= $trade['status'] === 'FILLED'
									? s_money((float) $trade['commission'] + (float) $trade['stamp_duty'] + (float) $trade['slippage'], 2)
									: '&ndash;' ?></td>
								<td><?= htmlspecialchars((string) $trade['note']) ?></td>
								<td><?= htmlspecialchars((string) $trade['status']) ?></td>
							</tr>
						<?php endforeach ?>
						</tbody>
					</table>
				</div>
			</details>
			<?php endif ?>

		</div>
	</div>
	<?php endforeach ?>

	<!-- ------------------------------------------------------------------ -->
	<!-- The chart, all strategies and the benchmark on one axis.           -->
	<!-- ------------------------------------------------------------------ -->
	<?php
		$chart = [];
		foreach ($cards as $card) {
			$points = strategy_rebased(strategy_series($card['strategy']));
			if (count($points) > 1) {
				$chart[] = ["name" => $card['strategy'], "points" => $points];
			}
		}
		$benchmark_points = strategy_rebased($benchmark);
		if (count($benchmark_points) > 1) {
			$chart[] = ["name" => "FTSE 350 (equal weighted)", "points" => $benchmark_points];
		}
	?>
	<div class="card mb-4">
		<div class="card-body">
			<h6 class="card-title">Value over time, each line rebased to 100 at its own start</h6>
			<?php if (!count($chart)): ?>
				<p class="text-muted">Two nights of history are needed before there is a line to draw.</p>
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
				<script src="/js/lightweight-charts.standalone.production.js"></script>
				<script src="/js/strategy_chart.js"></script>
			<?php endif ?>
		</div>
	</div>

</div>
