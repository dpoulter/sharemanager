<!DOCTYPE html>
<html>
  <head>
  	<style type="text/css">
      ul{padding:2px;list-style:none;}
      label{float:left;}
    </style>
    <title>Cash Transaction</title>
  </head>
  <body>
    <h3>Deposit/Withdraw Cash</h3>
    <form action="topup.php" method="post">
    <fieldset>
        <div class="form-group col-md-4">
        	<ul>
            
             <li><label>Date</label>	<input autofocus type="date" class="form-control" name="date" placeholder="YYYY-MM-DD"></input></li>
        	 <li><label>Amount</label>	<input  class="form-control" name="amount" placeholder="Amount" type="text"/></input></li>
        	</ul>
            <button type="submit" class="btn btn-default">Deposit</button>
            <button id="btnCancel" name="btnCancel" type="submit" class="btn btn-default">Cancel</button> 
        </div>
    </fieldset>
    </form>
  </body>
 </html>
