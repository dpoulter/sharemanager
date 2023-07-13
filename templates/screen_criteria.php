
        <body>
		<form method="post" action="screen_criteria.php">
		<input type="hidden" value= <?= $screen_id ?> name="screen_id">
        <h3><?= $screen ?></h3>
        <div>
		<table class="table">
        <?php
        //get all screen criteria
        foreach($criterias as $criteria): ?>
				<tr>
				<td><input type="checkbox" value="<?= $criteria["id"] ?>" name="delete[]"></input></td>
                <td><a href="<?="criteria_list.php?screen_id=".$screen_id."&id=".$criteria["id"]?>"><?= $criteria["description"] ?></a> </br></td>
				</tr>
        <?php endforeach ?>
		</table>
		<button name="btnAdd" class="btn btn-outline-primary" type="submit">Add Criteria</button>
		<button name="btnDelete" type="submit" class="btn btn-outline-primary">Delete Checked</button>
		<button name="btnClose" type="submit" class="btn btn-outline-primary">Back</button>
		</div>
		</form>
		</body>
