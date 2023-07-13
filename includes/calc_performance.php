<?php

    require_once("constants.php");
    include("functions.php");
    include("share_functions.php"); 
	include("portfolio_functions.php"); 
	
	//Set exchange
	$_SESSION["exchange"]='LON';
	
	 // query database for all users
     $user_rows = query("SELECT * FROM users where id=8");

	 foreach ($user_rows as $user) 
	 {
	 	$session_id= $user["id"];
		
		write_log("calc_performance.php","user_id=".$user["id"]);
		
		$current_date=new DateTime();
		
		write_log("calc_performance.php","current_date=".$current_date->format('Y-m-d'));
		
		//Delete existing records
		$result=query('delete from performance where session_id=? and performance_date >= ?',$session_id,$current_date->format('Y-m-d'));
		$result=query('delete from portfolio_performance where session_id=? and as_of_date >=?',$session_id,$current_date->format('Y-m-d'));
		
		
		
		$performance=calc_performance($current_date->format('Y-m-d'),$session_id);
			
		write_log("calc_performance.php","No of records returned by calc_performance=".count($performance));
		
			if (count($performance)>0){
				
				
				
				$insert=query('insert into performance(session_id,performance_date, total_value, total_profit, total_holding, cash) values (?,?,?,?,?,?)'
						,$session_id,$current_date->format('Y-m-d'),$performance['total_value'],$performance['total_profit'],
						$performance['total_value']+$performance['cash'],$performance['cash']);
						
						
				
				//Insert Active Positions
				foreach($performance['active_positions'] as $position){
						
					write_log("calc_performance.php","insert active share=".$position['symbol']);
					
					$insert=query('insert into portfolio_performance(active,as_of_date, commission, dividends, price, price_paid, price_sold,profit, profit_perc,profit_raw, qty_purchased, qty_sold, session_id, symbol, value, value_raw) values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
								,'Y',$current_date->format('Y-m-d')
								,$position['commission'],$position['dividends'],$position['price'],$position['price_paid'],$position['price_sold'],$position['profit'],$position['profit_perc'],
								$position['profit_raw'],$position['qty_purchased'],$position['qty_sold'],$session_id,$position['symbol'],$position['value'],$position['value_raw']);
				}
		
				//Insert Inactive Positions
				foreach($performance['inactive_positions'] as $position){
					
					write_log("calc_performance.php","insert Inactive share=".$position['symbol']);
					
					$insert=query('insert into portfolio_performance(active,as_of_date, commission, dividends, price, price_paid, price_sold,profit, profit_perc,profit_raw, qty_purchased, qty_sold, session_id, symbol, value, value_raw) values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
								,'N',$current_date->format('Y-m-d')
								,$position['commission'],$position['dividends'],$position['price'],$position['price_paid'],$position['price_sold'],$position['profit'],$position['profit_perc'],
								$position['profit_raw'],$position['qty_purchased'],$position['qty_sold'],$session_id,$position['symbol'],$position['value'],$position['value_raw']);
					
				}
			}
	
	}
?>
