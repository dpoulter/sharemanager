<?php
	require_once("constants.php");

	//Get screen name and description
	function screen($screen_id){
		$rows= query("select name, description from screen where id=?",$screen_id);
		return ["name"=>$rows[0]["name"],"description"=>$rows[0]["description"]];
	}

	//insert new criteria	
	function insert_criteria($criteria){
		$inserted=query("insert into screen_criteria (indicator_id,description,operator,first_operand,second_operand) values (?,?,?,?,?)",$criteria["indicator"],$criteria["description"],$criteria["operator"],$criteria["first_operand"],$criteria["second_operand"]);
		return $inserted;
	}
	
	//delete screen criteria
	function delete_screen_criteria($screen_id,$criteria){
		write_log("delete_screen_criteria","screen_id=".$screen_id);
		for ($i=0;$i<count($criteria);$i++){
			if (isset($criteria[$i])){
				write_log("delete_screen_criteria","criteria_id=".$criteria[$i]);
				query("delete from screen_build where screen_id=? and criteria_id=?",$screen_id, $criteria[$i]);
			}
		}
	}
	
	//delete criteria
	function delete_criteria($criteria){
		for ($i=0;$i<count($criteria);$i++) {
			//Check if delete checkbox checked
			write_log("delete_criteria","checkbox[".$i."]=".$criteria[$i]);
			if (isset($criteria[$i])){
				write_log("delete_criteria","query=delete from screen_criteria where id =". $criteria[$i]);
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
				query("insert into screen_build (screen_id,criteria_id) values (? ,?)", $screen_id,$criteria[$i]);
			}
		}
	}
	
		function intersect_list($list1,$list2){
		$list=array();
		foreach($list1 as $item){
			if (symbol_exists($item["symbol"],$list2)){
				array_push($list,["symbol"=>$item["symbol"]]);
			}
		} 
		return $list;
	}

	function combine_criteria_lists($build_list){
		//echo "count=" . count($build_list);
		if (count($build_list)<1){
			return array();
		}
		elseif (count ($build_list) <2) {
			return $build_list[0];
		}
		else {
		 	$list=array_pop($build_list);
//			print_r($list);
//			print_r($build_list);
	           	return intersect_list($list,combine_criteria_lists($build_list));
		}
	}
	
	
	//Build Screen and returns the complete sql query for all criteria in the screen
	function build_screen($screen_id){
		//initialize build where clause array
		//$build_where="select ss.symbol from stock_symbols ss, price_momentum pm where concat(ss.symbol,'.L')=pm.symbol and ss.enabled='Y' ";
		$build_where="select ss.symbol from stock_symbols ss, statistics s where ss.symbol=s.symbol and ss.enabled='Y' ";
		//Loop through each  criteria in the build
		$screen_list=array();
		$criterias=query("select sb.criteria_id, sc.operator,si.name,sc.first_operand,sc.second_operand from screen_build sb, screen_criteria sc, screen_indicators si where si.indicator_id=sc.indicator_id and sb.criteria_id=sc.id and screen_id=?",$screen_id);
		foreach($criterias as $criteria){
			$operator=$criteria["operator"];
			write_log("build_screen","operator=".$criteria["operator"]);
			//determine if the operand is contant or another indicator
			if (isset($criteria["first_operand"]))
				$operand=$criteria["first_operand"];
			else 
				if (isset($criteria["second_operand"]))
					$operand="(select value from statistics where indicator='".$criteria["second_operand"]."' and symbol=s.symbol limit 1)";
				else
					apologize("No operand found but expected one");
				
			write_log("build_screen","Normal Comparison operators");
			
			// Normal comparison operators
			if (($operator=='>=')||($operator=='>')||($operator=='<')||($operator=='<=')||($operator=='=')||($operator=='!=')){
				$build_where=$build_where . " AND s.indicator='".$criteria["name"]."' AND s.value" .$criteria["operator"]." ".$operand;
				//echo "screen criteria query=".$build_where;
				write_log("build_screen","screen query=".$build_where);
				$rows=query($build_where);
				if (count($rows)>0)
					array_push($screen_list,$rows);
			}
			//Complex criteria
			elseif ($operator=='Top Percent'){
			     // get number of shares using percent 
				$rows = query("SELECT count(distinct(symbol)) * ".$criteria['first_operand']."/100 top FROM stock_symbols WHERE enabled='Y'");	
				// if we found history
				if (count($rows) >= 1){
					$top = $rows[0]["top"];            
					//Get top percent of symbols for indicator
					write_log("build_screen","name=".$criteria['name']. " second_operand=".$criteria['second_operand']." top=".$top);
					$rows = query("SELECT symbol FROM statistics where indicator=? order by value desc limit ?",$criteria['name'],$top);
					if (count($rows)>0)
						array_push($screen_list,$rows);
				}
			}
		}
		//Combine all lists to retrieve symbols in all matches
		write_log("build_screen","No of lists=".count($screen_list));
		return combine_criteria_lists($screen_list);

	}

?>
