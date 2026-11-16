<?php

/* ---------------------------------------------------------------------------
   What is held, and what cash there is.

   One model, and this is it: purchases holds every buy and sell, cash_history
   holds every movement of money, and portfolio_performance is the nightly
   valuation of the two. Everything the Portfolio page, the dashboard and the
   strategy pages show is read from those three.

   Buy and Sell used to write a different set - shares, history and users.cash
   - so a purchase made through the application never appeared in the
   portfolio it was made in. Those three are read by nothing now; see
   "Where the old tables are still touched" in the commit that moved them.
   --------------------------------------------------------------------------- */

/**
 * Net shares held of one symbol, and what they cost on average.
 *
 * Buys less sells, from purchases. The average cost is weighted by the shares
 * in each lot - two lots of 500 at 72.5p and 250 at 81.1p average 75.37p, not
 * 76.8p - and it is the cost of the buys, because an average cost is what was
 * paid for what is held.
 *
 * Prices are pence, as purchases stores them.
 */
function portfolio_held($session_id, $symbol) {

	$rows = query("select
	                 sum(case when trx_type='BUY' then shares else 0 end) bought,
	                 sum(case when trx_type='SELL' then shares else 0 end) sold,
	                 sum(case when trx_type='BUY' then price_paid*shares else 0 end) cost,
	                 sum(case when trx_type='BUY' then shares else 0 end) buy_shares
	                from purchases where session_id=? and symbol=?",
	              $session_id, $symbol);

	if (!count($rows) || $rows[0]['bought'] === null) {
		return ['shares' => 0, 'avg_cost' => null];
	}

	$held = (int) $rows[0]['bought'] - (int) $rows[0]['sold'];
	$avg  = ((float) $rows[0]['buy_shares']) > 0
	      ? (float) $rows[0]['cost'] / (float) $rows[0]['buy_shares'] : null;

	return ['shares' => max(0, $held), 'avg_cost' => $avg];
}

/** Every symbol with shares still held, for the Sell list. */
function portfolio_held_symbols($session_id) {
	return query("select symbol,
	                     sum(case when trx_type='BUY'  then shares else 0 end)
	                   - sum(case when trx_type='SELL' then shares else 0 end) shares
	                from purchases where session_id=?
	               group by symbol having shares > 0 order by symbol", $session_id);
}

/**
 * The cash balance: the cash book, run down.
 *
 * DEPOSIT in, BUY out, SELL in, DELETE out, anything else in. This is what
 * every page in the application means by cash, and it is the only thing that
 * does - users.cash is a leftover that nothing reads.
 */
function portfolio_cash_balance($session_id) {
	$rows = query("select sum(case trx_type
	                            when 'DEPOSIT' then amount
	                            when 'BUY'     then -1*amount
	                            when 'SELL'    then amount
	                            when 'DELETE'  then -1*amount
	                            else amount end) cash
	                 from cash_history where user_id=?", $session_id);
	return (count($rows) && $rows[0]['cash'] !== null) ? (float) $rows[0]['cash'] : 0.0;
}

/**
 * Record a trade: one row in purchases, one in the cash book.
 *
 * Both or neither. A purchase recorded without the money leaving, or money
 * leaving without a purchase, is a portfolio that does not add up - and the
 * two used to be written to two different models, which is worse than either.
 *
 * $price is pence, as everything in purchases is. $commission is pounds,
 * because it is cash. The cash movement is the consideration plus the
 * commission on a buy, and the consideration less the commission on a sale.
 */
function portfolio_record_trade($session_id, $side, $symbol, $shares, $price_pence,
                                $commission, $date) {

	$side        = strtoupper($side) === 'SELL' ? 'SELL' : 'BUY';
	$shares      = (int) $shares;
	$price_pence = (float) $price_pence;
	$commission  = (float) $commission;

	$consideration = $price_pence * $shares / 100;
	$cash = $side === 'BUY' ? $consideration + $commission
	                        : $consideration - $commission;

	query("insert into purchases (session_id, symbol, trx_type, shares, price_paid,
	                              commission, purchase_date)
	       values (?,?,?,?,?,?,?)",
	      $session_id, $symbol, $side, $shares, $price_pence, $commission, $date);

	/* A BUY row holds what the purchase cost as a positive number; the
	   balance above negates it. Stored negative it would be negated again
	   and a purchase would add cash. */
	query("insert into cash_history (transaction_date, trx_type, amount, user_id)
	       values (?,?,?,?)", $date, $side, round($cash, 4), $session_id);

	return ['consideration' => $consideration, 'cash' => $cash];
}

/**
 * Value the account as at a date and store the result.
 *
 * portfolio_performance is the valuation of purchases and cash_history, and
 * the nightly job is what normally writes it - so a purchase made at eleven
 * in the morning was in the trade list and in the cash balance but not in
 * Holdings until the following night. That is not a portfolio somebody can
 * check a trade against.
 *
 * Called after every trade, so the page is true the moment it is reloaded.
 * The nightly job still owns the history; this only ever rewrites one date.
 */
function portfolio_revalue($session_id, $date = null) {

	$date = $date === null ? date('Y-m-d') : substr((string) $date, 0, 10);

	$performance = calc_performance($date, $session_id);
	if (!is_array($performance)) { return false; }

	/* One date, replaced. Deleting more would throw away history the
	   nightly job wrote and this cannot rebuild. */
	query("delete from performance where session_id=? and performance_date=?",
	      $session_id, $date);
	query("delete from portfolio_performance where session_id=? and as_of_date=?",
	      $session_id, $date);

	query("insert into performance
	         (session_id, performance_date, total_value, total_profit, total_holding, cash)
	       values (?,?,?,?,?,?)",
	      $session_id, $date, $performance['total_value'], $performance['total_profit'],
	      $performance['total_value'] + $performance['cash'], $performance['cash']);

	foreach ([['Y', 'active_positions'], ['N', 'inactive_positions']] as [$active, $key]) {
		foreach ((array) ($performance[$key] ?? []) as $position) {
			query("insert into portfolio_performance
			         (active, as_of_date, exchange, commission, dividends, price,
			          price_paid, price_sold, profit, profit_perc, profit_raw,
			          qty_purchased, qty_sold, session_id, symbol, value, value_raw)
			       values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
			      $active, $date, session_exchange(),
			      $position['commission'], $position['dividends'], $position['price'],
			      $position['price_paid'], $position['price_sold'], $position['profit'],
			      $position['profit_perc'], $position['profit_raw'],
			      $position['qty_purchased'], $position['qty_sold'], $session_id,
			      $position['symbol'], $position['value'], $position['value_raw']);
		}
	}

	return true;
}

//Get Portfolio Overview
function get_portfolio_overview($session_id){    
	
    // query database for user
	$rows = query("SELECT * FROM performance WHERE session_id = ? and performance_date=(select max(performance_date) from performance where session_id=?)", $session_id,$session_id);

    // if we found user
    if (count($rows) == 1)
    {
        // first (and only) row
        $row = $rows[0];
		
        $cash=$row["cash"];
		$total_value=$row["total_value"];
        $total_portfolio=$row["total_holding"];
        $total_profit=$row["total_profit"];	

		// create array for portfolio
        return ["cash" => $cash,"total_value" => $total_value,"total_portfolio"=> $total_portfolio,"total_profit" => $total_profit];      
		
	}

	//An account the performance job has not run for yet - every newly
	//registered one - had no row here, and returning null made performance.php
	//read three offsets off it and the template pass null to number_format.
	//An empty portfolio is worth zero, so say so.
	return ["cash" => 0, "total_value" => 0, "total_portfolio" => 0, "total_profit" => 0];
}

//Get Portfolio Active Positions
function get_active_positions($session_id){
	$positions=query("select ss.symbol,ss.name, commission,dividends,price,price_paid,price_sold,profit,profit_perc,profit_raw,qty_purchased,qty_sold,value,value_raw from portfolio_performance pp,stock_symbols ss where ss.symbol=pp.symbol and active=? and session_id=? and ss.exchange=? and as_of_date = (select max(as_of_date) from portfolio_performance where session_id=?)"
					,'Y',$session_id,session_exchange(),$session_id);
	$active_positions = [];
	foreach($positions as $position){
		$active_positions[]=[
			"name" => $position["name"],
			"price" => $position["price"],
			"price_paid" => $position["price_paid"], 
			"commission" => $position["commission"],
			"dividends" => $position["dividends"],
			"qty_purchased" => $position["qty_purchased"],
			"qty_sold" => $position["qty_sold"],
			"price_sold" => $position["price_sold"], 
			"symbol" => $position["symbol"],
			"value_raw" => $position["value_raw"],
			"value" => $position["value"],
			"profit_raw" => $position["profit_raw"],
			"profit" => $position["profit"]	,
			"profit_perc" => $position["profit_perc"] 
		] ;
	}
	return $active_positions;				
}

//Get Portfolio Inactive Positions
function get_inactive_positions($session_id){
	$positions=query("select ss.symbol,ss.name, commission,dividends,price,price_paid,price_sold,profit,profit_perc,profit_raw,qty_purchased,qty_sold,value,value_raw from portfolio_performance pp,stock_symbols ss where ss.symbol=pp.symbol and active=? and session_id=? and ss.exchange=? and as_of_date = (select max(as_of_date) from portfolio_performance where session_id=?)"
					,'N',$session_id,session_exchange(),$session_id);
	$inactive_positions = [];
	foreach($positions as $position){
		$inactive_positions[]=[
			"name" => $position["name"],
			"price" => $position["price"],
			"price_paid" => $position["price_paid"], 
			"commission" => $position["commission"],
			"dividends" => $position["dividends"],
			"qty_purchased" => $position["qty_purchased"],
			"qty_sold" => $position["qty_sold"],
			"price_sold" => $position["price_sold"], 
			"symbol" => $position["symbol"],
			"value_raw" => $position["value_raw"],
			"value" => $position["value"],
			"profit_raw" => $position["profit_raw"],
			"profit" => $position["profit"]	,
			"profit_perc" => $position["profit_perc"] 
		] ;
	}
	return $inactive_positions;				
}


function calc_performance($asOfDate,$session_id){

 	write_log('portfolio_functions.calc_performance',"asOfDate= ".$asOfDate.", session_id= ".$session_id);
 	
	
    //query shares held by user
    $inactive_positions =[];
    $active_positions=[];
    $rows = query("SELECT symbol, sum( IF( trx_type =  'BUY', shares, 0 )) as qty_purchased, sum( IF( trx_type =  'SELL', shares, 0 )) as qty_sold,sum(commission) as commission, round(sum(price_paid*IF( trx_type =  'BUY', shares, 0 ))/sum(IF( trx_type =  'BUY', shares, 0 )),2) as price_paid, round(sum(price_paid*IF( trx_type =  'SELL', shares, 0 ))/sum(IF( trx_type =  'SELL', shares, 0 )),2) price_sold FROM purchases where purchase_date <=? and session_id = ? group by symbol", $asOfDate, $session_id);
    // if we found stocks
        if (count($rows) >= 1)
        {	write_log('portfolio_functions.calc_performance',"we found stocks numbering= ".count($rows));
		
            $positions = [];
            foreach ($rows as $row)
            {
					//dividends
					$dividends=query("select ifnull(sum(amount),0) as dividends from dividends where dividend_date<=? and session_id=? and symbol=?",$asOfDate,$session_id,$row["symbol"]);
	                //lookup price for each share
	                //$price = get_share_price($row["symbol"],$asOfDate);
	                
					if ($row["qty_purchased"]>0)
	                {
	                	
						//two arrays - one for active stocks and the other for inactive. Inactive have a value of 0
						if (($row["qty_purchased"]-$row["qty_sold"])>0) {  //we have an active position
						
							write_log('portfolio_functions.calc_performance',"insert active share = ".$row["symbol"]);
							
							$quote=lookup($row["symbol"]);
							$price=$quote["price"];
							write_log('portfolio_functions.calc_performance',"price = ".$price);
							
							if ($price !== false) {
							
								$active_positions[] = [
									"price" => $price,
									"price_paid" => $row["price_paid"], 
									"commission" => $row["commission"],
									"dividends" => $dividends[0]["dividends"],
									"qty_purchased" => $row["qty_purchased"],
									"qty_sold" => nvl($row["qty_sold"],0),
									"price_sold" => nvl($row["price_sold"],0), 
									"symbol" => $row["symbol"],
									"value_raw" => ($price * ($row["qty_purchased"]-$row["qty_sold"]))/100,
									"value" => number_format(($price * ($row["qty_purchased"]-$row["qty_sold"]))/100,2),
									"profit_raw" => (((          $price - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"],
									"profit" => number_format(((($price - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"],2) 						,
									"profit_perc" => number_format((((($price - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"])/(( $row["price_paid"] * ($row["qty_purchased"]))/100)*100,1)
								];            
							}
						}
						//else we have an inactive position
						else {
							
								write_log('portfolio_functions.calc_performance',"insert inactive share = ".$row["symbol"]);
								
								$price = get_share_price($row["symbol"],$asOfDate);
							    write_log('portfolio_functions.calc_performance',"price = ".$price);
							
				 			    if ($price !== false) {
					
									$inactive_positions[]=[
										"price" => $price,
										"price_paid" => $row["price_paid"], 
										"commission" => $row["commission"],
										"dividends" => $dividends[0]["dividends"],
										"qty_purchased" => $row["qty_purchased"],
										"qty_sold" => $row["qty_sold"],
										"price_sold" => $row["price_sold"], 
										"symbol" => $row["symbol"],
										"value_raw" => ($price * ($row["qty_purchased"]-$row["qty_sold"]))/100,
										"value" => number_format(($price * ($row["qty_purchased"]-$row["qty_sold"]))/100,2),
										"profit_raw" => (((          $price - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"],
										"profit" => number_format(((($price - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"],2) ,
										"profit_perc" => number_format((((($price - $row["price_paid"] ) * ($row["qty_purchased"]-$row["qty_sold"])+($row["price_sold"] - $row["price_paid"] )*$row["qty_sold"] )/100) - $row["commission"]+$dividends[0]["dividends"])/(( $row["price_paid"] * ($row["qty_purchased"]))/100)*100,1)
									];
								}
	                }
	            }
	            //Increment totals
	            $total_value=0;
	            $total_profit=0;
	            foreach ($active_positions as $position) {
					  $total_value=$total_value+$position['value_raw'];
					  $total_profit=$total_profit+$position['profit_raw'];
	            }
	            if(isset($inactive_positions)) 
						foreach ($inactive_positions as $position) {
				  			$total_profit=$total_profit+$position['profit_raw'];
	            	}
	            //Get cash available
	            //$rows = query("SELECT cash FROM users WHERE id = ?", $_SESSION["id"]);
	            $rows = query("SELECT sum(CASE  trx_type 	WHEN 'DEPOSIT' THEN amount	WHEN 'BUY' THEN -1*amount	WHEN 'SELL' then amount	WHEN 'DELETE' then -1*amount ELSE amount  END) cash FROM cash_history WHERE transaction_date<=? and user_id=?",$asOfDate, $session_id);
	            // first (and only) row
	            $row = $rows[0];
	    
	         } 

            // render portfolio
            return  ["title" => "Portfolio","active_positions" => $active_positions,"inactive_positions"=>$inactive_positions,"cash" => $row["cash"],"total_value" => $total_value,"total_profit" => $total_profit];      
   
        }
   	}

?>