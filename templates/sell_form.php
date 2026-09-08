<form action="sell.php" method="post">
    <fieldset>
        Stock to sell:
        <div class="mb-3">
            <select name="symbol">
            <?php foreach ($portfolio as $stock): ?>
            <option><?=$stock["symbol"] ?> </option>
            <?php endforeach ?>
            </select>
            
        </div>
        <div class="mb-3">
            <input autofocus class="form-control" name="qty" placeholder="Quantity" type="text"/>
        </div>
        <div>
            <button type="submit" class="btn btn-default">Sell</button>
        </div>
    </fieldset>
</form>
