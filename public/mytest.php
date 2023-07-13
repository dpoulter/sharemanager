<?php

  //  require(__DIR__ . "/../includes/config.php");
    // numerically indexed array of places
     $symbols = [['name'=>'XXX','description'=>'Lots of XXX'], ['name'=>'Alaska','description'=>'Lots of XXX'], ['name'=>'Arizona','description'=>'Lots of XXX'],[ 'name'=>'Arkansas','description'=>'Lots of XXX'],['name'=>'California','description'=>'Lots of XXX']]; 
    // TODO: search database for symbols matching $_GET["symbol"]
   // $rows = query("SELECT symbol FROM stock_symbols WHERE MATCH(symbol,description) AGAINST('".$_GET["symbol"]."')");
 //  $rows = query("SELECT symbol FROM stock_symbols where enabled='Y'");
   
//	for ($i=0;$i<count($rows);$i++)
	//	array_push($symbols,$rows[$i]["symbol"]);
	
	 
    // output shares as JSON (pretty-printed for debugging convenience)
  //  header("Content-type: application/json");
    print(json_encode($symbols));
    
    

$arr = array('a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5);

echo json_encode($arr);


   
?>
