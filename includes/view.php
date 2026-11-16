<?php

	/**
	 * view.php - how a number, a date and a colour are printed.
	 *
	 * Every template formats through here. The point is not tidiness: the
	 * same figure was being printed three ways on three pages, and a reader
	 * comparing Home with the quote page could not tell whether 1350.6 and
	 * 13.506 were the same price. One function per kind of figure, and the
	 * kind is in the name, so a template cannot accidentally print a pence
	 * price as pounds.
	 *
	 * London prices are stored in pence. That is the single largest source
	 * of confusion in this application, so the pence formatter says "p" and
	 * the pounds formatter says "GBP": a reader can always tell which they
	 * are looking at, and so can a test.
	 *
	 * Everything here returns a string that is safe to print. Functions
	 * whose name ends in _html build markup and escape what they are given;
	 * the rest return plain text and are escaped by the caller only if the
	 * value could contain markup, which for a formatted number it cannot.
	 */

	/* ---------------------------------------------------------------------
	   Missing values.
	   --------------------------------------------------------------------- */

	/* An en dash, not a zero and not an empty cell. An empty cell reads as
	   "nothing here" and a zero reads as "the value is nought"; neither is
	   what "the provider did not supply this" means. */
	define("VIEW_DASH", "\xe2\x80\x93");

	/** True when there is nothing to print: null, empty string, or non-numeric. */
	function view_blank($value) {
		if ($value === null) { return true; }
		if (is_string($value) && trim($value) === '') { return true; }
		return !is_numeric($value);
	}

	/**
	 * The muted dash, with a tooltip saying why the figure is absent.
	 *
	 * The reason matters. "Not supplied by FMP" is a different fact from
	 * "the nightly job has not run yet", and a reader who cannot tell them
	 * apart will either chase the provider or wait for a job that is never
	 * coming.
	 */
	function view_missing_html($why = "not supplied by the data provider") {
		return '<span class="sm-missing" title="' . htmlspecialchars($why, ENT_QUOTES)
		     . '" data-bs-toggle="tooltip">' . VIEW_DASH . '</span>';
	}

	/* ---------------------------------------------------------------------
	   Numbers.
	   --------------------------------------------------------------------- */

	/**
	 * A London price, in pence, as stored: 1,350.6p
	 *
	 * Pence is how the provider sends it, how historical_prices holds it and
	 * how the London market quotes it. Dividing by 100 for display would be
	 * inventing a convention the reader does not use.
	 */
	function fmt_pence($pence, $dp = 1) {
		if (view_blank($pence)) { return VIEW_DASH; }
		return number_format((float) $pence, $dp) . 'p';
	}

	/** Money in pounds: GBP 6,609.33 - for strategy values, costs, cash. */
	function fmt_pounds($pounds, $dp = 2) {
		if (view_blank($pounds)) { return VIEW_DASH; }
		return "\xc2\xa3" . number_format((float) $pounds, $dp);
	}

	/** Money in pounds, signed, so a loss reads as one: -GBP 420.00 */
	function fmt_pounds_signed($pounds, $dp = 2) {
		if (view_blank($pounds)) { return VIEW_DASH; }
		$value = (float) $pounds;
		return ($value < 0 ? '-' : '+') . fmt_pounds(abs($value), $dp);
	}

	/**
	 * Large money, shortened above GBP 10,000: GBP 4.7bn, GBP 312.4m, GBP 48.1k
	 *
	 * For summaries and cards only. A table of figures a reader might add up
	 * keeps fmt_pounds, because "GBP 4.7bn" cannot be added to anything.
	 */
	function fmt_money_compact($pounds, $symbol = "\xc2\xa3") {
		if (view_blank($pounds)) { return VIEW_DASH; }
		$value = (float) $pounds;
		$sign  = $value < 0 ? '-' : '';
		$size  = abs($value);
		if ($size >= 1000000000) { return $sign . $symbol . number_format($size / 1000000000, 1) . 'bn'; }
		if ($size >= 1000000)    { return $sign . $symbol . number_format($size / 1000000, 1) . 'm'; }
		if ($size >  10000)      { return $sign . $symbol . number_format($size / 1000, 1) . 'k'; }
		return $sign . $symbol . number_format($size, 2);
	}

	/**
	 * A percentage change: +12.4%, -2.2%
	 *
	 * Takes a percentage, not a fraction. A fraction goes through
	 * fmt_percent_of() instead - the two are a factor of 100 apart and the
	 * bug that mistake produces is silent, so they get different names
	 * rather than a flag.
	 */
	function fmt_change_pct($percent, $dp = 1) {
		if (view_blank($percent)) { return VIEW_DASH; }
		$value = (float) $percent;
		$sign  = $value > 0 ? '+' : ($value < 0 ? '-' : '');
		return $sign . number_format(abs($value), $dp) . '%';
	}

	/** The same, from a fraction: 0.124 becomes +12.4%. */
	function fmt_percent_of($fraction, $dp = 1) {
		if (view_blank($fraction)) { return VIEW_DASH; }
		return fmt_change_pct((float) $fraction * 100, $dp);
	}

	/** An unsigned percentage, for a proportion rather than a change: 62.5% */
	function fmt_pct($percent, $dp = 1) {
		if (view_blank($percent)) { return VIEW_DASH; }
		return number_format((float) $percent, $dp) . '%';
	}

	/** A ratio: P/E, P/B, EV/EBITDA. One decimal place. */
	function fmt_ratio($value, $dp = 1) {
		if (view_blank($value)) { return VIEW_DASH; }
		return number_format((float) $value, $dp);
	}

	/** A yield, two places: 3.11% */
	function fmt_yield($percent) {
		if (view_blank($percent)) { return VIEW_DASH; }
		return number_format((float) $percent, 2) . '%';
	}

	/** A score out of 100, whole: 82 */
	function fmt_score($value) {
		if (view_blank($value)) { return VIEW_DASH; }
		return number_format(round((float) $value));
	}

	/** A share quantity, whole, with separators: 11,578 */
	function fmt_quantity($value) {
		if (view_blank($value)) { return VIEW_DASH; }
		return number_format(round((float) $value));
	}

	/**
	 * A sentiment score and its band word: +0.70 good
	 *
	 * The number alone is meaningless to a reader who has not been told the
	 * scale, and the word alone throws away the strength. Both, always.
	 */
	function fmt_sentiment($value) {
		if (view_blank($value)) { return VIEW_DASH . ' not scored'; }
		$score = (float) $value;
		[, $word] = view_sentiment_band($score);
		return ($score >= 0 ? '+' : '-') . number_format(abs($score), 2) . ' ' . $word;
	}

	/**
	 * rns_band(), loaded on demand.
	 *
	 * The bands live in rns.php because that is where they were needed
	 * first, and they are not copied here: one number getting two different
	 * colours on two pages is the whole thing this file exists to stop.
	 * Loaded only when a page actually shows sentiment, so the pages that
	 * do not - Portfolio, Screens - do not pull in the announcement reader
	 * and the model client behind it.
	 */
	function view_sentiment_band($value) {
		if (!function_exists('rns_band')) { require_once("rns.php"); }
		return rns_band($value);
	}

	/* ---------------------------------------------------------------------
	   Dates.
	   --------------------------------------------------------------------- */

	/** 13 Nov 2026, for prose and headings. */
	function fmt_date($date) {
		if ($date === null || trim((string) $date) === '') { return VIEW_DASH; }
		$stamp = strtotime((string) $date);
		return $stamp === false ? VIEW_DASH : date('j M Y', $stamp);
	}

	/** 13 Nov, for a dense table where the year is in the heading. */
	function fmt_date_short($date) {
		if ($date === null || trim((string) $date) === '') { return VIEW_DASH; }
		$stamp = strtotime((string) $date);
		return $stamp === false ? VIEW_DASH : date('j M', $stamp);
	}

	/* ---------------------------------------------------------------------
	   Colour.
	   --------------------------------------------------------------------- */

	/**
	 * Every colour the application uses for a value, in one array.
	 *
	 * Here rather than in the stylesheet because the contrast test reads it:
	 * a badge whose text fails WCAG AA is a bug, and a bug a test can see is
	 * worth more than a comment asking the next person to check. Each entry
	 * is [background, text].
	 */
	function view_palette() {
		return [
			/* Scores out of 100. One scale for Momentum, Value, Quality,
			   ShareRank and the financial health ranks - they were four
			   different colour schemes, so a reader comparing two scores
			   was comparing two languages. */
			'score-bad'    => ['#b91c1c', '#ffffff'],
			'score-weak'   => ['#b45309', '#ffffff'],
			'score-fair'   => ['#4d7c0f', '#ffffff'],
			'score-good'   => ['#15803d', '#ffffff'],
			'score-none'   => ['#4b5563', '#ffffff'],
			/* Status. One style each, so "PAPER" never looks like a warning
			   and "halted" never looks like a label. */
			'status-paper' => ['#1d4ed8', '#ffffff'],
			'status-off'   => ['#4b5563', '#ffffff'],
			'status-halt'  => ['#b91c1c', '#ffffff'],
			'status-due'   => ['#b45309', '#ffffff'],
		];
	}

	/** Moves and returns, as text on the page background rather than badges. */
	function view_text_colours() {
		return [
			'up'      => '#15803d',
			'down'    => '#b91c1c',
			'flat'    => '#6b7280',
			'muted'   => '#6b7280',
			/* The sentiment bands, darkened to carry text. rns_band's own
			   colours are the swatch on "In the news" and stay as they are;
			   orange on white does not pass AA with a word written on it. */
			'bad'     => '#b91c1c',
			'poor'    => '#c2410c',
			'neutral' => '#4b5563',
			'good'    => '#4d7c0f',
			'strong'  => '#15803d',
		];
	}

	/**
	 * Which band a score out of 100 falls in.
	 *
	 * 0-24 bad, 25-49 weak, 50-74 fair, 75-100 good. The boundaries are
	 * inclusive at the bottom, which is the only reading that makes 25 a
	 * different band from 24.
	 */
	function score_band($score) {
		if (view_blank($score)) { return 'score-none'; }
		$value = (float) $score;
		if ($value < 25) { return 'score-bad'; }
		if ($value < 50) { return 'score-weak'; }
		if ($value < 75) { return 'score-fair'; }
		return 'score-good';
	}

	/** The word for a band, for a tooltip or a screen reader. */
	function score_band_word($score) {
		return [
			'score-bad'  => 'poor',
			'score-weak' => 'below average',
			'score-fair' => 'above average',
			'score-good' => 'strong',
			'score-none' => 'not scored',
		][score_band($score)];
	}

	/**
	 * A score badge: the number, coloured, with the scale in a tooltip.
	 *
	 * The tooltip is not decoration. A bare 82 in green does not say what is
	 * being scored or which way is better, and the page had four scales
	 * where higher was better and one where it was not.
	 */
	function score_badge_html($score, $label = 'Score', $higher_is_better = true) {
		$band = score_band($score);
		if ($band === 'score-none') {
			return view_missing_html($label . ' has not been scored for this share');
		}
		$title = $label . ' ' . fmt_score($score) . '/100: '
		       . ($higher_is_better ? 'higher is better' : 'lower is better')
		       . ' (' . score_band_word($score) . ')';
		return '<span class="sm-badge sm-' . $band . '" title="'
		     . htmlspecialchars($title, ENT_QUOTES) . '" data-bs-toggle="tooltip">'
		     . fmt_score($score) . '</span>';
	}

	/** up, down or flat - the class a move or a return is printed in. */
	function move_class($value) {
		if (view_blank($value)) { return 'sm-flat'; }
		$number = (float) $value;
		if ($number > 0) { return 'sm-up'; }
		if ($number < 0) { return 'sm-down'; }
		return 'sm-flat';
	}

	/** A percentage change, coloured: green up, red down, grey flat. */
	function move_pct_html($percent, $dp = 1) {
		if (view_blank($percent)) { return view_missing_html("no price history for this period"); }
		return '<span class="' . move_class($percent) . '">'
		     . htmlspecialchars(fmt_change_pct($percent, $dp)) . '</span>';
	}

	/** Money, coloured the same way, for a gain or a day's change. */
	function move_pounds_html($pounds, $dp = 2) {
		if (view_blank($pounds)) { return view_missing_html("nothing held, so there is no gain"); }
		return '<span class="' . move_class($pounds) . '">'
		     . htmlspecialchars(fmt_pounds_signed($pounds, $dp)) . '</span>';
	}

	/** A status badge: PAPER, disabled, halted, rebalance tonight. */
	function status_badge_html($kind, $text = null) {
		$styles = ['paper' => 'status-paper', 'off' => 'status-off',
		           'halted' => 'status-halt', 'due' => 'status-due'];
		$words  = ['paper' => 'PAPER', 'off' => 'disabled',
		           'halted' => 'halted', 'due' => 'rebalance tonight'];
		$key = isset($styles[$kind]) ? $kind : 'off';
		return '<span class="sm-badge sm-' . $styles[$key] . '">'
		     . htmlspecialchars($text === null ? $words[$key] : $text) . '</span>';
	}

	/**
	 * A sentiment swatch and its words, as "In the news" draws it.
	 *
	 * The swatch keeps rns_band's colours so the three pages that show
	 * sentiment show one scale; the word beside it is the darkened tone,
	 * because it is text.
	 */
	function sentiment_html($value) {
		[$swatch, $word] = view_sentiment_band($value);
		$colours = view_text_colours();
		$text    = isset($colours[$word]) ? $colours[$word] : $colours['neutral'];
		$out  = '<span class="sm-swatch" style="background:' . htmlspecialchars($swatch, ENT_QUOTES) . '"';
		$out .= ' title="' . htmlspecialchars('Sentiment ' . fmt_sentiment($value)
		                                      . ' on a scale of -1 to +1', ENT_QUOTES) . '"';
		$out .= ' data-bs-toggle="tooltip"></span> ';
		$out .= '<span style="color:' . htmlspecialchars($text, ENT_QUOTES) . '">'
		      . htmlspecialchars(fmt_sentiment($value)) . '</span>';
		return $out;
	}

	/* ---------------------------------------------------------------------
	   How fresh the figures are.
	   --------------------------------------------------------------------- */

	/**
	 * The as-of date of the latest prices, and whether it is old.
	 *
	 * Returns [date, level, words]. level is 'fresh', 'amber' or 'red':
	 * amber once the latest close is older than the last trading day, red
	 * once it is three trading days behind. A page showing Friday's prices
	 * on a Wednesday is not wrong, it is stale, and those are different
	 * things for somebody deciding whether to sell.
	 *
	 * Trading days are counted off the exchange's own stored closes rather
	 * than from a calendar: the application already knows which days the
	 * market traded, and a bank holiday calendar it does not have would be
	 * one more thing to keep right.
	 */
	function view_freshness($exchange = null) {
		$exchange = $exchange === null ? default_exchange() : $exchange;
		$latest   = last_trading_date($exchange);
		if ($latest === null) {
			return ['date' => null, 'level' => 'red', 'words' => 'No prices loaded'];
		}

		/* How many stored trading days sit between the latest close and
		   today. Zero means the latest close is the newest session there
		   could be. */
		$rows = query("select count(*) sessions from
		                (select distinct date from historical_prices
		                  where exchange=? and date>? and date<=curdate()) d",
		              $exchange, $latest);
		$behind = count($rows) ? (int) $rows[0]['sessions'] : 0;

		/* The market may simply not have traded since. Weekdays that are
		   not in the price history at all are the measure of last resort,
		   because a Saturday is not a late job. */
		if ($behind === 0) {
			$weekdays = 0;
			$day = strtotime($latest . ' +1 day');
			$today = strtotime(date('Y-m-d'));
			while ($day <= $today) {
				$dow = (int) date('N', $day);
				if ($dow <= 5) { $weekdays++; }
				$day = strtotime(date('Y-m-d', $day) . ' +1 day');
			}
			$behind = $weekdays;
		}

		$level = 'fresh';
		if ($behind >= 3) { $level = 'red'; }
		else if ($behind >= 1) { $level = 'amber'; }

		$words = 'Prices ' . fmt_date($latest);
		if ($level === 'amber') { $words .= ' (a day behind)'; }
		if ($level === 'red')   { $words .= ' (' . $behind . ' trading days behind)'; }

		return ['date' => $latest, 'level' => $level, 'words' => $words,
		        'behind' => $behind];
	}

	/* ---------------------------------------------------------------------
	   Odds and ends every template wants.
	   --------------------------------------------------------------------- */

	/**
	 * The logged-in user's name, for the user menu.
	 *
	 * Cached in the session: it is wanted on every page and it cannot change
	 * underneath a session, so one query per login rather than one per page.
	 * An empty string when there is nobody logged in, which is the login
	 * page and the password reset.
	 */
	function view_username() {
		if (empty($_SESSION["id"])) { return ''; }
		if (isset($_SESSION["sm_username"])) { return (string) $_SESSION["sm_username"]; }
		$rows = query("select username from users where id=?", $_SESSION["id"]);
		$name = count($rows) ? (string) $rows[0]['username'] : '';
		$_SESSION["sm_username"] = $name;
		return $name;
	}

	/** Shorthand for htmlspecialchars on a value that may be null. */
	function e($value) {
		return htmlspecialchars((string) $value, ENT_QUOTES);
	}

	/**
	 * The "How to read the scores" popover, one copy per page.
	 *
	 * A colour scale with no key is a decoration. This is the key, and it is
	 * generated from the same palette the badges use so the two cannot drift
	 * apart.
	 */
	function score_key_html() {
		$rows = [[12, '0-24'], [37, '25-49'], [62, '50-74'], [88, '75-100']];
		$body = '<div class="sm-key">';
		foreach ($rows as [$example, $range]) {
			$body .= '<div><span class="sm-badge sm-' . score_band($example) . '">'
			       . $range . '</span> ' . score_band_word($example) . '</div>';
		}
		$body .= '</div>';
		return '<a href="#" class="sm-score-key small" role="button" tabindex="0"'
		     . ' data-bs-toggle="popover" data-bs-html="true" data-bs-trigger="focus"'
		     . ' data-bs-title="How to read the scores"'
		     . ' data-bs-content="' . htmlspecialchars($body, ENT_QUOTES) . '">'
		     . 'How to read the scores</a>';
	}

?>
