<!DOCTYPE html>
<html>
  <head>
    <title>New Criteria</title>
  </head>
  <body>
 
    <form class="form-horizontal" action="backtest.php" method="post"> 			
    <fieldset>
    <div class="form-group">    			  
          <label class="col-sm-3 control-label" for="start_date">Start Date</label>
		    <div class="col-sm-3">
		    	<input autofocus class="form-control" type="text" id="start_date" name="start_date" placeholder="YYYY-MM-DD"/>
		    </div>
		    <label class="col-sm-3 control-label" for="end_date">End Date</label>
		    <div class="col-sm-3">
		    	<input class="form-control" type="text" id="end_date" name="end_date" placeholder="YYYY-MM-DD"/>
		    </div> 
	 </div>          
    <div class="form-group">    			  
          <label class="col-sm-3 control-label" for="super_purch_gain">Percentage increase since purchase to qualify as Super Performer</label>
		    <div class="col-sm-3">
		    	<input class="form-control" type="text" id="super_purch_gain" name="super_purch_gain" value="0.3"/>
		    </div>
  		    <label class="col-sm-3 control-label" for="mini_purch_gain">Percentage increase since purchase to qualify as Mini Performer</label>
		    <div class="col-sm-3">
		    	<input class="form-control" type="text" id="mini_purch_gain" name="mini_purch_gain" value="0.2"/>
		    </div>
    </div>       
    <div class="form-group">    			  
          <label class="col-sm-3 control-label" for="super_gain">Percentage increase of Super Performer to double holding</label>
		    <div class="col-sm-3">
		    	<input class="form-control" type="text" id="super_gain" name="super_gain" value="0.4"/>
		    </div>
  		    <div class="col-sm-3"></div>
		    <div class="col-sm-3"></div> 
    </div>       
    <div class="form-group">    			  
          <label class="col-sm-3 control-label" for="super_loss_1">Percentage decrease from peak price before selling half of Super Performer</label>
		    <div class="col-sm-3">
		    	<input class="form-control" type="text" id="super_loss_1" name="super_loss_1" value="-0.15"/>
		    </div>
		    <label class="col-sm-3 control-label" for="super_loss_2">Percentage decrease from peak price before selling rest of Super Performer</label>
		    <div class="col-sm-3">
		    	<input class="form-control" type="text" id="super_loss_2" name="super_loss_2" value="-0.3"/>
		    </div> 
    </div>                     
    <div class="form-group">    			  
          <label class="col-sm-3 control-label" for="mini_loss_1">Percentage decrease from Peak before selling half of Mini Performer</label>
		    <div class="col-sm-3">
		    	<input class="form-control" type="text" id="mini_loss_1" name="mini_loss_1" value="-0.2"/>
		    </div>
			<label class="col-sm-3 control-label" for="mini_loss_2">Percentage decrease from Peak before selling rest of Mini Performer</label>
		    <div class="col-sm-3">
		    	<input class="form-control" type="text" id="mini_loss_2" name="mini_loss_2" value="-0.2"/>
		    </div>
    </div>       
    <div class="form-group">    			  
          <label class="col-sm-3 control-label" for="norm_purch_loss">Decrease in purchase price of Normal Performer to sell</label>
		    <div class="col-sm-3">
		    	<input class="form-control" type="text" id="norm_purch_loss" name="norm_purch_loss" value="-0.1"/>
		    </div>
		    <label class="col-sm-3 control-label" for="norm_peak_loss">Decrease from peak price of Normal Performer to sell</label>
		    <div class="col-sm-3">
		    	<input class="form-control" type="text" id="norm_peak_loss" name="norm_peak_loss" value="-0.2"/>
		    </div>
    </div>       
    <div class="form-group">    			  
          <div class="col-sm-3">
		    	<button type="submit" class="btn btn-primary">Run</button>
		    </div>
		    <div class="col-sm-3"></div>
		    <div class="col-sm-3"></div>
    </div>      
    </fieldset>    
    </form>
  </body>
 </html>
