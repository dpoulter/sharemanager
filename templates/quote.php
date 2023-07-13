

    
		<?php write_log ("quote.php","1")?>
		<div class="container">
			
			<div class="row">
			    <div class="col-md-12">
			     <h3><?=$share_info["name"]?> (<?=$title?>)</h3> 
			    </div>  
			</div>	    
		
			<div class="row">
				<div class="col-md-12">
			    	<?php if ($share_info["change"]>0)
							$arrow='/img/16px-Green-Up-Arrow.svg.png';
						elseif ($share_info["change"]<0) 
							$arrow='/img/16px-RedDownArrow.svg.png';
						else $arrow='';
					?>
					<h4> <?=$share_info["price"]?> <!-- () 	<img src="<//?php print($arrow)?>"></img>--></h4>
			     </div>
			</div>
		</div>
		
		<?php write_log ("quote.php","2")?>
      	<div class="container">
      		<div class="row">
      			<div class="col-md-8">
      				<div class="row">
      				
						
							<table class="table table-sm table-borderless ">
						      	<tr>
								     <td><b>Symbol</b></td><td><?=$title?></td><td><b>Mkt cap</b></td><td></td>
							    </tr>
								<tr>
								     <td><b>Price</b></td><td><?=$share_info["price"]?></td><td><b>Shares</b></td><td><?=$share_info["shares"]?></td>
							    </tr>
								<tr>
								     <td><b>52 Wk Low</b></td><td></td><td><b>52 Wk High</b></td><td></td>
							    </tr>
							</table>
						
					
					</div>
					<?php write_log ("quote.php","3")?>
					<div class="row">
						<div class="card card-default">
							<div class="card-body">
								<img src="stockgraph.php?symbol=<?=$symbol?>&timespan=<?=$timespan?>"></img>
							</div>
							<form action="quote.php">
							 <input type="hidden" value="<?=$symbol?>" name="symbol"></input>
								<table>
									<thead>
										
										<tr>
											<td><small>Timespan</small></td>
											<td>
												<select name="timespan" value="1d">
												  <option <?php if ($timespan=='1m'): ?>selected="selected" <?php endif?> value="1m">1 Month</option>
												  <option <?php if ($timespan=='3m'): ?>selected="selected" <?php endif?>value="3m">3 Months</option>
												  <option <?php if ($timespan=='6m'): ?>selected="selected" <?php endif?>value="6m">6 Months</option>
												  <option <?php if ($timespan=='1y'): ?>selected="selected" <?php endif?>value="1y">1 Year</option>
												  <option <?php if ($timespan=='2y'): ?>selected="selected" <?php endif?>value="2y">2 Years</option>
												  <option <?php if ($timespan=='5y'): ?>selected="selected" <?php endif?>value="5y">5 Years</option>
												  <option <?php if ($timespan=='10y'): ?>selected="selected" <?php endif?>value="10y">10 Years</option>
												</select> 
											</td>
											<td><small>   Type</small></td>
											<td>
												<select name="type" value="l">
												  <option value="l">Line</option>
												  <option value="b">Bar</option>
												  <option value="c">Candle</option>
												</select> 
											</td>
											<td>
												<input type="submit" class="btn btn-link" value="Go">
											</td>
										</tr>
									</thead>
								</table>	
									
							</form>
						</div>
						
					</div>
				</div>
				<div class="col-md-4">
				<?php write_log ("quote.php","4")?>	
					<div class="card card-default">
					
  						<div class="card-body">
  							
								
  						
  							<ul class="list-group">
	  							<?php if ($scores['momentum'] <= 25)
										 $badge_color='badge-danger';
									  elseif ($scores['momentum'] <= 50)
									  	$badge_color='badge-warning';
									  elseif ($scores['momentum'] <= 75)
									  	$badge_color='badge-info';
									  elseif ($scores['momentum'] <= 100)
									  	$badge_color='badge-success';
								?>
  								<li class="list-group-item">
    								<span class="badge <?php print($badge_color)?>"><?php print(round($scores['momentum'],0))?></span>
    								<a data-toggle="modal" href="#MomentumModal" >Momentum</a>
    							</li>
    							<?php if ($scores['value'] <= 25)
										 $badge_color='badge-danger';
									  elseif ($scores['value'] <= 50)
									  	$badge_color='badge-warning';
									  elseif ($scores['value'] <= 75)
									  	$badge_color='badge-info';
									  elseif ($scores['value'] <= 100)
									  	$badge_color='badge-success';
								?>
	  							<li class="list-group-item">
	    						<span class="badge <?php print($badge_color)?>"><?php print(round($scores['value'],0))?></span>
	    							<a data-toggle="modal" href="#ValueModal" >Value</a>
	  							</li>
						
	  							<?php if ($scores['quality'] <= 25)
										 $badge_color='badge-danger';
									  elseif ($scores['quality'] <= 50)
									  	$badge_color='badge-warning';
									  elseif ($scores['quality'] <= 75)
									  	$badge_color='badge-info';
									  elseif ($scores['quality'] <= 100)
									  	$badge_color='badge-success';
								?>
								<li class="list-group-item">
	    						<span class="badge <?php print($badge_color)?>"><?php print(round($scores['quality'],0))?></span>
	    							<a data-toggle="modal" href="#QualityModal" >Quality</a>
	  							</li>
							</ul>
		  				</div>
						  <?php write_log ("quote.php","5")?>
		  		
  						<div class="card-footer">
	  						<?php if ($scores['overall'] <= 25)
										 $badge_color='badge-danger';
									  elseif ($scores['overall'] <= 50)
									  	$badge_color='badge-warning';
									  elseif ($scores['overall'] <= 75)
									  	$badge_color='badge-info';
									  elseif ($scores['overall'] <= 100)
									  	$badge_color='badge-success';
								?>
	  						<ul class="list-group">
	  							<li class="list-group-item">
	    						<span class="badge <?php print($badge_color)?>"><?php print(round($scores['overall'],0))?></span>
	    							ShareRank
	  							</li>
							</ul>
  						
  						</div>
					</div>
					<?php write_log ("quote.php","6")?>
				<!--
					<div class="card card-default">
						<div class="card-header">
							Health Indicators
						</div>
		  				<div class="card-body">
		  					<table class="table">
		  						<tr>
		  							<td width="80%"><a href="#Piotroski" onclick="load_piotroski_modal()">Piotroski F-Score</a></td>
		  							
		  							<td style="text-align:right">
		  							<?php if ($piotroski_fscore <= 2)
											 $state='label-danger';
										  elseif ($piotroski_fscore <= 5)
										  	$state='label-warning';
										  elseif ($piotroski_fscore <= 7)
										  	$state='label-info';
										  elseif ($piotroski_fscore <= 9)
										  	$state='label-success';
									?>
									
									<span class="label <?php print($state)?>"><?php print($piotroski_fscore)?>	</span>
		  							</td>
		  						</tr>
		  						<tr>
		  						<td width="80%"><a href="#Altman" onclick="load_altman_modal()">Altman Z-Score (manufacturing)</a></td>	
		  						<td style="text-align:right">
		  						<?php if ($altman_zscore < 1.81)
											 $state='label-danger';
										  elseif ($altman_zscore < 2.675)
										  	$state='label-warning';
										  elseif ($altman_zscore <= 2.99)
										  	$state='label-info';
										  elseif ($altman_zscore >= 3.0)
										  	$state='label-success';
									?>
									
									<span class="label <?php print($state)?>"><?php print($altman_zscore)?>	</span>
									
		  						</td>
		  						</tr>
		  						<tr>
		  						<td width="80%"><a href="#AltmanNonman" onclick="load_altman_nonman_modal()">Altman Z-Score (non manufacturing)</a></td>	
		  						<td style="text-align:right">
		  						<?php if ($altman_zscore_nonman < 1.2)
											 $state='label-danger';
										  elseif ($altman_zscore_nonman <= 2.6)
										  	$state='label-info';
										  elseif ($altman_zscore_nonman > 2.6)
										  	$state='label-success';
									?>
									
									<span class="label <?php print($state)?>"><?php print($altman_zscore_nonman)?>	</span>
									
		  						</td>
		  						</tr>
		  						
		  					</table>
		  					
		  					
		    						
		  				</div>
	  				</div>
	  				
				-->
				
				<?php write_log ("quote.php","7")?>
	  				
	  				<div class="card card-default">
	  					<div class="card-header">
							Price Valuation
						</div>
						<div class="card-body">
							<table class="table table-borderless table-sm">
							<tr>
								<?php 
									if ($valuation['ratio'] <= 25)
							 			$label_color='badge-danger';
						  	  		elseif ($valuation['ratio'] <= 50)
						  				$label_color='badge-warning';
						  	  		elseif ($valuation['ratio'] <= 100)
						  				$label_color='badge-info';
						  	  		elseif ($valuation['ratio'] > 100)
						  				$label_color='badge-success';
					 				
									 $ratio=$valuation['ratio'];
					 			?>
								<td width="80%">
									<a data-toggle="modal" href="#RelativeSectorModal" >Relative to Sector</a>  <?php $valuation['ratio']?>
								</td>	
		  						<td style="text-align:right">
		  							<span data-toggle="tooltip" title="<?php print($ratio)?>%" class="badge <?php print($label_color)?>"><?php print($valuation["value"])?></span>
								</td>
							</tr>
							<tr>
								
								<?php 
									if ($industry_valuation['ratio'] <= 25)
							 			$label_color='badge-danger';
						  	  		elseif ($industry_valuation['ratio'] <= 50)
						  				$label_color='badge-warning';
						  	  		elseif ($industry_valuation['ratio'] <= 100)
						  				$label_color='badge-info';
						  	  		elseif ($industry_valuation['ratio'] > 100)
						  				$label_color='badge-success';
					 				
									 $ratio=$industry_valuation['ratio'];
					 			?>
								<td width="80%">
									<a data-toggle="modal" href="#RelativeIndustryModal" >Relative to Industry</a>  <?php $industry_valuation['ratio']?>
								</td>	
		  						<td style="text-align:right">
		  							<span data-toggle="tooltip" title="<?php print($ratio)?>%" class="badge <?php print($label_color)?>"><?php print($industry_valuation["value"])?></span>
								</td>
							</tr>
							</table>
	  					</div>
	  				</div>
	  				
					  <?php write_log ("quote.php","8")?>
	  				
	  				
				</div>
			</div>
		</div>
		</br>
		
		<!-- Business Profile, News and Ratios  --> 
		
		<div class="container">
		
			<div class="row">
			
				<ul class="nav nav-tabs" id="myTab" role="tabList">
				    <li class="nav-item"><a class="nav-link active" id="profile-tab" data-toggle="tab" role="tab" aria-controls="profile" aria-selected="true" href="#profile">Profile</a></li>
					<li class="nav-item"><a class="nav-link" data-toggle="tab" role="tab" href="#news">News</a></li>
					<li class="nav-item"><a class="nav-link" data-toggle="tab" role="tab" href="#statistics">Ratios</a></li>
				<!--	<li role="presentation" ><a data-toggle="tab" href="#financials">Financial Statements</a></li>-->
		   		</ul>
		   		
	    		<div class="tab-content">
	    		
   	 				<div class="tab-pane active" id="profile" role="tab-card" aria-labelledby="profile-tab">
   	 				<?php write_log ("quote.php","8")?>
   	 					<?php $profile=get_profile($symbol); ?>
   	 					<div class="container">
							<div class="row">
								<div class="col-md-6">
									<table  class="table table-borderless table-sm">
										<tr>
											<td><b>Business Summary</b></td>
										</tr>
										<tr>
												<td><?php print($profile['description']) ?><td>
										</tr>
										
									</table>
									<?php write_log ("quote.php","9")?>
								</div>
								<div class="col-md-6">
									<table  class="table table-borderless table-sm">
										<tr>
											<td><b>Sector</b></td><td><a href="<?php print("sector_companies.php?sector=".$share_info["sector"])?>"><?php print($share_info["sector"])?></td>
										</tr>
										<tr>
											<td><b>Industry Group</b></td><td><?php print($share_info["industry_group"])?></td>
										</tr>
										<tr>
											<td><b>Industry</b></td><td><?php print($share_info["industry"])?></td>
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
	 				</div>
					
	 				<div class="tab-pane" id="news">  
				<!--	 <?php write_log ("quote.php","10")?>
					 	
					    <//?php $articles=get_articles($symbol);
						foreach($articles as $article):?>
						 	<div class="container">
			      				<div class="row">
			      					<//?php print ($article["description"]); ?>
				      			</div>
			         		</div>
			         	<//?php endforeach?>
						--> 
			      	</div>
			      	<?php write_log ("quote.php","11")?>
					<div class="tab-pane" id="statistics" >
						<div class="container">
						
							<?php $i=0; foreach ($quote as $category_indicator): ?>
							
								<?php if (($i % 2) == 0): ?>
									<div class="row">
				  				<?php endif?>	
				  				
				  				<?php if ($category_indicator["category"]["name"]!='other'): ?>
								
										<div class="col-md-6">
											<div class="card card-default">
							  					<div class="card-header"><?= $category_indicator["category"]["description"]?></div>
							  					<div class="card-body">
							  						<table class="table table-striped table-bordered table-condensed">
											    
														<thead>
														<tr><th width="20%"></th><th width="20%">Share</th><th width="20%">Sector</th><th width="20%">Market</th ><th width="20%">Rank</th></tr>
														</thead>
														<tbody>
														<?php foreach ($category_indicator["indicators"] as $indicator): ?>
															
															<tr><td width="20%"><?php print($indicator["description"]);?></td><td width="20%"> <?php print ($indicator["value"] ); ?> </td><td width="20%"><?php print($indicator["sector_average"]);?></td>
																
																	<td width="20%">
																		<?php print($indicator["market_average"]);?>
																	</td>
																	<td width="20%">	
																		<div class="progress">
																			<?php if ($indicator["percentile"] <= 25)
																			 	$progress_bar='bg-danger';
																		  	  elseif ($indicator["percentile"] <= 50)
																		  		$progress_bar='bg-warning';
																		  	  elseif ($indicator["percentile"] <= 75)
																		  		$progress_bar='bg-info';
																		  	  elseif ($indicator["percentile"] <= 100)
																		  		$progress_bar='bg-success';
																	 		?>
																			<div class="progress-bar <?php print ($progress_bar); ?>" role="progressbar" aria-valuenow="<?php print($indicator["percentile"]);?>" aria-valuemin="0" aria-valuemax="100" style="width:<?php print($indicator["percentile"]);?>%"><?php print($indicator["percentile"]);?>
																			</div>
																		</div>
																		
																	</td>
														
															</tr>
														<?php endforeach?>
														</tbody>
												
							                 		</table>
							                 	</div>
											</div>
										</div>
										
								<?php endif?>
								
								<?php if (($i % 2) == 1): ?>
									</div>
				  				<?php endif?>	
								
						<?php $i++; endforeach; ?>
						
						<?php if (($i % 2) == 1): ?>
									</div>
				  		<?php endif?>	
					</div>	
			      </div>
				  <?php write_log ("quote.php","12")?>
		<div class="tab-pane" id="financials" >
			<ul class="nav nav-pills">
				<li class="active"><a data-toggle="pill" href="#incomestatement">Income Statement</a></li>
				<li><a data-toggle="pill" href="#balancesheet">Balance Sheet</a></li>
				<li><a data-toggle="pill" href="#cashflowstatement">Cash Flow Statement</a></li>
			</ul>
			<div class="tab-content">
				<div class="tab-pane active" id="incomestatement">
						<table class="table table-striped table-bordered">
							<tr><td>Numbers in millions</td></tr>
							<tr><td></td><?php for ($i=0; $i<count($incomestatement['periods']);$i++): ?><td> <strong><?php print($incomestatement['periods'][$i]["end_date"])?></strong></td><?php endfor?></tr>
							<?php foreach ($incomestatement["period_items"] as $period_item ): ?>
								<tr><td><?php print($period_item['description'])?></td><?php for ($i=0; $i<count($period_item['values']);$i++): ?><td><?php print($period_item['values'][$i])?></td><?php endfor?></tr>
							<?php endforeach?>
							<tr><td></td></tr>
						 </table>
				</div>
				<div class="tab-pane" id="balancesheet">
						<table class="table table-striped table-bordered">
							<tr><td>Numbers in millions</td></tr>
							<tr><td></td><?php for ($i=0; $i<count($balancesheet['periods']);$i++): ?><td> <strong><?php print($balancesheet['periods'][$i]["end_date"])?></strong></td><?php endfor?></tr>
							<?php foreach ($balancesheet["period_items"] as $period_item ): ?>
								<tr><td><?php  print($period_item['description'])?></td><?php for ($i=0; $i<count($period_item['values']);$i++): ?><td><?php print($period_item['values'][$i])?></td><?php endfor?></tr>
							<?php endforeach?>
							<tr><td></td></tr>
						 </table>
				</div>
				<div class="tab-pane" id="cashflowstatement">
						<table class="table table-striped table-bordered">
							<tr><td>Numbers in millions</td></tr>
							<tr><td></td><?php for ($i=0; $i<count($cashflowstatement['periods']);$i++): ?><td> <strong><?php print($cashflowstatement['periods'][$i]["end_date"])?></strong></td><?php endfor?></tr>
							<?php foreach ($cashflowstatement["period_items"] as $period_item ): ?>
								<tr><td><?php  print($period_item['description'])?></td><?php for ($i=0; $i<count($period_item['values']);$i++): ?><td><?php print($period_item['values'][$i])?></td><?php endfor?></tr>
							<?php endforeach?>
							<tr><td></td></tr>
						 </table>
				</div>
			</div>
		</div>
		<?php write_log ("quote.php","13")?>
	 	<div class="tab-pane" id="ratings" >
	 	
	 		<div class="container">	
	 	
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
		</div>
		
	</div>
	<?php write_log ("quote.php","14")?>

		<?php if (isset($screen_id)): ?>
			<FORM action="screen_list.php" METHOD="POST" NAME="myForm">
			<INPUT TYPE="HIDDEN" NAME="btnRun" VALUE="Y">
			<INPUT TYPE="hidden" NAME="screen" VALUE=<?=$screen_id?>>
			<A HREF="#" onClick="document.myForm.submit();return false">Back</A>
			</FORM>
		<?php else: ?>
			<a href="javascript:history.go(-1);">Back</a></br>
		<?php endif ?>

	
	<!--  Modals   -->

        <div id="MomentumModal" class="modal fade" tabindex="-1" role="dialog" >
			  <div class="modal-dialog">
			  	
			    <div class="modal-content">
			    	<div class="modal-header">
          				<h4 class="modal-title">Momentum</h4>
        			</div>
					<div class="modal-body">
						<table class="table borderless">
			    
						<thead>
						<tr><th width="25%">Ratio</th><th width="25%">Share</th><th width="25%">Market</th><th width="25%">Rank</th></tr>
						</thead>
						<tbody>
							<?php foreach($momentumranks as $momentumrank): ?>
							<tr>
								<td width="25%"><?php print($momentumrank["description"]);?></td>
								<td width="25%"> <?php print ($momentumrank["share_value"] ); ?> </td>
								<td width="25%"><?php print($momentumrank["market_value"]);?></td>
								<td width="25%">
									<div class="progress">
										<?php if ($momentumrank["percentile"] <= 25)
												 $progress_bar='bg-danger';
											  elseif ($momentumrank["percentile"] <= 50)
											  	$progress_bar='bg-warning';
											  elseif ($momentumrank["percentile"] <= 75)
											  	$progress_bar='bg-info';
											  elseif ($momentumrank["percentile"] <= 100)
											  	$progress_bar='bg-success';
										 ?>
										<div class="progress-bar <?php print ($progress_bar); ?>" role="progressbar" aria-valuenow="<?php print($momentumrank["percentile"]);?>" aria-valuemin="0" aria-valuemax="100" style="width:<?php print($momentumrank["percentile"]);?>%"><?php print($momentumrank["percentile"]);?></div>
									</div>
								</td>
							</tr>
							<?php endforeach?>
						</tbody>
		
                 		</table>
                 </div>
		        <div class="modal-footer">
          			<button type="button" class="btn btn-outline-primary" data-dismiss="modal">Close</button>
        		</div>         		
		 				
			    </div>
			    
			  </div>
		
			</div>

			<?php write_log ("quote.php","15")?>
        <div id="QualityModal" class="modal fade bs-example-modal-lg" tabindex="-1" role="dialog" aria-labelledby="QualityModal" aria-hidden="true">
			  <div class="modal-dialog modal-lg">
			  	
			    <div class="modal-content">
			    	<div class="modal-header">
          				<h4 class="modal-title">Quality</h4>
        			</div>
					<div class="modal-body">
						<table class="table borderless">
			    
						<thead>
						<tr><th width="25%">Ratio</th><th width="25%">Share</th><th width="25%">Market</th><th width="25%">Rank</th></tr>
						</thead>
						<tbody>
							<?php foreach($qualityranks as $qualityrank): ?>
							<tr>
								<td width="25%"><?php print($qualityrank["description"]);?></td>
								<td width="25%"> <?php print ($qualityrank["share_value"] ); ?> </td>
								<td width="25%"><?php print($qualityrank["market_value"]);?></td>
								<td width="25%">
									<div class="progress">
										<?php if ($qualityrank["percentile"] <= 25)
												 $progress_bar='bg-danger';
											  elseif ($qualityrank["percentile"] <= 50)
											  	$progress_bar='bg-warning';
											  elseif ($qualityrank["percentile"] <= 75)
											  	$progress_bar='bg-info';
											  elseif ($qualityrank["percentile"] <= 100)
											  	$progress_bar='bg-success';
										 ?>
										<div class="progress-bar <?php print ($progress_bar); ?>" role="progressbar" aria-valuenow="<?php print($qualityrank["percentile"]);?>" aria-valuemin="0" aria-valuemax="100" style="width:<?php print($qualityrank["percentile"]);?>%"><?php print($qualityrank["percentile"]);?></div>
									</d
							</tr>
							<?php endforeach?>
						</tbody>
		
                 		</table>
                 </div>
                 
                 
		        <div class="modal-footer">
          			<button type="button" class="btn btn-outline-primary" data-dismiss="modal">Close</button>
        		</div>         		
		 				
			    </div>
			    
			  </div>
		
			</div>
			
			<?php write_log ("quote.php","16")?>
			
			
			<div id="ValueModal" class="modal fade bs-example-modal-lg" tabindex="-1" role="dialog" aria-labelledby="ValueModal" aria-hidden="true">
			  <div class="modal-dialog modal-lg">
			  	
			    <div class="modal-content">
			    	<div class="modal-header">
          				<h4 class="modal-title">Value</h4>
        			</div>
					<div class="modal-body">
						<table class="table borderless">
			    
						<thead>
						<tr><th width="25%">Ratio</th><th width="25%">Share</th><th width="25%">Market</th><th width="25%">Rank</th></tr>
						</thead>
						<tbody>
							<?php foreach($valueranks as $valuerank): ?>
							<tr>
								<td width="25%"><?php print($valuerank["description"]);?></td>
								<td width="25%"> <?php print ($valuerank["share_value"] ); ?> </td>
								<td width="25%"><?php print($valuerank["market_value"]);?></td>
								<td width="25%">
									<div class="progress">
										<?php if ($valuerank["percentile"] <= 25)
												 $progress_bar='bg-danger';
											  elseif ($valuerank["percentile"] <= 50)
											  	$progress_bar='bg-warning';
											  elseif ($valuerank["percentile"] <= 75)
											  	$progress_bar='bg-info';
											  elseif ($valuerank["percentile"] <= 100)
											  	$progress_bar='bg-success';
										 ?>
										<div class="progress-bar <?php print ($progress_bar); ?>" role="progressbar" aria-valuenow="<?php print($valuerank["percentile"]);?>" aria-valuemin="0" aria-valuemax="100" style="width:<?php print($valuerank["percentile"]);?>%"><?php print($valuerank["percentile"]);?></div>
									</div>
								</td>
							</tr>
							<?php endforeach?>
						</tbody>
		
                 		</table>
                  </div>
		        <div class="modal-footer">
          			<button type="button" class="btn btn-outline-primary" data-dismiss="modal">Close</button>
        		</div>         		
		 				
			    </div>
			    
			  </div>
		
			</div>
                 
			<?php write_log ("quote.php","17")?>
			
         <div id="RelativeSectorModal" class="modal fade bs-example-modal-lg" tabindex="-1" role="dialog" aria-labelledby="RelativeSectorModal" aria-hidden="true">
			  <div class="modal-dialog modal-lg">
			  	
			    <div class="modal-content">
			    	<div class="modal-header">
          				<h4 class="modal-title">Relative to Sector</h4>
        			</div>
					<div class="modal-body">
						<table class="table borderless">
			    
						<th>Ratio</th><th>Share</th><th>Sector</th><th>Price</th>
						<tbody>
							<?php foreach($relative_sector_valuations as $sector_valuation): ?>
							<tr>
								<td width="25%"><?php print($sector_valuation["indicator"]);?></td>
								<td width="25%"> <?php print ($sector_valuation["share_stat"] ); ?> </td>
								<td width="25%"> <?php print ($sector_valuation["sector_stat"] ); ?> </td>
								<td width="25%"> <?php print ($sector_valuation["value"] ); ?> </td>
							</tr>
							
							<?php endforeach?>
							<tr>
								<td width="25%"><h5>Average</h5></td>
								<td width="25%">  </td>
								<td width="25%">  </td>
								<td width="25%"> <h5><?php print ($valuation["value"] ); ?></h5> </td>
							</tr>
						</tbody>
		
                 		</table>
                 </div>
		        <div class="modal-footer">
          			<button type="button" class="btn btn-outline-primary" data-dismiss="modal">Close</button>
        		</div>         		
		 				
			    </div>
			    
			  </div>
		
			</div>
			<?php write_log ("quote.php","18")?>
         <div id="RelativeIndustryModal" class="modal fade bs-example-modal-lg" tabindex="-1" role="dialog" aria-labelledby="RelativeIndustryModal" aria-hidden="true">
			  <div class="modal-dialog modal-lg">
			  	
			    <div class="modal-content">
			    	<div class="modal-header">
          				<h4 class="modal-title">Relative to Industry</h4>
        			</div>
					<div class="modal-body">
						<table class="table borderless">
			    
						<th>Ratio</th><th>Share</th><th>Industry</th><th>Price</th>
						<tbody>
							<?php foreach($relative_industry_valuations as $valuation): ?>
							<tr>
								<td width="25%"><?php print($valuation["indicator"]);?></td>
								<td width="25%"> <?php print ($valuation["share_stat"] ); ?> </td>
								<td width="25%"> <?php print ($valuation["industry_stat"] ); ?> </td>
								<td width="25%"> <?php print ($valuation["value"] ); ?> </td>
							</tr>
							
							<?php endforeach?>
							<tr>
								<td width="25%"><h5>Average</h5></td>
								<td width="25%">  </td>
								<td width="25%">  </td>
								<td width="25%"> <h5><?php print ($industry_valuation["value"] ); ?></h5> </td>
							</tr>
						</tbody>
		
                 		</table>
                 </div>
		        <div class="modal-footer">
          			<button type="button" class="btn btn-outline-primary" data-dismiss="modal">Close</button>
        		</div>         		
		 				
			    </div>
			    
			  </div>
		
			</div>

			<?php write_log ("quote.php","19")?>
			<div id="PiotroskiModal" class="modal fade bs-example-modal-lg" tabindex="-1" role="dialog" aria-labelledby="PiotroskiModal" aria-hidden="true">
			  <div class="modal-dialog modal-lg">
			  	
			    <div class="modal-content">
			    	<div class="modal-header">
          				<h4 class="modal-title">Piotroski F-Score Criteria</h4>
        			</div>
					<div class="modal-body">
						The F-Score was designed by Joseph Piotroski, a professor in accounting at Stanford University, and is used to identify companies for which the prospects are improving. It's the sum of 9 binary scores based on profitability, funding and operational efficiency. It looks at simple things such as: 'has the company made more profit compared to last year?' (+1 point) but also: 'is the company cooking the books by adjusting accruals?' (0 points). By using 9 points he was able to get enough signals to determine whether the company is really improving or not.
						<i>MFIE Capital</i>. Retrieved 12:17, September 11, 2016, from <a class="external free" href="https://www.valuesignals.com/Glossary/Details/Piotroski_F_Score">https://www.valuesignals.com/Glossary/Details/Piotroski_F_Score</a>
						<table class="table borderless">
			    
						
						<tbody>
							<?php foreach($piotroski_variables as $variable): ?>
								
								<?php if ($variable["value"] == 1){
										$label='label-success';
										$label_text='PASS';
								}
									  else{
									  	$label='label-danger';	
									    $label_text='FAIL ';
									  }
								?>

							<tr>
								<td width="90%"> <?php print ($variable["variable"] ); ?> </td>
								<td width="10%"><span class="label <?= $label ?>"><?php print ($label_text); ?> </span> </td>
								
							</tr>
							
							<?php endforeach?>
							
						</tbody>
		
                 		</table>
                 </div>
		        <div class="modal-footer">
          			<button type="button" class="btn btn-outline-primary" data-dismiss="modal">Close</button>
        		</div>         		
		 				
			    </div>
			    
			  </div>
			  
			</div>
			<?php write_log ("quote.php","20")?>
			<div id="AltmanModal" class="modal fade bs-example-modal-lg" tabindex="-1" role="dialog" aria-labelledby="AltmanModal" aria-hidden="true">
			  <div class="modal-dialog modal-lg">
			  	
			    <div class="modal-content">
			    	<div class="modal-header">
          				<h4 class="modal-title">Altman Z-Score</h4>
        			</div>
					<div class="modal-body">
						<p>The Z-score formula for predicting bankruptcy was published in 1968 by Edward I. Altman, who was, at the time, an Assistant Professor of Finance at New York University. The formula may be used to predict the probability that a firm will go into bankruptcy within two years. Z-scores are used to predict corporate defaults and an easy-to-calculate control measure for the financial distress status of companies in academic studies. The Z-score uses multiple corporate income and balance sheet values to measure the financial health of a company.
						<i>Wikipedia, The Free Encyclopedia</i>. Retrieved 12:17, September 11, 2016, from <a class="external free" href="https://en.wikipedia.org/w/index.php?title=Altman_Z-score&amp;oldid=737490093">https://en.wikipedia.org/w/index.php?title=Altman_Z-score&amp;oldid=737490093</a>
						</p>
						<p><b>Z' Score bankruptcy Model:</b></p>
						<table class="table borderless">
			    
						
						<tbody>
							<?php foreach($altman_variables as $variable): ?>

							<tr>
								<td width="90%"> <?php print ($variable["variable"] ); ?> </td>
								<td width="10%"><?php print ($variable["value"]); ?>  </td>
								
							</tr>
							
							<?php endforeach?>
							
						</tbody>
		
                 		</table>
                 		
                 		<p>Z' = 0.717*X1 + 0.847*X2 + 3.107*X3 + 0.420*X4 + 0.998*X5 = <?php print($altman_zscore) ?> </p>
                 		<p><b>Zones of Discrimination:</b></p>
                 		<p>Z' &gt; 2.9 - Safe Zone</p>
                 		<p>1.23 &lt; Z' &lt; 2.9 - Grey Zone</p>
                 		<p>Z' &lt; 1.23 - Distress Zone</p>
                 		
                 </div>
		        <div class="modal-footer">
          			<button type="button" class="btn btn-outline-primary" data-dismiss="modal">Close</button>
        		</div>         		
		 				
			    </div>
			    
			  </div>
			  
			</div>
			
			<?php write_log ("quote.php","21")?>
			
			<div id="Altman2Modal" class="modal fade bs-example-modal-lg" tabindex="-1" role="dialog" aria-labelledby="Altman2Modal" aria-hidden="true">
			  <div class="modal-dialog modal-lg">
			  	
			    <div class="modal-content">
			    	<div class="modal-header">
          				<h4 class="modal-title">Altman Z-Score (non manufacturing)</h4>
        			</div>
					<div class="modal-body">
						<p>The Z-score formula for predicting bankruptcy was published in 1968 by Edward I. Altman, who was, at the time, an Assistant Professor of Finance at New York University. The formula may be used to predict the probability that a firm will go into bankruptcy within two years. Z-scores are used to predict corporate defaults and an easy-to-calculate control measure for the financial distress status of companies in academic studies. The Z-score uses multiple corporate income and balance sheet values to measure the financial health of a company.
						<i>Wikipedia, The Free Encyclopedia</i>. Retrieved 12:17, September 11, 2016, from <a class="external free" href="https://en.wikipedia.org/w/index.php?title=Altman_Z-score&amp;oldid=737490093">https://en.wikipedia.org/w/index.php?title=Altman_Z-score&amp;oldid=737490093</a>
						</p>
						<p><b>Z' Score bankruptcy Model:</b></p>
						<table class="table borderless">
			    
						
						<tbody>
							<?php foreach($altman_nonman_variables as $variable): ?>

							<tr>
								<td width="90%"> <?php print ($variable["variable"] ); ?> </td>
								<td width="10%"><?php print ($variable["value"]); ?>  </td>
								
							</tr>
							
							<?php endforeach?>
							
						</tbody>
		
                 		</table>
                 		
                 		<p>Z = 6.56*X1 + 3.26*X2 + 6.72*X3 + 1.05*X4 = <?php print($altman_zscore_nonman) ?> </p>
                 		<p><b>Zones of Discrimination:</b></p>
                 		<p>Z' &gt; 2.6 - Safe Zone</p>
                 		<p>1.1 &lt; Z' &lt; 2.6 - Grey Zone</p>
                 		<p>Z' &lt; 1.1 - Distress Zone</p>
                 		
                 </div>
		        <div class="modal-footer">
          			<button type="button" class="btn btn-outline-primary" data-dismiss="modal">Close</button>
        		</div>         		
		 				
				<?php write_log ("quote.php","22")?>
