<!DOCTYPE html>

<html>

    <head>
		
        <title><?=$title?></title>
    </head>

	
    <body>
    
		<div class="container">
		<div class="row">
		    <div class="col-md-6">
		     <h3><?=$share_info["name"]?> (<?=$title?>)</h3>
		    </div>
		</div>
		<div class="row">
		    <div class="col-md-6">
		     <h4> <?=$share_info["price"]?> (<?=$share_info["change"]?>)</h4>
		    </div>
		 </div>
		</div>
      <div class="container">
      <div class="col-md-6">
      	<div class="row">
	      	<div class="col-md-2">
			     <small><b>Symbol</b></small>
			    </div>
			    <div class="col-md-2">
			     <small><?=$title?></small>
			    </div>
			    <div class="col-md-2">
			     <small><b>Mkt cap</b></small>
			    </div>
			    <div class="col-md-3">
			     <small><?=$share_info["capital"]?></small>
			    </div>
		    </div>
		    <div class="row">
	      	<div class="col-md-2">
			     <small><b>Price</b></small>
			    </div>
			    <div class="col-md-2">
			     <small><?=$share_info["price"]?></small>
			    </div>
			    <div class="col-md-2">
			     <small><b>Shares</b></small>
			    </div>
			    <div class="col-md-3">
			     <small><?=$share_info["float"]?></small>
			    </div>
		    </div>
		    <div class="row">
	      	<div class="col-md-2">
			     <small><b>Change</b></small>
			    </div>
			    <div class="col-md-2">
			     <small><?=$share_info["change"]?></small>
			    </div>
			    <div class="col-md-2">
			     <small><b>Range</b></small>
			    </div>
			    <div class="col-md-3">
			     <small><?=$share_info["day_range"]?></small>
			    </div>
		    </div>
		    <div class="row">
	      	<div class="col-md-2">
			     <small><b>52 Wk Low</b></small>
			    </div>
			    <div class="col-md-2">
			     <small><?=$share_info["52w_low"]?></small>
			    </div>
			    <div class="col-md-2">
			     <small><b>52 Wk High</b></small>
			    </div>
			    <div class="col-md-3">
			     <small><?=$share_info["52w_high"]?></small>
			    </div>
		    </div>
			</div>
			<div class="col-md-6">
				<div class="row">
					<div class="col-md-3">
						Momentum
					</div>						
					<div class="col-md-3">
						<?php print(round($scores['momentum']))?>
					</div>
				</div>
				<div class="row">					
					<div class="col-md-3">
						Value
					</div>						
					<div class="col-md-3">
						<?php print(round($scores['value']))?>
					</div>	
				</div>
			</div>
		</div>
		</br>
		<div class="container">
		<div class="row">
		<ul class="nav nav-tabs" id="myTab" data-tabs="tabs">
		   <li role="presentation" class="active"><a data-toggle="tab" href="#profile">Profile</a></li>
			<li role="presentation"><a data-toggle="tab" href="#chart">Chart</a></li>
			<li role="presentation"><a data-toggle="tab" href="#news">News</a></li>
			<li role="presentation"><a data-toggle="tab" href="#statistics">Statistics</a></li>
			<li role="presentation" ><a data-toggle="tab" href="#financials">Financial Statements</a></li>
			<li role="presentation" ><a data-toggle="tab" href="#ratings">Ratings</a></li>
		
   		</ul>
	    <div class="tab-content">
   	 <div class="tab-pane active" id="profile">
   	 <?php $profile=get_profile($symbol); ?>
   	 	<div class="container">
	 		<div class="col-md-6">
	 		<table  class="table">
		 			<tr>
		 				<td><b>Business Summary</b></td>
		 			</tr>
	 				<tr>
							<td><?php print($profile['description']) ?><td>
					</tr>
					
				</table>
   	    		
	 		</div>
	 		<div class="col-md-6">
	 			<table  class="table">
		 			<tr>
		 				<td><b>Sector</b></td><td><?php print($share_info["sector"])?></td>
		 			</tr>
	 				<tr>
							<td><b>Website</b></td><td><?php if($profile['website']!='N/A') print('<a target="_blank" href="'.$profile['website'].'">'.$profile['website'].'</a>'); else print('N/A');?></td>
					</tr>
					<tr>
							<td><b>Employees</b></td><td><?php print($profile['employees']) ?></td>
					</tr>
					<tr>
							<td><b>Directors</b></td><td><?php print($profile['directors']) ?></td>
					</tr>
					<tr>
							<td></td><td><?php print('<img alt="" src="'.$profile['logo'].'"></img>') ?> </td><td></td>
					</tr>
				</table>
				
	 			
	 		</div>
	 
	 </div>
       </div>
		<div class="tab-pane" id="chart">
             <img src=<?=$chart?>>
			 <form action="quote.php">
			 <input type="hidden" value="<?=$symbol?>" name="symbol"></input>
				<table>
					<thead>
						<tr>
							<td><strong>Timespan</strong></td><td><strong>Type</strong></td>
						</tr>
						<tr>
							<td>
								<select name="timespan" value="1d">
								  <option value="1d">1 Day</option>
								  <option value="5d">5 Day</option>
								  <option value="3m">3 Months</option>
								  <option value="6m">6 Months</option>
								  <option value="1y">1 Year</option>
								  <option value="2y">2 Years</option>
								  <option value="1y">5 Years</option>
								  <option value="my">Maximum</option>
								</select> 
							</td>
							<td>
								<select name="type" value="l">
								  <option value="l">Line</option>
								  <option value="b">Bar</option>
								  <option value="c">Candle</option>
								</select> 
							</td>
						</tr>
					</thead>
				</table>	
				<input type="submit" class="btn btn-default">	
				</form>
        </div>
		<div class="tab-pane" id="news">
		 	<table class="table">
		     <?php $articles=get_articles($symbol);
			 foreach($articles as $article)
				print ('<tr><td><a href="'.$article["link"].'" target="_blank">'.$article["title"].'</a></td></tr>');
             ?>
			</table>        
        </div>
		<div class="tab-pane" id="statistics" >
			
			<?php $i=0; foreach ($quote as $category_indicator): ?>
				 
                  <table class="table table-striped table-bordered table-condensed">
				    
					<thead>
					<tr><th width="25%"><?= $category_indicator["category"]["description"]?></th><th width="25%">Share</th><th width="25%">Sector</th><th width="25%">Market</th></tr>
					</thead>
					<tbody>
					<?php foreach ($category_indicator["indicators"] as $indicator): ?>
						<tr><td width="25%"><?php print($indicator["description"]);?></td><td width="25%"> <?php print ($indicator["value"] ); ?> </td><td width="25%"><?php print($indicator["sector_average"]);?></td><td width="25%"><?php print($indicator["market_average"]);?></td></tr>
					<?php endforeach?>
					</tbody>
					
                 </table>
		
			<?php $i++; endforeach; ?>
			
        </div>
  
		<div class="tab-pane" id="financials" >
			<ul class="nav nav-pills">
				<li class="active"><a data-toggle="pill" href="#incomestatement">Income Statement</a></li>
				<li><a data-toggle="pill" href="#balancesheet">Balance Sheet</a></li>
			</ul>
			<div class="tab-content">
				<div class="tab-pane active" id="incomestatement">
						<table class="table table-striped table-bordered">
							<tr><td></td></tr>
							<tr><td></td><?php for ($i=0; $i<count($incomestatement['periods']);$i++): ?><td> <strong><?php print($incomestatement['periods'][$i]["end_date"])?></strong></td><?php endfor?></tr>
							<?php foreach ($incomestatement["period_items"] as $period_item ): ?>
								<tr><td><?php print($period_item['description'])?></td><?php for ($i=0; $i<count($period_item['values']);$i++): ?><td><?php print($period_item['values'][$i])?></td><?php endfor?></tr>
							<?php endforeach?>
							<tr><td></td></tr>
						 </table>
				</div>
				<div class="tab-pane" id="balancesheet">
						<table class="table table-striped table-bordered">
							<tr><td></td></tr>
							<tr><td></td><?php for ($i=0; $i<count($balancesheet['periods']);$i++): ?><td> <strong><?php print($balancesheet['periods'][$i]["end_date"])?></strong></td><?php endfor?></tr>
							<?php foreach ($balancesheet["period_items"] as $period_item ): ?>
								<tr><td><?php write_log('quote.php','description='.$period_item['description']); print($period_item['description'])?></td><?php for ($i=0; $i<count($period_item['values']);$i++): ?><td><?php print($period_item['values'][$i])?></td><?php endfor?></tr>
							<?php endforeach?>
							<tr><td></td></tr>
						 </table>
				</div>
			</div>
		
		</div>
		<div class="tab-pane" id="ratings" >
			
			<table class="table table-striped table-bordered">
				<tr><th>Rating</th><th>Value out of 5</th></tr>
				<tr><td>Momentum Rating</td><td><?=$ratings["momentum_rating"]?></td></tr>
				<tr><td>Growth Rating</td><td><?=$ratings["growth_rating"]?></td></tr>
				<tr><td>Value Rating</td><td><?=$ratings["value_rating"]?></td></tr>
				<tr><td>Quality Rating</td><td><?=$ratings["quality_rating"]?></td></tr>
				<tr><td><b>Overall Rating</b></td><td><b><?=$ratings["overall_rating"]?></b></td></tr>
			</table>
			<table class="table table-striped table-bordered">
				<tr>
				<td>
				<table class="table table-striped table-bordered">
				<tr><th>Momentum Statistics</th><th></th></tr>
				<?php foreach($momentum_statistics as $momentum): ?><tr> <td><?php print($momentum["description"])?></td><td><?php print($momentum["value"])?></td></tr><?php endforeach?>
				</table>
				</td>
				<td>
				<table class="table table-striped table-bordered">
				<tr><th>Growth Statistics</th><th></th></tr>
				<?php foreach($growth_statistics as $growth): ?><tr> <td><?php print($growth["description"])?></td><td><?php print($growth["value"])?></td></tr><?php endforeach?>
				</table>
				</td>
				</tr>
				<tr>
				<td>
				<table class="table table-striped table-bordered">
				<tr><th>Value Statistics</th><th></th></tr>
				<?php foreach($value_statistics as $value): ?><tr> <td><?php print($value["description"])?></td><td><?php print($value["value"])?></td></tr><?php endforeach?>
				</table>
				</td>
				<td>
				<table class="table table-striped table-bordered">
				<tr><th>Quality Statistics</th><th></th></tr>
				<?php foreach($quality_statistics as $quality): ?><tr> <td><?php print($quality["description"])?></td><td><?php print($quality["value"])?></td></tr><?php endforeach?>
				</table>
				</td>
				</tr>
			</table>
		
			
		</div>
		
		<?php if (isset($screen_id)): ?>
			<FORM action="screen_list.php" METHOD="POST" NAME="myForm">
			<INPUT TYPE="HIDDEN" NAME="btnRun" VALUE="Y">
			<INPUT TYPE="hidden" NAME="screen" VALUE=<?=$screen_id?>>
			<A HREF="#" onClick="document.myForm.submit();return false">Back</A>
			</FORM>
		<?php else: ?>
			<a href="javascript:history.go(-1);">Back</a></br>
		<?php endif ?>
	</div>	
	</div>	
	</body>

</html>
