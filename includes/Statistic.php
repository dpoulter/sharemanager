<?php
 class Statistic {
 	
          public function insert($symbol,$exchange,$indicator,$date,$value) {
            
            //check if symbol for indicator exists in statistics
			$rows=query("select symbol from statistics where symbol=? and exchange=? and indicator=? and date=?",$symbol,$exchange,$indicator,$date);
			
			//insert record in statistics
			if (count($rows)==0){
              		query("insert into statistics (symbol, exchange, indicator, value,date) values (?,?,?,?,?)",$symbol,$exchange,$indicator,$value,$date);
			}
			//update record in statistics
			else {
              		query("update statistics set value=? where symbol=? and exchange=? and indicator=? and date=?",$value,$symbol,$exchange,$indicator,$date);
			}
          }
        }


?>