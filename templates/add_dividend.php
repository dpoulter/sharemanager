
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
          <li><label>Symbol</label><input autofocus class="form-control typeahead" name="symbol[]" placeholder="Symbol" type="text"/></li>
			 <li><label>Dividend Date</label>	<input type="date" class="form-control" name="dividend_date[]" placeholder="YYYY-MM-DD"></input></li>
			 <li><label>Amount</label>	<input type="number" min="0" step="any" class="form-control" name="amount[]" placeholder="Amount"></input></li>
			 <button id="btnSubmit" name="btnSubmit" type="submit" class="btn btn-outline-primary">Submit</button> 
			 <button id="btnCancel" name="btnCancel" type="submit" class="btn btn-outline-primary">Cancel</button>    
        </div>
    </fieldset>
</body>

