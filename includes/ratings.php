<?php
// Ratings

include_once("share_screen.php");
include_once("functions.php");
include_once("share_functions.php");

function momentum_ratings($asOfDate){

	query("update momentum_ratings set number=0 where date=?",$asOfDate);
	
	$screens=[3,4,5,6,7];
	foreach ($screens as $screen){
		write_log("ratings.php","screen=$screen");
		$symbols = build_screen($screen,$asOfDate);
		//print_r("Number returned=".count($symbols));
		foreach ($symbols as $symbol){
			
			$rows=query("select 1 from momentum_ratings where symbol=? and date=?",$symbol['symbol'],$asOfDate);
			if (count($rows)>0) 	{
				//print("updating symbol ".$symbol['symbol']."/n");
				query("update momentum_ratings set number=number+1 where symbol=? and date=?",$symbol['symbol'],$asOfDate);
				}
			else
				query("insert into momentum_ratings (symbol,number,date) values (?,?,?)",$symbol['symbol'],1,$asOfDate);

			//Add Momentum Rating as statistic
			query("delete from statistics where symbol=? and date=? and indicator='momentum_rating'",$symbol['symbol'],$asOfDate);
			query("insert into statistics (date, indicator, symbol, value) (select date,'momentum_rating',symbol,number from momentum_ratings where symbol=? and date=?)",$symbol['symbol'],$asOfDate);

		}
	}
}
//Growth Ratings
function growth_ratings($asOfDate){

	query("update momentum_ratings set growth_rating=0 where date=?",$asOfDate);
	
	$screens=[8,9,10,11,12];
	foreach ($screens as $screen){
		write_log("ratings.php","screen=$screen");
		$symbols = build_screen($screen,$asOfDate);
		//print_r("Number returned=".count($symbols));
		foreach ($symbols as $symbol){
			
			$rows=query("select 1 from momentum_ratings where symbol=? and date=?",$symbol['symbol'],$asOfDate);
			if (count($rows)>0) 	{
				//print("updating symbol ".$symbol['symbol']."/n");
				query("update momentum_ratings set growth_rating=growth_rating+1 where symbol=? and date=?",$symbol['symbol'],$asOfDate);
				}
			else
				query("insert into momentum_ratings (symbol,growth_rating,date) values (?,?,?)",$symbol['symbol'],1,$asOfDate);

				//Add Growth Rating as statistic
			query("delete from statistics where symbol=? and date=? and indicator='growth_rating'",$symbol['symbol'],$asOfDate);
			query("insert into statistics (date, indicator, symbol, value) (select date,'growth_rating',symbol,growth_rating from momentum_ratings where symbol=? and date=?)",$symbol['symbol'],$asOfDate);

		}
	}
	


}

//Value Ratings
function value_ratings($asOfDate){
    //print_r("As of Date=".$asOfDate."/n");
	query("update momentum_ratings set value_rating=0 where date=?",$asOfDate);
	
	$screens=[13,14,15,16,17];
	foreach ($screens as $screen){
		write_log("ratings.php","screen=$screen");
		$symbols = build_screen($screen,$asOfDate);
		//print_r("Number returned=".count($symbols)."/n");
		foreach ($symbols as $symbol){
			write_log("ratings.php","symbol=".$symbol['symbol']." asOfDate=$asOfDate");
			$rows=query("select 1 from momentum_ratings where symbol=? and date=?",$symbol['symbol'],$asOfDate);
			if (count($rows)>0) 	{
				write_log("ratings.php","updating symbol ".$symbol['symbol']."/n");
				query("update momentum_ratings set value_rating=value_rating+1 where symbol=? and date=?",$symbol['symbol'],$asOfDate);
				}
			else {
				write_log("ratings.php","inserting symbol ".$symbol['symbol']."/n");	
				query("insert into momentum_ratings (symbol,value_rating,date) values (?,?,?)",$symbol['symbol'],1,$asOfDate);
			}
				//Add Value Rating as statistic
				query("delete from statistics where symbol=? and date=? and indicator='value_rating'",$symbol['symbol'],$asOfDate);
				query("insert into statistics (date, indicator, symbol, value) (select date,'value_rating',symbol,value_rating from momentum_ratings where symbol=? and date=?)",$symbol['symbol'],$asOfDate);
		
		}
	}
	

}

//Quality Ratings
function quality_ratings($asOfDate){
    //print_r("As of Date=".$asOfDate."/n");
	query("update momentum_ratings set quality_rating=0 where date=?",$asOfDate);
	
	$screens=[18,19,20,21,22];
	foreach ($screens as $screen){
		write_log("ratings.php","screen=$screen");
		$symbols = build_screen($screen,$asOfDate);
		//print_r("Number returned=".count($symbols)."/n");
		foreach ($symbols as $symbol){
			write_log("ratings.php","symbol=".$symbol['symbol']." asOfDate=$asOfDate");
			$rows=query("select 1 from momentum_ratings where symbol=? and date=?",$symbol['symbol'],$asOfDate);
			if (count($rows)>0) 	{
				write_log("ratings.php","updating symbol ".$symbol['symbol']."/n");
				query("update momentum_ratings set quality_rating=quality_rating+1 where symbol=? and date=?",$symbol['symbol'],$asOfDate);
				}
			else {
				write_log("ratings.php","inserting symbol ".$symbol['symbol']."/n");	
				query("insert into momentum_ratings (symbol,quality_rating,date) values (?,?,?)",$symbol['symbol'],1,$asOfDate);
			}
				//Add Value Rating as statistic
				query("delete from statistics where symbol=? and date=? and indicator='quality_rating'",$symbol['symbol'],$asOfDate);
				query("insert into statistics (date, indicator, symbol, value) (select date,'quality_rating',symbol,quality_rating from momentum_ratings where symbol=? and date=?)",$symbol['symbol'],$asOfDate);
		
		}
	}
	

}

//Overall Ratings
function overall_ratings($asOfDate){

	query("update momentum_ratings set overall_rating=(value_rating+growth_rating+number+quality_rating)/4 where date=?",$asOfDate);
	query("delete from statistics where date=? and indicator='overall_rating'",$asOfDate);
	query("insert into statistics (date, indicator, symbol, value) (select date,'overall_rating',symbol,overall_rating from momentum_ratings where date=?)",$asOfDate);
	
	

}


	
//*****************************************************************************************	
//Main
//*****************************************************************************************

//Get As of Date as yesterdays date
$asOfDate=new DateTime();
$asOfDate->sub(new DateInterval('P1D'));
$asOfDate=date_format($asOfDate,'Y-m-d');

write_log("ratings.php","asOfDate=$asOfDate");

momentum_ratings($asOfDate);
growth_ratings($asOfDate);
value_ratings($asOfDate);
quality_ratings($asOfDate);
overall_ratings($asOfDate);
	
?>
