<?php

    require(__DIR__ . "/../includes/config.php");

    // numerically indexed array of places
    $symbols = [];
    // TODO: search database for symbols matching $_GET["symbol"]
   // $rows = query("SELECT symbol FROM stock_symbols WHERE MATCH(symbol,description) AGAINST('".$_GET["symbol"]."')");
   //$rows = query("SELECT symbol FROM stock_symbols where enabled='Y'");
   $rows = query("SELECT concat(rpad(symbol,5,' '), concat(' - ',name)) symbol FROM stock_symbols where enabled='Y' and exchange=?",$_SESSION["exchange"]);
	for ($i=0;$i<count($rows);$i++)
		array_push($symbols,$rows[$i]["symbol"]);
    // output shares as JSON (pretty-printed for debugging convenience)
    header("Content-type: application/json");
    print(json_encode($symbols));
   
?>
