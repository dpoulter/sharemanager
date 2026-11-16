<?php

	/**
	 * quote_view.php - the facts at the top of the quote page.
	 *
	 * The header used to read get_share_info(), which calls the price API
	 * for the quote and stock_info for the fundamentals. Three of its six
	 * figures - market capitalisation, shares in issue and the 52 week range
	 * - were blank on every share, because they come from a provider
	 * endpoint this plan does not include. They are all derivable from what
	 * is already stored, so they are derived here: the range from the price
	 * history, the capitalisation from the shares in issue and the close.
	 *
	 * Reading the stored close rather than the live quote also means the
	 * page agrees with the rest of the application. A header quoting a live
	 * price above a chart drawn from stored closes is two different prices
	 * on one screen.
	 */

	/**
	 * Everything in the band under the company name.
	 *
	 * Prices are pence, as stored. Market capitalisation is pounds, because
	 * a market capitalisation in pence is a number nobody has ever wanted.
	 */
	function quote_header($symbol, $exchange = null) {

		$exchange = $exchange === null ? session_exchange() : $exchange;

		$head = ['symbol' => $symbol, 'name' => null, 'market' => null,
		         'sector' => null, 'logo' => null,
		         'price' => null, 'as_of' => null,
		         'change' => null, 'change_pct' => null,
		         'low_52w' => null, 'high_52w' => null, 'range_pct' => null,
		         'shares' => null, 'market_cap' => null];

		$rows = query("select name, market, sector, logo from stock_symbols
		                where symbol=? and exchange=?", $symbol, $exchange);
		if (!count($rows)) { return $head; }

		$head['name']   = $rows[0]['name'];
		$head['market'] = $rows[0]['market'];
		$head['sector'] = $rows[0]['sector'];
		/* A logo is shown only if the provider supplied one. The page used to
		   draw an <img> whatever the column held, so a share with no logo got
		   a grey box with the company name as its alt text. */
		$logo = trim((string) $rows[0]['logo']);
		$head['logo'] = ($logo === '' || strtoupper($logo) === 'N/A') ? null : $logo;

		/* The last two closes: the price, and what it did on the day. Two
		   stored dates rather than "today and yesterday", so a Monday is
		   measured against the Friday. */
		$closes = query("select date, price from historical_prices
		                  where symbol=? and exchange=? and price is not null
		                  order by date desc limit 2", $symbol, $exchange);

		if (count($closes)) {
			$head['price'] = (float) $closes[0]['price'];
			$head['as_of'] = (string) $closes[0]['date'];
			if (count($closes) > 1 && (float) $closes[1]['price'] != 0.0) {
				$head['change']     = (float) $closes[0]['price'] - (float) $closes[1]['price'];
				$head['change_pct'] = ($head['change'] / (float) $closes[1]['price']) * 100;
			}
		}

		/* The 52 week range, from the stored history rather than from the
		   provider's Technicals block, which this plan does not return. A
		   year of closes is a year of closes. */
		if ($head['as_of'] !== null) {
			$range = query("select min(price) low, max(price) high
			                  from historical_prices
			                 where symbol=? and exchange=? and price is not null
			                   and date > date_sub(?, interval 1 year) and date <= ?",
			               $symbol, $exchange, $head['as_of'], $head['as_of']);
			if (count($range) && $range[0]['low'] !== null) {
				$head['low_52w']  = (float) $range[0]['low'];
				$head['high_52w'] = (float) $range[0]['high'];
				$span = $head['high_52w'] - $head['low_52w'];
				/* Where today sits in the range, as a percentage, for the bar.
				   A flat year is 50%: it is neither near the high nor near the
				   low, and dividing by nothing is not an answer. */
				$head['range_pct'] = $span > 0
				                   ? max(0, min(100, (($head['price'] - $head['low_52w']) / $span) * 100))
				                   : 50.0;
			}
		}

		/* Shares in issue, and the capitalisation that follows from it.
		   share_fundamentals() reads the provider's own figures where they
		   exist; the calculated indicator is the fallback, and the
		   capitalisation is worked out rather than taken, because a stored
		   capitalisation goes stale the moment the price moves. */
		$fundamentals = share_fundamentals($symbol);
		$shares = (isset($fundamentals['shares']) && is_numeric($fundamentals['shares']))
		        ? (float) $fundamentals['shares'] : null;
		if ($shares === null && $head['as_of'] !== null) {
			$indicator = get_indicator_value($symbol, 'shares_outstanding', $head['as_of']);
			if (is_numeric($indicator)) { $shares = (float) $indicator; }
		}
		$head['shares'] = $shares;

		if ($shares !== null && $head['price'] !== null) {
			//Pence times shares is pence; the capitalisation is pounds.
			$head['market_cap'] = $shares * $head['price'] / 100;
		}
		else if (isset($fundamentals['market_cap']) && is_numeric($fundamentals['market_cap'])) {
			$head['market_cap'] = (float) $fundamentals['market_cap'];
		}

		return $head;
	}

	/**
	 * One named indicator out of the structure share_lookup() returns.
	 *
	 * The header shows four ratios that are also in the Ratios tab. Pulling
	 * them out of the same structure rather than querying again means the
	 * two cannot disagree about what this share's P/E is, which they would
	 * the first time one of them was given its own as-of date.
	 */
	function quote_indicator($quote, $name) {
		if (!is_array($quote)) { return null; }
		foreach ($quote as $category) {
			foreach ($category['indicators'] as $indicator) {
				if ($indicator['name'] === $name) { return $indicator; }
			}
		}
		return null;
	}

	/** Its value, or null. */
	function quote_indicator_value($quote, $name) {
		$indicator = quote_indicator($quote, $name);
		return ($indicator === null || !is_numeric($indicator['value']))
		     ? null : (float) $indicator['value'];
	}

	/**
	 * What a relative valuation actually says, in words.
	 *
	 * The page showed "Relative to Industry" and a red badge reading 341.
	 * 341 is the price the share would be if it were valued like its peers,
	 * in pence, and the badge was red because that is a quarter of what it
	 * costs - which is to say the badge was red for being a bargain. Neither
	 * the number nor the colour said any of that.
	 *
	 * Returns [words, class] where class is the standard move colouring:
	 * implied above the price is green, below is red.
	 */
	function valuation_words($valuation, $price = null) {

		/* A zero implied price is not a valuation of nothing, it is a
		   valuation that could not be worked out: every peer ratio was
		   missing, or the share's own figure was. Printed as a price it
		   reads "0.0p, 100% below", which is a statement about the company
		   rather than about the data. */
		if (!is_array($valuation) || $valuation['value'] === null
		    || (float) $valuation['value'] <= 0.0) {
			return [null, 'sm-flat'];
		}

		/* The price to compare against is the one the page is showing.
		   get_valuation() reads its own as-of date - yesterday, whatever
		   day that is - so its copy of the price can be a different day's
		   close, and two prices for one share on one page is the confusion
		   this is meant to end. */
		if (!is_numeric($price)) { $price = $valuation['price'] ?? null; }
		if (!is_numeric($price) || (float) $price == 0.0) { return [null, 'sm-flat']; }

		$implied = (float) $valuation['value'];
		$price   = (float) $price;
		$gap     = (($implied / $price) - 1) * 100;

		$words = 'Implied price ' . fmt_pence($implied) . ' against ' . fmt_pence($price)
		       . ', ' . fmt_pct(abs($gap)) . ' ' . ($gap >= 0 ? 'above' : 'below');

		return [$words, move_class($gap)];
	}

?>
