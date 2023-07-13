
<body>
<form action="dividends.php" method="post">
<h3><?php print_r($title);?></h3>
    <style type="text/css">
      ul{padding:2px;list-style:none;}
      label{float:left;}
    </style>
<fieldset>
		 <div class="form-group col-md-4" id="search">
		   <ul>
		    <li><input type="hidden" value="<?= $dividends["dividend_id"] ?>" name="dividend_id[]"></input></li>
		    <li><label>Symbol</label>	<input type="text" class="form-control typeahead" id="search" name="symbol[]" placeholder="Symbol" value="<?= $dividends["symbol"]?>"></input></li>
          <li><label>Dividend Date</label>	<input type="date" class="form-control" name="dividend_date[]" placeholder="YYYY-MM-DD" value="<?= $dividends["dividend_date"]?>"></input></li>
			 <li><label>Amount</label>	<input type="number" min="0" step="any" class="form-control" name="amount[]" placeholder="Amount" value="<?= $dividends["amount"]?>"></input></li>				 
			 <button id="btnSubmit" name="btnSubmit" type="submit" class="btn btn-outline-primary">Submit</button>    
			 <button id="btnCancel" name="btnCancel" type="submit" class="btn btn-outline-primary">Cancel</button> 
        </div>
    </fieldset>
</body>

