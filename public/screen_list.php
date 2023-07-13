<?php

    // configuration
    require("../includes/config.php");
	include("../includes/share_screen.php");
  // if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {	$screen_id=$_POST["screen"];
	    //write_log("screen_list.php","screen_id=".$screen_id);
		//get screen name and description
		$screen=query("select name, description from screen where id=? and displayed='Y'",$screen_id);
		if (isset($_POST["btnEdit"])){
			//get all screen criteria
			$criteria=query("SELECT sc.id,sc.description FROM screen_build sb, screen_criteria sc WHERE sb.criteria_id=sc.id and sb.screen_id=?",$screen_id);
			render("screen_criteria.php",["screen_id"=>$screen_id, "screen" => $screen[0]["name"],"description"=>$screen[0]["description"], "criterias"=>$criteria]);
		}
		elseif (isset($_POST["btnRun"])){
			//echo "screen_id=".$screen_id;
			//Get screen name and description
			//write_log("screen_list.php","btnRun");
			$screen=screen($screen_id);
			$symbols=build_screen($screen_id,null);
			//print_r($symbols);
			
			//Check if the screen returned any symbols
			if (count($symbols)>0){
				//echo "number of symbols=".count($symbols);
				//print_r($symbols);
				
				//Get As of Date as todays date
				$asOfDate=new DateTime();	
				//$asOfDate->sub(new DateInterval('P1D'));
				$date=date_format($asOfDate,'Y-m-d');
				
				//get the maximum date of statistics
				$date_row=query("select date(max(date_sub(job_date,INTERVAL 1 DAY))) max_date from jobs where job_name = 'get_statistics'");
				$date=$date_row[0]["max_date"];
				
				
				//Get screen indicators
				$indicators=query("select si.name, si.description FROM screen_criteria sc, screen_build sb, screen_indicators si WHERE sb.criteria_id=sc.id and sc.indicator_id=si.indicator_id and sb.screen_id=? union SELECT second_operand name, si.description FROM screen_criteria sc, screen_build sb, screen_indicators si WHERE sb.criteria_id=sc.id AND si.name = sc.second_operand and sb.screen_id=? and length(second_operand)>0",$screen_id,$screen_id);
				
				//Build select
				$select =  'SELECT symbol, indicator, value ';
				//Build where using indicators
				foreach ($indicators as $indicator){
					//write_log("screen_list","indicators query=".$indicator["name"]);
					if (!isset($ind_where)){
						$ind_where = 'WHERE (indicator="' . $indicator["name"] . '"' ;
						//write_log("screen_list","ind_where=".$ind_where);
					}
					else {
						$ind_where=$ind_where . ' OR indicator="' . $indicator["name"] . '"';
					}
				}
				$ind_where=$ind_where . ')';
				//echo "where:" . $ind_where;
				//Build where using symbols
				foreach($symbols as $symbol)
					if (empty($sym_where))
						$sym_where = ' AND (symbol="' . $symbol["symbol"] . '"';
					else
						$sym_where=$sym_where . ' OR symbol="' . $symbol["symbol"] . '"';
				$sym_where=$sym_where . ')';
				//Get stats
				//echo $where;
				//write_log("screen_list","stats query=".$select . ' FROM statistics ' . $ind_where . $sym_where. " and date='$date'");
				$rows=query($select . ' FROM statistics ' . $ind_where . $sym_where. " and date='$date'");
				//$rows=query($select . ' FROM statistics s1' . $ind_where . $sym_where. " and date=(select max(date) from statistics s2 where date<=?)",$date);
				if (count($rows)>0){
					$stats=array();
					//loop through symbols to create new array containing symbol with indicator values
					foreach ($symbols as $symbol){
						//new indicator array to hold indicator values
						$ind_values=array();
						//loop through statistics to get indicators for the symbol
						foreach ($rows as $row){
							if ($symbol["symbol"]==$row["symbol"]){
								array_push($ind_values,["indicator"=>$row["indicator"],"value"=>$row["value"]]);
							}
						}
						
						//get company name
						$name=get_share_name($symbol["symbol"]);
						
						//now push the symbol with values onto the stats array
						array_push($stats,["symbol"=>$symbol["symbol"],"name"=>$name,"ind_values"=>$ind_values]);
						//ß_r($stats);
						//echo "</br>";
					}
					//print_r($stats);
				}
				else
					$stats=array();
				
				render("screen.php",["title"=>$screen["name"],"screen_id"=>$screen_id,"stats"=>$stats,"indicators"=>$indicators]);
			}
			else {
				//echo "No shares selected by screen";
				render("screen.php",["title"=>$screen["name"],"stats"=>array(),"indicators"=>array()]);
				}
		}	
		
	
	}
	else{
	//query screens
    $rows = query("SELECT id,name, description from screen where displayed='Y'");
    // if we found history
        if (count($rows) >= 1)
        {
            $screens = [];
            foreach ($rows as $row)
            {
                {
                    $screens[] = [
						"id" => $row["id"],
                        "name" => $row["name"],
                        "description" => $row["description"]
                    ];
                }
            }


            // render history
            render("screens.php", ["title" => "Screens","screens" => $screens]);
        }
        else
            apologize("You dont have any Screens yet.");
	}
?>
