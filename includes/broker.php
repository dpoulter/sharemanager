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

	/** The only broker this knows about. A column, not an assumption. */
	define("BROKER_T212", "t212");

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
	 * A field out of a response, by any of the names it might have.
	 *
	 * Returns [value, name] where name is the spelling that was found, or
	 * [null, null]. The caller decides whether a missing field is worth a
	 * problem line: a missing average price is, a missing dividend type is
	 * not.
	 */
	function broker_field($row, array $candidates) {

		if (!is_array($row)) { return [null, null]; }

		foreach ($candidates as $name) {
			if (array_key_exists($name, $row) && $row[$name] !== null && $row[$name] !== '') {
				return [$row[$name], $name];
			}
		}
		return [null, null];
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
			'summary_cash'     => ['cash', 'free', 'freeForStocks', 'freeCash', 'availableCash'],
			'summary_invested' => ['invested', 'investedValue', 'investments'],
			'summary_total'    => ['total', 'totalValue', 'equity', 'netAssetValue'],
			'summary_free'     => ['free', 'freeForStocks', 'freeCash'],
			'summary_result'   => ['result', 'ppl', 'profitLoss', 'unrealisedPnl'],
			'summary_currency' => ['currencyCode', 'currency'],

			'position_ticker'  => ['ticker', 'instrumentTicker', 'code', 'symbol'],
			'position_quantity'=> ['quantity', 'qty', 'shares'],
			'position_average' => ['averagePrice', 'average_price', 'avgPrice', 'averageEntryPrice'],
			'position_current' => ['currentPrice', 'current_price', 'price', 'marketPrice'],
			'position_value'   => ['value', 'marketValue', 'currentValue'],
			'position_result'  => ['ppl', 'profitLoss', 'result', 'gain'],

			'instrument_ticker'=> ['ticker', 'code', 'symbol'],
			'instrument_isin'  => ['isin', 'ISIN', 'isinCode'],
			'instrument_name'  => ['name', 'shortName', 'prettyName', 'longName'],
			'instrument_ccy'   => ['currencyCode', 'currency'],
			'instrument_type'  => ['type', 'instrumentType'],

			'order_id'         => ['id', 'orderId', 'fillId', 'reference'],
			'order_ticker'     => ['ticker', 'instrumentTicker', 'code', 'symbol'],
			'order_quantity'   => ['filledQuantity', 'quantity', 'orderedQuantity'],
			'order_price'      => ['fillPrice', 'filledPrice', 'averagePrice', 'limitPrice', 'price'],
			'order_value'      => ['filledValue', 'fillCost', 'value'],
			'order_status'     => ['status', 'state'],
			'order_type'       => ['type', 'orderType'],
			'order_placed'     => ['dateCreated', 'createdAt', 'dateCreatedUtc'],
			'order_filled'     => ['dateExecuted', 'dateModified', 'fillDate', 'executedAt'],

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
		else if (preg_match('~^([A-Z0-9][A-Z0-9._\-]*)([a-z])$~', $ticker, $m)) {
			$ticker = $m[1];
			$market = strtoupper($m[2]);
		}

		return ['code' => strtoupper($ticker), 'market' => $market];
	}

	/**
	 * The comparable form of a code.
	 *
	 * London writes a short ticker with a trailing dot (RR.) and this
	 * application writes a share class with a dash (BT-A) where other places
	 * write a dot (BT.A). None of those differences is a different company,
	 * and none of them is worth a table of exceptions, so the separators come
	 * out: RR. RR and BT-A BT.A BTA all key the same.
	 *
	 * Two of our symbols could in principle key the same way. That is exactly
	 * why the matcher refuses a key that fits more than one.
	 */
	function broker_code_key($code) {
		return strtoupper(str_replace(['.', '-', ' ', '_'], '', (string) $code));
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
	 * ISIN wins where both sides have one: it is the company, where a ticker
	 * is only the way one venue writes it. Otherwise the code decides, and
	 * only when it decides one symbol - a code that fits two of ours, or two
	 * of the broker's instruments that fit one of our symbols, is ambiguous
	 * and is stored as such. Nothing is guessed, in either direction.
	 *
	 * Returns ['rows' => [...], 'matched' => n, 'ambiguous' => n,
	 *          'unknown' => n] where rows are ready for broker_instruments.
	 */
	function broker_match_instruments(array $instruments, $exchange = null) {

		$by_key  = broker_symbol_keys($exchange);
		$by_isin = broker_symbol_isins($exchange);

		$candidates = [];   // symbol => [row, row, ...]
		$ambiguous  = [];   // ticker => note
		$unknown    = 0;

		foreach ($instruments as $instrument) {

			[$ticker]   = broker_field($instrument, broker_fields('instrument_ticker'));
			if ($ticker === null || trim((string) $ticker) === '') { continue; }
			$ticker     = trim((string) $ticker);

			[$isin]     = broker_field($instrument, broker_fields('instrument_isin'));
			[$name]     = broker_field($instrument, broker_fields('instrument_name'));
			[$currency] = broker_field($instrument, broker_fields('instrument_ccy'));

			$isin  = $isin === null ? null : strtoupper(trim((string) $isin));
			$parts = broker_ticker_code($ticker);

			$row = ['broker_ticker' => $ticker,
			        'isin'          => $isin,
			        'name'          => $name === null ? null : (string) $name,
			        'currency'      => $currency === null ? null : strtoupper((string) $currency),
			        'market'        => $parts['market'],
			        'code'          => $parts['code']];

			/* ISIN first. */
			if ($isin !== null && $isin !== '' && isset($by_isin[$isin])) {
				if (count($by_isin[$isin]) === 1) {
					$row['symbol']     = $by_isin[$isin][0];
					$row['matched_on'] = 'isin';
					$candidates[$row['symbol']][] = $row;
					continue;
				}
				$ambiguous[$ticker] = 'ISIN ' . $isin . ' is on more than one of our symbols';
				continue;
			}

			$key = broker_code_key($parts['code']);
			if ($key === '' || !isset($by_key[$key])) { $unknown++; continue; }

			if (count($by_key[$key]) > 1) {
				$ambiguous[$ticker] = 'the code fits ' . implode(', ', $by_key[$key]);
				continue;
			}

			$row['symbol']     = $by_key[$key][0];
			$row['matched_on'] = 'ticker';
			$candidates[$row['symbol']][] = $row;
		}

		/* The other direction: two of the broker's instruments fitting one of
		   our symbols is the same problem. VOD on London and VOD somewhere
		   else are different shares in different currencies, and picking the
		   first one read is picking at random. A currency we can see to be
		   sterling settles it; nothing else does. */
		$rows = [];
		foreach ($candidates as $symbol => $found) {

			if (count($found) > 1) {

				$sterling = array_values(array_filter($found, function ($row) {
					return in_array((string) $row['currency'], ['GBP', 'GBX', 'GBp'], true);
				}));

				if (count($sterling) === 1) { $found = $sterling; }
				else {
					foreach ($found as $row) {
						$ambiguous[$row['broker_ticker']] =
							'more than one instrument fits ' . $symbol . ' ('
							. implode(', ', array_column($found, 'broker_ticker')) . ')';
					}
					continue;
				}
			}

			$row = $found[0];
			$row['status'] = 'matched';
			$row['note']   = null;
			$rows[] = $row;
		}

		foreach ($ambiguous as $ticker => $note) {
			$parts = broker_ticker_code($ticker);
			$rows[] = ['broker_ticker' => $ticker, 'symbol' => null, 'isin' => null,
			           'name' => null, 'currency' => null, 'market' => $parts['market'],
			           'code' => $parts['code'], 'matched_on' => null,
			           'status' => 'ambiguous', 'note' => $note];
		}

		return ['rows' => $rows,
		        'matched' => count(array_filter($rows, function ($r) { return $r['status'] === 'matched'; })),
		        'ambiguous' => count($ambiguous),
		        'unknown' => $unknown];
	}

	/** Store a matched instrument list. Re-runnable: the ticker is the key. */
	function broker_store_instruments($broker, array $rows, $as_of = null) {

		$as_of = $as_of === null ? date('Y-m-d') : $as_of;

		foreach ($rows as $row) {
			query("replace into broker_instruments
			         (broker, broker_ticker, symbol, isin, currency, name,
			          status, matched_on, note, last_seen)
			       values (?,?,?,?,?,?,?,?,?,?)",
			      $broker,
			      mb_substr((string) $row['broker_ticker'], 0, 40),
			      $row['symbol'] === null ? null : mb_substr((string) $row['symbol'], 0, 50),
			      $row['isin'] === null ? null : mb_substr((string) $row['isin'], 0, 12),
			      $row['currency'] === null ? null : mb_substr((string) $row['currency'], 0, 8),
			      $row['name'] === null ? null : mb_substr((string) $row['name'], 0, 255),
			      (string) $row['status'],
			      $row['matched_on'] === null ? null : (string) $row['matched_on'],
			      $row['note'] === null ? null : mb_substr((string) $row['note'], 0, 255),
			      $as_of);
		}
		return count($rows);
	}

	/** ticker => symbol, for the instruments that matched one of ours. */
	function broker_symbol_map($broker = BROKER_T212) {

		$out = [];
		foreach (query("select broker_ticker, symbol from broker_instruments
		                 where broker=? and status='matched' and symbol is not null",
		               $broker) as $row) {
			$out[(string) $row['broker_ticker']] = (string) $row['symbol'];
		}
		return $out;
	}

	/** When the instrument list was last refreshed, or null. */
	function broker_instruments_as_of($broker = BROKER_T212) {

		$rows = query("select max(last_seen) d from broker_instruments where broker=?", $broker);
		return (count($rows) && $rows[0]['d'] !== null) ? (string) $rows[0]['d'] : null;
	}

	/**
	 * The FTSE 350 symbols with no instrument of their own.
	 *
	 * Listed on the page rather than guessed at. A symbol here is a share the
	 * broker connection cannot see: not an error, but the reason a holding
	 * would be missing if one were opened in it.
	 */
	function broker_unmatched_symbols($broker = BROKER_T212, $exchange = null) {

		$exchange = $exchange === null ? default_exchange() : $exchange;

		return query("select s.symbol, s.name from stock_symbols s
		               where s.enabled='Y' and s.exchange=?
		                 and not exists (select 1 from broker_instruments i
		                                  where i.broker=? and i.status='matched'
		                                    and i.symbol=s.symbol)
		               order by s.symbol", $exchange, $broker);
	}

	/** The instruments that fitted more than one thing, with the reason. */
	function broker_ambiguous_instruments($broker = BROKER_T212) {
		return query("select broker_ticker, note, last_seen from broker_instruments
		               where broker=? and status='ambiguous' order by broker_ticker", $broker);
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
	function broker_store_account_value($broker, $as_of, array $values) {

		query("replace into broker_account_values
		         (broker, as_of_date, cash, invested, total, free, result, currency, synced_at)
		       values (?,?,?,?,?,?,?,?,?)",
		      $broker, $as_of,
		      $values['cash'] ?? null, $values['invested'] ?? null, $values['total'] ?? null,
		      $values['free'] ?? null, $values['result'] ?? null,
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
	function broker_store_positions($broker, $as_of, array $positions) {

		query("delete from broker_positions where broker=? and as_of_date=?", $broker, $as_of);

		foreach ($positions as $position) {
			query("replace into broker_positions
			         (broker, as_of_date, broker_ticker, symbol, quantity, average_price,
			          current_price, value, gain, price_unit, synced_at)
			       values (?,?,?,?,?,?,?,?,?,?,?)",
			      $broker, $as_of,
			      mb_substr((string) $position['broker_ticker'], 0, 40),
			      $position['symbol'] === null ? null : mb_substr((string) $position['symbol'], 0, 50),
			      $position['quantity'] ?? null,
			      $position['average_price'] ?? null,
			      $position['current_price'] ?? null,
			      $position['value'] ?? null,
			      $position['gain'] ?? null,
			      $position['price_unit'] ?? null,
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
	function broker_store_order($broker, array $order) {

		$existing = query("select first_seen f from broker_orders
		                    where broker=? and broker_order_id=?",
		                  $broker, (string) $order['broker_order_id']);
		$is_new = !count($existing);

		query("replace into broker_orders
		         (broker, broker_order_id, broker_ticker, symbol, side, quantity,
		          fill_price, fill_value, status, order_type, placed_at, filled_at,
		          price_unit, first_seen)
		       values (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
		      $broker,
		      mb_substr((string) $order['broker_order_id'], 0, 40),
		      isset($order['broker_ticker']) ? mb_substr((string) $order['broker_ticker'], 0, 40) : null,
		      isset($order['symbol']) && $order['symbol'] !== null
		        ? mb_substr((string) $order['symbol'], 0, 50) : null,
		      $order['side'] ?? null,
		      $order['quantity'] ?? null,
		      $order['fill_price'] ?? null,
		      $order['fill_value'] ?? null,
		      isset($order['status']) ? mb_substr((string) $order['status'], 0, 30) : null,
		      isset($order['order_type']) ? mb_substr((string) $order['order_type'], 0, 30) : null,
		      $order['placed_at'] ?? null,
		      $order['filled_at'] ?? null,
		      $order['price_unit'] ?? null,
		      $is_new ? date('Y-m-d H:i:s') : (string) $existing[0]['f']);

		return $is_new;
	}

	/** One dividend from the history. Returns true if it was new. */
	function broker_store_dividend($broker, array $dividend) {

		$existing = query("select first_seen f from broker_dividends
		                    where broker=? and broker_dividend_id=?",
		                  $broker, (string) $dividend['broker_dividend_id']);
		$is_new = !count($existing);

		query("replace into broker_dividends
		         (broker, broker_dividend_id, broker_ticker, symbol, amount,
		          amount_per_share, quantity, paid_on, dividend_type, first_seen)
		       values (?,?,?,?,?,?,?,?,?,?)",
		      $broker,
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
	 */
	function broker_sync($options = []) {

		$broker   = BROKER_T212;
		$exchange = isset($options["exchange"]) ? $options["exchange"] : default_exchange();
		$as_of    = isset($options['as_of']) ? $options['as_of'] : date('Y-m-d');

		$result = ['ok' => false, 'ran' => false, 'status' => 'OFF', 'message' => '',
		           'problems' => [], 'positions' => 0, 'cash' => null, 'new_orders' => 0,
		           'new_dividends' => 0, 'instruments' => 0, 'unmatched' => 0,
		           'price_unit' => null, 'requests' => 0, 'as_of' => $as_of];

		if (!broker_ready()) {
			$result['message'] = "sql/broker.sql has not been applied, so there is"
			                   . " nowhere to put what the broker says.";
			$result['problems'][] = $result['message'];
			return $result;
		}

		$client = isset($options['client']) ? $options['client'] : new T212Client();

		/* Off is the normal state of a host nobody has configured. It is not
		   an error, it writes nothing, and above all it opens no socket. */
		if (!$client->configured()) {
			$result['ok']      = true;
			$result['message'] = $client->why_not();
			return $result;
		}

		$result['ran'] = true;
		$problems = [];
		$started  = date('Y-m-d H:i:s');

		/* --- the instrument list, weekly ---------------------------------
		   One request per 50 seconds and a large answer, for something that
		   changes when a company lists or leaves. */
		$instruments_as_of = broker_instruments_as_of($broker);
		$stale = $instruments_as_of === null
		      || strtotime($instruments_as_of) < strtotime('-' . max(1, (int) T212_INSTRUMENT_DAYS) . ' day');

		if (!empty($options['instruments']) || $stale) {

			$answer = $client->get('instruments');

			if (!$answer['ok']) { $problems[] = "instruments: " . $answer['message']; }
			else {
				$rows = is_array($answer['data']) ? $answer['data'] : [];
				$matched = broker_match_instruments($rows, $exchange);
				broker_store_instruments($broker, $matched['rows'], $as_of);
				$result['instruments'] = $matched['matched'];
				if ($matched['ambiguous'] > 0) {
					$problems[] = $matched['ambiguous'] . " of the broker's instruments fit"
					            . " more than one of our shares and were not matched to any.";
				}
			}
		}

		$map = broker_symbol_map($broker);

		/* --- what the account is worth ----------------------------------- */
		$summary = $client->get('summary');
		if (!$summary['ok']) { $problems[] = "account summary: " . $summary['message']; }
		else {
			$data = is_array($summary['data']) ? $summary['data'] : [];

			$values = [
				'cash'     => broker_number($data, broker_fields('summary_cash')),
				'invested' => broker_number($data, broker_fields('summary_invested')),
				'total'    => broker_number($data, broker_fields('summary_total')),
				'free'     => broker_number($data, broker_fields('summary_free')),
				'result'   => broker_number($data, broker_fields('summary_result')),
			];
			[$currency] = broker_field($data, broker_fields('summary_currency'));
			$values['currency'] = $currency === null ? 'GBP' : (string) $currency;

			if ($values['cash'] === null && $values['total'] === null) {
				$problems[] = "the account summary carried none of the field names this"
				            . " knows for cash or total (" . implode(', ', array_keys($data))
				            . "). Nothing was stored for it.";
			}
			else {
				broker_store_account_value($broker, $as_of, $values);
				$result['cash'] = $values['cash'];
			}
		}

		/* --- the positions ------------------------------------------------ */
		$positions = $client->get('positions');
		if (!$positions['ok']) { $problems[] = "positions: " . $positions['message']; }
		else {

			$rows = is_array($positions['data']) ? $positions['data'] : [];
			if (isset($rows['items']) && is_array($rows['items'])) { $rows = $rows['items']; }

			$parsed = []; $samples = [];

			foreach ($rows as $row) {

				[$ticker] = broker_field($row, broker_fields('position_ticker'));
				if ($ticker === null) { continue; }
				$ticker = (string) $ticker;
				$symbol = isset($map[$ticker]) ? $map[$ticker] : null;

				$one = [
					'broker_ticker' => $ticker,
					'symbol'        => $symbol,
					'quantity'      => broker_number($row, broker_fields('position_quantity')),
					'average'       => broker_number($row, broker_fields('position_average')),
					'current'       => broker_number($row, broker_fields('position_current')),
					'value'         => broker_number($row, broker_fields('position_value')),
					'result'        => broker_number($row, broker_fields('position_result')),
				];
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

			$unit = broker_price_unit($samples);
			$result['price_unit'] = $unit['unit'];
			if ($unit['unit'] === null && count($parsed)) { $problems[] = $unit['why']; }

			$store = [];
			foreach ($parsed as $row) {

				$average = broker_to_pounds($row['average'], $unit['unit']);
				$current = broker_to_pounds($row['current'], $unit['unit']);

				/* The value the broker sent is money, not a price, so it is
				   in the account's currency whatever the prices are in. When
				   it did not send one, quantity times the price is the same
				   number - once the price is in pounds. */
				$value = $row['value'];
				if ($value === null && $current !== null && $row['quantity'] !== null) {
					$value = $current * (float) $row['quantity'];
				}

				$gain = $row['result'];
				if ($gain === null && $value !== null && $average !== null && $row['quantity'] !== null) {
					$gain = $value - $average * (float) $row['quantity'];
				}

				$store[] = ['broker_ticker' => $row['broker_ticker'],
				            'symbol'        => $row['symbol'],
				            'quantity'      => $row['quantity'],
				            'average_price' => $average,
				            'current_price' => $current,
				            'value'         => $value,
				            'gain'          => $gain,
				            'price_unit'    => $unit['unit']];
			}

			broker_store_positions($broker, $as_of, $store);
			$result['positions'] = count($store);

			$unknown = count(array_filter($store, function ($r) { return $r['symbol'] === null; }));
			if ($unknown > 0) {
				$problems[] = $unknown . " holding(s) are in instruments this application"
				            . " could not match to one of its own shares, so they are"
				            . " shown by the broker's ticker.";
			}
		}

		/* --- the history: orders and dividends ----------------------------
		   Paged to the end, or to T212_MAX_PAGES. The API takes a cursor and
		   a limit and the documentation describes no date filter, so none is
		   invented: the rows are keyed on the broker's own ids and a re-run
		   writes nothing new. */
		$orders = $client->get_all('order_history');
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

			/* A sell is a negative quantity, which is how Trading 212 says
			   which way round a trade went. Stored as a side and a positive
			   number, because "-200 SELL" reads like two minus signs. */
			$side = $quantity === null ? null : ($quantity < 0 ? 'SELL' : 'BUY');

			$value = broker_number($row, broker_fields('order_value'));
			$price_pounds = broker_to_pounds($price, $result['price_unit']);
			if ($value === null && $price_pounds !== null && $quantity !== null) {
				$value = abs($quantity) * $price_pounds;
			}

			$new = broker_store_order($broker, [
				'broker_order_id' => (string) $id,
				'broker_ticker'   => $ticker,
				'symbol'          => ($ticker !== null && isset($map[$ticker])) ? $map[$ticker] : null,
				'side'            => $side,
				'quantity'        => $quantity === null ? null : abs($quantity),
				'fill_price'      => $price_pounds,
				'fill_value'      => $value === null ? null : abs($value),
				'status'          => $status,
				'order_type'      => $type,
				'placed_at'       => broker_datetime($row, broker_fields('order_placed')),
				'filled_at'       => broker_datetime($row, broker_fields('order_filled')),
				'price_unit'      => $result['price_unit'],
			]);
			if ($new) { $result['new_orders']++; }
		}

		$dividends = $client->get_all('dividends');
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
			]);
			if ($new) { $result['new_dividends']++; }
		}

		/* --- the record ---------------------------------------------------- */
		foreach ($client->problems() as $problem) {
			if (!in_array($problem, $problems, true)) { $problems[] = $problem; }
		}
		$problems = array_map('t212_mask', $problems);

		$result['unmatched'] = count(broker_unmatched_symbols($broker, $exchange));
		$result['requests']  = $client->requests();
		$result['problems']  = $problems;
		$result['status']    = count($problems) === 0 ? 'OK'
		                     : ($result['positions'] > 0 || $result['cash'] !== null
		                        ? 'PARTIAL' : 'FAILED');
		$result['ok']        = $result['status'] !== 'FAILED';
		$result['message']   = $result['status'] === 'OK'
		                     ? "read " . $result['positions'] . " position(s)"
		                     : implode(' ', $problems);

		query("insert into broker_sync_runs
		         (broker, environment, started_at, finished_at, status, as_of_date,
		          price_unit, positions, cash, new_orders, new_dividends, instruments,
		          unmatched, requests, problems)
		       values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
		      $broker, $client->environment(), $started, date('Y-m-d H:i:s'),
		      $result['status'], $as_of, $result['price_unit'], $result['positions'],
		      $result['cash'], $result['new_orders'], $result['new_dividends'],
		      $result['instruments'], $result['unmatched'], $result['requests'],
		      count($problems) ? t212_mask(implode("\n", $problems)) : null);

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
		        . (count($problems) ? " problems=" . t212_mask(implode('; ', $problems)) : ""));

		return $result;
	}

	/* ---------------------------------------------------------------------
	   What the page reads.
	   --------------------------------------------------------------------- */

	/** The most recent sync attempt, successful or not. */
	function broker_last_run($broker = BROKER_T212) {

		if (!broker_ready()) { return null; }
		$rows = query("select * from broker_sync_runs where broker=?
		                order by run_id desc limit 1", $broker);
		return count($rows) ? $rows[0] : null;
	}

	/** The connection, in the words the page prints. */
	function broker_status($client = null) {

		$client = $client === null ? new T212Client() : $client;
		$run    = broker_last_run();

		return [
			'environment' => $client->environment(),
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
			'instruments_as_of' => broker_ready() ? broker_instruments_as_of() : null,
		];
	}

	/** The latest figures for the account. */
	function broker_latest_values($broker = BROKER_T212) {

		if (!broker_ready()) { return null; }
		$rows = query("select * from broker_account_values where broker=?
		                order by as_of_date desc limit 1", $broker);
		return count($rows) ? $rows[0] : null;
	}

	/** The latest positions, with our own name for the share where we know it. */
	function broker_latest_positions($broker = BROKER_T212) {

		if (!broker_ready()) { return []; }

		$rows = query("select max(as_of_date) d from broker_positions where broker=?", $broker);
		if (!count($rows) || $rows[0]['d'] === null) { return []; }

		return query("select p.*, s.name
		                from broker_positions p
		                left join stock_symbols s on s.symbol=p.symbol and s.enabled='Y'
		               where p.broker=? and p.as_of_date=?
		               order by p.value desc, p.broker_ticker", $broker, (string) $rows[0]['d']);
	}

	function broker_recent_orders($broker = BROKER_T212, $limit = 20) {
		if (!broker_ready()) { return []; }
		$limit = max(1, min(200, (int) $limit));
		return query("select * from broker_orders where broker=?
		               order by coalesce(filled_at, placed_at) desc, broker_order_id desc
		               limit " . $limit, $broker);
	}

	function broker_recent_dividends($broker = BROKER_T212, $limit = 20) {
		if (!broker_ready()) { return []; }
		$limit = max(1, min(200, (int) $limit));
		return query("select * from broker_dividends where broker=?
		               order by paid_on desc, broker_dividend_id desc limit " . $limit, $broker);
	}

/* No closing tag: see the note at the end of constants.php. */
