<?php
 // configuration
  require_once("../includes/constants.php");
  include("../includes/functions.php");
  //require("../includes/config.php"); 
	include("../includes/share_functions.php");
	
    $asOfDate=date_create_from_format('Y-m-d','2016-02-25');
	
$indicators=query("select name ,if(rank_order='','DESC',rank_order) rank_order from screen_indicators where enabled='Y' and calc_rank='Y'");
    foreach ($indicators as $indicator){
	 $sql1      =   "SELECT symbol,rank, round(100-(rank/@rownum*100)) percentile FROM (".
                      "SELECT @rownum := @rownum + 1 AS rank, value, symbol".
                    " FROM statistics, (SELECT @rownum := 0) r where date=? and indicator=?  and value != 0 ORDER BY value ".$indicator['rank_order'].
                    ") as result ";

        $ranks = query($sql1,date_format($asOfDate,'Y-m-d'),$indicator['name']);
        
        foreach($ranks as $rank){
        		//query("insert into ranks(symbol,indicator,rank,percentile) values(?,?,?,?,?)",$rank['symbol'],$indicator['name'],$rank['rank'],$rank['percentile']);	
				query("update statistics set rank=?, percentile=? where symbol=? and indicator=? and date=? ",$rank['rank'],$rank['percentile'],$rank['symbol'],$indicator['name'],date_format($asOfDate,'Y-m-d'));	
        
        }
        
   }    
    
   //delete existing records of value and momentum scores for todays date
    query("delete from statistics where date=? and indicator in ('value_score','momentum_score')",date_format($asOfDate,'Y-m-d'));	
	
	//Calculate Momentum Rank for each share
		 $sql      =   "SELECT symbol,rank, @rownum,round(100-(rank/@rownum*100)) percentile FROM (
							                     SELECT @rownum := @rownum + 1 AS rank, symbol
							                    FROM (SELECT  symbol, round(sum(if(percentile is null,50,percentile))/3) as perc
FROM statistics r where date=? and indicator in ('3mnth','6mnth','12mnth') 
group by symbol) as stats, (SELECT @rownum := 0) r  ORDER BY perc DESC) as result ";
								
							$scores = query($sql,date_format($asOfDate,'Y-m-d'));
	foreach($scores as $score){
			query("insert into statistics (symbol,date, indicator,value) values (?,?,?,?)",$score['symbol'],date_format($asOfDate,'Y-m-d'),'momentum_score',$score['percentile']);

	}
	
	//Calculate Value Ranking
	
	 $sql      =   "
SELECT symbol,round(100-(rank/@rownum*100)) percentile FROM (
                     SELECT @rownum := @rownum + 1 AS rank, symbol
                    FROM (SELECT  symbol, round(sum(if(percentile is null,50,percentile))/3) as perc
FROM statistics r where date=? and indicator in ('div_yield','pe','price_sales_ttm','price_book_ratio','enterprise_value_to_ebitda') 
group by symbol) as stats, (SELECT @rownum := 0) r ORDER BY perc DESC) as result  ";

	$scores = query($sql,date_format($asOfDate,'Y-m-d'));
	foreach($scores as $score){
			query("insert into statistics (symbol,date, indicator,value) values (?,?,?,?)",$score['symbol'],date_format($asOfDate,'Y-m-d'),'value_score',$score['percentile']);

	}

   

   
?>