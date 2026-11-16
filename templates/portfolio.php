<h1>Portfolio</h1>
<p class="sm-sub">Everything held, at the latest stored close.</p>

<?php /* These four were a <pre> block of unlabelled numbers - "Cash: 0.00
         Shares:0.00" - which is a debug dump rather than a summary, and the
         stray "6m" after it was the timespan variable printed by accident.
         Rebuilt properly in part 4 of the redesign; formatted here so the
         figures are at least in pounds and labelled. */ ?>
<div class="card">
	<div class="card-body">
		<div class="sm-strip">
			<div class="sm-strip-item">
				<div class="sm-strip-label">Shares</div>
				<div class="sm-strip-value"><?= e(fmt_pounds($total_value)) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Cash</div>
				<div class="sm-strip-value"><?= e(fmt_pounds($cash)) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Total</div>
				<div class="sm-strip-value"><?= e(fmt_pounds($cash + $total_value)) ?></div>
			</div>
			<div class="sm-strip-item">
				<div class="sm-strip-label">Gain</div>
				<div class="sm-strip-value"><?= move_pounds_html($total_profit) ?></div>
			</div>
		</div>
	</div>
</div>

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
			<li role="presentation" class="active"><a data-bs-toggle="tab" href="#active">Active Shares</a></li>
			<li role="presentation"><a data-bs-toggle="tab" href="#inactive">Sold Shares</a></li>
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
					<?php /* Prices are pence as stored, cash and dividends are
					         pounds, and quantities are whole shares. Every one of
					         these was printing the column straight out of the
					         database: "75.3700" for a price and "0.0000" for a
					         dividend. */ ?>
					<td class="sm-num"><?= e(fmt_quantity($position["qty_purchased"])) ?></td>
					<td class="sm-num"><?= e(fmt_pence($position["price_paid"], 2)) ?></td>
					<td class="sm-num"><?= e(fmt_pounds($position["commission"])) ?></td>
					<td class="sm-num"><?= e(fmt_pence($position["price"], 2)) ?></td>
					<td class="sm-num"><?= e(fmt_quantity($position["qty_sold"])) ?></td>
					<td class="sm-num"><?= e(fmt_pence($position["price_sold"], 2)) ?></td>
					<td class="sm-num"><?= e(fmt_pounds($position["dividends"])) ?></td>
					<td class="sm-num"><?= e(fmt_pounds($position["value_raw"])) ?></td>
					<td class="sm-num"><?= move_pounds_html($position["profit_raw"]) ?></td>
					<td class="sm-num"><?= move_pct_html($position["profit_perc"]) ?></td>
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
					<?php /* Prices are pence as stored, cash and dividends are
					         pounds, and quantities are whole shares. Every one of
					         these was printing the column straight out of the
					         database: "75.3700" for a price and "0.0000" for a
					         dividend. */ ?>
					<td class="sm-num"><?= e(fmt_quantity($position["qty_purchased"])) ?></td>
					<td class="sm-num"><?= e(fmt_pence($position["price_paid"], 2)) ?></td>
					<td class="sm-num"><?= e(fmt_pounds($position["commission"])) ?></td>
					<td class="sm-num"><?= e(fmt_pence($position["price"], 2)) ?></td>
					<td class="sm-num"><?= e(fmt_quantity($position["qty_sold"])) ?></td>
					<td class="sm-num"><?= e(fmt_pence($position["price_sold"], 2)) ?></td>
					<td class="sm-num"><?= e(fmt_pounds($position["dividends"])) ?></td>
					<td class="sm-num"><?= e(fmt_pounds($position["value_raw"])) ?></td>
					<td class="sm-num"><?= move_pounds_html($position["profit_raw"]) ?></td>
					<td class="sm-num"><?= move_pct_html($position["profit_perc"]) ?></td>
					<td><img src="<?php print($arrow)?>"></img></td>
				</tr>
			<?php endforeach ?>
			</table>
        </div>
		


</div>



