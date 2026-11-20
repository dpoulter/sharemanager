<?php
/**
 * Clear the practice account: what a retired practice strategy left at the
 * broker, and the two steps that sell it.
 *
 * Expects $pa_state (practice_account_state()) and $pa_plan
 * (practice_clear_plan()). Shown only when something is left. The page only
 * SHOWS the reason a step would be refused; practice_clear() decides.
 */
if (empty($pa_state) || !$pa_state['readable'] || !count($pa_state['leftover'])) { return; }

/* Step two is offered only for the list step one showed. */
$sm_token = $_SESSION['sm_practice_clear'] ?? null;
$sm_can_confirm = $pa_plan['ok'] && is_array($sm_token)
               && $sm_token['hash'] === practice_clear_hash($pa_plan['shares'])
               && time() - (int) $sm_token['at'] <= 900;
?>
<div class="alert alert-warning py-2" id="sm-practice-clear">
	<strong>Left on the practice account.</strong>
	These shares belong to no running strategy
	(read <?= e((string) $pa_state['as_of']) ?>; the account is worth
	<?= $pa_state['value'] === null ? 'an unknown amount' : e(number_format($pa_state['value'], 2)) ?>).
	<ul class="mb-1 small">
		<?php foreach ($pa_state['leftover'] as $sm_share): ?>
		<li data-share="<?= e((string) $sm_share['symbol']) ?>">
			<?= e($sm_share['symbol'] === null ? $sm_share['ticker'] : $sm_share['symbol']) ?>
			<?= e(rtrim(rtrim(number_format($sm_share['quantity'], 6, '.', ''), '0'), '.')) ?>
			<?php if ($sm_share['tied'] !== null): ?>
				&mdash; left by <?= e(strategy_display_name($sm_share['tied'])) ?>
			<?php else: ?>
				&mdash; <strong>tied to no strategy</strong>
			<?php endif ?>
		</li>
		<?php endforeach ?>
	</ul>

	<?php if (!$pa_plan['ok']): ?>
		<div class="small">Clear the practice account is closed now: <?= e($pa_plan['why']) ?>.</div>
	<?php else: ?>
	<div class="d-flex flex-wrap gap-2">
		<form method="post" action="strategies.php">
			<input type="hidden" name="do" value="practice_clear">
			<input type="hidden" name="step" value="1">
			<button type="submit" class="btn btn-sm btn-outline-secondary">Show what would be sold</button>
		</form>
		<?php if ($sm_can_confirm): ?>
		<form method="post" action="strategies.php">
			<input type="hidden" name="do" value="practice_clear">
			<input type="hidden" name="step" value="2">
			<button type="submit" class="btn btn-sm btn-danger">Confirm: sell these through the practice account</button>
		</form>
		<?php endif ?>
	</div>
	<div class="small mt-1">Nothing is sent until the second request.</div>
	<?php endif ?>
</div>
