<?php
/**
 * The form behind the New screen button.
 *
 * Makes an empty screen: a name and what it looks for. The criteria are
 * added on the next page.
 */
$val = function ($name) use ($posted) {
	return isset($posted[$name]) && is_string($posted[$name]) ? $posted[$name] : '';
};
?>

<h1>New screen</h1>
<p class="sm-sub">
	A name and what it looks for. The criteria are added on the next page.
</p>

<?php if ($message !== null): ?>
<div class="alert alert-danger py-2">Not created: <?= e($message) ?></div>
<?php endif ?>

<div class="card">
	<div class="card-body">
		<p class="small text-muted">
			This makes the screen and nothing else. Until it has criteria it cannot be run and
			no strategy can be created from it.
		</p>
		<form method="post" action="screen_create.php" class="row g-3" id="screen-create">
			<input type="hidden" name="ticket" value="<?= e($ticket) ?>">

			<div class="col-12 col-md-6">
				<label class="form-label" for="sn-name">Name</label>
				<input type="text" class="form-control" id="sn-name" name="name" required
				       maxlength="100" value="<?= e($val('name')) ?>">
			</div>
			<div class="col-12">
				<label class="form-label" for="sn-description">What it looks for
					<small class="text-muted">(optional)</small></label>
				<input type="text" class="form-control" id="sn-description" name="description"
				       maxlength="255" value="<?= e($val('description')) ?>">
			</div>
			<div class="col-12">
				<button type="submit" class="btn btn-primary">Create screen</button>
				<a class="btn btn-outline-secondary" href="screen_list.php">Cancel</a>
			</div>
		</form>
	</div>
</div>
