
<body>
<form action="edit.php" method="post">
<h3>Add Transaction</h3>
    <style type="text/css">
      ul{padding:2px;list-style:none;}
      label{float:left;}
    </style>
<fieldset>
		 <div class="form-group col-md-4" id="search">
		   <ul>
          <li><label>Symbol</label><input autofocus class="form-control typeahead" name="symbol[]" placeholder="Symbol" type="text"/></li>
			 <li><label>Transaction Type</label>	<select id="trx_type" class="form-control" name="trx_type[]" placeholder="Transaction Type" ><option value="SELL">SELL</option><option value="BUY">BUY</option></select></li>
			 <li><label>No of Shares</label>	<input type="text" class="form-control" name="shares[]" placeholder="No of Shares"> </input></li>
			 <li><label>Execution Price</label>	<input type="text" class="form-control" name="price_paid[]" placeholder="Execution Price"> </input></td></tr></li>
			 <li><label>Charges Paid</label>	<input type="text" class="form-control" name="commission[]" placeholder="Charges Paid" > </input></li>
			 <li><label>Transaction Date</label>	<input type="date" class="form-control" name="purchase_date[]" placeholder="YYYY-MM-DD"></input></li>
			 <button id="btnSubmit" name="btnSubmit" type="submit" class="btn btn-outline-primary">Submit</button> 
			 <button id="btnCancel" name="btnCancel" type="submit" class="btn btn-outline-primary">Cancel</button>    
        </div>
    </fieldset>
</body>

