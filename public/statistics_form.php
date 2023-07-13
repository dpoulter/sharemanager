<!DOCTYPE html>
<html>
  <head>
    <title>Statistics Form</title>
  </head>
 <style>
.field label {
    float: left;
    width: 30%;
    text-align: right;
    padding-right: 10px;
    margin: 5px 0px 5px 0px;
  }
  .field input {
    width: 70%;
    margin: 0px;
  }
</style>
  <body>
    <h3>Calculate Statistics</h3>
    <form action="statistics.php" method="post" class="form-inline">
        <fieldset>
            <div class="input-group">
		<input autofocus  id="asofdate" size="10" name="asOfDate" value=<?php echo date("d-m-Y");?>  type="text"/>
                <button type="submit" class="btn btn-default">Calculate</button>
	    	<ul class="nav nav-tabs" id="myTab" data-tabs="tabs">
      		<li role="presentation" class="active"><a data-toggle="tab" href="#pricemomentum">Price Momentum</a></li>
      		<li role="presentation"><a data-toggle="tab" href="#movingavg">Moving Average</a></li>
      		<li role="presentation"><a data-toggle="tab" href="#earningsgrowth">Earnings Growth</a></li>
   		</ul>
	<div class="tab-content">
  		<div class="tab-pane active" id="pricemomentum">
                  <table>
                 <tr><td></td></tr>
                 <tr><td> <input type="checkbox" name="threeMnth" value="Y" checked="checked">3 month Momentum<br></td></tr>
                 <tr><td> <input autofocus type="checkbox" name="sixMnth" value="Y" checked="checked">6 month Momentum<br></td></tr>
                 <tr><td> <input autofocus type="checkbox" name="twelveMnth" value="Y" checked="checked">12 month Momentum<br></td></tr>
                <tr><td></td></tr>
                 </table>
                 </div>
  		<div class="tab-pane" id="movingavg">
                 <table>
                 <tr><td></td></tr>
                 <tr><td> <input autofocus type="checkbox" name="tenDayAvg" value="Y" checked="checked">10 Day Moving Average<br></td></tr>
                 <tr><td> <input autofocus type="checkbox" name="thirtyDayAvg" value="Y" checked="checked">30 Day Moving Average<br></td></tr>
                 <tr><td> <input autofocus type="checkbox" name="hndrdDayAvg" value="Y" checked="checked">100 Day Moving Average<br></td></tr>
                 <tr><td></td></tr>
                 </table>
               </div>
   		<div class="tab-pane" id="earningsgrowth">
	         <table>
                 <tr><td> </td> </tr>
		 <tr><td> <input autofocus type="checkbox" name="EarningsGrowth" value="Y" checked="checked">Earnings Growth<br></td></tr>
                <tr><td> </td></tr>
                <tr><td> </td></tr>
                <tr><td> </td></tr>
                </table>
                </div>
	    </div>
            <div class="form-group">
            </div>
        </fieldset>
    </form>
  </body>
 </html>
