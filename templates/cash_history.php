
<h1>Cash History</h1>
<p class="sm-sub">Every deposit and withdrawal on the account, oldest first.</p>

<div class="card">
	<div class="table-responsive">
		<table class="table table-sm mb-0">
			<thead>
				<tr>
					<th>Date</th>
					<th>Type</th>
					<th class="sm-num">Amount</th>
				</tr>
			</thead>
			<tbody>
			<?php if (!count($cash_history)): ?>
				<tr><td colspan="3" class="text-muted">No cash movements yet.</td></tr>
			<?php endif ?>
			<?php foreach ($cash_history as $cash): ?>
				<tr>
					<td><?= e(fmt_date($cash["date"])) ?></td>
					<td><?= e($cash["type"]) ?></td>
					<?php /* Cash is held in pounds, not pence: cash_history.amount
					         is a decimal(20,4) of pounds and was printing all four
					         places. */ ?>
					<td class="sm-num"><?= e(fmt_pounds_signed($cash["amount"])) ?></td>
				</tr>
			<?php endforeach ?>
			</tbody>
		</table>
	</div>
</div>
