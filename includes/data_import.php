<?php

	/**
	 * The data_import module (contracts: data_import, 21 Nov 2026): the only way
	 * data comes in from the Mac mini. Separate from backtest_api; the same
	 * caller check and the same token (one secret per machine pair).
	 *
	 * Each request is a POST with a JSON body:
	 *
	 *   {"action": "symbols"}
	 *       the N100's symbols (enabled, XLON) and the data version it holds
	 *
	 *   {"action": "batch", "publish_id", "seq",
	 *    "prices":     [[symbol, date, price], ...],
	 *    "statistics": [[symbol, date, indicator, value], ...]}
	 *       stage rows. Refused whole if any row is for a symbol not in
	 *       stock_symbols, a statistic the N100 owns, a weekend, or a value
	 *       that is not a number.
	 *
	 *   {"action": "close", "publish_id", "data_version", "full",
	 *    "replace_from": {symbol: date, ...},
	 *    "statistic_from": {indicator: {symbol: date, ...}, ...},   (optional)
	 *    "batches", "price_rows", "statistic_rows", "missing": [symbols]}
	 *       make the staged publish live in one transaction: for each symbol in
	 *       replace_from, its prices and published statistics from that date on
	 *       are replaced by exactly the staged rows. statistic_from moves one
	 *       statistic's start earlier for a symbol (the accounts statistics,
	 *       sent with their whole history from before the prices'): that
	 *       statistic's rows for the symbol are replaced from the earlier of the
	 *       two dates. Then the publish is recorded in data_publishes. The
	 *       counts must match what was staged, or nothing changes.
	 *
	 *   {"action": "abort", "publish_id"}
	 *       throw the staged rows away.
	 *
	 * Writes only historical_prices, statistics (the published names, see
	 * data_ownership.php), its staging tables and data_publishes. A publish
	 * that never closes changes nothing the strategies read. A rank and
	 * percentile already on a replaced statistics row is kept: ranking is the
	 * N100's (get_statistics.php).
	 *
	 * The caller has to come from the Tailscale range and carry the token; a
	 * refusal is logged (module data_import) with the address and the reason,
	 * never the token.
	 */

	require_once("constants.php");
	require_once("functions.php");
	require_once(__DIR__ . "/data_ownership.php");
	/* The token reader and the Tailscale check. Only those two functions are
	   used from it; nothing here goes near the backtest queue. */
	require_once(__DIR__ . "/backtest_api.php");

	if (!defined('DATA_IMPORT_EXCHANGE')) { define('DATA_IMPORT_EXCHANGE', 'XLON'); }
	/* The most rows one batch may carry. */
	if (!defined('DATA_IMPORT_MAX_ROWS')) { define('DATA_IMPORT_MAX_ROWS', 50000); }
	/* Staged rows of a publish that never closed are thrown away after this long. */
	if (!defined('DATA_IMPORT_STALE_HOURS')) { define('DATA_IMPORT_STALE_HOURS', 12); }

	/** Whether sql/data_import.sql has been applied. */
	function data_import_ready() {
		$rows = query("select count(*) n from information_schema.tables where table_schema=database()
		                and table_name in ('data_import_batches','data_import_prices','data_import_statistics','data_publishes')");
		return is_array($rows) && (int) $rows[0]['n'] === 4;
	}

	/** The newest closed publish, or null. */
	function data_import_last_publish() {
		$rows = query("select * from data_publishes order by id desc limit 1");
		return (is_array($rows) && count($rows)) ? $rows[0] : null;
	}

	/** The data version the N100 holds: that of the newest publish, or null before the first. */
	function data_import_held_version() {
		$last = data_import_last_publish();
		return $last === null ? null : (int) $last['data_version'];
	}

	function data_import_valid_id($id) {
		return is_string($id) && preg_match('~^[A-Za-z0-9_.-]{8,64}$~', $id) === 1;
	}

	function data_import_valid_date($d) {
		if (!is_string($d) || !preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $d, $m)) { return false; }
		return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
	}

	function data_import_weekend($d) {
		return (int) date('N', strtotime($d . ' 12:00:00')) >= 6;
	}

	function data_import_number($v) {
		return (is_int($v) || is_float($v)) && is_finite((float) $v);
	}

	/** Every symbol in stock_symbols on the exchange, as a set. */
	function data_import_known_symbols() {
		$out = [];
		foreach (query("select symbol from stock_symbols where exchange=?", DATA_IMPORT_EXCHANGE) as $r) {
			$out[(string) $r['symbol']] = true;
		}
		return $out;
	}

	/** Throw away staged rows of publishes that never closed. */
	function data_import_purge_stale() {
		$cut = date('Y-m-d H:i:s', time() - DATA_IMPORT_STALE_HOURS * 3600);
		$old = query("select distinct publish_id from data_import_batches b
		               where received_at < ? and not exists
		                     (select 1 from data_import_batches c where c.publish_id=b.publish_id and c.received_at >= ?)",
		             $cut, $cut);
		foreach ($old ?: [] as $r) {
			data_import_drop($r['publish_id']);
			write_log("data_import", "staged publish " . $r['publish_id'] . " never closed; its rows were thrown away");
		}
	}

	function data_import_drop($publish_id) {
		query("delete from data_import_prices where publish_id=?", $publish_id);
		query("delete from data_import_statistics where publish_id=?", $publish_id);
		query("delete from data_import_batches where publish_id=?", $publish_id);
	}

	/**
	 * Answer one request. $request: method, remote_addr, authorization (the
	 * header), body (raw). Returns [http status, array to send as JSON].
	 */
	function data_import_handle(array $request) {

		$ip = (string) ($request['remote_addr'] ?? '');
		$refuse = function ($status, $why) use ($ip) {
			write_log("data_import", "refused " . $ip . ": " . $why);
			return [$status, ['ok' => false, 'why' => $why]];
		};

		if (!backtest_api_tailscale($ip)) { return $refuse(403, "the caller is not on the Tailscale network."); }

		$token = backtest_api_token();
		if ($token === '') { return $refuse(503, "no API token is set on the server (" . BACKTEST_TOKEN_KEY . ")."); }
		$auth = (string) ($request['authorization'] ?? '');
		if (!preg_match('~^Bearer\s+(\S+)$~', $auth, $m)) { return $refuse(401, "no token was given."); }
		if (!hash_equals($token, $m[1])) { return $refuse(401, "the token is wrong."); }

		if (strtoupper((string) ($request['method'] ?? '')) !== 'POST') { return $refuse(405, "only POST is answered."); }
		if (!data_import_ready()) { return $refuse(503, "the data_import tables are not migrated (sql/data_import.sql)."); }

		$body = json_decode((string) ($request['body'] ?? ''), true);
		if (!is_array($body)) { return $refuse(400, "the body is not a JSON object."); }

		switch ($body['action'] ?? null) {
			case 'symbols': $out = data_import_symbols(); break;
			case 'batch':   $out = data_import_batch($body); break;
			case 'close':   $out = data_import_close($body); break;
			case 'abort':   $out = data_import_abort($body); break;
			default:        return $refuse(400, "the action is symbols, batch, close or abort.");
		}
		return $out['ok'] ? [200, $out] : $refuse(409, $out['why']);
	}

	function data_import_symbols() {
		data_import_purge_stale();
		$symbols = array_map(function ($r) { return (string) $r['symbol']; },
		                     query("select symbol from stock_symbols where exchange=? and enabled='Y' order by symbol",
		                           DATA_IMPORT_EXCHANGE));
		$last = data_import_last_publish();
		return ['ok' => true, 'exchange' => DATA_IMPORT_EXCHANGE, 'symbols' => $symbols,
		        'data_version' => $last === null ? null : (int) $last['data_version'],
		        'latest_date' => $last === null ? null : $last['latest_date'],
		        'publish_id' => $last === null ? null : $last['publish_id'],
		        'statistics' => DATA_PUBLISHED_STATISTICS];
	}

	function data_import_batch(array $body) {

		$pid = $body['publish_id'] ?? null;
		if (!data_import_valid_id($pid)) { return ['ok' => false, 'why' => "the publish_id is missing or not one."]; }
		$seq = $body['seq'] ?? null;
		if (!is_int($seq) || $seq < 0) { return ['ok' => false, 'why' => "the seq is missing or not a whole number."]; }
		if (count(query("select 1 from data_publishes where publish_id=?", $pid))) {
			return ['ok' => false, 'why' => "publish $pid is already closed."];
		}
		if (count(query("select 1 from data_import_batches where publish_id=? and seq=?", $pid, $seq))) {
			return ['ok' => false, 'why' => "batch $seq of publish $pid was already received."];
		}
		$prices = $body['prices'] ?? [];
		$stats = $body['statistics'] ?? [];
		if (!is_array($prices) || !is_array($stats)) { return ['ok' => false, 'why' => "prices and statistics are lists."]; }
		if (count($prices) + count($stats) > DATA_IMPORT_MAX_ROWS) {
			return ['ok' => false, 'why' => "a batch carries at most " . DATA_IMPORT_MAX_ROWS . " rows."];
		}

		$known = data_import_known_symbols();
		$bad = function ($what, $i, $why) { return ['ok' => false, 'why' => "$what row $i: $why; nothing in this batch was staged."]; };
		foreach ($prices as $i => $r) {
			if (!is_array($r) || count($r) !== 3) { return $bad('price', $i, "not [symbol, date, price]"); }
			[$s, $d, $p] = $r;
			if (!is_string($s) || !isset($known[$s])) { return $bad('price', $i, "symbol " . json_encode($s) . " is not in stock_symbols"); }
			if (!data_import_valid_date($d)) { return $bad('price', $i, "date " . json_encode($d) . " is not a date"); }
			if (data_import_weekend($d)) { return $bad('price', $i, "$d is a weekend"); }
			if (!data_import_number($p) || $p <= 0) { return $bad('price', $i, "the price is not a positive number"); }
		}
		foreach ($stats as $i => $r) {
			if (!is_array($r) || count($r) !== 4) { return $bad('statistic', $i, "not [symbol, date, indicator, value]"); }
			[$s, $d, $n, $v] = $r;
			if (!is_string($s) || !isset($known[$s])) { return $bad('statistic', $i, "symbol " . json_encode($s) . " is not in stock_symbols"); }
			if (!data_import_valid_date($d)) { return $bad('statistic', $i, "date " . json_encode($d) . " is not a date"); }
			if (data_import_weekend($d)) { return $bad('statistic', $i, "$d is a weekend"); }
			if (!data_published_statistic($n)) { return $bad('statistic', $i, "statistic " . json_encode($n) . " is the N100's, not the Mac mini's"); }
			if (!data_import_number($v)) { return $bad('statistic', $i, "the value is not a number"); }
		}

		query("start transaction");
		$ok = true;
		foreach (array_chunk($prices, 1000) as $chunk) {
			$params = [];
			foreach ($chunk as $r) { array_push($params, $pid, $r[0], $r[1], $r[2]); }
			$ok = $ok && query("insert into data_import_prices (publish_id, symbol, date, price) values "
			                   . implode(',', array_fill(0, count($chunk), '(?,?,?,?)')), ...$params) !== false;
		}
		foreach (array_chunk($stats, 1000) as $chunk) {
			$params = [];
			foreach ($chunk as $r) { array_push($params, $pid, $r[0], $r[1], $r[2], $r[3]); }
			$ok = $ok && query("insert into data_import_statistics (publish_id, symbol, date, indicator, value) values "
			                   . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?)')), ...$params) !== false;
		}
		$ok = $ok && query("insert into data_import_batches (publish_id, seq, price_rows, stat_rows, received_at) values (?,?,?,?,?)",
		                   $pid, $seq, count($prices), count($stats), date('Y-m-d H:i:s')) !== false;
		if (!$ok) {
			query("rollback");
			return ['ok' => false, 'why' => "batch $seq could not be staged (a row repeats one already staged in this publish?); nothing in it was kept."];
		}
		query("commit");
		return ['ok' => true, 'publish_id' => $pid, 'seq' => $seq, 'prices' => count($prices), 'statistics' => count($stats)];
	}

	function data_import_abort(array $body) {
		$pid = $body['publish_id'] ?? null;
		if (!data_import_valid_id($pid)) { return ['ok' => false, 'why' => "the publish_id is missing or not one."]; }
		if (count(query("select 1 from data_publishes where publish_id=?", $pid))) {
			return ['ok' => false, 'why' => "publish $pid is closed; a closed publish is not undone."];
		}
		data_import_drop($pid);
		write_log("data_import", "publish $pid aborted by the Mac mini; staged rows thrown away: " . substr((string) ($body['why'] ?? ''), 0, 500));
		return ['ok' => true];
	}

	/**
	 * Old against new for the rows a publish is about to replace: per price and
	 * per statistic, how many were compared, how many differ by more than half
	 * a per cent, and the twenty largest relative differences.
	 */
	function data_import_differences($pid) {

		$diff_sql = function ($select_old, $join, $where, $params) {
			$expr = "abs(n.v - o.v) / greatest(abs(o.v), 0.000001)";
			$summary = query("select count(*) compared, sum($expr > 0.005) differing
			                    from (select $select_old) o $join where $where", ...$params);
			$top = query("select o.symbol, o.date, o.v old_value, n.v new_value, round($expr, 6) rel_diff
			                from (select $select_old) o $join where $where and $expr > 0
			               order by $expr desc limit 20", ...$params);
			return ['compared' => (int) ($summary[0]['compared'] ?? 0), 'differing' => (int) ($summary[0]['differing'] ?? 0),
			        'largest' => $top ?: []];
		};

		$out = [];
		$out['price'] = $diff_sql(
			"h.symbol, h.date, h.price v from historical_prices h
			   join data_import_ranges r on r.symbol=h.symbol and h.date>=r.from_date where h.exchange=?",
			"join (select symbol, date, price v from data_import_prices where publish_id=?) n on n.symbol=o.symbol and n.date=o.date",
			"1=1", [DATA_IMPORT_EXCHANGE, $pid]);
		foreach (DATA_PUBLISHED_STATISTICS as $name) {
			$out[$name] = $diff_sql(
				"s.symbol, s.date, s.value v from statistics s
				   join data_import_stat_ranges r on r.symbol=s.symbol and r.indicator=s.indicator and s.date>=r.from_date
				  where s.exchange=? and s.indicator=?",
				"join (select symbol, date, value v from data_import_statistics where publish_id=? and indicator=?) n
				   on n.symbol=o.symbol and n.date=o.date",
				"1=1", [DATA_IMPORT_EXCHANGE, $name, $pid, $name]);
		}
		return $out;
	}

	function data_import_close(array $body) {

		$pid = $body['publish_id'] ?? null;
		if (!data_import_valid_id($pid)) { return ['ok' => false, 'why' => "the publish_id is missing or not one."]; }
		if (count(query("select 1 from data_publishes where publish_id=?", $pid))) {
			return ['ok' => false, 'why' => "publish $pid is already closed."];
		}
		$version = $body['data_version'] ?? null;
		if (!is_int($version) || $version < 0) { return ['ok' => false, 'why' => "the data_version is missing or not a whole number."]; }
		$held = data_import_held_version();
		if ($held !== null && $version < $held) {
			return ['ok' => false, 'why' => "data version $version is older than the $held the N100 holds."];
		}
		$full = ($body['full'] ?? false) === true;
		$ranges = $body['replace_from'] ?? [];
		$missing = $body['missing'] ?? [];
		$stat_from = $body['statistic_from'] ?? [];
		if (!is_array($ranges) || !is_array($missing)) { return ['ok' => false, 'why' => "replace_from is a map and missing a list."]; }
		if (!is_array($stat_from)) { return ['ok' => false, 'why' => "statistic_from is a map of statistic to a map of symbol to date."]; }
		foreach ($stat_from as $n => $per) {
			if (!data_published_statistic($n)) { return ['ok' => false, 'why' => "statistic_from names " . json_encode($n) . ", which is the N100's, not the Mac mini's."]; }
			if (!is_array($per)) { return ['ok' => false, 'why' => "statistic_from for $n is not a map of symbol to date."]; }
			foreach ($per as $s => $d) {
				if (!array_key_exists((string) $s, $ranges)) { return ['ok' => false, 'why' => "statistic_from for $n names " . json_encode($s) . ", which is not in replace_from."]; }
				if (!data_import_valid_date($d)) { return ['ok' => false, 'why' => "statistic_from for $n and $s is not a date."]; }
			}
		}

		$known = data_import_known_symbols();
		foreach ($ranges as $s => $d) {
			if (!isset($known[(string) $s])) { return ['ok' => false, 'why' => "replace_from names " . json_encode($s) . ", which is not in stock_symbols."]; }
			if (!data_import_valid_date($d)) { return ['ok' => false, 'why' => "replace_from for $s is not a date."]; }
		}
		$missing = array_values(array_filter($missing, 'is_string'));

		/* What was staged has to be what the Mac mini says it sent. */
		$staged = query("select count(*) batches, coalesce(sum(price_rows),0) p, coalesce(sum(stat_rows),0) s
		                   from data_import_batches where publish_id=?", $pid)[0];
		$np = (int) query("select count(*) n from data_import_prices where publish_id=?", $pid)[0]['n'];
		$ns = (int) query("select count(*) n from data_import_statistics where publish_id=?", $pid)[0]['n'];
		$want = [(int) ($body['batches'] ?? -1), (int) ($body['price_rows'] ?? -1), (int) ($body['statistic_rows'] ?? -1)];
		if ([(int) $staged['batches'], $np, $ns] !== $want || (int) $staged['p'] !== $np || (int) $staged['s'] !== $ns) {
			return ['ok' => false, 'why' => sprintf("the publish is incomplete: staged %d batches, %d prices, %d statistics; "
			        . "the close says %d, %d, %d. Nothing was changed.", $staged['batches'], $np, $ns, $want[0], $want[1], $want[2])];
		}

		/* The ranges, where SQL can join them. */
		query("drop temporary table if exists data_import_ranges");
		query("drop temporary table if exists data_import_stat_ranges");
		query("create temporary table data_import_ranges (symbol varchar(50) not null primary key, from_date date not null)");
		foreach (array_chunk(array_keys($ranges), 1000) as $chunk) {
			$params = [];
			foreach ($chunk as $s) { array_push($params, (string) $s, $ranges[$s]); }
			query("insert into data_import_ranges (symbol, from_date) values "
			      . implode(',', array_fill(0, count($chunk), '(?,?)')), ...$params);
		}

		/* Each published statistic's range per symbol: the symbol's, or earlier
		   where statistic_from says so. */
		query("drop temporary table if exists data_import_stat_ranges");
		query("create temporary table data_import_stat_ranges (indicator varchar(50) not null, symbol varchar(50) not null,
		       from_date date not null, primary key (indicator, symbol))");
		$stat_rows = [];
		foreach (DATA_PUBLISHED_STATISTICS as $name) {
			foreach ($ranges as $s => $d) {
				$earlier = $stat_from[$name][$s] ?? null;
				$stat_rows[] = [$name, (string) $s, ($earlier !== null && $earlier < $d) ? $earlier : $d];
			}
		}
		foreach (array_chunk($stat_rows, 1000) as $chunk) {
			query("insert into data_import_stat_ranges (indicator, symbol, from_date) values "
			      . implode(',', array_fill(0, count($chunk), '(?,?,?)')), ...array_merge(...$chunk));
		}

		/* Every staged row has to fall inside its range, or the close would
		   add a row and leave an older one beside it. */
		$outside = (int) query("select count(*) n from data_import_prices p left join data_import_ranges r on r.symbol=p.symbol
		                         where p.publish_id=? and (r.symbol is null or p.date < r.from_date)", $pid)[0]['n']
		         + (int) query("select count(*) n from data_import_statistics p left join data_import_stat_ranges r
		                          on r.symbol=p.symbol and r.indicator=p.indicator
		                         where p.publish_id=? and (r.symbol is null or p.date < r.from_date)", $pid)[0]['n'];
		if ($outside > 0) {
			return ['ok' => false, 'why' => "$outside staged rows fall outside replace_from. Nothing was changed."];
		}

		$report = data_import_differences($pid);
		$in = implode(',', array_fill(0, count(DATA_PUBLISHED_STATISTICS), '?'));
		$ex = DATA_IMPORT_EXCHANGE;

		$prices_replaced = (int) query("select count(*) n from historical_prices h join data_import_ranges r
		                                  on r.symbol=h.symbol and h.date>=r.from_date where h.exchange=?", $ex)[0]['n'];
		$stats_replaced = (int) query("select count(*) n from statistics s join data_import_stat_ranges r
		                                 on r.symbol=s.symbol and r.indicator=s.indicator and s.date>=r.from_date
		                                where s.exchange=? and s.indicator in ($in)", $ex, ...DATA_PUBLISHED_STATISTICS)[0]['n'];
		$latest = query("select max(date) d from data_import_prices where publish_id=?", $pid)[0]['d'];
		if ($latest === null) {
			$last = data_import_last_publish();
			$latest = $last === null ? null : $last['latest_date'];
		}

		query("start transaction");
		$steps = [
			/* Keep the N100's rank and percentile on a row that is replaced. */
			["update data_import_statistics n
			    join statistics s on s.symbol=n.symbol and s.date=n.date and s.indicator=n.indicator and s.exchange=?
			     set n.stat_rank=s.stat_rank, n.percentile=s.percentile
			   where n.publish_id=?", [$ex, $pid]],
			["delete h from historical_prices h join data_import_ranges r on r.symbol=h.symbol and h.date>=r.from_date
			   where h.exchange=?", [$ex]],
			["insert into historical_prices (symbol, exchange, date, price)
			  select symbol, ?, date, price from data_import_prices where publish_id=?", [$ex, $pid]],
			["delete s from statistics s join data_import_stat_ranges r
			     on r.symbol=s.symbol and r.indicator=s.indicator and s.date>=r.from_date
			   where s.exchange=? and s.indicator in ($in)", array_merge([$ex], DATA_PUBLISHED_STATISTICS)],
			["insert into statistics (symbol, date, indicator, value, exchange, stat_rank, percentile)
			  select symbol, date, indicator, value, ?, stat_rank, percentile from data_import_statistics where publish_id=?", [$ex, $pid]],
			["insert into data_publishes (publish_id, data_version, full_publish, latest_date, closed_at, batches, price_rows,
			                              statistic_rows, prices_replaced, statistics_replaced, symbols_sent, symbols_missing, report)
			  values (?,?,?,?,?,?,?,?,?,?,?,?,?)",
			 [$pid, $version, $full ? 'Y' : 'N', $latest, date('Y-m-d H:i:s'), (int) $staged['batches'], $np, $ns,
			  $prices_replaced, $stats_replaced, count($ranges), json_encode($missing), json_encode($report)]],
			["delete from data_import_prices where publish_id=?", [$pid]],
			["delete from data_import_statistics where publish_id=?", [$pid]],
			["delete from data_import_batches where publish_id=?", [$pid]],
		];
		foreach ($steps as $i => [$sql, $params]) {
			if (query($sql, ...$params) === false) {
				query("rollback");
				query("drop temporary table if exists data_import_ranges");
				query("drop temporary table if exists data_import_stat_ranges");
				return ['ok' => false, 'why' => "the close failed at step $i and was rolled back; nothing was changed."];
			}
		}
		query("commit");
		query("drop temporary table if exists data_import_ranges");
		query("drop temporary table if exists data_import_stat_ranges");

		write_log("data_import", sprintf("publish %s closed: data version %d%s, latest date %s, %d prices and %d statistics "
		          . "(replacing %d and %d) for %d symbols; missing: %s", $pid, $version, $full ? " (full)" : "",
		          $latest ?? 'none', $np, $ns, $prices_replaced, $stats_replaced, count($ranges),
		          count($missing) ? implode(', ', $missing) : 'none'));

		return ['ok' => true, 'publish_id' => $pid, 'data_version' => $version, 'latest_date' => $latest,
		        'prices' => $np, 'statistics' => $ns, 'prices_replaced' => $prices_replaced,
		        'statistics_replaced' => $stats_replaced, 'symbols' => count($ranges), 'missing' => $missing,
		        'differences' => $report];
	}

/* No closing tag: see the note at the end of constants.php. */
