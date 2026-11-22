<?php
	/* The screen builder as it was before industry criteria (includes/share_screen.php
	   at commit 836e593), word for word, each function renamed with
	   _before_industry. tests/tests.php runs every existing screen through
	   this and through build_screen() and expects the same shares in the same
	   order. Used by the tests only. */

		function intersect_list_before_industry($list1,$list2){
		if (count($list1)<1)
			return $list2;
		else {
			$list=array();
			if (!is_null($list1)&&is_array($list1)){
				foreach(($list1) as $item){
				//	print_r($item);
					if(isset($item["symbol"]))
						if (symbol_exists($item["symbol"],$list2)){
							array_push($list,["symbol"=>$item["symbol"]]);
							}
				}
			}
			return $list;	
		}	 
		
	}

	function combine_criteria_lists_before_industry($build_list){
		//write_log( "share_screen.php","count=" . count($build_list));
		if (count($build_list)<1){
			return array();
		}
		elseif (count ($build_list) <2) {
			return $build_list[0];
		}
		else {
		 	$list=array_pop($build_list);
		 	//write_log( "share_screen.php","list count=" . count($list));
//			print_r($list);
//			print_r($build_list);
	           	return intersect_list_before_industry($list,combine_criteria_lists_before_industry($build_list));
		}
	}
	
	
	/* An indicator's rank_order, checked against what it is allowed to be.
	   The column is configuration rather than input, but it is interpolated
	   into an order by, so it is checked rather than trusted - the same check
	   get_statistics.php makes on the same column. */
	function screen_rank_order_before_industry($rank_order,$indicator){
		$rank_order=trim((string)$rank_order);
		if (!preg_match('/^value\s+(ASC|DESC)$/i',$rank_order)){
			write_log("build_screen","unexpected rank_order '".$rank_order."' for "
			          .$indicator.", using value DESC");
			return 'value DESC';
		}
		return $rank_order;
	}

	/* The best N of the shares passed in, by one indicator, in that order.
	
	   A share with no value for the indicator drops out rather than sorting
	   to one end: "the cheapest 26" cannot include a share whose cheapness is
	   unknown. The symbol list is bound rather than interpolated.
	
	   Duplicates are dropped in PHP rather than by filtering on exchange.
	   Some older insert paths in share_functions.php leave statistics.exchange
	   null, so a strict exchange filter would silently return nothing for
	   those indicators; counting a symbol once is what "the first N" needs,
	   and this gets it without assuming every row was written the same way. */
	function top_n_list_before_industry($symbols,$indicator,$rank_order,$n,$date){
		$n=(int)$n;
		if ($n<1||!is_array($symbols)||count($symbols)<1)
			return array();
		$placeholders=array();
		$args=array($indicator,$date);
		foreach($symbols as $symbol){
			if (!isset($symbol["symbol"]))
				continue;
			$placeholders[]='?';
			$args[]=$symbol["symbol"];
		}
		if (!count($placeholders))
			return array();
		/* Ties break on symbol so the same input always gives the same list.
		   Without it the order is whatever the storage engine returns, and a
		   screen that is one share different between two runs on identical
		   data is not a screen anybody can act on. */
		$sql="select s.symbol from statistics s
		       where s.indicator=? and s.date=? and s.value is not null
		         and s.symbol in (".implode(',',$placeholders).")
		       order by ".screen_rank_order_before_industry($rank_order,$indicator).", s.symbol asc";
		$rows=call_user_func_array('query',array_merge(array($sql),$args));
		$list=array();
		$seen=array();
		foreach($rows as $row){
			if (isset($seen[$row["symbol"]]))
				continue;
			$seen[$row["symbol"]]=true;
			array_push($list,["symbol"=>$row["symbol"]]);
			if (count($list)>=$n)
				break;
		}
		write_log("build_screen","top_n ".$indicator." kept ".count($list)." of ".count($symbols));
		return $list;
	}

	//Build Screen and returns the complete sql query for all criteria in the screen
	function build_screen_before_industry($screen_id,$date){
		
		//write_log("build_screen","date=$date");
		
		if (!isset($date)){
			
			//Get As of Date as the latest date of the get_statistics job run
			//$asOfDate=new DateTime();	
			//$asOfDate->sub(new DateInterval('P1D'));
			//$date=date_format($asOfDate,'Y-m-d');
			$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
			$date=$rows[0]['job_date'];
			write_log("build_screen","As of date=".$date);
		}
		
		
		
		//initialize build where clause array
		$build_select="select distinct ss.symbol from stock_symbols ss, statistics s where ss.symbol=s.symbol and ss.enabled='Y' and s.date='".$date."'";
		//Loop through each  criteria in the build
		$screen_list=array();
		/* Ordered by criteria id. More than one top_n on a screen is applied
		   in that order, each to the survivors of the one before, which is
		   what makes "the cheapest 26 by value, then the best 15 of those by
		   momentum" a thing a screen can say. Without the order by, the order
		   was whatever the join happened to return. */
		$criterias=query("select sb.criteria_id, sc.operator,si.name,si.rank_order,sc.first_operand,sc.second_operand from screen_build sb, screen_criteria sc, screen_indicators si where si.indicator_id=sc.indicator_id and sb.criteria_id=sc.id and screen_id=? order by sb.criteria_id asc",$screen_id);
		$top_n_criteria=array();
		foreach($criterias as $criteria){
			$operator=$criteria["operator"];
			write_log("build_screen","criteria name=".$criteria["name"]);
			//determine if the operand is contant or another indicator
			if (isset($criteria["first_operand"])&&strlen($criteria["first_operand"])>0)
				$operand=$criteria["first_operand"];
			else 
				if (isset($criteria["second_operand"]))
					$operand="(select value from statistics s1 where indicator='".$criteria["second_operand"]."' and s1.symbol=ss.symbol and s1.date='".$date."')";
				else
					apologize("No operand found but expected one");
				
			/* top_n is not a filter and cannot be intersected: it is "the best
			   N of whatever is left", so it has to wait until everything else
			   has been applied. Collected here, applied below. */
			if ($operator=='top_n'){
				array_push($top_n_criteria,$criteria);
				continue;
			}

			write_log("build_screen","Normal Comparison operators");
			write_log("build_screen","first_operand=".$criteria["first_operand"]." second operand=".$criteria["second_operand"]);
			
			// Normal comparison operators
			write_log("build_screen","operator=".$criteria["operator"]);
			if (isset($operand)&&(($operator=='>=')||($operator=='>')||($operator=='<')||($operator=='<=')||($operator=='=')||($operator=='!='))){
				$build_query=$build_select . " AND s.indicator='".$criteria["name"]."' AND s.value" .$criteria["operator"]." ".$operand;
				//echo "screen criteria query=".$build_where;
				write_log("build_screen","screen query=".$build_query);
				$rows=query($build_query);
				if (count($rows)>0)
					array_push($screen_list,$rows);
				else
					return array();
			}
			//Complex criteria
			
			elseif ($operator=='top_percent'){
				write_log("build_screen","Complex criteria");
				write_log("build_screen","operator=".$operator);
			     // get number of shares using percent 
				//$rows = query("SELECT count(distinct(symbol)) * ".$criteria['first_operand']." top FROM stock_symbols WHERE enabled='Y'");	
				// if we found history
				//if (count($rows) >= 1){
					//$top = round($rows[0]["top"]);            
					//Get top percent of symbols for indicator
					write_log("build_screen","Get top percent of symbols for indicator");
					//write_log("build_screen","name=".$criteria['name']. " second_operand=".$criteria['second_operand']." top=".$top. " date=".$date);
					write_log("build_screen","query=SELECT symbol FROM statistics s where indicator='".$criteria['name']."' and date='".$date."' and percentile >= ".$criteria['first_operand']." order by value desc");
					$query="SELECT s.symbol FROM statistics s where indicator='".$criteria["name"]."' and date='".$date."' and percentile >= ".$criteria['first_operand']." order by value desc";
					$symbols = query($query);
					if (count($symbols)>0){
						write_log("build_screen","No of symbols=".count($symbols));
						array_push($screen_list,$symbols);
					}
					else
						return array();
				//}
			}
		}
		//Combine all lists to retrieve symbols in all matches
		write_log("build_screen","No of lists=".count($screen_list));
		//print_r($screen_list);
		$surviving=combine_criteria_lists_before_industry($screen_list);

		if (!count($top_n_criteria))
			return $surviving;

		/* A screen made only of top_n criteria has nothing to intersect, so
		   it starts from every share with a statistic on the date - the same
		   set the comparison criteria select from. */
		if (!count($screen_list))
			$surviving=query($build_select);

		foreach($top_n_criteria as $criteria){
			if (!count($surviving))
				return array();
			$surviving=top_n_list_before_industry($surviving,$criteria["name"],$criteria["rank_order"],
			                      $criteria["first_operand"],$date);
		}

		/* In the order of the last top_n: screen_list.php walks this list to
		   build its rows, so the page shows them ranked rather than in
		   whatever order the symbols came out of the database. */
		return $surviving;

	}

?>
