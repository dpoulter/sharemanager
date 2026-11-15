<head>
<script>

</script>
</head>

<div class="container">
<?php
	require_once(__DIR__ . '/../includes/ai_outlook.php');
	$outlook_scope  = 'market';
	$outlook_symbol = null;
	$outlook_title  = 'Market outlook';
	include(__DIR__ . '/ai_outlook_panel.php');
?>
</div>

<div class="container">
	<div class="row">
		<div class="col-md-6">
			<div class="card card-default">
				<div class="card-header">Momentum Top Ten</div>		
				<div class="card-body">
					<table class="table">
						<?php foreach ($momentum_topten as $item): ?>
							<tr>
								<td><a href="<?="quote.php?symbol=".$item["symbol"]."&page=quote_form.php"?>"><?= $item["symbol"] ?></a></td>
								<td><?= $item["name"] ?> </td>
							</tr>
						<?php endforeach ?>
					</table>
				</div>
			</div>
		</div>
		<div class="col-md-6">
			<div class="card card-default">
				<div class="card-header">Value Top Ten</div>		
				<div class="card-body">
					<table class="table">
						<?php foreach ($value_topten as $item): ?>
							<tr>
								<td><a href="<?="quote.php?symbol=".$item["symbol"]."&page=quote_form.php"?>"><?= $item["symbol"] ?></a></td>
								<td><?= $item["name"] ?> </td>
							</tr>
						<?php endforeach ?>
					</table>
				</div>
			</div>
		</div>
	</div>
	<div class="row">
		<div class="col-md-6">
			<div class="card card-default">
				<div class="card-header">Quality Top Ten</div>		
				<div class="card-body">
					<table class="table">
						<?php foreach ($quality_topten as $item): ?>
							<tr>
								<td><a href="<?="quote.php?symbol=".$item["symbol"]."&page=quote_form.php"?>"><?= $item["symbol"] ?></a></td>
								<td><?= $item["name"] ?> </td>
							</tr>
						<?php endforeach ?>
					</table>
				</div>
			</div>
		</div>
		<div class="col-md-6">
			<div class="card card-default">
				<div class="card-header">Overall Top Ten</div>		
				<div class="card-body">
					<table class="table">
						<?php foreach ($overall_topten as $item): ?>
							<tr>
								<td><a href="<?="quote.php?symbol=".$item["symbol"]."&page=quote_form.php"?>"><?= $item["symbol"] ?></a></td>
								<td><?= $item["name"] ?> </td>
							</tr>
						<?php endforeach ?>
					</table>
				</div>
			</div>
		</div>
	</div>
	<div class="row">
	<div class="col-md-6">
			<div class="card card-default">
				<div class="card-header">System Messages</div>		
				<div class="card-body">
				<?php /* The as of date and the run time are different facts.
				         "Statistics Refreshed: 2026-11-14 09:12:03" read as one
				         timestamp and was neither: the date was the day the
				         prices came from, the time was when the job started. */ ?>
				<p>Prices as of <?php echo htmlspecialchars((string) $last_update) ?: 'no run yet'?><?php if ($last_run): ?> &middot; updated <?php echo htmlspecialchars((string) $last_run)?><?php endif?> <p>	
				</div>
			</div>
		</div>
	</div>
</div>
