
<body>
<form action="dividends.php" method="post">
<h3>Dividends</h3>
<div>
				<table>
					<thead>
						<tr>
							<td><strong>Month</strong></td><td><strong>Year</strong></td><td></td>
						</tr>
						<tr>
							<td>
								<select name="month" value="<?=$month?>">
								  <option value="1" <?php if($month=='01'):?> selected="selected"<?php endif ?>>January</option>
								  <option value="2" <?php if($month=='02'):?> selected="selected"<?php endif ?>>February</option>
								  <option value="3" <?php if($month=='03'):?> selected="selected"<?php endif ?>>March</option>
								  <option value="4" <?php if($month=='04'):?> selected="selected"<?php endif ?>>April</option>
								  <option value="5" <?php if($month=='05'):?> selected="selected"<?php endif ?>>May</option>
								  <option value="6" <?php if($month=='06'):?> selected="selected"<?php endif ?>>June</option>
								  <option value="7" <?php if($month=='07'):?> selected="selected"<?php endif ?>>July</option>
								  <option value="8" <?php if($month=='08'):?> selected="selected"<?php endif ?>>August</option>
								  <option value="9" <?php if($month=='09'):?> selected="selected"<?php endif ?>>September</option>
								  <option value="10" <?php if($month=='10'):?> selected="selected"<?php endif ?>>October</option>
								  <option value="11" <?php if($month=='11'):?> selected="selected"<?php endif ?>>November</option>
								  <option value="12" <?php if($month=='12'):?> selected="selected"<?php endif ?>>December</option>
								</select> 
							</td>
							<td>
								<select name="year" value="<?=$year?>">
								  <option value="2014" <?php if($year==2014):?> selected="selected"<?php endif ?>>2014</option>
								  <option value="2015" <?php if($year==2015):?> selected="selected"<?php endif ?>>2015</option>
								  <option value="2016" <?php if($year==2016):?> selected="selected"<?php endif ?>>2016</option>
								  <option value="2017" <?php if($year==2017):?> selected="selected"<?php endif ?>>2017</option>
								  <option value="2018" <?php if($year==2018):?> selected="selected"<?php endif ?>>2018</option>
								  <option value="2019" <?php if($year==2019):?> selected="selected"<?php endif ?>>2019</option>
								</select> 
							</td>
							<td>
								<input type="submit" value="Go" class="btn btn-outline-primary">	
							</td>
						</tr>
					</thead>
				</table>	
</div>
<div>
<table class="table" id="tblDiv">
    <tr>
	<thead>
		<td></td>
        <td>Symbol</td>
        <td>Date</td>
        <td>Amount</td>
		<td>Edit</td>
		<td>Remove</td>
	</thead>
    </tr>

<?php foreach ($dividends as $dividend): ?>
    <tr>
	
					<td></td>
					<td><?= $dividend["symbol"] ?></td>
					<td><?= $dividend["dividend_date"] ?> </td>
					<td><?= $dividend["amount"] ?> </td>
					<td><a href="dividends.php?dividend_id=<?= $dividend["dividend_id"] ?>&action=edit"><i class="glyphicon glyphicon-pencil"></i></a></td>
					<td><a href="dividends.php?dividend_id=<?= $dividend["dividend_id"] ?>&action=delete"><i class="glyphicon glyphicon-remove"></i></a></td>
				
   </tr>
<?php endforeach ?>
</table>
</div>

<button id="btnAdd" name="btnAdd" type="submit" class="btn btn-outline-primary">Add</button>
</body>

