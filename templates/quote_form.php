<head>
<script>

</script>
</head>

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
				<p>Statistics Refreshed: <?php echo $last_update?> <p>	
				</div>
			</div>
		</div>
	</div>
</div>
