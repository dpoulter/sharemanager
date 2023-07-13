<?php

    /**
     * strategies.php
     *
     * Implement Share Strategies.
     */

    require_once("constants.php");
	 include("share_screen.php");
	
		
		
	//Get Historical price
	function get_historical_price($symbol,$date){
		$price=query("select price from historical_prices where date=? and symbol=?",$date, $symbol);
		if (count($price)>0)
			return $price[0]["price"];
		else
			return null;
	}
	
    /**
     * Backing Winners Back test.
     */
	 
      function backtest($start_date, $end_date,$super_purch_gain,$mini_purch_gain,$super_gain,$super_loss_1,$super_loss_2,$mini_loss_1,$mini_loss_2,$norm_purch_loss,$norm_peak_loss){
	   
	   // write_log("backing_winners","Parameters: start_date=$start_date");
		
		//$shares=query("SELECT symbol FROM  purchases where symbol='BKG.L' GROUP BY symbol"); 	 
		
		//Select shares from screen Monsters of Momentum
		$screen_id=1;
		$date=$start_date;
		$shares=build_screen($screen_id,$date);
		  
		foreach ($shares as $share){
			$share=$share['symbol'];
			//delete all records 
			query("delete from strategy_backtest where strategy_id=?",1);
			//initialize
			$status='1.0';
			$classification='NORM';
			//Get price of share at start date or earliest date after start date
			$adj_start_date=query("select min(date) start_date from historical_prices where date>=? and symbol=?",$start_date,$share);
			$adj_start_date=$adj_start_date[0]['start_date'];
			//write_log("backing_winners","share=$share,start_date=$adj_start_date");
			$price_paid=get_historical_price($share,$adj_start_date);
			//write_log("backing_winners","share=$share, price_paid=$price_paid");
			$peak_price=$price_paid;
			//$purchase_date=$start_date;
			
			//for each day between start and end date
			for ($date=$adj_start_date;!empty($adj_start_date)&&(strtotime($date)<=strtotime($end_date))&&($status!='1.2')&&($status!='2.2');$date=date("Y-m-d",strtotime("+1 day",strtotime($date)))){
				
					/***************************************************************************************/
					/*  Share Classification                                                                 
					/***************************************************************************************/
					//go through each share with status 0 (not classified)
					$current_price = get_historical_price($share,$date);
					
						//Get peak price
						//$peak_share_price=query("select max(price) peak_price from historical_prices where symbol=? and date >= ? and date <= ?",$share,$purchase_date,$date);
						//$peak_price=$peak_share_price[0]["peak_price"];
						if (empty($peak_price))
							$peak_price=$current_price;
						elseif ($current_price>$peak_price)
							$peak_price=$current_price;
						
						//write_log("backing_winners.php","1. Symbol=$share,  date=$date, current_price =$current_price ,price_paid=$price_paid,peak_price=$peak_price,status=$status");
						//Calculate gains and losses
						if (!empty($current_price)&&!empty($price_paid)&&!empty($peak_price)){
							$purch_gainloss_percent = round(($current_price - $price_paid)/$price_paid,2); 
							$peak_gainloss_percent = round(($current_price - $peak_price)/$peak_price,2);
							//write_log("backing_winners.php","2. purch_gainloss_percent=$purch_gainloss_percent ,peak_gainloss_percent=$peak_gainloss_percent,status=$status");
							//determine classification based on purchase gain 
							if ($purch_gainloss_percent>=$super_purch_gain){
								$status='1.0';
								$classification='SUPER';
							}
							elseif (($classification=='MINI'||$classification=='NORM')&&$purch_gainloss_percent>=$mini_purch_gain){
								$status='1.0';
								$classification='MINI';
							}
							
				
							/******************************************************/
							/* Set Buy/Sell Flags
							/******************************************************/
							//Get superstar shares
							if ($classification=='SUPER'){
								if ($status=='1.0' && $purch_gainloss_percent>=$super_gain){
										$buy_sell_flag='BUY';
										$status='2.0';
										
								}		
								elseif ($status=='2.0' && $peak_gainloss_percent<=$super_loss_1){
										$status='2.1';
										$buy_sell_flag='SELL';
										//$status_date=date();
								}
								elseif ($status=='2.1' && $peak_gainloss_percent<=$super_loss_2){
										$status='2.2';
										$buy_sell_flag='SELL';
										
								}	
								else $buy_sell_flag='HOLD';
							}
							//Get Ministar shares
							if ($classification=='MINI'){
								if ($status=='1.0' && $peak_gainloss_percent<=$mini_loss_1){
										$status='1.1';
										$buy_sell_flag='SELL';
										
								}
								elseif ($status=='1.1' && $peak_gainloss_percent<=$mini_loss_2){
										$status='1.2';
										$buy_sell_flag='SELL';
										
								}
								else $buy_sell_flag='HOLD';
							}
							if ($classification=='NORM'){
								//Get Normal shares
								if ($status=='1.0' && ($purch_gainloss_percent<=$norm_purch_loss||$peak_gainloss_percent<=$norm_peak_loss)){
											$status='1.2';
											$buy_sell_flag='SELL';
											
										
								}
								else $buy_sell_flag='HOLD';
							}
							//write_log("backing_winners.php","3. symbol=".$share." current_price=$current_price, date=$date, status=$status");
							
							//insert into table
							query("insert into strategy_backtest (symbol, date,price,status,buy_sell_flag,classification,peak_gainloss_percent,purch_gainloss_percent,strategy_id) values (?,?,?,?,?,?,?,?,?)",$share,$date,$current_price,$status,$buy_sell_flag,$classification,$peak_gainloss_percent,$purch_gainloss_percent,1);

						}
										}
		
		}
	//calculate Benchmark Daily return
	$row=query("select price from historical_prices hp where symbol='ISF.L' and date=(select min(date) from historical_prices hp1 where hp1.symbol=hp.symbol and hp1.date>=?)",$start_date);
	$start_date_price=$row[0]['price'];
	//write_log('backing_winners','Benchmark Start Date Price='.$start_date_price);
	
	$row=query("select price from historical_prices hp where symbol='ISF.L' and date=(select max(date) from historical_prices hp1 where hp1.symbol=hp.symbol and hp1.date<=?)",$end_date);
	$end_date_price=$row[0]['price'];
	//write_log('backing_winners','Benchmark End Date Price='.$end_date_price);	
	
	$benchmark = ($end_date_price-$start_date_price)/$start_date_price;
	
	//write_log('backing_winners','Benchmark='.$benchmark);
	
	
	//calculate sharpe ratio
	$rows=query("select symbol,price from strategy_backtest where strategy_id=? order by symbol,date",1);
	$daily_return=[];
	$j=0;
	for ($i=0;($i+1)<count($rows);$i++){
		if ($i==0)
			$symbol=$rows[$i+1]["symbol"];
		elseif ($symbol!=$rows[$i+1]["symbol"]){
			//next symbol
			$symbol=$rows[$i+1]["symbol"];
			//first row of new symbol so skip row
			$i++;
		}	
		
		//calculate daily return risk adjusted
		//$daily_return[$j]=(($rows[$i+1]["price"] - $rows[$i]["price"])/$rows[$i]["price"])-0.04/252;
		$daily_return[$j]=(($rows[$i+1]["price"] - $rows[$i]["price"])/$rows[$i]["price"])-$benchmark/252;
		//write_log('backing_winners','Daily Return day $j: '.$daily_return[$j]);
		$j++;
	}
	
	//Get Average Daily Risk Adjusted Return
	$avg_daily_ret = average($daily_return);
	//write_log('backing_winners','Average Daily Risk Adjusted Return='.$avg_daily_ret);
	
	//Get Standard Deviation
	$std_dev=standard_deviation($daily_return);
	//write_log('backing_winners','Standard Deviation='.$std_dev);
	
	//Calculate Sharpe Ratio
	$sharpe_ratio=sqrt(252)*$avg_daily_ret/$std_dev;	
	//print_r( "Sharpe Ratio=$sharpe_ratio \r\n");
	
	//insert result into table
	query("insert into backtest_results (start_date,end_date, parameter1_name, parameter1_value,parameter2_name,parameter2_value,parameter3_name,parameter3_value,parameter4_name,parameter4_value,parameter5_name,parameter5_value,parameter6_name,parameter6_value,parameter7_name,parameter7_value,parameter8_name,parameter8_value,parameter9_name,parameter9_value,sharpe_ratio,avg_daily_return,standard_deviation) values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",$start_date,$end_date,'super_purch_gain',$super_purch_gain,'mini_purch_gain',$mini_purch_gain,'super_gain',$super_gain,'super_loss_1',$super_loss_1,'super_loss_2',$super_loss_2,'mini_loss_1',$mini_loss_1,'mini_loss_2',$mini_loss_2,'norm_purch_loss',$norm_purch_loss,'norm_peak_loss',$norm_peak_loss,$sharpe_ratio,$avg_daily_ret,$std_dev);

			
	}
			
	function average($arr)
{
    if (!count($arr)) return 0;

    $sum = 0;
    for ($i = 0; $i < count($arr); $i++)
    {
        $sum += $arr[$i];
    }
	 //write_log('average','Sum='.$sum." Count=".count($arr));
    return $sum / count($arr);
}

// Function to calculate square of value - mean
function sd_square($x, $mean) { return pow($x - $mean,2); }

// Function to calculate standard deviation (uses sd_square)    
function standard_deviation($array) {
    
// square root of sum of squares devided by N-1
return sqrt(array_sum(array_map("sd_square", $array, array_fill(0,count($array), (array_sum($array) / count($array)) ) ) ) / (count($array)-1) );
}

function run_backtest ($start_date, $end_date,$super_purch_gain,$mini_purch_gain,$super_gain,$super_loss_1,	$super_loss_2,	$mini_loss_1,		$mini_loss_2,		$norm_purch_loss,		$norm_peak_loss){
        /**************************************************************************************
		Run test
       ***************************************************************************************/		
	   //Get Start Date and End date parameters from Arguments passed in
		//$start_date=$argv[1];
	   // $end_date=$argv[2];
	
//		echo "start_date=$start_date \r\n";
//		echo "end_date=$end_date \r\n";
	
			//backtest($share["symbol"],$share["start_date"],$share["end_date"],$super_purch_gain,$mini_purch_gain,$super_gain,$super_loss_1,$super_loss_2,$mini_loss_1,$mini_loss_2,$norm_purch_loss,$norm_peak_loss);
		
		//set parameters test 1. Sharpe Ratio=-0.38745992123426
		//echo "Test1 \r\n";
	
	 	backtest($start_date,$end_date,$super_purch_gain,$mini_purch_gain,$super_gain,$super_loss_1,$super_loss_2,$mini_loss_1,$mini_loss_2,$norm_purch_loss,$norm_peak_loss);
}
			
		
?>
