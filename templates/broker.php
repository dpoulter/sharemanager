<?php
	/**
	 * The broker connection.
	 *
	 * Everything that came out of the database or off the wire goes out
	 * through htmlspecialchars(): a ticker, a status and an instrument name
	 * are somebody else's text, which makes them input.
	 *
	 * READ ONLY, and the page says so at the top. The one button here syncs,
	 * which reads.
	 */
	function b_money($value, $dp = 2) { return e(fmt_pounds($value, $dp)); }
	function b_date($value)  { return e(fmt_date($value)); }
	function b_when($value) {
		return $value === null ? e(VIEW_DASH)
		     : e(date('j M Y H:i', strtotime((string) $value)));
	}
?>

	<h1><?= e($title) ?></h1>

	<p class="sm-sub mb-1">
		What Trading 212 says this account holds, read from its API.
		<strong>Nothing on this page can place an order.</strong>
		It does not change the Portfolio pages: those are still the
		transactions entered by hand.
	</p>

	<details class="sm-reveal mb-3">
		<summary>How this works</summary>
		<div class="card card-body py-2 sm-reveal-body">
			<small class="text-muted mb-0">
				The nightly job reads the account summary, the positions and the
				order and dividend history, and refreshes the instrument list
				once a week. Every figure below is the broker's, not this
				application's working.
				Prices are shown in pounds; which unit the broker sends them in
				is worked out by comparing its price for a share against this
				application's own close for the same share, because the API
				documentation does not say. A position whose unit could not be
				decided shows no price rather than one that might be a hundred
				times out.
				Holdings are matched to shares by ISIN where both sides have
				one and by ticker otherwise, and a ticker that fits more than
				one share is left unmatched rather than guessed at.
				A strategy in <strong>T212_DEMO</strong> mode sends its orders to
				the Trading 212 <strong>practice</strong> account between
				<?= e(T212_MARKET_OPEN) ?> and <?= e(T212_MARKET_SHUT) ?>
				(<?= e(T212_MARKET_TZ) ?>), in fractions of a share, from
				<?= e(fmt_pounds(T212_MIN_ORDER_VALUE, 0)) ?> an order.
				Nothing anywhere in this application can place an order against
				the live account: see includes/README-broker.md.
			</small>
		</div>
	</details>

	<?php if ($synced !== null): ?>
	<div class="alert <?= $synced['status'] === 'OK' ? 'alert-success' : 'alert-warning' ?>">
		<strong>Sync <?= e(strtolower((string) $synced['status'])) ?>.</strong>
		<?= e((string) $synced['message']) ?>
	</div>
	<?php endif ?>

	<!-- The connection itself: which environment, whether it has a key, and
	     when it last managed to read anything. -->
	<h5>Connection</h5>
	<div class="card card-body mb-3">
		<div class="sm-strip">
			<div class="sm-strip-item">
				<div class="sm-strip-label">Environment</div>
				<div>
					<?php if (!$status['configured']): ?>
						<?= status_badge_html('off', 'off') ?>
					<?php elseif ($status['live']): ?>
						<?= status_badge_html('paper', 'LIVE, READ ONLY') ?>
					<?php else: ?>
						<?= status_badge_html('paper', 'DEMO') ?>
					<?php endif ?>
					<div><small class="text-muted"><?= e((string) $status['environment']) ?></small></div>
				</div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Last sync</div>
				<div>
					<?php if ($status['run'] === null): ?>
						<?= e(VIEW_DASH) ?> <small class="text-muted">never</small>
					<?php else: ?>
						<?= b_when($status['run']['finished_at']) ?>
						<div><small class="text-muted"><?= e((string) $status['run']['status']) ?></small></div>
					<?php endif ?>
				</div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Instrument list</div>
				<div><?= $status['instruments_as_of'] === null
					? e(VIEW_DASH) : b_date($status['instruments_as_of']) ?></div>
			</div>
			<?php /* When orders go, for the strategies that send them. The
			         nightly job plans and this window is when the plan is
			         sent: a market order queued overnight fills at whatever
			         the open does, which is not the price anything was
			         decided at. */ ?>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Orders are sent</div>
				<div><?= e(T212_MARKET_OPEN) ?>&ndash;<?= e(T212_MARKET_SHUT) ?>
					<small class="text-muted"><?= e(T212_MARKET_TZ) ?></small></div>
			</div>
		</div>

		<?php if (!$status['ready']): ?>
		<p class="text-muted mb-0 mt-2"><small>
			sql/broker.sql has not been applied on this host, so there is nowhere
			to put what the broker says.
		</small></p>
		<?php elseif (!$status['configured']): ?>
		<p class="text-muted mb-0 mt-2"><small>
			<?= e((string) $status['why_not']) ?>
			Nothing is requested and nothing else on the site is affected.
		</small></p>
		<?php endif ?>

		<?php if ($status['forbidden']): ?>
		<div class="alert alert-warning mt-2 mb-0">
			<strong>The key is missing a permission.</strong>
			Trading 212 answered 403 to one of the calls, which is a setting on
			the key rather than something the next sync will fix: add the
			permission for that call to the key in Trading 212, or accept that
			this page cannot show what it covers.
		</div>
		<?php endif ?>

		<?php /* Two lists, because they are two different things. A problem
		         stopped something being right and wants doing something
		         about; a notice is the run telling you what it noticed. Six
		         instruments it could not tell apart used to be filed as a
		         problem, which made every run read as a failure. */ ?>
		<?php if ($status['run'] !== null && trim((string) $status['run']['problems']) !== ''): ?>
		<div class="mt-2">
			<div class="sm-strip-label">What the last sync could not do</div>
			<ul class="mb-0">
			<?php foreach (explode("\n", (string) $status['run']['problems']) as $problem): ?>
				<?php if (trim($problem) === '') { continue; } ?>
				<li><small><?= e($problem) ?></small></li>
			<?php endforeach ?>
			</ul>
		</div>
		<?php endif ?>

		<?php if ($status['run'] !== null && array_key_exists('notices', $status['run'])
		          && trim((string) $status['run']['notices']) !== ''): ?>
		<div class="mt-2">
			<div class="sm-strip-label">Worth knowing</div>
			<ul class="mb-0">
			<?php foreach (explode("\n", (string) $status['run']['notices']) as $notice): ?>
				<?php if (trim($notice) === '') { continue; } ?>
				<li class="text-muted"><small><?= e($notice) ?></small></li>
			<?php endforeach ?>
			</ul>
		</div>
		<?php endif ?>

		<?php if ($status['configured']): ?>
		<form method="post" action="broker.php" class="mt-3">
			<input type="hidden" name="sync" value="1">
			<div class="form-check mb-2">
				<input class="form-check-input" type="checkbox" value="1"
				       id="brokerInstruments" name="instruments">
				<label class="form-check-label" for="brokerInstruments">
					<small>Refresh the instrument list as well (one request, and the
					broker allows one every 50 seconds)</small>
				</label>
			</div>
			<button class="btn btn-primary" type="submit">Sync now</button>
		</form>
		<?php endif ?>
	</div>

	<!-- Cash, invested and total, as the broker has them. -->
	<h5>Account</h5>
	<?php if ($values === null): ?>
	<p class="text-muted">Nothing has been read yet.</p>
	<?php else: ?>
	<div class="card card-body mb-2">
		<div class="sm-strip">
			<div class="sm-strip-item">
				<div class="sm-strip-label">Cash</div>
				<div class="sm-strip-value"><?= b_money($values['cash']) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Invested</div>
				<div class="sm-strip-value"><?= b_money($values['invested']) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Total</div>
				<div class="sm-strip-value"><?= b_money($values['total']) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Gain, unrealised</div>
				<div class="sm-strip-value"><?= $values['unrealised'] === null
					? e(VIEW_DASH) : move_pounds_html($values['unrealised']) ?></div>
			</div>
		</div>
	</div>
	<?php /* The rest of what the summary carries. Second rank rather than
	         hidden: a reader wants the cash, the invested value and the
	         total first, and then to be able to find the money set aside
	         for an order that has not filled. */ ?>
	<p class="text-muted mb-3"><small>
		As at <?= b_date($values['as_of_date']) ?>, in
		<?= e((string) ($values['currency'] === null ? 'GBP' : $values['currency'])) ?>.
		Reserved for orders <?= b_money($values['reserved']) ?>,
		in pies <?= b_money($values['in_pies']) ?>,
		cost of what is held <?= b_money($values['invested_cost']) ?>,
		realised <?= b_money($values['realised']) ?>.
	</small></p>
	<?php endif ?>

	<!-- The positions. -->
	<h5>Positions</h5>
	<?php if (!count($positions)): ?>
	<p class="text-muted">No positions have been read.</p>
	<?php else: ?>
	<div class="table-responsive mb-3">
		<table class="table table-sm">
			<thead>
				<tr>
					<th>Share</th><th>Broker ticker</th><th>Matched on</th>
					<th class="text-end">Quantity</th>
					<th class="text-end">Average (&pound;)</th>
					<th class="text-end">Price (&pound;)</th>
					<th class="text-end">Value</th>
					<th class="text-end">Gain</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($positions as $position): ?>
				<tr>
					<td>
						<?php if ($position['symbol'] !== null): ?>
							<a href="quote.php?symbol=<?= urlencode((string) $position['symbol']) ?>"><?= e((string) $position['symbol']) ?></a>
							<?php if ($position['name'] !== null): ?>
							<small class="text-muted d-none d-md-inline"><?= e((string) $position['name']) ?></small>
							<?php endif ?>
						<?php else: ?>
							<?= e(VIEW_DASH) ?>
							<small class="text-muted">not matched</small>
						<?php endif ?>
					</td>
					<td><small><?= e((string) $position['broker_ticker']) ?></small></td>
					<td><small class="text-muted"><?= e((string) $position['matched_on']) ?></small></td>
					<td class="sm-num"><?= e(fmt_quantity($position['quantity'])) ?></td>
					<td class="sm-num"><?= b_money($position['average_price']) ?></td>
					<td class="sm-num"><?= b_money($position['current_price']) ?></td>
					<td class="sm-num"><?= b_money($position['value']) ?></td>
					<td class="sm-num"><?= $position['gain'] === null
						? e(VIEW_DASH) : move_pounds_html($position['gain']) ?></td>
				</tr>
			<?php endforeach ?>
			</tbody>
		</table>
	</div>
	<?php endif ?>

	<!-- The history. Both tables are the broker's record, not this
	     application's: an order here was placed in the Trading 212 app. -->
	<h5>Recent orders</h5>
	<?php if (!count($orders)): ?>
	<p class="text-muted">No orders have been read.</p>
	<?php else: ?>
	<div class="table-responsive mb-3">
		<table class="table table-sm">
			<thead>
				<tr>
					<th>Date</th><th>Share</th><th>Side</th>
					<th class="text-end">Quantity</th>
					<th class="text-end">Price (&pound;)</th>
					<th class="text-end">Value</th>
					<th>Status</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($orders as $order): ?>
				<tr>
					<td style="white-space: nowrap"><?= b_when($order['filled_at'] === null
						? $order['placed_at'] : $order['filled_at']) ?></td>
					<td><?= $order['symbol'] === null
						? '<small>' . e((string) $order['broker_ticker']) . '</small>'
						: '<a href="quote.php?symbol=' . urlencode((string) $order['symbol']) . '">'
						  . e((string) $order['symbol']) . '</a>' ?></td>
					<td><?= e((string) $order['side']) ?></td>
					<td class="sm-num"><?= e(fmt_quantity($order['quantity'])) ?></td>
					<td class="sm-num"><?= b_money($order['fill_price']) ?></td>
					<td class="sm-num"><?= b_money($order['fill_value']) ?></td>
					<td><small><?= e((string) $order['status']) ?></small></td>
				</tr>
			<?php endforeach ?>
			</tbody>
		</table>
	</div>
	<?php endif ?>

	<h5>Recent dividends</h5>
	<?php if (!count($dividends)): ?>
	<p class="text-muted">No dividends have been read.</p>
	<?php else: ?>
	<div class="table-responsive mb-3">
		<table class="table table-sm">
			<thead>
				<tr>
					<th>Paid</th><th>Share</th>
					<th class="text-end">Quantity</th>
					<th class="text-end">Per share (p)</th>
					<th class="text-end">Amount</th>
					<th>Type</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($dividends as $dividend): ?>
				<tr>
					<td style="white-space: nowrap"><?= b_when($dividend['paid_on']) ?></td>
					<td><?= $dividend['symbol'] === null
						? '<small>' . e((string) $dividend['broker_ticker']) . '</small>'
						: '<a href="quote.php?symbol=' . urlencode((string) $dividend['symbol']) . '">'
						  . e((string) $dividend['symbol']) . '</a>' ?></td>
					<td class="sm-num"><?= e(fmt_quantity($dividend['quantity'])) ?></td>
					<?php /* In pence, because that is how a London dividend per share
					         is quoted and how the Dividends page shows one. The stored
					         figure is pounds, like the total beside it. */ ?>
					<td class="sm-num"><?= $dividend['amount_per_share'] === null
						? e(VIEW_DASH) : e(fmt_pence((float) $dividend['amount_per_share'] * 100, 2)) ?></td>
					<td class="sm-num"><?= b_money($dividend['amount']) ?></td>
					<td><small><?= e((string) $dividend['dividend_type']) ?></small></td>
				</tr>
			<?php endforeach ?>
			</tbody>
		</table>
	</div>
	<?php endif ?>

	<!-- What the mapping could not do. Folded away, because it is long and
	     it is only interesting when a holding is missing - but it is here,
	     because the alternative to listing these is guessing at them. -->
	<h5>Mapping</h5>
	<?php if (!$status['ready'] || ($status['instruments_as_of'] === null)): ?>
	<p class="text-muted">The instrument list has not been read yet.</p>
	<?php else: ?>
	<details class="sm-reveal mb-3">
		<summary>
			<?= e(fmt_quantity(count($unmatched))) ?> share<?= count($unmatched) === 1 ? '' : 's' ?>
			with no instrument at the broker<?php if (count($ambiguous)): ?>,
			<?= e(fmt_quantity(count($ambiguous))) ?> ticker<?= count($ambiguous) === 1 ? '' : 's' ?>
			that fit more than one<?php endif ?>
		</summary>
		<div class="card card-body py-2 sm-reveal-body">
			<?php if (count($ambiguous)): ?>
			<p class="mb-1"><small><strong>Not matched, because the answer was not
				obvious.</strong> Guessing one of these would value the wrong
				company's holding.</small></p>
			<ul>
			<?php foreach ($ambiguous as $row): ?>
				<li><small><?= e((string) $row['broker_ticker']) ?>
					<?php if ($row['short_name'] !== null): ?>(<?= e((string) $row['short_name']) ?>)<?php endif ?>
					<?= e((string) $row['currency']) ?>
					<?= e((string) $row['isin']) ?>:
					<?= e((string) $row['note']) ?></small></li>
			<?php endforeach ?>
			</ul>
			<?php endif ?>

			<?php if (count($unmatched)): ?>
			<p class="mb-1"><small>Shares this application follows that the broker
				connection cannot see. Not a fault: a share here simply would not
				appear below if it were held.</small></p>
			<p class="mb-0"><small>
			<?php foreach (array_slice($unmatched, 0, 120) as $row): ?><?= e((string) $row['symbol']) ?>
			<?php endforeach ?>
			<?php if (count($unmatched) > 120): ?>
				and <?= e(fmt_quantity(count($unmatched) - 120)) ?> more
			<?php endif ?>
			</small></p>
			<?php endif ?>
		</div>
	</details>
	<?php endif ?>
