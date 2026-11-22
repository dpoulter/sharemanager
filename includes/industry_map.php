<?php

	/**
	 * industry_map (contracts/modules.yaml): which ICB industry a share
	 * belongs to.
	 *
	 * Sector names are a mess: 78 different ones among the N100's enabled
	 * shares, from mixed ICB versions and providers (Banks, Banking, Finance
	 * and Credit Services), and FMP's own sector and industry names on the Mac
	 * mini. This module hides how those names map to ICB's 11 industries.
	 *
	 * In: a raw sector name (stock_symbols.sector, or FMP's sector and
	 * industry). Out: one of the 11 industries or INDUSTRY_UNMAPPED, and the
	 * map's version.
	 *
	 * - A name is looked up exactly as it is written (spaces at the ends
	 *   aside). A name the map does not hold is unmapped: nothing is guessed
	 *   from the text of a name.
	 * - A name is in the map once (the table's key), so it has one industry.
	 * - The unmapped names among enabled shares can be listed.
	 * - The version is worked out from the rows, so any change to the map,
	 *   by whatever hand, is a new version.
	 *
	 * The map is data (sql/industry_map.sql, reviewed by Dale). This file
	 * owns the tables `industries` and `industry_map` and is their only
	 * writer; no other module compares raw sector text to decide an industry.
	 * It is also the only place that reads stock_symbols.sector for that.
	 */

	require_once("constants.php");
	require_once("functions.php");

	if (!defined('INDUSTRY_UNMAPPED')) { define('INDUSTRY_UNMAPPED', 'unmapped'); }

	/** Whether sql/industry_map.sql has been applied. */
	function industry_map_ready() {

		static $ready = null;
		if ($ready === true) { return true; }

		$rows = query("select count(*) n from information_schema.tables
		                where table_schema=database() and table_name in ('industries','industry_map')");
		$ready = is_array($rows) && count($rows) && (int) $rows[0]['n'] === 2;
		return $ready;
	}

	/** The 11 industries, in their order. */
	function industry_names() {

		if (!industry_map_ready()) { return []; }
		return array_map(function ($r) { return (string) $r['name']; },
		                 query("select name from industries order by position, name"));
	}

	/** Every row of the map, in a fixed order. */
	function industry_map_rows() {

		if (!industry_map_ready()) { return []; }
		return query("select source, sector, fmp_industry, industry from industry_map
		               order by source, sector, fmp_industry");
	}

	/**
	 * The map's version: the same rows give the same value, and any change
	 * to a row or to the list of industries gives another.
	 */
	function industry_map_version() {

		$content = [industry_names()];
		foreach (industry_map_rows() as $r) {
			$content[] = [(string) $r['source'], (string) $r['sector'], (string) $r['fmp_industry'], (string) $r['industry']];
		}
		return substr(sha1(json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 12);
	}

	/** The map as lookups: ['n100'=>[sector=>industry], 'fmp'=>[sector=>industry], 'fmp_exceptions'=>[sector=>[fmp industry=>industry]]]. */
	function industry_map_lookups() {

		$out = ['n100' => [], 'fmp' => [], 'fmp_exceptions' => []];
		foreach (industry_map_rows() as $r) {
			if ($r['source'] === 'n100') { $out['n100'][(string) $r['sector']] = (string) $r['industry']; }
			else if ($r['source'] === 'fmp' && (string) $r['fmp_industry'] === '') {
				$out['fmp'][(string) $r['sector']] = (string) $r['industry'];
			}
			else if ($r['source'] === 'fmp') {
				$out['fmp_exceptions'][(string) $r['sector']][(string) $r['fmp_industry']] = (string) $r['industry'];
			}
		}
		return $out;
	}

	/** One name against one lookup: the industry, or unmapped. Exact, never by resemblance. */
	function industry_lookup(array $names, $raw) {

		$raw = trim((string) $raw);
		return ($raw !== '' && isset($names[$raw])) ? $names[$raw] : INDUSTRY_UNMAPPED;
	}

	/** The industry of an N100 sector name: ['industry'=>one of the 11 or 'unmapped', 'version'=>...]. */
	function industry_for_sector($raw) {
		return ['industry' => industry_lookup(industry_map_lookups()['n100'], $raw),
		        'version'  => industry_map_version()];
	}

	/** The industry of an FMP sector and industry: the exception for that industry if there is one, else the sector's. */
	function industry_for_fmp($sector, $fmp_industry) {

		$map = industry_map_lookups();
		$sector = trim((string) $sector);
		$fmp_industry = trim((string) $fmp_industry);
		$found = isset($map['fmp_exceptions'][$sector][$fmp_industry]) && $fmp_industry !== ''
		       ? $map['fmp_exceptions'][$sector][$fmp_industry]
		       : industry_lookup($map['fmp'], $sector);
		return ['industry' => $found, 'version' => industry_map_version()];
	}

	/**
	 * The FMP half of the map, as a backtest claim carries it: the Mac mini
	 * applies it to its own shares and keeps no copy.
	 */
	function industry_map_fmp() {

		$map = industry_map_lookups();
		$exceptions = [];
		foreach ($map['fmp_exceptions'] as $sector => $by_industry) {
			foreach ($by_industry as $fmp_industry => $industry) { $exceptions[] = [$sector, $fmp_industry, $industry]; }
		}
		return ['version' => industry_map_version(), 'industries' => industry_names(),
		        'fmp' => ['sectors' => (object) $map['fmp'], 'exceptions' => $exceptions]];
	}

	/**
	 * The industry of each of these N100 shares: [symbol => industry or
	 * 'unmapped']. A share with no sector, or not in stock_symbols, is
	 * unmapped. An enabled line decides where a symbol has more than one.
	 */
	function industry_of_symbols(array $symbols) {

		$out = [];
		foreach ($symbols as $symbol) { $out[(string) $symbol] = INDUSTRY_UNMAPPED; }
		if (!count($out)) { return $out; }

		$names = industry_map_lookups()['n100'];
		$decided = [];
		foreach (array_chunk(array_keys($out), 500) as $chunk) {
			$rows = query("select symbol, sector, enabled from stock_symbols where symbol in ("
			              . implode(',', array_fill(0, count($chunk), '?')) . ") order by symbol, (enabled='Y') desc, exchange",
			              ...array_map('strval', $chunk));
			foreach ($rows as $r) {
				$symbol = (string) $r['symbol'];
				if (isset($decided[$symbol])) { continue; }
				$decided[$symbol] = true;
				$out[$symbol] = industry_lookup($names, $r['sector']);
			}
		}
		return $out;
	}

	/**
	 * The sector names among enabled shares that the map does not hold, with
	 * how many shares carry each: [['name'=>the name ('' for none), 'shares'=>n], ...].
	 */
	function industry_unmapped_sectors() {

		$names = industry_map_lookups()['n100'];
		$out = [];
		foreach (query("select trim(coalesce(sector, '')) sector, count(distinct symbol) shares
		                  from stock_symbols where enabled='Y' group by trim(coalesce(sector, '')) order by 1") as $r) {
			if (industry_lookup($names, $r['sector']) === INDUSTRY_UNMAPPED) {
				$out[] = ['name' => (string) $r['sector'], 'shares' => (int) $r['shares']];
			}
		}
		return $out;
	}

	/**
	 * Map a name, or move it to another industry. $source is 'n100' or
	 * 'fmp'; $fmp_industry is '' except for an FMP exception. Refused for an
	 * industry that is not one of the 11. A name has one row, so one industry.
	 *
	 * Returns ['ok'=>bool, 'why'=>string, 'version'=>the map's version after].
	 */
	function industry_map_set($source, $sector, $fmp_industry, $industry) {

		$source = (string) $source;
		$sector = trim((string) $sector);
		$fmp_industry = trim((string) $fmp_industry);
		$refuse = function ($why) { return ['ok' => false, 'why' => $why, 'version' => industry_map_version()]; };

		if (!industry_map_ready()) { return $refuse("the industry map is not there yet: apply sql/industry_map.sql."); }
		if (!in_array($source, ['n100', 'fmp'], true)) { return $refuse("the source is n100 or fmp."); }
		if ($sector === '') { return $refuse("a name to map is needed."); }
		if ($source === 'n100' && $fmp_industry !== '') { return $refuse("only an FMP name has an industry of its own."); }
		if (!in_array((string) $industry, industry_names(), true)) {
			return $refuse("\"" . $industry . "\" is not one of the 11 industries.");
		}

		query("delete from industry_map where source=? and sector=? and fmp_industry=?", $source, $sector, $fmp_industry);
		query("insert into industry_map (source, sector, fmp_industry, industry) values (?,?,?,?)",
		      $source, $sector, $fmp_industry, (string) $industry);
		write_log("industry_map", $source . " \"" . $sector . ($fmp_industry !== '' ? " / " . $fmp_industry : "")
		          . "\" is " . $industry . "; the map is now version " . industry_map_version());
		return ['ok' => true, 'why' => '', 'version' => industry_map_version()];
	}

	/** Take a name out of the map, so it is unmapped again. */
	function industry_map_remove($source, $sector, $fmp_industry = '') {

		if (!industry_map_ready()) { return ['ok' => false, 'why' => "the industry map is not there yet.", 'version' => industry_map_version()]; }
		query("delete from industry_map where source=? and sector=? and fmp_industry=?",
		      (string) $source, trim((string) $sector), trim((string) $fmp_industry));
		write_log("industry_map", $source . " \"" . trim((string) $sector) . "\" removed; the map is now version " . industry_map_version());
		return ['ok' => true, 'why' => '', 'version' => industry_map_version()];
	}

/* No closing tag: see the note at the end of constants.php. */
