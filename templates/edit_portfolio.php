<?php
/**
 * The transactions behind the portfolio, for a month.
 *
 * Reworked for width and for formatting only: the table had no responsive
 * wrapper, so on a phone it made the whole page scroll sideways and took
 * the fixed navbar with it - which leaves no way back to Home. The columns
 * now go through the formatters, because a price paid is pence and the
 * charges are pounds and both were printing four decimal places.
 *
 * What the page does is unchanged. The month and year list, and the fact
 * that editing a row posts the whole table back, are left exactly as they
 * were.
 */
?>

<h1><?= e($title) ?></h1>
<p class="sm-sub">Every buy and sell recorded for the month.</p>

<form action="edit.php" method="post">

	<div class="row g-2 align-items-end mb-3">
		<div class="col-auto">
			<label class="form-label mb-0" for="month">Month</label>
			<select class="form-select form-select-sm" id="month" name="month">
			<?php foreach ([1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
			                5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
			                9 => 'September', 10 => 'October', 11 => 'November',
			                12 => 'December'] as $m_number => $m_name): ?>
				<option value="<?= (int) $m_number ?>"
					<?= (int) ($month ?? 0) === $m_number ? 'selected' : '' ?>><?= e($m_name) ?></option>
			<?php endforeach ?>
			</select>
		</div>
		<div class="col-auto">
			<label class="form-label mb-0" for="year">Year</label>
			<?php /* From the data: the first year there is a transaction in to
			         this one. The list was a fixed 2014 to 2019, so a page that
			         defaults to the current month could not be pointed at any
			         month in it. */ ?>
			<select class="form-select form-select-sm" id="year" name="year">
			<?php foreach ($years as $y): ?>
				<option value="<?= (int) $y ?>"
					<?= (int) ($year ?? 0) === $y ? 'selected' : '' ?>><?= (int) $y ?></option>
			<?php endforeach ?>
			</select>
		</div>
		<div class="col-auto">
			<button type="submit" class="btn btn-sm btn-outline-primary">Go</button>
		</div>
	</div>

	<div class="card">
		<div class="table-responsive">
			<table class="table table-sm mb-0" id="tbl">
				<thead>
					<tr>
						<th></th>
						<th>Share</th>
						<th>Type</th>
						<th class="sm-num">Shares</th>
						<th class="sm-num">Execution price</th>
						<th class="sm-num">Charges</th>
						<th>Date</th>
						<th>Edit</th>
						<th>Remove</th>
					</tr>
				</thead>
				<tbody>
				<?php if (!count($positions)): ?>
					<tr><td colspan="9" class="text-muted">No transactions in this month.</td></tr>
				<?php endif ?>
				<?php foreach ($positions as $position): ?>
					<?php
						$editable = false;
						if (isset($edits)) {
							for ($i = 0; $i < count($edits); $i++) {
								if (isset($edits[$i]) && $edits[$i] == $position["id"]) { $editable = true; }
							}
						}
					?>
					<tr>
					<?php if ($editable): ?>
						<td><input type="hidden" value="<?= e($position["id"]) ?>" name="id[]"></td>
						<td><input class="form-control form-control-sm" type="text"
						           value="<?= e($position["symbol"]) ?>" name="symbol[]"></td>
						<td>
							<select class="form-select form-select-sm" name="trx_type[]">
								<option value="<?= e($position["trx_type"]) ?>" selected><?= e($position["trx_type"]) ?></option>
								<?php if ($position["trx_type"] == "BUY"): ?>
									<option value="SELL">SELL</option>
								<?php else: ?>
									<option value="BUY">BUY</option>
								<?php endif ?>
							</select>
						</td>
						<td><input class="form-control form-control-sm" type="text"
						           value="<?= e($position["shares"]) ?>" name="shares[]"></td>
						<td><input class="form-control form-control-sm" type="text"
						           value="<?= e($position["price_paid"]) ?>" name="price_paid[]"></td>
						<td><input class="form-control form-control-sm" type="text"
						           value="<?= e($position["commission"]) ?>" name="commission[]"></td>
						<td><input class="form-control form-control-sm" type="date"
						           value="<?= e($position["purchase_date"]) ?>" name="purchase_date[]"></td>
						<td></td>
						<td></td>
					<?php else: ?>
						<td></td>
						<td><a href="quote.php?symbol=<?= urlencode($position["symbol"]) ?>"><?= e($position["symbol"]) ?></a></td>
						<td><?= e($position["trx_type"]) ?></td>
						<td class="sm-num"><?= e(fmt_quantity($position["shares"])) ?></td>
						<?php /* Pence, as every price in this application is. */ ?>
						<td class="sm-num"><?= e(fmt_pence($position["price_paid"], 2)) ?></td>
						<?php /* Pounds: commission is cash, not a share price. */ ?>
						<td class="sm-num"><?= e(fmt_pounds($position["commission"])) ?></td>
						<td class="sm-nowrap"><?= e(fmt_date($position["purchase_date"])) ?></td>
						<td><a href="edit.php?id=<?= e($position["id"]) ?>&amp;action=edit"
						       aria-label="Edit this transaction"><i class="fas fa-edit" aria-hidden="true"></i></a></td>
						<td><a href="edit.php?id=<?= e($position["id"]) ?>&amp;action=delete"
						       aria-label="Remove this transaction"><i class="fas fa-trash" aria-hidden="true"></i></a></td>
					<?php endif ?>
					</tr>
				<?php endforeach ?>
				</tbody>
			</table>
		</div>
	</div>

	<button id="btnAdd" name="btnAdd" type="submit" class="btn btn-outline-primary mt-3">Add</button>
</form>
