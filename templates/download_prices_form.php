<!DOCTYPE html>
<html>
  <head>
    <title>Download Share Prices</title>
  </head>
  <body>
    <form action="download_prices.php" method="post">
        <fieldset>
            <div class="form-group">
                <input autofocus class="form-control"  name="start_date" placeholder="Start (DD-MM-YYYY)" type="date"/> 
		<input autofocus class="form-control"  name="end_date" placeholder="End (DD-MM-YYYY)" type="date"/>
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-default">Submit</button>
            </div>
        </fieldset>
    </form>
  </body>
 </html>
