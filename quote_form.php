<head>
<script>

</script>
</head>
<div class="container">
<form role="form" action="quote.php" method="post">
	 <div class="form-group col-md-4" id="search">
				<input  class="form-control typeahead"  type="text" name="symbol" placeholder="Enter Symbol"/>
		</div>
		<div class="form-group">
				<button type="submit" class="btn btn-default">Lookup</button>
        </div>
	
</form>
</div>
<div class="container">
	<div class="row">
<div class="col-md-4">
	<div class="panel panel-default">
		<div class="panel-heading">Momentum Top Ten</div>		
  		<div class="panel-body">
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
<div class="col-md-4">
	<div class="panel panel-default">
		<div class="panel-heading">Value Top Ten</div>		
  		<div class="panel-body">
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
<div class="col-md-4">
	<div class="panel panel-default">
		<div class="panel-heading">Quality Top Ten</div>		
  		<div class="panel-body">
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
</div>
<div class="row">
<div class="col-md-4">
	<div class="panel panel-default">
		<div class="panel-heading">Overall Top Ten</div>		
  		<div class="panel-body">
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
</div>