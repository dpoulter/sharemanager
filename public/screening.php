<?php

    // configuration
    require("../includes/config.php"); 
    
    // get share list in top 10 of 3mnth momentum%
    $rows = query("SELECT count(1) * 10/100 top10 FROM price_momentum WHERE 1");	
    // if we found history
        if (count($rows) >= 1){
		$top10 = $rows[0]["top10"];            
		//Get top 10% of symbols in 3mnth momentum
		$threeMnthrows = query("SELECT symbol,3mnth FROM price_momentum WHERE 3mnth>0 order by 3mnth desc limit ?",$top10);
        
           
      }
	//get share list in top 25% of 6mnth momentum
    $rows = query("SELECT count(1) * 25/100 top25 FROM price_momentum WHERE 1");
    // if we found history
        if (count($rows) >= 1){
                $top25 = $rows[0]["top25"];
                //Get top 25% of symbols in 6mnth momentum
                $sixMnthrows = query("SELECT symbol,6mnth FROM price_momentum WHERE 6mnth>0 order by 6mnth desc limit ?",$top25);
      }
	//Get share list in top 50% of 12mnth momentum
      $rows = query("SELECT count(1) * 50/100 top50 FROM price_momentum WHERE 1");
    // if we found history
        if (count($rows) >= 1){
                $top50 = $rows[0]["top50"];
                //Get top 50% of symbols in 12mnth momentum
                $twelveMnthrows = query("SELECT symbol,12mnth FROM price_momentum WHERE 12mnth>0 order by 12mnth desc limit ?",$top50);
      }

	//Get Price Momentum winners
	$list=get_momentum_screen($threeMnthrows,$sixMnthrows,$twelveMnthrows);

	//Get Trend winners using Momentum winners
	$trend_list=get_trend_screen($list);

       //Get Earnings Growth
	$earnings_growth=get_earnings_growth($trend_list);

	//Render page
    render("screen.php", ["title" => "Share Screen","MomentumList"=>$list,"TrendList"=>$trend_list,"EarningsGrowth"=>$earnings_growth]);

?>
