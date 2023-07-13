<!DOCTYPE html>
<html>
  <head>
    <title>Strategy Form</title>
  </head>
  <body>
  <div class="container">
   <div class="col-md-6">
     <h4>Backing Winners</h4>
    <p>Backing Winners is based on a strategy in the book: Selecting Shares that Perform: Ten Ways to Beat the Index. By Richard Koch. The rules of this strategy are:</p>
    <ul>
		<li>If a share has gained 40% then buy an equal value to current value</li>
		<li>Monitor Superstar shares (annualised return of at least 30%) - sell half if it declined more than 15% from peak. Sell rest if share decline buy another 15%</li>     
		<li>Hold Mini-stars (annualised return of at least 20%). If declines more than 7% from peak , sell half. If shares decline by another 7% then sell rest.)  </li>  
		<li>Sell Normal shares that decline by 8% from purchase price or 10% from peak. </li>
    </ul>
    <form action="strategies.php" method="post">
        <fieldset>
                <button type="submit" class="btn btn-default">Run</button>
        </fieldset>
    </form>
   </div>
   <div class="col-md-6">
	
	 <div class="form-group" id="search">
	 <h4>Shares assigned to the Strategy</h4>
	 <div class="col-md-9">
		<input  class="form-control typeahead"  type="text" name="symbol" placeholder="Enter Symbol"/>
	 </div>
	 </div>
	 <div class="form-group">
    <div class="col-md-3">
		<button type="submit" class="btn btn-default">Add</button>
	</div>
	</div>

   <div class="form-group">
     <?php
		  foreach($shares as $share):?>
        <strong> <?=$share["symbol"]?></strong><br>
	 <?php endforeach?>
   </div>
  </div>
  </div>
  </body>
 </html>
