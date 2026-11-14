<?php
	//require_once("constants.php");
	

	//insert new screen criteria	
	function insert_criteria($criteria){
		write_log("share_screen.php","insert into screen_criteria (indicator_id,description,operator,first_operand,second_operand) values (".$criteria["indicator"].",".$criteria["description"].",".$criteria["operator"].",".$criteria["first_operand"].",".$criteria["second_operand"].")");
		$inserted=query("insert into screen_criteria (indicator_id,description,operator,first_operand,second_operand) values (?,?,?,?,?)",$criteria["indicator"],$criteria["description"],$criteria["operator"],$criteria["first_operand"],$criteria["second_operand"]);
		return $inserted;
	}
	
	//return where clause build from the criteria 
	function  screen_criteria($criteria_id){
		$criteria=query("select * from screen_criteria where id=?",$criteria_id);
		$indicator=query("select name from screen_indicators where indicator_id=?",$criteria[0]["indicator_id"]);
		if ($criteria[0]["operator"]=='>='){
			$where=$indicator[0]['name']." ".$criteria[0]["operator"]." ".$criteria[0]["first_operand"];
		}
		return $where;
	}

	//Build Screen and returns the complete sql query for all criteria in the screen
	function build_screen($screen_id){
		//initialize build where clause array
		$build_where="select ss.symbol from stock_symbols ss, price_momentum pm where ss.symbol=pm.symbol and ss.enabled='Y' ";
		//Loop through each  criteria in the build
		$criterias=query("select criteria_id from screen_build where screen_id=?",$screen_id);
		foreach($criterias as $criteria){
			$build_where=$build_where . " AND pm." . screen_criteria($criteria["criteria_id"]);
		}
		echo $build_where;
		return $build_where;

	}

?>
