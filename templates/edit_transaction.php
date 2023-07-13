
<body>
<form action="edit.php" method="post">
<h3><?php print_r($title);?></h3>
    <style type="text/css">
      ul{padding:2px;list-style:none;}
      label{float:left;}
    </style>
<fieldset>
		 <div class="form-group col-md-4" id="search">
		   <ul>
		    <li><input type="hidden" value="<?= $position["id"] ?>" name="id[]"></input></li>
            <li><label>Symbol</label><input autofocus class="form-control typeahead" name="symbol[]" placeholder="Symbol" type="text" value="<?= $position["symbol"] ?>"></input></li>
			 <li><label>Transaction Type</label>	<select id="trx_type" class="form-control" name="trx_type[]" placeholder="Transaction Type" >
			<?php if ($position["trx_type"]=="SELL"):?>
			<option value="SELL">SELL</option>
			<?php else:?>
			<option value="BUY">BUY</option>
			<?php endif?></select></li>
			 <li><label>No of Shares</label>	<input type="text" class="form-control" name="shares[]" placeholder="No of Shares" value="<?= $position["shares"] ?>"> </input></li>
			 <li><label>Execution Price</label>	<input type="text" class="form-control" name="price_paid[]" placeholder="Execution Price" value="<?= $position["price_paid"] ?>"> </input></li>
			 <li><label>Charges Paid</label>	<input type="text" class="form-control" name="commission[]" placeholder="Charges Paid" value="<?= $position["commission"] ?>"> </input></li>
			 <li><label>Transaction Date</label>	<input type="date" class="form-control" name="purchase_date[]" placeholder="Transaction Date" value="<?= $position["purchase_date"]?>"></input></li>
			 <button id="btnSubmit" name="btnSubmit" type="submit" class="btn btn-default">Submit</button>    
			 <button id="btnCancel" name="btnCancel" type="submit" class="btn btn-default">Cancel</button> 
        </div>
    </fieldset>
</body>

