<?php

	/**
	 * news_view.php - how "In the news" arranges what it has.
	 *
	 * The page works; these are the two things that made it hard to read.
	 *
	 * A company that files three announcements on one day got three rows,
	 * and a day with a dozen such filings was a dozen rows of the same
	 * company. They are one row now, with the count and the strongest of
	 * them, opening to the individual items.
	 *
	 * And an announcement that is both type "other" and neutral is a filing
	 * nobody is going to act on - it is the long tail of the page, and it
	 * was mixed in with the results and the warnings. Hidden by default,
	 * with a count so it is clear what has been hidden rather than silently
	 * dropped.
	 */

	require_once("rns.php");

	/* Neutral, on the scale rns_band() uses: not "exactly nought", which
	   almost nothing is, but the band the model's answer falls in. */
	define("NEWS_NEUTRAL_BAND", 0.15);

	/**
	 * Whether a row is routine: type "other" and neutral in tone.
	 *
	 * Both, not either. A profit warning typed "other" is not routine, and
	 * neither is a neutral set of results - the first is the most important
	 * thing on the page and the second is a scheduled event somebody is
	 * waiting for.
	 */
	function news_is_routine($row) {
		$type = strtolower(trim((string) ($row['ann_type'] ?? '')));
		if ($type !== 'other' && $type !== '') { return false; }
		if ($row['sentiment'] === null) { return true; }
		return abs((float) $row['sentiment']) < NEWS_NEUTRAL_BAND;
	}

	/** How many of these rows are routine. For the toggle's label. */
	function news_routine_count($rows) {
		$n = 0;
		foreach ($rows as $row) { if (news_is_routine($row)) { $n++; } }
		return $n;
	}

	/** The rows with the routine ones taken out. */
	function news_without_routine($rows) {
		$out = [];
		foreach ($rows as $row) { if (!news_is_routine($row)) { $out[] = $row; } }
		return $out;
	}

	/**
	 * One entry per company per day.
	 *
	 * The entry carries the strongest announcement of the group - the one
	 * furthest from neutral, in either direction - because that is the one
	 * that decides how the day reads. Three neutral filings and a profit
	 * warning is a profit warning.
	 *
	 * The order of the rows is kept: the first time a company-day is seen
	 * fixes where its entry sits, so a page sorted by sentiment still shows
	 * the strongest first and a page sorted by date still runs newest down.
	 */
	function news_group_by_day($rows) {

		$groups = [];

		foreach ($rows as $row) {

			$key = $row['symbol'] . '|' . $row['published'];

			if (!isset($groups[$key])) {
				$groups[$key] = [
					'symbol'    => $row['symbol'],
					'company'   => $row['company'] ?? null,
					'published' => $row['published'],
					'count'     => 0,
					'strongest' => $row,
					'items'     => [],
				];
			}

			$groups[$key]['count']++;
			$groups[$key]['items'][] = $row;

			$current = $groups[$key]['strongest']['sentiment'];
			$theirs  = $row['sentiment'];
			if ($current === null
			    || ($theirs !== null && abs((float) $theirs) > abs((float) $current))) {
				$groups[$key]['strongest'] = $row;
			}
		}

		return array_values($groups);
	}

?>
