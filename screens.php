<body>
<form action="screen_list.php" method="post">
<h3>Screens</h3>
<div>
<table class="table" id="tbl">
     <tr>
	<th>Select</th>
        <th>Name</th>
        <th>Description</th>
     </tr>

<?php foreach ($screens as $screen): ?>
    <tr>
	<td><input type="radio" value=<?= $screen["id"] ?> name="screen"></input></td>
        <td><?= $screen["name"] ?></td>
	<td><?= $screen["description"] ?></td>
   </tr>
<?php endforeach ?>
</table>
</div>
<button id="btnEdit" type="submit" name="btnEdit" class="btn btn-default">View/Edit</button>
<button id="btnRun" type="submit" name="btnRun" class="btn btn-default">Run</button>
</form>
</body>


