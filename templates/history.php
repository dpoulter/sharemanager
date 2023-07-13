<!DOCTYPE html>

<html>

    <head>
        <title>dump</title>

    </head>
    <body>
        <h3>Transaction History</h3>
        <div>
        <table class="table">
             <tr>
		<th>
                <td>Timestamp</td>
                <td>Type</td>
                <td>Price</td>
                <td>Number of Shares</td>
                </th>
	     </tr>
        <?php foreach ($transactions as $transaction): ?>

            <tr>
                <td><?= $transaction["timestamp"] ?></td>
                <td><?= $transaction["trx_type"] ?></td>
                <td><?= $transaction["price"] ?></td>
                <td><?= $transaction["quantity"] ?></td>
            </tr>

        <?php endforeach ?>
        </table>
        </div>
 </body>

</html>
