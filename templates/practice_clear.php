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
<?php
/* One line, amber: what is left and who left it, then the link. The two
   steps and when they are open are practice_clear()'s, unchanged; only the
   look is this file's. */
$sm_symbols = [];
$sm_tied = [];
$sm_untied = 0;
foreach ($pa_state['leftover'] as $sm_share) {
	$sm_symbols[] = $sm_share;
	if ($sm_share['tied'] !== null) { $sm_tied[strategy_display_name($sm_share['tied'])] = true; }
	else { $sm_untied++; }
}
$sm_n = count($pa_state['leftover']);
?>
<div class="sm-note-amber" id="sm-practice-clear">
	<span class="sm-note-text">
		<strong>Practice account:</strong>
		<?= e(fmt_quantity($sm_n)) ?> share<?= $sm_n === 1 ? '' : 's' ?> left over,
		worth <?= $pa_state['value'] === null ? 'an unknown amount' : '<span title="Account value ' . e(number_format($pa_state['value'], 2)) . ', read ' . e((string) $pa_state['as_of']) . '">' . e(fmt_pounds($pa_state['value'], 0)) . '</span>' ?>
		(<?php foreach ($sm_symbols as $sm_i => $sm_share): ?><?= $sm_i ? ', ' : '' ?><span
			data-share="<?= e((string) $sm_share['symbol']) ?>"
			title="<?= e(rtrim(rtrim(number_format($sm_share['quantity'], 6, '.', ''), '0'), '.')) ?> held, as of <?= e((string) $pa_state['as_of']) ?>"><?=
			e($sm_share['symbol'] === null ? $sm_share['ticker'] : $sm_share['symbol']) ?></span><?php endforeach ?>).
		<?php if (count($sm_tied)): ?>Left by <?= e(implode(', ', array_keys($sm_tied))) ?> (archived strategy).<?php endif ?>
		<?php if ($sm_untied): ?><strong><?= e(fmt_quantity($sm_untied)) ?> tied to no strategy.</strong><?php endif ?>
	</span>
	<span class="sm-note-actions">
	<?php if (!$pa_plan['ok']): ?>
		<span class="small">Clearing is closed now: <?= e($pa_plan['why']) ?>.</span>
	<?php else: ?>
		<form method="post" action="strategies.php" class="d-inline">
			<input type="hidden" name="do" value="practice_clear">
			<input type="hidden" name="step" value="1">
			<button type="submit" class="btn btn-link btn-sm p-0 sm-note-link"
			        title="Lists the sells. Nothing is sent until the second request.">Show what would be sold</button>
		</form>
		<?php if ($sm_can_confirm): ?>
		<form method="post" action="strategies.php" class="d-inline">
			<input type="hidden" name="do" value="practice_clear">
			<input type="hidden" name="step" value="2">
			<button type="submit" class="btn btn-sm btn-danger">Confirm: sell these through the practice account</button>
		</form>
		<?php endif ?>
	<?php endif ?>
	</span>
</div>
