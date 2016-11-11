
<body>
<form action="cash_history.php" method="post">
<h3>Cash History</h3>
<div>
<table class="table" id="tblDiv">
    <tr>
	<thead>
		<td></td>
        <td>Date</td>
        <td>Type</td>
        <td>Amount</td>
	</thead>
    </tr>

<?php foreach ($cash_history as $cash): ?>
    <tr>
					<td></td>
					<td><?= $cash["date"] ?></td>
					<td><?= $cash["type"] ?> </td>
					<td><?= $cash["amount"] ?> </td>
   </tr>
<?php endforeach ?>
</table>
</div>
</body>

