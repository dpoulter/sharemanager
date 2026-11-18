<?php
	/**
	 * One filled trade, as a row.
	 *
	 * Its own file because the Trades tab draws the same row twice: the
	 * fifteen most recent, and the rest behind "show all". Two copies of
	 * nine columns is two places for a column to go missing from.
	 *
	 * Expects $trade and $card to be in scope.
	 */
	$costs = (float) $trade['commission'] + (float) $trade['stamp_duty']
	       + (float) $trade['slippage'];
?>
<tr>
	<td class="sm-nowrap" data-value="<?= e((string) ($trade['fill_date'] === null
		? $trade['as_of_date'] : $trade['fill_date'])) ?>"><?= s_date($trade['fill_date'] === null
		? $trade['as_of_date'] : $trade['fill_date']) ?></td>
	<td><?= htmlspecialchars((string) $trade['side']) ?></td>
	<td><a href="quote.php?symbol=<?= urlencode($trade['symbol']) ?>"><?= htmlspecialchars($trade['symbol']) ?></a></td>
	<td class="sm-num" data-value="<?= e((string) $trade['quantity']) ?>"><?= s_shares($trade['quantity'], $card) ?></td>
	<td class="sm-num" data-value="<?= e((string) $trade['fill_price']) ?>"><?= e(fmt_pounds($trade['fill_price'])) ?></td>
	<td class="sm-num" data-value="<?= e((string) $trade['consideration']) ?>"><?= s_money($trade['consideration'], 2) ?></td>
	<td class="sm-num" data-value="<?= e((string) $costs) ?>"><?= s_money($costs, 2) ?></td>
	<?php /* Signed, because it is the cash that moved: a purchase is money
	         out of the account and a sale is money in. */ ?>
	<td class="sm-num" data-value="<?= e((string) $trade['total_cost']) ?>"><?= s_money_signed($trade['total_cost'], 2) ?></td>
	<td><?= htmlspecialchars((string) $trade['note']) ?><?php
		/* DEMO on every order that went to Trading 212, so a line that left
		   this database can never be mistaken for one that did not. */
		if (!empty($trade['broker'])): ?>
		<?= status_badge_html('due', 'DEMO') ?><?php endif ?></td>
</tr>
