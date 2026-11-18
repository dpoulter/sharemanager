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
	/* The ones that can come out negative - realised profit, and the cash a
	   fill moved, which is negative on a buy - so the minus sign sits in
	   front of the figure rather than inside it. */
	function b_money_signed($value, $dp = 2) { return e(fmt_pounds_signed($value, $dp)); }
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
				A strategy in <strong>Practice</strong> mode sends its orders to
				the Trading 212 practice account between
				<?= e(T212_MARKET_OPEN) ?> and <?= e(T212_MARKET_SHUT) ?>
				(<?= e(T212_MARKET_TZ) ?>), in fractions of a share, from
				<?= e(fmt_pounds(T212_MIN_ORDER_VALUE, 0)) ?> an order.
				An order against the LIVE account needs three things that no
				setting on this page can supply: SM_T212_LIVE_ORDERS switched
				on with its own key, the strategy armed by a logged-in user
				from its own page, and the batch approved. With any of them
				missing, a live strategy plans its orders and sends nothing.
				See includes/README-broker.md.
			</small>
		</div>
	</details>

	<?php foreach (($synced === null ? [] : $synced) as $synced_one): ?>
	<div class="alert <?= $synced_one['status'] === 'OK' ? 'alert-success' : 'alert-warning' ?>">
		<strong><?= e(t212_label_words($synced_one['label'])) ?>:</strong>
		<strong>Sync <?= e(strtolower((string) $synced_one['status'])) ?>.</strong>
		<?= e((string) $synced_one['message']) ?>
	</div>
	<?php endforeach ?>


	<?php /* One button, which reads every account that is switched on: a
	         Sync now that refreshed one of two would leave the page half
	         fresh and give no sign of which half. */ ?>
	<?php if (count(t212_environments_to_read())): ?>
	<form method="post" action="broker.php" class="mb-3">
		<input type="hidden" name="sync" value="1">
		<div class="form-check mb-2">
			<input class="form-check-input" type="checkbox" value="1"
			       id="brokerInstruments" name="instruments">
			<label class="form-check-label" for="brokerInstruments">
				<small>Refresh the instrument list as well (one request per account, and
				the broker allows one every 50 seconds)</small>
			</label>
		</div>
		<button class="btn btn-primary" type="submit">Sync now</button>
	</form>
	<?php endif ?>

	<?php /* One block per account. Two are read now and a position in the
	         practice account is not a position in the live one, so they are
	         never shown in one table. */ ?>
	<?php foreach ($accounts as $account): ?>
		<?php include __DIR__ . '/broker_account.php' ?>
	<?php endforeach ?>
