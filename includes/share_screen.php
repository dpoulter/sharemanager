<?php
	require_once("constants.php");
   include_once("share_functions.php");
   
	//Get screen name and description
	function screen($screen_id){
		$rows= query("select name, description from screen where id=?",$screen_id);
		return ["name"=>$rows[0]["name"],"description"=>$rows[0]["description"]];
	}

	//insert new criteria	
	function insert_criteria($criteria){
		if (isset($criteria["first_operand"])&&strlen($criteria["second_operand"])>0){
			//write_log("share_screen.php","insert into screen_criteria (indicator_id,description,operator,first_operand,second_operand) values (".$criteria["indicator"].",".$criteria["description"].",".$criteria["operator"].",".$criteria["first_operand"].",".$criteria["second_operand"].")");
			$result=query("insert into screen_criteria (indicator_id,description,operator,first_operand,second_operand) values (?,?,?,?,?)",$criteria["indicator"],$criteria["description"],$criteria["operator"],$criteria["first_operand"],$criteria["second_operand"]);
		}
		elseif(isset($criteria["first_operand"])&&strlen($criteria["second_operand"])==0) {	
			//write_log("share_screen.php","insert into screen_criteria (indicator_id,description,operator,first_operand) values (".$criteria["indicator"].",".$criteria["description"].",".$criteria["operator"].",".$criteria["first_operand"].")");				
			$result=query("insert into screen_criteria (indicator_id,description,operator,first_operand) values (?,?,?,?)",$criteria["indicator"],$criteria["description"],$criteria["operator"],$criteria["first_operand"]);
		}
		$result=query("select LAST_INSERT_ID() screen_id");
		return $result[0]["screen_id"];
	}
	
	//Update criteria	
	function update_criteria($criteria){
		$result=query("update screen_criteria set indicator_id=?,description=?,operator=?,first_operand=?,second_operand=? where id=?",$criteria["indicator"],$criteria["description"],$criteria["operator"],$criteria["first_operand"],$criteria["second_operand"],$criteria["id"]);
		return $result;
	}
	
	
	//delete screen criteria
	function delete_screen_criteria($screen_id,$criteria){
		//write_log("delete_screen_criteria","screen_id=".$screen_id);
		for ($i=0;$i<count($criteria);$i++){
			if (isset($criteria[$i])){
				//write_log("delete_screen_criteria","criteria_id=".$criteria[$i]);
				query("delete from screen_build where screen_id=? and criteria_id=?",$screen_id, $criteria[$i]);
			}
		}
	}
	
	//delete criteria
	function delete_criteria($criteria){
		for ($i=0;$i<count($criteria);$i++) {
			//Check if delete checkbox checked
			//write_log("delete_criteria","checkbox[".$i."]=".$criteria[$i]);
			if (isset($criteria[$i])){
				//write_log("delete_criteria","query=delete from screen_criteria where id =". $criteria[$i]);
				query("delete from screen_criteria where id = ?", $criteria[$i]);
			}
		}
	}
	
	//Return all criteria for a screen
	function screen_criteria($screen_id){
		$criteria=query("select sc.* from screen_criteria sc, screen_build sb where sb.criteria_id=sc.id and sb.screen_id=?",$screen_id);
		return $criteria;
	}
	
	//Insert new screen criteria
	function insert_screen_criteria($screen_id, $criteria){
		for ($i=0;$i<count($criteria);$i++) {
			//Check if delete checkbox checked
			if (isset($criteria[$i])){
				//write_log("share_screen.php","criteria_id =". $criteria[$i]);
				query("insert into screen_build (screen_id,criteria_id) values (? ,?)", $screen_id,$criteria[$i]);
			}
		}
	}
	
		function intersect_list($list1,$list2){
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

	function combine_criteria_lists($build_list){
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
	           	return intersect_list($list,combine_criteria_lists($build_list));
		}
	}
	
	
	//Build Screen and returns the complete sql query for all criteria in the screen
	function build_screen($screen_id,$date){
		
		//write_log("build_screen","date=$date");
		
		if (!isset($date)){
			
			//Get As of Date as the latest date of the get_statistics job run
			//$asOfDate=new DateTime();	
			//$asOfDate->sub(new DateInterval('P1D'));
			//$date=date_format($asOfDate,'Y-m-d');
			$rows=query("select date(max(date_sub(job_date,INTERVAL 1 DAY))) job_date from jobs where job_name='get_statistics'");
			$date=$rows[0]['job_date'];
			write_log("build_screen","As of date=".$date);
		}
		
		
		
		//initialize build where clause array
		$build_select="select distinct ss.symbol from stock_symbols ss, statistics s where ss.symbol=s.symbol and ss.enabled='Y' and s.date='".$date."'";
		//Loop through each  criteria in the build
		$screen_list=array();
		$criterias=query("select sb.criteria_id, sc.operator,si.name,sc.first_operand,sc.second_operand from screen_build sb, screen_criteria sc, screen_indicators si where si.indicator_id=sc.indicator_id and sb.criteria_id=sc.id and screen_id=?",$screen_id);
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
		return combine_criteria_lists($screen_list);

	}

?>
