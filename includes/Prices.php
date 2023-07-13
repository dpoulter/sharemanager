<?php
 //require_once('share_functions.php');
 require("../includes/config.php"); 
 
 class Prices {
 	
		  public $graphdates=array();
		  
		  public $graphvalues=array();
		 
 			
		  //Graph stock price between two dates
          public function graph($symbol,$startdate,$enddate) {
          	
				$rows=query("SELECT date, price FROM historical_prices WHERE symbol=? and date between ? and ?",$symbol,
							$startdate->format('Y-m-d'),$enddate->format('Y-m-d'));
          	
				foreach ($rows as $row){
          			array_push($this->graphdates, new DateTime($row['date']));
					array_push($this->graphvalues,$row['price']);
				}
				
				//print_r($this->graphdates);
		
				
		  }
		  
		  //Graph performance for a user
		  public function performance($session_id,$startdate,$enddate){

				$rows=query("SELECT performance_date date, total_profit FROM performance WHERE session_id=? and performance_date between ? and ?",$session_id
							,$startdate->format('Y-m-d'),$enddate->format('Y-m-d'));
          	
				foreach ($rows as $row){
          			array_push($this->graphdates, new DateTime($row['date']));
					array_push($this->graphvalues,$row['total_profit']);
				}
		  	
		  }
		  

		  //Graph Total Holding for a user
		  public function total_holding($session_id,$startdate,$enddate){

				$rows=query("SELECT performance_date date, total_holding FROM performance WHERE session_id=? and performance_date between ? and ?",$session_id
							,$startdate->format('Y-m-d'),$enddate->format('Y-m-d'));
          	
				foreach ($rows as $row){
          			array_push($this->graphdates, new DateTime($row['date']));
					array_push($this->graphvalues,$row['total_holding']);
				}
		  	
		  }
		  
 }

?>