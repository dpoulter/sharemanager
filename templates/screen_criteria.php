
        <body>
		<form method="post" action="screen_criteria.php">
		<input type="hidden" value= <?= $screen_id ?> name="screen_id">
        <h3><?= $screen ?></h3>
		<?php
		/* An edit here changes what every strategy built on this screen
		   would hold, which sends each back to Draft: its backtest tested
		   the old criteria. Said before the edit, not after. */
		require_once(__DIR__ . "/../includes/screen_links.php");
		$feeds = strategies_for_screen((int) $screen_id);
		?>
		<?php if (count($feeds)): ?>
		<div class="alert alert-warning py-2" id="screen-feeds">
			Strategies built on this screen:
			<?php foreach ($feeds as $i => $feed): ?><?= $i ? ', ' : ' ' ?><a href="strategies.php?s=<?= urlencode($feed['strategy']) ?>"><?= e($feed['name']) ?></a><?= $feed['archived'] ? ' (archived)' : '' ?><?php endforeach ?>.
			Changing a criterion returns them to <strong>Draft</strong>: their backtests tested the
			criteria as they are now.
		</div>
		<?php endif ?>
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
