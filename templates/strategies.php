
<body>
    <h3>Backing Winners</h3>
     <div>
	<table class="table">
	<tr>
	<th>Symbol</th><th>Purchase Gain/Loss %</th><th>Peak Loss %</th><th>Classification</th><th>Buy/Sell</th>
	</tr>
     <?php

    //query strategy shares
    $rows = query("SELECT symbol,purch_gainloss_percent*100 purch_gainloss_percent,peak_gainloss_percent*100 peak_gainloss_percent,classification, status, status_date, buy_sell_flag from strategy_shares where session_id=?",$_SESSION["id"]);
    // if we found rows
     foreach($rows as $row):?>
         <tr><td><?=$row["symbol"]?></td><td><?=$row["purch_gainloss_percent"]?></td><td><?=$row["peak_gainloss_percent"]?></td><td><?=$row["classification"]?></td><td><?=$row["buy_sell_flag"]?></td></tr>
	 <?php endforeach?>
	</table>
	</div>
</body>
