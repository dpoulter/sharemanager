<?php

    require(__DIR__ . "/../includes/config.php");

    // numerically indexed array of places
    $symbols = [];
    // TODO: search database for symbols matching $_GET["symbol"]
   //Bound, not interpolated. $_GET["symbol"] reaches this straight from the
   //navbar typeahead, so concatenating it into the SQL was an injection point.
   $term = isset($_GET["symbol"]) ? trim($_GET["symbol"]) : "";
   $rows = ($term === "") ? []
         : query("SELECT symbol FROM stock_symbols WHERE MATCH(symbol,description) AGAINST(?)", $term);
   //$rows = query("SELECT description FROM stock_symbols where symbol='AGA.L'");
	for ($i=0;$i<count($rows);$i++)
		array_push($symbols,$rows[$i]["symbol"]);

    // output shares as JSON (pretty-printed for debugging convenience)
    header("Content-type: application/json");
    print(json_encode($symbols));
   
?>
