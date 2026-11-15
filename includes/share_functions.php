<?php

	//get_momentum_screen() and the other screen builders below call
	//combine_criteria_lists() and intersect_list(), which live in
	//share_screen.php. Pages that happened to include that file themselves
	//worked; screening.php did not, and died on an undefined function.
	require_once("share_screen.php");


/* Share Functions */


/* ---------------------------------------------------------------------------
   Investment funds.

   An investment trust owns other companies' shares, so its P/E, price/sales,
   price/book and return on equity describe a wrapper rather than a business:
   ranked against operating companies they are noise, and on the live FTSE 350
   for 2026-11-13 they put three trusts in the ten best overall scores. Funds
   are therefore left out of the value, quality and overall rankings, and out
   of the denominators those rankings are worked out against, so a company is
   ranked against companies only. Momentum is untouched - a price series is a
   price series whoever owns the assets behind it.

   Which sectors are funds is data, in fund_sectors; which indicators funds are
   ranked on is data too, in screen_indicators.rank_funds. Both arrive with
   sql/fund_sectors.sql. See that file.
   --------------------------------------------------------------------------- */

/**
 * True once sql/fund_sectors.sql has been applied.
 *
 * deploy/deploy.sh pulls code and restarts PHP; it does not run migrations, so
 * there is a window where this code is on the server and the table is not.
 * Without this check the nightly job would die on an unknown table. It carries
 * on ranking the way it did before instead, and says so in the log.
 *
 * Checked once per process: get_statistics.php asks per indicator and per
 * symbol, and this is information_schema, not a cheap lookup.
 */
function fund_config_ready() {

	static $ready = null;

	if ($ready !== null)
		return $ready;

	$table  = query("select count(*) n from information_schema.tables
	                  where table_schema=database() and table_name='fund_sectors'");
	$column = query("select count(*) n from information_schema.columns
	                  where table_schema=database() and table_name='screen_indicators'
	                    and column_name='rank_funds'");
	$flag   = query("select count(*) n from information_schema.columns
	                  where table_schema=database() and table_name='stock_symbols'
	                    and column_name='is_fund'");

	$ready = (count($table) && (int) $table[0]['n'] === 1)
	      && (count($column) && (int) $column[0]['n'] === 1)
	      && (count($flag) && (int) $flag[0]['n'] === 1);

	if (!$ready)
		write_log("fund_sectors", "sql/fund_sectors.sql has not been applied: "
		          ."funds are still being ranked alongside companies");

	return $ready;
}

/**
 * A SQL predicate, ready to be AND'ed on, that excludes investment funds.
 *
 * $symbol_expr is how the surrounding query spells the symbol column, e.g.
 * 's.symbol'. The same two sources as is_fund(): the provider's flag on
 * stock_symbols, or a sector in fund_sectors. No sector name is interpolated
 * anywhere, so adding a sector is a row rather than a release.
 *
 * Not scoped by exchange. A symbol's sector is the same whichever exchange row
 * carries it, and the ranking query it is used in is not scoped by exchange
 * either.
 *
 * Returns the empty string when the migration has not been applied, which
 * leaves the query exactly as it was.
 */
function not_fund_sql($symbol_expr) {

	if (!fund_config_ready())
		return "";

	return " and not exists (select 1 from stock_symbols fund_ss
	                          where fund_ss.symbol = ".$symbol_expr."
	                            and (fund_ss.is_fund = 'Y'
	                                 or exists (select 1 from fund_sectors fund_fs
	                                             where fund_fs.sector = fund_ss.sector))) ";
}

/* ---------------------------------------------------------------------------
   Indicators that do not apply to a whole sector.

   Altman's Z was fitted on manufacturers. A bank or an insurer carries
   leverage because leverage is the business, and the formula reads that as
   distress: ranking it there would put every financial at the bottom of the
   quality score for being a financial. So the score is still shown - it is a
   real number and the owner can look at it - but it is not ranked and it does
   not feed the quality score.

   indicator_sector_exclusions holds which indicator does not apply to which
   sector, matched against stock_symbols.sector, the same column fund_sectors
   matches. No sector name appears in the application's SQL; see
   sql/fmp_scores.sql.
   --------------------------------------------------------------------------- */

/**
 * True once sql/fmp_scores.sql has been applied.
 *
 * Same reason as fund_config_ready(): deploy/deploy.sh does not run
 * migrations, so the code can reach the server before the table does, and the
 * nightly job should carry on ranking the way it did rather than die on an
 * unknown table.
 */
function sector_exclusions_ready() {

	static $ready = null;

	if ($ready !== null)
		return $ready;

	$rows = query("select count(*) n from information_schema.tables
	                where table_schema=database() and table_name='indicator_sector_exclusions'");

	$ready = count($rows) && (int) $rows[0]['n'] === 1;

	if (!$ready)
		write_log("fmp_scores", "sql/fmp_scores.sql has not been applied: "
		          ."every indicator is being ranked for every sector");

	return $ready;
}

/**
 * A SQL predicate, ready to be AND'ed on, that leaves out the sectors this
 * indicator does not apply to. Empty when it applies everywhere.
 *
 * $symbol_expr is how the surrounding query spells the symbol column. The
 * indicator name is passed as a bound parameter nowhere - it is interpolated,
 * so it is quoted here the way the rest of the ranking query quotes it, and
 * it comes from screen_indicators rather than from a request.
 */
function sector_exclusion_sql($indicator, $symbol_expr) {

	if (!sector_exclusions_ready())
		return "";

	$rows = query("select count(*) n from indicator_sector_exclusions where indicator=?",
	              $indicator);

	if (!count($rows) || (int) $rows[0]['n'] === 0)
		return "";

	return " and not exists (select 1 from stock_symbols excl_ss, indicator_sector_exclusions excl_x
	                          where excl_ss.symbol = ".$symbol_expr."
	                            and excl_x.sector = excl_ss.sector
	                            and excl_x.indicator = '".addslashes($indicator)."') ";
}

/**
 * The same exclusion where the indicator is a column rather than a constant.
 *
 * The score queries span several indicators at once, so they cannot name one.
 * A row left out here is left out of the average as well as the ranking,
 * which is the point: a bank should be scored on the measures that apply to
 * it, not scored on Altman and handed the neutral 50 for it.
 *
 * That neutral 50 is still right for a value that was left unranked because
 * it has no meaning for the company - a negative P/E - which is a different
 * case and keeps the old treatment.
 */
function not_sector_excluded_sql($symbol_expr, $indicator_expr) {

	if (!sector_exclusions_ready())
		return "";

	return " and not exists (select 1 from stock_symbols excl2_ss, indicator_sector_exclusions excl2_x
	                          where excl2_ss.symbol = ".$symbol_expr."
	                            and excl2_x.sector = excl2_ss.sector
	                            and excl2_x.indicator = ".$indicator_expr.") ";
}

/**
 * Why an indicator is not ranked for this symbol, or null when it is.
 *
 * The quote page shows the reason beside the figure, so a blank rank reads as
 * a decision rather than as missing data.
 */
function sector_exclusion_reason($indicator, $symbol) {

	if (!sector_exclusions_ready())
		return null;

	$rows = query("select x.reason from indicator_sector_exclusions x, stock_symbols ss
	                where ss.symbol=? and x.sector=ss.sector and x.indicator=?",
	              $symbol, $indicator);

	return count($rows) ? (string) $rows[0]['reason'] : null;
}

/* ---------------------------------------------------------------------------
   Reporting currency.

   FMP's price ratios divide the London price by per-share figures from
   accounts kept in the company's own currency, and nothing converted between
   them: SHEL reports in dollars and came back with a P/E of 8.43 where it is
   about 11.1, BGEO reports in lari with a P/E of 2.38 where it is about 8.6.
   python/get_api_stats.py multiplies by the rate on the way into statistics,
   so everything downstream - the rankings, the peer medians, the implied
   prices, this page and the AI outlook - sees one currency.

   These two read back what was done, so the page can say so. See
   sql/report_currency.sql.
   --------------------------------------------------------------------------- */

/**
 * What a company keeps its accounts in, or null if it is not known.
 */
function report_currency($symbol) {

	$rows = query("select report_ccy from stock_symbols where symbol=? and exchange=?",
	              $symbol, session_exchange());

	if (!count($rows) || $rows[0]['report_ccy'] === null)
		return null;

	$currency = strtoupper(trim((string) $rows[0]['report_ccy']));

	return $currency === '' ? null : $currency;
}

/**
 * Quote units per pound on a date, or null if no rate was stored.
 */
function fx_rate_for($date, $currency, $base = 'GBP') {

	$currency = strtoupper(trim((string) $currency));

	if ($currency === '' || $currency === $base)
		return 1.0;

	$rows = query("select rate from fx_rates where date=? and base=? and quote_ccy=?",
	              $date, $base, $currency);

	return count($rows) ? (float) $rows[0]['rate'] : null;
}

/**
 * The currency note for a share, or null when there is nothing to say.
 *
 * Null for a sterling reporter and for one whose currency is not known: in
 * both cases the figures on the page are the provider's own and no
 * conversion happened.
 */
function report_currency_note($symbol, $date = null) {

	$currency = report_currency($symbol);

	if ($currency === null || $currency === 'GBP')
		return null;

	if ($date === null) {
		$rows = query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
		$date = count($rows) ? $rows[0]['job_date'] : null;
	}

	return ["currency" => $currency,
	        "rate"     => $date === null ? null : fx_rate_for($date, $currency),
	        "date"     => $date];
}

/* ---------------------------------------------------------------------------
   The as of date.

   The date the nightly job calculates for, and the date every page that shows
   a statistic resolves through the get_statistics_asof marker.

   It used to be the calendar day before the run. Run by hand on Sunday 15
   November it wrote a set of statistics for Saturday the 14th: no ratios,
   because no fundamentals are loaded for a Saturday, and so an overall score
   that was momentum alone - and the dashboard moved onto that date, because
   the marker said it was the newest. The prices themselves are the only thing
   that knows when the market was last open, so they are what the date comes
   from.
   --------------------------------------------------------------------------- */

/**
 * The last date prices were loaded for, or null if there are none.
 */
function last_trading_date($exchange = null) {

	$rows = query("select max(date) last_date from historical_prices where exchange=?",
	              $exchange === null ? session_exchange() : $exchange);

	if (!count($rows) || $rows[0]['last_date'] === null)
		return null;

	return substr((string) $rows[0]['last_date'], 0, 10);
}

/**
 * The as of date for a run, or null if there is not one to use.
 *
 * With no argument it is the last trading day. With one it has to be a date
 * prices were actually loaded for: a job asked to calculate for a day the
 * market was shut would write a half set of statistics and then tell every
 * page that that is the newest date there is, which is the failure this
 * exists to stop. Null means refuse - the caller says so and writes nothing.
 */
function resolve_asof_date($requested = null, $exchange = null) {

	$exchange = ($exchange === null) ? session_exchange() : $exchange;

	if ($requested === null || trim((string) $requested) === '')
		return last_trading_date($exchange);

	$requested = trim((string) $requested);

	/* A real date in the one format, not "yesterday", not 15/11/2026, and not
	   2026-02-30 - which DateTime would silently roll into March. */
	$parsed = DateTime::createFromFormat('Y-m-d', $requested);
	if ($parsed === false || $parsed->format('Y-m-d') !== $requested)
		return null;

	$rows = query("select count(*) n from historical_prices where exchange=? and date=?",
	              $exchange, $requested);

	return ((int) $rows[0]['n'] > 0) ? $requested : null;
}

/**
 * True when this symbol is an investment fund.
 *
 * Two sources, because neither is complete on its own. stock_symbols.is_fund
 * is FMP's Profile.isFund, written by the fundamentals loader, and it flags
 * 92 of the 350 - including FGT, VEIL, VOF and SEQI, which file under
 * "Equity Investments", "General Financial" and "Financial Services" and are
 * in no fund sector. fund_sectors is the editable side: a sector can be added
 * here without waiting for a provider to change its mind.
 */
function is_fund($symbol) {

	if (!fund_config_ready())
		return false;

	$rows = query("select 1 from stock_symbols ss
	                where ss.symbol=?
	                  and (ss.is_fund='Y'
	                       or exists (select 1 from fund_sectors fs
	                                   where fs.sector = ss.sector))", $symbol);

	return count($rows) > 0;
}


// function get_historical_prices($start_date,$end_date)

//Calculate indicator Stats
//function indicator_stats($asOfDate,$name)

///Calculate 3mnth Momentum
// function calc_momentum_3mnth($asOfDate)

//Calculate 6mnth Momentum
//function calc_momentum_6mnth($asOfDate)

//Calculate 12mnth Momentum
//function calc_momentum_12mnth($asOfDate){

//Calculate Price Momentum
//function calc_momentum($asOfDate,$mnth){

//Ten Day Moving Average
//	function tendayavg ($asOfDate)
	
//Thirty Day Moving Average
//function thirtydayavg ($asOfDate)

//Hundred Day Moving Average
//function hndrddayavg ($asOfDate)

// Calculate Earnings Growth forecast for next financial year 
//function calc_earnings_growth($asofdate)

//Get Share Statistics from Yahoo for the given Yahoo code to be assigned to the indicator.
//function get_share_statistics($asofdate,$indicator,$code)

//function symbol_exists($symbol, $list)

//function get_momentum_screen($threeMnthList,$sixMnthList,$twelveMnthList)

//function get_trend_screen($list)

//function get_momentum_screen($threeMnthList,$sixMnthList,$twelveMnthList)

//function get_earnings_growth($list)

//Get list of all indicators
//function get_indicators()

//get indicators to calculate statistics 
//function get_stats_indicators()
	
/* Returns a stock by symbol (case-insensitively) else false if not found.*/
//function share_lookup($symbol)

//function get_historical_prices($start_date,$end_date)

//Get News articles for the share symbol
//function get_articles($symbol)

//update cash balance
// function update_cash($amount) 

//Convert values containing B and k to actual number value
//function convert_value($value)

//get value for symbol, item and date from financial statement
//function get_val_from_fin_stmt($symbol, $item, $date) 

//get indicator value from statistics for a given date
//function get_indicator_value($symbol, $indicator,$date)

// Get_Share_Name
//function get_share_name ($symbol)

// Get Share Price
//function get_share_price($symbol, $date)

//Calculate Shareholder Yield
//function calc_shareholder_yield($date,$symbol)

//Get Sector Companies
//function get_sector_companies($sector)


//Get Piotroski Fscore

//Get Altman Z-score

//Get Active Companies
//function get_active_stocks($exchange) 

//Get Key Ratios
function get_key_ratios($symbol,$exchange) {
    $api_key = "H8OQV5MJTZ4UEFLU"; // Replace with your Alpha Vantage API key
    $url = "https://www.alphavantage.co/query?function=KEYS&symbol=" . $symbol . $exchange . "&apikey=" . $api_key;

	print($url);
	 
    // Send a GET request to the Alpha Vantage API
    $response = file_get_contents($url);
    
    // Convert the JSON response to an associative array
    $data = json_decode($response, true);

	print ($data);
    
    // Extract the key ratios from the data array
   // $key_ratios = $data["key_ratios"];
    $key_ratios=array();
    return $key_ratios;
}


//no longer used as this was using yahoo api- replaced by get_historical_prices using google api
 function OLD_get_key_ratios($exchange, $symbol){
        require_once("constants.php");
		//initialize array
		$keys=array();
		$values=array();

	 //Get symbols
       // $symbols = query("select symbol from stock_symbols where enabled='Y' and symbol='BKG.L' order by symbol");

        //for ($i=0;$i<count($symbols);$i++ )
        //{
       //         $symbol=$symbols[$i]['symbol'] ;
                //write_log("get_historical_prices", "Get historical prices for symbol " . $symbol . "<br/>");

       		//explode dates
	//	list($start_day,$start_month,$start_year) = explode('-',$start_date);
	//	$start_month=$start_month-1;
	//	list($end_day,$end_month,$end_year) = explode('-',$end_date);
	//	$end_month=$end_month-1;
		
		//write_log("get_historical_prices","start_day=".$start_day.", start_month=".$start_month.", start_year=".$start_year);
      //  //write_log("get_historical_prices","end_day=".$end_day.", end_month=".$end_month.", end_year=".$end_year);
		
		// open connection to Morning Star
		$url="http://financials.morningstar.com/ajax/exportKR2CSV.html?t=$exchange:$symbol";
		write_log("get_key_ratios", "URL: " . $url);
		
        	$handle = @fopen($url,"r");


        	if ($handle === false){
            		// trigger (big, orange) error
            		write_log("get_key_ratios", "Could not connect to Morningstar for symbol " . $symbol);
          
        	}
        	else{
        		// download first line of CSV file
        		$data = fgetcsv($handle);
        		if ($data === false ){
            		write_log("get_key_ratios", "No data for symbol " . $symbol . "<br>");
        		}
        		else{  
           			
           			while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
              				array_push($keys, $data[0] );
							array_push($values, $data[11] );
           			}    
        		}

	 		// close connection to Morninstar
        		fclose($handle);

  
      		}
			
		//$share_info = array();
	//	$len = count($keys);
		//for($i=1;$i<$len; $i++){
    		$share_info = array_combine($keys, $values);
		//}
		return $share_info;
	
 }
 
/**
 * Normalise either provider's price series into a flat list of day records.
 *
 * EODHD returns a flat list. FMP's stable API returns a flat list too, but its
 * v3 predecessor nested the series under "historical" and some endpoints still
 * wrap it, so both shapes are accepted. Returns null when the body is not a
 * series at all, which the caller treats as a failed request rather than as a
 * symbol with no prices - the two need different counters.
 *
 * Order is not normalised: the caller inserts each row by date and does not
 * depend on the sequence. EODHD is asked for ascending, FMP answers newest
 * first.
 */
function provider_prices_to_rows($decoded) {

	if (!is_array($decoded)) {
		return null;
	}
	//An error body is an object carrying a message, not a series.
	if (isset($decoded['Error Message']) || isset($decoded['error'])) {
		return null;
	}
	//Nested shape: {"symbol":"VOD.L","historical":[...]}
	if (isset($decoded['historical']) && is_array($decoded['historical'])) {
		return $decoded['historical'];
	}
	//Flat list, which is what both providers send today. An empty list is a
	//valid answer meaning the provider has nothing for this symbol.
	return $decoded;
}

/**
 * Fetch one symbol's prices from the provider and store them.
 *
 * Extracted from get_historical_prices() so the index loader below can use
 * exactly the same request, parsing and idempotent write rather than a second
 * copy of them that drifts.
 *
 * Returns 'loaded', 'empty' or 'failed'.
 */
function store_provider_prices($symbol,$exchange,$start_date,$end_date){

	//stock_symbols holds the bare code; each provider wants its own
	//suffix (EODHD CODE.LSE, FMP CODE.L). An index keeps its own code.
	$code=provider_symbol($symbol);

	if (data_provider() === 'fmp') {
		$url=fmp_base_url()."/historical-price-eod/full?symbol=".rawurlencode($code)
		    ."&from=".rawurlencode($start_date)
		    ."&to=".rawurlencode($end_date)
		    ."&apikey=".rawurlencode(fmp_api_key());
	} else {
		$url=eodhd_base_url()."/eod/".rawurlencode($code)
		    ."?from=".rawurlencode($start_date)
		    ."&to=".rawurlencode($end_date)
		    ."&period=d&order=a&fmt=json"
		    ."&api_token=".rawurlencode(eodhd_api_key());
	}

	//Never log the URL: it carries the API key.
	debug_log("get_historical_prices", "fetch ".$code." ".$start_date." to ".$end_date);

	$body=@file_get_contents($url);
	if ($body===false){
		write_log("get_historical_prices","request failed for ".$code);
		return 'failed';
	}

	$prices=provider_prices_to_rows(json_decode($body,true));
	if ($prices===null){
		write_log("get_historical_prices","unreadable response for ".$code);
		return 'failed';
	}
	if (count($prices)===0){
		debug_log("get_historical_prices","no prices for ".$code);
		return 'empty';
	}

	foreach ($prices as $line){

		if (!isset($line['date'])){
			continue;
		}

		//Prefer the adjusted close, which is what the momentum
		//calculations assume; fall back to the close if the plan does
		//not return it.
		//FMP's stable series spells it adjClose and omits it on some
		//plans, hence all three.
		$price=isset($line['adjusted_close']) ? $line['adjusted_close']
		      : (isset($line['adjClose']) ? $line['adjClose']
		      : (isset($line['close']) ? $line['close'] : null));
		if ($price===null||$price===''){
			continue;
		}

		$date=substr($line['date'],0,10);

		//Replace rather than append so a re-run for the same range is
		//idempotent.
		query("delete from historical_prices where symbol=? and exchange=? and date=?",$symbol,$exchange,$date);
		query("insert into historical_prices(symbol,exchange,date,price) values (?,?,?,?)",$symbol,$exchange,$date,$price);
	}

	return 'loaded';
}

/**
 * The index codes the market outlook reads.
 *
 * ^FTSE (FTSE 100) works on the current FMP plan. ^FTMC (FTSE 250) does not,
 * so the market panel speaks for large caps and says so rather than quietly
 * reporting a narrower index as "the market".
 *
 * These are deliberately not rows in stock_symbols. Everything in that table
 * is part of the tradeable universe: the screens rank it, the breadth figures
 * count it and the search box offers it. An index is none of those things.
 */
function index_symbols(){
	return ['^FTSE'];
}

/**
 * Load index prices into historical_prices, beside the shares.
 */
function get_index_prices($start_date,$end_date){

	$exchange=session_exchange();

	if (provider_api_key()===''){
		write_log("get_index_prices","no API key for the ".data_provider()." provider, cannot fetch index prices");
		return;
	}

	$loaded=0; $failed=0;
	foreach (index_symbols() as $symbol){
		if (store_provider_prices($symbol,$exchange,$start_date,$end_date)==='loaded'){ $loaded++; }
		else { $failed++; }
	}

	write_log("get_index_prices",
		strtoupper(data_provider())." ".$start_date." to ".$end_date.": "
		.$loaded." indices loaded, ".$failed." failed");
}

 function get_historical_prices($start_date,$end_date){

	   write_log("get_historical_prices", "Start Date= " . $start_date . " End Date=".$end_date);

        require_once("constants.php");

	    //Set exchange. This is the MIC the application keys on (XLON) and is
	    //what gets written to historical_prices; EODHD addresses the same
	    //market as CODE.LSE, so the request suffix is EODHD_EXCHANGE.
	    $exchange=session_exchange();

	    if (provider_api_key()===''){
	    	write_log("get_historical_prices",
	    	          "no API key for the ".data_provider()." provider, cannot fetch prices");
	    	return;
	    }

	    //Get symbols
        $symbols = query("select symbol from stock_symbols where enabled='Y' and exchange=? ",$exchange);

		write_log("get_historical_prices", "Number of symbols = " .count($symbols));

		$loaded=0; $empty=0; $failed=0;

		//EODHD's end of day endpoint is one symbol per request, so there is no
		//batching to do. The previous provider allowed 100 symbols per call but
		//the batching condition used ?? instead of ||, so it never accumulated
		//and issued one request per symbol anyway.
		foreach ($symbols as $row){

			switch (store_provider_prices($row['symbol'],$exchange,$start_date,$end_date)){
				case 'loaded': $loaded++; break;
				case 'empty':  $empty++;  break;
				default:       $failed++; break;
			}
		}

		write_log("get_historical_prices",
			//The provider is a setting, so naming one here reports the wrong
			//thing as soon as it is changed: an FMP run logged "EODHD".
			strtoupper(data_provider())." ".$start_date." to ".$end_date.": ".$loaded." symbols loaded, "
			.$empty." without prices, ".$failed." failed");
 	}
 
 /**
 * Convert time into decimal time.
 *
 * @param string $time The time to convert
 *
 * @return integer The time as a decimal value.
 */
function time_to_decimal($time) {
    $timeArr = explode(':', $time);
    $decTime = ($timeArr[0]*60) + ($timeArr[1]) + ($timeArr[2]/60);
 
    return $decTime;
}
 
    //Calculate indicator Stats
	function indicator_stats($asOfDate,$name,$symbol){
		
	//	write_log("indicator_stats","symbol=".$symbol);
		
		$indicator=query("select * from screen_indicators where name=? and enabled='Y' order by order_number",$name);
		if(count($indicator)>0) {
					
			//	write_log("indicator_stats","indicator=".$indicator[0]["name"]);
				
					
		
				//$from_time = strtotime('now');
	
				if ($indicator[0]["screen_function"]!=''){
					
		   		//Get symbols
					if (isset($symbol))
						$rows = query("select symbol from stock_symbols where enabled='Y' and symbol=? and exchange=?",$symbol,session_exchange());
					else
						$rows = query("select symbol from stock_symbols where enabled='Y' and exchange=? order by symbol",session_exchange());
						
					foreach($rows as $row) {
						
						$symbol=$row['symbol'];

						$function=$indicator[0]["screen_function"] ;
					//	write_log("indicator_stats","function=".$function);
						call_user_func($function,$asOfDate,$symbol );
					}
				}
				/*elseif ($indicator[0]["yahoo_code"]!=''){
					get_share_statistics($asOfDate,$name,$indicator[0]["yahoo_code"],$symbol);
				}*/
			//	$to_time = strtotime('now');
			//	write_log("indicator_stats","Time taken: ".round(abs($to_time - $from_time),2). " seconds");
			
			}	
	}
	
	//Calculate 3mnth Momentum
    function calc_momentum_3mnth($asOfDate,$symbol){
		debug_log("calc_momentum_3mnth","Call calc_momentum");
		calc_momentum($asOfDate,3,$symbol);
	}
	
   //Calculate 6mnth Momentum
    function calc_momentum_6mnth($asOfDate,$symbol ){
		 calc_momentum($asOfDate,6,$symbol );
	}

   //Calculate 12mnth Momentum
    function calc_momentum_12mnth($asOfDate,$symbol ){
		calc_momentum($asOfDate,12,$symbol );
	}

     //Calculate Price Momentum
	function calc_momentum($asOfDate,$mnth,$symbol ){

		debug_log("calc_momentum", "As of Date= $asOfDate");

		 //Get symbols
			if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=? and exchange=?",$symbol,session_exchange());
			else
				$symbols = query("select symbol from stock_symbols where enabled='Y' and exchange=? order by symbol",session_exchange());

       		 for ($i=0;$i<count($symbols);$i++ ){
       		 	
       		 	//initialise
       		 	$max_date_prices=null;
					$min_date_prices=null;
	 				$max_date_price=null;
					$min_date_price=null;
					
               $symbol=$symbols[$i]['symbol'];

			   debug_log("calc_momentum", "Symbol= $symbol");
					
					//write_log("calc_momentum", "Symbol: $symbol, As of Date: $asOfDate , Months: $mnth");
					//write_log("calc_momentum", "SELECT DATE_SUB($asOfDate, INTERVAL $mnth MONTH) min_date, date max_date FROM historical_prices WHERE symbol=$symbol and date =$asOfDate");
			
		 	//get min and max date
    			//$dates = query("SELECT min( date ) min_date, max( date ) max_date FROM historical_prices WHERE symbol=? and date >= DATE_SUB(STR_TO_DATE(?, '%d-%m-%Y'), INTERVAL ? MONTH)",$symbol,$asOfDate,$mnth);
				//$dates = query("SELECT min( date ) min_date, max( date ) max_date FROM historical_prices WHERE symbol=? and date >= DATE_SUB(?, INTERVAL ? MONTH) and date <= ?" ,$symbol,$asOfDate,$mnth,$asOfDate);
				$date= query("select max(hp1.date) max_date from historical_prices hp1 where hp1.symbol=? and hp1.exchange=? and hp1.date<=?",$symbol,session_exchange(),$asOfDate);
				
				$sql="SELECT DATE_SUB(?, INTERVAL ? MONTH) min_date, date max_date FROM historical_prices hp WHERE symbol=? and hp.exchange=? and date =?";

				debug_log("calc_momentum", "Query= $sql");

				$dates = query($sql,$asOfDate,$mnth,$symbol,session_exchange(),$date[0]['max_date']);    	

    			if (is_array($dates)&&count($dates)>0&&isset($dates[0]['min_date'])&&isset($dates[0]['max_date'])){
					
					$min_date_prices = [];
					$max_date_prices = [];

      				$min_date = $dates[0]['min_date'];
      				$max_date = $dates[0]['max_date'];
      				
					debug_log("calc_momentum", " Min date: " . $min_date . "</br>");
				    debug_log("calc_momentum", " Max date: " . $max_date . "</br>");

    			
			
					//get price at min and max date
					while((date_create_from_format('Y-m-d', $min_date)< date_create_from_format('Y-m-d', $max_date))&&((is_array($max_date_prices)&&count($max_date_prices)==0)||(is_array($min_date_prices)&&count($min_date_prices)==0))) {
						
    				debug_log("calc_momentum"," Count max date prices: " .count($max_date_prices));
    				
					debug_log("calc_momentum"," Count Min date prices: " .count($min_date_prices));
    				
    				
    				debug_log("calc_momentum"," Max Date : " . $max_date);
    			
    				$max_date_prices=query("select price from historical_prices where symbol=? and exchange=? and date=?",$symbol,session_exchange(),$max_date);
					
    				foreach ($max_date_prices as $price){
      					$max_date_price=$price['price'];
				  		debug_log("calc_momentum"," Max Date Price: " . $max_date_price . "</br>");
    				}
    				
					//$min_date=date_format(date_create_from_format('Y-m-d', $max_date), 'Y-m-d');  
					
					

					$min_date_prices=query("select price from historical_prices where symbol=? and exchange=? and date=?",$symbol,session_exchange(),$min_date);
					
    				foreach ($min_date_prices as $price){
      					$min_date_price=$price['price'];
      				 	debug_log("calc_momentum", " Min Price: " . $min_date_price . "</br>");
    				}
    			
    				if ((count($max_date_prices)==0)||(count($min_date_prices)==0)){
    					//$max_date=date_create_from_format('Y-m-d', $max_date);  
    					//$max_date->sub(new DateInterval('P1D'));
						//$max_date=date_format($max_date, 'Y-m-d');
				
						//Set date interval
						$min_date=date_create_from_format('Y-m-d', $min_date);  
						$interval="P1D";				
	    				$min_date->add(new DateInterval($interval));
	    				$min_date=date_format($min_date, 'Y-m-d');
						debug_log("calc_momentum"," Min Date : " . $min_date);
					
					}
					
    			}
    			
    			if (isset($max_date_price)&&isset($min_date_price)){
    				
    			//calculate perc change
    			if ($min_date_price!=0){
				$perc_change=($max_date_price -  $min_date_price)/$min_date_price*100;
			}
			else {
				$perc_change=0;
			}
    			 debug_log("calc_momentum", "Perc change: " . $perc_change . "</br>");

    			//update price momentum
				 //write_log("calc_momentum", "update price momentum");
			    //write_log("calc_momentum", "symbol=$symbol, date=$max_date, indicator=$mnth");
			if ($mnth==3){
    				//$rows=query("select 3mnth from price_momentum where symbol=?",$symbol);
					$rows=query("select 1 from statistics where symbol=? and indicator='3mnth' and date=? and exchange=?",$symbol,$asOfDate,session_exchange());
				if (count($rows)==0){
					//query("insert into price_momentum (symbol, 3mnth) values (?,?)",$symbol,$perc_change);
					query("insert into statistics (symbol, indicator, value,date,exchange) values (?,?,?,?,?)",$symbol,"3mnth",$perc_change,$asOfDate,session_exchange());
					
				}
				else {
					//query("update price_momentum set 3mnth=? where symbol=?",$perc_change, $symbol);
					query("update statistics set value=? where symbol=? and indicator='3mnth' and date=? and exchange=? ",$perc_change, $symbol,$asOfDate,session_exchange());
				}
			}
			elseif ($mnth==6){
				//$rows=query("select 6mnth from price_momentum where symbol=?",$symbol);
				  $rows=query("select 1 from statistics where symbol=? and indicator='6mnth' and date=? and exchange=?",$symbol,$asOfDate,session_exchange());
				  if (count($rows)==0){
                                        //query("insert into price_momentum (symbol, 6mnth) values (?,?)",$symbol,$perc_change);
										query("insert into statistics (symbol, indicator, value, date, exchange) values (?,?,?,?,?)",$symbol,"6mnth",$perc_change,$asOfDate,session_exchange());
                                }
                                else {
                                        //query("update price_momentum set 6mnth=? where symbol=?",$perc_change, $symbol);
										query("update statistics set value=? where symbol=? and indicator='6mnth' and date=? and exchange=?",$perc_change, $symbol,$asOfDate,session_exchange());
                                }
			}
			elseif ($mnth==12){
                                //$rows=query("select 12mnth from price_momentum where symbol=?",$symbol);
								$rows=query("select 1 from statistics where symbol=? and indicator='12mnth' and date=? and exchange=?",$symbol,$asOfDate,session_exchange());
								//write_log("calc_momentum", "No of rows=".count($rows));
                                if (count($rows)==0){
                                       // query("insert into price_momentum (symbol, 12mnth) values (?,?)",$symbol,$perc_change);
									   			//write_log("calc_momentum", "insert into statistics (symbol, indicator, value,date) values ($symbol,12mnth,$perc_change,$asOfDate)");
									   query("insert into statistics (symbol, indicator, value,date,exchange) values (?,?,?,?,?)",$symbol,"12mnth",$perc_change,$asOfDate,session_exchange());
                                }
                                else {
                                        //query("update price_momentum set 12mnth=? where symbol=?",$perc_change, $symbol);
										query("update statistics set value=? where symbol=? and indicator='12mnth' and date=? and exchange=?",$perc_change, $symbol,$asOfDate,session_exchange());
                                }
                        }
	 		}
	 		
	 	}
	 	
	 }
	}
	
	//Ten Day Moving Average
	function tendayavg ($asOfDate,$symbol){
		calc_moving_avg("tendayavg",10,$asOfDate,$symbol);
	}
	
	//Thirty Day Moving Average
	function thirtydayavg ($asOfDate,$symbol){
		calc_moving_avg("thirtydayavg",30,$asOfDate,$symbol);
	}
	//Hundred Day Moving Average
	function hndrddayavg ($asOfDate,$symbol){
		calc_moving_avg("hndrddayavg",100,$asOfDate,$symbol);
	}

	/* ------------------------------------------------------------------
	   Trend following indicators.

	   These three are compared against each other rather than ranked, so
	   screen_indicators.calc_rank is 'N' for all of them: a percentile on a
	   share price means nothing. See sql/trend_screens.sql.

	   They are registered with a screen_function, which is what makes
	   get_statistics.php compute them: get_stats_indicators() returns every
	   enabled indicator, indicator_stats() dispatches through the function
	   name, and the as of date is the one get_statistics.php then records as
	   the get_statistics_asof job - the same date build_screen() reads. So no
	   job needed changing; get_momentum_statistics.php works on monthly as of
	   dates and would have been the wrong place for a daily signal.
	   ------------------------------------------------------------------ */

	//Latest close on or before the as of date
	function close_price ($asOfDate,$symbol){
		calc_close_price("close_price",$asOfDate,$symbol);
	}

	//50 day simple moving average
	function ma50 ($asOfDate,$symbol){
		calc_moving_avg("ma50",50,$asOfDate,$symbol);
	}

	//200 day simple moving average
	function ma200 ($asOfDate,$symbol){
		calc_moving_avg("ma200",200,$asOfDate,$symbol);
	}

	/**
	 * Write the latest close on or before $asOfDate to statistics.
	 *
	 * A share with no price history at all gets no row rather than a zero: a
	 * zero close would sit below every moving average and read as a sell
	 * signal, which is a statement about the share rather than the absence of
	 * data. Any stale value is removed in that case, so a symbol that loses
	 * its history does not keep yesterday's answer.
	 */
	function calc_close_price($indicator,$asOfDate,$symbol){

		if (isset($symbol))
			$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=? and exchange=?",$symbol,session_exchange());
		else
			$symbols = query("select symbol from stock_symbols where enabled='Y' and exchange=? order by symbol",session_exchange());

		foreach ($symbols as $row){

			$symbol=$row['symbol'];

			$prices=query("select price from historical_prices where symbol=? and exchange=? and date<=? ORDER BY date DESC LIMIT 1",
			              $symbol,session_exchange(),$asOfDate);

			if (count($prices)==0){
				clear_statistic($symbol,$indicator,$asOfDate);
				continue;
			}

			upsert_statistic($symbol,$indicator,$asOfDate,$prices[0]['price']);
		}
	}

	/**
	 * Remove an indicator value for one symbol and date.
	 *
	 * Used when a run decides there is no honest value to write. Without this
	 * a re-run is not idempotent in the direction that matters: the run that
	 * refuses to write leaves the previous run's answer in place, so a bad
	 * value outlives the data that produced it.
	 */
	function clear_statistic($symbol,$indicator,$asOfDate){
		query("delete from statistics where symbol=? and indicator=? and date=? and exchange=?",
		      $symbol,$indicator,$asOfDate,session_exchange());
	}

	/**
	 * Insert or update one indicator value, the same semantics as
	 * insert_statistic() in python/share_functions.py: one row per symbol,
	 * indicator, date and exchange, whatever order the jobs run in.
	 *
	 * statistics has a non-unique index on those four columns rather than a
	 * unique constraint, so "insert on duplicate key" is not available and the
	 * check has to be explicit.
	 */
	function upsert_statistic($symbol,$indicator,$asOfDate,$value){
		$rows=query("select symbol from statistics where symbol=? and indicator=? and date=? and exchange=?",
		            $symbol,$indicator,$asOfDate,session_exchange());

		if (count($rows)==0)
			query("insert into statistics (symbol, indicator, value, date, exchange) values (?,?,?,?,?)",
			      $symbol,$indicator,$value,$asOfDate,session_exchange());
		else
			query("update statistics set value=? where symbol=? and indicator=? and date=? and exchange=?",
			      $value,$symbol,$indicator,$asOfDate,session_exchange());
	}

	//Calculate Moving Average
	function calc_moving_avg($indicator,$numDays,$asOfDate,$symbol){
		  //Get symbols
		  if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=? and exchange=?",$symbol,session_exchange());
			else
                $symbols = query("select symbol from stock_symbols where enabled='Y' and exchange=? order by symbol",session_exchange());

		foreach ($symbols as $row){

			$symbol=$row['symbol'];

			//Get last numDays prices
			$prices=query("select price from historical_prices where symbol=? and exchange=? and date<=? ORDER BY date DESC LIMIT ?",
			              $symbol,session_exchange(),$asOfDate,$numDays);

			/* A full window or nothing.
			
			   This used to divide the sum by $numDays however many prices came
			   back, so a share with less history than the window got an
			   average of its own prices scaled down by the shortfall: 138 days
			   of prices divided by 200 is an answer 31% too low. Against a
			   trend screen that is not a small error in the right direction,
			   it is the wrong answer - the average sits far below the price, so
			   a newly listed share passes "close above its 200 day average"
			   on arithmetic rather than on any trend. One of the 350 symbols
			   on the live exchange is in exactly that position for ma200.
			
			   There is no partial-window average worth writing, because the
			   indicator's meaning depends on the window being the stated
			   length. So write nothing, and clear any value an earlier run
			   left, which is also what makes the job safe to re-run. */
			if (count($prices)<$numDays){
				clear_statistic($symbol,$indicator,$asOfDate);
				continue;
			}

			$avg=0;
			foreach ($prices as $price){
				$avg=$avg+$price['price'];
			}
			$avg=$avg/$numDays;

			upsert_statistic($symbol,$indicator,$asOfDate,$avg);
		}
	}

	/* 
	  Calculate Earnings Growth forecast for next financial year  
	*/
	
	function calc_earnings_growth($asofdate,$symbol){
		 //Get symbols
		 //write_log("calc_earnings_growth","symbol=$symbol");
		 if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=? and exchange=?",$symbol,session_exchange());
			else
                $symbols = query("select symbol from stock_symbols where enabled='Y' and exchange=? order by symbol",session_exchange());
		 //write_log("calc_earnings_growth","No of symbols=".count($symbols));
         for ($i=0;$i<count($symbols);$i++){
			//write_log("calc_earnings_growth","symbol=".$symbols[$i]['symbol']);
			//write_log("calc_earnings_growth","asofdate=$asofdate");
			
			$statistics=query("select indicator,value from statistics s1 where date=(select max(date) from statistics s2 where s2.indicator=s1.indicator and s2.symbol=s1.symbol and date<=?) and indicator in ('last_year_eps', 'eps_est_nxt_year','eps_est_current_year','price_eps_ratio_curr_year') and symbol=?",$asofdate,$symbols[$i]['symbol']);
			if (count($statistics)>0){
				foreach($statistics as $statistic){
					if ($statistic['indicator']=='last_eps')
						$earnings_share=$statistic['value'];
					elseif ($statistic['indicator']=='eps_est_nxt_year')
						$eps_est_nxt_year=$statistic['value'];
					elseif ($statistic['indicator']=='eps_est_current_year')
						$eps_est_current_year=$statistic['value'];
					elseif ($statistic['indicator']=='price_eps_ratio_curr_year')
						$pe=$statistic['value'];
						
					//write_log("calc_earnings_growth","statistic=".$statistic['indicator']);
				
				}		
			}
			//calculate percentage growth forecast
			if (isset($eps_est_nxt_year)&&isset($eps_est_current_year)&&$eps_est_current_year!=0){
				$earnings_growth=round(($eps_est_nxt_year - $eps_est_current_year)/$eps_est_current_year*100,1);
				//write_log("calc_earnings_growth","earnings_growth=".$earnings_growth);
				//check if symbol for indicator exists in statistics
				$rows=query("select symbol from statistics s1 where symbol=? and indicator=? and date=?",$symbols[$i]['symbol'],'earnings_growth',$asofdate);
				//insert record in statistics
				if (count($rows)==0){
					//query("insert into price_momentum (symbol, peg,pe,earnings_growth) values (?,?,?,?)",$symbol,$peg,$pe,$earnings_growth);
					query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$symbols[$i]['symbol'],'earnings_growth',$earnings_growth,$asofdate);
				}
				//update record in statistics
				else {
					query("update statistics set value=? where symbol=? and indicator=? and date=?",$earnings_growth,$symbols[$i]['symbol'],'earnings_growth',$asofdate);
				}
				//Calculate PEG based on Earnings Growth Current Year
				//write_log("calc_earnings_growth","Calculate PEG based on Earnings Growth Current Year");
				
				if ((isset($earnings_growth) && $earnings_growth>0) && (isset($pe) && $pe > 0))
					$peg = round($pe/$earnings_growth,1);
				else 
					$peg = 0;
					
				//write_log("calc_earnings_growth","PEG Current Year=$peg");
					
				//check if symbol for indicator exists in statistics
				//write_log("calc_earnings_growth","check if symbol for indicator exists in statistics");
				$rows=query("select symbol from statistics s1 where symbol=? and indicator=? and date=?",$symbols[$i]['symbol'],'peg_current_year',$asofdate);
				
				//insert record in statistics
				//write_log("calc_earnings_growth","insert record in statistics");
				
				if (count($rows)==0){
					query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$symbols[$i]['symbol'],'peg_current_year',$peg,$asofdate);
				}
					
				else {
					//update record in statistics
					//write_log("calc_earnings_growth","update record in statistics");
					query("update statistics set value=? where symbol=? and indicator=? and date=?",$peg,$symbols[$i]['symbol'],'peg_current_year',$asofdate);
				}
			}
		 }
	
	}

	//Calculate Price Book ratio
	function calc_price_book_ratio($asofdate,$symbol){
			
		$total_assets = get_val_from_fin_stmt($symbol, 'total_assets', $asofdate) ;
		
	//	write_log("calc_price_book_ratio","total_assets = $total_assets");
		 
		$total_liabilities = get_val_from_fin_stmt($symbol, 'total_liabilities', $asofdate) ;
		
	//	write_log("calc_price_book_ratio","total_liabilities = $total_liabilities");
		
		$shares = get_indicator_value($symbol, 'shares_outstanding', $asofdate);
		
	//	write_log("calc_price_book_ratio","shares = $shares");
		
		//Calulate book value in cents
		$book_value = ($total_assets - $total_liabilities)*100/$shares;
		
	//	write_log("calc_price_book_ratio","book value per share = $book_value");
		
		//Get share price
		$price=get_share_price($symbol,$asofdate);
		
	//	write_log("calc_price_book_ratio","share price = $price");
		
		//Calculate Price to Book value
		$price_book_ratio=$price/$book_value;
		
	//	write_log("calc_price_book_ratio","price to book value = $price_book_ratio");
		
		//check if symbol for indicator exists in statistics
		$rows=query("select symbol from statistics s1 where symbol=? and indicator=? and date=?",$symbol,'price_book_ratio',$asofdate);
		
		//insert record in statistics
		//write_log("calc_earnings_growth","insert record in statistics");
		
		if (count($rows)==0){
			query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$symbol,'price_book_ratio',$price_book_ratio,$asofdate);
		}
			
		else {
			//update record in statistics
			//write_log("calc_earnings_growth","update record in statistics");
			query("update statistics set value=? where symbol=? and indicator=? and date=?",$price_book_ratio,$symbol,'price_book_ratio',$asofdate);
		}
	}

	//get 5 year forecast growth average using PEG (5 years) ratio for each share and current PE ration
	
	function calc_earnings_growth_5yr($asofdate,$symbol){
		 //Get symbols
		 
		// write_log("calc_earnings_growth_5yr","symbol=$symbol");
		 
		 if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=? and exchange=?",$symbol,session_exchange());
			else
                $symbols = query("select symbol from stock_symbols where enabled='Y' and exchange=? order by symbol",session_exchange());
		$i=0;
		
		// write_log("calc_earnings_growth_5yr","No of symbols=".count($symbols));
         
         while ($i<count($symbols)){
			$list_count=0;
			$symbol_list="LSE.L";
			while ($list_count<=100 && $i<count($symbols)){
                        	$symbol_list=$symbol_list . "+" . $symbols[$i]['symbol'];
				$list_count=$list_count+1;
				$i=$i + 1;
			}
        		// open connection to Yahoo
			$url="http://finance.yahoo.com/d/quotes.csv?s=$symbol_list&f=sr5r";
        		$handle = @fopen($url,'r');
		        if ($handle === false){
            			// trigger (big, orange) error
            			// write_log("calc_earnings_growth_5yr", "Could not connect to Yahoo! with url: " . $url . "<br>");
        		}
        		else {
        			// download first line of CSV file
        			$data = fgetcsv($handle);
       			 	if ($data === false || count($data) == 1){
            			//	write_log("calc_earnings_growth_5yr",  "No data for symbol list" . $symbol_list . "<br>");
				}
        			else {
           				$ratios=array();
           				while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
              					array_push($ratios, ["symbol" => $data[0],
                                    				     "peg" => $data[1],
                                                		     "pe" => $data[2]]);
					}
		                        //insert ratios into table
                		        foreach($ratios as $ratio){
                                		//calculate  Earnings Growth
                                		$symbol=$ratio['symbol'];
                                		$peg = $ratio['peg'];
                                		$pe = $ratio['pe'];
						
									//	write_log("calc_earnings_growth_5yr",  "peg= " . $peg . "<br>");
									//	write_log("calc_earnings_growth_5yr",  "pe= " . $pe . "<br>");
                        
						        		if ((isset($peg) && $peg>0) && (isset($pe) && $pe > 0)){
                                        		$earnings_growth=$pe/$peg;

											//	write_log("calc_earnings_growth_5yr",  "Got earnings growth for symbol ". $symbol. " = " . $earnings_growth . "<br>");
                                		}
                                		else {
                                        		$earnings_growth=0;
                                		}
										
										$statistics=[["symbol"=>$symbol,"indicator"=>"peg","value"=>$peg],["symbol"=>$symbol,"indicator"=>"pe","value"=>$pe],["symbol"=>$symbol,"indicator"=>"earnings_growth_5yr","value"=>$earnings_growth]];
										
										foreach($statistics as $statistic){
											//check if symbol for indicator exists in statistics
											//$rows=query("select symbol from price_momentum where symbol=?",$symbol);
											$rows=query("select symbol from statistics where symbol=? and indicator=? and date=?",$statistic["symbol"],$statistic["indicator"],$asofdate);
											//insert record in statistics
											if (count($rows)==0){
                                        		//query("insert into price_momentum (symbol, peg,pe,earnings_growth) values (?,?,?,?)",$symbol,$peg,$pe,$earnings_growth);
												query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$statistic["symbol"],$statistic["indicator"],$statistic["value"],$asofdate);
											}
											//update record in statistics
											else {
                                        		query("update statistics set value=? where symbol=? and indicator=? and date=?",$statistic["value"],$statistic["symbol"],$statistic["indicator"],$asofdate);
											}
										}
                        		}

				}
           		}

		}
	}
	//Calculate Dividend per Share
	function dividend_share($asofdate,$symbol){
		 //Get symbols
		 
		 //write_log("dividend_share","symbol=$symbol");

		 if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=? and exchange=?",$symbol,session_exchange());
			else
          $symbols = query("select symbol from stock_symbols where enabled='Y' and exchange=? order by symbol",session_exchange());
		 	//write_log("dividend_share","No of symbols=".count($symbols));
         for ($i=0;$i<count($symbols);$i++){
			//write_log("dividend_share","symbol=".$symbols[$i]['symbol']);
			//write_log("dividend_share","asofdate=$asofdate");
			
			$statistics=query("select indicator,value from statistics where date=? and indicator in ('div_yield','previous_close') and symbol=?",$asofdate,$symbols[$i]['symbol']);
			if (count($statistics)>0){
				foreach($statistics as $statistic){
					if ($statistic['indicator']=='div_yield')
						$div_yield=$statistic['value'];
					elseif ($statistic['indicator']=='previous_close')
						$previous_close=$statistic['value'];
						
					//write_log("dividend_share","statistic=".$statistic['indicator']);
				
				}		
			}
			//calculate Dividend per share
			
			//write_log("dividend_share","previous_close=".$previous_close);
			
			if (isset($previous_close)&&isset($div_yield)){
				
				//write_log("dividend_share","div_yield=".$div_yield);
				
				$dividend_share=round($previous_close*$div_yield/100,1);
				
				//write_log("dividend_share","dividend_share=".$dividend_share);
				
				//check if symbol for indicator exists in statistics
				$rows=query("select symbol from statistics where symbol=? and indicator=? and date=?",$symbols[$i]['symbol'],'dividend_share',$asofdate);
				//insert record in statistics
				if (count($rows)==0){
					query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$symbols[$i]['symbol'],'dividend_share',$dividend_share,$asofdate);
				}
				//update record in statistics
				else {
					query("update statistics set value=? where symbol=? and indicator=? and date=?",$dividend_share,$symbols[$i]['symbol'],'dividend_share',$asofdate);
				}
			}
			}
	}
	//Calculate Dividend Cover
	function dividend_cover($asofdate,$symbol){
		 //Get symbols
		 //write_log("dividend_cover","symbol=$symbol");
		 
		 if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=? and exchange=?",$symbol,session_exchange());
			else
                $symbols = query("select symbol from stock_symbols where enabled='Y' and exchange=? order by symbol",session_exchange());
		   ////write_log("dividend_cover","No of symbols=".count($symbols));
         
         for ($i=0;$i<count($symbols);$i++){
			//write_log("dividend_cover","symbol=".$symbols[$i]['symbol']);
			//write_log("dividend_cover","asofdate=$asofdate");
			
			$statistics=query("select indicator,value from statistics s1 where date=(select max(date) from statistics s2 where s2.symbol=s1.symbol and s2.indicator=s1.indicator and date<=?) and indicator in ('earnings_share','dividend_share') and symbol=?",$asofdate,$symbols[$i]['symbol']);
			if (count($statistics)>0){
				foreach($statistics as $statistic){
					if ($statistic['indicator']=='earnings_share')
						$earnings_share=$statistic['value'];
					elseif ($statistic['indicator']=='dividend_share')
						$dividend_share=$statistic['value'];
						
					//write_log("dividend_cover","statistic=".$statistic['indicator']);
				
				}		
			}
			//calculate Dividend Cover
			//write_log("dividend_cover","earnings_share=".$earnings_share);
			//write_log("dividend_cover","dividend_share=".$dividend_share);
			if (isset($earnings_share)&&isset($dividend_share)&&($dividend_share>0)){
				$dividend_cover=round($earnings_share/$dividend_share,1);
				//write_log("dividend_cover","dividend_cover=".$dividend_cover);
				//check if symbol for indicator exists in statistics
				$rows=query("select symbol from statistics s1 where symbol=? and indicator=? and date=?",$symbols[$i]['symbol'],'dividend_cover',$asofdate);
				//insert record in statistics
				if (count($rows)==0){
					query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$symbols[$i]['symbol'],'dividend_cover',$dividend_cover,$asofdate);
				}
				//update record in statistics
				else {
					query("update statistics set value=? where symbol=? and indicator=? and date=?",$dividend_cover,$symbols[$i]['symbol'],'dividend_cover',$asofdate);
				}
			}
			}
	}
	
	//Calculate Bearbull Dividend Yield to Cover 
	function dividend_yield_cover($asofdate,$symbol){
		 //Get symbols
		 //write_log("dividend_yield_cover","symbol=$symbol");
		 if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=? and exchange=?",$symbol,session_exchange());
			else
                $symbols = query("select symbol from stock_symbols where enabled='Y' and exchange=? order by symbol",session_exchange());
		 	//write_log("dividend_yield_cover","No of symbols=".count($symbols));
         for ($i=0;$i<count($symbols);$i++){
			//write_log("dividend_yield_cover","symbol=".$symbols[$i]['symbol']);
			//write_log("dividend_yield_cover","asofdate=$asofdate");
			
			$statistics=query("select indicator,value from statistics where date=? and indicator in ('dividend_cover','div_yield') and symbol=?",$asofdate,$symbols[$i]['symbol']);
			if (count($statistics)>0){
				foreach($statistics as $statistic){
					if ($statistic['indicator']=='div_yield')
						$div_yield=$statistic['value'];
					elseif ($statistic['indicator']=='dividend_cover')
						$dividend_cover=$statistic['value'];
						
					//write_log("dividend_yield_cover","statistic=".$statistic['indicator']);
				
				}		
			}
			//calculate Dividend Yield to Dividend Cover Ratio
			if (isset($div_yield)&&isset($dividend_cover)&&($dividend_cover>0)){
				$yield_cover=round($dividend_cover/$div_yield*10,1);
				//write_log("dividend_yield_cover","yield_cover=".$yield_cover);
				//check if symbol for indicator exists in statistics
				$rows=query("select symbol from statistics where symbol=? and indicator=? and date=?",$symbols[$i]['symbol'],'yield_cover',$asofdate);
				//insert record in statistics
				if (count($rows)==0){
					query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$symbols[$i]['symbol'],'yield_cover',$yield_cover,$asofdate);
				}
				//update record in statistics
				else {
					query("update statistics set value=? where symbol=? and indicator=? and date=?",$yield_cover,$symbols[$i]['symbol'],'yield_cover',$asofdate);
				}
			}
			}
	}


	//Get Share Statistics from Yahoo for the given Yahoo code to be assigned to the indicator.
	function get_share_statistics($asofdate,$indicator,$code, $symbol){
	
	//write_log("get_share_statistics","date=$asofdate");
		 //Get symbols
            if (isset($symbol))
				$symbols = query("select symbol from stock_symbols where enabled='Y' and symbol=? and exchange=?",$symbol,session_exchange());
			else
				$symbols = query("select symbol from stock_symbols where enabled='Y' and exchange=? order by symbol",session_exchange());
			
			//Create list of symbols			
			$i=0;
         while ($i<count($symbols)){
			$list_count=0;
			$symbol_list="LSE.L";
			while ($list_count<=50 && $i<count($symbols)){
                     	$symbol_list=$symbol_list . "+" . $symbols[$i]['symbol'];
						$list_count=$list_count+1;
						$i=$i + 1;
			}
			
        		// open connection to Yahoo
				$url="http://finance.yahoo.com/d/quotes.csv?s=$symbol_list&f=s".$code;
				//write_log("get_share_statistics","url=".$url);
        		$handle = @fopen($url,'r');
		        if ($handle === false){
            			// trigger (big, orange) error
            			 //write_log("get_share_statistics", "Could not connect to Yahoo! with url: " . $url . "<br>");
        		}
        		else {
        			// download first line of CSV file
        			$data = fgetcsv($handle);
       			 	if ($data === false || count($data) == 0){
            				//write_log("get_share_statistics",  "No data for symbol list" . $symbol_list . "<br>");
					}
        			else {
						//write_log("get_share_statistics","count data=".count($data));
						//write_log("get_share_statistics","symbol="  .$data[0]);
           				$statistics=array();
						//$j=1;
           				while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
              					array_push($statistics, ["symbol" => $data[0],
														"indicator" => $indicator ,
														"value"=> convert_value($data[1])]);
								//$j++;
						}
		                foreach($statistics as $statistic){
							//check if symbol for indicator exists in statistics
							$rows=query("select symbol from statistics where symbol=? and indicator=? and date=?",$statistic["symbol"],$statistic["indicator"],$asofdate);
							//insert record in statistics
							if (count($rows)==0){
                              		//query("insert into price_momentum (symbol, peg,pe,earnings_growth) values (?,?,?,?)",$symbol,$peg,$pe,$earnings_growth);
									query("insert into statistics (symbol, indicator, value,date) values (?,?,?,?)",$statistic["symbol"],$statistic["indicator"],$statistic["value"],$asofdate);
							}
							//update record in statistics
							else {
                              		query("update statistics set value=? where symbol=? and indicator=? and date=?",$statistic["value"],$statistic["symbol"],$statistic["indicator"],$asofdate);
							}
						}
               		}

				}
			}
	}
	function symbol_exists($symbol, $list){
		foreach($list as $item){
			if ($symbol==$item["symbol"])
				return true;
		}
	}

/*
	function get_momentum_screen($threeMnthList,$sixMnthList,$twelveMnthList){
		$list=array();
		foreach($threeMnthList as $threeMnth){
			$symbol=$threeMnth["symbol"];
			$symbol_exists = symbol_exists($symbol,$sixMnthList);
			if($symbol_exists){
				$symbol_exists = symbol_exists($symbol,$twelveMnthList);
				if ($symbol_exists){
					$momentum=query("select 3mnth,6mnth,12mnth from price_momentum where symbol=?",$symbol); 
					array_push($list,["symbol"=>$symbol,"threeMnth"=>$momentum[0]["3mnth"],"sixMnth"=>$momentum[0]["6mnth"],"twelveMnth"=>$momentum[0]["12mnth"]]);
					
				}
			}	
		}
		return $list;
	}	
*/
	function get_momentum_screen($threeMnthList,$sixMnthList,$twelveMnthList){
		$build_list=array();
		$threemnth=array();
		$sixmnth=array();
		$twelvemnth=array();
		$momentum_list=array();
		foreach($threeMnthList as $list)
			array_push($threemnth,["symbol"=>$list["symbol"]]);
		array_push($build_list,$threemnth);
		foreach($sixMnthList as $list)
                        array_push($sixmnth,["symbol"=>$list["symbol"]]);
//print_r($sixmnth);
		array_push($build_list,$sixmnth);
		foreach($twelveMnthList as $list)
                        array_push($twelvemnth,["symbol"=>$list["symbol"]]);
                array_push($build_list,$twelvemnth);
		//print_r($twelvemnth);
		$final_list= combine_criteria_lists($build_list);
		//print_r($final_list);
		foreach($final_list as $symbol){
			$momentum=query("select 3mnth,6mnth,12mnth from price_momentum where symbol=?",$symbol["symbol"]);
                        array_push($momentum_list,["symbol"=>$symbol["symbol"],"threeMnth"=>$momentum[0]["3mnth"],"sixMnth"=>$momentum[0]["6mnth"],"twelveMnth"=>$momentum[0]["12mnth"]]);
		}
		//print_r($momentum_list);
		return $momentum_list;

	}

	function get_trend_screen($list){
		$trend_list=array();
		foreach ($list as $share){
			//Get Moving Averages
			$averages=query("select symbol,tendayavg, thirtydayavg, hndrddayavg from price_momentum where symbol=?",$share['symbol']);
			//screen moving avg
			if ($averages[0]["tendayavg"] > $averages[0]["thirtydayavg"] && $averages[0]["thirtydayavg"] > $averages[0]["hndrddayavg"]){
				array_push($trend_list,["symbol"=>$averages[0]["symbol"],"tenday" =>$averages[0]["tendayavg"],"thirtyday"=>$averages[0]["thirtydayavg"],"hundredday"=>$averages[0]["hndrddayavg"] ]);
			}
		}
		return  $trend_list;

	}

	function get_earnings_growth($list){
		$earnings_list=array();
		//Get count of shares
		$row=query("select count(1) total from  stock_symbols where enabled='Y' and exchange=?",session_exchange());
		$count=$row[0]["total"];
		$rows=query("select symbol, 3mnth,6mnth,12mnth,tendayavg, thirtydayavg, hndrddayavg, earnings_growth from price_momentum order by earnings_growth desc limit ?",$count/4);
		foreach ($rows as $row){
			if (symbol_exists($row["symbol"],$list)){
				array_push($earnings_list,["symbol"=>$row["symbol"],"earningsgrowth"=>$row["earnings_growth"],"3mnth"=>$row["3mnth"],"6mnth"=>$row["6mnth"],"12mnth"=>$row["12mnth"],"tendayavg"=>$row["tendayavg"],"thirtydayavg"=>$row["thirtydayavg"],"hndrddayavg"=>$row["hndrddayavg"]]);
			}
		}
		return $earnings_list;
  	}

	//Get list of all indicators
	function get_indicators(){
		$category_indicators=array();
		//Get indicator categories
		$categories=query("select category_id, name, description from indicator_category");
		foreach($categories as $category){
			$indicators=query("select indicator_id, name, description from screen_indicators where category=?",$category["category_id"]);
			array_push($category_indicators,["category"=>$category,"indicators"=>$indicators]);
		}
		return $category_indicators;
	}
	
	//get indicators to calculate statistics 
	function get_stats_indicators(){
		$stats_indicators=array();
	

	//get stats pulled from yahoo
		$indicators=query("select indicator_id, name from screen_indicators where enabled='Y' and screen_function is null order by order_number asc");
			for ($i=0;$i<count($indicators);$i++)
				array_push($stats_indicators,$indicators[$i]);
	
	
		//get indicators defined by functions
		$indicators=query("select indicator_id, name from screen_indicators where enabled='Y' and length(screen_function)>0 order by order_number asc");
		for ($i=0;$i<count($indicators);$i++)
				array_push($stats_indicators,$indicators[$i]);	
		return $stats_indicators;
	
	}
	
	//
	
   /* Returns a stock by symbol (case-insensitively) else false if not found.
     */
    function share_lookup($symbol)
    {
        // reject symbols that start with ^
        if (preg_match("/^\^/", $symbol))
        {
            return false;
        }

        // reject symbols that contain commas
        if (preg_match("/,/", $symbol))
        {
            return false;
        }
		

		//initialize the share quote
		$share_quote=array();
		
		//Get As of Date as yesterdays date
		$date=new DateTime();
		$date->sub(new DateInterval('P1D'));
		$date=date_format($date,'Y-m-d');
		
		//write_log("share_lookup","date=$date");

	
		//get indicator categories .
		$categories=query("select distinct ic.category_id,ic.name,ic.description,ic.order from indicator_category ic, screen_indicators si where si.category=ic.category_id order by ic.order");
		foreach ($categories as $category){
			
			//Get latest share statistics from Yahoo
		/*	if (isset($category["yahoo_code"])) {
				get_share_statistics($date,$category["name"],$category["yahoo_code"],$symbol);
			}
		 * 
		 * 
	*/		
	
			//Get latest statistics job date
			$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
			$job_date=$rows[0]['job_date'];
			
			//initialize
			$indicators=array();
			
			$rows = query("SELECT
						      s2.date,
						      s2.indicator
						    FROM
						      statistics s2,
						      screen_indicators si 
						    WHERE
						      s2.symbol     =?
						      and s2.exchange =?
						    and s2.indicator    =si.name
						    AND si.category  =?
						    AND s2.date    =?
						    and si.enabled='Y'
						    group by s2.date,s2.indicator",$symbol,session_exchange(),$category["category_id"],$job_date);
    
    		foreach($rows as $row){

			//Get all stats for category that are non yahoo and calculated internally.
				//write_log("share_lookup","category_id=".$category["category_id"]);
				$statistics=query("select si.name, si.description, s.value, s.percentile, sec.value sector_average,sa.value market_average from statistics s, screen_indicators si , statistic_averages sec,statistic_averages sa, stock_symbols ss where s.indicator=si.name and sa.indicator=si.name and sec.indicator=si.name and sa.date=s.date and sec.date=s.date and sa.type='MEDIAN' and sec.type='MEDIAN' and sa.category='MARKET' and sec.category='SECTOR' and si.category=? and s.symbol=ss.symbol and s.exchange=sa.exchange and ss.sector=sec.sector and s.symbol=? and s.exchange=? and s.date=? and si.name=?"
								,$category["category_id"],$symbol,session_exchange(),$row['date'],$row['indicator']);
				
				//write_log("share_lookup","No of rows =".count($statistics));
				
				//Add the indicators values to the indicator array
				foreach ($statistics as $statistic)
					array_push($indicators,["name"=>$statistic["name"],"description"=>$statistic["description"],"value"=>$statistic["value"],"percentile"=>$statistic["percentile"],"market_average"=>$statistic["market_average"],"sector_average"=>$statistic["sector_average"]]);
			}
			
			//Add the indicators values to the category array
			array_push($share_quote,["category"=>["name"=>$category["name"],"description"=>$category["description"]],"indicators"=>$indicators]);

		}
		
		
		//finished processing all categories so now return the share indicators
		return $share_quote;
    }
	
	/* ------------------------------------------------------------------
	   The navbar share lookup.

	   templates/header.php posts the search box to quote.php, which used to
	   read the symbol as substr($term, 0, strpos($term, ' ')): everything
	   before the first space. With no space strpos() returns false, substr()
	   treats that as 0 and the symbol came out empty, so typing "III" gave
	   "Invalid Symbol" and only the "III 3i Group" form the suggestions
	   produce ever worked - and the suggestions never appeared either.

	   Resolving is split from searching because they answer different
	   questions: the form needs to know whether the term identifies one share,
	   and the suggestion endpoint just needs a short list.
	   ------------------------------------------------------------------ */

	/**
	 * Shares whose symbol or name contains $term. For the suggestion endpoint.
	 *
	 * Bound parameters only: $term arrives from the search box, and the
	 * archived search.php this replaces concatenated it into a MATCH()
	 * expression.
	 */
	function search_symbols($term,$limit=10){

		$term=trim((string)$term);
		if ($term===''){ return array(); }

		/* % and _ are wildcards inside LIKE, so someone searching for "100%"
		   would otherwise match everything. Backslash is escaped first, or
		   the backslashes added for the other two would be escaped in turn. */
		$like='%'.str_replace(array('\\','%','_'),array('\\\\','\%','\_'),$term).'%';

		//The only interpolated value here. It is a caller-supplied integer
		//rather than anything from the request, and it is cast regardless so
		//that stays true if a caller ever passes something looser.
		$limit=max(1,(int)$limit);

		return query("select symbol, name from stock_symbols
		               where enabled='Y' and exchange=?
		                 and (symbol like ? or name like ?)
		               order by case when upper(symbol)=upper(?) then 0
		                             when upper(symbol) like upper(?) then 1
		                             else 2 end, symbol
		               limit ".$limit,
		             session_exchange(),$like,$like,$term,$term.'%');
	}

	/**
	 * Resolve what someone typed into the shares it could mean.
	 *
	 * Returns a list of ['symbol'=>..,'name'=>..]. Empty means no match, one
	 * element means the term names a single share and the caller can show its
	 * quote, and more than one means it is ambiguous and the caller should
	 * offer a choice. The caller decides, because "no match" and "several"
	 * want different pages.
	 *
	 * An exact ticker always wins over a name containing the same letters: a
	 * share whose symbol is typed in full is what was meant, even if the
	 * letters also appear in a dozen company names.
	 */
	function find_symbols($term){

		$term=trim((string)$term);
		if ($term===''){ return array(); }

		$exchange=session_exchange();

		/* What the term might be as a ticker. The whole thing for a bare
		   symbol, and the first word for the "SYMBOL Name" the suggestions
		   put in the box. London tickers are written with a dot (BT.A) and
		   stored with a dash (BT-A), so each candidate is tried both ways. */
		$words=preg_split('/\s+/',$term);
		$candidates=array();
		foreach (array($term,$words[0]) as $candidate){
			if ($candidate===''){ continue; }
			$candidates[]=$candidate;
			if (strpos($candidate,'.')!==false){
				$candidates[]=str_replace('.','-',$candidate);
			}
		}

		foreach (array_unique($candidates) as $candidate){
			/* upper() on both sides rather than relying on the column
			   collation, so "iii" finds III whether or not the database was
			   built case insensitive. 350 rows, so the lost index does not
			   matter. */
			$rows=query("select symbol, name from stock_symbols
			              where enabled='Y' and exchange=? and upper(symbol)=upper(?)
			              order by symbol",
			            $exchange,$candidate);
			if (count($rows)>0){ return $rows; }
		}

		//Nothing is a ticker, so treat it as part of a company name.
		return search_symbols($term,50);
	}

	//function Get_Share_Info
	function get_share_info ($symbol){
		
		$share_query =query ("select name, market, sector,industry_group, industry from stock_symbols where symbol = ? and exchange=?",$symbol,session_exchange());
		
		if(count($share_query)>0){
			
			//Get Job date
			$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
			$job_date=$rows[0]['job_date'];
			//echo "jobdate=".$job_date;
			
			write_log('get_share_info','job_date='.$job_date);

			//Get Market Capitalisation
			//$market_cap=change_number(get_indicator_value($symbol, 'market_capitalisation',$job_date)); 
			
			//write_log('get_share_info',"market_cap=".$market_cap);
			
			//Get Shares Issued
			$fundamentals=share_fundamentals($symbol);
			$shares=get_indicator_value($symbol, 'shares_outstanding',$job_date);
			//shares_outstanding is a calculated indicator. When it has not been
			//calculated, the provider's own figure is in stock_info.
			if ($shares===null||$shares===""||$shares===0){ $shares=$fundamentals["shares"]; }
			//change_number() abbreviates to "18m", which the template's
			//number_or_blank() then rejects as non numeric and renders as an
			//empty cell. Market cap next to it is returned raw and formatted by
			//the template, so shares is too.
			
			//write_log('get_share_info','shares='.$shares);
			
			// Get Share info
			$yahoo_info = lookup($symbol);

			//The log line used to sit above this guard, so an unknown symbol or
			//an unset API key made lookup() return false and reading ["price"]
			//off it warned on every quote page.
			if($yahoo_info!==false) {

				debug_log('get_share_info','price='.$yahoo_info["price"]);
			
				$share_info=["symbol"=>$symbol,"name" => $share_query[0]["name"],"market" => $share_query[0]["market"],"sector" => $share_query[0]["sector"],"industry_group" => $share_query[0]["industry_group"],"industry" => $share_query[0]["industry"],"price" => $yahoo_info["price"],"capital" => $fundamentals["market_cap"],"shares" => $shares,"change"=>  $yahoo_info["change"],"day_range"=>  $yahoo_info["day_range"],"52w_low"=>  $yahoo_info["52w_low"],"52w_high"=>  $yahoo_info["52w_high"]];
		
				//echo print_r($share_info);
				return $share_info;
			}
			else 
				return false;
		}
		else 
			return false;
	}
	
	//function Get_Share_Name
	function get_share_name ($symbol){
		
		$share_query =query ("select name from stock_symbols where symbol = ? and exchange=?",$symbol,session_exchange());
		
		if(count($share_query)>0)
			return $share_query[0]["name"];
		else 
			return "";
	}
	
	//Get Profile for the share symbol
	function get_profile($symbol){
		$profile=query('SELECT description, ifnull(employees,"N/A") employees, ifnull(website,"N/A") website, ifnull(directors,"N/A") directors, logo FROM stock_symbols WHERE symbol=? and exchange=?'
						,$symbol,session_exchange());
		//write_log("get_profile","Count = ".count($profile));
		if (count($profile)>0)
			return $profile[0];
	}
	
	/**
	 * The company name to search news for.
	 *
	 * The feed was searched for "LSE:RR" and "LON:RR". Those are not how
	 * anybody writes about a company, and for a two letter ticker they match
	 * almost nothing: RR returned no usable articles at all. A quoted company
	 * name plus "shares" is what a person would type, and it returns the
	 * coverage the panel and the News tab are for.
	 *
	 * The legal suffix is dropped. "Rolls-Royce Holdings plc" as an exact
	 * phrase excludes every article that writes the name the normal way.
	 */
	function news_query($symbol){

		$name = '';
		$rows = query("select name from stock_symbols where symbol=? and exchange=?",
		              $symbol, session_exchange());
		if (count($rows) > 0) { $name = trim((string) $rows[0]['name']); }

		//No name on file: the ticker is all there is, and a bare ticker is
		//better than an empty search.
		if ($name === '') { return '"' . $symbol . '" shares'; }

		$name = preg_replace('/\s+(p\.?l\.?c\.?|ltd\.?|limited|inc\.?|corp\.?)\s*$/i', '', $name);
		$name = trim(preg_replace('/\s+/', ' ', $name));
		if ($name === '') { $name = $symbol; }

		//Quoted, so the words stay together: Rolls-Royce rather than every
		//article containing "Rolls" and "Royce" somewhere.
		return '"' . $name . '" shares';
	}

	//Get News articles for the share symbol
	function get_articles($symbol){
	
		 // numerically indexed array of articles
		$articles = [];

		//The News tab is one tab on a page that is mostly about something else,
		//so nothing here is worth a fatal. simplexml comes from php-xml, which
		//is a separate package like php-curl was, and the fetch needs
		//allow_url_fopen. Missing either means no articles, not a dead page.
		if (!function_exists("simplexml_load_string") || !ini_get("allow_url_fopen")) {
			write_log("get_articles","news unavailable: no simplexml or allow_url_fopen is off");
			return $articles;
		}

		$query = news_query($symbol);

		// headers for proxy servers
		//The User-Agent used to be built from curl_version(), which needs
		//ext-curl. The fetch itself is file_get_contents, so curl was never
		//doing the work - it only supplied a version string - and on a host
		//without php-curl the call was a fatal that took the page with it.
		//
		//Connection: close, and it matters more than it looks.
		//
		//PHP's http stream wrapper does not do keep-alive: it opens one
		//socket, sends the request and reads to end of file. Ask for
		//Keep-Alive and the server obligingly does not close the socket, so
		//there is no end of file to read - the wrapper sits there until the
		//read timeout expires and only then hands back the body it already
		//had. Every response costs the full timeout: on the N100 that was
		//6.2 seconds per search against 0.2-0.6 with close, and the feed
		//redirects, so the wrapper paid it twice. The quote page took 13
		//seconds, 12.4 of them here.
		//
		//Nothing was ever reused: there is no second request on the
		//connection to reuse it for.
		$headers = [
			"Accept" => "*/*",
			"Connection" => "close",
			"User-Agent" => "sharemanager/1.0 (+php " . PHP_VERSION . ")"
		];

		// download RSS from Google News
		//This blocks the quote page while it runs. Without a timeout it
		//waits for default_socket_timeout, which on a host with no outbound
		//network means the page hangs rather than loads.
		$context = stream_context_create([
			"http" => [
				"header" => implode(array_map(function($value, $key) { return sprintf("%s: %s\r\n", $key, $value); }, $headers, array_keys($headers))),
				"method" => "GET",
				"timeout" => 3
			]
		]);

		//One request now rather than two. The old pair only differed by an
		//exchange prefix on the ticker, and both halves searched for
		//something nobody writes.
		$contents = @file_get_contents(news_feed_url($query), false, $context);

		//This runs inside the quote page. Ending the request on a failed
		//fetch took the whole page down with it, so a news feed that is
		//unreachable - no outbound network, a rate limit, a changed URL -
		//now just contributes no articles.
		if ($contents === false)
		{
			write_log("get_articles","no response for ".$symbol);
			return $articles;
		}

		// parse RSS
		$rss = @simplexml_load_string($contents);
		if ($rss === false || !isset($rss->channel->item))
		{
			write_log("get_articles","unparseable feed for ".$symbol);
			return $articles;
		}

		// iterate over items in channel
		foreach ($rss->channel->item as $item)
		{

			$title = (string) $item->title ;
			$found=false;

			//Block certain publishers
			$blocked=array("Motley Fool","London South East","Proactive Investor");

			foreach($blocked as $publisher){
				//strpos returns 0 when the title starts with the publisher
				//name, which >0 reads as "not found".
				if (strpos($title,$publisher)!==false)
					$found=true;
			}

			// add article to array. $blocked is a list of publishers to
			// leave out, so the test was the wrong way round: it kept only
			// the articles it was meant to drop.
			if (!$found)
			$articles[] = [
				"link" => (string) $item->link,
				"title" => (string) $item->title,
				"pubdate" => (string) $item->pubDate,
				"description" => (string) $item->description
			];
		}

		return $articles;

	}

	/**
	 * The Google News RSS URL for a search.
	 *
	 * Separate so the tests can read the query back without a network call.
	 */
	function news_feed_url($query){
		$base = defined('SM_NEWS_BASE_URL') && SM_NEWS_BASE_URL !== ''
		      ? rtrim(SM_NEWS_BASE_URL, '/') : 'https://news.google.com';
		return $base . "/rss/search?q=" . rawurlencode($query)
		     . "&hl=en-GB&gl=GB&ceid=GB:en";
	}

	//Calculate average cost of share
	function avg_share_cost($symbol){
	
//	history table
//for each transaction of share
//add up all buy transactions cost or qty and price
//add up all sell transactions amout or qty and price
//substract sum of sell from sum of buy transactions
//take amount and divide by numbers of buy shares minus number of sell shares
	$avg_share_cost = query("select shares.symbol,(SELECT sum(price_paid)/sum(shares) FROM `purchases` WHERE symbol=shares.symbol and trx_type='BUY')/ (select sum(case when trx_type='BUY' then shares 
                                                                                                  when 'SELL' then -1*shares end) from purchases where symbol=shares.symbol) as avg_cost from (select distinct symbol as symbol from purchases) as shares where symbol=?",$symbol);
	 if (count($avg_share_cost)>0)
		return $avg_share_cost[0]["avg_cost"];
	}
	
	//Update Portfolio 
	function update_portfolio($symbol){
		$shares=query("select distinct symbol as symbol from purchases where symbol=?",$symbol);
		foreach ($shares as $share){
			$avg_cost = avg_share_cost($share["symbol"]);
			//check if share exists in portfolio
			$share_symbol = query("select symbol from shares where symbol=?",$share["symbol"]);
			if (count($share_symbol)>0) 
				query("update shares set avg_cost=? where symbol=?",$avg_cost, $share["symbol"]);
			else
				query("insert into shares (symbol,avg_cost) values (?,?)",$share["symbol"],$avg_cost);
		}
	
	}
	
	//Financial Statement
	function financial_statement($symbol,$type){
	
		$income_statement=array();
		$period_items=array();
	
		//Get the financial statement items
		$items=query("SELECT name, description FROM financial_statement_items WHERE type='$type'");
		//Get the periods for the symbol
		$periods=query("SELECT fsp.period_id, fsp.end_date,fsp.period_name FROM `financial_statement_values` fsv, financial_statement_items fsi, financial_statement_periods fsp   WHERE fsi.name=fsv.item_name and fsv.period_id=fsp.period_id  and fsv.symbol=?  and fsi.type=? group by fsp.period_id, fsp.end_date,fsp.period_name order by fsp.end_date desc limit 5 ",$symbol,$type);
			
		//for each item get the last 5 years maximum values
		foreach($items as $item) {
			$period_values=[];
			foreach ($periods as $period){
				//write_log('financial_statement',$symbol.' '.$period["period_id"].' '.$item["name"].' '.$item["description"]);
				$values=query("SELECT fsv.value FROM `financial_statement_values` fsv WHERE fsv.symbol=? and fsv.period_id=? and fsv.item_name=?",$symbol,$period["period_id"],$item["name"]);
				if (count($values)>0) {
					
					$value=$values[0]["value"];
					
					//Format number to contain commas and brackets for negative numbers
					if ($value<0)
						$values[0]["value"]="(".number_format(abs($value),2).")";
					else 
						$values[0]["value"]=number_format($value,2);	

					$period_values[]=$values[0]["value"];
					
				}
			}
			array_push($period_items,["item_name"=> $item["name"],"description"=>$item["description"],"values"=>$period_values]);
		
		}
		
		return ["periods"=> $periods,"period_items"=>$period_items];
	
	
	
	
	}






		
	
	//Income Statement
	function income_statement($symbol){
			return financial_statement($symbol,'income_statement');
	}
	
	//Balance Sheet
	function balance_sheet($symbol){
			return financial_statement($symbol,'balance_sheet');
	}

	//Cash Flow Statement
	function cash_flow_statement($symbol){
			return financial_statement($symbol,'cash_flow_statement');
	}
	
	function update_cash($amount) {
		query("update users set cash=cash+? where id=?",$amount,$_SESSION["id"]);
	}
	
	function update_cash_history($transaction_date, $trx_type, $amount) {
		query("insert into cash_history (transaction_date,trx_type,amount,user_id) values (?, ?, ?, ?)",$transaction_date, $trx_type, $amount,$_SESSION["id"]);
	}
	
	//Ratings
	function ratings($symbol){

		//Get As of Date as yesterdays date
		$date=new DateTime();
		$date->sub(new DateInterval('P1D'));
		$date=date_format($date,'Y-m-d');

		$rows=query("select number momentum_rating, growth_rating, value_rating, quality_rating,overall_rating from momentum_ratings where symbol=? and date=?",$symbol,$date);
		
		if (count($rows)>0){
			return $rows[0];
		}
		
		//A symbol the ratings job has not reached yet returned null, and the
		//Ratings tab then read five offsets on it and warned five times. Hand
		//back the same keys with no values so the tab renders blank instead.
		return array("momentum_rating"=>null,"growth_rating"=>null,"value_rating"=>null,
		             "quality_rating"=>null,"overall_rating"=>null);
	}
	
//Get momentum statistics for the Momentum Rating
function get_momentum_statistics($symbol){
	
//Get As of Date as yesterdays date
//$asOfDate=new DateTime();
//$asOfDate->sub(new DateInterval('P1D'));
//$asOfDate=date_format($asOfDate,'Y-m-d');

//Get Job date
$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
$job_date=$rows[0]['job_date'];

//write_log("ratings.php","job_date=job_date");

//$symbol arrives from quote.php's query string. The sibling growth, value and
//quality queries bind it; this one interpolated it straight into the SQL.
$query="SELECT si.description, s.value,si.order_number
FROM  `screen_criteria` sc, screen_build sb, screen_indicators si, statistics s
WHERE sb.criteria_id = sc.id
AND si.indicator_id = sc.indicator_id
AND si.name = s.indicator
AND s.symbol =  ?
and s.exchange = ?
AND sb.screen_id
IN ( 3, 4, 5, 6, 7 )
and s.date=?
union
SELECT si.description, s.value,si.order_number
FROM  `screen_criteria` sc, screen_build sb, screen_indicators si, statistics s
WHERE sb.criteria_id = sc.id
AND si.name = sc.second_operand
AND si.name = s.indicator
AND s.symbol =  ?
and s.exchange = ?
AND sb.screen_id
IN ( 3, 4, 5, 6, 7 )
and s.date=?
order by order_number";

//write_log("ratings.php","query=$query");


	$rows = query($query,$symbol,session_exchange(),$job_date,$symbol,session_exchange(),$job_date);

return $rows;

}

//Get statistics for the Growth Rating
function get_growth_statistics($symbol){
	
//Get As of Date as yesterdays date
//$asOfDate=new DateTime();
//$asOfDate->sub(new DateInterval('P1D'));
//$asOfDate=date_format($asOfDate,'Y-m-d');


//Get Job date
$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
$job_date=$rows[0]['job_date'];


$rows = query("SELECT si.description, s.value,order_number
FROM  `screen_criteria` sc, screen_build sb, screen_indicators si, statistics s
WHERE sb.criteria_id = sc.id
AND si.indicator_id = sc.indicator_id
AND si.name = s.indicator
AND s.symbol =  ?
and s.exchange = '".session_exchange()."'
AND sb.screen_id
IN ( 8,9,10,11,12 )
and s.date=?
union
SELECT si.description, s.value,order_number
FROM  `screen_criteria` sc, screen_build sb, screen_indicators si, statistics s
WHERE sb.criteria_id = sc.id
AND si.name = sc.second_operand
AND si.name = s.indicator
AND s.symbol =  ?
and s.exchange = '".session_exchange()."'
AND sb.screen_id
IN ( 8,9,10,11,12 )
and s.date=?
order by order_number",$symbol,$job_date,$symbol,$job_date);

return $rows;

}

//Get statistics for the Value Rating
function get_value_statistics($symbol){
	
//Get As of Date as yesterdays date
//$asOfDate=new DateTime();
//$asOfDate->sub(new DateInterval('P1D'));
//$asOfDate=date_format($asOfDate,'Y-m-d');


//Get Job date
$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
$job_date=$rows[0]['job_date'];

$rows = query("SELECT si.description, s.value,order_number
FROM  `screen_criteria` sc, screen_build sb, screen_indicators si, statistics s
WHERE sb.criteria_id = sc.id
AND si.indicator_id = sc.indicator_id
AND si.name = s.indicator
AND s.symbol =  ?
and s.exchange = '".session_exchange()."'
AND sb.screen_id
IN ( 13,14,15,16,17 )
and s.date=?
union
SELECT si.description, s.value,order_number
FROM  `screen_criteria` sc, screen_build sb, screen_indicators si, statistics s
WHERE sb.criteria_id = sc.id
AND si.name = sc.second_operand
AND si.name = s.indicator
AND s.symbol =  ?
and s.exchange = '".session_exchange()."'
and s.exchange=?
AND sb.screen_id
IN ( 13,14,15,16,17 )
and s.date=?
order by order_number",$symbol,session_exchange(),$job_date,$symbol,$job_date);

return $rows;

}

//Get statistics for the Quality Rating
function get_quality_statistics($symbol){
	
//Get As of Date as yesterdays date
//$asOfDate=new DateTime();
//$asOfDate->sub(new DateInterval('P1D'));
//$asOfDate=date_format($asOfDate,'Y-m-d');


//Get Job date
$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
$job_date=$rows[0]['job_date'];

$rows = query("SELECT si.description, s.value,order_number
FROM   screen_indicators si, statistics s, indicator_category ic
WHERE 1=1
and si.name=s.indicator
and si.category=ic.category_id
AND s.symbol =  ?
and s.exchange=?
and s.date=?
and ic.category_id in (10,11);",$symbol,session_exchange(),$job_date);

return $rows;

}

//Get Momentum and Value Score
function get_scores($symbol){
	
//Get As of Date as yesterdays date
$asOfDate=new DateTime();
$asOfDate->sub(new DateInterval('P1D'));
$asOfDate=date_format($asOfDate,'Y-m-d');

//write_log("get_scores.php","asOfDate=$asOfDate");

	///init score
	$score ['momentum']=50;
	$score ['value']=50;
	$score ['quality']=50;
	$score ['overall']=50;

	/* A fund has no value, quality or overall score at all, and the neutral 50
	   above would read as a real middling score rather than an absent one.
	   templates/quote.php shows "Not scored: investment fund" instead. */
	$score ['is_fund']=is_fund($symbol);
	
	$rows = query("SELECT indicator,value from statistics s1 where symbol=? and exchange=? and date=(select max(date) from statistics s2 where s2.symbol=s1.symbol and s2.indicator=s1.indicator and s2.date<=? and s2.exchange=s1.exchange) and s1.indicator in ('quality_score','momentum_score','value_score','overall_score')",$symbol,session_exchange(),$asOfDate);
   foreach($rows as $row){
		if ($row['indicator']=='momentum_score')
			$score['momentum']=$row['value'];
		else if ($row['indicator']=='value_score')
			$score['value']=$row['value'];	  
		else if ($row['indicator']=='quality_score')
			$score['quality']=$row['value'];
		else if ($row['indicator']=='overall_score')
			$score['overall']=$row['value'];	  
   }	
   
   	//write_log("get_scores.php","value score=".$score['value']);
		
		return $score;

}

//Get Value Score Components
function get_value_rank($symbol){
	
//Get As of Date as yesterdays date
$asOfDate=new DateTime();
$asOfDate->sub(new DateInterval('P1D'));
$asOfDate=date_format($asOfDate,'Y-m-d');

//Get latest statistics job date
			$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
			$asOfDate=$rows[0]['job_date'];

//write_log("get_scores.php","asOfDate=$asOfDate");


	
	/* The same membership as the value score, from the same place: category
	   12 with calc_rank='Y'. It was a hard-coded list naming two indicators
	   that are not rows in screen_indicators at all, so the panel and the
	   score could drift apart without anything saying so. */
	$score = query("SELECT s1.indicator,si.description, s1.value share_value,s1.percentile, sa.value market_value from statistics s1 ,screen_indicators si, statistic_averages sa where s1.indicator=si.name and s1.indicator=sa.indicator and sa.date=s1.date and type='MEDIAN' and sa.category='MARKET' and s1.symbol=?  and s1.exchange=? and sa.exchange=s1.exchange and s1.date=? and si.category=12 and si.calc_rank='Y'",$symbol,session_exchange(),$asOfDate);
   
   
   	//write_log("get_scores.php","value score=".$score['value']);
		
		return $score;

}

//Get Momentum Score Components
function get_momentum_rank($symbol){
	
//Get As of Date as yesterdays date
$asOfDate=new DateTime();
$asOfDate->sub(new DateInterval('P1D'));
$asOfDate=date_format($asOfDate,'Y-m-d');

//write_log("get_scores.php","asOfDate=$asOfDate");
//Get latest statistics job date
	$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
	$asOfDate=$rows[0]['job_date'];


	
	$score = query("SELECT s1.indicator,si.description, s1.value share_value,s1.percentile, sa.value market_value from statistics s1 ,screen_indicators si, statistic_averages sa where s1.indicator=si.name and s1.indicator=sa.indicator and sa.date=s1.date and type='MEDIAN' and sa.category='MARKET' and s1.symbol=? and s1.exchange=? and sa.exchange=s1.exchange and s1.date=? and s1.indicator in ('3mnth','6mnth','12mnth') ",$symbol,session_exchange(),$asOfDate);
   
   
   	//write_log("get_scores.php","value score=".$score['value']);
		
		return $score;

}

//Get Quality Score Components
function get_quality_rank($symbol){
	
//Get As of Date as yesterdays date
$asOfDate=new DateTime();
$asOfDate->sub(new DateInterval('P1D'));
$asOfDate=date_format($asOfDate,'Y-m-d');

//write_log("get_scores.php","asOfDate=$asOfDate");
//Get latest statistics job date
$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
$asOfDate=$rows[0]['job_date'];


	
	$score = query("SELECT s1.indicator,si.description, s1.value share_value,s1.percentile, sa.value market_value from statistics s1 ,screen_indicators si, statistic_averages sa where s1.indicator=si.name and s1.indicator=sa.indicator and sa.date=s1.date and type='MEDIAN' and sa.category='MARKET' and s1.symbol=? and s1.exchange=? and sa.exchange=s1.exchange and s1.date=? and s1.indicator in ('roe_ttm','roa','operating_margin','profit_margin','roce') ",$symbol,session_exchange(),$asOfDate);
   
   
   	//write_log("get_scores.php","value score=".$score['value']);
		
		return $score;

}

//Get Financial Statement Value
function get_fin_Stmt_value($symbol,$date,$item){
	
	//write_log("get_fin_Stmt_value","asOfDate=".$date." symbol=$symbol item=$item");
	
	$rows = query(" SELECT fsv.period_id, value
					FROM `financial_statement_values` fsv,
					financial_statement_periods fsp
					WHERE fsp.period_id=fsv.period_id 
					and symbol=?
					and fsv.item_name=?
					and fsp.end_date = (select max(fsp.end_date)
					                 from `financial_statement_values` fsv,
					                 financial_statement_periods fsp
									WHERE fsv.period_id=fsp.period_id 
									and symbol=?
									and fsv.item_name=?
									and fsp.end_date<=?)
					limit 1",$symbol,$item,$symbol,$item,$date);
					
	if (count($rows)>0){
		
		$value=$rows[0]['value'];
		
		//write_log("get_fin_Stmt_value","value=$value");
		
		return $value;
	}
}

/**
 * The two health scores, as the provider worked them out.
 *
 * Returns value, percentile and - where the score does not apply to this
 * company's sector - the reason it was left unranked, so the page can say so
 * rather than show a blank that reads as missing data.
 */
function get_health_scores($symbol) {

	$rows = query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
	$as_of = count($rows) ? $rows[0]['job_date'] : null;

	$scores = [];

	foreach (['piotroski_score' => 'Piotroski F-Score',
	          'altman_z'        => 'Altman Z-Score'] as $name => $label) {

		$row = $as_of === null ? [] : query(
			"select s.value, s.percentile, si.description
			   from statistics s, screen_indicators si
			  where si.name = s.indicator and s.indicator = ?
			    and s.symbol = ? and s.exchange = ? and s.date = ?",
			$name, $symbol, session_exchange(), $as_of);

		$scores[$name] = [
			"label"      => (count($row) && $row[0]['description'] !== null)
			                ? $row[0]['description'] : $label,
			"value"      => (count($row) && $row[0]['value'] !== null)
			                ? (float) $row[0]['value'] : null,
			"percentile" => (count($row) && $row[0]['percentile'] !== null)
			                ? (float) $row[0]['percentile'] : null,
			"not_ranked" => sector_exclusion_reason($name, $symbol),
		];
	}

	return $scores;
}

//Get Relative Sector Valuation
function get_valuation($symbol){
	
	//Get As of Date as yesterdays date
	$asOfDate=new DateTime();
	$asOfDate->sub(new DateInterval('P1D'));
	$asOfDate=date_format($asOfDate,'Y-m-d');
	
//	write_log("get_scores.php","asOfDate=$asOfDate");
	
	$value=get_indicator_value($symbol, 'relative_valuation',$asOfDate);
	
	//write_log("get_valuation","value =".$value);
	
	//get share price
	$price=get_share_price($symbol,$asOfDate);
	
//	write_log("get_valuation","price =".$price);
	
	//calculate ratio value to price
	if ($price && $price!=0){
		$ratio=round($value/$price*100);
	}
	else {
		$ratio=0;
	}
	
//	write_log("get_valuation","ratio =".$ratio);
	
	//round(null) is deprecated in PHP 8, and a null $value means the valuation
	//could not be calculated rather than that it is zero. Keep it null.
	$valuation['value']=($value===null) ? null : round($value);
	$valuation['price']=$price;
	$valuation['ratio']=$ratio;
	
//	write_log("get_valuation","valuation['ratio'] =".$valuation['ratio']);
	
	return $valuation;

}

//Get Relative Industry Valuation
function get_industry_valuation($symbol){
	
	//Get As of Date as yesterdays date
	$asOfDate=new DateTime();
	$asOfDate->sub(new DateInterval('P1D'));
	$asOfDate=date_format($asOfDate,'Y-m-d');
	
	//write_log("get_scores.php","asOfDate=$asOfDate");
	$value=get_indicator_value($symbol, 'relative_industry_valuation',$asOfDate);
	if (is_null($value)){
		$value=0;
	}
	
	//write_log("get_valuation","value =".$value);
	
	//get share price
	$price=get_share_price($symbol,$asOfDate);
	
	//write_log("get_valuation","price =".$price);
	
	//calculate ratio value to price
	$ratio=round($value/$price*100);
	
	//write_log("get_valuation","ratio =".$ratio);
	
	$valuation['value']=round($value);
	$valuation['price']=$price;
	$valuation['ratio']=$ratio;
	
	//write_log("get_valuation","valuation['ratio'] =".$valuation['ratio']);
	
	return $valuation;

}

/* ---------------------------------------------------------------------------
   Medians.

   Two medians are published, and they answer different questions:

     peer_median()   the median of a share's peers, for the implied price in
                     price_valuation. The share is left out of it, because the
                     question is what the rest of the sector says this one is
                     worth.
     group_median()  the median of a whole group, for the sector, industry and
                     market columns in statistic_averages. The share is in it,
                     because the question is what the group looks like.

   Everything else about them is the same, and is in median_sample(): the same
   universe, the same exclusions, the same arithmetic. They were two different
   definitions until now - the stored ones came from a SQL expression that took
   the upper of the two middle values for an even count
   (HAVING SUM(SIGN(1-SIGN(y.value-x.value)))/COUNT(*) > .5), which is a
   member's own figure rather than a median - so the quote page showed two
   numbers both labelled "sector" that did not agree.

   What the implied price was before this:

   - The median included the share itself. Aerospace & Defense has four members
     on the live FTSE 350 - BAB 23.3, BA 26.9, MRO 37.9, RR 37.5 - and the
     median returned for RR was RR's own 37.52, so the implied price came back
     as the current price and the whole figure was circular.
   - Four members is not a sample. A median over two or three peers moves
     wholesale when one of them reports.
   --------------------------------------------------------------------------- */

/* Below this many peers the median says more about which companies happen to
   be listed than about the price of anything, so nothing is written. Five
   leaves two values either side of the middle. Counted after the exclusions
   below, not before: four usable peers and one loss-maker is still four. */
if (!defined('PEER_MEDIAN_MIN_PEERS')) { define('PEER_MEDIAN_MIN_PEERS', 5); }

/**
 * The values one median is taken over, sorted.
 *
 * $group is 'sector', 'industry', or 'market' for the whole exchange, in which
 * case $group_value is ignored. $exclude_symbol is left out when given.
 *
 * What is left out, and why:
 *
 *   funds           an investment trust's P/E is its holdings' earnings seen
 *                   through a discount; it is not a price for anything the
 *                   other members of the sector sell. Same rule as the
 *                   rankings - see sql/fund_sectors.sql.
 *   disabled        not in the universe.
 *   zero            where rank_zero says a zero is a missing value rather
 *                   than a real one.
 *   non-positive    where rank_negative is EXCLUDE. A negative P/E does not
 *                   mean cheap and it does not mean dear: the ratio has no
 *                   meaning for a company with no earnings, so it has no
 *                   business dragging the median its peers are measured
 *                   against. This is the same rule the rankings already
 *                   apply, where leaving negatives in had a loss-maker
 *                   scoring as the cheapest share in the index.
 */
function median_sample($indicator, $date, $group, $group_value, $exclude_symbol = null) {

	/* Interpolated into the query below, so it is checked against what it is
	   allowed to be rather than trusted. */
	if ($group !== 'sector' && $group !== 'industry' && $group !== 'market') {
		write_log('median_sample', "unknown grouping '".$group."'");
		return [];
	}

	$params = [$indicator, $date, session_exchange()];
	$where  = "";

	if ($group !== 'market') {
		if ($group_value === null || trim((string) $group_value) === '')
			return [];
		$where   .= " and ss.".$group." = ?";
		$params[] = $group_value;
	}

	if ($exclude_symbol !== null) {
		$where   .= " and ss.symbol <> ?";
		$params[] = $exclude_symbol;
	}

	$rows = query("select s.value
	                 from statistics s, stock_symbols ss, screen_indicators si
	                where s.symbol = ss.symbol
	                  and s.exchange = ss.exchange
	                  and si.name = s.indicator
	                  and s.indicator = ?
	                  and s.date = ?
	                  and s.exchange = ?
	                  and ss.enabled = 'Y'
	                  and if(s.value=0,if(si.rank_zero='N',0,1),1)=1
	                  and if(ifnull(nullif(si.rank_negative,''),'NORMAL')='EXCLUDE',
	                         s.value > 0, 1)=1
	                  ".not_fund_sql('ss.symbol').$where."
	                order by s.value",
	              ...$params);

	return array_map('floatval', array_column($rows, 'value'));
}

/**
 * The median of a sorted list, or null if it is empty.
 *
 * The middle value, or the mean of the two middle values when the count is
 * even. Taking the upper of the two, which is what the old SQL did, returns a
 * member's own figure and is not a median.
 */
function median_of(array $sorted) {

	$n = count($sorted);

	if ($n === 0)
		return null;

	$middle = intdiv($n, 2);

	return ($n % 2 === 1) ? $sorted[$middle]
	                      : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
}

/**
 * The median of one indicator across a share's peers, or null.
 *
 * $symbol is the share being valued and is left out of its own comparison set.
 * Returns null when fewer than $min_peers peers are left once median_sample()
 * has done its excluding, which the caller treats as "no comparison to make"
 * rather than as a zero.
 */
function peer_median($indicator, $date, $group, $group_value, $symbol,
                     $min_peers = PEER_MEDIAN_MIN_PEERS) {

	$values = median_sample($indicator, $date, $group, $group_value, $symbol);

	return (count($values) < $min_peers) ? null : median_of($values);
}

/**
 * The median of one indicator across a whole group, or null.
 *
 * This is what statistic_averages stores and the sector and market columns on
 * the quote page show. No minimum: a group of one has a median, and saying so
 * is the behaviour the column has always had. The share being looked at is in
 * it, which is the difference from peer_median() and is why the two are
 * labelled differently on the page.
 */
function group_median($indicator, $date, $group, $group_value = null) {

	return median_of(median_sample($indicator, $date, $group, $group_value));
}

//Get Price valuation indicators for Relative to Sector
function get_relative_to_sector($symbol){
	//Get statistics job date
	$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
	$job_date=$rows[0]['job_date'];
			
	$rows=query("select si.description indicator, share_stat,sector_stat,value from price_valuation pv, screen_indicators si where si.name=pv.indicator and type='relative_sector' and symbol=? and date=?",$symbol,$job_date);
	
	return $rows;	
}

//Get Price valuation indicators for Relative to Industry
function get_relative_to_industry($symbol){
	//Get statistics job date
	$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
	$job_date=$rows[0]['job_date'];
			
	$rows=query("select si.description indicator, share_stat,industry_stat,value from price_valuation pv, screen_indicators si where si.name=pv.indicator and type='relative_industry' and symbol=? and date=?",$symbol,$job_date);
	
	return $rows;	
}

//Convert values containing B, K and M to actual number value
function convert_value($value){
	//Strip £ and commas
	
	$string=$value;
	$patterns = array();
	$patterns[0] = '/£/';
	$patterns[1] = '/,/';
	$patterns[2] = '/%/';
	$replacements = array();
	$replacements[0] = '';
	$replacements[1] = '';
	$replacements[2] = '';
	$value=preg_replace($patterns, $replacements, $string);
	
	//echo "converted value".$value;

	if (strpos($value,'B')>0)
		return 1000000000 * substr($value,0,strpos($value,'B'));
	elseif (strpos($value,'b')>0)
		return 1000000000 * substr($value,0,strpos($value,'b'));
	elseif (strpos($value,'K')>0)
		return 1000 * substr($value,0,strpos($value,'K'));
	elseif (strpos($value,'k')>0)
		return 1000 * substr($value,0,strpos($value,'k'));
	elseif (strpos($value,'m')>0)
		return 1000000 * substr($value,0,strpos($value,'m'));
	elseif (strpos($value,'M')>0)
		return 1000000 * substr($value,0,strpos($value,'M'));
	else return $value;
}

//Convert number values to a display value containing m, or b for millions or billions.
function change_number($value){
	//Called with whatever a provider or an uncalculated indicator supplied,
	//which may be null or a non numeric string. Dividing that is a TypeError
	//on PHP 8, so anything that is not a number comes straight back.
	if (!is_numeric($value)) {
		return $value;
	}
	$value = (float)$value;
	if ($value/1000000000>=1) 
		return strval($value/1000000000)."b";
	elseif ($value/1000000>=1) 
		return strval($value/1000000)."m";
	else {
		return $value;
	}
	
	/*if (strpos($value,'B')>0)
		return 1000000000 * substr($value,0,strpos($value,'B'));
	elseif (strpos($value,'b')>0)
		return 1000000000 * substr($value,0,strpos($value,'b'));
	elseif (strpos($value,'K')>0)
		return 1000 * substr($value,0,strpos($value,'K'));
	elseif (strpos($value,'k')>0)
		return 1000 * substr($value,0,strpos($value,'k'));
	elseif (strpos($value,'m')>0)
		return 1000000 * substr($value,0,strpos($value,'m'));
	elseif (strpos($value,'M')>0)
		return 1000000 * substr($value,0,strpos($value,'M'));
	else return $value;*/
}

//Insert or update statistic value
function update_statistic($symbol, $indicator, $value, $date){
	
	//write_log('update_statistic',"symbol=$symbol , indicator=$indicator, value = $value, date= $date");
	
	//check if symbol for indicator exists in statistics
	$rows=query("select symbol from statistics where symbol=? and exchange=? and indicator=? and date=?",$symbol,session_exchange(),$indicator,$date);
	//insert record in statistics
	if (count($rows)==0){
		query("insert into statistics (symbol, exchange, indicator, value,date) values (?,?,?,?,?)",$symbol,session_exchange(),$indicator,$value,$date);
	}
	//update record in statistics
	else {
		query("update statistics set value=? where symbol=? and exchange=? and indicator=? and date=?",$value,$symbol,session_exchange(),$indicator,$date);
	}
}

//get value for symbol, item and date from financial statement
function get_val_from_fin_stmt($symbol, $item, $date) {
	
	//write_log("get_val_from_fin_stmt","symbol=".$symbol."Item=".$item." Date=".$date);
	
	$row=query("SELECT value * 1000 value
FROM 
financial_statement_values fsv, 
financial_statement_items fsi,
financial_statement_periods fsp 
WHERE fsv.item_name=fsi.name 
and fsp.period_id=fsv.period_id
and fsi.name=?
and fsv.symbol=?
and fsp.end_date=(
SELECT max(end_date)
FROM 
financial_statement_values fsv, 
financial_statement_items fsi ,
financial_statement_periods fsp
WHERE fsv.item_name=fsi.name 
and fsp.period_id=fsv.period_id
and fsi.name=?
and fsv.symbol=?
and fsp.end_date<=?)",$item,$symbol,$item,$symbol,$date);

 if (count($row)>0)
//We only expect one row
return $row[0]['value'];
	
}



//get indicator value from statistics for a given date
function get_indicator_value($symbol, $indicator,$date){
	//write_log('get_indicator_value',"symbol=$symbol, indicator=$indicator, date=$date");
	
	$row=query("select value from statistics where symbol=? and exchange=? and indicator=? and date=(select max(date) max_date from statistics where symbol=? and exchange=? and indicator=? and date<=? and date is not null)",$symbol,session_exchange(),$indicator,$symbol,session_exchange(),$indicator,$date );
	
	
	if (count($row)>0)
		return  $row[0]['value'];
}

//Calculate Revenue Per Share
function calc_revenue_per_share($date,$symbol){

	//write_log("calc_revenue_per_share","symbol=".$symbol." Date=".$date);
	
	//get total_revenue from financial_statement
	$revenue=get_val_from_fin_stmt($symbol,'total_revenue',$date);
	
	//write_log("calc_revenue_per_share","revenue=".$revenue);
	
	//get number of shares
	$num_shares=get_indicator_value($symbol, 'shares_outstanding',$date);
	
	//write_log("calc_revenue_per_share","num_shares=".$num_shares);
	
	if ($num_shares>0){
		//calculate revenue per share in cents per share
		$rev_per_share=$revenue*100/$num_shares;
	
		//write_log("calc_revenue_per_share","rev_per_share=".$rev_per_share);
	
		//update statistics
		update_statistic($symbol, 'revenue_per_share', $rev_per_share, $date);
	}
}

//Calculate Price to Free Cash Flow
function calc_price_free_cash_flow($date,$symbol){	
	
	//write_log("calc_price_free_cash_flow","symbol=".$symbol." Date=".$date);
	
	//get number of shares
	$num_shares=get_indicator_value($symbol, 'shares_outstanding',$date);
	
	//write_log("calc_price_free_cash_flow","num_shares=".$num_shares);
	
	if ($num_shares>0){
		
		//get total_cash_flow_operating_activities from financial_statement
		$total_cash_flow_operating_activities=get_val_from_fin_stmt($symbol,'total_cash_flow_operating_activities',$date);
		
		//write_log("calc_price_free_cash_flow","total_cash_flow_operating_activities=".$total_cash_flow_operating_activities);
		
		//get Capital Expenditure
		$capital_expenditures=get_val_from_fin_stmt($symbol,'capital_expenditures',$date);
		
		//write_log("calc_price_free_cash_flow","capital_expenditures=".$capital_expenditures);
		
		//Calculate Free Cash Flow
		$free_cash_flow=$total_cash_flow_operating_activities+$capital_expenditures;
			
		//write_log("calc_price_free_cash_flow","free_cash_flow=".$free_cash_flow);
		
		if ($free_cash_flow<=0){
			$price_free_cash_flow_per_share=0;
		}
		else {
		
			//get share price
			$price=get_share_price($symbol,$date);
			
			//calculate free cash flow per share in cents per share
			$free_cash_flow_per_share=$free_cash_flow*100/$num_shares;
		
			//write_log("calc_price_free_cash_flow","free_cash_flow_per_share=".$free_cash_flow_per_share);
	
			//Calculate Price to Free Cash Flow Ratio
			$price_free_cash_flow_per_share=$price/$free_cash_flow_per_share;
		}
			//update statistics
			update_statistic($symbol, 'price_free_cash_flow_per_share', $price_free_cash_flow_per_share, $date);
	}
}


//Calculate Price to Sales Ratio
function calc_price_to_sales($date,$symbol){

	//write_log("calc_price_to_sales","symbol=".$symbol." Date=".$date);
	
	//Revenue per share
	$rev_per_share=get_indicator_value($symbol, 'revenue_per_share',$date);
	
	//write_log("calc_price_to_sales","rev_per_share=".$rev_per_share);
	
	//get share price
	$price=get_share_price($symbol,$date);
	
	if ($rev_per_share>0){
		//calculate price sales ratio
		$price_sales_ratio=$price/$rev_per_share;
	
		//write_log("calc_price_to_sales","price_sales_ratio".$price_sales_ratio);
	
		//update statistics
		update_statistic($symbol, 'price_sales_ratio', $price_sales_ratio, $date);
	}
}
	
	
//Calculate Buyback Yield
function calc_buyback_yield($date,$symbol){

	//write_log("calc_buyback_yield","symbol=".$symbol." Date=".$date);
	
    //Get Common Stock for Latest period
 	$rows = query(" SELECT fsv.period_id, value
					FROM `financial_statement_values` fsv,
					financial_statement_periods fsp
					WHERE fsp.period_id=fsv.period_id 
					and symbol=?
					and fsv.item_name='common_stock'
					and fsp.end_date = (select max(fsp.end_date)
					                 from `financial_statement_values` fsv,
					                 financial_statement_periods fsp
									WHERE fsv.period_id=fsp.period_id 
									and symbol=?
									and fsv.item_name='common_stock'
									and fsp.end_date<=?)
					limit 1",$symbol,$symbol,$date);
	if (count($rows)>0){
				
		$common_stock=$rows[0]['value'];
		
		//write_log("calc_buyback_yield","common_stock".$common_stock);
	
		//Set Previous year Date
		$prevYrDate=date_create_from_format('Y-m-d', $date);
	    $prevYrDate->sub(new DateInterval('P11M'));
		$prevYrDate = date_format($prevYrDate,'Y-m-d');
		
		//write_log("calc_buyback_yield","prevYrDate".$prevYrDate);
		
		//Get Common Stock for prior year
		
	    $rows = query(" SELECT fsv.period_id, value
						FROM `financial_statement_values` fsv,
						financial_statement_periods fsp
						WHERE fsp.period_id=fsv.period_id 
						and symbol=?
						and fsv.item_name='common_stock'
						and fsp.end_date = (select max(fsp.end_date)
						                 from `financial_statement_values` fsv,
						                 financial_statement_periods fsp
										WHERE fsv.period_id=fsp.period_id 
										and symbol=?
										and fsv.item_name='common_stock'
										and fsp.end_date<=?)
						limit 1",$symbol,$symbol,$prevYrDate);
		if (count($rows)>0){
										
			$common_stock_prior_yr=$rows[0]['value'];
			
			//write_log("calc_buyback_yield","common_stock".$common_stock_prior_yr);
			
			if ($common_stock_prior_yr>0){
			
				//Buyback Yield
				$buyback_yield=($common_stock_prior_yr-$common_stock)/$common_stock_prior_yr;
			        
			
				//update statistics
				update_statistic($symbol, 'buyback_yield', $buyback_yield, $date);
			}
		}
	}
}
	
//Calculate Shareholder Yield
/**
 * What a company handed back to its owners, as a fraction of its own price.
 *
 * Dividends plus buybacks: two ways of doing the same thing, so ranking them
 * separately would score the choice rather than the amount. Both components
 * are written by python/get_api_stats.py, which runs before this does, and
 * both have already been converted out of the company's reporting currency -
 * so nothing is converted here, or the rate would be applied twice.
 *
 * This wrote a constant zero for every share for years. Neither component was
 * mapped to anything, so get_indicator_value() found nothing for either, and
 * the function added nought to nought and stored it. shareholder_yield was
 * then one of the six names in the value score's hard-coded list, where a row
 * with no percentile counts as the neutral 50 - so it diluted every share's
 * score by the same amount and said nothing about any of them.
 *
 * A zero is a real answer here: a company that pays no dividend and buys back
 * no stock has a shareholder yield of nought. A missing one is not, so when
 * neither component exists the row is cleared rather than written as zero -
 * otherwise the old constant comes straight back.
 */
function calc_shareholder_yield($date,$symbol){

	//write_log("calc_shareholder_yield","symbol=".$symbol." Date=".$date);

	$buyback_yield = get_indicator_value($symbol, 'buyback_yield', $date);
	$div_yield     = get_indicator_value($symbol, 'div_yield', $date);

	if (!is_numeric($buyback_yield) && !is_numeric($div_yield)){
		clear_statistic($symbol, 'shareholder_yield', $date);
		return;
	}

	$shareholder_yield = (is_numeric($buyback_yield) ? (float) $buyback_yield : 0.0)
	                   + (is_numeric($div_yield)     ? (float) $div_yield     : 0.0);

	update_statistic($symbol, 'shareholder_yield', $shareholder_yield, $date);
}
	
/* calc_piotroski_fscore(), calc_altman_zscore() and
   calc_altman_zscore_nonman() were here, with their readers
   get_piotroski_fscore(), get_altman_zscore(), get_altman_zscore_nonman(),
   get_piotroski_variables(), get_altman_variables() and
   get_altman_nonman_variables().

   All of them worked from financial_statement_values, which is empty on the
   live database, so no score was ever written, nothing read one, and the
   quote page's health card had been commented out for years with its three
   modals hanging off onclick handlers that do not exist. The provider
   computes both scores from statements it already holds - see
   sql/fmp_scores.sql - so they arrive as piotroski_score and altman_z like
   any other indicator, and get_health_scores() reads them back.

   health_indicators and its variables lookup are left in place: nothing
   writes them now, and dropping a table is a different decision from
   deleting the code that filled it. */

function get_share_price($symbol, $date){
	
	write_log('get_share_price',"symbol=$symbol , date=$date");
	$rows=query("SELECT price FROM `historical_prices` WHERE symbol=? and exchange=? and date=(select max(date) from historical_prices where symbol=? and exchange=? and date <= ?)",$symbol,session_exchange(),$symbol,session_exchange(),$date );
	
	
	//write_log('get_share_price',"rows returned=".count($rows));
	
	//write_log('get_share_price',"price=".$rows[0]['price']);
	
	if (count($rows)>0)
		return $rows[0]['price'];
}


/* get_sector_average() and get_industry_average() were here. Their only
   caller was calc_implied_valuation(), which uses peer_median() now - a
   leave-one-out median cannot be stored per sector, so it is worked out per
   share instead. statistic_averages is still written and still read, by the
   sector and market columns on the quote page, through the queries in
   get_value_statistics() and its siblings. */

//Implied Valuation
function calc_implied_valuation ($date,$symbol){
		
	write_log('calc_implied_valuation','symbol='.$symbol);

	/* Clear this date's working before recalculating it. The inserts below had
	   no delete in front of them, so every run appended another copy: eight
	   runs of get_statistics.php left 15,888 rows in price_valuation where
	   there are 1,986 distinct ones, and the AI outlook was sent twenty-four
	   relative_to_sector rows for RR instead of three.

	   Before the fund check, not after it, so a symbol that has moved into a
	   fund sector loses the rows it was given as a company. The two
	   relative_* statistics go the same way: they are written only when a
	   valuation can be worked out, so without clearing them first a share that
	   stops qualifying keeps yesterday's answer for ever. */
	query("delete from price_valuation where symbol=? and date=?", $symbol, $date);
	clear_statistic($symbol, 'relative_valuation', $date);
	clear_statistic($symbol, 'relative_industry_valuation', $date);

	/* Funds are out of the sector and industry medians this works from, so a
	   fund has no comparison set of its own: its sector is all funds and now
	   has no median at all, and valuing a trust off the median price/book of
	   the operating companies that happen to share its industry is the
	   comparison this change exists to remove. */
	if (is_fund($symbol)){
		write_log('calc_implied_valuation','skipped: '.$symbol.' is an investment fund');
		return;
	}
	
	
	//List of Indicators
	//$indicators=['price_book_ratio','enterprise_value_to_ebitda','price_sales_ratio','peg_current_year','price_eps_ratio_curr_year'] ;
	$indicators=['price_book_ratio','enterprise_value_to_ebitda','price_sales_ratio','pe','price_free_cash_flow_per_share'] ;
	
	//Initialize array of indicator values
	$indicator_values=[];
	
	//Get Share Price
	$price=get_share_price($symbol,$date);
	
	write_log('calc_implied_valuation','price='.$price);
	
	if ($price>0){
		
		//Get Share Sector
		$rows = query("select sector from stock_symbols where symbol=? and exchange=?",$symbol,session_exchange());
		
		if (count($rows)>0){
			
			$sector=$rows[0]['sector'];
			
			write_log('calc_implied_valuation','sector='.$sector);
			
			//Loop though indicators
		
			foreach ($indicators as $indicator){
				
				//get Indicator Ratio
		    	$indicator_value = get_indicator_value($symbol, $indicator,$date);
				
				write_log('calc_implied_valuation',$indicator.'='.$indicator_value);
		
				if (isset($indicator_value)&&($indicator_value>0)){
					
					//Get the median of the share's sector peers, excluding
					//the share itself. Null means too few peers to compare
					//against, which is not the same as a zero.
					$avg=peer_median($indicator,$date,'sector',$sector,$symbol);
				
					write_log('calc_implied_valuation','avg='.$avg);
				
					if (isset($avg)&&($avg>0)){
					
						//calc implied valuation
						$value=round($avg/$indicator_value*$price);
					
						write_log('calc_implied_valuation','value='.$value);
						
						//Add valuation to array
						array_push($indicator_values, $value);
						
						//Insert valuation for indicator into table
						query("insert into price_valuation(symbol,date,indicator,share_stat,sector_stat, type, value) values (?,?,?,?,?,?,?)",$symbol,$date,$indicator,$indicator_value,$avg,'relative_sector',$value);
					
					}
					
				}
		
			}
			
			//Get average for all indicators
			
			if (count($indicator_values)>0){
			
				$valuation=0;
				
				foreach ($indicator_values as $value)
					$valuation=$valuation+$value;
				
				$valuation=round($valuation/count($indicator_values));
				
				write_log('calc_implied_valuation','valuation='.$valuation);
			
			}
		
			//Update valuation
			if (isset($valuation))
				update_statistic($symbol, 'relative_valuation', $valuation, $date);
		}

		//Get Share Industry
		$rows = query("select industry from stock_symbols where symbol=? and exchange=?",$symbol,session_exchange());
		
		if (count($rows)>0){
			
			$industry=$rows[0]['industry'];
			
			//write_log('calc_implied_valuation','industry='.$industry);
			
			//Reset indicator values array
			$indicator_values=[];

			/* And the valuation itself. It is only assigned when there is
			   something to average, so the sector figure above was still in
			   scope here: an industry with nothing to compare against had the
			   sector's implied price written as its own. With a minimum peer
			   count that case is no longer rare. */
			unset($valuation);
			
			//Loop though indicators
		
			foreach ($indicators as $indicator){
				
				//get Indicator Ratio
		    	$indicator_value = get_indicator_value($symbol, $indicator,$date);
				
				//write_log('calc_implied_valuation',$indicator.'='.$indicator_value);
		
				if (isset($indicator_value)&&($indicator_value>0)){
					
					//Get the median of the share's industry peers, excluding
					//the share itself.
					$avg=peer_median($indicator,$date,'industry',$industry,$symbol);
				
					//write_log('calc_implied_valuation','avg='.$avg);
				
					if (isset($avg)&&($avg>0)){
					
						//calc implied valuation
						$value=round($avg/$indicator_value*$price);
					
						//write_log('calc_implied_valuation','value='.$value);
						
						//Add valuation to array
						array_push($indicator_values, $value);
						
						//Insert valuation for indicator into table
						//query("update price_valuation set industry_stat=?, type=?, value=? where symbol=? and date=? and indicator=?",$avg,'relative_industry',$value,$symbol,$date,$indicator);
						query("insert into price_valuation(symbol,date,indicator,share_stat,industry_stat, type, value) values (?,?,?,?,?,?,?)",$symbol,$date,$indicator,$indicator_value,$avg,'relative_industry',$value);
						
					}
					
				}
		
			}
			
			//Get average for all indicators
			
			if (count($indicator_values)>0){
			
				$valuation=0;
				
				foreach ($indicator_values as $value)
					$valuation=$valuation+$value;
				
				$valuation=round($valuation/count($indicator_values));
				
			//	write_log('calc_implied_valuation','valuation='.$valuation);
			
			}
			
			//Update valuation
			if (isset($valuation))
				update_statistic($symbol, 'relative_industry_valuation', $valuation, $date);
			
		}
	}
}

function get_momentum_topten(){
	
	//Get statistics job date
	$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
	$job_date=$rows[0]['job_date'];
	
	//Get top ten 
	$rows=query("select ss.symbol, ss.name, s.value from statistics s, stock_symbols ss where s.symbol=ss.symbol and indicator='momentum_score' and date=? and ss.exchange=? order by value DESC limit 10",$job_date,session_exchange());
	
	return $rows;
}

function get_value_topten(){
	
	//Get statistics job date
	$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
	$job_date=$rows[0]['job_date'];
	
	//Get top ten 
	/* Funds are not scored, so there should be no fund rows to find.
	   The filter is here as well because the dashboard reads the newest
	   statistics date, and between deploying this and the next nightly
	   run that date still holds the rows the old code wrote. */
	$rows=query("select ss.symbol, ss.name, s.value from statistics s, stock_symbols ss where s.symbol=ss.symbol and indicator='value_score' and date=? and ss.exchange=?"
	            .not_fund_sql('ss.symbol')
	            ." order by value DESC limit 10",$job_date,session_exchange());
	
	return $rows;
}	

function get_quality_topten(){
	
	//Get statistics job date
	$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
	$job_date=$rows[0]['job_date'];
	
	//Get top ten 
	/* Funds are not scored, so there should be no fund rows to find.
	   The filter is here as well because the dashboard reads the newest
	   statistics date, and between deploying this and the next nightly
	   run that date still holds the rows the old code wrote. */
	$rows=query("select ss.symbol, ss.name, s.value from statistics s, stock_symbols ss where s.symbol=ss.symbol and indicator='quality_score' and date=? and ss.exchange=?"
	            .not_fund_sql('ss.symbol')
	            ." order by value DESC limit 10",$job_date,session_exchange());
	
	return $rows;
}	

function get_overall_topten(){
	
	//Get statistics job date
	$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
	$job_date=$rows[0]['job_date'];
	
	//Get top ten 
	/* Funds are not scored, so there should be no fund rows to find.
	   The filter is here as well because the dashboard reads the newest
	   statistics date, and between deploying this and the next nightly
	   run that date still holds the rows the old code wrote. */
	$rows=query("select ss.symbol, ss.name, s.value from statistics s, stock_symbols ss where s.symbol=ss.symbol and indicator='overall_score' and date=? and ss.exchange=?"
	            .not_fund_sql('ss.symbol')
	            ." order by value DESC limit 10",$job_date,session_exchange());
	
	return $rows;
}	

function get_sector_companies($sector){
	//Get statistics job date
	$rows=query("select date(max(job_date)) job_date from jobs where job_name='get_statistics_asof'");
	$job_date=$rows[0]['job_date'];
	
	//Get companies
	$rows = query("select ss.symbol, ss.name, 
(select s.value from statistics s where s.symbol=ss.symbol and indicator = 'quality_score' and s.date=?) quality_score,
(select s.value from statistics s where s.symbol=ss.symbol and indicator = 'momentum_score' and s.date=?) momentum_score,
(select s.value from statistics s where s.symbol=ss.symbol and indicator = 'value_score' and s.date=?) value_score,
(select s.value from statistics s where s.symbol=ss.symbol and indicator = 'overall_score' and s.date=?) overall_score
from stock_symbols ss where enabled='Y' and sector=? and ss.exchange=?",$job_date,$job_date,$job_date,$job_date,$sector,session_exchange());
	
	return($rows);
		
	
}

function get_active_stocks($exchange){
	//Get symbols
    $symbols = query("select symbol from stock_symbols where enabled='Y' and exchange=? order by symbol",$exchange);

    for ($i=0;$i<count($symbols);$i++ )	{
    	$symbolList[]=$symbols[$i]["symbol"];	
    }
    
    return $symbolList;
}


    /**
     * Returns a stock by symbol (case-insensitively) else false if not found.
     */
    function lookup_key_ratios($symbol)
    {
        // reject symbols that start with ^
        if (preg_match("/^\^/", $symbol))
        {
            return false;
        }

        // reject symbols that contain commas
        if (preg_match("/,/", $symbol))
        {
            return false;
        }
		
		//remove .L from symbol
		if (strpos($symbol,'.')>0){
			$symbol=substr($symbol,0,strpos($symbol,'.'));
		};
		
		//Get Default Exchange
		$exchange=session_exchange();

        // open connection to GOOGLE
        $string = file_get_contents("https://www.worldtradingdata.com/api/v1/stock?symbol=$symbol"."&api_token=ALFvINqaRaN1WSsJqL5CA6BGG79Hooi0siMCcHi1G5PUWm16f6eMa8MYD8Bi");

        
        // get uncommented json string
		//$arrMatches = explode('// ', $string); 
		
		
		
		// ensure symbol was found
        //if (count($arrMatches)<2)
        //{
         //   return false;
       // }
       
       
		// decode json
		//$arrJson = json_decode($arrMatches[1], true)[0]; 
		
		$arrJson = json_decode($string, true);
		
		//Name
		if (isset($arrJson["data"][0]["name"]))
			$name=$arrJson["data"][0]["name"];
		else {
			$name=null;
		}
		
		//Price
		if (isset($arrJson["data"][0]["price"]))
			$price=$arrJson["data"][0]["price"];
		else {
			$price=null;
		}
		
		//Change
		if (isset($arrJson["data"][0]["day_change"]))
			$change=$arrJson["data"][0]["day_change"];
		else {
			$change=null;
		}
		
		//52 Week Low
		if (isset($arrJson["data"][0]["52_week_low"]))
			$fifty_two_week_low=$arrJson["data"][0]["52_week_low"];
		else {
			$fifty_two_week_low=null;
		}
				
		//52 Week High
		if (isset($arrJson["data"][0]["52_week_high"]))
			$fifty_two_week_high=$arrJson["data"][0]["52_week_high"];
		else {
			$fifty_two_week_high=null;
		}
		
		//Market Cap
		if (isset($arrJson["data"][0]["market_cap"]))
			$market_cap=$arrJson["data"][0]["market_cap"];
		else {
			$market_cap=null;
		}
		
		
		
		/*
		//print_r($arrJson);
		if (isset($arrJson["keyratios"][0]["ttm"]))
			$net_profit_margin=$arrJson["keyratios"][0]["ttm"];
		else {
			$net_profit_margin=null;
		}
		
		if (isset($arrJson["keyratios"][1]["ttm"]))
			$operating_margin=$arrJson["keyratios"][1]["ttm"];
		else {
			$operating_margin=null;
		}
		
		if (isset($arrJson["keyratios"][3]["ttm"]))
			$roa=$arrJson["keyratios"][3]["ttm"];
		else {
			$roa=null;
		}
			
		
		if (isset($arrJson["keyratios"][4]["ttm"]))
			$roe_ttm=$arrJson["keyratios"][4]["ttm"];
		else 
			$roe_ttm=null
			;
		
		//print("net profit margin".$net_profit_margin);

		$symbol = $arrJson["symbol"];
		$price = $arrJson["l"];
		*/
        // download first line of CSV file
       // $data = fgetcsv($handle, 1000, ",");
       // if ($data === false )
       // {
       //     return false;
       // }
        
        // ensure symbol was found
        write_log("functions.php", "symbol=".$arrJson["data"][0]["symbol"]);
        
        if (!isset($arrJson["data"][0]["symbol"]))
        {
            return false;
        }
        
        $share_info=[
            "symbol" => $symbol,
            "name" => $name,
            "price" => convert_value($price),
            "shares" => [],//convert_value($arrJson["shares"]),
            "change" => convert_value($change),
            "day_range" => [],
            "52w_low" => convert_value($fifty_two_week_low),
            "52w_high" => convert_value($fifty_two_week_high),
            "pe" => [],//$arrJson["pe"],
            "profit_margin" => [],//convert_value($net_profit_margin),
            "operating_margin"=>[],//convert_value($operating_margin),
            "roa"=>[],//convert_value($roa),
            "roe_ttm"=>[],//convert_value($roe_ttm)
            "market_cap"=>change_number($market_cap)
        ];
        

        // return stock as an associative array
        return $share_info;
    }


?>
