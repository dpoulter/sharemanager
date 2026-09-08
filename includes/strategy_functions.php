<?php

	/**
	 * strategy_functions.php
	 *
	 * Paper execution: turn a signal into target weights, reconcile those
	 * against holdings, and fill the resulting orders against historical
	 * prices.
	 *
	 * Nothing here talks to a broker. The fill model is deliberately
	 * pessimistic so the paper run does not flatter the strategy.
	 */

	require_once("constants.php");

	//---------------------------------------------------------------------
	// Limits. Deliberately constants rather than database configuration: a
	// bad row in a config table should not be able to widen them, and a
	// change should show up in a diff.
	//---------------------------------------------------------------------

	define("STRATEGY_MAX_POSITION_WEIGHT", 0.20);  // no single holding over 20%
	define("STRATEGY_MAX_ORDERS_PER_RUN",  50);
	define("STRATEGY_MAX_TURNOVER",        0.50);  // total traded value / account value
	//Overridable so the halt path can actually be tested; production leaves
	//the variable unset and gets the fixed path.
	define("STRATEGY_KILL_SWITCH_FILE",
		getenv("STRATEGY_KILL_SWITCH_FILE") ?: "/var/www/shares/STOP_TRADING");

	// UK dealing costs. Stamp duty is 0.5% on purchases of UK shares; AIM
	// stocks are exempt, which this does not model, so buy costs are if
	// anything overstated for an AIM heavy book.
	define("STRATEGY_STAMP_DUTY_RATE", 0.005);
	define("STRATEGY_COMMISSION",      5.95);      // per order, flat
	define("STRATEGY_SLIPPAGE_BPS",    25);        // half spread, each way

	// Below this consideration an order costs more in commission and stamp
	// than the position adjustment is worth, so it is not placed at all.
	define("STRATEGY_MIN_ORDER_VALUE",  250.00);

	/**
	 * Trading is halted if the kill switch file exists. Checked before every
	 * order, not once per run, so dropping the file stops a run in progress.
	 */
	function strategy_halted() {
		return file_exists(STRATEGY_KILL_SWITCH_FILE);
	}

	/**
	 * Deterministic order id: one order per symbol per rebalance.
	 *
	 * Neither quantity nor side is part of it, and that is the whole point. A
	 * re-run values the account after dealing costs have been paid, so target
	 * quantities come out a share or two lower and the correction is a SELL.
	 * With side in the id that SELL is a different key, so re-running a
	 * rebalance quietly traded again, paying commission and stamp to shave a
	 * share off each holding. Keyed on the symbol alone, a re-run finds the
	 * order already placed and does nothing, which is what "this rebalance has
	 * already dealt in this symbol" should mean.
	 */
	function strategy_order_id($strategy,$as_of_date,$symbol) {
		return substr(strtolower($strategy),0,20)."-".$as_of_date."-".strtoupper($symbol);
	}

	/**
	 * Last known price on or before a date. Returns null when there is none,
	 * which callers must treat as "do not trade this symbol".
	 */
	function strategy_price_asof($symbol,$exchange,$date) {
		$rows=query("select price from historical_prices where symbol=? and exchange=? and date<=? order by date desc limit 1",
			$symbol,$exchange,$date);
		return (count($rows)>0&&$rows[0]['price']!==null) ? (float)$rows[0]['price'] : null;
	}

	/**
	 * First price strictly after a date, with its date.
	 *
	 * This is the fill: an order decided on the as of date cannot transact at
	 * that date's price, so it fills on the next available session. Returns
	 * null when the future price is not in the data yet, which is what stops
	 * the executor filling today's rebalance against today's own close.
	 *
	 * Note historical_prices holds the adjusted close, which is restated by
	 * later dividends and splits. Fills are therefore approximate; a real
	 * broker fills at an unadjusted traded price.
	 */
	function strategy_next_price($symbol,$exchange,$date) {
		$rows=query("select date, price from historical_prices where symbol=? and exchange=? and date>? and price is not null order by date asc limit 1",
			$symbol,$exchange,$date);
		return count($rows)>0 ? ['date'=>$rows[0]['date'],'price'=>(float)$rows[0]['price']] : null;
	}

	function strategy_account($strategy) {
		$rows=query("select * from strategy_accounts where strategy=?",$strategy);
		return count($rows)>0 ? $rows[0] : null;
	}

	function strategy_positions($strategy) {
		$positions=[];
		foreach (query("select * from strategy_positions where strategy=? and quantity<>0",$strategy) as $row) {
			$positions[$row['symbol']]=$row;
		}
		return $positions;
	}

	/**
	 * Account value = cash + holdings marked at the as of date.
	 *
	 * Returns null if any holding cannot be priced. That is deliberate: an
	 * account value computed from a partially priced book would silently
	 * mis-size every order, so the executor refuses to trade instead.
	 */
	function strategy_account_value($strategy,$as_of_date) {
		$account=strategy_account($strategy);
		if ($account===null){ return null; }

		$value=(float)$account['cash'];
		foreach (strategy_positions($strategy) as $symbol=>$position) {
			$price=strategy_price_asof($symbol,$position['exchange'],$as_of_date);
			if ($price===null){
				write_log("strategy","cannot price held symbol ".$symbol." as of ".$as_of_date);
				return null;
			}
			$value += $price * (int)$position['quantity'];
		}
		return $value;
	}

	/**
	 * Turn targets into orders, without placing them.
	 *
	 * Split out from execution so the decision can be inspected and tested on
	 * its own. Returns a list of intents; an empty list means do nothing,
	 * which is a valid and common outcome.
	 */
	function strategy_plan_orders($strategy,$as_of_date) {

		$plan=['orders'=>[],'errors'=>[],'account_value'=>null];

		$account=strategy_account($strategy);
		if ($account===null){ $plan['errors'][]="no account for strategy ".$strategy; return $plan; }
		if ($account['enabled']!=='Y'){ $plan['errors'][]="account is disabled"; return $plan; }
		if ($account['mode']!=='PAPER'){ $plan['errors'][]="only PAPER mode is supported"; return $plan; }

		$account_value=strategy_account_value($strategy,$as_of_date);
		if ($account_value===null){ $plan['errors'][]="cannot value the account, refusing to trade"; return $plan; }
		if ($account_value<=0){ $plan['errors'][]="account value is not positive"; return $plan; }
		$plan['account_value']=$account_value;

		$targets=query("select * from strategy_targets where strategy=? and as_of_date=?",$strategy,$as_of_date);
		if (count($targets)===0){ $plan['errors'][]="no targets for ".$as_of_date; return $plan; }

		$total_weight=0;
		foreach ($targets as $target) { $total_weight += (float)$target['target_weight']; }
		if ($total_weight>1.0000001){
			$plan['errors'][]="target weights sum to ".round($total_weight,4).", over 1";
			return $plan;
		}

		$positions=strategy_positions($strategy);
		$wanted=[];

		//Size against what can actually be spent, not the headline account
		//value. Buying 100% of the account leaves nothing for stamp duty,
		//spread and commission, so the last order of every rebalance would be
		//rejected for want of cash and the book would quietly run one name
		//short. Reserving the costs up front leaves a small cash buffer, which
		//is the right direction to be wrong in.
		$cost_multiplier=1+STRATEGY_STAMP_DUTY_RATE+(STRATEGY_SLIPPAGE_BPS/10000);
		$investable=($account_value-(STRATEGY_COMMISSION*count($targets)))/$cost_multiplier;
		if ($investable<=0){ $plan['errors'][]="account too small to cover dealing costs"; return $plan; }

		//Buys and rebalances
		foreach ($targets as $target) {

			$symbol=$target['symbol'];
			$weight=(float)$target['target_weight'];
			$wanted[$symbol]=true;

			if ($weight>STRATEGY_MAX_POSITION_WEIGHT){
				$plan['errors'][]=$symbol." target weight ".$weight." exceeds the ".STRATEGY_MAX_POSITION_WEIGHT." limit";
				return $plan;
			}

			$price=strategy_price_asof($symbol,$target['exchange'],$as_of_date);
			if ($price===null||$price<=0){
				//Not fatal: skip the symbol, keep the rest of the rebalance.
				$plan['errors'][]="no usable price for ".$symbol.", skipped";
				continue;
			}

			$target_quantity=(int)floor(($weight*$investable)/$price);
			$held=isset($positions[$symbol]) ? (int)$positions[$symbol]['quantity'] : 0;
			$delta=$target_quantity-$held;

			//Skip adjustments too small to be worth the dealing costs. A
			//one share correction on a 160 pound stock costs commission plus
			//stamp to move the weight by a rounding error.
			if ($delta!==0&&(abs($delta)*$price)>=STRATEGY_MIN_ORDER_VALUE){
				$plan['orders'][]=[
					'symbol'=>$symbol,'exchange'=>$target['exchange'],
					'side'=>$delta>0?'BUY':'SELL','quantity'=>abs($delta),
					'reference_price'=>$price,
				];
			}
		}

		//Exits: anything held that is no longer a target
		foreach ($positions as $symbol=>$position) {
			if (!isset($wanted[$symbol])&&(int)$position['quantity']>0){
				$price=strategy_price_asof($symbol,$position['exchange'],$as_of_date);
				$plan['orders'][]=[
					'symbol'=>$symbol,'exchange'=>$position['exchange'],
					'side'=>'SELL','quantity'=>(int)$position['quantity'],
					'reference_price'=>$price,
				];
			}
		}

		//Whole-run limits. Breaching either aborts the rebalance rather than
		//trimming it: a partial rebalance is a different portfolio from the
		//one the signal asked for, and silently taking half of it is worse
		//than taking none.
		if (count($plan['orders'])>STRATEGY_MAX_ORDERS_PER_RUN){
			$plan['errors'][]=count($plan['orders'])." orders exceeds the limit of ".STRATEGY_MAX_ORDERS_PER_RUN;
			$plan['orders']=[];
			return $plan;
		}

		//Turnover is min(buys, sells), the standard definition: it measures
		//how much of the book is being swapped, not how much is being moved.
		//Deploying idle cash is all buys and no sells, so it scores zero,
		//which is right - the first rebalance of a new account would
		//otherwise breach every sane limit. Liquidating scores zero for the
		//same reason, and must stay possible even when churn is capped.
		$buys=$sells=0;
		foreach ($plan['orders'] as $order) {
			if ($order['reference_price']===null){ continue; }
			$value=$order['quantity']*$order['reference_price'];
			if ($order['side']==='BUY'){ $buys += $value; } else { $sells += $value; }
		}
		$turnover=min($buys,$sells);
		if ($account_value>0&&($turnover/$account_value)>STRATEGY_MAX_TURNOVER){
			$plan['errors'][]="turnover ".round($turnover/$account_value,3)." exceeds the limit of ".STRATEGY_MAX_TURNOVER;
			$plan['orders']=[];
			return $plan;
		}

		return $plan;
	}

	/**
	 * Dealing costs for one fill. Buys pay stamp duty, sells do not; both pay
	 * commission and half the spread.
	 */
	function strategy_costs($side,$quantity,$price) {
		$consideration=$quantity*$price;
		$slippage=$consideration*(STRATEGY_SLIPPAGE_BPS/10000);
		$stamp_duty=($side==='BUY') ? $consideration*STRATEGY_STAMP_DUTY_RATE : 0;
		return [
			'consideration'=>$consideration,
			'commission'=>STRATEGY_COMMISSION,
			'stamp_duty'=>$stamp_duty,
			'slippage'=>$slippage,
			//Signed cash effect: a buy leaves the account, a sell arrives,
			//and costs are always a subtraction.
			'total_cost'=>($side==='BUY')
				? -($consideration+$slippage+$stamp_duty+STRATEGY_COMMISSION)
				:  ($consideration-$slippage-STRATEGY_COMMISSION),
		];
	}

	/**
	 * Record an order as PENDING before anything is placed.
	 *
	 * Returns the client_order_id on success, or null if an order for this
	 * intent already exists. That null is the crash-safety property: a run
	 * that died after recording but before filling will find the row on
	 * restart and resume it rather than placing a second order.
	 */
	function strategy_record_order($strategy,$as_of_date,$order) {

		$client_order_id=strategy_order_id($strategy,$as_of_date,$order['symbol']);

		$existing=query("select status from strategy_orders where client_order_id=?",$client_order_id);
		if (count($existing)>0){
			write_log("strategy","order ".$client_order_id." already exists with status ".$existing[0]['status']);
			return null;
		}

		$written=query("insert into strategy_orders
			(client_order_id,strategy,as_of_date,symbol,exchange,side,quantity,status,created_at)
			values (?,?,?,?,?,?,?,'PENDING',?)",
			$client_order_id,$strategy,$as_of_date,$order['symbol'],$order['exchange'],
			$order['side'],$order['quantity'],date('Y-m-d H:i:s'));

		//The unique key is the backstop: if two processes raced, the loser
		//gets false here and must not proceed to fill.
		if ($written===false){
			write_log("strategy","refused duplicate order ".$client_order_id);
			return null;
		}

		return $client_order_id;
	}

	/**
	 * Fill one PENDING order against the next available session's price.
	 *
	 * This is the paper broker. It is the only function that moves cash or
	 * positions, and it leaves the order row FILLED or REJECTED either way,
	 * so nothing is ever left in an unknown state.
	 */
	function strategy_fill_order($client_order_id) {

		$rows=query("select * from strategy_orders where client_order_id=?",$client_order_id);
		if (count($rows)===0){ return false; }
		$order=$rows[0];

		if ($order['status']!=='PENDING'){
			//Already resolved. Re-running must not fill it twice.
			return $order['status']==='FILLED';
		}

		$reject=function($reason) use ($client_order_id) {
			query("update strategy_orders set status='REJECTED', note=?, filled_at=? where client_order_id=?",
				substr($reason,0,255),date('Y-m-d H:i:s'),$client_order_id);
			write_log("strategy","rejected ".$client_order_id.": ".$reason);
			return false;
		};

		$fill=strategy_next_price($order['symbol'],$order['exchange'],$order['as_of_date']);
		if ($fill===null){
			//No session after the as of date yet. This is the guard against
			//filling a rebalance at the same close that produced it.
			return $reject("no price after ".$order['as_of_date']);
		}

		$quantity=(int)$order['quantity'];
		$costs=strategy_costs($order['side'],$quantity,$fill['price']);

		$account=strategy_account($order['strategy']);
		$positions=strategy_positions($order['strategy']);
		$held=isset($positions[$order['symbol']]) ? (int)$positions[$order['symbol']]['quantity'] : 0;

		if ($order['side']==='BUY'&&((float)$account['cash']+$costs['total_cost'])<0){
			return $reject("insufficient cash: need ".round(-$costs['total_cost'],2)." have ".$account['cash']);
		}
		if ($order['side']==='SELL'&&$quantity>$held){
			//No shorting in a long only paper book.
			return $reject("cannot sell ".$quantity." of ".$order['symbol'].", hold ".$held);
		}

		//Cash first, then position, then the order row. If the process dies
		//between these the order stays PENDING and the next run resumes it;
		//the reconciliation in run_paper_execution.php reports the gap.
		query("update strategy_accounts set cash=cash+? where strategy=?",$costs['total_cost'],$order['strategy']);

		if ($order['side']==='BUY'){
			$new_quantity=$held+$quantity;
			//Average cost carries the dealing costs, so realised profit is
			//measured after what it actually took to get in.
			$previous_cost=$held * (isset($positions[$order['symbol']]) ? (float)$positions[$order['symbol']]['avg_price'] : 0);
			$avg_price=($previous_cost + (-$costs['total_cost'])) / max($new_quantity,1);
		}
		else {
			$new_quantity=$held-$quantity;
			$avg_price=isset($positions[$order['symbol']]) ? (float)$positions[$order['symbol']]['avg_price'] : 0;
		}

		query("insert into strategy_positions (strategy,symbol,exchange,quantity,avg_price,updated_at)
			values (?,?,?,?,?,?)
			on duplicate key update quantity=values(quantity), avg_price=values(avg_price), updated_at=values(updated_at)",
			$order['strategy'],$order['symbol'],$order['exchange'],$new_quantity,round($avg_price,4),date('Y-m-d H:i:s'));

		query("update strategy_orders set status='FILLED', fill_date=?, fill_price=?, consideration=?,
			commission=?, stamp_duty=?, slippage=?, total_cost=?, filled_at=? where client_order_id=?",
			$fill['date'],$fill['price'],round($costs['consideration'],4),
			round($costs['commission'],4),round($costs['stamp_duty'],4),round($costs['slippage'],4),
			round($costs['total_cost'],4),date('Y-m-d H:i:s'),$client_order_id);

		return true;
	}

	/**
	 * Resolve orders a previous run left PENDING.
	 *
	 * Safe to do automatically only because this is a paper broker: the order
	 * demonstrably never went anywhere, so filling it now is the completion of
	 * the original intent. Against a real broker a PENDING order is genuinely
	 * unknown - it may have filled, partially filled, or been rejected - and
	 * the only correct move is to query the broker by client_order_id and, if
	 * that is inconclusive, halt for a human. Hence the mode check.
	 */
	function strategy_resume_pending($strategy) {

		$account=strategy_account($strategy);
		if ($account===null||$account['mode']!=='PAPER'){
			return ['resumed'=>0,'failed'=>0,'halted'=>true];
		}

		$resumed=$failed=0;
		foreach (query("select client_order_id from strategy_orders where strategy=? and status='PENDING' order by created_at",$strategy) as $row) {
			write_log("strategy","resuming ".$row['client_order_id']." left pending by an earlier run");
			if (strategy_fill_order($row['client_order_id'])){ $resumed++; } else { $failed++; }
		}

		return ['resumed'=>$resumed,'failed'=>$failed,'halted'=>false];
	}

	/**
	 * Compare the order history against holdings and cash.
	 *
	 * The paper broker and the ledger are the same database here, so this
	 * cannot catch a broker disagreement the way the real thing must. What it
	 * does catch is the failure that actually happens: orders left PENDING by
	 * a run that died, and positions that went negative.
	 */
	function strategy_reconcile($strategy) {

		$problems=[];

		$pending=query("select client_order_id, as_of_date, symbol, side, quantity from strategy_orders
			where strategy=? and status='PENDING' order by created_at",$strategy);
		foreach ($pending as $order) {
			$problems[]="order ".$order['client_order_id']." is still PENDING from ".$order['as_of_date'];
		}

		foreach (query("select symbol, quantity from strategy_positions where strategy=? and quantity<0",$strategy) as $row) {
			$problems[]="position ".$row['symbol']." is negative (".$row['quantity'].")";
		}

		$account=strategy_account($strategy);
		if ($account===null){ $problems[]="no account row"; }
		elseif ((float)$account['cash']<0){ $problems[]="cash is negative (".$account['cash'].")"; }

		return $problems;
	}

?>
