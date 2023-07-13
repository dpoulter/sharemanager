
<body>
<form action="edit.php" method="post">
<h3><?php print_r($title);?></h3>
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
								<input type="submit" value="Go" class="btn btn-outline-primary"/>
							</td>
						</tr>
					</thead>
				</table>	
</div>
<div>
<table class="table" id="tbl">
     <tr>
	<thead>
		<td></td>
        <td>Symbol</td>
		<td>Transaction Type</td>
        <td>No of Shares</td>
        <td> Execution Price</td>
        <td>Charges Paid</td>
		<td>Transaction Date</td>
		<td>Edit</td>
		<td>Remove</td>
	</thead>
     </tr>

<?php foreach ($positions as $position): ?>
    <tr>
		
		<?php 
			$editable=false;
			if (isset($edits))
			for ($i=0;$i<count($edits);$i++)
				if (isset($edits[$i])&&$edits[$i]==$position["id"])
					$editable=true;
		 if ($editable==true): ?>
		 <td><input type="hidden" value="<?= $position["id"] ?>" name="id[]"></input></td> 
		 <td><input type="text" value="<?= $position["symbol"] ?>" name="symbol[]"></input></td>
		 <td><select id="trx_type" name="trx_type[]">
			<option value="<?= $position["trx_type"]?>" selected><?=$position["trx_type"]?></option>
			<?php if ($position["trx_type"]=="BUY"):?>
			<option value="SELL">SELL</option>
			<?php else:?>
			<option value="BUY">BUY</option>
			<?php endif?>
		</select></td>
        <td><input type="text" value="<?= $position["shares"] ?>" name="shares[]"> </input></td>
        <td><input type="text" value="<?= $position["price_paid"] ?>" name="price_paid[]"> </input></td>
        <td><input type="text" value="<?= $position["commission"] ?>" name="commission[]"> </input></td>
        <td><input type="date" value="<?= $position["purchase_date"]?>" name="purchase_date[]"></input></td>
		<?php else:?>
			<td></td>
        	<td><?= $position["symbol"] ?></td>
			<td><?= $position["trx_type"] ?> </td>
        	<td><?= $position["shares"] ?> </td>
        	<td><?= $position["price_paid"] ?> </td>
        	<td><?= $position["commission"] ?> </td>
        	<td><?= $position["purchase_date"]?></td>
			<td><a href="edit.php?id=<?= $position["id"] ?>&action=edit"><i class="fas fa-edit"></i></a></td>
			<td><a href="edit.php?id=<?= $position["id"] ?>&action=delete"><i class="fas fa-trash"></i></a></td>
		<?php endif?>
   </tr>
<?php endforeach ?>
</table>
</div>

<button id="btnAdd" name="btnAdd" type="submit" class="btn btn-outline-primary">Add</button>
</body>

