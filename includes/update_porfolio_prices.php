<?php

    // configuration
     require_once("constants.php");
     require_once("functions.php");
     require_once("share_functions.php");
	
	$rows=query("select id, symbol, purchase_date from purchases");
	foreach($rows as $purchase){
		$price=get_share_price($purchase['symbol'],$purchase['purchase_date']);
		$result = query("update purchases set avg_Cost=? where id=?",$price,$purchase['id']);
	}

	
?>