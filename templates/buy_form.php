<!DOCTYPE html>
<html>
  <head>
    <title>Buy Form</title>
  </head>
  <body>
    <form action="buy.php" method="post">
        <fieldset>
            <div class="form-group">
                <input autofocus class="form-control" name="symbol" placeholder="Symbol" type="text"/>
                <input autofocus class="form-control" name="qty" placeholder="Quantity" type="text"/>
                <input autofocus class="form-control"  name="buyDate" placeholder="Buy Date (DD-MM-YYYY)" type="date"/> 
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-default">Buy</button>
				<button type="button" onclick="location.href='edit.php'" class="btn btn-default">Cancel</button>
            </div>
        </fieldset>
    </form>
  </body>
 </html>
