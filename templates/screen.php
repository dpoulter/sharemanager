<!DOCTYPE html>

<html>

    <head>
        <title>><?php $title ?></title>

    </head>
    <body>
    <h3><?php $title ?></h3>
        <table class="table">
		<tr><th>Symbol</th><th>Company</th>
	    <?php foreach ($indicators as $indicator): ?>
		<th><?= $indicator["description"] ?></th>
		<?php endforeach ?>
         </tr>    
		
        <?php foreach ($stats as $list): ?>
            <tr>
			<td><a href="<?="quote.php?symbol=".$list["symbol"]."&screen_id=".$screen_id?>"><?= $list["symbol"] ?></a></td>
			<td><?=$list["name"]?></td>
			<?php foreach ($indicators as $indicator): ?>
			<td>
				<?php foreach($list["ind_values"] as $ind_value): ?>
					<?php if ($ind_value["indicator"]==$indicator['name']): ?>
						<?= $ind_value["value"]?>
					<?php endif ?>
				<?php endforeach ?>
			</td>
			<?php endforeach ?>
            </tr>
        <?php endforeach ?>
        </table>
        
        <button name="btnClose" type="button" onclick="location.href='screen_list.php'"  class="btn btn-outline-primary">Back</button>

 </body>

</html>
