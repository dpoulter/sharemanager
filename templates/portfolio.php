<?php
/**
 * The portfolio.
 *
 * It was four unlabelled numbers in a <pre> block ("Cash: 0.00
 * Shares:0.00"), a stray "6m" where the timespan variable had been printed
 * by accident, an empty box saying "No performance history available", the
 * Timespan / Type / Go form, two tab links run together as "Active
 * SharesSold Shares", and a table header with nothing under it.
 *
 * Top to bottom now: the summary, the chart against the benchmark, and the
 * holdings with what each one is doing. It has to read properly with no
 * holdings at all and with thirty, so the empty states are written out
 * rather than left to be an empty table.
 */
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
	<div>
		<h1>Portfolio</h1>
		<p class="sm-sub">
			<?php if ($summary['as_of'] !== null): ?>
				Valued at the <?= e(fmt_date($summary['as_of'])) ?> close.
			<?php else: ?>
				No closes have been loaded yet.
			<?php endif ?>
			<?= score_key_html() ?>
		</p>
	</div>
	<?php /* The actions. Buy, Sell and Deposit post to the pages that already
	         handle them, from a modal here rather than from a page of their
	         own: adding a purchase meant leaving the portfolio to find a
	         form and then finding your way back. Dividends keep their own
	         page as well, because that page also edits and deletes them. */ ?>
	<div class="btn-group mb-3">
		<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#buyModal">Buy</button>
		<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#sellModal"
		        <?= count($holdings) ? '' : 'disabled title="Nothing to sell"' ?>>Sell</button>
		<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#dividendModal">Add dividend</button>
		<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#cashModal">Deposit cash</button>
	</div>
</div>

<?php /* ---------------- the summary ---------------- */ ?>
<div class="card">
	<div class="card-body">
		<div class="sm-strip">
			<div class="sm-strip-item">
				<div class="sm-strip-label">Total value</div>
				<div class="sm-strip-value"><?= e(fmt_pounds($summary['total'])) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Holdings</div>
				<div class="sm-strip-value"><?= e(fmt_pounds($summary['value'])) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Cash</div>
				<div class="sm-strip-value"><?= e(fmt_pounds($summary['cash'])) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Invested</div>
				<div class="sm-strip-value"><?= e(fmt_pounds($summary['invested'])) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Total gain</div>
				<div class="sm-strip-value">
					<?= move_pounds_html($summary['gain']) ?>
					<?php if ($summary['gain_pct'] !== null): ?>
						<span class="sm-strip-label">(<?= move_pct_html($summary['gain_pct']) ?>)</span>
					<?php endif ?>
				</div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Today</div>
				<div class="sm-strip-value">
					<?php if ($summary['day_change'] === null): ?>
						<?= view_missing_html("two closes are needed to work out a day's change and only one is stored") ?>
					<?php else: ?>
						<?= move_pounds_html($summary['day_change']) ?>
						<span class="sm-strip-label">(<?= move_pct_html($summary['day_change_pct']) ?>)</span>
					<?php endif ?>
				</div>
			</div>
			<div class="sm-strip-item">
				<?php /* The UK tax year, 6 April to 5 April. A calendar year
				         figure matches nothing on a tax return. */ ?>
				<div class="sm-strip-label">Dividends since <?= e(fmt_date_short($summary['tax_year'])) ?></div>
				<div class="sm-strip-value"><?= e(fmt_pounds($summary['dividends_tax_year'])) ?></div>
			</div>
		</div>
	</div>
</div>

<?php /* ---------------- the chart ---------------- */ ?>
<div class="card">
	<div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
		<span>Performance</span>
		<div class="btn-group btn-group-sm" role="group" aria-label="Timespan">
			<button type="button" class="btn btn-outline-secondary" data-portfolio-span="30">1M</button>
			<button type="button" class="btn btn-outline-secondary" data-portfolio-span="91">3M</button>
			<button type="button" class="btn btn-outline-secondary" data-portfolio-span="182">6M</button>
			<button type="button" class="btn btn-outline-secondary active" data-portfolio-span="365">1Y</button>
			<button type="button" class="btn btn-outline-secondary" data-portfolio-span="1825">5Y</button>
		</div>
	</div>
	<div class="card-body">
		<?php /* The box used to say "No performance history available" and
		         stay there, 300px of empty card. The script hides the chart
		         when there is nothing to draw and the line below says why. */ ?>
		<div id="portfolio-chart" class="sm-chart"></div>
		<small id="portfolio-chart-status" class="text-body-secondary d-block mt-1"></small>
		<script src="/js/lightweight-charts.standalone.production.js"></script>
		<script src="/js/portfolio_chart.js"></script>
	</div>
</div>

<?php /* ---------------- holdings, sold, dividends, cash ---------------- */ ?>
<?php /* Four tabs. They were two links with no space between them, which
         rendered as one word: "Active SharesSold Shares". */ ?>
<ul class="nav nav-tabs" id="portfolioTabs" role="tablist">
	<li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" role="tab" href="#holdings">Holdings
		<span class="text-muted">(<?= e(fmt_quantity(count($holdings))) ?>)</span></a></li>
	<li class="nav-item"><a class="nav-link" data-bs-toggle="tab" role="tab" href="#sold">Sold
		<span class="text-muted">(<?= e(fmt_quantity(count($sold))) ?>)</span></a></li>
	<li class="nav-item"><a class="nav-link" data-bs-toggle="tab" role="tab" href="#dividends">Dividends</a></li>
	<li class="nav-item"><a class="nav-link" data-bs-toggle="tab" role="tab" href="#cash">Cash</a></li>
</ul>

<div class="tab-content pt-3">

	<div class="tab-pane active" id="holdings" role="tabpanel">
	<?php if (!count($holdings)): ?>
		<div class="card">
			<div class="card-body text-center py-5">
				<p class="text-muted">Nothing held.</p>
				<button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#buyModal">
					Add a purchase
				</button>
			</div>
		</div>
	<?php else: ?>
		<div class="card">
			<div class="table-responsive">
				<table class="table table-sm table-hover sm-table-sort mb-0">
					<thead>
						<tr>
							<th data-sort="text">Share</th>
							<th data-sort="text">Company</th>
							<th class="sm-num" data-sort="number">Shares</th>
							<th class="sm-num" data-sort="number">Avg cost</th>
							<th class="sm-num" data-sort="number">Price</th>
							<th class="sm-num" data-sort="number">Value</th>
							<th class="sm-num" data-sort="number">Gain</th>
							<th class="sm-num" data-sort="number">Gain %</th>
							<th class="sm-num" data-sort="number">Weight</th>
							<th class="sm-num" data-sort="number">Today</th>
							<th class="sm-badges-tight">Scores</th>
							<th data-sort="text">Trend</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($holdings as $holding): ?>
						<tr>
							<td><a href="quote.php?symbol=<?= urlencode($holding['symbol']) ?>"><?= e($holding['symbol']) ?></a></td>
							<td><?= e($holding['name']) ?></td>
							<td class="sm-num" data-value="<?= e($holding['shares']) ?>"><?= e(fmt_quantity($holding['shares'])) ?></td>
							<td class="sm-num" data-value="<?= e($holding['avg_cost']) ?>"><?= e(fmt_pence($holding['avg_cost'], 2)) ?></td>
							<td class="sm-num" data-value="<?= e($holding['price']) ?>"><?= e(fmt_pence($holding['price'], 2)) ?></td>
							<td class="sm-num" data-value="<?= e($holding['value']) ?>"><?= e(fmt_pounds($holding['value'])) ?></td>
							<td class="sm-num" data-value="<?= e($holding['gain']) ?>"><?= move_pounds_html($holding['gain']) ?></td>
							<td class="sm-num" data-value="<?= e($holding['gain_pct']) ?>"><?= move_pct_html($holding['gain_pct']) ?></td>
							<td class="sm-num" data-value="<?= e($holding['weight']) ?>"><?= e(fmt_pct($holding['weight'])) ?></td>
							<td class="sm-num" data-value="<?= e($holding['today_pct']) ?>"><?= move_pct_html($holding['today_pct']) ?></td>
							<td class="sm-badges-tight" style="white-space: nowrap">
								<?= score_badge_html($holding['momentum'], 'Momentum') ?>
								<?= score_badge_html($holding['value_score'], 'Value') ?>
								<?= score_badge_html($holding['quality'], 'Quality') ?>
							</td>
							<?php /* The sell rule is a close below the 200 day
							         average, so this says three things rather than
							         two: a holding 1% above the average may be sold
							         this week and the table should say so. */ ?>
							<td style="white-space: nowrap">
								<?php if ($holding['trend'] === 'above'): ?>
									<span class="sm-up" data-bs-toggle="tooltip"
									      title="<?= e('Above the 200 day average of ' . fmt_pence($holding['ma200'])) ?>">&#9650; above</span>
								<?php elseif ($holding['trend'] === 'near'): ?>
									<span class="sm-badge sm-status-due" data-bs-toggle="tooltip"
									      title="<?= e('Within 2% of the 200 day average of ' . fmt_pence($holding['ma200'])
									                   . ', which is the sell rule') ?>">near the 200 day</span>
								<?php elseif ($holding['trend'] === 'below'): ?>
									<span class="sm-down" data-bs-toggle="tooltip"
									      title="<?= e('Below the 200 day average of ' . fmt_pence($holding['ma200'])) ?>">&#9660; below</span>
								<?php else: ?>
									<?= view_missing_html("no 200 day average has been calculated for this share") ?>
								<?php endif ?>
							</td>
						</tr>
					<?php endforeach ?>
						<tr class="sm-total-row">
							<td colspan="5">Total</td>
							<td class="sm-num"><?= e(fmt_pounds($summary['value'])) ?></td>
							<td class="sm-num"><?= move_pounds_html($summary['gain']) ?></td>
							<td class="sm-num"><?= move_pct_html($summary['gain_pct']) ?></td>
							<td class="sm-num"><?= e(fmt_pct(100)) ?></td>
							<td class="sm-num"><?= move_pct_html($summary['day_change_pct']) ?></td>
							<td colspan="2"></td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif ?>
	</div>

	<div class="tab-pane" id="sold" role="tabpanel">
		<div class="card">
			<div class="table-responsive">
				<table class="table table-sm sm-table-sort mb-0">
					<thead>
						<tr>
							<th data-sort="text">Share</th>
							<th data-sort="text">Company</th>
							<th class="sm-num" data-sort="number">Shares sold</th>
							<th class="sm-num" data-sort="number">Avg cost</th>
							<th class="sm-num" data-sort="number">Sold at</th>
							<th class="sm-num" data-sort="number">Commission</th>
							<th class="sm-num" data-sort="number">Dividends</th>
							<th class="sm-num" data-sort="number">Gain</th>
							<th class="sm-num" data-sort="number">Gain %</th>
						</tr>
					</thead>
					<tbody>
					<?php if (!count($sold)): ?>
						<tr><td colspan="9" class="text-muted">Nothing has been sold.</td></tr>
					<?php endif ?>
					<?php foreach ($sold as $position): ?>
						<tr>
							<td><a href="quote.php?symbol=<?= urlencode($position['symbol']) ?>"><?= e($position['symbol']) ?></a></td>
							<td><?= e($position['name']) ?></td>
							<td class="sm-num" data-value="<?= e($position['shares']) ?>"><?= e(fmt_quantity($position['shares'])) ?></td>
							<td class="sm-num" data-value="<?= e($position['avg_cost']) ?>"><?= e(fmt_pence($position['avg_cost'], 2)) ?></td>
							<td class="sm-num" data-value="<?= e($position['price_sold']) ?>"><?= e(fmt_pence($position['price_sold'], 2)) ?></td>
							<td class="sm-num" data-value="<?= e($position['commission']) ?>"><?= e(fmt_pounds($position['commission'])) ?></td>
							<td class="sm-num" data-value="<?= e($position['dividends']) ?>"><?= e(fmt_pounds($position['dividends'])) ?></td>
							<td class="sm-num" data-value="<?= e($position['gain']) ?>"><?= move_pounds_html($position['gain']) ?></td>
							<td class="sm-num" data-value="<?= e($position['gain_pct']) ?>"><?= move_pct_html($position['gain_pct']) ?></td>
						</tr>
					<?php endforeach ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="tab-pane" id="dividends" role="tabpanel">
		<div class="card">
			<div class="card-header d-flex justify-content-between align-items-center">
				<span>Dividends received</span>
				<a class="small" href="dividends.php">Edit dividends</a>
			</div>
			<div class="table-responsive">
				<table class="table table-sm sm-table-sort mb-0">
					<thead>
						<tr>
							<th data-sort="text">Date</th>
							<th data-sort="text">Share</th>
							<th data-sort="text">Company</th>
							<th class="sm-num" data-sort="number">Amount</th>
						</tr>
					</thead>
					<tbody>
					<?php if (!count($dividends)): ?>
						<tr><td colspan="4" class="text-muted">No dividends recorded.</td></tr>
					<?php endif ?>
					<?php foreach ($dividends as $dividend): ?>
						<tr>
							<td data-value="<?= e($dividend['dividend_date']) ?>"><?= e(fmt_date($dividend['dividend_date'])) ?></td>
							<td><a href="quote.php?symbol=<?= urlencode($dividend['symbol']) ?>"><?= e($dividend['symbol']) ?></a></td>
							<td><?= e($dividend['name']) ?></td>
							<td class="sm-num" data-value="<?= e($dividend['amount']) ?>"><?= e(fmt_pounds($dividend['amount'])) ?></td>
						</tr>
					<?php endforeach ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="tab-pane" id="cash" role="tabpanel">
		<div class="card">
			<div class="card-header d-flex justify-content-between align-items-center">
				<span>Cash movements</span>
				<a class="small" href="cash_history.php">The whole cash history</a>
			</div>
			<div class="table-responsive">
				<table class="table table-sm sm-table-sort mb-0">
					<thead>
						<tr>
							<th data-sort="text">Date</th>
							<th data-sort="text">Type</th>
							<th class="sm-num" data-sort="number">Amount</th>
						</tr>
					</thead>
					<tbody>
					<?php if (!count($cash)): ?>
						<tr><td colspan="3" class="text-muted">No cash movements recorded.</td></tr>
					<?php endif ?>
					<?php foreach ($cash as $movement): ?>
						<tr>
							<td data-value="<?= e($movement['date']) ?>"><?= e(fmt_date($movement['date'])) ?></td>
							<td><?= e($movement['type']) ?></td>
							<td class="sm-num" data-value="<?= e($movement['amount']) ?>"><?= e(fmt_pounds($movement['amount'])) ?></td>
						</tr>
					<?php endforeach ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>

<?php /* ---------------- the action modals ----------------

   Each posts to the page that already handles it. Nothing here duplicates
   the validation: buy.php, sell.php, topup.php and dividends.php check
   what they are given and apologise for what they cannot use, and a second
   copy of those rules in a template is a second copy to drift.          */ ?>

<div class="modal fade" id="buyModal" tabindex="-1" aria-labelledby="buyModalLabel" aria-hidden="true">
	<div class="modal-dialog">
		<form class="modal-content" action="buy.php" method="post">
			<div class="modal-header">
				<h2 class="modal-title h5" id="buyModalLabel">Buy</h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div class="mb-2">
					<label class="form-label" for="buy-symbol">Share</label>
					<input class="form-control" id="buy-symbol" name="symbol" placeholder="Symbol" type="text" required>
				</div>
				<div class="mb-2">
					<label class="form-label" for="buy-qty">Shares</label>
					<input class="form-control" id="buy-qty" name="qty" inputmode="numeric" pattern="\d+" required>
				</div>
				<div class="mb-2">
					<label class="form-label" for="buy-date">Date</label>
					<input class="form-control" id="buy-date" name="buyDate" type="date" required>
				</div>
				<?php /* Dealing costs. Optional: nought is an honest default and
				         the figure shows on the Transactions page. */ ?>
				<div class="mb-2">
					<label class="form-label" for="buy-commission">Commission &pound;</label>
					<input class="form-control" id="buy-commission" name="commission"
					       inputmode="decimal" value="0">
				</div>
				<p class="text-muted small mb-0">
					Bought at the latest price for that share. The cost comes out of
					the cash book.
				</p>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
				<button type="submit" class="btn btn-primary">Buy</button>
			</div>
		</form>
	</div>
</div>

<div class="modal fade" id="sellModal" tabindex="-1" aria-labelledby="sellModalLabel" aria-hidden="true">
	<div class="modal-dialog">
		<form class="modal-content" action="sell.php" method="post">
			<div class="modal-header">
				<h2 class="modal-title h5" id="sellModalLabel">Sell</h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div class="mb-2">
					<label class="form-label" for="sell-symbol">Share</label>
					<select class="form-select" id="sell-symbol" name="symbol" required>
					<?php foreach ($holdings as $holding): ?>
						<option value="<?= e($holding['symbol']) ?>">
							<?= e($holding['symbol']) ?> &ndash; <?= e(fmt_quantity($holding['shares'])) ?> held
						</option>
					<?php endforeach ?>
					</select>
				</div>
				<div class="mb-2">
					<label class="form-label" for="sell-qty">Shares</label>
					<input class="form-control" id="sell-qty" name="qty" inputmode="numeric" pattern="\d+" required>
				</div>
				<div class="mb-2">
					<label class="form-label" for="sell-date">Date</label>
					<input class="form-control" id="sell-date" name="sellDate" type="date"
					       value="<?= e(date('Y-m-d')) ?>">
				</div>
				<div class="mb-2">
					<label class="form-label" for="sell-commission">Commission &pound;</label>
					<input class="form-control" id="sell-commission" name="commission"
					       inputmode="decimal" value="0">
				</div>
				<p class="text-muted small mb-0">
					Sold at the latest price for that share. The proceeds go into the
					cash book.
				</p>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
				<button type="submit" class="btn btn-primary">Sell</button>
			</div>
		</form>
	</div>
</div>

<div class="modal fade" id="dividendModal" tabindex="-1" aria-labelledby="dividendModalLabel" aria-hidden="true">
	<div class="modal-dialog">
		<form class="modal-content" action="dividends.php" method="post">
			<div class="modal-header">
				<h2 class="modal-title h5" id="dividendModalLabel">Add a dividend</h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<?php /* dividends.php reads these as arrays - $symbol[0],
				         $amount[0] - because its own page edits several rows at
				         once. Posted as scalars, $symbol[0] is the first letter
				         of the ticker, and the dividend is filed against a
				         company called "A". */ ?>
				<div class="mb-2">
					<label class="form-label" for="dividend-symbol">Share</label>
					<input class="form-control" id="dividend-symbol" name="symbol[]" list="dividend-symbols" required>
					<datalist id="dividend-symbols">
					<?php foreach ($holdings as $holding): ?>
						<option value="<?= e($holding['symbol']) ?>"></option>
					<?php endforeach ?>
					</datalist>
				</div>
				<div class="mb-2">
					<label class="form-label" for="dividend-date">Date</label>
					<input class="form-control" id="dividend-date" name="dividend_date[]" type="date" required>
				</div>
				<div class="mb-2">
					<label class="form-label" for="dividend-amount">Amount &pound;</label>
					<input class="form-control" id="dividend-amount" name="amount[]" inputmode="decimal" required>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
				<button type="submit" class="btn btn-primary" name="btnSubmit" value="Y">Add</button>
			</div>
		</form>
	</div>
</div>

<div class="modal fade" id="cashModal" tabindex="-1" aria-labelledby="cashModalLabel" aria-hidden="true">
	<div class="modal-dialog">
		<form class="modal-content" action="topup.php" method="post">
			<div class="modal-header">
				<h2 class="modal-title h5" id="cashModalLabel">Deposit cash</h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div class="mb-2">
					<label class="form-label" for="cash-date">Date</label>
					<input class="form-control" id="cash-date" name="date" type="date" required>
				</div>
				<div class="mb-2">
					<label class="form-label" for="cash-amount">Amount &pound;</label>
					<input class="form-control" id="cash-amount" name="amount" inputmode="decimal" required>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
				<button type="submit" class="btn btn-primary">Deposit</button>
			</div>
		</form>
	</div>
</div>
