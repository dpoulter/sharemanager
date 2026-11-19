<?php

	/**
	 * The broker connection, as data.
	 *
	 * includes/t212_client.php knows how to ask Trading 212 a question;
	 * this file knows what the answers mean, which of our shares they are
	 * about, and how to store them. The job (includes/sync_broker.php) and
	 * the page (public/broker.php) both call broker_sync(), so the two cannot
	 * disagree about what a sync does.
	 *
	 * READ ONLY. Nothing here places, cancels or amends an order, and the
	 * client it calls has no method that could.
	 *
	 * Three things the API documentation does not state, and which are
	 * therefore worked out rather than assumed:
	 *
	 *   the field names. Every read goes through broker_field(), which takes
	 *   the spellings that are plausible and records a problem when the
	 *   response carries none of them, instead of quietly storing null;
	 *
	 *   the ticker format for London shares. The only documented example is
	 *   AAPL_US_EQ. broker_ticker_code() takes the shapes apart rather than
	 *   betting on one, and a code that fits more than one of our symbols is
	 *   left unmatched - a wrong match values the wrong company's holding;
	 *
	 *   whether prices are pounds or pence. historical_prices is pence and
	 *   the strategy engine is pounds, so this is a factor of 100 on every
	 *   figure. broker_price_unit() decides it by comparing the broker's
	 *   price for a share against our own close for the same share, and a
	 *   position whose unit cannot be decided stores null rather than a
	 *   number that might be a hundred times wrong.
	 *
	 * tools/t212_probe.php exists to settle all three against real responses.
	 * Once they are settled, the candidate lists below can be cut to the one
	 * spelling that is real and T212_PRICE_UNIT set to the right answer; the
	 * guards stay, because a beta API can change them back.
	 */

	require_once("constants.php");
	require_once("t212_client.php");

	/* BROKER_T212 lives in constants.php: the strategy engine needs the
	   same name for an account's mode and does not include this file. */

	/** Whether sql/broker.sql has been applied. */
	function broker_ready() {

		static $known = null;
		if ($known !== null) { return $known; }

		$rows = query("select count(*) n from information_schema.tables
		                where table_schema=database()
		                  and table_name in ('broker_instruments','broker_account_values',
		                                     'broker_positions','broker_orders',
		                                     'broker_dividends','broker_sync_runs')");
		$known = count($rows) && (int) $rows[0]['n'] === 6;
		return $known;
	}

	/**
	 * One value out of a response, by a dotted path.
	 *
	 * The account summary nests: cash is cash.availableToTrade and the
	 * invested value is investments.currentValue. A reader that only knows
	 * about top-level keys is handed the whole object and makes a null of
	 * it, which is how the first live sync stored a blank cash balance
	 * beside a correct total.
	 *
	 * Returns the value, or null if any step of the path is missing.
	 */
	function broker_path($row, $path) {

		$node = $row;
		foreach (explode('.', (string) $path) as $step) {
			if (!is_array($node) || !array_key_exists($step, $node)) { return null; }
			$node = $node[$step];
		}
		return $node;
	}

	/**
	 * A field out of a response, by any of the paths it might have.
	 *
	 * Returns [value, path]:
	 *
	 *   [null, null]   none of the paths is in the response;
	 *   [null, path]   the path is there and holds an object or an empty
	 *                  string, which is not a value - the caller can say so
	 *                  by name rather than storing a silent null;
	 *   [value, path]  the value, and which spelling carried it.
	 *
	 * Nought is a value. investments.currentValue is 0 on an account that
	 * holds nothing, and reading that as "missing" would report a problem
	 * with a figure that is perfectly correct.
	 */
	function broker_field($row, array $candidates) {

		if (!is_array($row)) { return [null, null]; }

		foreach ($candidates as $name) {

			$value = broker_path($row, $name);
			if ($value === null) { continue; }

			if (is_array($value) || $value === '') { return [null, $name]; }
			return [$value, $name];
		}
		return [null, null];
	}

	/**
	 * Why a field could not be read, in a sentence, or '' if it could.
	 *
	 * The first live sync stored cash as null and called the run a success,
	 * because nothing checked. A field this application needs and cannot
	 * find is a problem with the data, and the page has to say which field
	 * and what the response had instead.
	 */
	function broker_field_problem($row, $what, $label, $where) {

		[$value, $name] = broker_field($row, broker_fields($what));
		if ($value !== null) { return ''; }

		$keys = is_array($row) ? implode(', ', array_keys($row)) : '(not an object)';

		return $name === null
		     ? "the " . $where . " carried none of the names this knows for "
		       . $label . " (" . implode(', ', broker_fields($what)) . "). It has: " . $keys
		     : "the " . $where . "'s " . $name . " is an object rather than a "
		       . $label . ", so nothing was stored for it.";
	}

	/** A number out of a response, or null. Strings are numbers here too. */
	function broker_number($row, array $candidates) {

		[$value] = broker_field($row, $candidates);
		if ($value === null) { return null; }
		if (is_bool($value) || is_array($value)) { return null; }
		return is_numeric($value) ? (float) $value : null;
	}

	/** A datetime out of a response, as 'Y-m-d H:i:s' UTC, or null. */
	function broker_datetime($row, array $candidates) {

		[$value] = broker_field($row, $candidates);
		if ($value === null || is_array($value)) { return null; }

		$stamp = strtotime((string) $value);
		if ($stamp === false) { return null; }
		return gmdate('Y-m-d H:i:s', $stamp);
	}

	/* ---------------------------------------------------------------------
	   The field names, in one place.

	   Each list is "the spellings this field could plausibly have", best
	   first. The probe prints which one a real response actually uses.
	   --------------------------------------------------------------------- */

	function broker_fields($what) {

		$map = [
			/* The account summary, as it really answers (17 Nov 2026):
			     {"id", "currency":"GBP", "totalValue",
			      "cash":{"availableToTrade","reservedForOrders","inPies"},
			      "investments":{"currentValue","totalCost",
			                     "realizedProfitLoss","unrealizedProfitLoss"}}
			   Values are pounds. The paths are dotted because it nests, and
			   the lists are short because these are the names it uses rather
			   than the names it might. */
			'summary_cash'       => ['cash.availableToTrade'],
			'summary_reserved'   => ['cash.reservedForOrders'],
			'summary_in_pies'    => ['cash.inPies'],
			'summary_invested'   => ['investments.currentValue'],
			'summary_cost'       => ['investments.totalCost'],
			'summary_realised'   => ['investments.realizedProfitLoss'],
			'summary_unrealised' => ['investments.unrealizedProfitLoss'],
			'summary_total'      => ['totalValue'],
			'summary_currency'   => ['currency', 'currencyCode'],

			/* A position, as it really answers (17 Nov 2026):
			     {"instrument":{"ticker","name","isin","currency"},
			      "createdAt","quantity","quantityAvailableForTrading",
			      "quantityInPies","currentPrice","averagePricePaid",
			      "walletImpact":{"currency","totalCost","currentValue",
			                      "unrealizedProfitLoss","fxImpact"}}

			   The two halves are in different units and that is the whole
			   trap: the PRICES are pence, and everything under walletImpact
			   is pounds. A reader that took currentPrice for pounds would
			   value a GBP 331 holding at GBP 33,100. */
			'position_ticker'  => ['instrument.ticker', 'ticker', 'instrumentTicker'],
			'position_isin'    => ['instrument.isin', 'isin'],
			'position_quantity'=> ['quantity', 'qty', 'shares'],
			'position_average' => ['averagePricePaid', 'averagePrice', 'avgPrice'],
			'position_current' => ['currentPrice', 'current_price', 'marketPrice'],
			'position_value'   => ['walletImpact.currentValue', 'value', 'marketValue'],
			'position_cost'    => ['walletImpact.totalCost', 'totalCost'],
			'position_result'  => ['walletImpact.unrealizedProfitLoss', 'ppl', 'profitLoss'],
			'position_opened'  => ['createdAt', 'initialFillDate'],

			/* An instrument, as it really answers: ticker, type,
			   workingScheduleId, isin, currencyCode, name, shortName,
			   maxOpenQuantity, extendedHours, addedOn. All 18,600 carry an
			   ISIN; London shares are GBX, with a few GBP.

			   shortName is its own field here rather than a fallback for
			   name, because it is what the matching turns on: the ticker
			   keeps a company's old code after a rename - Barratt Redrow is
			   still BDEVl_EQ - and shortName carries the current one. */
			'instrument_ticker' => ['ticker', 'code', 'symbol'],
			'instrument_isin'   => ['isin', 'ISIN', 'isinCode'],
			'instrument_short'  => ['shortName'],
			'instrument_name'   => ['name', 'prettyName', 'longName'],
			'instrument_ccy'    => ['currencyCode', 'currency'],
			'instrument_type'   => ['type', 'instrumentType'],

			/* An order. Two shapes, one map: the PENDING list is flat
			     {"id","type","ticker","quantity","filledQuantity","status",
			      "side","createdAt","instrument":{...}}
			   and the HISTORY nests the order inside an envelope with its
			   fill beside it:
			     {"order":{"id","ticker","quantity","filledQuantity","status",
			               "side","createdAt","instrument":{...}},
			      "fill":{"id","quantity","price","filledAt",
			              "walletImpact":{"netValue","fxRate","taxes":[...]}}}

			   The nested path comes first in each list and the flat one
			   second, so one reader handles both.

			   price is pence; everything under walletImpact is pounds.
			   netValue is the cash that actually moved, taxes and all. */
			'order_id'         => ['order.id', 'id', 'orderId', 'reference'],
			'order_ticker'     => ['order.ticker', 'ticker', 'order.instrument.ticker',
			                       'instrument.ticker'],
			'order_isin'       => ['order.instrument.isin', 'instrument.isin'],
			/* Its own field, not the sign of the quantity: the history
			   reports a sale as side SELL with a positive quantity, and
			   reading the sign would have made every sale a purchase. */
			'order_side'       => ['order.side', 'side'],
			'order_quantity'   => ['order.filledQuantity', 'filledQuantity',
			                       'fill.quantity', 'order.quantity', 'quantity'],
			'order_price'      => ['fill.price', 'fillPrice', 'price'],
			'order_value'      => ['fill.walletImpact.netValue', 'filledValue', 'value'],
			'order_taxes'      => ['fill.walletImpact.taxes'],
			'order_status'     => ['order.status', 'status', 'state'],
			'order_type'       => ['order.type', 'type', 'orderType'],
			'order_placed'     => ['order.createdAt', 'createdAt', 'dateCreated'],
			'order_filled'     => ['fill.filledAt', 'filledAt', 'dateExecuted'],

			'dividend_id'      => ['reference', 'id', 'dividendId'],
			'dividend_ticker'  => ['ticker', 'instrumentTicker', 'code', 'symbol'],
			'dividend_amount'  => ['amount', 'grossAmount', 'amountInEuro'],
			'dividend_per'     => ['grossAmountPerShare', 'amountPerShare', 'perShare'],
			'dividend_quantity'=> ['quantity', 'shares'],
			'dividend_paid'    => ['paidOn', 'paidOnDate', 'date', 'timestamp'],
			'dividend_type'    => ['type', 'dividendType'],
		];

		return isset($map[$what]) ? $map[$what] : [];
	}

	/**
	 * Which way round a trade went.
	 *
	 * Trading 212's own field, and only the sign of the quantity when there
	 * is no field - which is how an order is SENT, and is not how the
	 * history reports one: a sale comes back as side SELL with a positive
	 * quantity, so reading the sign would make every sale a purchase, and a
	 * purchase this application thought it had made.
	 */
	function broker_order_side($row) {

		[$side] = broker_field($row, broker_fields('order_side'));

		if ($side !== null) {
			$side = strtoupper(trim((string) $side));
			if (in_array($side, ['BUY', 'SELL'], true)) { return $side; }
		}

		$quantity = broker_number($row, broker_fields('order_quantity'));
		return $quantity === null ? null : ($quantity < 0 ? 'SELL' : 'BUY');
	}

	/**
	 * The tax a fill was charged, from the broker's own itemised list.
	 *
	 * walletImpact.taxes is a list of {name, quantity, currency}, with the
	 * quantity negative because it left the account:
	 *
	 *   "taxes":[{"name":"STAMP_DUTY_RESERVE_TAX","quantity":-1.66,...}]
	 *
	 * Taken rather than worked out, because 0.5% of a purchase is not always
	 * what is charged: B&M is Jersey registered, its list came back empty,
	 * and a calculated figure would have invented GBP 1.65 of stamp duty on
	 * it. An empty list means no tax, which is a fact and not a gap.
	 *
	 * Returns a positive number of pounds, or null when the response carried
	 * no list at all - which is not the same as a list with nothing in it.
	 */
	function broker_taxes_paid($row) {

		$raw = null;
		foreach (broker_fields('order_taxes') as $path) {
			$found = broker_path($row, $path);
			if (is_array($found)) { $raw = $found; break; }
		}
		if ($raw === null) { return null; }

		$total = 0.0;
		foreach ($raw as $tax) {
			if (!is_array($tax)) { continue; }
			foreach (['quantity', 'amount', 'value'] as $field) {
				if (isset($tax[$field]) && is_numeric($tax[$field])) {
					$total += abs((float) $tax[$field]);
					break;
				}
			}
		}
		return $total;
	}

	/* ---------------------------------------------------------------------
	   Matching the broker's instruments to our symbols.
	   --------------------------------------------------------------------- */

	/**
	 * A broker ticker taken apart: ['code' => 'VOD', 'market' => 'L'].
	 *
	 * Trading 212's documented example is AAPL_US_EQ, and the format for a
	 * London share is not documented at all. Rather than guess which of the
	 * plausible shapes it is, this strips the parts that are certainly not
	 * the company's code:
	 *
	 *   a trailing _EQ, which is the instrument type;
	 *   a trailing _XX, which is a country or exchange marker;
	 *   a single trailing lower-case letter on an otherwise upper-case code,
	 *   which is the other spelling of the same thing (VODl).
	 *
	 * Whatever is left is the code. market is kept for the page, not used for
	 * matching: a marker this function has never seen must not silently
	 * exclude a share.
	 */
	function broker_ticker_code($broker_ticker) {

		$ticker = trim((string) $broker_ticker);
		if ($ticker === '') { return ['code' => '', 'market' => null]; }

		$market = null;

		if (strtoupper(substr($ticker, -3)) === '_EQ') { $ticker = substr($ticker, 0, -3); }

		if (preg_match('~^(.+)_([A-Za-z]{2,4})$~', $ticker, $m)) {
			$ticker = $m[1];
			$market = strtoupper($m[2]);
		}
		else if (preg_match('~^([A-Z0-9][A-Z0-9._\-]*?)([a-zI])(\d*)$~', $ticker, $m)) {
			/* The digits are a line rather than part of the code: Shawbrook
			   is SHAWl1_EQ, not a company called SHAWL1. I is taken as a
			   marker too, because PRNI_EQ is PRN on a London line - the one
			   upper-case marker in the list. */
			$ticker = $m[1];
			$market = strtoupper($m[2]);
		}

		return ['code' => strtoupper($ticker), 'market' => $market];
	}

	/**
	 * Which venue a ticker form says a line is on.
	 *
	 * Returns 'london', 'foreign' or 'unknown'. The forms are what the real
	 * list carries, and nothing else is inferred from them:
	 *
	 *   london   <code>l_EQ, <code>l1_EQ, <code>p_EQ, <code>I_EQ
	 *   foreign  <code>_US_EQ, <code>_CA_EQ (a country),
	 *            <code>d_EQ (EUR), <code>s_EQ (CHF)
	 *
	 * This matters because a foreign line must never be matched to a London
	 * share on a name or a code. Twelve shares were matched to two
	 * instruments after the second deploy - ASC to ASOS and to a US company
	 * called ASC, RKT to Reckitt and to Rocket Companies - and a holding in
	 * Rocket Companies valued as Reckitt is worse than no holding at all.
	 *
	 * The currency cannot do this job on its own: the London line for IHG is
	 * IHGl1_EQ and it reports USD.
	 *
	 * A marker this has never seen comes back 'unknown', which is treated as
	 * foreign everywhere that matters. A new London suffix would then show up
	 * as an unmatched share - visible, and nobody's holding valued wrongly -
	 * rather than as a wrong match.
	 */
	function broker_venue($broker_ticker) {

		$ticker = trim((string) $broker_ticker);
		if ($ticker === '') { return 'unknown'; }

		if (strtoupper(substr($ticker, -3)) === '_EQ') { $ticker = substr($ticker, 0, -3); }

		/* A country: AAPL_US, PEY_CA. */
		if (preg_match('~_[A-Za-z]{2,4}$~', $ticker)) { return 'foreign'; }

		/* A venue marker: one letter, then a line number. I is the only
		   upper-case one seen (PRNI_EQ, which is PRN in London). */
		if (preg_match('~([a-zI])\d*$~', $ticker, $m)) {
			if (in_array($m[1], ['l', 'p', 'I'], true)) { return 'london'; }
			if (in_array($m[1], ['d', 's'], true))      { return 'foreign'; }
			return 'unknown';
		}

		return 'unknown';
	}

	/**
	 * The comparable form of a code.
	 *
	 * London writes a short ticker with a trailing dot (RR.) and this
	 * application writes a share class with a dash (BT-A) where other places
	 * write a dot (BT.A) or a slash (BT/A, which is Trading 212's spelling).
	 * None of those differences is a different company, and none of them is
	 * worth a table of exceptions, so the separators come out: RR. RR and
	 * BT-A BT.A BT/A BTA all key the same.
	 *
	 * Two of our symbols could in principle key the same way. That is exactly
	 * why the matcher refuses a key that fits more than one.
	 */
	function broker_code_key($code) {
		/* The slash is Trading 212's spelling of a share class: BT's A
		   shares are BT-A here and BT/A there. */
		return strtoupper(str_replace(['.', '-', '/', ' ', '_'], '', (string) $code));
	}

	/** Our symbols by comparable key: ['BTA' => ['BT-A'], ...]. */
	function broker_symbol_keys($exchange = null) {

		$exchange = $exchange === null ? default_exchange() : $exchange;

		$out = [];
		foreach (query("select symbol from stock_symbols
		                 where enabled='Y' and exchange=? order by symbol", $exchange) as $row) {
			$key = broker_code_key($row['symbol']);
			if ($key === '') { continue; }
			$out[$key][] = (string) $row['symbol'];
		}
		return $out;
	}

	/** Our symbols by ISIN, where we have one. Usually empty: see sql/broker.sql. */
	function broker_symbol_isins($exchange = null) {

		$exchange = $exchange === null ? default_exchange() : $exchange;

		$out = [];
		$rows = query("select symbol, isin from stock_symbols
		                where enabled='Y' and exchange=? and isin is not null and isin<>''",
		              $exchange);
		foreach ($rows as $row) {
			$out[strtoupper(trim((string) $row['isin']))][] = (string) $row['symbol'];
		}
		return $out;
	}

	/**
	 * The broker's instrument list matched to our symbols.
	 *
	 * Three rules, in this order, because that is the order they can be
	 * trusted in:
	 *
	 *   1. the ISIN, where both sides have one. It is the company; a ticker
	 *      is only how one venue writes it. Every Trading 212 instrument
	 *      carries one, so this is exact as soon as ours are filled in.
	 *
	 *   2. shortName, on a London line. Trading 212's ticker keeps a
	 *      company's OLD code after a rename - Barratt Redrow is still
	 *      BDEVl_EQ, Entain is GVCl_EQ, Frasers is SPDl_EQ - and shortName
	 *      carries the current one. Matching on the ticker alone left 32 of
	 *      the FTSE 350 unmatched, most of them renames.
	 *
	 *   3. the stripped ticker, on a London line, which is what is left for
	 *      a company that has never been renamed.
	 *
	 * Rules 2 and 3 are London only because a name and a three letter code
	 * are not unique across 18,600 instruments: ASC is our ASOS and a US
	 * company, RKT is Reckitt and Rocket Companies. A line on a foreign
	 * form - _US_EQ, _CA_EQ, d_EQ, s_EQ - or on a form nobody has seen
	 * before can only be matched by its ISIN.
	 *
	 * Each instrument makes one claim, by the best rule it can. Where two
	 * instruments claim the same share, the better rule wins; if they tie,
	 * sterling wins; if they still tie, the London ticker form wins; and if
	 * they still tie, neither is used and the page says so. A wrong match
	 * values the wrong company's holding, so the last step is never a guess.
	 *
	 * SHCp_EQ and CAPCl_EQ are the case that needs all of it: both reduce to
	 * the code SHC, and CAPCl_EQ is the one whose shortName says SHC.
	 *
	 * Returns ['rows' => [...], 'matched' => n, 'ambiguous' => n,
	 *          'unknown' => n, 'isins' => [...]] where rows are ready for
	 * broker_instruments and isins is every ISIN the broker listed.
	 */
	function broker_match_instruments(array $instruments, $exchange = null) {

		$by_key  = broker_symbol_keys($exchange);
		$by_isin = broker_symbol_isins($exchange);

		$claims    = [];   // symbol => [row, row, ...]
		$ambiguous = [];   // ticker => row, with its note
		$unknown   = 0;
		$isins     = [];

		foreach ($instruments as $instrument) {

			[$ticker] = broker_field($instrument, broker_fields('instrument_ticker'));
			if ($ticker === null || trim((string) $ticker) === '') { continue; }
			$ticker   = trim((string) $ticker);

			[$isin]     = broker_field($instrument, broker_fields('instrument_isin'));
			[$short]    = broker_field($instrument, broker_fields('instrument_short'));
			[$name]     = broker_field($instrument, broker_fields('instrument_name'));
			[$currency] = broker_field($instrument, broker_fields('instrument_ccy'));

			$isin     = $isin === null ? null : strtoupper(trim((string) $isin));
			$currency = $currency === null ? null : strtoupper(trim((string) $currency));
			$parts    = broker_ticker_code($ticker);

			if ($isin !== null && $isin !== '') { $isins[$isin] = true; }

			$venue    = broker_venue($ticker);
			$sterling = in_array((string) $currency, ['GBP', 'GBX'], true);

			$row = ['broker_ticker' => $ticker,
			        'isin'          => $isin,
			        'short_name'    => $short === null ? null : trim((string) $short),
			        'name'          => $name === null ? null : (string) $name,
			        'currency'      => $currency,
			        'venue'         => $venue,
			        'market'        => $parts['market'],
			        'code'          => $parts['code'],
			        /* GBX for most London lines, GBP for a few - and USD for
			           IHGl1_EQ, which is why this is not the test for being
			           a London line. */
			        'sterling'      => $sterling,
			        'london'        => $venue === 'london',
			        'symbol'        => null,
			        'matched_on'    => null,
			        'rank'          => null,
			        'status'        => 'matched',
			        'note'          => null];

			/* A name or a code may only speak for a line that is on this
			   market. ASC_US_EQ is a US company whose code is our ASOS
			   ticker; RKT_US_EQ is Rocket Companies, not Reckitt. Both were
			   matched by the code rule after the second deploy, and a
			   holding in Rocket Companies valued as Reckitt is a worse
			   answer than no holding at all. An unrecognised form is treated
			   the same way: an unmatched share is visible, a wrong match is
			   not. The ISIN is exempt, because it is the company. */
			$may_use_names = $venue === 'london' || ($sterling && $venue !== 'foreign');

			$claim = function ($symbol, $how, $rank) use (&$claims, $row) {
				$row['symbol']     = $symbol;
				$row['matched_on'] = $how;
				$row['rank']       = $rank;
				$claims[$symbol][] = $row;
			};
			$refuse = function ($why) use (&$ambiguous, $row, $ticker) {
				$row['status'] = 'ambiguous';
				$row['note']   = $why;
				$ambiguous[$ticker] = $row;
			};

			/* 1. The ISIN. */
			if ($isin !== null && $isin !== '' && isset($by_isin[$isin])) {
				if (count($by_isin[$isin]) === 1) { $claim($by_isin[$isin][0], 'isin', 0); continue; }
				$refuse('ISIN ' . $isin . ' is on more than one of our shares ('
				        . implode(', ', $by_isin[$isin]) . ')');
				continue;
			}

			/* 2. shortName, on a London line only. */
			if ($may_use_names && $row['short_name'] !== null && $row['short_name'] !== '') {
				$short_key = broker_code_key($row['short_name']);
				if ($short_key !== '' && isset($by_key[$short_key])) {
					if (count($by_key[$short_key]) === 1) {
						$claim($by_key[$short_key][0], 'short_name', 1);
						continue;
					}
					$refuse('the short name ' . $row['short_name'] . ' fits '
					        . implode(', ', $by_key[$short_key]));
					continue;
				}
			}

			/* 3. The ticker, with the parts that are not the company's code
			      taken off it. On a London line only, for the same reason. */
			$key = broker_code_key($parts['code']);
			if (!$may_use_names || $key === '' || !isset($by_key[$key])) { $unknown++; continue; }

			if (count($by_key[$key]) > 1) {
				$refuse('the code ' . $parts['code'] . ' fits ' . implode(', ', $by_key[$key]));
				continue;
			}

			$claim($by_key[$key][0], 'ticker', 2);
		}

		/* The contest, where more than one instrument claimed a share. */
		$rows = [];
		foreach ($claims as $symbol => $found) {

			if (count($found) > 1) {

				/* The better rule first: a shortName match is current where
				   a ticker match may be a company's old code. */
				$best  = min(array_column($found, 'rank'));
				$found = array_values(array_filter($found,
				         function ($row) use ($best) { return $row['rank'] === $best; }));
			}

			if (count($found) > 1) {
				$sterling = array_values(array_filter($found,
				            function ($row) { return $row['sterling']; }));
				if (count($sterling) >= 1) { $found = $sterling; }
			}

			if (count($found) > 1) {
				$london = array_values(array_filter($found,
				          function ($row) { return $row['london']; }));
				if (count($london) >= 1) { $found = $london; }
			}

			if (count($found) > 1) {
				foreach ($found as $row) {
					$row['status'] = 'ambiguous';
					$row['symbol'] = null;
					$row['note']   = 'more than one instrument fits ' . $symbol . ' ('
					               . implode(', ', array_column($found, 'broker_ticker')) . ')';
					$ambiguous[$row['broker_ticker']] = $row;
				}
				continue;
			}

			/* The instruments that lost the contest are simply not used.
			   SHCp_EQ beside CAPCl_EQ is not an ambiguity once shortName has
			   spoken; it is an instrument that is not ours. */
			$unknown += count($claims[$symbol]) - 1;

			$rows[] = $found[0];
		}

		foreach ($ambiguous as $row) { $rows[] = $row; }

		return ['rows'      => $rows,
		        'matched'   => count(array_filter($rows, function ($r) {
		                           return $r['status'] === 'matched'; })),
		        'ambiguous' => count($ambiguous),
		        'unknown'   => $unknown,
		        'isins'     => array_keys($isins)];
	}

	/**
	 * Store a matched instrument list.
	 *
	 * REPLACES the mapping for this broker rather than adding to it. The
	 * first version wrote the rows it had matched and left the rest alone,
	 * so after the rules changed the table held both answers: 362 matched
	 * rows for 350 shares, twelve of them matched to a second instrument
	 * that was a different company, and four ambiguous rows whose notes
	 * named pairs that had been settled a deploy ago.
	 *
	 * A mapping is a photograph of somebody else's list, not a ledger. The
	 * right way to refresh one is to take a new photograph.
	 */
	function broker_store_instruments($broker, array $rows, $as_of = null, $environment = 'demo') {

		$as_of = $as_of === null ? date('Y-m-d') : $as_of;

		query("delete from broker_instruments where broker=? and environment=?",
		      $broker, $environment);

		foreach ($rows as $row) {
			query("replace into broker_instruments
			         (broker, environment, broker_ticker, symbol, short_name, isin,
			          currency, venue, name, status, matched_on, note, last_seen)
			       values (?,?,?,?,?,?,?,?,?,?,?,?,?)",
			      $broker, $environment,
			      mb_substr((string) $row['broker_ticker'], 0, 40),
			      $row['symbol'] === null ? null : mb_substr((string) $row['symbol'], 0, 50),
			      (!isset($row['short_name']) || $row['short_name'] === null)
			        ? null : mb_substr((string) $row['short_name'], 0, 60),
			      $row['isin'] === null ? null : mb_substr((string) $row['isin'], 0, 12),
			      $row['currency'] === null ? null : mb_substr((string) $row['currency'], 0, 8),
			      isset($row['venue']) ? mb_substr((string) $row['venue'], 0, 10) : null,
			      $row['name'] === null ? null : mb_substr((string) $row['name'], 0, 255),
			      (string) $row['status'],
			      $row['matched_on'] === null ? null : (string) $row['matched_on'],
			      $row['note'] === null ? null : mb_substr((string) $row['note'], 0, 255),
			      $as_of);
		}
		return count($rows);
	}

	/** ticker => symbol, for the instruments that matched one of ours. */
	function broker_symbol_map($broker = BROKER_T212, $environment = 'demo') {

		$out = [];
		foreach (query("select broker_ticker, symbol from broker_instruments
		                 where broker=? and environment=? and status='matched'
		                   and symbol is not null",
		               $broker, $environment) as $row) {
			$out[(string) $row['broker_ticker']] = (string) $row['symbol'];
		}
		return $out;
	}

	/** When the instrument list was last refreshed, or null. */
	function broker_instruments_as_of($broker = BROKER_T212, $environment = 'demo') {

		$rows = query("select max(last_seen) d from broker_instruments
		                where broker=? and environment=?", $broker, $environment);
		return (count($rows) && $rows[0]['d'] !== null) ? (string) $rows[0]['d'] : null;
	}

	/**
	 * The FTSE 350 symbols with no instrument of their own.
	 *
	 * Listed on the page rather than guessed at. A symbol here is a share the
	 * broker connection cannot see: not an error, but the reason a holding
	 * would be missing if one were opened in it.
	 */
	function broker_unmatched_symbols($broker = BROKER_T212, $exchange = null, $environment = 'demo') {

		$exchange = $exchange === null ? default_exchange() : $exchange;

		return query("select s.symbol, s.name from stock_symbols s
		               where s.enabled='Y' and s.exchange=?
		                 and not exists (select 1 from broker_instruments i
		                                  where i.broker=? and i.environment=?
		                                    and i.status='matched'
		                                    and i.symbol=s.symbol)
		               order by s.symbol", $exchange, $broker, $environment);
	}

	/**
	 * Our shares whose ISIN the broker does not list at all.
	 *
	 * Every Trading 212 instrument carries an ISIN, so a share of ours whose
	 * ISIN is nowhere in their list is a share that cannot be bought there -
	 * which is worth knowing before a strategy picks it, and is a different
	 * thing from one this application merely failed to match.
	 *
	 * Answerable only for the shares we have an ISIN for, which until
	 * fetch_fmp_fundamentals.py has run is none of them. No ISINs on our
	 * side means an empty list rather than a claim that nothing is tradeable.
	 */
	function broker_isins_not_listed(array $broker_isins, $exchange = null) {

		if (!count($broker_isins)) { return []; }

		$have = [];
		foreach ($broker_isins as $isin) { $have[strtoupper(trim((string) $isin))] = true; }

		$out = [];
		foreach (broker_symbol_isins($exchange) as $isin => $symbols) {
			if (!isset($have[$isin])) {
				foreach ($symbols as $symbol) { $out[] = $symbol; }
			}
		}
		sort($out);
		return $out;
	}

	/** The instruments that fitted more than one thing, with the reason. */
	function broker_ambiguous_instruments($broker = BROKER_T212, $environment = 'demo') {
		/* With their ISIN and currency, which the first version threw away:
		   the next question anybody asks about an ambiguous pair is which
		   of them is the London line, and the answer was not on the row. */
		return query("select broker_ticker, short_name, isin, currency, note, last_seen
		                from broker_instruments
		               where broker=? and environment=? and status='ambiguous'
		               order by broker_ticker", $broker, $environment);
	}

	/* ---------------------------------------------------------------------
	   Pounds or pence.
	   --------------------------------------------------------------------- */

	/** Our own latest close for a share, in pence, or null. */
	function broker_our_close($symbol, $exchange = null) {

		$exchange = $exchange === null ? default_exchange() : $exchange;

		$rows = query("select price from historical_prices
		                where symbol=? and exchange=? and price is not null
		                order by date desc limit 1", $symbol, $exchange);

		return count($rows) ? (float) $rows[0]['price'] : null;
	}

	/**
	 * Whether the broker's prices are pounds or pence, decided rather than
	 * assumed.
	 *
	 * $samples is a list of ['symbol'=>..., 'price'=>broker price,
	 * 'close'=>our close in pence]. A London share quoted at 690p is either
	 * 690 or 6.90 at the other end, and those are a hundred times apart - far
	 * further than a share moves between our close and the broker's quote, so
	 * the ratio says which it is without needing them to agree.
	 *
	 * The bands are wide on purpose (half to double, fifty to two hundred).
	 * A tight band would call a 30% mover undecidable; a band that wide still
	 * cannot confuse one answer for the other.
	 *
	 * Disagreement is not resolved by majority: if some shares say pounds and
	 * others say pence then the premise is wrong somewhere, and the honest
	 * answer is null - which stores no price at all rather than a figure that
	 * is a hundred times out for half the book.
	 *
	 * T212_PRICE_UNIT overrides the lot, for when the probe has settled it.
	 */
	function broker_price_unit(array $samples, $configured = null) {

		$configured = $configured === null
		            ? (defined('T212_PRICE_UNIT') ? T212_PRICE_UNIT : 'auto')
		            : $configured;

		if (in_array($configured, ['pounds', 'pence'], true)) {
			return ['unit' => $configured, 'pounds' => 0, 'pence' => 0, 'samples' => 0,
			        'why' => "T212_PRICE_UNIT says prices are " . $configured];
		}

		$pounds = 0; $pence = 0; $used = 0;

		foreach ($samples as $sample) {

			$price = isset($sample['price']) ? (float) $sample['price'] : 0.0;
			$close = isset($sample['close']) ? (float) $sample['close'] : 0.0;
			if ($price <= 0 || $close <= 0) { continue; }

			$ratio = $close / $price;
			$used++;

			if ($ratio >= 0.5 && $ratio <= 2)      { $pence++; }
			else if ($ratio >= 50 && $ratio <= 200) { $pounds++; }
		}

		if ($pounds > 0 && $pence > 0) {
			return ['unit' => null, 'pounds' => $pounds, 'pence' => $pence, 'samples' => $used,
			        'why' => "the broker's prices look like pounds for " . $pounds
			               . " holding(s) and pence for " . $pence . ", which cannot both"
			               . " be true, so no price was stored. Set SM_T212_PRICE_UNIT"
			               . " once tools/t212_probe.php has settled it."];
		}
		if ($pounds > 0) {
			return ['unit' => 'pounds', 'pounds' => $pounds, 'pence' => 0, 'samples' => $used,
			        'why' => "the broker's prices are about a hundredth of our pence"
			               . " closes, so they are pounds"];
		}
		if ($pence > 0) {
			return ['unit' => 'pence', 'pounds' => 0, 'pence' => $pence, 'samples' => $used,
			        'why' => "the broker's prices match our pence closes, so they are pence"];
		}

		return ['unit' => null, 'pounds' => 0, 'pence' => 0, 'samples' => $used,
		        'why' => "nothing could be compared against one of our own closes, so"
		               . " the price unit is unknown and no price was stored. Set"
		               . " SM_T212_PRICE_UNIT once tools/t212_probe.php has settled it."];
	}

	/**
	 * The unit for ONE position, where its own share can settle it.
	 *
	 * The sync-wide answer is a vote across the holdings, which is the right
	 * default and the wrong answer for a line that is quoted in something
	 * else: the London line for IHG reports USD. Where a holding's own price
	 * can be compared against our own close for the same share, that
	 * comparison decides it; where it cannot, the sync-wide answer stands.
	 *
	 * The bands are broker_price_unit()'s, for the same reason: half to
	 * double cannot be confused with fifty to two hundred, and a share does
	 * not move a hundredfold between our close and the broker's quote.
	 */
	function broker_position_unit($price, $close, $fallback) {

		$price = $price === null ? 0.0 : (float) $price;
		$close = $close === null ? 0.0 : (float) $close;

		if ($price <= 0 || $close <= 0) { return $fallback; }

		$ratio = $close / $price;
		if ($ratio >= 0.5 && $ratio <= 2)       { return 'pence'; }
		if ($ratio >= 50 && $ratio <= 200)      { return 'pounds'; }

		return $fallback;
	}

	/** A broker price in pounds, or null if the unit is not known. */
	function broker_to_pounds($value, $unit) {

		if ($value === null || $unit === null) { return null; }
		$value = (float) $value;
		return $unit === 'pence' ? $value / 100 : $value;
	}

	/* ---------------------------------------------------------------------
	   Storing the answers.
	   --------------------------------------------------------------------- */

	/** What the account is worth. One row a day; a second sync replaces it. */
	function broker_store_account_value($broker, $as_of, array $values, $environment = 'demo') {

		query("replace into broker_account_values
		         (broker, environment, as_of_date, cash, reserved, in_pies, invested,
		          invested_cost, realised, unrealised, total, currency, synced_at)
		       values (?,?,?,?,?,?,?,?,?,?,?,?,?)",
		      $broker, $environment, $as_of,
		      $values['cash'] ?? null, $values['reserved'] ?? null, $values['in_pies'] ?? null,
		      $values['invested'] ?? null, $values['invested_cost'] ?? null,
		      $values['realised'] ?? null, $values['unrealised'] ?? null,
		      $values['total'] ?? null,
		      isset($values['currency']) ? mb_substr((string) $values['currency'], 0, 8) : null,
		      date('Y-m-d H:i:s'));
	}

	/**
	 * The positions, as at a date.
	 *
	 * Replaced wholesale for that date: a holding that has been sold is gone
	 * from the broker's answer, and leaving yesterday's row in today's set
	 * would show a holding that no longer exists.
	 */
	function broker_store_positions($broker, $as_of, array $positions, $environment = 'demo') {

		query("delete from broker_positions where broker=? and environment=? and as_of_date=?",
		      $broker, $environment, $as_of);

		foreach ($positions as $position) {
			query("replace into broker_positions
			         (broker, environment, as_of_date, broker_ticker, symbol, quantity,
			          average_price, current_price, value, cost, gain, price_unit,
			          currency, synced_at)
			       values (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
			      $broker, $environment, $as_of,
			      mb_substr((string) $position['broker_ticker'], 0, 40),
			      $position['symbol'] === null ? null : mb_substr((string) $position['symbol'], 0, 50),
			      $position['quantity'] ?? null,
			      $position['average_price'] ?? null,
			      $position['current_price'] ?? null,
			      $position['value'] ?? null,
			      $position['cost'] ?? null,
			      $position['gain'] ?? null,
			      $position['price_unit'] ?? null,
			      isset($position['currency']) ? $position['currency'] : null,
			      date('Y-m-d H:i:s'));
		}
		return count($positions);
	}

	/**
	 * One order from the history. Returns true if it was new.
	 *
	 * Keyed on Trading 212's own id, so a re-run inserts nothing: the same
	 * order read twice is one order. The figures are updated all the same -
	 * an order read while it was filling has a price when it is read again.
	 */
	function broker_store_order($broker, array $order, $environment = 'demo') {

		$existing = query("select first_seen f from broker_orders
		                    where broker=? and environment=? and broker_order_id=?",
		                  $broker, $environment, (string) $order['broker_order_id']);
		$is_new = !count($existing);

		query("replace into broker_orders
		         (broker, environment, broker_order_id, broker_ticker, symbol, side,
		          quantity, fill_price, fill_value, taxes, net_value, status,
		          order_type, placed_at, filled_at, price_unit, first_seen)
		       values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
		      $broker, $environment,
		      mb_substr((string) $order['broker_order_id'], 0, 40),
		      isset($order['broker_ticker']) ? mb_substr((string) $order['broker_ticker'], 0, 40) : null,
		      isset($order['symbol']) && $order['symbol'] !== null
		        ? mb_substr((string) $order['symbol'], 0, 50) : null,
		      $order['side'] ?? null,
		      $order['quantity'] ?? null,
		      $order['fill_price'] ?? null,
		      $order['fill_value'] ?? null,
		      $order['taxes'] ?? null,
		      $order['net_value'] ?? null,
		      isset($order['status']) ? mb_substr((string) $order['status'], 0, 30) : null,
		      isset($order['order_type']) ? mb_substr((string) $order['order_type'], 0, 30) : null,
		      $order['placed_at'] ?? null,
		      $order['filled_at'] ?? null,
		      $order['price_unit'] ?? null,
		      $is_new ? date('Y-m-d H:i:s') : (string) $existing[0]['f']);

		return $is_new;
	}

	/** One dividend from the history. Returns true if it was new. */
	function broker_store_dividend($broker, array $dividend, $environment = 'demo') {

		$existing = query("select first_seen f from broker_dividends
		                    where broker=? and environment=? and broker_dividend_id=?",
		                  $broker, $environment, (string) $dividend['broker_dividend_id']);
		$is_new = !count($existing);

		query("replace into broker_dividends
		         (broker, environment, broker_dividend_id, broker_ticker, symbol,
		          amount, amount_per_share, quantity, paid_on, dividend_type, first_seen)
		       values (?,?,?,?,?,?,?,?,?,?,?)",
		      $broker, $environment,
		      mb_substr((string) $dividend['broker_dividend_id'], 0, 40),
		      isset($dividend['broker_ticker']) ? mb_substr((string) $dividend['broker_ticker'], 0, 40) : null,
		      isset($dividend['symbol']) && $dividend['symbol'] !== null
		        ? mb_substr((string) $dividend['symbol'], 0, 50) : null,
		      $dividend['amount'] ?? null,
		      $dividend['amount_per_share'] ?? null,
		      $dividend['quantity'] ?? null,
		      $dividend['paid_on'] ?? null,
		      isset($dividend['dividend_type']) ? mb_substr((string) $dividend['dividend_type'], 0, 30) : null,
		      $is_new ? date('Y-m-d H:i:s') : (string) $existing[0]['f']);

		return $is_new;
	}

	/* ---------------------------------------------------------------------
	   The sync.
	   --------------------------------------------------------------------- */

	/**
	 * Read the account and write down what it said.
	 *
	 * Called by includes/sync_broker.php from the nightly run and by the
	 * Sync now button on the broker page, so the two cannot drift apart.
	 *
	 * One failure does not stop the rest: a 403 on the dividend history is a
	 * missing permission on one call, not a reason to lose tonight's
	 * positions. Every step that fails adds a sentence to problems, the run
	 * is recorded as PARTIAL, and the page shows the sentences.
	 *
	 * $options:
	 *   instruments  true to refresh the instrument list whatever its age
	 *   exchange     which of our exchanges to match against
	 *   client       an injected client, for the suite
	 *   only         read only these parts: any of instruments, summary,
	 *                positions, orders, dividends. The sender uses
	 *                ['positions'] to read the book again after writing a
	 *                fill up, which is one request rather than five.
	 */
	function broker_sync($options = []) {

		$broker   = BROKER_T212;
		$exchange = isset($options["exchange"]) ? $options["exchange"] : default_exchange();
		$as_of    = isset($options['as_of']) ? $options['as_of'] : date('Y-m-d');

		/* Which parts of the account to read. Everything, unless a caller
		   asks for less: re-reading the positions after a fill is one
		   request, and spending five of them against the rate limit every
		   ten minutes to answer one question is how a sender runs out of
		   calls on the day it has the most to do. */
		$only = isset($options['only']) ? array_values((array) $options['only']) : null;
		$want = function ($what) use ($only) {
			return $only === null || in_array($what, $only, true);
		};

		/* Which account this run reads, and the label every row it writes
		   carries. Two accounts are read now - the practice one and the
		   live one - and a position in one is not a position in the other,
		   so nothing is stored without saying which it came from. */
		$environment = isset($options['environment'])
		             ? strtolower(trim((string) $options['environment']))
		             : null;

		/* Two lists, and the difference between them decides the status.
		   A PROBLEM is something that stops the data being right: a call
		   that failed, a field this application needed and could not read,
		   a price unit it could not settle on a share that is held. A
		   NOTICE is worth saying and wrong with nothing: six instruments
		   that fit more than one share, a holding whose instrument is not
		   one of ours. Counting the notices as problems made every run
		   FAILED on a night when the only thing wrong was the cash. */
		$result = ['ok' => false, 'ran' => false, 'status' => 'OFF', 'message' => '',
		           'problems' => [], 'notices' => [], 'positions' => 0, 'cash' => null,
		           'new_orders' => 0, 'new_dividends' => 0, 'instruments' => 0,
		           'unmatched' => 0, 'price_unit' => null, 'requests' => 0,
		           'as_of' => $as_of, 'scope' => 'all',
		           'environment' => $environment === null ? '' : $environment,
		           'label' => 'demo'];

		if (!broker_ready()) {
			$result['message'] = "sql/broker.sql has not been applied, so there is"
			                   . " nowhere to put what the broker says.";
			$result['problems'][] = $result['message'];
			return $result;
		}

		$client = isset($options['client']) ? $options['client']
		        : new T212Client($environment === null ? [] : ['env' => $environment]);

		/* Named $env_label, not $label: the summary's own field check
		   loops over a $label a hundred lines below, and a row stored with
		   "total value" in its environment column is a row nobody can
		   find. The suite caught it, because the column is ten characters
		   wide; one character wider and it would have gone out. */
		$env_label = $client->label();
		$result['environment'] = $client->environment();
		$result['label']       = $env_label;

		/* Off is the normal state of a host nobody has configured. It is not
		   an error, it writes nothing, and above all it opens no socket. */
		if (!$client->configured()) {
			$result['ok']      = true;
			$result['message'] = $client->why_not();
			return $result;
		}

		$result['ran'] = true;
		$problems = [];
		$notices  = [];
		$started  = date('Y-m-d H:i:s');

		/* --- the instrument list, weekly ---------------------------------
		   One request per 50 seconds and a large answer, for something that
		   changes when a company lists or leaves. */
		$instruments_as_of = broker_instruments_as_of($broker, $env_label);
		$stale = $instruments_as_of === null
		      || strtotime($instruments_as_of) < strtotime('-' . max(1, (int) T212_INSTRUMENT_DAYS) . ' day');

		if ($want('instruments') && (!empty($options['instruments']) || $stale)) {

			$answer = $client->get('instruments');

			if (!$answer['ok']) { $problems[] = "instruments: " . $answer['message']; }
			else {
				$rows = is_array($answer['data']) ? $answer['data'] : [];
				$matched = broker_match_instruments($rows, $exchange);
				broker_store_instruments($broker, $matched['rows'], $as_of, $env_label);
				$result['instruments'] = $matched['matched'];

				/* Belt and braces over the unique key: if a share ever ends
				   up with two instruments again, the page says so rather
				   than valuing a holding against whichever row a join
				   happened to pick. */
				$doubled = query("select symbol, count(*) n from broker_instruments
				                   where broker=? and status='matched' and symbol is not null
				                   group by symbol having n > 1", $broker);
				if (count($doubled)) {
					$problems[] = count($doubled) . " share(s) are matched to more than one"
					            . " instrument, which should be impossible: "
					            . implode(', ', array_column($doubled, 'symbol'));
				}

				if ($matched['ambiguous'] > 0) {
					$notices[] = $matched['ambiguous'] . " of the broker's instruments fit"
					           . " more than one of our shares, so none of them was matched"
					           . " to any. They are listed under Mapping.";
				}

				/* A share whose ISIN is not in the broker's list at all
				   cannot be traded there, which is worth knowing before a
				   strategy picks it. Only answerable once our own ISINs are
				   filled in, which fetch_fmp_fundamentals.py does. */
				$missing = broker_isins_not_listed($matched['isins'], $exchange);
				if (count($missing)) {
					$notices[] = count($missing) . " share(s) have an ISIN that Trading 212"
					           . " does not list at all, so they cannot be traded there: "
					           . implode(', ', array_slice($missing, 0, 20))
					           . (count($missing) > 20 ? ' and ' . (count($missing) - 20) . ' more' : '');
				}
			}
		}

		$map = broker_symbol_map($broker, $env_label);

		/* --- what the account is worth ----------------------------------- */
		$summary = $want('summary') ? $client->get('summary')
		                            : ['ok' => true, 'skipped' => true, 'data' => []];
		if (!empty($summary['skipped'])) { /* not asked for by this caller */ }
		else if (!$summary['ok']) { $problems[] = "account summary: " . $summary['message']; }
		else {
			$data = is_array($summary['data']) ? $summary['data'] : [];

			$values = [
				'cash'          => broker_number($data, broker_fields('summary_cash')),
				'reserved'      => broker_number($data, broker_fields('summary_reserved')),
				'in_pies'       => broker_number($data, broker_fields('summary_in_pies')),
				'invested'      => broker_number($data, broker_fields('summary_invested')),
				'invested_cost' => broker_number($data, broker_fields('summary_cost')),
				'realised'      => broker_number($data, broker_fields('summary_realised')),
				'unrealised'    => broker_number($data, broker_fields('summary_unrealised')),
				'total'         => broker_number($data, broker_fields('summary_total')),
			];
			[$currency] = broker_field($data, broker_fields('summary_currency'));
			$values['currency'] = $currency === null ? 'GBP' : (string) $currency;

			/* Every figure the page shows at the top is checked by name. A
			   null stored quietly is how the first live sync reported a
			   correct total beside a blank cash balance and called itself a
			   success. */
			foreach (['summary_cash'     => 'cash balance',
			          'summary_invested' => 'invested value',
			          'summary_total'    => 'total value'] as $what => $label) {
				$why = broker_field_problem($data, $what, $label, 'account summary');
				if ($why !== '') { $problems[] = $why; }
			}

			/* Stored whatever was missing: three figures out of four beats
			   none, and the problem above says which one is not there. */
			broker_store_account_value($broker, $as_of, $values, $env_label);
			$result['cash'] = $values['cash'];
		}

		/* --- the positions ------------------------------------------------ */
		$positions = $want('positions') ? $client->get('positions')
		                                : ['ok' => true, 'skipped' => true, 'data' => []];
		if (!empty($positions['skipped'])) { /* not asked for by this caller */ }
		else if (!$positions['ok']) { $problems[] = "positions: " . $positions['message']; }
		else {

			$rows = is_array($positions['data']) ? $positions['data'] : [];
			if (isset($rows['items']) && is_array($rows['items'])) { $rows = $rows['items']; }

			$parsed = []; $samples = [];

			foreach ($rows as $row) {

				[$ticker] = broker_field($row, broker_fields('position_ticker'));

				/* A row this cannot read is a holding this cannot see. The
				   first stage 2 sync stored nothing while the broker held
				   nine shares and reported itself OK, because the ticker
				   moved inside an instrument object and a row with no
				   ticker was skipped in silence. */
				if ($ticker === null) {
					$problems[] = "a position carried none of the names this knows for a"
					            . " ticker (" . implode(', ', broker_fields('position_ticker'))
					            . "). It has: " . implode(', ', array_keys((array) $row));
					continue;
				}

				$ticker = (string) $ticker;
				$symbol = isset($map[$ticker]) ? $map[$ticker] : null;

				$one = [
					'broker_ticker' => $ticker,
					'symbol'        => $symbol,
					'quantity'      => broker_number($row, broker_fields('position_quantity')),
					'average'       => broker_number($row, broker_fields('position_average')),
					'current'       => broker_number($row, broker_fields('position_current')),
					/* Pounds already: walletImpact is the account's own
					   money, not a price, so no unit question arises. */
					'value'         => broker_number($row, broker_fields('position_value')),
					'cost'          => broker_number($row, broker_fields('position_cost')),
					'result'        => broker_number($row, broker_fields('position_result')),
				];

				if ($one['quantity'] === null) {
					$problems[] = $ticker . ": the broker sent a position with no quantity this"
					            . " could read (" . implode(', ', array_keys((array) $row)) . ")";
				}

				$parsed[] = $one;

				/* The comparison that settles pounds against pence, gathered
				   here because this is where both numbers are in hand. */
				if ($symbol !== null && $one['current'] !== null) {
					$close = broker_our_close($symbol, $exchange);
					if ($close !== null) {
						$samples[] = ['symbol' => $symbol, 'price' => $one['current'],
						              'close' => $close];
					}
				}
			}

			/* The unit is known now - the practice account's first nine
			   fills settled it - so the comparison is a tripwire rather
			   than the decider: if a held share's own close says something
			   other than the configured unit, one of the two is wrong and
			   the page should say so. */
			$unit = broker_price_unit($samples);
			$result['price_unit'] = $unit['unit'];
			if ($unit['unit'] === null && count($parsed)) { $problems[] = $unit['why']; }
			else if ($unit['unit'] !== null && in_array(T212_PRICE_UNIT, ['pounds', 'pence'], true)
			         && $unit['samples'] > 0 && $unit['unit'] !== T212_PRICE_UNIT) {
				$problems[] = "the broker's prices look like " . $unit['unit'] . " against our own"
				            . " closes, but SM_T212_PRICE_UNIT says " . T212_PRICE_UNIT
				            . ". One of the two is wrong and every price here is out by a"
				            . " hundred if it is this one.";
			}

			/* What each held line is quoted in, so a holding in a line that
			   is not sterling is visible rather than read as pounds. */
			$ccy = [];
			foreach (query("select broker_ticker, currency from broker_instruments
			                 where broker=?", $broker) as $row) {
				$ccy[(string) $row['broker_ticker']] = $row['currency'];
			}

			$store = [];
			foreach ($parsed as $row) {

				/* This share's own comparison first, the sync's vote second.
				   IHG's London line is quoted in USD, and one holding in a
				   different currency must not decide the unit for the rest
				   or be forced into theirs. */
				$own = $row['symbol'] === null ? null : broker_our_close($row['symbol'], $exchange);
				$row_unit = broker_position_unit($row['current'], $own, $unit['unit']);

				$average = broker_to_pounds($row['average'], $row_unit);
				$current = broker_to_pounds($row['current'], $row_unit);

				/* The value the broker sent is money, not a price, so it is
				   in the account's currency whatever the prices are in. When
				   it did not send one, quantity times the price is the same
				   number - once the price is in pounds. */
				$value = $row['value'];
				if ($value === null && $current !== null && $row['quantity'] !== null) {
					$value = $current * (float) $row['quantity'];
				}

				/* The broker's own unrealised figure, which is the one the
				   app shows beside it. Worked out here only if it did not
				   send one, and from the cost it charged rather than from
				   the average price, because the cost includes the stamp
				   duty that was actually paid. */
				$gain = $row['result'];
				if ($gain === null && $value !== null && $row['cost'] !== null) {
					$gain = $value - (float) $row['cost'];
				}
				if ($gain === null && $value !== null && $average !== null && $row['quantity'] !== null) {
					$gain = $value - $average * (float) $row['quantity'];
				}

				$store[] = ['broker_ticker' => $row['broker_ticker'],
				            'symbol'        => $row['symbol'],
				            'quantity'      => $row['quantity'],
				            'average_price' => $average,
				            'current_price' => $current,
				            'value'         => $value,
				            'cost'          => $row['cost'],
				            'gain'          => $gain,
				            'price_unit'    => $row_unit,
				            'currency'      => isset($ccy[$row['broker_ticker']])
				                             ? $ccy[$row['broker_ticker']] : null];
			}

			/* A holding in a line that is not quoted in sterling: its value
			   and its gain are the broker's own figures, in the account's
			   currency, but its price is in whatever the line is quoted in
			   and nothing here converts it. */
			$foreign = [];
			foreach ($store as $row) {
				if ($row['currency'] !== null
				    && !in_array((string) $row['currency'], ['GBP', 'GBX'], true)) {
					$foreign[] = $row['broker_ticker'] . ' (' . $row['currency'] . ')';
				}
			}
			if (count($foreign)) {
				$notices[] = "held in a line that is not quoted in sterling, so its price"
				           . " is in that currency rather than pounds: " . implode(', ', $foreign);
			}

			broker_store_positions($broker, $as_of, $store, $env_label);
			$result['positions'] = count($store);

			/* The cross-check that would have caught the first stage 2 sync.
			   It reported OK with nothing in broker_positions while the
			   broker held nine shares, because every row was skipped in
			   silence - and the summary it had just stored said the account
			   had money invested. The two cannot both be right. */
			if (count($store) === 0 && isset($values['invested'])
			    && $values['invested'] !== null && (float) $values['invested'] > 0) {
				$problems[] = "the account summary says " . round((float) $values['invested'], 2)
				            . " is invested and the broker sent no positions this could read."
				            . " One of the two is wrong, so nothing was stored for the holdings.";
			}

			$unknown = count(array_filter($store, function ($r) { return $r['symbol'] === null; }));
			if ($unknown > 0) {
				/* A notice: the holding is read, stored and shown, by the
				   broker's own ticker instead of our name for it. */
				$notices[] = $unknown . " holding(s) are in instruments this application"
				           . " could not match to one of its own shares, so they are"
				           . " shown by the broker's ticker.";
			}
		}

		/* --- the history: orders and dividends ----------------------------
		   Paged to the end, or to T212_MAX_PAGES. The API takes a cursor and
		   a limit and the documentation describes no date filter, so none is
		   invented: the rows are keyed on the broker's own ids and a re-run
		   writes nothing new. */
		$orders = $want('orders') ? $client->get_all('order_history')
		                          : ['ok' => true, 'items' => []];
		if (!$orders['ok']) { $problems[] = "order history: " . $orders['message']; }
		foreach ($orders['items'] as $row) {

			[$id] = broker_field($row, broker_fields('order_id'));
			if ($id === null) { continue; }

			[$ticker]   = broker_field($row, broker_fields('order_ticker'));
			$ticker     = $ticker === null ? null : (string) $ticker;
			$quantity   = broker_number($row, broker_fields('order_quantity'));
			$price      = broker_number($row, broker_fields('order_price'));
			[$status]   = broker_field($row, broker_fields('order_status'));
			[$type]     = broker_field($row, broker_fields('order_type'));

			/* The side is its own field. It used to be read from the sign of
			   the quantity, which is how an order is SENT - and the history
			   reports a sale as side SELL with a POSITIVE quantity, so every
			   sale would have been written up as a purchase. */
			$side = broker_order_side($row);

			/* The cash that moved, taxes and all, and the tax itself. Both
			   are the broker's own figures: the fill price times the
			   quantity is the consideration, not what left the account. */
			$net   = broker_number($row, broker_fields('order_value'));
			$taxes = broker_taxes_paid($row);

			$price_pounds = broker_to_pounds($price, $result['price_unit']);
			$value = ($price_pounds !== null && $quantity !== null)
			       ? abs($quantity) * $price_pounds : null;
			if ($value === null && $net !== null) { $value = abs($net); }

			$new = broker_store_order($broker, [
				'broker_order_id' => (string) $id,
				'broker_ticker'   => $ticker,
				'symbol'          => ($ticker !== null && isset($map[$ticker])) ? $map[$ticker] : null,
				'side'            => $side,
				'quantity'        => $quantity === null ? null : abs($quantity),
				'fill_price'      => $price_pounds,
				'fill_value'      => $value === null ? null : abs($value),
				'taxes'           => $taxes,
				'net_value'       => $net === null ? null : abs($net),
				'status'          => $status,
				'order_type'      => $type,
				'placed_at'       => broker_datetime($row, broker_fields('order_placed')),
				'filled_at'       => broker_datetime($row, broker_fields('order_filled')),
				'price_unit'      => $result['price_unit'],
			], $env_label);
			if ($new) { $result['new_orders']++; }
		}

		$dividends = $want('dividends') ? $client->get_all('dividends')
		                                : ['ok' => true, 'items' => []];
		if (!$dividends['ok']) { $problems[] = "dividends: " . $dividends['message']; }
		foreach ($dividends['items'] as $row) {

			[$id] = broker_field($row, broker_fields('dividend_id'));
			if ($id === null) { continue; }

			[$ticker] = broker_field($row, broker_fields('dividend_ticker'));
			$ticker   = $ticker === null ? null : (string) $ticker;
			[$type]   = broker_field($row, broker_fields('dividend_type'));

			/* The total is money in the account's currency, so the pence
			   question does not arise for it. The per-share figure is money
			   too, but it is the one a London investor would expect in
			   pence, so it is the one the broker might send that way - and
			   the dividend carries its own check: the total divided by the
			   quantity is the same number in the same currency as the
			   total. Out by about a hundred means it arrived in pence. */
			$amount   = broker_number($row, broker_fields('dividend_amount'));
			$quantity = broker_number($row, broker_fields('dividend_quantity'));
			$per      = broker_number($row, broker_fields('dividend_per'));

			if ($per !== null && $per > 0 && $amount !== null && $quantity !== null
			    && $quantity > 0) {
				$implied = $amount / $quantity;
				if ($implied > 0 && $per / $implied >= 50 && $per / $implied <= 200) {
					$per = $per / 100;
				}
			}

			$new = broker_store_dividend($broker, [
				'broker_dividend_id' => (string) $id,
				'broker_ticker'      => $ticker,
				'symbol'             => ($ticker !== null && isset($map[$ticker])) ? $map[$ticker] : null,
				'amount'             => $amount,
				'amount_per_share'   => $per,
				'quantity'           => $quantity,
				'paid_on'            => broker_datetime($row, broker_fields('dividend_paid')),
				'dividend_type'      => $type,
			], $env_label);
			if ($new) { $result['new_dividends']++; }
		}

		/* --- the record ---------------------------------------------------- */
		foreach ($client->problems() as $problem) {
			if (!in_array($problem, $problems, true)) { $problems[] = $problem; }
		}
		$problems = array_map('t212_mask', $problems);
		$notices  = array_map('t212_mask', $notices);

		$result['unmatched'] = count(broker_unmatched_symbols($broker, $exchange, $env_label));
		$result['requests']  = $client->requests();
		$result['problems']  = $problems;
		$result['notices']   = $notices;

		/* The status is about the problems only. A run that read the cash,
		   the positions and the history, and had something to say about six
		   instruments, did its job. */
		$result['status']    = count($problems) === 0 ? 'OK'
		                     : ($result['positions'] > 0 || $result['cash'] !== null
		                        ? 'PARTIAL' : 'FAILED');
		$result['ok']        = $result['status'] !== 'FAILED';
		/* A partial read says so. The page shows the last sync, and "read
		   15 position(s), cash unknown" from the sender's one-call re-read
		   would look like a sync that half failed. */
		$result['scope'] = $only === null ? 'all' : implode(', ', $only);

		$result['message']   = count($problems)
		                     ? implode(' ', $problems)
		                     : ($only === null ? '' : $result['scope'] . " only: ")
		                       . "read " . $result['positions'] . " position(s)"
		                       . ($want('summary')
		                          ? ", cash " . ($result['cash'] === null ? 'unknown' : $result['cash'])
		                          : "")
		                       . (count($notices) ? '. ' . implode(' ', $notices) : '');

		query("insert into broker_sync_runs
		         (broker, environment, started_at, finished_at, status, as_of_date,
		          price_unit, positions, cash, new_orders, new_dividends, instruments,
		          unmatched, requests, problems, notices)
		       values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
		      $broker, $env_label, $started, date('Y-m-d H:i:s'),
		      $result['status'], $as_of, $result['price_unit'], $result['positions'],
		      $result['cash'], $result['new_orders'], $result['new_dividends'],
		      $result['instruments'], $result['unmatched'], $result['requests'],
		      count($problems) ? t212_mask(implode("\n", $problems)) : null,
		      count($notices) ? t212_mask(implode("\n", $notices)) : null);

		write_log("sync_broker",
		          "env=" . $client->environment() . " status=" . $result['status']
		        . " positions=" . $result['positions']
		        . " cash=" . ($result['cash'] === null ? '-' : $result['cash'])
		        . " new_orders=" . $result['new_orders']
		        . " new_dividends=" . $result['new_dividends']
		        . " unit=" . ($result['price_unit'] === null ? 'unknown' : $result['price_unit'])
		        . " unmatched=" . $result['unmatched']
		        . " requests=" . $result['requests']
		        . (count($client->notes())
		           ? " notes=" . t212_mask(implode('; ', $client->notes())) : "")
		        . (count($notices) ? " notices=" . t212_mask(implode('; ', $notices)) : "")
		        . (count($problems) ? " problems=" . t212_mask(implode('; ', $problems)) : ""));

		return $result;
	}

	/* ---------------------------------------------------------------------
	   What the page reads.
	   --------------------------------------------------------------------- */

	/** The most recent sync attempt, successful or not. */
	function broker_last_run($broker = BROKER_T212, $environment = null) {

		if (!broker_ready()) { return null; }

		/* The last run of one account, or of either where the caller does
		   not care. */
		$rows = ($environment === null)
		      ? query("select * from broker_sync_runs where broker=?
		                order by run_id desc limit 1", $broker)
		      : query("select * from broker_sync_runs where broker=? and environment=?
		                order by run_id desc limit 1", $broker, $environment);
		return count($rows) ? $rows[0] : null;
	}

	/** The connection, in the words the page prints. */
	function broker_status($client = null) {

		$client = $client === null ? new T212Client() : $client;
		$label  = $client->label();
		$run    = broker_last_run(BROKER_T212, $label);

		return [
			'environment' => $client->environment(),
			'label'       => $label,
			'words'       => t212_label_words($label),
			'configured'  => $client->configured(),
			'live'        => $client->is_live(),
			'why_not'     => $client->why_not(),
			'base_url'    => $client->base_url(),
			'ready'       => broker_ready(),
			'run'         => $run,
			/* A key without the permission a call needs answers 403, and
			   that is a thing somebody has to go and change in Trading 212
			   rather than something the next run will fix. */
			'forbidden'   => $run !== null && stripos((string) $run['problems'], '(403)') !== false,
			'instruments_as_of' => broker_ready()
			                     ? broker_instruments_as_of(BROKER_T212, $label) : null,
		];
	}

	/* ---------------------------------------------------------------------
	   What a key can read, and whether the order book was blind.
	   sql/broker_key_scopes.sql. Where this has not been applied nothing is
	   stored and nothing is read, and everything else behaves as it did.
	   --------------------------------------------------------------------- */

	function broker_scopes_ready() {

		static $known = null;
		if ($known !== null) { return $known; }

		$rows = query("select count(*) n from information_schema.tables
		                where table_schema=database()
		                  and table_name in ('broker_key_scopes','broker_order_book_state')");
		$known = count($rows) && (int) $rows[0]['n'] === 2;
		return $known;
	}

	/** Keep what key_scopes() found. One row per environment. */
	function broker_store_key_scopes(array $scopes) {

		if (!broker_scopes_ready() || empty($scopes['checked'])) { return false; }

		query("replace into broker_key_scopes
		         (environment, orders, history, orders_http, history_http, summary, checked_at)
		       values (?,?,?,?,?,?,?)",
		      (string) $scopes['environment'], $scopes['orders'], $scopes['history'],
		      (int) $scopes['orders_http'], (int) $scopes['history_http'],
		      substr(t212_mask((string) $scopes['summary']), 0, 200), date('Y-m-d H:i:s'));
		return true;
	}

	/**
	 * The last key check of each environment, keyed by environment.
	 * Only environments that have been checked appear.
	 */
	function broker_key_scopes_state() {

		$out = [];
		if (!broker_scopes_ready()) { return $out; }
		foreach (query("select * from broker_key_scopes order by environment") as $row) {
			$out[(string) $row['environment']] = $row;
		}
		return $out;
	}

	/**
	 * Record whether the order book was blind the last time this strategy's
	 * orders were resolved. Written by broker_resolve_orders() every time it
	 * reads the book, so a key that has been fixed clears it on the next run.
	 */
	function broker_store_book_state($strategy, $environment, $blind, array $missing = []) {

		if (!broker_scopes_ready()) { return false; }

		query("replace into broker_order_book_state
		         (strategy, environment, blind, missing, checked_at)
		       values (?,?,?,?,?)",
		      (string) $strategy, (string) $environment, $blind ? 'Y' : 'N',
		      count($missing) ? substr(implode(', ', $missing), 0, 200) : null,
		      date('Y-m-d H:i:s'));
		return true;
	}

	/** ['blind'=>bool, 'missing'=>string, 'environment'=>string] for a strategy. */
	function broker_book_state($strategy) {

		$none = ['blind' => false, 'missing' => '', 'environment' => '', 'checked_at' => null];
		if (!broker_scopes_ready()) { return $none; }

		$rows = query("select * from broker_order_book_state where strategy=?", (string) $strategy);
		if (!count($rows)) { return $none; }

		return ['blind' => $rows[0]['blind'] === 'Y', 'missing' => (string) $rows[0]['missing'],
		        'environment' => (string) $rows[0]['environment'],
		        'checked_at' => $rows[0]['checked_at']];
	}

	/** The latest figures for the account. */
	function broker_latest_values($broker = BROKER_T212, $environment = 'demo') {

		if (!broker_ready()) { return null; }
		$rows = query("select * from broker_account_values where broker=? and environment=?
		                order by as_of_date desc limit 1", $broker, $environment);
		return count($rows) ? $rows[0] : null;
	}

	/** The latest positions, with our own name for the share where we know it. */
	function broker_latest_positions($broker = BROKER_T212, $environment = 'demo') {

		if (!broker_ready()) { return []; }

		$rows = query("select max(as_of_date) d from broker_positions
		                where broker=? and environment=?", $broker, $environment);
		if (!count($rows) || $rows[0]['d'] === null) { return []; }

		/* matched_on comes from the instrument row rather than the position:
		   how a holding was matched is a fact about the mapping, and the
		   page shows it so that a rename matched on shortName can be told
		   from a plain ticker match without reading the code. */
		return query("select p.*, s.name, i.matched_on, i.short_name
		                from broker_positions p
		                left join stock_symbols s on s.symbol=p.symbol and s.enabled='Y'
		                left join broker_instruments i on i.broker=p.broker
		                     and i.environment=p.environment
		                     and i.broker_ticker=p.broker_ticker
		               where p.broker=? and p.environment=? and p.as_of_date=?
		               order by p.value desc, p.broker_ticker",
		             $broker, $environment, (string) $rows[0]['d']);
	}

	function broker_recent_orders($broker = BROKER_T212, $limit = 20, $environment = 'demo') {
		if (!broker_ready()) { return []; }
		$limit = max(1, min(200, (int) $limit));
		return query("select * from broker_orders where broker=? and environment=?
		               order by coalesce(filled_at, placed_at) desc, broker_order_id desc
		               limit " . $limit, $broker, $environment);
	}

	function broker_recent_dividends($broker = BROKER_T212, $limit = 20, $environment = 'demo') {
		if (!broker_ready()) { return []; }
		$limit = max(1, min(200, (int) $limit));
		return query("select * from broker_dividends where broker=? and environment=?
		               order by paid_on desc, broker_dividend_id desc limit " . $limit,
		             $broker, $environment);
	}

/* No closing tag: see the note at the end of constants.php. */
