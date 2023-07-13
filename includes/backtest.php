<?php

    /**
     * backtest.php
     *
     * Perform a backtest
     */

    require_once("constants.php");
    include("share_screen.php");
	//include("functions.php");
	
class DateTimeEnhanced extends DateTime {

    public function returnAdd(DateInterval $interval)
    {
        $dt = clone $this;
        $dt->add($interval);
        return $dt;
    }
   
    public function returnSub(DateInterval $interval)
    {
        $dt = clone $this;
        $dt->sub($interval);
        return $dt;
    }

		
	}	
	//Get Historical price
	function get_historical_price($symbol,$date){
		$price=query("select price from historical_prices where date=? and symbol=?",$date, $symbol);
		if (count($price)>0)
			return $price[0]["price"];
		else
			return null;
	}
	
    /**
     * Main Back test.
     */
	 
    function backtest($start_date, $end_date,$screen_id, $strategy_id,$symbol){
    	
    		$rebalance=new DateInterval('P1M');
	   
    	    write_log("backing_winners","Parameters: start_date=$start_date");
	
		   //delete all records 
			query("delete from strategy_backtest where strategy_id=?",$strategy_id);
			//$dt = new DateTimeEnhanced; # initialize new object
		   //$rebalance_start= new DateTimeEnhanced;
		   
			//Rebalance portfolio once a year
			$rebalance_start=DateTime::createFromFormat('Y-m-d',$start_date);
			//$dt=DateTimeEnhanced::createFromFormat('Y-m-d',$start_date);
			
			//write_log("backing_winners","rebalance_start date=".date_format($rebalance_start,'Y-m-d'));
			
			while($rebalance_start<date_create_from_format('Y-m-d',$end_date)) {
				//write_log("backing_winners","rebalance start date=".date_format($rebalance_start,'Y-m-d'));

				$end=date_create_from_format('Y-m-d',date_format($rebalance_start,'Y-m-d'));
				$rebalance_end=$end->Add($rebalance);

				//write_log("backing_winners","rebalance end date=".date_format($rebalance_end,'Y-m-d'));
			
				if($rebalance_end>date_create_from_format('Y-m-d',$end_date))
					$rebalance_end=date_create_from_format('Y-m-d',$end_date);

				write_log("backing_winners","rebalance start date=".date_format($rebalance_start,'Y-m-d'));
				write_log("backing_winners","rebalance end date=".date_format($rebalance_end,'Y-m-d'));
				
				if (!isset($symbol))			  	
			  		$shares = build_screen($screen_id,date_format($rebalance_start,'Y-m-d'));
			  	else {
					$shares =[];
					array_push($shares,["symbol"=>$symbol]);			  	
			  	} 
			  		
			  	while ((count($shares)==0)&&($rebalance_start<$rebalance_end)) {
			  		
			  		$rebalance_start->add($rebalance);	
			  		
				  	$shares = build_screen($screen_id,date_format($rebalance_start,'Y-m-d'));
				  	
				}
					  	
			  	write_log("backing_winners","Number of Shares = ".count($shares));
			  	
			  	$dt=date_format($rebalance_start,'Y-m-d');
  
			   foreach ($shares as $share){
				 $share=$share['symbol'];
				 write_log("backing_winners","share = $share");
			
				//for each day between start and end date
				for ($date=date_create_from_format('Y-m-d',$dt);!empty($rebalance_start)&&($date<$rebalance_end);$date->add($rebalance)) {
				
					write_log("backing_winners","date = ".date_format($date,'Y-m-d'));
					write_log("backing_winners","rebalance_start = ".date_format($rebalance_start,'Y-m-d'));
					write_log("backing_winners","rebalance_end = ".date_format($rebalance_end,'Y-m-d'));
					
					$current_price = get_historical_price($share,date_format($date,'Y-m-d'));
					
					write_log("backing_winners","current_price = $current_price");
							
					//insert into table
					if (isset($current_price)){
						query("insert into strategy_backtest (symbol, date,price,strategy_id) values (?,?,?,?)",$share,date_format($date,'Y-m-d'),$current_price,$strategy_id);
					}
				}
			}
			
			//Rebalance portfolio once a year
			$rebalance_start=$rebalance_end;			
		}
			
	//calculate Benchmark Daily return
	$rows=query("select symbol,price from strategy_backtest where symbol='ISF.L' order by symbol,date");
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
		
		//calculate daily return 
		$daily_return[$j]=(($rows[$i+1]["price"] - $rows[$i]["price"])/$rows[$i]["price"]);
		$j++;
	}
	
	//Get average daily return of the benchmark
	$benchmark=average($daily_return); 
	print_r( "Benchmark=$benchmark \r\n");
	
	
	//calculate sharpe ratio
	$rows=query("select symbol,price from strategy_backtest where strategy_id=? order by symbol,date",$strategy_id);
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
		$daily_return[$j]=(($rows[$i+1]["price"] - $rows[$i]["price"])/$rows[$i]["price"])-$benchmark;
		$j++;
	}
	// Calculate Sharpe Ratio
	$avg_daily_return=average($daily_return);
	$standard_deviation=standard_deviation($daily_return);
	$sharpe_ratio=sqrt(252)*$avg_daily_return/$standard_deviation;
	
	print_r( "Sharpe Ratio=$sharpe_ratio \r\n");
	
	//insert result into table
	query("insert into backtest_results (start_date,end_date, sharpe_ratio, strategy_id, avg_daily_return, standard_deviation) values (?,?,?,?,?,?)",$start_date,$end_date,$sharpe_ratio,$strategy_id,$avg_daily_return, $standard_deviation);
	
}
			
	function average($arr)
{
    if (!count($arr)) return 0;

    $sum = 0;
    for ($i = 0; $i < count($arr); $i++)
    {
        $sum += $arr[$i];
    }

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
		echo "Test1 \r\n";
	
	 	backtest($start_date,$end_date,$super_purch_gain,$mini_purch_gain,$super_gain,$super_loss_1,$super_loss_2,$mini_loss_1,$mini_loss_2,$norm_purch_loss,$norm_peak_loss);
}
			
		
?>
