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
	/* The kill switch: a file whose existence stops everything.
	
	   /var/lib/sharemanager, not /var/www/shares, which was the pre-2026
	   server layout and does not exist. php-fpm's open_basedir blocked it,
	   so the page's dry run logged a warning on every load and could never
	   see the switch, and on the command line it only worked if somebody
	   knew to create a file in a directory that was not there. A safety
	   mechanism nobody can reach is not one.
	
	   SM_KILL_SWITCH_FILE overrides it, named like the application's other
	   environment settings. STRATEGY_KILL_SWITCH_FILE is still read after
	   it: the test suite has used that name since the engine was written,
	   and renaming a variable is not worth breaking a halt path over. */
	define("STRATEGY_KILL_SWITCH_FILE",
		getenv("SM_KILL_SWITCH_FILE")
		?: (getenv("STRATEGY_KILL_SWITCH_FILE") ?: "/var/lib/sharemanager/STOP_TRADING"));

	// UK dealing costs. Stamp duty is 0.5% on purchases of UK shares; AIM
	// stocks are exempt, which this does not model, so buy costs are if
	// anything overstated for an AIM heavy book.
	define("STRATEGY_STAMP_DUTY_RATE", 0.005);
	define("STRATEGY_COMMISSION",      5.95);      // per order, flat
	define("STRATEGY_SLIPPAGE_BPS",    25);        // half spread, each way

	// Below this consideration an order costs more in commission and stamp
	// than the position adjustment is worth, so it is not placed at all.
	define("STRATEGY_MIN_ORDER_VALUE",  250.00);

	// A holding whose weight is within this of its target is left alone. Two
	// percentage points on a 6.67% target is a third of the position, which
	// sounds generous until you price the alternative: trimming fifteen
	// holdings by a few shares every rebalance is thirty orders a month, at
	// 5.95 plus stamp plus spread, to correct a drift the market will undo
	// by Thursday.
	define("STRATEGY_DRIFT_BAND", 0.02);

	// How many sessions the rest of the market can trade without a holding
	// before the holding is treated as having stopped trading. One missing
	// day is a data gap; a week is a takeover, a suspension or a delisting.
	define("STRATEGY_STALE_SESSIONS", 5);

	//---------------------------------------------------------------------
	// The two ways an account can trade.
	//
	// PAPER is this database pretending to be a broker: whole shares, a
	// GBP 5.95 commission and a GBP 250 minimum order, because that is what
	// the engine was written against in September 2026 and changing it now
	// would make the paper record incomparable with its own past.
	//
	// T212_DEMO sends the same plan to Trading 212's practice account,
	// which charges no commission and deals from GBP 1 in fractions of a
	// share. A GBP 250 account can hold fifteen names at about GBP 16.67
	// each - but only if the quantity can be 0.44 of a share, which is why
	// the minimum and the rounding belong to the mode rather than to the
	// engine.
	//
	// NOTHING HERE TRADES REAL MONEY. T212_DEMO posts to the demo host and
	// to nothing else; see includes/broker_orders.php.
	//---------------------------------------------------------------------

	define("STRATEGY_MODE_PAPER",     "PAPER");
	define("STRATEGY_MODE_T212_DEMO", "T212_DEMO");
	/* Real money, in Dale's Trading 212 Invest account, and nothing about
	   the mode alone lets an order out: see includes/broker_live.php for
	   the conditions, which include the account being armed from the page
	   and the batch being approved. */
	define("STRATEGY_MODE_T212_LIVE", "T212_LIVE");

	/* A replay of a Trading 212 strategy: its dealing rules, and no broker.
	 *
	 * A backtest of the practice strategy used to run as a paper account,
	 * so it replayed whole shares, GBP 5.95 a deal and a GBP 250 minimum -
	 * none of which the practice account pays - and the figures it produced
	 * were a different strategy's. Copying T212_DEMO onto the backtest
	 * account instead would be worse: the engine would leave its orders
	 * PENDING for a broker to fill, the selection would be narrowed to the
	 * instruments Trading 212 lists, and an account the sender might one
	 * day pick up would exist for every run.
	 *
	 * So the dealing rules without the broker: no commission, fractions,
	 * the Trading 212 minimum, filled here from stored prices like any
	 * paper account. Only backtest accounts are given it - see
	 * strategy_backtest_mode(). */
	define("STRATEGY_MODE_PAPER_T212", "PAPER_T212");

	/* The figures a Trading 212 account trades by - the minimum order, the
	   quantity precision, the per-run cap, the session times - are in
	   constants.php beside the rest of the broker's settings, because the
	   broker page reads them too and does not load the strategy engine. */

	/**
	 * What a mode allows, in one place.
	 *
	 * Every figure the engine uses that differs between a paper account and
	 * a Trading 212 one is here, so the difference is a table rather than a
	 * scattering of if statements - and so that the paper account's
	 * behaviour is demonstrably unchanged by stage 2.
	 */
	function strategy_mode_rules($mode) {

		$mode = strtoupper(trim((string) $mode));

		if ($mode === STRATEGY_MODE_T212_LIVE) {
			/* The same dealing rules as the practice account, because it is
			   the same broker and the same instruments. What differs is
			   everything around the order: the environment it is sent to,
			   the caps in constants.php, and the arming and approval that
			   have to be in place before anything is sent at all. */
			return [
				'mode'          => STRATEGY_MODE_T212_LIVE,
				'broker'        => BROKER_T212,
				'demo'          => false,
				'live'          => true,
				'environment'   => 'live',
				'minimum_order' => T212_MIN_ORDER_VALUE,
				'fractional'    => true,
				'decimals'      => max(0, min(8, (int) T212_QUANTITY_DECIMALS)),
				'commission'    => 0.0,
				'words'         => 'Trading 212 live',
			];
		}

		if ($mode === STRATEGY_MODE_PAPER_T212) {
			return [
				'mode'          => STRATEGY_MODE_PAPER_T212,
				/* No broker: nothing sends its orders and nothing waits
				   for a fill from anywhere but the stored prices. */
				'broker'        => null,
				'demo'          => false,
				'live'          => false,
				'environment'   => null,
				'minimum_order' => T212_MIN_ORDER_VALUE,
				'fractional'    => true,
				'decimals'      => max(0, min(8, (int) T212_QUANTITY_DECIMALS)),
				'commission'    => 0.0,
				'words'         => 'replay, Trading 212 costs',
			];
		}

		if ($mode === STRATEGY_MODE_T212_DEMO) {
			return [
				'mode'          => STRATEGY_MODE_T212_DEMO,
				'broker'        => BROKER_T212,
				'demo'          => true,
				'live'          => false,
				'environment'   => 'demo',
				'minimum_order' => T212_MIN_ORDER_VALUE,
				'fractional'    => true,
				'decimals'      => max(0, min(8, (int) T212_QUANTITY_DECIMALS)),
				'commission'    => 0.0,
				'words'         => 'Trading 212 demo',
			];
		}

		return [
			'mode'          => STRATEGY_MODE_PAPER,
			'broker'        => null,
			'demo'          => false,
			'live'          => false,
			'environment'   => null,
			'minimum_order' => STRATEGY_MIN_ORDER_VALUE,
			'fractional'    => false,
			'decimals'      => 0,
			'commission'    => STRATEGY_COMMISSION,
			'words'         => 'paper',
		];
	}

	/** Whether a mode is one this application knows how to run. */
	function strategy_mode_known($mode) {
		return in_array(strtoupper(trim((string) $mode)),
		                [STRATEGY_MODE_PAPER, STRATEGY_MODE_PAPER_T212,
		                 STRATEGY_MODE_T212_DEMO, STRATEGY_MODE_T212_LIVE], true);
	}

	/**
	 * The mode a replay of this strategy deals under.
	 *
	 * A backtest answers "what would these rules have done", and the costs
	 * are part of the rules: fifteen names of GBP 16.67 are possible for
	 * the practice account and refused for the paper one, so replaying
	 * either under the other's dealing rules answers about a strategy
	 * nobody runs. The broker is not part of it - see
	 * STRATEGY_MODE_PAPER_T212.
	 */
	function strategy_backtest_mode($mode) {
		$rules = strategy_mode_rules($mode);
		return ($rules['broker'] === null)
		     ? STRATEGY_MODE_PAPER : STRATEGY_MODE_PAPER_T212;
	}

	/** Whether a mode deals with real money. */
	function strategy_mode_is_live($mode) {
		return strtoupper(trim((string) $mode)) === STRATEGY_MODE_T212_LIVE;
	}

	/**
	 * A quantity rounded to what the mode can actually trade, always DOWN.
	 *
	 * Down, so an order never costs more than the target it was sized from.
	 * The cash left over sits idle until the next rebalance, which is the
	 * right direction to be wrong in: the alternative is an order the
	 * account cannot pay for.
	 */
	function strategy_round_quantity($quantity, $rules) {

		$quantity = (float) $quantity;
		if ($quantity <= 0) { return 0.0; }

		if (empty($rules['fractional'])) { return (float) floor($quantity); }

		$factor = pow(10, max(0, (int) $rules['decimals']));
		return floor($quantity * $factor) / $factor;
	}

	// The benchmark's own row in strategy_values. Underscore first so it can
	// never collide with a strategy name: strategy_strategies() skips it.
	define("STRATEGY_BENCHMARK", "_benchmark");

	/**
	 * Trading is halted if the kill switch file exists. Checked before every
	 * order, not once per run, so dropping the file stops a run in progress.
	 */
	function strategy_halted() {
		/* Silenced deliberately. file_exists() on a path outside php-fpm's
		   open_basedir raises a warning rather than answering, and this is
		   called on every page load and before every order: the answer
		   "I cannot see that path" has to be "not halted" without printing
		   anything into the page. A switch the server cannot read is a
		   configuration problem, reported by the page's own banner and by
		   the deploy notes, not by a warning in somebody's HTML. */
		return @file_exists(STRATEGY_KILL_SWITCH_FILE);
	}

	/**
	 * Whether the kill switch file can be seen at all, for the page to say
	 * so. False means the path is outside what PHP is allowed to read, so
	 * "not halted" is an assumption rather than an answer.
	 */
	function strategy_kill_switch_readable() {

		$directory=dirname(STRATEGY_KILL_SWITCH_FILE);

		if (@is_dir($directory)){ return true; }

		/* open_basedir makes is_dir() false for a path outside it, and so
		   does a directory that simply is not there. The two are different
		   problems and the message says which it cannot tell. */
		return false;
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
	 * What currency a share is quoted in, upper case, with pence spelled GBX.
	 *
	 * stock_symbols.quote_ccy when the loader has looked it up, otherwise
	 * pence on XLON and pounds anywhere else. Pence is not a guess there: it
	 * is what the London exchange quotes in, and what the rest of the
	 * application already assumes - portfolio_functions.php divides by 100
	 * in half a dozen places.
	 *
	 * Not report_ccy, which is what the company's ACCOUNTS are in. Shell
	 * reports in dollars and is quoted in pence; the two corrections are for
	 * different sums. See sql/strategy_prices.sql.
	 */
	function strategy_quote_ccy($symbol,$exchange) {

		static $cache=[];
		$key=$exchange.'|'.$symbol;

		if (isset($cache[$key])){ return $cache[$key]; }

		$default=(strtoupper((string)$exchange)==='XLON') ? 'GBX' : 'GBP';

		$ready=query("select count(*) n from information_schema.columns
		               where table_schema=database() and table_name='stock_symbols'
		                 and column_name='quote_ccy'");

		if (!count($ready)||(int)$ready[0]['n']!==1){
			/* sql/strategy_prices.sql has not been applied. Carry on with
			   the exchange's own currency rather than refusing to trade: the
			   column tells pence from dollars, it does not tell London from
			   nothing. */
			return $cache[$key]=$default;
		}

		$rows=query("select quote_ccy from stock_symbols where symbol=? and exchange=?",
		            $symbol,$exchange);

		if (!count($rows)||$rows[0]['quote_ccy']===null
		    ||trim((string)$rows[0]['quote_ccy'])===''){
			return $cache[$key]=$default;
		}

		$raw=trim((string)$rows[0]['quote_ccy']);

		/* THE CASE MATTERS, and this is the one place in the application
		   where it does. The provider writes pence as "GBp" and pounds as
		   "GBP": the same three letters, a hundred times apart. Upper-casing
		   first would turn every pence-quoted London line into a
		   pound-quoted one and put the hundred-times fault straight back.
		
		   Normalised to GBX, the ISO code for pence, so nothing downstream
		   has to remember this. ZAc and ILA need no such care: they differ
		   from ZAR and ILS in more than case. */
		if ($raw==='GBp'||strtoupper($raw)==='GBX'||strtoupper($raw)==='GBPENCE'){
			return $cache[$key]='GBX';
		}

		return $cache[$key]=strtoupper($raw);
	}

	/**
	 * A stored price, in pounds.
	 *
	 * The engine sizes, fills, values and costs in pounds, because the costs
	 * are in pounds: 5.95 a trade and a 250 pound minimum order mean nothing
	 * against a number that might be pence. So the conversion happens once,
	 * here, at the two places a price enters the engine.
	 *
	 * This is the second deploy's fault. historical_prices holds London
	 * prices as the provider sends them, which is pence, and the engine read
	 * 690p as 690 pounds - so a 100,000 pound account invested about 1,000,
	 * the commission was a tenth of every order, and "one share of Shell"
	 * made the weights meaningless.
	 *
	 *   GBX, ZAC, ILA   a minor unit   divided by 100, then converted
	 *   GBP             pounds         as it is
	 *   anything else   a major unit   converted through fx_rates, NOT
	 *                                  divided: a dollar quote is in
	 *                                  dollars, not in cents
	 *
	 * Returns null when a foreign quote has no rate for the date. That is
	 * deliberate, and it is the same contract as "no price": a position
	 * sized through a missing exchange rate is a wrong position rather than
	 * a late one.
	 */
	function strategy_price_pounds($price,$symbol,$exchange,$date) {

		if ($price===null){ return null; }

		$price=(float)$price;
		$currency=strategy_quote_ccy($symbol,$exchange);

		/* The same list python/fmp.py keeps in MINOR_UNIT_CURRENCIES, which
		   it uses to put the market cap in the same unit as the price.
		   Dividing before converting is what makes a dual-listed line work:
		   a ZAc quote needs both corrections, and either alone is wrong by a
		   factor of a hundred. */
		$minor_units=['GBX'=>'GBP','ZAC'=>'ZAR','ILA'=>'ILS'];

		if (isset($minor_units[$currency])){
			$price=$price/100.0;
			$currency=$minor_units[$currency];
		}

		if ($currency==='GBP'){ return $price; }

		/* fx_rates holds quote units per pound, so a price in dollars is
		   divided by GBPUSD to get pounds. The rate on the date if there is
		   one, else the most recent before it: a rate is a daily figure and
		   Friday's is the right one for Saturday. */
		if (!function_exists('fx_rate_for')){ return null; }

		$rate=fx_rate_for($date,$currency);

		if ($rate===null){
			$rows=query("select rate from fx_rates where base='GBP' and quote_ccy=? and date<=?
			              order by date desc limit 1",$currency,$date);
			$rate=count($rows) ? (float)$rows[0]['rate'] : null;
		}

		if ($rate===null||$rate<=0){
			write_log("strategy","no ".$currency." rate for ".$date." to price ".$symbol
			          ."; refusing to value or trade it");
			return null;
		}

		return $price/$rate;
	}

	/**
	 * Last known price on or before a date, IN POUNDS. Returns null when
	 * there is none, which callers must treat as "do not trade this symbol".
	 */
	function strategy_price_asof($symbol,$exchange,$date) {
		$rows=query("select date, price from historical_prices where symbol=? and exchange=? and date<=? order by date desc limit 1",
			$symbol,$exchange,$date);
		if (!count($rows)||$rows[0]['price']===null){ return null; }
		return strategy_price_pounds($rows[0]['price'],$symbol,$exchange,
		                             substr((string)$rows[0]['date'],0,10));
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

		if (!count($rows)){ return null; }

		$pounds=strategy_price_pounds($rows[0]['price'],$symbol,$exchange,
		                              substr((string)$rows[0]['date'],0,10));

		if ($pounds===null){ return null; }

		return ['date'=>$rows[0]['date'],'price'=>$pounds];
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
			$value += $price * (float)$position['quantity'];
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
	function strategy_plan_orders($strategy,$as_of_date,$targets=null,$options=[]) {

		/* 'notes' is for something a reader should know that is not a
		   problem: the live capital cap binding a plan is the system doing
		   what it was told, and reporting it as an error would make the
		   nightly job exit 1 on a night when nothing went wrong. */
		$plan=['orders'=>[],'errors'=>[],'skipped'=>[],'notes'=>[],'account_value'=>null,
		       'mode'=>STRATEGY_MODE_PAPER,'nothing_to_do'=>false];

		$account=strategy_account($strategy);
		if ($account===null){ $plan['errors'][]="no account for strategy ".$strategy; return $plan; }
		if ($account['enabled']!=='Y'){ $plan['errors'][]="account is disabled"; return $plan; }
		if (!strategy_mode_known($account['mode'])){
			$plan['errors'][]="mode '".$account['mode']."' is not one this knows how to run";
			return $plan;
		}
		if ($account['halted']==='Y'){
			$plan['errors'][]="halted: ".(string)$account['halt_reason'];
			return $plan;
		}

		/* Everything that differs between a paper account and a Trading 212
		   one: the minimum order, whether a quantity can be a fraction, and
		   the commission. The paper account's figures are unchanged, so the
		   paper record stays comparable with its own past. */
		$mode=strategy_mode_rules($account['mode']);
		$plan['mode']=$mode['mode'];

		$account_value=strategy_account_value($strategy,$as_of_date);
		if ($account_value===null){ $plan['errors'][]="cannot value the account, refusing to trade"; return $plan; }
		if ($account_value<=0){ $plan['errors'][]="account value is not positive"; return $plan; }
		$plan['account_value']=$account_value;

		/* THE LIVE ACCOUNT'S CAPITAL CAP.
		 *
		 * The strategy's cash is its own ledger - what Dale assigned at
		 * arming - and the cap is what it may ever have invested plus
		 * reserved. Sizing against the ledger would already respect it, but
		 * only while the two agree: a deposit into the Trading 212 account,
		 * or a cash row somebody edited, must not make the plan bigger.
		 * So the plan is sized against the smaller of the two, and says so
		 * when the cap is what bound it.
		 *
		 * An unarmed live account is sized against the constant ceiling in
		 * constants.php. It can send nothing either way; what this buys is
		 * that the orders waiting for an arming are the orders that arming
		 * would allow. */
		$live_limits=null;
		if (!empty($mode['live'])&&function_exists('broker_live_plan_limits')){
			$live_limits=broker_live_plan_limits($strategy);
			if ($account_value>$live_limits['capital']){
				$plan['notes'][]="sized against the ".$live_limits['capital']
				                 ." capital cap rather than the ".round($account_value,2)
				                 ." in the account"
				                 .($live_limits['armed'] ? "" : " (nothing is armed, so this"
				                                               ." is the ceiling in"
				                                               ." constants.php)");
				$account_value=$live_limits['capital'];
				$plan['account_value']=$account_value;
			}
		}

		/* Targets come from the table unless the caller hands them over. The
		   override is what makes a dry run on a rebalance date possible: the
		   plan has to be shown without writing the targets it is planned
		   from, and computing them twice - once for the dry run, once for
		   the real one - would be two answers to one question. */
		if ($targets===null){
			$targets=query("select * from strategy_targets where strategy=? and as_of_date=?",$strategy,$as_of_date);
		}
		if (count($targets)===0){
			/* On a night that is not a rebalance there are no targets, and
			   that is the normal state of a monthly strategy on the other
			   thirty nights of the month. It was reported as a problem,
			   which failed the nightly job for doing exactly what it is
			   supposed to do. The dry run and the backtest each filtered
			   the sentence out again by matching on its text; neither has
			   to now, because it is not said.

			   On a night that IS a rebalance, no targets is a real problem
			   and stays one: the selection found nothing. */
			if (array_key_exists('rebalance',$options)&&$options['rebalance']===false){
				$plan['nothing_to_do']=true;
				return $plan;
			}
			$plan['errors'][]="no targets for ".$as_of_date;
			return $plan;
		}

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
		$investable=($account_value-($mode['commission']*count($targets)))/$cost_multiplier;
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

			/* A share the broker will not deal in fractions of is sized in
			   whole shares whatever the mode allows. Learnt from the broker
			   refusing one, not guessed at. */
			$share_rules=$mode;
			if (!empty($mode['fractional'])&&strategy_whole_shares_only($symbol,$mode['broker'])){
				$share_rules['fractional']=false;
				$share_rules['decimals']=0;
			}

			$target_quantity=strategy_round_quantity(($weight*$investable)/$price,$share_rules);
			$held=isset($positions[$symbol]) ? (float)$positions[$symbol]['quantity'] : 0.0;
			$delta=$target_quantity-$held;

			/* Leave a holding alone while its weight is close enough. A
			   share bought at 6.67% that is now 8% is not a different
			   portfolio from the one the signal asked for; selling a few
			   shares to make it 6.67% again pays commission, stamp and
			   spread for a decimal place. Measured on the holding's actual
			   weight rather than on the share count, because that is what
			   the band is about.

			   A name that is not held yet has a weight of zero, so a new
			   holding is always outside the band, and so is a position being
			   built from cash. */
			if ($held>0){
				$held_weight=($held*$price)/$account_value;
				if (abs($held_weight-$weight)<=STRATEGY_DRIFT_BAND){ continue; }
			}

			/* Skip adjustments too small to be worth placing. On a paper
			   account that is GBP 250, because a one share correction on a
			   160 pound stock costs commission plus stamp to move the weight
			   by a rounding error. On a Trading 212 account there is no
			   commission and the minimum is GBP 10, so a small account can
			   actually hold fifteen names.

			   An order that rounds away to nothing, or that falls under the
			   minimum, is recorded with its reason rather than dropped in
			   silence: its cash sits idle until the next rebalance and
			   somebody should be able to see why. */
			$value=abs($delta)*$price;

			if (abs($delta)>0&&$value<$mode['minimum_order']){
				$plan['skipped'][]=['symbol'=>$symbol,'side'=>$delta>0?'BUY':'SELL',
				                    'quantity'=>abs($delta),'value'=>$value,
				                    'reason'=>'under the '.$mode['minimum_order']
				                             .' minimum order for '.$mode['words']];
			}
			else if (abs($delta)<=0&&$target_quantity<=0&&$held<=0){
				$plan['skipped'][]=['symbol'=>$symbol,'side'=>'BUY','quantity'=>0.0,
				                    'value'=>0.0,
				                    'reason'=>'the target rounds to nothing at '
				                             .round($price,2).' a share'];
			}

			/* And no single live order over its share of the capital cap.
			   Fifteen names of a GBP 250 account are 6.7% each, so an order
			   over 15% is a sizing bug rather than a big position - and the
			   send path refuses it, so planning it would only mean a plan
			   that cannot be carried out.

			   Buys only. The caps limit what can be put at risk, and
			   selling is the direction that reduces it: a sale held back
			   for being large would leave a position the strategy has
			   decided to be out of. */
			if ($live_limits!==null&&$delta>0&&$value>$live_limits['per_order']+0.0001){
				$plan['skipped'][]=['symbol'=>$symbol,'side'=>'BUY',
				                    'quantity'=>abs($delta),'value'=>$value,
				                    'reason'=>'over the '.$live_limits['per_order']
				                             .' a single live order may be'];
				continue;
			}

			if (abs($delta)>0&&$value>=$mode['minimum_order']){
				$plan['orders'][]=[
					'symbol'=>$symbol,'exchange'=>$target['exchange'],
					'side'=>$delta>0?'BUY':'SELL','quantity'=>abs($delta),
					'reference_price'=>$price,
					/* What the order is for, in data rather than in prose.
					   The dry run shows this to somebody deciding whether
					   tonight looks right, and "BUY 2876 PENCE1" on its own
					   does not answer that. */
					'held'=>$held,
					'held_weight'=>($account_value>0) ? ($held*$price)/$account_value : null,
					'target_weight'=>$weight,
					'reason_code'=>($held===0) ? 'new_holding' : 'back_to_target',
				];
			}
		}

		//Exits: anything held that is no longer a target
		foreach ($positions as $symbol=>$position) {
			if (!isset($wanted[$symbol])&&(float)$position['quantity']>0){
				$price=strategy_price_asof($symbol,$position['exchange'],$as_of_date);
				$held=(float)$position['quantity'];
				$plan['orders'][]=[
					'symbol'=>$symbol,'exchange'=>$position['exchange'],
					'side'=>'SELL','quantity'=>$held,
					'reference_price'=>$price,
					'held'=>$held,
					'held_weight'=>($account_value>0&&$price!==null)
					              ? ($held*$price)/$account_value : null,
					'target_weight'=>0.0,
					'reason_code'=>'no_longer_selected',
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

		/* The first build of an account is exempt. Under the definition
		   above it scores zero anyway - an all-cash build is all buys and no
		   sells - so this is belt and braces rather than a fix: it says in
		   code that filling an empty account from cash is not churn, so that
		   a later change to how turnover is measured cannot stop an account
		   ever opening. Nothing else is exempt, and the second run is
		   capped like every other. */
		$first_build=strategy_first_build($strategy);

		if (!$first_build&&$account_value>0&&($turnover/$account_value)>STRATEGY_MAX_TURNOVER){
			$plan['errors'][]="turnover ".round($turnover/$account_value,3)." exceeds the limit of ".STRATEGY_MAX_TURNOVER;
			$plan['orders']=[];
			return $plan;
		}

		return $plan;
	}

	/**
	 * Whether the broker refuses fractions of this share.
	 *
	 * Null in broker_instruments means nobody has found out, which is the
	 * state of every row until an order is refused for its quantity. The
	 * instruments list carries no field that says, so this is learnt from
	 * the broker rather than guessed at - and a share that has never been
	 * ordered is sized in fractions until it says otherwise, which is the
	 * only answer that can be tested against anything.
	 */
	function strategy_whole_shares_only($symbol,$broker=null) {

		if ($broker===null){ return false; }

		/* Asked every time rather than cached. The run that learns this is
		   the run that sends orders, and it plans again afterwards: a cache
		   taken before the broker refused an order would still be saying
		   "fractions are fine" when the next plan is written. One indexed
		   row per symbol is not worth being wrong about. */
		$rows=query("select whole_shares from broker_instruments
		              where broker=? and symbol=? and whole_shares='Y'",$broker,$symbol);

		return count($rows)>0;
	}

	/**
	 * Dealing costs for one fill. Buys pay stamp duty, sells do not; both pay
	 * commission and half the spread.
	 */
	function strategy_costs($side,$quantity,$price,$mode=null) {

		/* The commission belongs to the mode: Trading 212 charges none,
		   and the paper account's GBP 5.95 is what its own record was built
		   on. Stamp duty and the spread do not care who the broker is -
		   half a per cent on a purchase of UK shares is the government's,
		   whatever the size, and the spread is the market's. The PTM levy
		   starts at GBP 10,000 and cannot apply to an account this size. */
		$rules=strategy_mode_rules($mode===null ? STRATEGY_MODE_PAPER : $mode);

		$consideration=$quantity*$price;
		$slippage=$consideration*(STRATEGY_SLIPPAGE_BPS/10000);
		$stamp_duty=($side==='BUY') ? $consideration*STRATEGY_STAMP_DUTY_RATE : 0;
		return [
			'consideration'=>$consideration,
			'commission'=>$rules['commission'],
			'stamp_duty'=>$stamp_duty,
			'slippage'=>$slippage,
			//Signed cash effect: a buy leaves the account, a sell arrives,
			//and costs are always a subtraction.
			'total_cost'=>($side==='BUY')
				? -($consideration+$slippage+$stamp_duty+$rules['commission'])
				:  ($consideration-$slippage-$rules['commission']),
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
	function strategy_record_order($strategy,$as_of_date,$order,$note=null) {

		$client_order_id=strategy_order_id($strategy,$as_of_date,$order['symbol']);

		$existing=query("select status from strategy_orders where client_order_id=?",$client_order_id);
		if (count($existing)>0){
			write_log("strategy","order ".$client_order_id." already exists with status ".$existing[0]['status']);
			return null;
		}

		/* WHICH BATCH, for a live account.
		 *
		 * A live account's orders are planned into one batch a night and
		 * the batch waits for Dale to approve it; the order carries which
		 * batch it was in, so "was this approved" has an answer years
		 * later. An exit is in the same batch as the buys, deliberately:
		 * a batch that could be half approved is a portfolio nobody chose.
		 *
		 * When includes/broker_live.php is not loaded - the nightly job
		 * does not need the broker layer to plan a paper account - a live
		 * order gets no batch, and an order with no batch can never be
		 * approved, so nothing can be sent. Failing closed is the only
		 * acceptable direction for this one.
		 */
		$batch=null;
		if (function_exists('broker_live_batch_open')){
			$account=strategy_account($strategy);
			if ($account!==null&&strategy_mode_is_live($account['mode'])){
				$batch=broker_live_batch_open($strategy,$as_of_date);
			}
		}

		/* The note is why, in the order's own row: 'rebalance' or the name
		   of the exit rule that sold it. The strategy page reads it back as
		   the reason column, so a trade can be accounted for a year later
		   without re-deriving the signal. */
		$written=($batch===null)
		       ? query("insert into strategy_orders
			(client_order_id,strategy,as_of_date,symbol,exchange,side,quantity,status,note,created_at)
			values (?,?,?,?,?,?,?,'PENDING',?,?)",
			$client_order_id,$strategy,$as_of_date,$order['symbol'],$order['exchange'],
			$order['side'],$order['quantity'],
			$note===null ? null : substr((string)$note,0,255),
			date('Y-m-d H:i:s'))
		       : query("insert into strategy_orders
			(client_order_id,strategy,as_of_date,batch,symbol,exchange,side,quantity,status,note,created_at)
			values (?,?,?,?,?,?,?,?,'PENDING',?,?)",
			$client_order_id,$strategy,$as_of_date,$batch,$order['symbol'],$order['exchange'],
			$order['side'],$order['quantity'],
			$note===null ? null : substr((string)$note,0,255),
			date('Y-m-d H:i:s'));

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
	function strategy_fill_order($client_order_id,$last_price_fallback=false) {

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

		/* A share that has stopped trading has no next price and never will,
		   so the ordinary guard would reject the sale for ever and the book
		   would carry a holding it cannot value or leave. The last price it
		   actually traded at is the only honest number available, and it is
		   a real traded close rather than an estimate - which is the line:
		   fill at the last price, say so on the order, and never invent one.
		   Only ever reached for an exit that asked for it; a rebalance keeps
		   the strict rule. */
		if ($fill===null&&$last_price_fallback){
			$last=strategy_price_asof($order['symbol'],$order['exchange'],$order['as_of_date']);
			if ($last!==null&&$last>0){
				$fill=['date'=>$order['as_of_date'],'price'=>$last];
				write_log("strategy","filling ".$client_order_id." at the last known price "
				          .$last." - no session after ".$order['as_of_date']);
			}
		}

		if ($fill===null){

			/* "Not yet" is not "never", and the difference is the whole
			   nightly run. An order planned tonight cannot fill tonight:
			   the price it fills at is tomorrow's close, which does not
			   exist while tonight's job is running. Rejecting it there
			   loses the rebalance for good - the order id is keyed on the
			   as of date, so tomorrow's run finds the rejected row and
			   places nothing - and a monthly strategy would never trade at
			   all.

			   So: if the market has not traded since the as of date, the
			   order stays PENDING and the next run resumes and fills it,
			   which is what strategy_resume_pending() is for. If the market
			   HAS traded since and this symbol still has no price, that is
			   a real failure and it is rejected as before; a holding whose
			   price has stopped altogether is the delisting case, which the
			   exit rules handle with the last traded price. */
			$later=query("select count(*) n from historical_prices
			               where exchange=? and date>?",
			             $order['exchange'],$order['as_of_date']);

			if (!count($later)||(int)$later[0]['n']===0){
				write_log("strategy","leaving ".$client_order_id." pending: the market has"
				          ." not traded since ".$order['as_of_date']);
				return false;
			}

			return $reject("no price after ".$order['as_of_date']);
		}

		$account=strategy_account($order['strategy']);
		$mode=strategy_mode_rules($account===null ? STRATEGY_MODE_PAPER : $account['mode']);

		$quantity=(float)$order['quantity'];
		$costs=strategy_costs($order['side'],$quantity,$fill['price'],$mode['mode']);

		$positions=strategy_positions($order['strategy']);
		$held=isset($positions[$order['symbol']]) ? (float)$positions[$order['symbol']]['quantity'] : 0.0;

		/* A buy the cash no longer covers is trimmed to fit rather than
		   rejected.
		
		   The quantities are decided from the as of close and filled at the
		   next one, so an overnight gap up makes the last order of a
		   rebalance cost more than the plan reserved - and rejecting it
		   leaves the strategy holding fourteen names instead of fifteen,
		   with the cash for the fifteenth sitting idle until the next
		   rebalance. Holding slightly less of a share than intended is a
		   rounding error; not holding it at all is a different portfolio.
		
		   This showed up when London prices started being read as pence:
		   the same weights in a hundredth of the units round to a far finer
		   number of shares, so the account is fully invested and there is no
		   slack left for the gap. The fault was always there - it just
		   needed the last order to be within a rounding error of the cash.
		
		   Never increased, only reduced, and the trim is noted on the order
		   so the audit trail says why the position is not the target. */
		if ($order['side']==='BUY'&&((float)$account['cash']+$costs['total_cost'])<0){

			$cash=(float)$account['cash'];
			$per_share=$fill['price']*(1+STRATEGY_STAMP_DUTY_RATE+(STRATEGY_SLIPPAGE_BPS/10000));
			$affordable=($per_share>0)
			          ? strategy_round_quantity(($cash-$mode['commission'])/$per_share,$mode) : 0.0;

			if ($affordable<=0){
				return $reject("insufficient cash: need ".round(-$costs['total_cost'],2)
				               ." have ".$account['cash']);
			}

			write_log("strategy","trimming ".$client_order_id." from ".$quantity." to "
			          .$affordable." shares: the fill price moved against the plan");

			$quantity=$affordable;
			$costs=strategy_costs($order['side'],$quantity,$fill['price'],$mode['mode']);

			query("update strategy_orders set quantity=?, note=? where client_order_id=?",
			      $quantity,
			      substr('trimmed to fit cash: '.(string)$order['note'],0,255),
			      $client_order_id);

			/* Belt and braces: if the trimmed order still does not fit, the
			   arithmetic above is wrong and the right answer is to refuse
			   rather than to overdraw a paper account. */
			if (($cash+$costs['total_cost'])<0){
				return $reject("insufficient cash after trimming to ".$quantity
				               .": need ".round(-$costs['total_cost'],2)." have ".$cash);
			}
		}
		if ($order['side']==='SELL'&&$quantity>$held+0.00000001){
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
			$avg_price=($new_quantity>0)
			         ? ($previous_cost + (-$costs['total_cost'])) / $new_quantity : 0.0;
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
		if ($account===null){
			return ['resumed'=>0,'failed'=>0,'halted'=>true];
		}

		/* Any account this database fills for, which is the paper account
		   and a replay of a broker one (STRATEGY_MODE_PAPER_T212): the
		   question is whether a pending order could have reached a broker,
		   and for those two it could not. An account with a broker is a
		   different matter entirely - see the comment above. */
		$mode=strategy_mode_rules($account['mode']);
		if ($mode['broker']!==null){
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


	/* =====================================================================
	   Strategies as configuration.

	   Everything above is the engine: it reconciles, plans, places and
	   fills, and it has no opinion about what should be held. Everything
	   below is what turns "the best N by overall_score" into a strategy
	   anybody can describe - what to select, how many, when to rebalance,
	   and when to leave before the next rebalance - read out of
	   strategy_rules rather than written into a script.

	   The engine's limits stay where they are. A rules row chooses from what
	   the engine allows; it cannot widen it.

	   See sql/strategy_rules.sql and includes/README-paper-trading.md.
	   ===================================================================== */

	// A backtest runs the same engine under its own account name. The
	// nightly job must never trade one, so the prefix is reserved here
	// rather than being a convention in the backtest script.
	define("STRATEGY_BACKTEST_PREFIX", "bt_");

	// What a backtest starts with. The same round number the paper accounts
	// were opened with, so the two are read on the same scale.
	define("STRATEGY_BACKTEST_CASH", 100000.00);

	/**
	 * True once sql/strategy_rules.sql has been applied.
	 *
	 * Same reason as fund_config_ready(): deploy/deploy.sh pulls code and
	 * restarts PHP but does not run migrations, so there is a window where
	 * this code is on the server and the tables are not. The nightly job
	 * says so and does nothing rather than dying on an unknown table.
	 */
	/**
	 * Whether the broker lists an instrument for a share, if it can be asked.
	 *
	 * Asked by the selection, which runs in the nightly job and knows
	 * nothing else about the broker, so the lookup is here rather than a
	 * require of broker_orders.php: the engine must not depend on the
	 * broker layer to plan a paper account.
	 *
	 * Returns true when there is no mapping table to ask. An answer of
	 * "cannot say" has to be "do not stop the share", or a database without
	 * sql/broker_mapping.sql would select nothing at all.
	 */
	function strategy_broker_lists($symbol,$broker) {

		static $ready=null;

		if ($broker===null||trim((string)$broker)===''){ return true; }

		if ($ready===null){
			$rows=query("select count(*) n from information_schema.tables
			              where table_schema=database() and table_name='broker_instruments'");
			$ready=(count($rows)&&(int)$rows[0]['n']===1);
		}
		if (!$ready){ return true; }

		$rows=query("select count(*) n from broker_instruments
		              where broker=? and symbol=? and status='matched'",$broker,$symbol);

		return count($rows)&&(int)$rows[0]['n']>0;
	}

	/**
	 * Whether the broker's instrument list has been read at all.
	 *
	 * An empty table is "nobody has asked Trading 212 what it lists", not
	 * "Trading 212 lists nothing". Reading it as the second would have a
	 * practice account select nothing on the night after the mapping was
	 * cleared, which is a silent way to stop trading.
	 *
	 * Asked once per selection rather than per candidate, and not cached
	 * between calls: the answer changes when a sync runs, and a stale yes
	 * is how a holding disappears.
	 */
	function strategy_broker_list_read($broker) {

		if ($broker===null||trim((string)$broker)===''){ return false; }

		$exists=query("select count(*) n from information_schema.tables
		                where table_schema=database() and table_name='broker_instruments'");
		if (!count($exists)||(int)$exists[0]['n']!==1){ return false; }

		$rows=query("select count(*) n from broker_instruments
		              where broker=? and status='matched'",$broker);

		return count($rows)&&(int)$rows[0]['n']>0;
	}

	function strategy_rules_ready() {

		static $ready=null;
		if ($ready!==null){ return $ready; }

		$rules=query("select count(*) n from information_schema.tables
		               where table_schema=database() and table_name='strategy_rules'");
		$values=query("select count(*) n from information_schema.tables
		                where table_schema=database() and table_name='strategy_values'");

		$ready=(count($rules)&&(int)$rules[0]['n']===1)
		     &&(count($values)&&(int)$values[0]['n']===1);

		if (!$ready){
			write_log("strategy","sql/strategy_rules.sql has not been applied: no strategy runs");
		}

		return $ready;
	}

	/**
	 * What to call a strategy on a page.
	 *
	 * The code name is what the tables are keyed on and what the scripts
	 * take on the command line, and it is not what a reader should be shown:
	 * "trend_following" is a column name. A display_name column on
	 * strategy_accounts carries the proper one; until the migration that
	 * adds it has been applied the code name is tidied up instead, so a page
	 * reads sensibly on a host that is one deploy behind.
	 */
	function strategy_display_name($strategy) {

		static $has_column = null;
		if ($has_column === null) {
			$found = query("select count(*) n from information_schema.columns
			                 where table_schema=database() and table_name='strategy_accounts'
			                   and column_name='display_name'");
			$has_column = count($found) && (int) $found[0]['n'] > 0;
		}

		if ($has_column) {
			$rows = query("select display_name from strategy_accounts where strategy=?", $strategy);
			if (count($rows) && trim((string) $rows[0]['display_name']) !== '') {
				return (string) $rows[0]['display_name'];
			}
		}

		return ucfirst(str_replace('_', ' ', (string) $strategy));
	}

	/**
	 * What a rule does, in words.
	 *
	 * The page printed "below_ma200_2d" and "6mnth" - the values as stored,
	 * which are an exit code and an indicator name. A reader cannot act on
	 * either, and a reader who could would not need the page.
	 *
	 * The exit codes are the same set strategy_reason_words() names on a
	 * trade, said here as a rule rather than as something that happened.
	 */
	function strategy_exit_words($code) {

		$words = [
			'below_ma200_2d' => 'Sells after two closes below the 200 day average',
			'delisted'       => 'Sells at the last price if a holding stops trading',
		];

		$code = strtolower(trim((string) $code));
		return isset($words[$code]) ? $words[$code] : $code;
	}

	/** How the selection is ranked, in words. */
	function strategy_rank_words($indicator) {

		$words = [
			'6mnth'          => 'Ranked by the 6 month return',
			'3mnth'          => 'Ranked by the 3 month return',
			'12mnth'         => 'Ranked by the 12 month return',
			'momentum_score' => 'Ranked by the momentum score',
			'value_score'    => 'Ranked by the value score',
			'quality_score'  => 'Ranked by the quality score',
			'overall_score'  => 'Ranked by the ShareRank',
		];

		$indicator = strtolower(trim((string) $indicator));
		if ($indicator === '') { return null; }

		if (isset($words[$indicator])) { return $words[$indicator]; }

		/* Anything else is a stored indicator name, so look up what it is
		   called rather than printing the column. */
		$rows = query("select description from screen_indicators where name=?", $indicator);
		return count($rows) && trim((string) $rows[0]['description']) !== ''
		     ? 'Ranked by ' . strtolower((string) $rows[0]['description'])
		     : 'Ranked by ' . $indicator;
	}

	/** How often it rebalances, as a sentence rather than an adverb. */
	function strategy_rebalance_words($rebalance) {
		$words = ['daily'   => 'Rebalanced every trading day',
		          'weekly'  => 'Rebalanced at the start of each week',
		          'monthly' => 'Rebalanced at the start of each month'];
		$rebalance = strtolower(trim((string) $rebalance));
		return isset($words[$rebalance]) ? $words[$rebalance] : 'Rebalanced ' . $rebalance;
	}



	/* ---------------------------------------------------------------------
	   What the Strategies page needs: words for the modes, the summary
	   rows behind the menu and the table, the pairs, and the backtest
	   labels.
	   --------------------------------------------------------------------- */

	/**
	 * The mode as a reader should see it: Paper, Practice.
	 *
	 * PAPER and T212_DEMO are column values. "Practice" is what Trading 212
	 * calls the account, which is the word somebody looking at the page has
	 * already seen on the broker's own site.
	 */
	function strategy_mode_name($mode) {

		$mode=strtoupper(trim((string)$mode));

		if ($mode===STRATEGY_MODE_T212_LIVE){ return 'Live'; }
		if ($mode===STRATEGY_MODE_T212_DEMO){ return 'Practice'; }
		if ($mode===STRATEGY_MODE_PAPER){ return 'Paper'; }
		/* A backtest account only, and those are never listed as
		   strategies - but a name is cheaper than an "Unknown" in front of
		   somebody reading a log. */
		if ($mode===STRATEGY_MODE_PAPER_T212){ return 'Replay'; }

		return 'Unknown';
	}

	/** The mode's dealing rules, as sentences, for the Rules tab. */
	function strategy_mode_dealing($mode) {

		$rules=strategy_mode_rules($mode);

		$out=[];
		$out[]='Minimum order '.fmt_pounds($rules['minimum_order'],0).'.';
		$out[]=empty($rules['fractional'])
		     ? 'Whole shares only.'
		     : 'Fractional shares, to '.(int)$rules['decimals'].' decimal places.';
		$out[]=((float)$rules['commission']>0
		        ? 'Commission '.fmt_pounds($rules['commission'],2).' a trade'
		        : 'No commission')
		     .', stamp duty '.fmt_pct(STRATEGY_STAMP_DUTY_RATE*100,1).' on a purchase'
		     .', spread allowance '.fmt_pct(STRATEGY_SLIPPAGE_BPS/100,2).'.';

		if ($rules['broker']!==null){
			$out[]='Orders go to the Trading 212 practice account during the London'
			      .' session, at most '.fmt_pounds(T212_MAX_RUN_VALUE,0).' a run.';
		}
		else {
			$out[]='Orders fill at the next close, in this database only.';
		}

		return $out;
	}

	/**
	 * The latest close on or before a date, in pounds, for many symbols at
	 * once.
	 *
	 * One query for the whole page instead of one per holding. The page
	 * shows three strategies, each with fifteen holdings, and every one of
	 * them was its own round trip to find the same forty-five prices.
	 *
	 * The conversion is strategy_price_pounds()'s, the same one
	 * strategy_price_asof() uses, so there is one answer to what a stored
	 * price means rather than two.
	 */
	function strategy_prices_for($symbols,$exchange,$as_of) {

		$out=[];

		$symbols=array_values(array_unique(array_filter(array_map('strval',(array)$symbols),
		                                                function($s){ return $s!==''; })));
		if (!count($symbols)){ return $out; }

		$as_of=substr((string)$as_of,0,10);

		/* The placeholders are built from the count, and every value is
		   still bound: the only thing interpolated is a run of question
		   marks. */
		$marks=implode(',',array_fill(0,count($symbols),'?'));

		$args=array_merge([$exchange,$as_of],$symbols,[$exchange,$as_of],$symbols);

		$sql="select h.symbol, h.date, h.price
		        from historical_prices h
		        join (select symbol, max(date) d from historical_prices
		               where exchange=? and date<=? and price is not null
		                 and symbol in ($marks)
		               group by symbol) m
		          on m.symbol=h.symbol and m.d=h.date
		       where h.exchange=? and h.date<=? and h.symbol in ($marks)";

		foreach (query($sql,...$args) as $row) {
			$symbol=(string)$row['symbol'];
			if (isset($out[$symbol])){ continue; }
			$out[$symbol]=strategy_price_pounds($row['price'],$symbol,$exchange,
			                                    substr((string)$row['date'],0,10));
		}

		return $out;
	}

	/** The target weights written at the last rebalance, by symbol. */
	function strategy_target_weights($strategy) {

		$out=[];

		$last=query("select max(as_of_date) d from strategy_targets where strategy=?",$strategy);
		if (!count($last)||$last[0]['d']===null){ return $out; }

		foreach (query("select symbol, target_weight from strategy_targets
		                 where strategy=? and as_of_date=?",$strategy,$last[0]['d']) as $row) {
			$out[(string)$row['symbol']]=(float)$row['target_weight'];
		}

		return $out;
	}

	/**
	 * One row per strategy: everything the menu and the summary table show.
	 *
	 * Built from strategy_card(), which is the one place that decides what
	 * a strategy is worth, plus the count of orders that have not finished
	 * and - for an account with a broker - whether the two books agree.
	 */
	function strategy_summary($strategies,$as_of,$exchange=null) {

		$exchange=($exchange===null) ? session_exchange() : $exchange;

		$rows=[];

		/* Every held symbol on the page, priced in one query rather than
		   one per holding. */
		$symbols=[];
		foreach (query("select distinct symbol from strategy_positions where quantity>0") as $row) {
			$symbols[]=(string)$row['symbol'];
		}
		$prices=strategy_prices_for($symbols,$exchange,$as_of);

		/* The orders that have not finished, counted for every strategy in
		   one query rather than one each. */
		$waiting=[];
		foreach (query("select strategy, count(*) n from strategy_orders
		                 where status in ('PENDING','SENT','UNKNOWN') group by strategy") as $row) {
			$waiting[(string)$row['strategy']]=(int)$row['n'];
		}

		$halted=strategy_halted();

		foreach ((array)$strategies as $strategy) {

			$card=strategy_card($strategy,$exchange,$as_of,$prices);

			$card['waiting']=isset($waiting[$strategy]) ? $waiting[$strategy] : 0;
			$card['kill_switch']=$halted;
			/* The broker layer is not loaded by the nightly job, which has
			   no use for it. A page that wants the agreement line requires
			   broker_orders.php; everything else gets null and says
			   nothing, rather than a fatal. */
			$card['agreement']=($card['broker']===null
			                    ||!function_exists('broker_agreement_for'))
			                 ? null : broker_agreement_for($strategy);

			$card['status']=strategy_status($card);

			$rows[]=$card;
		}

		return $rows;
	}

	/**
	 * The order the page lists strategies in, everywhere it lists them.
	 *
	 * The menu and the summary table were sorted by two different things,
	 * so the third entry in one was the first row in the other and a
	 * reader had to find the same strategy twice. One order: paper first,
	 * then practice, then live, each by display name - and a halted
	 * strategy at the top of its group, because it is the one somebody has
	 * to look at.
	 */
	function strategy_order(array $rows) {

		$rank=function($row){
			$mode=strtoupper(trim((string)$row['mode']));
			if ($mode===STRATEGY_MODE_T212_LIVE){ return 2; }
			if ($mode===STRATEGY_MODE_T212_DEMO){ return 1; }
			return 0;
		};

		usort($rows,function($a,$b) use ($rank) {

			$ar=$rank($a); $br=$rank($b);
			if ($ar!==$br){ return $ar-$br; }

			$ah=($a['halted']==='Y') ? 0 : 1;
			$bh=($b['halted']==='Y') ? 0 : 1;
			if ($ah!==$bh){ return $ah-$bh; }

			return strcasecmp(strategy_display_name($a['strategy']),
			                  strategy_display_name($b['strategy']));
		});

		return $rows;
	}

	/** The group a strategy belongs to in the menu: its mode, in words. */
	function strategy_group_name($mode) { return strategy_mode_name($mode); }

	/**
	 * The worst thing true of a strategy right now, as a badge.
	 *
	 * Worst first: a halted strategy with orders waiting is a halted
	 * strategy, and showing it as "orders waiting" would be the page
	 * choosing the comfortable half of the truth.
	 */
	function strategy_status($card) {

		if (!empty($card['halted'])&&$card['halted']==='Y'){
			return ['key'=>'halted','words'=>'Halted','tone'=>'halted',
			        'reason'=>(string)$card['halt_reason']];
		}

		if (!empty($card['kill_switch'])){
			return ['key'=>'kill','words'=>'Kill switch on','tone'=>'halted',
			        'reason'=>'The kill switch file exists, so no strategy places anything.'];
		}

		if (!empty($card['waiting'])){
			$n=(int)$card['waiting'];
			return ['key'=>'orders','words'=>'Orders waiting','tone'=>'due',
			        'reason'=>$n.' order'.($n===1?'':'s').' planned and not yet filled.'];
		}

		if (!empty($card['agreement'])&&$card['agreement']['state']!=='agreed'){
			return ['key'=>'unagreed','words'=>'Not agreed with broker','tone'=>'due',
			        'reason'=>$card['agreement']['words']];
		}

		return ['key'=>'ok','words'=>'OK','tone'=>'ok','reason'=>'Nothing outstanding.'];
	}

	/**
	 * Accounts running identical rules, paired.
	 *
	 * The pair that matters is trend_following against trend_following_t212
	 * - the same rules, one on paper and one at the broker - but the names
	 * are not what makes them a pair: the rules are. A hold_rank changed on
	 * one of them makes them two different strategies, and the panel that
	 * compares them has to stop claiming otherwise.
	 */
	function strategy_pairs($strategies) {

		$key=function($rules){
			if ($rules===null){ return null; }
			return implode('|',[
				(string)$rules['selection'],
				(string)$rules['screen_id'],
				(string)$rules['score_indicator'],
				(string)$rules['rank_by'],
				(string)$rules['holdings'],
				(string)$rules['hold_rank'],
				(string)$rules['rebalance'],
				(string)$rules['exit_rules'],
				(string)$rules['exclude_funds'],
			]);
		};

		$groups=[];
		foreach ((array)$strategies as $strategy) {
			$signature=$key(strategy_rules($strategy));
			if ($signature===null){ continue; }
			$groups[$signature][]=$strategy;
		}

		$pairs=[];
		foreach ($groups as $group) {

			if (count($group)<2){ continue; }

			/* Paper first, so the panel always reads the same way round. */
			usort($group,function($a,$b){
				$am=strategy_account($a); $bm=strategy_account($b);
				$ab=($am===null)?null:strategy_mode_rules($am['mode'])['broker'];
				$bb=($bm===null)?null:strategy_mode_rules($bm['mode'])['broker'];
				if ($ab===$bb){ return strcmp($a,$b); }
				return ($ab===null) ? -1 : 1;
			});

			for ($i=1;$i<count($group);$i++) { $pairs[]=[$group[0],$group[$i]]; }
		}

		return $pairs;
	}

	/**
	 * Two accounts on the same rules, side by side.
	 *
	 * The fill comparison is the number this is for. The paper account
	 * fills at the next close with an allowance for the spread; the
	 * practice account fills at the market during the session. If the two
	 * are within a few basis points of each other then the paper record's
	 * cost model is fair, and if they are not then the paper record is
	 * flattering itself and nothing built on it means very much.
	 */
	function strategy_pair_compare($one,$two,$as_of,$exchange=null) {

		$exchange=($exchange===null) ? session_exchange() : $exchange;

		$out=['strategies'=>[$one,$two],'from'=>null,'returns'=>[],'gap'=>null,
		      'only_in'=>[$one=>[],$two=>[]],'fills'=>[],'average_bp'=>null,
		      'costs'=>[],'notes'=>[]];

		/* Measured from the later of the two starts: a strategy that began
		   in March must not be compared against one that began in January
		   over a period only one of them was invested in. */
		$starts=[];
		foreach ([$one,$two] as $strategy) {
			$rows=query("select min(date) d from strategy_values where strategy=?",$strategy);
			if (count($rows)&&$rows[0]['d']!==null){ $starts[$strategy]=(string)$rows[0]['d']; }
		}
		if (count($starts)===2){ $out['from']=max($starts); }

		foreach ([$one,$two] as $strategy) {

			if ($out['from']!==null){
				$first=query("select total from strategy_values where strategy=? and date>=?
				               order by date asc limit 1",$strategy,$out['from']);
				$card=strategy_card($strategy,$exchange,$as_of);
				if (count($first)&&(float)$first[0]['total']>0&&$card['total']!==null){
					$out['returns'][$strategy]=((float)$card['total']/(float)$first[0]['total'])-1;
				}
			}

			$traded=query("select ifnull(sum(abs(consideration)),0) v,
			                      ifnull(sum(commission+stamp_duty+slippage),0) c
			                 from strategy_orders where strategy=? and status='FILLED'",$strategy);

			$value=count($traded) ? (float)$traded[0]['v'] : 0.0;
			$cost =count($traded) ? (float)$traded[0]['c'] : 0.0;

			$out['costs'][$strategy]=['traded'=>$value,'paid'=>$cost,
			                          'percent'=>$value>0 ? $cost/$value : null];
		}

		if (count($out['returns'])===2){
			$out['gap']=$out['returns'][$one]-$out['returns'][$two];
		}

		/* What each holds, and what only one of them does. */
		$held=[];
		foreach ([$one,$two] as $strategy) {
			$held[$strategy]=[];
			foreach (query("select symbol from strategy_positions
			                 where strategy=? and quantity>0",$strategy) as $row) {
				$held[$strategy][]=(string)$row['symbol'];
			}
		}
		$out['only_in'][$one]=array_values(array_diff($held[$one],$held[$two]));
		$out['only_in'][$two]=array_values(array_diff($held[$two],$held[$one]));

		/* The fills both of them made on the same rebalance, which is the
		   only fair comparison: two buys of the same share a month apart
		   say nothing about either cost model. */
		$ours=[];
		foreach (query("select strategy, as_of_date, symbol, side, fill_price
		                  from strategy_orders
		                 where strategy in (?,?) and status='FILLED' and fill_price is not null",
		                $one,$two) as $row) {
			$ours[(string)$row['strategy']][(string)$row['as_of_date'].'|'
			      .(string)$row['symbol'].'|'.(string)$row['side']]=(float)$row['fill_price'];
		}

		$total=0.0; $counted=0;

		foreach (($ours[$one] ?? []) as $key => $price_one) {

			if (!isset($ours[$two][$key])||$price_one<=0){ continue; }

			$price_two=$ours[$two][$key];
			[$date,$symbol,$side]=explode('|',$key);

			$bp=(($price_two/$price_one)-1)*10000;

			$out['fills'][]=['date'=>$date,'symbol'=>$symbol,'side'=>$side,
			                 'one'=>$price_one,'two'=>$price_two,'bp'=>$bp];
			$total+=$bp; $counted++;
		}

		if ($counted>0){ $out['average_bp']=$total/$counted; }

		/* The caveat, every time, because the two are not the same size and
		   do not deal in the same thing. */
		$cash=[];
		foreach ([$one,$two] as $strategy) {
			$account=strategy_account($strategy);
			$cash[$strategy]=($account===null) ? null : strategy_mode_name($account['mode']);
		}
		$out['notes'][]='The two accounts are different sizes and one deals in whole shares'
		               .' while the other deals in fractions, so small differences in the'
		               .' weights are expected.';
		$out['modes']=$cash;

		return $out;
	}

	/**
	 * The backtest runs, labelled by what differs from the live rules.
	 *
	 * Three rows reading "rebalance=monthly, hold-rank=30" with three
	 * different returns is not a comparison. What differed between them was
	 * the cost settings, which the run records - so the label says so, and
	 * the run whose rules and costs match the live strategy is marked and
	 * listed first.
	 */
	function strategy_backtest_rows($strategy=null,$limit=50) {

		if (!strategy_backtest_ready()){ return []; }

		$limit=max(1,min(200,(int)$limit));

		$runs=($strategy===null)
		    ? query("select * from strategy_backtest_runs
		              order by started_at desc, run_id desc limit ".$limit)
		    : query("select * from strategy_backtest_runs where strategy=?
		              order by started_at desc, run_id desc limit ".$limit,$strategy);

		$out=[];
		$seen=[];

		/* What each run's own fills paid, in one query: the only evidence
		   of the cost model for a run that predates recording it. */
		$accounts=[];
		foreach ($runs as $run) { $accounts[]=(string)$run['account']; }
		$commission=strategy_backtest_commission($accounts);

		foreach ($runs as $run) {

			$rules=strategy_rules((string)$run['strategy']);
			$account=strategy_account((string)$run['strategy']);

			$paid=array_key_exists((string)$run['account'],$commission)
			    ? $commission[(string)$run['account']] : null;

			$label=strategy_backtest_label($run,$rules,$account,$paid);

			//The caveat belongs at the top of the table, once, not here.
			$run['note']=strategy_backtest_note($run);

			$run['label']=$label['words'];
			$run['matches_live']=$label['matches_live'];
			$run['settings_key']=(string)$run['strategy'].'|'.$label['key'];

			/* A later run with the same settings supersedes an earlier one:
			   the newer answer to the same question is the answer. */
			$run['superseded']=isset($seen[$run['settings_key']]);
			$seen[$run['settings_key']]=true;

			$run['a_year']=strategy_backtest_annual($run);

			$out[]=$run;
		}

		/* The one that matches the live settings first, then the rest
		   newest first, then the superseded ones. The sort is stable in
		   PHP 8, so runs that tie keep the order they came out of the
		   database in. */
		usort($out,function($a,$b){
			if ($a['matches_live']!==$b['matches_live']){ return $a['matches_live'] ? -1 : 1; }
			if ($a['superseded']!==$b['superseded']){ return $a['superseded'] ? 1 : -1; }
			return 0;
		});

		return $out;
	}

	/** A run's return restated as a yearly rate, or null if it cannot be. */
	function strategy_backtest_annual($run) {

		if ($run['total_return']===null){ return null; }

		$from=strtotime((string)$run['from_date']);
		$to=strtotime((string)$run['to_date']);
		if ($from===false||$to===false||$to<=$from){ return null; }

		$years=($to-$from)/(365.25*86400);
		if ($years<=0.05){ return null; }

		$growth=1.0+(float)$run['total_return'];
		if ($growth<=0){ return null; }

		return pow($growth,1/$years)-1;
	}

	/**
	 * What a backtest run was run with, in words, and whether that is what
	 * the live strategy does now.
	 */
	function strategy_backtest_label($run,$rules,$account=null,$commission_paid=null) {

		$parts=[];
		$key=[];

		/* The rules it was told to use: the overrides where there are any,
		   the stored rules where there are not. */
		$overrides=[];
		if (array_key_exists('overrides',$run)&&trim((string)$run['overrides'])!==''){
			foreach (explode(',',(string)$run['overrides']) as $piece) {
				$piece=trim($piece);
				if ($piece===''){ continue; }
				$bits=explode('=',$piece,2);
				$overrides[trim($bits[0])]=isset($bits[1]) ? trim($bits[1]) : '';
			}
		}

		$rebalance=isset($overrides['rebalance']) ? $overrides['rebalance']
		         : ($rules===null ? null : (string)$rules['rebalance']);
		$holdings =isset($overrides['holdings']) ? $overrides['holdings']
		         : ($rules===null ? null : (string)$rules['holdings']);
		$hold_rank=isset($overrides['hold-rank']) ? $overrides['hold-rank']
		         : ($rules===null ? null : (string)$rules['hold_rank']);

		if ($rebalance!==null&&$rebalance!==''){ $parts[]=ucfirst((string)$rebalance); }
		if ($holdings!==null&&$holdings!==''){ $parts[]=$holdings.' holdings'; }

		if ($hold_rank===null||$hold_rank===''||strtolower((string)$hold_rank)==='none'){
			$parts[]='no hold rank';
			$hold_rank='none';
		}
		else { $parts[]='hold to rank '.$hold_rank; }

		$key[]=$rebalance; $key[]=$holdings; $key[]=$hold_rank;

		/* And what it cost. Two runs of the same rules with different cost
		   settings are two different experiments, and the only thing that
		   said so was the return. */
		$costs=strategy_backtest_costs_words($run,$commission_paid);
		$parts[]=$costs['words'];
		$key[]=$costs['key'];

		$live=($account===null) ? null : strategy_backtest_costs_now($account['mode']);

		$matches=$rules!==null
		      && (string)$rebalance===(string)$rules['rebalance']
		      && (string)$holdings===(string)$rules['holdings']
		      && (string)$hold_rank===(string)($rules['hold_rank']===null
		                                       ||(int)$rules['hold_rank']===0
		                                       ? 'none' : $rules['hold_rank'])
		      && $live!==null && $costs['key']===$live['key'];

		return ['words'=>implode(', ',$parts),
		        'key'=>implode('|',$key),
		        'matches_live'=>$matches];
	}

	/**
	 * Which of the two cost models a run dealt under, in words.
	 *
	 * There have only ever been two, and the model changed once, on
	 * 16 Nov 2026: before it every deal paid GBP 5.95 commission and a
	 * 25 bp spread; after it a Trading 212 account pays no commission.
	 * "costs not recorded (GBP 29.51 a fill)" was an average, and an
	 * average of commission, stamp duty and spread over fifteen names
	 * does not tell anybody which model the run assumed - two runs with
	 * the same model and different turnover read as two models, and two
	 * runs with different models and similar turnover read as one.
	 *
	 * The recorded settings answer it where a run has them, and the
	 * commission they name is a commission a deal, so it is the figure in
	 * the label. Where a run has no recorded settings, $commission_paid is
	 * what its fills paid in total and only its sign is read: anything
	 * above nothing is the old model, which only ever charged
	 * STRATEGY_COMMISSION a deal; nothing over at least one fill is the
	 * Trading 212 model; and null - no fills left to read - is not guessed
	 * at, because a run that never dealt says nothing about either.
	 */
	function strategy_backtest_costs_words($run,$commission_paid=null) {

		$recorded=array_key_exists('cost_settings',$run)
		        ? trim((string)$run['cost_settings']) : '';

		$commission=null;

		if ($recorded!==''){
			$commission=strategy_backtest_costs_parse($recorded)['commission'];
		}
		else if ($commission_paid!==null){
			$commission=((float)$commission_paid>0) ? (float)STRATEGY_COMMISSION : 0.0;
		}

		if ($commission===null){
			return ['key'=>'unknown','words'=>'cost model not recorded'];
		}

		return ($commission>0)
		     ? ['key'=>'commission',
		        'words'=>'old costs ('.fmt_pounds($commission,2).' commission)']
		     : ['key'=>'no-commission','words'=>'Trading 212 costs'];
	}

	/**
	 * The cost model in force now.
	 *
	 * 'text' is what a run records against itself, field by field, so a
	 * later change to the spread or the minimum can still be read off an
	 * old run. 'key' is the model, which is what a run is labelled and
	 * compared by: the two are not the same thing, and a run recorded
	 * before the spread moved is still a run under the old model.
	 */
	function strategy_backtest_costs_now($mode) {

		$rules=strategy_mode_rules($mode);

		$text='commission='.number_format((float)$rules['commission'],2,'.','')
		     .', stamp='.number_format(STRATEGY_STAMP_DUTY_RATE,5,'.','')
		     .', spread_bps='.number_format(STRATEGY_SLIPPAGE_BPS,2,'.','')
		     .', minimum='.number_format((float)$rules['minimum_order'],2,'.','');

		$commission=(float)$rules['commission'];

		return ['text'=>$text,
		        'key'=>($commission>0) ? 'commission' : 'no-commission',
		        'commission'=>$commission];
	}

	/**
	 * What a run's own fills paid in commission, by backtest account.
	 *
	 * One query for the whole table rather than one a row. The value is
	 * null where no fill is left to read, which is not the same as zero:
	 * a run that never dealt says nothing about either model.
	 */
	function strategy_backtest_commission(array $accounts) {

		$out=[];

		$wanted=[];
		foreach ($accounts as $account) {
			$account=trim((string)$account);
			if ($account!==''){ $wanted[$account]=true; }
		}
		if (!count($wanted)){ return $out; }

		$names=array_keys($wanted);
		$marks=implode(',',array_fill(0,count($names),'?'));

		foreach (query("select strategy, count(*) fills,
		                       ifnull(sum(commission),0) commission
		                  from strategy_orders
		                 where status='FILLED' and strategy in (".$marks.")
		                 group by strategy",...$names) as $row) {
			$out[(string)$row['strategy']]=((int)$row['fills']>0)
			                             ? (float)$row['commission'] : null;
		}

		return $out;
	}

	/**
	 * What is left of a run's note once the general caveat is taken out.
	 *
	 * The survivorship and look-ahead caveat was written into every run's
	 * note as well as printed once at the top of the table, so a reader
	 * met it six times and read it none. It belongs to the method, not to
	 * a run. New runs no longer record it; the rows that already have it
	 * are stripped here rather than edited in the database, and what is
	 * left - a turnover refusal, a problem - is the only thing a note was
	 * ever worth printing for.
	 */
	function strategy_backtest_note($run) {

		$note=trim((string)(array_key_exists('note',$run) ? $run['note'] : ''));
		if ($note===''){ return null; }

		/* By sentence, because the wording of the caveat has changed
		   once already and the rows that carry the older one are the
		   rows that need it taken out. Neither sentence has a full stop
		   inside it, so one is all it takes to find the end. */
		$note=preg_replace('/\b(?:Survivorship|Look-ahead)\b[^.]*\.\s*/i','',$note);
		$note=preg_replace('/\bPrices are adjusted closes\.\s*/i','',$note);
		$note=trim((string)$note);

		return ($note==='') ? null : $note;
	}

	/** The pieces of a recorded cost setting string. */
	function strategy_backtest_costs_parse($text) {

		$out=['commission'=>0.0,'stamp'=>0.0,'spread_bps'=>0.0,'minimum'=>0.0];

		foreach (explode(',',(string)$text) as $piece) {
			$bits=explode('=',trim($piece),2);
			if (count($bits)!==2){ continue; }
			$name=trim($bits[0]);
			if (array_key_exists($name,$out)){ $out[$name]=(float)trim($bits[1]); }
		}

		return $out;
	}

	/**
	 * A name the nightly run must not treat as a strategy: the benchmark's
	 * row in strategy_values, and any backtest account.
	 */
	function strategy_reserved($strategy) {
		$strategy=(string)$strategy;
		return substr($strategy,0,1)==='_'
		    || strpos($strategy,STRATEGY_BACKTEST_PREFIX)===0;
	}

	/**
	 * The strategies tonight's run should drive, in a stable order.
	 *
	 * Enabled, PAPER, and with a rules row: an account with no rules has
	 * nothing to select, and guessing on its behalf is how an account that
	 * was only ever meant to exercise the engine starts trading.
	 */
	function strategy_strategies() {

		if (!strategy_rules_ready()){ return []; }

		/* The modes this application knows how to run, named rather than
		   assumed: an account whose mode is anything else - a half finished
		   migration, a typo, a mode a later version adds - is not run at
		   all rather than run as if it were a paper one. Reserved names
		   (the backtest accounts) are skipped whatever their mode. */
		$out=[];
		foreach (query("select a.strategy, a.mode from strategy_accounts a, strategy_rules r
		                 where r.strategy=a.strategy and a.enabled='Y'
		                 order by a.strategy") as $row) {
			if (strategy_reserved($row['strategy'])){ continue; }
			if (!strategy_mode_known($row['mode'])){ continue; }
			$out[]=$row['strategy'];
		}
		return $out;
	}

	/**
	 * One strategy's rules, or null. Defaults are applied here rather than
	 * being assumed by each caller.
	 */
	function strategy_rules($strategy) {

		if (!strategy_rules_ready()){ return null; }

		$rows=query("select * from strategy_rules where strategy=?",$strategy);
		if (!count($rows)){ return null; }

		$rules=$rows[0];
		$rules['selection']=strtolower(trim((string)$rules['selection']))==='screen' ? 'screen' : 'score';
		$rules['holdings']=max(1,(int)$rules['holdings']);
		$rebalance=strtolower(trim((string)$rules['rebalance']));
		$rules['rebalance']=in_array($rebalance,['monthly','weekly','daily'],true) ? $rebalance : 'monthly';
		$rules['exclude_funds']=strtoupper(trim((string)$rules['exclude_funds']))==='N' ? 'N' : 'Y';
		$rules['exit_list']=array_values(array_filter(array_map('trim',
		                      explode(',',strtolower((string)$rules['exit_rules'])))));
		$rules['rank_by']=trim((string)$rules['rank_by'])===''  ? null : trim((string)$rules['rank_by']);

		/* The rank buffer: hold a name until it drops out of the top N
		   rather than out of the book. Null, and absent, both mean the old
		   behaviour - only the top `holdings` are kept - so a host that has
		   not applied sql/strategy_hold_rank.sql behaves as it did.

		   Below `holdings` it would be stricter than no buffer at all,
		   which is not what a buffer is, so it is floored there. */
		$rules['hold_rank']=(isset($rules['hold_rank'])&&$rules['hold_rank']!==null
		                     &&(int)$rules['hold_rank']>0)
		                  ? max((int)$rules['hold_rank'],$rules['holdings']) : null;
		$rules['score_indicator']=trim((string)$rules['score_indicator'])===''
		                        ? null : trim((string)$rules['score_indicator']);

		return $rules;
	}

	/**
	 * The first trading date of the period $as_of falls in.
	 *
	 * Trading dates, not calendar dates: "the first as of date of the month"
	 * on a month that opens on a Sunday is the Monday, and a week that opens
	 * on a bank holiday rebalances on the Tuesday. The dates come from
	 * historical_prices, which is the only thing here that knows when the
	 * market was actually open.
	 */
	function strategy_period_first_date($rebalance,$as_of,$exchange=null) {

		$exchange=($exchange===null) ? session_exchange() : $exchange;
		$as_of=substr((string)$as_of,0,10);

		if ($rebalance==='daily'){ return $as_of; }

		if ($rebalance==='weekly'){
			//ISO weeks: Monday starts the week, so a Sunday as of date
			//belongs to the week that began six days earlier.
			$start=date('Y-m-d',strtotime('monday this week',strtotime($as_of)));
		}
		else {
			$start=date('Y-m-01',strtotime($as_of));
		}

		$rows=query("select min(date) d from historical_prices
		              where exchange=? and date>=? and date<=?",$exchange,$start,$as_of);

		return (count($rows)&&$rows[0]['d']!==null) ? substr((string)$rows[0]['d'],0,10) : null;
	}

	/**
	 * Has this account never traded?
	 *
	 * No positions and no filled orders. Used for the turnover exemption and
	 * to decide that the first rebalance is due now rather than at the next
	 * month boundary.
	 */
	function strategy_first_build($strategy) {

		$positions=query("select count(*) n from strategy_positions
		                   where strategy=? and quantity<>0",$strategy);
		if (count($positions)&&(int)$positions[0]['n']>0){ return false; }

		/* An order that has not filled yet counts. A monthly strategy built
		   on the 16th fills at the 17th close, so on the night of the 17th
		   it held nothing and had nothing FILLED - and this said "not built
		   yet", which made the 17th a rebalance night. Both paper
		   strategies rebalanced the day after they were built, sold three
		   names apiece and planned eight orders nobody asked for.

		   REJECTED and CANCELLED do not count: an order that was refused is
		   an order that did not happen, and a strategy whose whole first
		   build was refused has still not been built. */
		$live=query("select count(*) n from strategy_orders
		              where strategy=? and status in ('FILLED','PENDING','SENT','UNKNOWN')",
		            $strategy);

		return !(count($live)&&(int)$live[0]['n']>0);
	}

	/**
	 * Is tonight a rebalance for this strategy?
	 *
	 * The first build is always due: a strategy deployed on the 12th should
	 * not sit in cash until the 1st, and "start on the first as of date
	 * after deploy" is what the brief asked for. After that it is the
	 * calendar - the first trading date of the month, or of the week.
	 */
	function strategy_rebalance_due($rules,$as_of,$exchange=null) {

		if ($rules===null){ return false; }

		$as_of=substr((string)$as_of,0,10);

		$start=trim((string)$rules['start_date']);
		if ($start!==''&&substr($start,0,10)>$as_of){ return false; }

		if (strategy_first_build($rules['strategy'])){ return true; }

		$first=strategy_period_first_date($rules['rebalance'],$as_of,$exchange);

		return $first!==null&&$first===$as_of;
	}

	/**
	 * An indicator's rank_order out of screen_indicators, defaulting to
	 * "value DESC". Checked by screen_rank_order() before it reaches an
	 * order by, the same way build_screen() checks it.
	 */
	function strategy_rank_order($indicator) {

		$rows=query("select rank_order from screen_indicators where name=?",$indicator);

		return (count($rows)&&trim((string)$rows[0]['rank_order'])!=='')
		     ? (string)$rows[0]['rank_order'] : 'value DESC';
	}

	/**
	 * The symbols a strategy wants to hold on a date, in order, already cut
	 * to the number of holdings.
	 *
	 *   selection 'screen'  the screen's own output, which for a screen
	 *                       ending in a top_n is already ranked
	 *   selection 'score'   every name with a value for one indicator
	 *
	 * Then, in this order: funds out if the rules say so, anything that
	 * cannot be priced on the day out, and rank_by applied if there is one.
	 *
	 * rank_by goes through top_n_list(), which is the same function
	 * build_screen() uses for a top_n criterion - so "the best 15 by
	 * momentum_score" means exactly what it means on a screen, including
	 * that a share with no momentum_score drops out rather than sorting to
	 * one end. The best 15 cannot include a share whose momentum is unknown.
	 */
	function strategy_select_symbols($rules,$as_of,$exchange=null) {

		$out=['symbols'=>[],'errors'=>[],'skipped'=>[]];

		if ($rules===null){ $out['errors'][]="no rules row"; return $out; }

		$exchange=($exchange===null) ? session_exchange() : $exchange;
		$as_of=substr((string)$as_of,0,10);

		//build_screen() and top_n_list() live in share_screen.php. Loaded
		//here rather than at the top of this file so that the engine's
		//existing callers keep the include list they always had.
		if (!function_exists('build_screen')){ require_once("share_screen.php"); }

		$candidates=[];

		if ($rules['selection']==='screen'){

			$screen_id=(int)$rules['screen_id'];
			if ($screen_id<1){ $out['errors'][]="selection is 'screen' but no screen_id"; return $out; }

			$screen=query("select name from screen where id=?",$screen_id);
			if (!count($screen)){ $out['errors'][]="screen ".$screen_id." does not exist"; return $out; }

			foreach (build_screen($screen_id,$as_of) as $row) {
				if (isset($row['symbol'])){ $candidates[]=['symbol'=>$row['symbol']]; }
			}
		}
		else {
			$indicator=$rules['score_indicator'];
			if ($indicator===null){ $out['errors'][]="selection is 'score' but no score_indicator"; return $out; }

			$sql="select s.symbol from statistics s, stock_symbols ss
			       where s.symbol=ss.symbol and ss.enabled='Y' and ss.exchange=?
			         and s.indicator=? and s.date=? and s.value is not null
			       order by ".screen_rank_order(strategy_rank_order($indicator),$indicator).", s.symbol asc";
			foreach (query($sql,$exchange,$indicator,$as_of) as $row) {
				$candidates[]=['symbol'=>$row['symbol']];
			}
		}

		if (!count($candidates)){
			$out['errors'][]="the selection returned nothing for ".$as_of;
			return $out;
		}

		/* Funds. An investment trust passes a value screen for being a
		   wrapper rather than a business (see sql/fund_sectors.sql), and
		   the same argument applies to buying one: the strategy is about
		   operating companies. Momentum is a different matter, which is why
		   this is a rule per strategy rather than a rule here. */
		/* Which broker this account deals through, if any. Asked once
		   rather than per candidate. */
		$account=strategy_account($rules['strategy']);
		$broker=($account===null||!strategy_mode_known($account['mode'])) ? null
		      : strategy_mode_rules($account['mode'])['broker'];

		/* Only worth asking per share if the broker has been asked what it
		   lists at all. */
		if ($broker!==null&&!strategy_broker_list_read($broker)){ $broker=null; }

		$kept=[];
		foreach ($candidates as $candidate) {

			$symbol=$candidate['symbol'];

			if ($rules['exclude_funds']==='Y'&&is_fund($symbol)){ continue; }

			$enabled=query("select count(*) n from stock_symbols
			                 where symbol=? and exchange=? and enabled='Y'",$symbol,$exchange);
			if (!count($enabled)||(int)$enabled[0]['n']===0){ continue; }

			/* Unpriceable on the day is not tradeable on the day. The
			   executor would refuse it anyway; dropping it here means the
			   strategy holds the next name down instead of one fewer. */
			if (strategy_price_asof($symbol,$exchange,$as_of)===null){ continue; }

			/* And on an account that deals through a broker, a share the
			   broker does not list at all. OCI (Oakley Capital
			   Investments) is one: it is in the FTSE 250, so the universe
			   has it and the screen can pick it, and Trading 212 has no
			   instrument to send an order for.

			   Dropped here rather than refused by the sender, for the same
			   reason an unpriceable share is: the next name down takes the
			   slot instead of the strategy holding fourteen names and the
			   fifteenth slot's cash sitting idle for a month. A paper
			   account has no broker and is not affected. */
			if (!strategy_broker_lists($symbol,$broker)){
				$out['skipped'][]=['symbol'=>$symbol,
				                   'reason'=>'the broker lists no instrument for it, so an'
				                            .' order could not be sent'];
				continue;
			}

			$kept[]=['symbol'=>$symbol];
		}

		if (!count($kept)){
			$out['errors'][]="every name the selection returned was excluded or unpriceable";
			return $out;
		}

		/* The whole selection in rank order, not just the top slice: the
		   buffer below needs to know where a holding sits, and "not in the
		   top fifteen" and "not in the selection at all" are different
		   answers. Without a buffer the slice is taken straight away, as
		   before. */
		$want=$rules['holdings'];
		$depth=($rules['hold_rank']===null) ? $want : max($want,$rules['hold_rank']);

		if ($rules['rank_by']!==null){
			$ranked=top_n_list($kept,$rules['rank_by'],
			                   strategy_rank_order($rules['rank_by']),
			                   $depth,$as_of);
		}
		else {
			$ranked=array_slice($kept,0,$depth);
		}

		if ($rules['hold_rank']===null){
			foreach (array_slice($ranked,0,$want) as $row) { $out['symbols'][]=$row['symbol']; }
			return $out;
		}

		/* With a buffer: keep what is held and still ranked inside it, then
		   fill the slots that are free from the top of the ranking.

		   A holding that is not in $ranked at all has either left the
		   screen or fallen past the buffer, and is sold either way - the
		   brief's "leaves the screen" and "falls below that rank" land in
		   the same place, which is what makes this one rule rather than
		   two. */
		$held=[];
		foreach (strategy_positions($rules['strategy']) as $symbol => $position) {
			if ((float)$position['quantity']>0){ $held[$symbol]=true; }
		}

		$keeping=[]; $spare=[];
		foreach ($ranked as $row) {
			if (isset($held[$row['symbol']])){ $keeping[]=$row['symbol']; }
			else { $spare[]=$row['symbol']; }
		}

		/* More holdings still inside the buffer than there are slots: the
		   best ranked of them stay. $ranked is already in rank order, so
		   this is the top of the list. */
		$out['symbols']=array_slice($keeping,0,$want);

		foreach ($spare as $symbol) {
			if (count($out['symbols'])>=$want){ break; }
			$out['symbols'][]=$symbol;
		}

		return $out;
	}

	/**
	 * Write a strategy's targets for a date, or work out what they would be.
	 *
	 * Equal weight at one over the number of HOLDINGS, not one over the
	 * number of names found. A selection that gives twelve names for a
	 * fifteen holding strategy holds twelve positions and 20% cash; it does
	 * not put a fifth more into each name. Padding the weights would turn a
	 * thin signal into a concentrated bet, which is the opposite of what a
	 * thin signal means.
	 *
	 * $dry_run computes and returns the targets without writing them, which
	 * is what the strategy page's dry run and the executor's plan override
	 * are built on.
	 */
	function strategy_write_targets($strategy,$as_of,$dry_run=false,$exchange=null) {

		$result=['targets'=>[],'weight'=>0.0,'errors'=>[],'skipped'=>[]];

		$exchange=($exchange===null) ? session_exchange() : $exchange;
		$as_of=substr((string)$as_of,0,10);

		$rules=strategy_rules($strategy);
		if ($rules===null){ $result['errors'][]="no rules for ".$strategy; return $result; }

		$selection=strategy_select_symbols($rules,$as_of,$exchange);
		$result['errors']=$selection['errors'];
		/* The names the selection dropped and why, so the reason reaches
		   the page rather than only the engine. */
		$result['skipped']=isset($selection['skipped']) ? $selection['skipped'] : [];
		if (!count($selection['symbols'])){ return $result; }

		/* Rounded DOWN to the six decimal places strategy_targets.target_weight
		   stores, not to the nearest. One fifteenth is 0.0666666..., and
		   round() makes that 0.066667: fifteen of those sum to 1.000005,
		   which the executor's "weights must not sum over 1" check
		   correctly refuses - so every strategy with a holdings count that
		   does not divide one exactly (3, 6, 7, 9, 11, 12, 13, 14, 15 ...)
		   planned no orders at all. Found on the first live dry run; the
		   suite missed it because every fixture used 5.

		   floor() leaves the sum at or just under 1 and the remainder as
		   cash: at most 0.000015 of the account, which is ten pence on a
		   hundred thousand. The check stays as it is - it is a real guard
		   against a selection that would try to invest more than the
		   account holds. */
		$weight=floor((1.0/$rules['holdings'])*1000000)/1000000;
		$result['weight']=$weight;

		//The score is kept for the audit trail: what the strategy thought of
		//the name on the day it bought it.
		$indicator=$rules['rank_by']!==null ? $rules['rank_by'] : $rules['score_indicator'];
		$scores=[];
		if ($indicator!==null){
			foreach (query("select symbol, value from statistics where indicator=? and date=?",
			               $indicator,$as_of) as $row) {
				$scores[$row['symbol']]=$row['value'];
			}
		}

		foreach ($selection['symbols'] as $symbol) {
			$result['targets'][]=[
				'strategy'=>$strategy,'as_of_date'=>$as_of,'symbol'=>$symbol,
				'exchange'=>$exchange,'target_weight'=>$weight,
				'score'=>isset($scores[$symbol]) ? $scores[$symbol] : null,
			];
		}

		if ($dry_run){ return $result; }

		query("delete from strategy_targets where strategy=? and as_of_date=?",$strategy,$as_of);
		foreach ($result['targets'] as $target) {
			query("insert into strategy_targets
			         (strategy,as_of_date,symbol,exchange,target_weight,score,created_at)
			       values (?,?,?,?,?,?,?)",
			      $target['strategy'],$target['as_of_date'],$target['symbol'],$target['exchange'],
			      $target['target_weight'],$target['score'],date('Y-m-d H:i:s'));
		}

		write_log("strategy",$strategy." targets for ".$as_of.": ".count($result['targets'])
		          ." names at ".$weight." each (".$rules['holdings']." holdings)");

		return $result;
	}

	/* ---------------------------------------------------------------------
	   Exits between rebalances.
	   --------------------------------------------------------------------- */

	/**
	 * Has this share closed below its 200 day average on the last two as of
	 * dates?
	 *
	 * Two, not one: a single close through a long average is noise, and a
	 * strategy that sells on it pays dealing costs to be whipsawed. Read out
	 * of the statistics the trend screens already use, so the exit and the
	 * screen cannot disagree about where the average is.
	 *
	 * Fewer than two dates carrying both figures is not a signal. A new
	 * share, or a night the job did not run, is an absence of evidence.
	 */
	function strategy_below_ma200_2d($symbol,$as_of) {

		$rows=query("select s.date,
		                    max(case when s.indicator='close_price' then s.value end) close_value,
		                    max(case when s.indicator='ma200'       then s.value end) ma200_value
		               from statistics s
		              where s.symbol=? and s.date<=? and s.indicator in ('close_price','ma200')
		              group by s.date
		             having close_value is not null and ma200_value is not null
		              order by s.date desc
		              limit 2",$symbol,substr((string)$as_of,0,10));

		if (count($rows)<2){ return false; }

		foreach ($rows as $row) {
			if ((float)$row['close_value']>=(float)$row['ma200_value']){ return false; }
		}

		return true;
	}

	/**
	 * One close below the 200 day average, and not two.
	 *
	 * The exit sells on the second. A holding on its first is one close
	 * away from being sold, which is worth marking on the page: a reader
	 * looking at the book should be able to see what is about to leave it
	 * rather than find out from the trades the next morning.
	 */
	function strategy_below_ma200_once($symbol,$as_of) {

		$rows=query("select s.date,
		                    max(case when s.indicator='close_price' then s.value end) close_value,
		                    max(case when s.indicator='ma200'       then s.value end) ma200_value
		               from statistics s
		              where s.symbol=? and s.date<=? and s.indicator in ('close_price','ma200')
		              group by s.date
		             having close_value is not null and ma200_value is not null
		              order by s.date desc
		              limit 2",$symbol,substr((string)$as_of,0,10));

		if (!count($rows)){ return false; }

		if ((float)$rows[0]['close_value']>=(float)$rows[0]['ma200_value']){ return false; }

		/* Only one so far: with no day before it there is nothing to say it
		   is the second, and with one that held above the average this is
		   the first. */
		return count($rows)<2
		    || (float)$rows[1]['close_value']>=(float)$rows[1]['ma200_value'];
	}

	/**
	 * Which holdings are one close away from being sold.
	 *
	 * Only where the strategy actually has the rule: a mark on a card whose
	 * strategy never sells on the 200 day average would be the page warning
	 * about something that cannot happen.
	 */
	function strategy_exit_warnings($strategy,$as_of) {

		$rules=strategy_rules($strategy);
		if ($rules===null||!in_array('below_ma200_2d',$rules['exit_list'],true)){ return []; }

		$out=[];
		foreach (strategy_positions($strategy) as $symbol=>$position) {
			if ((float)$position['quantity']<=0){ continue; }
			if (strategy_below_ma200_once($symbol,$as_of)){ $out[]=(string)$symbol; }
		}

		return $out;
	}

	/**
	 * Has this share stopped trading?
	 *
	 * Measured against the rest of the market: if the exchange has had
	 * STRATEGY_STALE_SESSIONS sessions since this symbol's last price, the
	 * share is not merely missing a day's data, it has gone - taken over,
	 * suspended or delisted. Counting sessions rather than calendar days
	 * means a Christmas week does not look like a delisting.
	 */
	function strategy_price_stale($symbol,$exchange,$as_of) {

		$as_of=substr((string)$as_of,0,10);

		$rows=query("select count(distinct date) n from historical_prices
		              where exchange=? and date<=?
		                and date > ifnull((select max(date) from historical_prices
		                                    where symbol=? and exchange=?),'1900-01-01')",
		            $exchange,$as_of,$symbol,$exchange);

		return count($rows)&&(int)$rows[0]['n']>=STRATEGY_STALE_SESSIONS;
	}

	/**
	 * Which holdings have to go tonight, and why.
	 *
	 * The named rules come from the strategy's own configuration. The
	 * delisting check does not: a holding that has stopped trading must be
	 * sold whatever the rules say, because the alternative is a book that
	 * carries a price nobody will ever trade at again. It is the one exit no
	 * strategy can turn off.
	 */
	function strategy_exits_due($strategy,$as_of,$exchange=null) {

		$exchange=($exchange===null) ? session_exchange() : $exchange;
		$as_of=substr((string)$as_of,0,10);

		$rules=strategy_rules($strategy);
		$exit_list=($rules===null) ? [] : $rules['exit_list'];

		$exits=[];

		foreach (strategy_positions($strategy) as $symbol=>$position) {

			if ((float)$position['quantity']<=0){ continue; }

			$position_exchange=(string)$position['exchange'];

			if (strategy_price_stale($symbol,$position_exchange,$as_of)){
				$exits[]=['symbol'=>$symbol,'exchange'=>$position_exchange,
				          'quantity'=>(float)$position['quantity'],
				          'reason'=>'delisted','last_price'=>true];
				continue;
			}

			if (in_array('below_ma200_2d',$exit_list,true)
			    &&strategy_below_ma200_2d($symbol,$as_of)){
				$exits[]=['symbol'=>$symbol,'exchange'=>$position_exchange,
				          'quantity'=>(float)$position['quantity'],
				          'reason'=>'below_ma200_2d','last_price'=>false];
			}
		}

		return $exits;
	}

	/**
	 * Sell the whole of each position an exit rule has triggered on.
	 *
	 * The cash is left idle until the next rebalance. That is deliberate:
	 * the exit says this holding is wrong, not that another one is right,
	 * and buying a replacement tonight would be a selection the strategy's
	 * own rules never made.
	 *
	 * The reason goes on the order, so the page can say why a trade happened
	 * without re-deriving the signal a year later.
	 */
	function strategy_place_exits($strategy,$as_of,$dry_run=false,$exchange=null) {

		$report=['exits'=>[],'sold'=>0,'failed'=>0];

		foreach (strategy_exits_due($strategy,$as_of,$exchange) as $exit) {

			$report['exits'][]=$exit;

			if ($dry_run){ continue; }

			if (strategy_halted()){
				write_log("strategy","kill switch stopped the exits for ".$strategy);
				break;
			}

			$client_order_id=strategy_record_order($strategy,$as_of,
				['symbol'=>$exit['symbol'],'exchange'=>$exit['exchange'],
				 'side'=>'SELL','quantity'=>$exit['quantity']],
				'exit: '.$exit['reason']);

			if ($client_order_id===null){ continue; }

			if (strategy_fill_order($client_order_id,$exit['last_price'])){ $report['sold']++; }
			else { $report['failed']++; }

			write_log("strategy",$strategy." exit ".$exit['reason']." ".$exit['symbol']
			          ." ".$exit['quantity']." at ".$as_of);
		}

		return $report;
	}

	/* ---------------------------------------------------------------------
	   Running one strategy for one night.
	   --------------------------------------------------------------------- */

	/**
	 * Resume, reconcile, plan, place and fill - the body that
	 * run_paper_execution.php used to be.
	 *
	 * Made a function so that three callers can share it: the command line
	 * script, the nightly run over every strategy, and the strategy page's
	 * dry run. A dry run writes nothing at all, and may be handed the
	 * targets it should plan from so that a rebalance can be inspected
	 * before its targets exist.
	 */
	function strategy_execute($strategy,$as_of,$dry_run=false,$targets=null,$options=[]) {

		$report=['lines'=>[],'orders'=>[],'errors'=>[],
		         'filled'=>0,'rejected'=>0,'skipped'=>0,'pending'=>0,
		         'account_value'=>null,'halted'=>false,'mode'=>STRATEGY_MODE_PAPER,
		         'not_placed'=>[]];

		$say=function($line) use (&$report) { $report['lines'][]=$line; };

		if (strategy_halted()){
			$report['halted']=true;
			$report['errors'][]="halted by the kill switch at ".STRATEGY_KILL_SWITCH_FILE;
			write_log("strategy","halted by kill switch at ".STRATEGY_KILL_SWITCH_FILE);
			return $report;
		}

		$account=strategy_account($strategy);
		$mode=strategy_mode_rules($account===null ? STRATEGY_MODE_PAPER : $account['mode']);
		$report['mode']=$mode['mode'];

		if ($account!==null&&$account['halted']==='Y'){
			$report['errors'][]="halted: ".(string)$account['halt_reason'];
			$report['halted']=true;
			return $report;
		}

		/* Unless the caller has already done it. strategy_run_night()
		   resumes before it decides whether tonight is a rebalance,
		   because last night's fills are what say the strategy has been
		   built - and resuming them here, after that decision, was how
		   both paper strategies came to rebalance the night after their
		   first build. */
		if (!$dry_run&&$mode['broker']===null&&empty($options['resumed'])){
			$resume=strategy_resume_pending($strategy);
			if ($resume['halted']){
				$report['errors'][]="pending orders exist and the account is not in PAPER mode;"
				                   ." a human must resolve them";
				return $report;
			}
			if ($resume['resumed']>0||$resume['failed']>0){
				$say("resumed ".$resume['resumed']." pending order(s), "
				     .$resume['failed']." could not be filled");
			}
		}

		$problems=strategy_reconcile($strategy);
		if (count($problems)>0){
			write_log("strategy","reconciliation failed: ".implode("; ",$problems));
			foreach ($problems as $problem) { $report['errors'][]="reconciliation: ".$problem; }
			return $report;
		}

		$plan=strategy_plan_orders($strategy,$as_of,$targets,$options);
		$report['account_value']=$plan['account_value'];
		$report['orders']=$plan['orders'];
		$report['not_placed']=isset($plan['skipped']) ? $plan['skipped'] : [];
		foreach ($plan['errors'] as $error) { $report['errors'][]=$error; }
		/* Said, not counted. A live plan bound by its capital cap is the
		   cap working. */
		foreach ((isset($plan['notes']) ? $plan['notes'] : []) as $note) { $say($note); }

		/* What was deliberately not ordered, and why. Cash left idle because
		   an order would have been too small is a decision, and a decision
		   nobody can see is indistinguishable from a bug. */
		foreach ($report['not_placed'] as $missed) {
			$say(sprintf("skip %-8s %s",$missed['symbol'],$missed['reason']));
		}

		if (count($plan['orders'])===0){
			$say("no orders");
			write_log("strategy",$strategy." ".$as_of.": no orders");
			return $report;
		}

		foreach ($plan['orders'] as $order) {
			$say(sprintf("%-4s %-8s %10s @ ~%s",$order['side'],$order['symbol'],
			             rtrim(rtrim(number_format($order['quantity'],4,'.',''),'0'),'.'),
			             $order['reference_price']===null ? '?' : round($order['reference_price'],2)));
		}

		if ($dry_run){
			$say("dry run, nothing written");
			return $report;
		}

		//Sells first, so the cash they raise is available to the buys.
		usort($plan['orders'],function($a,$b){
			if ($a['side']===$b['side']){ return 0; }
			return $a['side']==='SELL' ? -1 : 1;
		});

		foreach ($plan['orders'] as $order) {

			//Re-checked per order so dropping the kill switch stops a run midway.
			if (strategy_halted()){
				write_log("strategy","kill switch appeared mid-run, stopping");
				$report['halted']=true;
				$say("HALTED mid-run");
				break;
			}

			$client_order_id=strategy_record_order($strategy,$as_of,$order,'rebalance');
			if ($client_order_id===null){ $report['skipped']++; continue; }

			/* A broker account's orders are not filled here. They are
			   recorded PENDING and sent during the session by
			   includes/send_broker_orders.php, because a market order
			   queued overnight fills at whatever the open does - which is
			   not the price anything was decided at. */
			if ($mode['broker']!==null){ $report['pending']++; continue; }

			if (strategy_fill_order($client_order_id)){ $report['filled']++; continue; }

			/* Not filled. Left PENDING means the market has not traded
			   since the as of date and the next run will fill it, which is
			   the normal state of a rebalance decided tonight - and not a
			   failure to report as one. */
			$status=query("select status from strategy_orders where client_order_id=?",$client_order_id);
			if (count($status)&&$status[0]['status']==='PENDING'){ $report['pending']++; }
			else { $report['rejected']++; }
		}

		$account=strategy_account($strategy);
		$summary=$strategy." ".$as_of." (".$mode['words']."): ".$report['filled']." filled, "
		        .$report['pending']
		        .($mode['broker']===null ? " waiting for the next close, "
		                                 : " waiting to be sent in the session, ")
		        .$report['rejected']
		        ." rejected, ".$report['skipped']." already placed, cash "
		        .round((float)$account['cash'],2);
		write_log("strategy",$summary);
		$say($summary);

		return $report;
	}

	/**
	 * One strategy, one night: exits, then a rebalance if it is due, then
	 * the execution.
	 *
	 * That order matters. An exit is a decision about a holding the strategy
	 * already has, and it has to be taken before the rebalance values the
	 * account, or tonight's selection would be sized against a position the
	 * strategy has already decided to leave.
	 *
	 * Nothing here throws: a strategy that cannot run tonight must not stop
	 * the next one, let alone the rest of the nightly job. The report says
	 * what happened and the caller prints it.
	 */
	function strategy_run_night($strategy,$as_of,$dry_run=false,$exchange=null) {

		$night=['strategy'=>$strategy,'as_of'=>substr((string)$as_of,0,10),
		        'dry_run'=>$dry_run,'rebalance'=>false,'exits'=>[],
		        'targets'=>[],'not_selected'=>[],'lines'=>[],'errors'=>[],'orders'=>[],
		        'filled'=>0,'rejected'=>0,'skipped'=>0,'pending'=>0,
		        'account_value'=>null];

		$exchange=($exchange===null) ? session_exchange() : $exchange;
		$as_of=$night['as_of'];

		$rules=strategy_rules($strategy);
		if ($rules===null){
			$night['errors'][]="no rules for ".$strategy.", nothing to run";
			return $night;
		}

		$account=strategy_account($strategy);
		if ($account===null){ $night['errors'][]="no account for ".$strategy; return $night; }

		/* PAPER or T212_DEMO, and nothing else. A mode this application does
		   not know is not run as if it were one it does. A T212_DEMO account
		   is planned here like any other and its orders are sent during the
		   session by includes/send_broker_orders.php; nothing in the nightly
		   path sends anything anywhere. */
		if (!strategy_mode_known($account['mode'])){
			$night['errors'][]="mode '".$account['mode']."' is not one this knows how to run";
			return $night;
		}
		if ($account['halted']==='Y'){
			$night['errors'][]="halted: ".(string)$account['halt_reason'];
			return $night;
		}

		try {

			/* Last night's orders first, before anything is decided from
			   what the strategy holds.

			   A paper order decided on the 16th fills at the 17th close,
			   so on the night of the 17th the book is still last night's
			   until these are resumed - and two things read that book. The
			   first build test saw no positions and nothing filled and
			   said "not built yet", which made the 17th a rebalance night
			   for a monthly strategy. The hold rank buffer saw no holdings
			   and so had nothing to hold, and re-picked the whole book
			   from the top of the ranking. Both paper strategies
			   rebalanced the night after their first build, sold three
			   names apiece and planned eight orders nobody asked for.

			   A broker account's orders are not filled here at all - the
			   sending job resolves them during the session - so there is
			   nothing to resume for one. */
			$mode=strategy_mode_rules($account['mode']);
			$resumed=false;

			if (!$dry_run&&$mode['broker']===null){
				$resume=strategy_resume_pending($strategy);
				if ($resume['halted']){
					$night['errors'][]="pending orders exist and the account is not in PAPER"
					                  ." mode; a human must resolve them";
					return $night;
				}
				if ($resume['resumed']>0||$resume['failed']>0){
					$night['lines'][]="resumed ".$resume['resumed']." pending order(s), "
					                 .$resume['failed']." could not be filled";
				}
				$resumed=true;
			}

			$exits=strategy_place_exits($strategy,$as_of,$dry_run,$exchange);
			$night['exits']=$exits['exits'];
			foreach ($exits['exits'] as $exit) {
				$night['lines'][]="exit ".$exit['reason']." ".$exit['symbol']
				                 ." ".$exit['quantity']." shares";
			}

			$night['rebalance']=strategy_rebalance_due($rules,$as_of,$exchange);

			$targets=null;
			if ($night['rebalance']){
				$written=strategy_write_targets($strategy,$as_of,$dry_run,$exchange);
				$night['targets']=$written['targets'];
				foreach ($written['errors'] as $error) { $night['errors'][]=$error; }

				/* A name the selection would have picked and could not.
				   Said on the run, kept on the record, and shown by the
				   dry run: a share quietly missing from a book of fifteen
				   is indistinguishable from a bug. */
				$night['not_selected']=isset($written['skipped']) ? $written['skipped'] : [];
				foreach ($night['not_selected'] as $missed) {
					$night['lines'][]="skip ".$missed['symbol'].": ".$missed['reason'];
					if (!$dry_run){
						write_log("strategy",$strategy." did not select ".$missed['symbol']
						          .": ".$missed['reason']);
					}
				}

				//A dry run has not written them, so the plan has to be told.
				if ($dry_run){ $targets=$written['targets']; }
			}

			$report=strategy_execute($strategy,$as_of,$dry_run,$targets,
			                         ['resumed'=>$resumed,
			                          'rebalance'=>$night['rebalance']]);

			foreach ($report['lines'] as $line) { $night['lines'][]=$line; }
			foreach ($report['errors'] as $error) { $night['errors'][]=$error; }
			$night['filled']=$report['filled'];
			$night['rejected']=$report['rejected'];
			$night['skipped']=$report['skipped'];
			$night['pending']=$report['pending'];
			/* The plan itself, not only the lines printed from it. The
			   strategy page's dry run shows a table of what tonight would
			   do, and a table cannot be built from sentences. */
			$night['orders']=$report['orders'];
			$night['account_value']=$report['account_value'];
			$night['halted']=!empty($report['halted']);
		}
		catch (Throwable $e) {
			/* One strategy's failure is one strategy's failure. Caught here
			   rather than in the script so that every caller - the nightly
			   run, the page's dry run - gets the same containment. */
			$night['errors'][]="failed: ".$e->getMessage();
			write_log("strategy",$strategy." threw: ".$e->getMessage());
		}

		return $night;
	}

	/* ---------------------------------------------------------------------
	   What it was all worth.
	   --------------------------------------------------------------------- */

	/**
	 * Write one strategy's value for a date.
	 *
	 * Holdings are marked at the last price on or before the date, so a
	 * holding that has stopped trading keeps its last traded price until the
	 * exit sells it rather than vanishing from the total. The note says when
	 * that is happening, because a flat line on the page should be
	 * explainable.
	 */
	function strategy_write_values($strategy,$as_of,$exchange=null) {

		$exchange=($exchange===null) ? session_exchange() : $exchange;
		$as_of=substr((string)$as_of,0,10);

		$account=strategy_account($strategy);
		if ($account===null){ return null; }

		$cash=(float)$account['cash'];
		$holdings_value=0.0;
		$holdings=0;
		$stale=[];

		foreach (strategy_positions($strategy) as $symbol=>$position) {

			if ((float)$position['quantity']<=0){ continue; }

			$price=strategy_price_asof($symbol,(string)$position['exchange'],$as_of);
			if ($price===null){
				//No price ever, which is not the same as a stale one. Counted
				//as a holding and valued at nothing, and named in the note.
				$stale[]=$symbol.' (unpriced)';
				$holdings++;
				continue;
			}

			if (strategy_price_stale($symbol,(string)$position['exchange'],$as_of)){
				$stale[]=$symbol.' (stale)';
			}

			$holdings_value += $price*(float)$position['quantity'];
			$holdings++;
		}

		$total=$cash+$holdings_value;
		$note=count($stale) ? substr('priced from stale data: '.implode(', ',$stale),0,255) : null;

		query("insert into strategy_values (strategy,date,cash,holdings_value,total,holdings,note)
		       values (?,?,?,?,?,?,?)
		       on duplicate key update cash=values(cash), holdings_value=values(holdings_value),
		                               total=values(total), holdings=values(holdings),
		                               note=values(note)",
		      $strategy,$as_of,round($cash,4),round($holdings_value,4),round($total,4),$holdings,$note);

		return ['cash'=>$cash,'holdings_value'=>$holdings_value,'total'=>$total,
		        'holdings'=>$holdings,'note'=>$note];
	}

	/**
	 * The equal weighted return of the universe between two dates: the mean
	 * of each constituent's own return, over the names that have a price on
	 * both.
	 *
	 * A name that joined in between has no starting price and is left out of
	 * that step rather than counted as a zero return. Returns null when
	 * nothing could be compared, which is not the same as a flat market.
	 */
	function strategy_equal_weight_step($from,$to,$exchange=null) {

		$exchange=($exchange===null) ? session_exchange() : $exchange;

		$rows=query("select avg(h1.price/h0.price-1) r, count(*) n
		               from historical_prices h0, historical_prices h1, stock_symbols ss
		              where h0.symbol=h1.symbol and h0.exchange=h1.exchange
		                and ss.symbol=h0.symbol and ss.exchange=h0.exchange
		                and ss.enabled='Y' and h0.exchange=?
		                and h0.date=? and h1.date=? and h0.price>0 and h1.price>0",
		            $exchange,substr((string)$from,0,10),substr((string)$to,0,10));

		if (!count($rows)||$rows[0]['r']===null||(int)$rows[0]['n']===0){
			return ['return'=>null,'constituents'=>0];
		}

		return ['return'=>(float)$rows[0]['r'],'constituents'=>(int)$rows[0]['n']];
	}

	/**
	 * The benchmark's return over a period, compounded one trading day at a
	 * time.
	 *
	 * Day by day rather than first close to last: an equal weighted index is
	 * rebalanced to equal weights every day, and the mean of the start-to-end
	 * returns is a different thing - a buy and hold basket. Compounding the
	 * daily cross-sectional mean is what the nightly benchmark does, so this
	 * and that agree.
	 */
	function strategy_benchmark_return($from,$to,$exchange=null) {

		$exchange=($exchange===null) ? session_exchange() : $exchange;

		$dates=query("select distinct date from historical_prices
		               where exchange=? and date>=? and date<=? order by date",
		             $exchange,substr((string)$from,0,10),substr((string)$to,0,10));

		if (count($dates)<2){ return null; }

		$level=1.0;
		$previous=substr((string)$dates[0]['date'],0,10);

		for ($i=1;$i<count($dates);$i++) {
			$date=substr((string)$dates[$i]['date'],0,10);
			$step=strategy_equal_weight_step($previous,$date,$exchange);
			if ($step['return']!==null){ $level=$level*(1+$step['return']); }
			$previous=$date;
		}

		return $level-1;
	}

	/**
	 * The benchmark: the FTSE 350 as an equal weighted index, written into
	 * strategy_values under STRATEGY_BENCHMARK.
	 *
	 * Equal weighted because that is what the data supports. No index level
	 * is loaded anywhere, so the alternative to building one from the 350
	 * constituents' own prices is having nothing to compare against. It is
	 * the honest comparison for an equal weighted strategy in any case: a
	 * capitalisation weighted index would be measuring stock picking against
	 * a bet on the largest companies.
	 *
	 * Two things it is not, both of which belong on the page:
	 *
	 *   it is today's constituents, so it carries the same survivorship
	 *   flattery a backtest does - the companies that left the index are not
	 *   in it;
	 *
	 *   it is a price index, not a total return one. Dividends are in
	 *   historical_prices only to the extent the adjusted close carries
	 *   them, which is the same treatment the strategies get, so the
	 *   comparison is at least like for like.
	 *
	 * The level starts at 100 on the first date it is asked for and
	 * compounds the equal weighted return between consecutive stored dates
	 * thereafter, so a night that is missed is covered by the next one
	 * rather than leaving a gap.
	 */
	function strategy_benchmark_update($as_of,$exchange=null) {

		$exchange=($exchange===null) ? session_exchange() : $exchange;
		$as_of=substr((string)$as_of,0,10);

		$previous=query("select date, total from strategy_values
		                  where strategy=? and date<=? order by date desc limit 1",
		                STRATEGY_BENCHMARK,$as_of);

		if (!count($previous)){
			//The base. 100 on the first date anybody asked about.
			query("insert into strategy_values (strategy,date,cash,holdings_value,total,holdings,note)
			       values (?,?,0,0,100,?,?)
			       on duplicate key update total=values(total), holdings=values(holdings),
			                               note=values(note)",
			      STRATEGY_BENCHMARK,$as_of,
			      (int)count_benchmark_constituents($exchange,$as_of),
			      'FTSE 350, equal weighted, rebased to 100');
			return ['date'=>$as_of,'level'=>100.0,'return'=>null];
		}

		$from=substr((string)$previous[0]['date'],0,10);
		$level=(float)$previous[0]['total'];

		if ($from>=$as_of){ return ['date'=>$from,'level'=>$level,'return'=>null]; }

		$step=strategy_equal_weight_step($from,$as_of,$exchange);

		if ($step['return']===null){
			write_log("strategy","no benchmark return between ".$from." and ".$as_of
			          ."; the level is carried forward");
			$return=0.0;
		}
		else {
			$return=$step['return'];
		}

		$level=$level*(1+$return);
		$rows=[['n'=>$step['constituents']]];

		query("insert into strategy_values (strategy,date,cash,holdings_value,total,holdings,note)
		       values (?,?,0,0,?,?,?)
		       on duplicate key update total=values(total), holdings=values(holdings),
		                               note=values(note)",
		      STRATEGY_BENCHMARK,$as_of,round($level,4),
		      (int)(count($rows) ? (int)$rows[0]['n'] : 0),
		      'FTSE 350, equal weighted, rebased to 100');

		return ['date'=>$as_of,'level'=>$level,'return'=>$return];
	}

	/** How many constituents the benchmark had on a date, for its row. */
	function count_benchmark_constituents($exchange,$as_of) {

		$rows=query("select count(distinct h.symbol) n
		               from historical_prices h, stock_symbols ss
		              where ss.symbol=h.symbol and ss.exchange=h.exchange
		                and ss.enabled='Y' and h.exchange=? and h.date=?",
		            $exchange,substr((string)$as_of,0,10));

		return count($rows) ? (int)$rows[0]['n'] : 0;
	}


	/* ---------------------------------------------------------------------
	   Readers for the strategy page.

	   Queries only: nothing below this line writes anything, so the page
	   cannot move cash however it is called. The dry run on that page is
	   strategy_run_night($strategy,$as_of,true), which writes nothing
	   either.
	   --------------------------------------------------------------------- */

	/** One strategy's value history, oldest first. */
	function strategy_series($strategy) {

		$out=[];
		foreach (query("select date, total, cash, holdings_value, holdings, note
		                 from strategy_values where strategy=? order by date",$strategy) as $row) {
			$out[]=['date'=>substr((string)$row['date'],0,10),
			        'total'=>(float)$row['total'],
			        'cash'=>(float)$row['cash'],
			        'holdings_value'=>(float)$row['holdings_value'],
			        'holdings'=>(int)$row['holdings'],
			        'note'=>$row['note']];
		}
		return $out;
	}

	/**
	 * The worst fall from a high, as a fraction.
	 *
	 * Measured on the stored nightly totals, so it is the worst drawdown
	 * between two nights rather than intraday - which is the honest figure
	 * for a strategy that only ever trades on a close.
	 */
	function strategy_drawdown($series) {

		$peak=null;
		$worst=0.0;

		foreach ($series as $point) {
			$total=(float)$point['total'];
			if ($peak===null||$total>$peak){ $peak=$total; }
			if ($peak>0){
				$fall=($peak-$total)/$peak;
				if ($fall>$worst){ $worst=$fall; }
			}
		}

		return $worst;
	}

	/**
	 * When the next rebalance can happen at the earliest.
	 *
	 * "At the earliest", and the page says so: the first trading date of
	 * next month is not knowable tonight, because the prices that decide
	 * which day the market opens have not been loaded yet. So this is the
	 * calendar boundary, and the run takes the first session on or after it.
	 */
	function strategy_next_rebalance($rules,$as_of) {

		if ($rules===null){ return null; }

		$as_of=substr((string)$as_of,0,10);

		if ($rules['rebalance']==='daily'){
			return date('Y-m-d',strtotime($as_of.' +1 day'));
		}

		if ($rules['rebalance']==='weekly'){
			return date('Y-m-d',strtotime('monday next week',strtotime($as_of)));
		}

		return date('Y-m-01',strtotime($as_of.' first day of next month'));
	}

	/** The last date this strategy actually rebalanced, or null. */
	function strategy_last_rebalance($strategy) {

		$rows=query("select max(as_of_date) d from strategy_targets where strategy=?",$strategy);

		return (count($rows)&&$rows[0]['d']!==null) ? substr((string)$rows[0]['d'],0,10) : null;
	}

	/**
	 * What the account is worth right now: cash, positions, both priced.
	 *
	 * The nightly job writes a valuation into strategy_values and that
	 * series is the history and the chart. It is not the headline. A
	 * practice account that bought fifteen shares this morning has a row
	 * from last night saying GBP5,000 and nothing held, and printing that
	 * over a table of fifteen holdings is a page arguing with itself.
	 *
	 * A position whose price cannot be found is counted at what it cost,
	 * and said so in "unpriced", rather than dropped: a share suspended
	 * this morning is still money in the account.
	 */
	/**
	 * The shares this strategy bought after the latest close, by symbol.
	 *
	 * A holding filled this morning has no close of its own yet: the
	 * latest one in historical_prices is yesterday's, from before the
	 * shares were bought. Marking it to that price is not a valuation, it
	 * is arithmetic on two unrelated days - and it showed the practice
	 * account up 0.7% on the afternoon it opened.
	 *
	 * One query, and the answer is normally empty: this only happens on
	 * the day of a fill.
	 */
	function strategy_fills_after($strategy,$as_of) {

		$as_of=substr((string)$as_of,0,10);

		$out=[];
		foreach (query("select symbol, max(fill_date) d from strategy_orders
		                 where strategy=? and status='FILLED' and fill_date is not null
		                 group by symbol",$strategy) as $row) {
			if (substr((string)$row['d'],0,10)>$as_of){
				$out[(string)$row['symbol']]=substr((string)$row['d'],0,10);
			}
		}
		return $out;
	}

	function strategy_value_now($strategy,$as_of,$exchange=null,$prices=null) {

		$exchange=($exchange===null) ? session_exchange() : $exchange;
		$as_of=substr((string)$as_of,0,10);

		$account=strategy_account($strategy);

		$out=['cash'=>$account===null ? 0.0 : (float)$account['cash'],
		      'holdings'=>0,'value'=>0.0,'total'=>0.0,'unpriced'=>0,'at_cost'=>0,
		      'prices_as_of'=>$as_of,'last_fill'=>null,'prices_behind'=>false];

		$filled=strategy_fills_after($strategy,$as_of);

		foreach (query("select symbol, exchange, quantity, avg_price
		                  from strategy_positions
		                 where strategy=? and quantity>0",$strategy) as $row) {

			$symbol=(string)$row['symbol'];
			$quantity=(float)$row['quantity'];
			/* From the page's one price query when it brought one, and
			   from the database when it did not. */
			$price=($prices!==null&&array_key_exists($symbol,$prices))
			     ? $prices[$symbol]
			     : strategy_price_asof($row['symbol'],(string)$row['exchange'],$as_of);

			$out['holdings']++;

			/* Bought since the last close. Valuing it at that close values
			   shares at a price from before they were bought, which is how
			   a practice account showed +0.7% on the day it opened: it had
			   not made anything, it had been marked to a price that
			   predated it. What it paid is the honest figure until a close
			   exists that is newer than the fill. */
			if (isset($filled[$symbol])){
				$out['at_cost']++;
				$out['value'] += (float)$row['avg_price']*$quantity;
				continue;
			}

			if ($price===null){
				$out['unpriced']++;
				$out['value'] += (float)$row['avg_price']*$quantity;
				continue;
			}
			$out['value'] += $price*$quantity;
		}

		$out['total']=$out['cash']+$out['value'];

		/* Whether the prices are behind the trading. An order that filled
		   at eleven this morning is in the positions above, but the last
		   close in historical_prices is yesterday's, so the value is
		   yesterday's prices on today's book and the page should say which
		   day it is quoting. */
		$fills=query("select max(fill_date) d from strategy_orders
		               where strategy=? and status='FILLED'",$strategy);

		if (count($fills)&&$fills[0]['d']!==null){
			$out['last_fill']=substr((string)$fills[0]['d'],0,10);
			$out['prices_behind']=($out['last_fill']>$as_of);
		}

		return $out;
	}

	/**
	 * The orders that have not finished: planned, waiting to be sent, sent.
	 *
	 * A paper account plans tonight and fills at tomorrow's close, so
	 * between the two the page showed "nothing held" over an account that
	 * had just decided to buy fifteen shares. These are those orders.
	 */
	function strategy_pending_orders($strategy,$limit=200) {

		$limit=max(1,min(500,(int)$limit));

		$out=[];
		foreach (query("select o.client_order_id, o.as_of_date, o.symbol, o.exchange, o.side,
		                       o.quantity, o.status, o.note, o.broker, o.broker_order_id,
		                       o.sent_at, ss.name
		                  from strategy_orders o
		                  left join stock_symbols ss on ss.symbol=o.symbol
		                                            and ss.exchange=o.exchange
		                 where o.strategy=? and o.status in ('PENDING','SENT','UNKNOWN')
		                 order by o.side desc, o.symbol asc
		                 limit ".$limit,$strategy) as $row) {
			$out[]=$row;
		}
		return $out;
	}

	/**
	 * What became of an order, in words.
	 *
	 * The status column is the engine's vocabulary. REJECTED is what the
	 * broker did; "Refused" is what a reader needs to know happened.
	 */
	function strategy_order_outcome($status) {

		$words=['FILLED'=>'Filled','REJECTED'=>'Refused','CANCELLED'=>'Cancelled',
		        'SENT'=>'Sent','UNKNOWN'=>'Unknown','PENDING'=>'Waiting'];

		$status=strtoupper(trim((string)$status));

		return isset($words[$status]) ? $words[$status] : $status;
	}

	/**
	 * What an outstanding order is waiting for, in words.
	 *
	 * The status column holds PENDING for two different waits: a paper
	 * order waits for tomorrow's close, and a broker order waits for the
	 * sending job. A reader cannot tell those apart from the word PENDING.
	 */
	function strategy_order_wait($status,$broker=null) {

		$status=strtoupper(trim((string)$status));

		if ($status==='SENT'){ return 'Sent, waiting to fill'; }
		if ($status==='UNKNOWN'){ return 'Sent, no answer seen yet'; }
		if ($status==='PENDING'){
			return (trim((string)$broker)!=='')
			     ? 'Waiting to be sent to the broker'
			     : "Waiting to fill at tonight's close";
		}
		return $status;
	}

	/**
	 * Everything the page shows at the top of a strategy's card.
	 *
	 * The benchmark is read over the strategy's own period rather than over
	 * all of history: a strategy that started in March must not be compared
	 * against an index that started in January, which is the easiest way to
	 * make a comparison flatter or damn a strategy for a month it was not
	 * invested in.
	 */
	function strategy_card($strategy,$exchange=null,$as_of=null,$prices=null) {

		$exchange=($exchange===null) ? session_exchange() : $exchange;

		/* The date the live figures are priced at: the last close in the
		   data. Not today - on a Sunday there is no close - and not the
		   last night the valuation job ran, which is the thing this card
		   stopped trusting. */
		if ($as_of===null){
			$as_of=last_trading_date($exchange);
			if ($as_of===null){ $as_of=date('Y-m-d'); }
		}

		$rules=strategy_rules($strategy);
		$account=strategy_account($strategy);
		$series=strategy_series($strategy);
		$now=strategy_value_now($strategy,$as_of,$exchange,$prices);

		$card=['strategy'=>$strategy,
		       'rules'=>$rules,
		       'note'=>$account===null ? null : $account['note'],
		       'enabled'=>$account===null ? 'N' : $account['enabled'],
		       'mode'=>$account===null ? null : $account['mode'],
		       'mode_words'=>$account===null ? null
		                   : strategy_mode_rules($account['mode'])['words'],
		       'broker'=>$account===null ? null
		               : strategy_mode_rules($account['mode'])['broker'],
		       'halted'=>$account===null ? 'N' : $account['halted'],
		       'halt_reason'=>$account===null ? null : $account['halt_reason'],
		       /* Live, not last night's. The nightly valuation is history
		          and the chart; what the account holds and what is in it is
		          read from strategy_accounts and strategy_positions every
		          time this card is built. The practice account showed
		          GBP5,000 and nought holdings over a table of fifteen
		          because these three lines came out of strategy_values. */
		       'cash'=>$now['cash'],
		       'screen'=>null,
		       'start'=>null,'as_of'=>null,
		       'total'=>$now['total'],'holdings'=>$now['holdings'],
		       'held_value'=>$now['value'],
		       'unpriced'=>$now['unpriced'],
		       'prices_as_of'=>$now['prices_as_of'],
		       'prices_behind'=>$now['prices_behind'],
		       'last_fill'=>$now['last_fill'],
		       'return'=>null,'benchmark_return'=>null,'drawdown'=>null,
		       'last_rebalance'=>strategy_last_rebalance($strategy),
		       'next_rebalance'=>null,
		       'stale'=>null,
		       'points'=>count($series)];

		if ($rules!==null&&$rules['selection']==='screen'&&(int)$rules['screen_id']>0){
			$screen=query("select name from screen where id=?",(int)$rules['screen_id']);
			$card['screen']=count($screen) ? $screen[0]['name'] : null;
		}

		if (!count($series)){ return $card; }

		$first=$series[0];
		$last=$series[count($series)-1];

		$card['start']=$first['date'];
		$card['as_of']=$last['date'];
		$card['stale']=$last['note'];
		$card['drawdown']=strategy_drawdown($series);
		$card['next_rebalance']=strategy_next_rebalance($rules,$last['date']);

		/* The return is measured to the live total as well, so the
		   headline value and the return beside it are the same money. */
		if ($first['total']>0&&$card['total']!==null){
			$card['return']=($card['total']/$first['total'])-1;
		}

		/* The benchmark over the same two dates. The first row on or after
		   the strategy's start, so a strategy that began before the
		   benchmark did is compared from the first night both existed
		   rather than against nothing. */
		$from=query("select total from strategy_values where strategy=? and date>=? order by date asc limit 1",
		            STRATEGY_BENCHMARK,$first['date']);
		$to=query("select total from strategy_values where strategy=? and date<=? order by date desc limit 1",
		          STRATEGY_BENCHMARK,$last['date']);

		if (count($from)&&count($to)&&(float)$from[0]['total']>0){
			$card['benchmark_return']=((float)$to[0]['total']/(float)$from[0]['total'])-1;
		}

		return $card;
	}

	/**
	 * What a strategy holds now, priced at a date.
	 *
	 * Days held is measured from the first buy after the last time the
	 * position was closed, which is what "how long has this been in the
	 * book" means for a strategy that can sell a name and buy it back two
	 * months later.
	 */
	function strategy_holdings($strategy,$as_of,$exchange=null,$prices=null) {

		$exchange=($exchange===null) ? session_exchange() : $exchange;
		$as_of=substr((string)$as_of,0,10);

		$rows=[];
		$total=0.0;

		/* The same rule the headline uses: a share bought since the last
		   close is worth what it cost until there is a close newer than
		   the fill. The two figures have to agree, or the Holdings table
		   adds up to something the card does not say. */
		$filled=strategy_fills_after($strategy,$as_of);

		foreach (query("select p.symbol, p.exchange, p.quantity, p.avg_price, ss.name
		                 from strategy_positions p
		                 left join stock_symbols ss on ss.symbol=p.symbol and ss.exchange=p.exchange
		                where p.strategy=? and p.quantity>0
		                order by p.symbol",$strategy) as $row) {

			$price=($prices!==null&&array_key_exists((string)$row['symbol'],$prices))
			     ? $prices[(string)$row['symbol']]
			     : strategy_price_asof($row['symbol'],(string)$row['exchange'],$as_of);
			/* A float: a Trading 212 account holds 0.44 of a share, and an
			   int cast here showed the holding as nought on the page while
			   its average cost and its value were right. */
			$quantity=(float)$row['quantity'];
			$cost=(float)$row['avg_price']*$quantity;

			$fresh=isset($filled[(string)$row['symbol']]);
			if ($fresh){ $price=(float)$row['avg_price']; }

			$value=($price===null) ? null : $price*$quantity;

			$last_sell=query("select max(fill_date) d from strategy_orders
			                   where strategy=? and symbol=? and side='SELL' and status='FILLED'",
			                 $strategy,$row['symbol']);
			$since=(count($last_sell)&&$last_sell[0]['d']!==null) ? $last_sell[0]['d'] : '1900-01-01';

			$opened=query("select min(fill_date) d from strategy_orders
			                where strategy=? and symbol=? and side='BUY' and status='FILLED'
			                  and fill_date>?",$strategy,$row['symbol'],$since);

			$held_since=(count($opened)&&$opened[0]['d']!==null) ? substr((string)$opened[0]['d'],0,10) : null;

			$rows[]=['symbol'=>$row['symbol'],
			         'name'=>$row['name'],
			         'quantity'=>$quantity,
			         'avg_price'=>(float)$row['avg_price'],
			         'price'=>$price,
			         'value'=>$value,
			         'cost'=>$cost,
			         'gain'=>($value===null) ? null : $value-$cost,
			         'gain_percent'=>($value===null||$cost<=0) ? null : (($value/$cost)-1),
			         /* Priced at what it cost, because it was bought after
			            the latest close and there is nothing newer to mark
			            it to. */
			         'at_cost'=>$fresh,
			         'held_since'=>$held_since,
			         /* To today, and never below nought. Measured to the as of
			            date it read -1 on the morning of a fill: the shares were
			            bought today and the last close in the data was yesterday. */
			         'days_held'=>$held_since===null ? null
			                     : max(0,(int)floor((strtotime(date('Y-m-d'))-strtotime($held_since))/86400)),
			         'stale'=>strategy_price_stale($row['symbol'],(string)$row['exchange'],$as_of)];

			if ($value!==null){ $total += $value; }
		}

		/* Weights as a share of the ACCOUNT, not of what is held.
		   Target weight is one over the number of holdings of the account
		   - cash included - so a column of weights against the holdings
		   total was being read against a target measured on something
		   else: fifteen names at 6.6% of GBP4,962 beside a target of 6.6%
		   of GBP5,034. The page shows a cash row so the column still sums
		   to a hundred. */
		$account=strategy_account($strategy);
		$cash=($account===null) ? 0.0 : (float)$account['cash'];
		$account_value=$total+$cash;

		foreach ($rows as $i => $row) {
			$rows[$i]['weight']=($account_value>0&&$row['value']!==null)
			                  ? $row['value']/$account_value : null;
		}

		return $rows;
	}

	/**
	 * A value history rebased to 100 at its first point, which is how the
	 * chart puts a 100,000 pound account and an index on one axis.
	 *
	 * Each line is rebased to its own start rather than to a common date, so
	 * a strategy that began in March starts at 100 in March. Comparing the
	 * numbers at the right hand end is then only meaningful between lines
	 * that started together, which is why the card carries the benchmark
	 * return over the strategy's own period as well.
	 */
	function strategy_rebased($series) {

		$out=[];
		$base=null;

		foreach ($series as $point) {
			$total=(float)$point['total'];
			if ($base===null){
				if ($total<=0){ continue; }
				$base=$total;
			}
			$out[]=['time'=>$point['date'],'value'=>round(100.0*$total/$base,4)];
		}

		return $out;
	}

	/** The trades, newest first. */
	function strategy_trades($strategy,$limit=50) {

		$limit=max(1,min(500,(int)$limit));

		$out=[];
		foreach (query("select as_of_date, fill_date, symbol, side, quantity, fill_price,
		                       consideration, commission, stamp_duty, slippage, total_cost,
		                       status, note, broker, broker_order_id, broker_status, sent_at
		                  from strategy_orders where strategy=?
		                 order by ifnull(fill_date,as_of_date) desc, symbol asc
		                 limit ".$limit,$strategy) as $row) {
			$out[]=$row;
		}
		return $out;
	}

	/** Everything the strategy has paid to deal, ever. */
	function strategy_costs_paid($strategy) {

		$rows=query("select ifnull(sum(commission),0) commission,
		                    ifnull(sum(stamp_duty),0) stamp_duty,
		                    ifnull(sum(slippage),0) slippage,
		                    count(*) fills
		               from strategy_orders where strategy=? and status='FILLED'",$strategy);

		if (!count($rows)){ return ['commission'=>0.0,'stamp_duty'=>0.0,'slippage'=>0.0,
		                            'total'=>0.0,'fills'=>0]; }

		$row=$rows[0];

		return ['commission'=>(float)$row['commission'],
		        'stamp_duty'=>(float)$row['stamp_duty'],
		        'slippage'=>(float)$row['slippage'],
		        'total'=>(float)$row['commission']+(float)$row['stamp_duty']+(float)$row['slippage'],
		        'fills'=>(int)$row['fills']];
	}


	/* ---------------------------------------------------------------------
	   Backtesting, through the same engine.
	   --------------------------------------------------------------------- */

	/** True once sql/strategy_backtest.sql has been applied. */
	function strategy_backtest_ready() {

		static $ready=null;
		if ($ready!==null){ return $ready; }

		$rows=query("select count(*) n from information_schema.tables
		              where table_schema=database() and table_name='strategy_backtest_runs'");

		$ready=count($rows)&&(int)$rows[0]['n']===1;

		if (!$ready){
			write_log("strategy","sql/strategy_backtest.sql has not been applied: no backtests");
		}

		return $ready;
	}

	/**
	 * Every indicator a strategy's selection depends on.
	 *
	 * For a screen that is the indicator behind each of its criteria, read
	 * out of the screen rather than named here, so a criterion added to a
	 * screen is one the backtest knows it needs. Plus rank_by, which is how
	 * the names are ordered, and the score indicator for a score strategy.
	 */
	function strategy_required_indicators($rules) {

		if ($rules===null){ return []; }

		$needed=[];

		if ($rules['selection']==='screen'&&(int)$rules['screen_id']>0){
			foreach (query("select distinct si.name
			                  from screen_build sb, screen_criteria sc, screen_indicators si
			                 where si.indicator_id=sc.indicator_id and sb.criteria_id=sc.id
			                   and sb.screen_id=?",(int)$rules['screen_id']) as $row) {
				$needed[$row['name']]=true;
			}
		}

		if ($rules['score_indicator']!==null){ $needed[$rules['score_indicator']]=true; }
		if ($rules['rank_by']!==null){ $needed[$rules['rank_by']]=true; }

		/* An exit rule reads indicators too, and a backtest that could not
		   see them would hold through every exit and flatter the strategy. */
		if (in_array('below_ma200_2d',$rules['exit_list'],true)){
			$needed['close_price']=true;
			$needed['ma200']=true;
		}

		return array_keys($needed);
	}

	/**
	 * The earliest date a strategy can honestly be backtested from, and why.
	 *
	 * This is the look-ahead rule, and it is data rather than a list of
	 * strategies: an indicator can only be used on a date it was actually
	 * stored for. Fundamentals and the scores built from them start in
	 * November 2026, so a value strategy refuses anything earlier instead of
	 * ranking 2019 on today's figures - which would be a backtest of
	 * hindsight. Prices, and the close and moving averages built from them,
	 * can go back as far as the price history once
	 * backfill_trend_statistics.php has written them.
	 */
	function strategy_backtest_earliest($rules) {

		$out=['date'=>null,'indicators'=>[],'missing'=>[]];

		foreach (strategy_required_indicators($rules) as $indicator) {

			$rows=query("select min(date) d, count(*) n from statistics where indicator=?",$indicator);

			$first=(count($rows)&&$rows[0]['d']!==null) ? substr((string)$rows[0]['d'],0,10) : null;

			$out['indicators'][$indicator]=$first;

			if ($first===null){ $out['missing'][]=$indicator; continue; }
			if ($out['date']===null||$first>$out['date']){ $out['date']=$first; }
		}

		return $out;
	}

	/** The backtest runs for a strategy, newest first. */
	function strategy_backtests($strategy,$limit=3) {

		if (!strategy_backtest_ready()){ return []; }

		$limit=max(1,min(50,(int)$limit));

		return query("select * from strategy_backtest_runs where strategy=?
		               order by started_at desc, run_id desc limit ".$limit,$strategy);
	}


	/* ---------------------------------------------------------------------
	   The dry run, as data.

	   The command line prints lines; the strategy page shows a table in a
	   popup. Both come from here, so the two cannot disagree about what
	   tonight would do.
	   --------------------------------------------------------------------- */

	/**
	 * Why a trade is happening, in words somebody can read.
	 *
	 * The engine's own vocabulary is a reason code; this is the sentence.
	 * Kept together in one function so the page, the order note and any
	 * future report say the same thing about the same decision.
	 */
	function strategy_reason_words($code) {

		$words = [
			'new_holding'        => 'Rebalance: new holding',
			'back_to_target'     => 'Rebalance: back to target weight',
			'no_longer_selected' => 'Rebalance: no longer selected',
			'below_ma200_2d'     => 'Exit: closed below 200-day average twice',
			'delisted'           => 'Delisted: sold at last price',
		];

		$code = (string) $code;

		return isset($words[$code]) ? $words[$code] : $code;
	}

	/**
	 * Everything tonight would do, for one strategy, as structured data.
	 *
	 * Writes nothing: strategy_run_night() is called with dry_run true, and
	 * the only other thing here is arithmetic over what it returned.
	 *
	 * Sells first, then buys, which is the order the engine fills in - the
	 * cash a sale raises is what pays for a purchase, and a table that shows
	 * the purchases first invites the question "with what money".
	 */
	function strategy_dry_run($strategy,$as_of,$exchange=null) {

		$exchange=($exchange===null) ? session_exchange() : $exchange;
		$as_of=substr((string)$as_of,0,10);

		$rules=strategy_rules($strategy);
		$account=strategy_account($strategy);
		$positions=strategy_positions($strategy);

		$report=[
			'strategy'=>$strategy,
			'as_of'=>$as_of,
			'halted'=>strategy_halted(),
			'rebalance'=>false,
			'next_rebalance'=>strategy_next_rebalance($rules,$as_of),
			'errors'=>[],
			'trades'=>[],
			'skipped'=>[],
			'totals'=>[],
			'currency'=>($account===null) ? 'GBP' : (string)$account['currency'],
		];

		if ($account===null){
			$report['errors'][]="There is no paper account for ".$strategy.".";
			return $report;
		}

		/* The account's own dealing rules. The popup used to price every
		   plan as a paper account: whole shares and GBP 5.95 a deal, in
		   front of a practice account that deals in hundredths and pays no
		   commission. The quantities it showed were not the quantities
		   tonight would use, and on a live account that would be a
		   rehearsal of a different order. */
		$mode=strategy_mode_rules($account['mode']);

		$night=strategy_run_night($strategy,$as_of,true,$exchange);

		$report['rebalance']=$night['rebalance'];
		$report['lines']=$night['lines'];
		/* The names the selection could not take, with the reason. A book
		   of fourteen where the rules say fifteen has to say why. */
		$report['skipped']=isset($night['not_selected']) ? $night['not_selected'] : [];

		/* "No targets for tonight" is the normal state of a monthly
		   strategy on the other thirty nights of the month, not something
		   to put in red in front of somebody. The popup says "no rebalance
		   tonight, next on <date>" instead, which is the same fact without
		   the alarm. It used to be filtered out here by matching on its
		   text; the engine does not say it any more. */
		foreach ($night['errors'] as $error) { $report['errors'][]=$error; }

		$cash_before=(float)$account['cash'];
		$holdings_before=0;
		foreach ($positions as $position) {
			if ((float)$position['quantity']>0){ $holdings_before++; }
		}

		$companies=[];
		foreach (query("select symbol, name from stock_symbols where exchange=?",$exchange) as $row) {
			$companies[$row['symbol']]=$row['name'];
		}

		$account_value=strategy_account_value($strategy,$as_of);

		$add=function($symbol,$side,$quantity,$price,$reason_code,$held,$held_weight,$target_weight)
		     use (&$report,$companies,$mode) {

			//Rounded the way this account deals, not always to a whole share.
			$quantity=strategy_round_quantity($quantity,$mode);

			/* The price is rounded to the four places the table shows, and
			   the value and the costs are worked out FROM that rounded
			   price rather than from the one behind it. Otherwise the row
			   does not add up: a thousand shares at 0.647264 is 647.26,
			   while a reader multiplying the 0.6473 in front of them gets
			   647.30 and wonders which number is wrong.
			
			   The few pence this costs against the engine's own arithmetic
			   do not matter here, and saying so is the footnote's job: the
			   fill happens at the next close, so every figure in this table
			   is an estimate of a different number anyway. */
			$price=($price===null) ? null : round((float)$price,4);
			$costs=($price===null) ? null : strategy_costs($side,$quantity,$price,$mode['mode']);

			$report['trades'][]=[
				'side'=>$side,
				'symbol'=>$symbol,
				'company'=>isset($companies[$symbol]) ? $companies[$symbol] : null,
				'quantity'=>$quantity,
				'price'=>$price,
				'value'=>($price===null) ? null : round($quantity*$price,2),
				'costs'=>($costs===null) ? null : [
					'commission'=>round($costs['commission'],2),
					'stamp_duty'=>round($costs['stamp_duty'],2),
					'slippage'=>round($costs['slippage'],2),
					'total'=>round($costs['commission']+$costs['stamp_duty']+$costs['slippage'],2),
				],
				'held'=>(float)$held,
				'weight_now'=>($held_weight===null) ? null : round($held_weight,4),
				'weight_target'=>($target_weight===null) ? null : round($target_weight,4),
				'reason_code'=>$reason_code,
				'reason'=>strategy_reason_words($reason_code),
			];
		};

		/* The exits first: they are sales, and they are decided before the
		   rebalance is. A holding the exits are selling is dropped from the
		   rebalance below - the engine would skip it anyway, because one
		   order per symbol per date is the rule, and showing it twice would
		   read as two trades. */
		$exited=[];

		foreach ($night['exits'] as $exit) {

			$exited[$exit['symbol']]=true;
			$price=strategy_price_asof($exit['symbol'],$exit['exchange'],$as_of);
			//Float: a practice account holds 10.5 shares, and (int) sells ten.
			$held=(float)$exit['quantity'];

			$add($exit['symbol'],'SELL',$held,$price,$exit['reason'],$held,
			     ($account_value!==null&&$account_value>0&&$price!==null)
			       ? ($held*$price)/$account_value : null,
			     0.0);
		}

		$sells=[];
		$buys=[];

		foreach (($night['orders'] ?? []) as $order) {
			if (isset($exited[$order['symbol']])){ continue; }
			if ($order['side']==='SELL'){ $sells[]=$order; } else { $buys[]=$order; }
		}

		foreach (array_merge($sells,$buys) as $order) {
			$add($order['symbol'],$order['side'],$order['quantity'],$order['reference_price'],
			     isset($order['reason_code']) ? $order['reason_code'] : 'back_to_target',
			     isset($order['held']) ? $order['held'] : 0,
			     isset($order['held_weight']) ? $order['held_weight'] : null,
			     isset($order['target_weight']) ? $order['target_weight'] : null);
		}

		/* The totals. Cash after is cash before plus the signed effect of
		   every fill, which for a sale is what it raises less its costs and
		   for a purchase is what it costs including them. */
		$bought=$sold=0.0;
		$commission=$stamp=$slippage=0.0;
		$buy_count=$sell_count=0;
		$cash_after=$cash_before;
		$holdings_after=$holdings_before;

		foreach ($report['trades'] as $trade) {

			if ($trade['value']===null){ continue; }

			/* Compared as the quantities they are. A practice account
			   holds hundredths of a share, and whole-number comparisons
			   counted a holding of 0.44 as nothing held. */
			if ($trade['side']==='BUY'){
				$buy_count++;
				$bought += $trade['value'];
				$cash_after -= $trade['value']+$trade['costs']['total'];
				if ((float)$trade['held']<=0){ $holdings_after++; }
			}
			else {
				$sell_count++;
				$sold += $trade['value'];
				$cash_after += $trade['value']-$trade['costs']['total'];
				if ((float)$trade['quantity']>=(float)$trade['held']-0.00000001){ $holdings_after--; }
			}

			$commission += $trade['costs']['commission'];
			$stamp      += $trade['costs']['stamp_duty'];
			$slippage   += $trade['costs']['slippage'];
		}

		$report['totals']=[
			'buys'=>$buy_count,
			'sells'=>$sell_count,
			'value_bought'=>round($bought,2),
			'value_sold'=>round($sold,2),
			'costs'=>['commission'=>round($commission,2),
			          'stamp_duty'=>round($stamp,2),
			          'slippage'=>round($slippage,2),
			          'total'=>round($commission+$stamp+$slippage,2)],
			'cash_before'=>round($cash_before,2),
			'cash_after'=>round($cash_after,2),
			/* The estimate prices every fill at the as of close, while the
			   engine fills the sales first and then trims the last purchase
			   to whatever cash is left. So a small negative here is not an
			   overdraft, it is the arithmetic saying "the last order will be
			   a few shares smaller than this" - which is what the engine
			   will do. Said plainly rather than rounded up to zero. */
			'trim_expected'=>($cash_after<0),
			'holdings_before'=>$holdings_before,
			'holdings_after'=>max(0,$holdings_after),
			'account_value'=>($account_value===null) ? null : round($account_value,2),
		];

		return $report;
	}

?>
