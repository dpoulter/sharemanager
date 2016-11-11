<h3>Portfolio</h3>
<div>
<div>
        <pre><?php print("Cash: " . number_format($cash,2)); ?>
		<?php print("Shares:" . number_format($total_value,2)); ?>
		<?php print("Total: ".number_format($cash+$total_value,2)); ?>
		<?php print("Profit/Loss:" . number_format($total_profit,2)); ?>
		</pre>
</div>
		<ul class="nav nav-tabs" id="myTab" data-tabs="tabs">
			<li role="presentation" class="active"><a data-toggle="tab" href="#active">Active Shares</a></li>
			<li role="presentation"><a data-toggle="tab" href="#inactive">Sold Shares</a></li>
		</ul>
	    <div class="tab-content">
		<div class="tab-pane active" id="active">
			<table class="table">
			   <thead>
				 <tr>
					<td>Symbol</td>
					<td>Name</td>
					<td>Shares Purchased</td>
					<td>Price Paid</td>
					<td>Commission Paid</td>
					<td>Current Price</td>
					<td>Shares Sold</td>
					<td>Price Sold</td>
					<td>Dividends</td>
					<td>Value</td>
					<td>Profit/Loss</td>
					<td>Percent</td>
				 </tr>
			  </thead>
			<?php foreach ($positions as $position): ?>

				<tr>
					<td><a href="<?="quote.php?symbol=".$position["symbol"]."&page=portfolio.php"?>"><?= $position["symbol"] ?></a></td>
					<td><?= $position["name"] ?></td>
					<td><?= $position["qty_purchased"] ?></td>
					<td><?= $position["price_paid"] ?></td>
					<td><?= $position["commission"] ?></td>
					<td><?= $position["price"] ?></td>
					<td><?= $position["qty_sold"] ?></td>
					<td><?= $position["price_sold"] ?></td>
					<td><?= $position["dividends"] ?></td>
					<td><?= $position["value"] ?></td>
					<td><?= $position["profit"] ?></td>
					<td><?= $position["profit_perc"] ?></td>
				</tr>

			<?php endforeach ?>
            </table>  
        </div>
		<div class="tab-pane" id="inactive">
			<table class="table">
			<thead>
				 <tr>
					<td>Symbol</td>
					<td>Name</td>
					<td>Shares Purchased</td>
					<td>Price Paid</td>
					<td>Commission Paid</td>
					<td>Current Price</td>
					<td>Shares Sold</td>
					<td>Price Sold</td>
					<td>Dividends</td>
					<td>Value</td>
					<td>Profit/Loss</td>
					<td>Percent</td>
				 </tr>
			  </thead>
			<?php foreach ($inactive_positions as $position): ?>

				<tr>
					<td><a href="<?="quote.php?symbol=".$position["symbol"]."&page=portfolio.php"?>"><?= $position["symbol"] ?></a></td>
					<td><?= $position["name"] ?></td>
					<td><?= $position["qty_purchased"] ?></td>
					<td><?= $position["price_paid"] ?></td>
					<td><?= $position["commission"] ?></td>
					<td><?= $position["price"] ?></td>
					<td><?= $position["qty_sold"] ?></td>
					<td><?= $position["price_sold"] ?></td>
					<td><?= $position["dividends"] ?></td>
					<td><?= $position["value"] ?></td>
					<td><?= $position["profit"] ?></td>
					<td><?= $position["profit_perc"] ?></td>
				</tr>
			<?php endforeach ?>
			</table>
        </div>
		


</div>



