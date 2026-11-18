
<body>
    <h3>BackTest Results</h3>
    <div>
	<table class="table">
	<tr>
	<th>Id</th><th>Run Date</th><th>Start Date</th><th>End Date</th><th>Super Purchase Gain</th><th>Mini Purchase Gain</th><th>Super Gain</th><th>Super Loss First</th><th>Super Loss Second</th><th>Mini Loss First</th><th>Mini Loss Second</th><th>Normal Purchase/Loss</th><th>Normal Peak Loss</th><th>Sharpe Ratio</th><th>Risk Adjusted Return</th><th>Standard Deviation</th>
	</tr>
     <?php

    //query strategy shares
    $rows = query("SELECT * from backtest_results order by id desc");
    // if we found rows
     foreach($rows as $row):?>
         <tr><td><?=$row["id"]?></td><td><?=$row["run_date"]?></td><td><?=$row["start_date"]?></td><td><?=$row["end_date"]?></td><td><?=$row["parameter1_value"]?></td><td><?=$row["parameter2_value"]?></td><td><?=$row["parameter3_value"]?></td><td><?=$row["parameter4_value"]?></td><td><?=$row["parameter5_value"]?></td><td><?=$row["parameter6_value"]?></td><td><?=$row["parameter7_value"]?></td><td><?=$row["parameter8_value"]?></td><td><?=$row["parameter9_value"]?></td><td><?=$row["sharpe_ratio"]?></td><td><?=$row["avg_daily_return"]?></td><td><?=$row["standard_deviation"]?></td></tr>
	 <?php endforeach?>
	</table>
	</div>
</body>
