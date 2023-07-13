<h3>Portfolio</h3>
<div>
	<div>
        <pre><?php print("Cash: " . number_format($cash,2)); ?>
		<?php print("Shares:" . number_format($total_value,2)); ?>
		<?php print("Total: ".number_format($cash+$total_value,2)); ?>
		<?php print("Profit/Loss:" . number_format($total_profit,2)); ?>
		</pre>
	</div>
	
	<?php echo $timespan ?>

	<div class="container">
		<div class="row">
			<div class="panel panel-default">
				<div class="panel-body">
					<img src="performance_graph.php?session_id=<?=$_SESSION["id"]?>&timespan=<?=$timespan?>"></img>
				</div>
				<form action="performance.php">
				 
					<table>
						<thead>
							
							<tr>
								<td><small>Timespan</small></td>
								<td>
									<select name="selected_timespan" value="1d">
									  <option <?php if ($timespan=='1m'): ?>selected="selected" <?php endif?> value="1m">1 Month</option>
									  <option <?php if ($timespan=='3m'): ?>selected="selected" <?php endif?>value="3m">3 Months</option>
									  <option <?php if ($timespan=='6m'): ?>selected="selected" <?php endif?>value="6m">6 Months</option>
									  <option <?php if ($timespan=='1y'): ?>selected="selected" <?php endif?>value="1y">1 Year</option>
									  <option <?php if ($timespan=='2y'): ?>selected="selected" <?php endif?>value="2y">2 Years</option>
									  <option <?php if ($timespan=='5y'): ?>selected="selected" <?php endif?>value="5y">5 Years</option>
									  <option <?php if ($timespan=='10y'): ?>selected="selected" <?php endif?>value="10y">10 Years</option>
									</select> 
								</td>
								<td><small>   Type</small></td>
								<td>
									<select name="type" value="l">
									  <option value="l">Line</option>
									  <option value="b">Bar</option>
									  <option value="c">Candle</option>
									</select> 
								</td>
								<td>
									<input type="submit" class="btn btn-link" value="Go">
								</td>
							</tr>
						</thead>
					</table>	
						
				</form>
			</div>				
		</div>
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
					<td>Closing Price</td>
					<td>Shares Sold</td>
					<td>Price Sold</td>
					<td>Dividends</td>
					<td>Value</td>
					<td>Profit/Loss</td>
					<td>Percent</td>
					<td></td>
				 </tr>
			  </thead>
			<?php foreach ($positions as $position): ?>
			
				<?php if ($position["profit_perc"]>0)
						$arrow='/img/16px-Green-Up-Arrow.svg.png';
					elseif ($position["profit_perc"]<0) 
						$arrow='/img/16px-RedDownArrow.svg.png';
					else $arrow='';
				?>

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
					<td><img src="<?php print($arrow)?>"></img></td>
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
					<td></td>
				 </tr>
			  </thead>
			<?php foreach ($inactive_positions as $position): ?>
				
				<?php if ($position["profit_perc"]>0)
						$arrow='/img/16px-Green-Up-Arrow.svg.png';
					elseif ($position["profit_perc"]<0) 
						$arrow='/img/16px-RedDownArrow.svg.png';
					else $arrow='';
				?>

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
					<td><img src="<?php print($arrow)?>"></img></td>
				</tr>
			<?php endforeach ?>
			</table>
        </div>
		


</div>



