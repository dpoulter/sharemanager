<?php

    // configuration
    require("../includes/config.php");
    require_once("../includes/rns.php");
    require_once("../includes/quote_view.php");

	
	// if form was submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST")
    {
        // validate submission
        //is_string, because symbol[] in the request body makes this an array
        //and casting one to string is a warning rather than a search term.
        if (empty($_POST["symbol"]) || !is_string($_POST["symbol"]))
        {
            apologize("You must enter a symbol.");
        }
		else
		{
			/* What was typed in the navbar search box.
			
			   This used to be substr($term, 0, strpos($term, ' ')) - the text
			   before the first space. A bare symbol has no space, strpos()
			   returns false, substr() reads that as 0 and the symbol came out
			   empty, so "III" was an invalid symbol and only "III 3i Group"
			   worked. find_symbols() accepts a bare ticker in any case, the
			   "SYMBOL Name" the suggestions produce, the dotted form London
			   tickers are written in, or part of a company name. */
			$matches=find_symbols($_POST["symbol"]);
			
			if (count($matches)==0)
			{
				apologize("Invalid Symbol.");
			}
			
			/* More than one share fits. Offering the choice beats guessing:
			   picking the first would show a quote for a share nobody asked
			   for, which is worse than one more click. */
			if (count($matches)>1)
			{
				render("symbol_matches.php",
				       ["title"=>"Search","term"=>trim($_POST["symbol"]),"matches"=>$matches]);
				exit;
			}
			
			//Exactly one, so the term named a share.
			$symbol=$matches[0]["symbol"];
			
			//timespan
			if (isset($_POST["timespan"]))
				$timespan=$_POST["timespan"];
			else
				$timespan="6m";
				
			if (isset($_POST["type"]))
				$type=$_POST["type"];
			else
				$type="l";
		}
	}
	
	//Check if symbol was passed to form
	else {
			
		if (isset($_GET["symbol"]))
			$symbol=$_GET["symbol"];
			
		if (isset($_GET["screen_id"]))
			$screen_id=$_GET["screen_id"];
			
		if (isset($_GET["timespan"]))
			$timespan=$_GET["timespan"];
		else
			$timespan="6m";
			
		if (isset($_GET["type"]))
			$type=$_GET["type"];
		else
			$type="l";
			
	}
	
	if (isset($symbol)){
      
      //Get Stock Quote
     // write_log("quote.php","1");
		$share_info = get_share_info($symbol);
		
		//write_log("quote.php","2");
           
		if ($share_info!==false) {		
			$chart=null; //"http://chart.finance.yahoo.com/z?s=$symbol&t=$timespan&q=$type&l=on&z=l&p=m20,m50,m100,m200";	
			
			write_log("quote.php","3");
						
			$quote = share_lookup($symbol);  
			
			write_log("quote.php","4");
			
			
			/* The Financial Statements and Ratings panes are gone, and with
			   them seven queries a page. Neither was reachable: the tab list
			   offers Profile, News and Ratios, and those two panes sat in
			   the markup with nothing to open them. The functions behind
			   them - income_statement(), ratings(), get_value_statistics()
			   and the rest - are still in share_functions.php, so a tab can
			   be given back to them in a line. */
			$valuation=get_valuation($symbol);
			$industry_valuation=get_industry_valuation($symbol);
			write_log("quote.php","8");
			$scores = get_scores($symbol);
			//$scores = null;
			/* Null for a sterling reporter: nothing was converted, so the page
			   says nothing. For everyone else the ratios on this page have
			   been multiplied by the rate on the way in, and a figure that has
			   been adjusted should say so. */
			$currency_note = report_currency_note($symbol);
			write_log("quote.php","13");
			$valueranks=get_value_rank($symbol);
			//$valueranks=null;
			write_log("quote.php","14");
			$momentumranks=get_momentum_rank($symbol);
			//$momentumranks=null;
			write_log("quote.php","15");
			$qualityranks=get_quality_rank($symbol);
			//$qualityranks=null;
			write_log("quote.php","16");
/* Piotroski and Altman come from the provider now; the local
			   calculations that used to fill them read an empty table. */
			$health_scores=get_health_scores($symbol);

			write_log("quote.php","Get relative_sector_valuations");
			$relative_sector_valuations=get_relative_to_sector($symbol); 

			write_log("quote.php","Get get_relative_to_industry");
			$relative_industry_valuations=get_relative_to_industry($symbol);
			
			
			write_log("quote.php","Get category_indicators");
			$category_indicators=get_indicators();

			/* The News tab. Stored regulatory announcements, newest first -
			   the company's own words, already read and scored by the nightly
			   job - rather than a live Google News call on every page load.
			   Nothing is fetched while somebody waits for the page, and the
			   tab shows the same rows as the "In the news" page. */
			$announcements = rns_recent($symbol, 10);

			/* The band under the company name. Stored closes rather than the
			   live quote, so the price above the chart is the price the chart
			   ends on, and the three figures that were blank on every share -
			   capitalisation, shares, the 52 week range - are derived from
			   the price history. */
			$head = quote_header($symbol);

			/* The four ratios in that band come out of the same structure the
			   Ratios tab reads, so the two cannot disagree. */
			$pe         = quote_indicator_value($quote, 'pe');
			$price_book = quote_indicator_value($quote, 'price_book_ratio');
			$div_yield  = quote_indicator_value($quote, 'div_yield');


			//render form
			write_log("quote.php","Start render form");

			$view = ["title" => session_exchange().":".$symbol, "symbol"=>$symbol,
			         "head"=>$head, "pe"=>$pe, "price_book"=>$price_book, "div_yield"=>$div_yield,
			         "valuation"=>$valuation, "industry_valuation"=>$industry_valuation,
			         "relative_sector_valuations"=>$relative_sector_valuations,
			         "relative_industry_valuations"=>$relative_industry_valuations,
			         "share_info"=> $share_info, "quote" => $quote, "chart"=>$chart,
			         "timespan"=>$timespan, "type"=>$type,
			         "scores"=>$scores, "valueranks"=>$valueranks, "momentumranks"=>$momentumranks,
			         "qualityranks"=>$qualityranks, "announcements"=>$announcements,
			         "health_scores"=>$health_scores, "currency_note"=>$currency_note];

			/* screen_id used to pick between two copies of this list that
			   differed in one key. The Back button it fed is gone - the
			   browser and the navbar already do that - so it is carried only
			   so a screen can link here and be linked back from. */
			if (isset($screen_id)) { $view["screen_id"] = $screen_id; }

			render("quote.php", $view);
			
			write_log("quote.php","End render form");
		
		}
		else
			//quote_form.php is the dashboard template and needs five variables
			//index.php builds for it. Rendering it from here passed only a
			//title, so every panel foreach'd over an undefined variable.
			//index.php apologises for an unknown symbol; do the same.
			apologize("Invalid Symbol.");
    }

	else {  
		 //get stock symbols
        //$stock_symbols = query("SELECT symbol, description FROM stock_symbols where enabled='Y'");
        // else render form
        //No symbol to quote. The dashboard lives at index.php, which knows how
		//to populate it; rendering its template from here cannot.
		  redirect("index.php");
    }

?>
