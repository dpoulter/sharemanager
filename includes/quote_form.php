<form action="quote.php" method="post">
    <fieldset>
        <div id="symbol" class="form-group">
             <select name="symbol">
            <?php foreach ($symbols as $symbol): ?>
            <option value=<?=$symbol["symbol"]?>><?=$symbol["symbol"]?> - <?=$symbol["description"]?> </option>
            <?php endforeach ?>
            </select>

             <button type="submit" class="btn btn-default">Submit</button>
        </div>
    </fieldset>
</form>
