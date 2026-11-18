<!DOCTYPE html>

<html>

    <head>
        <title>><?php $title ?></title>
        <style type="text/css">
    	.text-bg-danger {
		  background-color: #d43f3a;
		}
		
		.text-bg-warning {
		background-color: #d58512;
		}
		
		.text-bg-success {
		background-color: #398439;
		}
		
		.text-bg-info {
		background-color: #269abc;
		}
    	</style>

    </head>
    <body>
    <h3><?php print( $sector )?></h3>
        <table class="table">
		<tr><th>Symbol</th><th>Company</th><th class="text-center">Quality</th><th class="text-center">Momentum</th><th class="text-center">Value</th><th class="text-center">Overall</th></tr>    
		
        <?php foreach ($sector_companies as $list): ?>
            <tr>
			<td><a href="<?="quote.php?symbol=".$list["symbol"]."&sector=".$sector?>"><?= $list["symbol"] ?></a></td>
			<td><?=$list["name"]?></td>
			
			<?php if ($list["quality_score"] <= 25)
				 $badge_color='text-bg-danger';
			  elseif ($list["quality_score"] <= 50)
			  	$badge_color='text-bg-warning';
			  elseif ($list["quality_score"] <= 75)
			  	$badge_color='text-bg-info';
			  elseif ($list["quality_score"] <= 100)
			  	$badge_color='text-bg-success';
			?>
			
			<td align="center"><span class="badge <?php print($badge_color)?>"><?php print(round($list["quality_score"],0))?></span></td>
			
			<?php if ($list["momentum_score"] <= 25)
				 $badge_color='text-bg-danger';
			  elseif ($list["momentum_score"] <= 50)
			  	$badge_color='text-bg-warning';
			  elseif ($list["momentum_score"] <= 75)
			  	$badge_color='text-bg-info';
			  elseif ($list["momentum_score"] <= 100)
			  	$badge_color='text-bg-success';
			?>
			
			<td align="center"><span class="badge <?php print($badge_color)?>"><?php print(round($list["momentum_score"],0))?></span></td>
			
			<?php if ($list["value_score"] <= 25)
				 $badge_color='text-bg-danger';
			  elseif ($list["value_score"] <= 50)
			  	$badge_color='text-bg-warning';
			  elseif ($list["value_score"] <= 75)
			  	$badge_color='text-bg-info';
			  elseif ($list["value_score"] <= 100)
			  	$badge_color='text-bg-success';
			?>
			
			<td align="center"><span class="badge <?php print($badge_color)?>"><?php print(round($list["value_score"],0))?></td>
				
			<?php if ($list["overall_score"] <= 25)
				 $badge_color='text-bg-danger';
			  elseif ($list["overall_score"] <= 50)
			  	$badge_color='text-bg-warning';
			  elseif ($list["overall_score"] <= 75)
			  	$badge_color='text-bg-info';
			  elseif ($list["overall_score"] <= 100)
			  	$badge_color='text-bg-success';
			?>
			
			<td align="center"><span class="badge <?php print($badge_color)?>"><?php print(round($list["overall_score"],0))?></td>
			
            </tr>
        <?php endforeach ?>
        </table>
 </body>

</html>
