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
?>

	<h1><?= e($title) ?></h1>

	<?php /* Two lines, and the rest folded away. The paragraph that was here
	         ran to eight, and the thing a reader has to know - that none of
	         this is real - was in the middle of it. */ ?>
	<p class="sm-sub mb-1">
		Strategies run by the nightly job. A <strong>PAPER</strong> one is filled
		at the next day's close after dealing costs and never leaves this
		database; a <strong>T212_DEMO</strong> one sends its orders to a Trading
		212 practice account during the session.
		<strong>No real money is ever at risk</strong>: nothing here can place an
		order against the live account.
	</p>
	<?php /* A <details>, not a Bootstrap collapse: the browser does the work,
	         so it opens with JavaScript off. */ ?>
	<details class="sm-reveal mb-3">
		<summary>How this works</summary>
		<div class="card card-body py-2 sm-reveal-body">
			<small class="text-muted mb-0">
				Each strategy starts with a notional GBP100,000 and holds equally
				weighted positions chosen by its own rules. The nightly job decides
				on tonight's close and the order fills at the next close, after
				commission, stamp duty and an allowance for the spread - so the
				figures are after costs, not before them.
				Valued at <?= s_date($as_of) ?>.
				The benchmark is the FTSE 350 equal weighted, built from the
				constituents' own prices and rebased to 100 - there is no index
				level in the data. It is today's constituents, so like any
				backtest it leaves out the companies that have left the index.
				Not financial advice.
			</small>
		</div>
	</details>

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

	<!-- ------------------------------------------------------------------ -->
	<!-- One card per strategy.                                             -->
	<!-- ------------------------------------------------------------------ -->
	<?php foreach ($cards as $card): ?>
	<div class="card mb-4">
		<div class="card-body">

			<?php /* The name a reader should see. "trend_following" is a
			         column name, and it was the heading of the card. */ ?>
			<h2 class="h5 card-title">
				<?= e(strategy_display_name($card['strategy'])) ?>
				<small class="text-muted"><?= e($card['strategy']) ?></small>
				<?php if ($card['enabled'] !== 'Y'): ?>
					<?= status_badge_html('off') ?>
				<?php endif ?>
				<?php /* The mode, beside the name, because what an order on
				         this card does depends on it: a PAPER order moves
				         nothing but this database, and a T212_DEMO one goes
				         to a practice account at Trading 212. Neither can
				         touch real money. */ ?>
				<?= status_badge_html($card['broker'] === null ? 'paper' : 'due',
				                      $card['mode']) ?>
				<?php if ($card['halted'] === 'Y'): ?>
					<?= status_badge_html('halted', 'halted') ?>
				<?php endif ?>
			</h2>

			<?php if ($card['halted'] === 'Y'): ?>
			<div class="alert alert-warning py-2">
				<strong>Stopped.</strong>
				<?= e((string) $card['halt_reason']) ?>
				This strategy places no orders until somebody has looked at it and
				cleared the halt.
			</div>
			<?php endif ?>

			<?php /* The rules as sentences. The page printed the values as
			         stored - "below_ma200_2d", "6mnth" - which are an exit
			         code and an indicator name: a reader cannot act on
			         either, and one who could would not need the page. */ ?>
			<p class="text-muted mb-3">
				<?php if ($card['screen'] !== null): ?>
					Holds the <strong><?= e($card['screen']) ?></strong> screen,
				<?php elseif ($card['rules'] !== null && $card['rules']['score_indicator'] !== null): ?>
					Holds the best by <strong><?= e($card['rules']['score_indicator']) ?></strong>,
				<?php endif ?>
				<?php if ($card['rules'] !== null): ?>
					<?= e(fmt_quantity($card['rules']['holdings'])) ?> equally weighted positions.
					<?= e(strategy_rebalance_words($card['rules']['rebalance'])) ?>.
					<?php $rank = strategy_rank_words($card['rules']['rank_by']); ?>
					<?php if ($rank !== null): ?><?= e($rank) ?>.<?php endif ?>
					<?php /* The buffer, when there is one. Without it the card
					         says nothing about why a holding that slipped two
					         places is still there. */ ?>
					<?php if (!empty($card['rules']['hold_rank'])): ?>
						Held until it falls out of the top
						<?= e(fmt_quantity($card['rules']['hold_rank'])) ?>.
					<?php endif ?>
					<?php foreach ($card['rules']['exit_list'] as $exit): ?>
						<?= e(strategy_exit_words($exit)) ?>.
					<?php endforeach ?>
					<?php if (!count($card['rules']['exit_list'])): ?>
						No exits between rebalances.
					<?php endif ?>
				<?php endif ?>
				<?php /* The free-text note used to be printed here as a
				         subtitle. It said "Above the 200 day average, weekly"
				         on a strategy that rebalances monthly: it is typed by
				         hand and the generated sentences above it are read
				         from the rules, so the two drift apart and the hand
				         typed one wins the reader. It belongs with the rules,
				         not over them. */ ?>
			</p>

			<?php /* "Not started" only when there is genuinely nothing: no
			         valuation history and nothing held. An account that
			         bought this morning has no night behind it yet and is
			         very much started. */ ?>
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
					<?php /* A drawdown is stored as a positive fraction and is a
					         fall, so it is printed negative. "8.5%" in the column
					         headed "Worst fall" is the right number pointing the
					         wrong way. */ ?>
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

			<p class="text-muted">
				<small>
					Last rebalance <?= s_date($card['last_rebalance']) ?>.
					Next on or after <?= s_date($card['next_rebalance'] === null
						? $card['starts_on'] : $card['next_rebalance']) ?>.
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

			<?php /* The dry run opens over the page rather than reloading it, and
			         the plan arrives as data rather than as the engine's own
			         printed lines - a table of trades with reasons answers "does
			         tonight look right", where a <pre> block of text did not.
			         templates/strategy_dry_run.js does the asking. */ ?>
			<div class="mb-3">
				<button type="button" class="btn btn-sm btn-outline-primary"
				        data-dry-run="<?= htmlspecialchars($card['strategy']) ?>">
					Dry run tonight
				</button>
				<small class="text-muted ms-2">Shows what the next run would do. Writes nothing.</small>
			</div>

			<!-- Holdings -->
			<h6>Holdings</h6>
			<?php if (!count($card['holdings_rows'])): ?>
				<p class="text-muted">Nothing held.
					<?= $card['enabled'] === 'Y' && !count($card['pending'])
					    ? 'The next rebalance will build the book.' : '' ?></p>
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
							<td class="sm-num"><?= s_shares($holding['quantity'], $card) ?></td>
							<td class="sm-num"><?= e(fmt_pounds($holding['avg_price'])) ?></td>
							<td class="sm-num"><?= e(fmt_pounds($holding['price'])) ?></td>
							<td class="sm-num"><?= s_money($holding['value'], 0) ?></td>
							<td class="sm-num"><?= $holding['weight'] === null
								? VIEW_DASH : e(fmt_pct($holding['weight'] * 100)) ?></td>
							<td class="text-end<?= s_colour($holding['gain']) ?>">
								<?= s_money_signed($holding['gain'], 0) ?>
								<?= $holding['gain_percent'] === null ? '' : '(' . s_percent($holding['gain_percent']) . ')' ?>
							</td>
							<td class="text-end"><?= $holding['days_held'] === null ? '&ndash;' : (int) $holding['days_held'] ?></td>
						</tr>
					<?php endforeach ?>
					</tbody>
				</table>
			</div>
			<?php endif ?>

			<?php /* The orders that have not finished. A card with an empty
			         book and fifteen orders planned for tonight said only
			         "nothing held", which told a reader the strategy had
			         done nothing when it had decided everything. */ ?>
			<?php if (count($card['pending'])): ?>
			<?php /* Counted by what each one is waiting for, not by the first
			         one: a run that sent nine of fifteen has two different
			         waits in the same list, and one sentence over both of
			         them would be wrong about six orders. */
			   $waits = [];
			   foreach ($card['pending'] as $order) {
			       $wait = strategy_order_wait($order['status'], $order['broker']);
			       $waits[$wait] = ($waits[$wait] ?? 0) + 1;
			   } ?>
			<p class="mb-1">
				<?php $first_wait = true; foreach ($waits as $wait => $count): ?>
					<?= $first_wait ? '' : ' ' ?>
					<strong><?= e(fmt_quantity($count)) ?></strong>
					order<?= $count === 1 ? ' is' : 's are' ?>
					<?= e(strtolower($wait)) ?>.
					<?php $first_wait = false; ?>
				<?php endforeach ?>
			</p>
			<div class="table-responsive mb-3">
				<table class="table table-sm table-striped">
					<thead>
						<tr>
							<th>Side</th><th>Share</th><th></th>
							<th class="text-end">Quantity</th>
							<th>Waiting for</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($card['pending'] as $order): ?>
						<tr>
							<td><?= htmlspecialchars((string) $order['side']) ?></td>
							<td><a href="quote.php?symbol=<?= urlencode($order['symbol']) ?>"><?= htmlspecialchars($order['symbol']) ?></a></td>
							<td><?= htmlspecialchars((string) $order['name']) ?></td>
							<td class="sm-num"><?= s_shares($order['quantity'], $card) ?></td>
							<td><?= e(strategy_order_wait($order['status'], $order['broker'])) ?>
								<?php if (!empty($order['broker'])): ?>
									<?= status_badge_html('due', 'DEMO') ?>
								<?php endif ?>
							</td>
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
							<th>Settings</th>
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
							<?php /* Four runs of the same strategy with four different
							         answers and one label between them is not a comparison, so
							         each row says what it was run with. */ ?>
							<td><small><?= array_key_exists('overrides', $run)
							                 && trim((string) $run['overrides']) !== ''
								? htmlspecialchars((string) $run['overrides'])
								: '<span class="text-muted">as stored</span>' ?></small></td>
							<td class="text-end"><?= (int) $run['trading_days'] ?></td>
							<td class="text-end"><?= (int) $run['rebalances'] ?></td>
							<td class="text-end"><?= (int) $run['exits'] ?></td>
							<td class="text-end<?= s_colour($run['total_return']) ?>"><?= s_percent($run['total_return']) ?></td>
							<td class="text-end<?= s_colour($run['benchmark_return']) ?>"><?= s_percent($run['benchmark_return']) ?></td>
							<td class="sm-num"><?= $run['max_drawdown'] === null
								? VIEW_DASH : move_pct_html(-(float) $run['max_drawdown'] * 100) ?></td>
							<?php /* The costs set against the money that went in: GBP53,553
							         means nothing until it is read against GBP100,000. The start
							         is the account's starting cash, not the first night's value,
							         which is already down by the first build's costs. */
							   $bt_start = (array_key_exists('starting_cash', $run)
							                && $run['starting_cash'] !== null)
							             ? (float) $run['starting_cash'] : (float) $run['start_value']; ?>
							<td class="text-end">
								<?= s_money($run['costs_paid'], 2) ?>
								<?php if ($bt_start > 0 && $run['costs_paid'] !== null): ?>
								<br><small class="text-muted"><?= number_format(
									(float) $run['costs_paid'] / $bt_start * 100, 1) ?>% of the start</small>
								<?php endif ?>
							</td>
						</tr>
						<?php if ($run['note'] !== null || !empty($run['turnover_refusals'])): ?>
						<tr>
							<td colspan="10" class="text-muted"><small>
								<?= htmlspecialchars((string) $run['note']) ?>
								<?php if (!empty($run['turnover_refusals'])): ?>
									<?= (int) $run['turnover_refusals'] ?>
									rebalance<?= (int) $run['turnover_refusals'] === 1 ? '' : 's' ?>
									refused by the turnover limit.
								<?php endif ?>
							</small></td>
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
								<td style="white-space: nowrap"><?= s_date($trade['fill_date'] === null ? $trade['as_of_date'] : $trade['fill_date']) ?></td>
								<td><?= htmlspecialchars((string) $trade['side']) ?></td>
								<td><a href="quote.php?symbol=<?= urlencode($trade['symbol']) ?>"><?= htmlspecialchars($trade['symbol']) ?></a></td>
								<td class="sm-num"><?= s_shares($trade['quantity'], $card) ?></td>
								<td class="sm-num"><?= e(fmt_pounds($trade['fill_price'])) ?></td>
								<td class="text-end"><?= $trade['status'] === 'FILLED'
									? s_money((float) $trade['commission'] + (float) $trade['stamp_duty'] + (float) $trade['slippage'], 2)
									: '&ndash;' ?></td>
								<td><?= htmlspecialchars((string) $trade['note']) ?></td>
								<td>
									<?= htmlspecialchars((string) $trade['status']) ?>
									<?php /* DEMO on every order that went to Trading 212,
									         so a line that left this database can never be
									         mistaken for one that did not. */ ?>
									<?php if (!empty($trade['broker'])): ?>
										<?= status_badge_html('due', 'DEMO') ?>
										<?php if ($trade['broker_order_id'] !== null): ?>
										<small class="text-muted"><?= e((string) $trade['broker_order_id']) ?></small>
										<?php endif ?>
									<?php endif ?>
								</td>
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
				$chart[] = ["name" => strategy_display_name($card['strategy']), "points" => $points];
			}
		}
		$benchmark_points = strategy_rebased($benchmark);
		if (count($benchmark_points) > 1) {
			$chart[] = ["name" => "FTSE 350 (equal weighted)", "points" => $benchmark_points];
		}
	?>
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
						<span>Working out what tonight would do&hellip;</span>
					</div>
				</div>
				<div class="modal-footer">
					<small class="text-muted me-auto" id="dry-run-footnote"></small>
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>

	<script src="/js/strategy_dry_run.js"></script>

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

	<?php /* The switched-off ones, folded away. A card that will never trade
	         again looks exactly like one that trades tomorrow. */ ?>
	<?php if ($disabled_count > 0 || $show_disabled): ?>
	<p>
		<a href="strategies.php<?= $show_disabled ? '' : '?disabled=1' ?>">
			<?= $show_disabled
			    ? 'Hide switched-off strategies'
			    : 'Show disabled (' . e(fmt_quantity($disabled_count)) . ')' ?>
		</a>
	</p>
	<?php endif ?>
