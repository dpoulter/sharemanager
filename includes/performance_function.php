<?php
   // configuration
     require_once("constants.php");
     require_once("functions.php");
     require_once("share_functions.php");
	 include("portfolio_functions.php");
	 
	 log_job("performance_function");
   	
   //Main
   		$current_date=new DateTime();
		$start_date=new DateTime();	
		$session_id=8;
		$_SESSION["exchange"]='LON';
		
		$start_date->sub(new DateInterval('P10Y'));
		$current_date->sub(new DateInterval('P1D'));
		

		//Delete existing records
		$result=query('delete from performance where session_id=? and performance_date >= ?',$session_id,$start_date->format('Y-m-d'));
		$result=query('delete from portfolio_performance where session_id=? and as_of_date >=?',$session_id,$start_date->format('Y-m-d'));
		while ($start_date<$current_date){
   				
				
	   		$performance=calc_performance($current_date->format('Y-m-d'),$session_id);
			if ($performance!= null and count($performance)>0){
				
				$insert=query('insert into performance(session_id,performance_date, total_value, total_profit, total_holding, cash) values (?,?,?,?,?,?)'
						,$session_id,$current_date->format('Y-m-d'),$performance['total_value'],$performance['total_profit'],
						$performance['total_value']+$performance['cash'],$performance['cash']);
						
						
				
				//Insert Active Positions
				foreach($performance['active_positions'] as $position){
						
					
					$insert=query('insert into portfolio_performance(active,as_of_date, commission, dividends, price, price_paid, price_sold,profit, profit_perc,profit_raw, qty_purchased, qty_sold, session_id, symbol, value, value_raw) values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
								,'Y',$current_date->format('Y-m-d')
								,$position['commission'],$position['dividends'],$position['price'],$position['price_paid'],$position['price_sold'],$position['profit'],$position['profit_perc'],
								$position['profit_raw'],$position['qty_purchased'],$position['qty_sold'],$session_id,$position['symbol'],$position['value'],$position['value_raw']);
				}
		
				//Insert Inactive Positions
				foreach($performance['inactive_positions'] as $position){
					
					$insert=query('insert into portfolio_performance(active,as_of_date, commission, dividends, price, price_paid, price_sold,profit, profit_perc,profit_raw, qty_purchased, qty_sold, session_id, symbol, value, value_raw) values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
								,'N',$current_date->format('Y-m-d')
								,$position['commission'],$position['dividends'],$position['price'],$position['price_paid'],$position['price_sold'],$position['profit'],$position['profit_perc'],
								$position['profit_raw'],$position['qty_purchased'],$position['qty_sold'],$session_id,$position['symbol'],$position['value'],$position['value_raw']);
					
				}
			}

			$current_date->sub(new DateInterval('P1D'));
		}
			
	
	
	
?>
