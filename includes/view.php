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

	/**
	 * Money in pounds, signed, so a loss reads as one: -GBP 420.00
	 *
	 * The sign goes in front of the sign of currency, where a reader looks
	 * for it. fmt_pounds() on a negative number writes "GBP -420.00", which
	 * is the minus sign hidden in the middle of the figure, so every
	 * quantity that can come out negative - a gain, a difference, a cash
	 * movement - goes through this one instead. Nought takes no sign.
	 */
	function fmt_pounds_signed($pounds, $dp = 2) {
		if (view_blank($pounds)) { return VIEW_DASH; }
		$value = (float) $pounds;
		if ($value == 0) { return fmt_pounds(0, $dp); }
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
	/**
	 * A number of shares, which may be a fraction.
	 *
	 * Whole numbers print as whole numbers - 750, not 750.00 - because that
	 * is what a share count is nearly always. A Trading 212 account deals in
	 * fractions, so 0.44 of a share has to print as 0.44 rather than as 0 or
	 * as 0.440000, and the trailing zeros come off so a column of them reads.
	 */
	function fmt_shares($value, $dp = 4, $trim = true) {

		if (view_blank($value)) { return VIEW_DASH; }

		$number = (float) $value;
		$dp     = max(0, (int) $dp);

		/* Fixed places, for a column where every row should line up: a
		   fractional account's quantities read as 583.50, 67.10, 66.63
		   rather than as 583.5, 67.1, 66.63, which looks like three
		   different kinds of number. */
		if (!$trim) { return number_format($number, $dp, '.', ','); }

		if (abs($number - round($number)) < 0.0000001) {
			return number_format(round($number));
		}

		$text = number_format($number, $dp, '.', ',');
		return rtrim(rtrim($text, '0'), '.');
	}

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

	/** 13 Nov 2026 17:44, for a timestamp: a moment, not a day.

	    A page was printing "2026-11-17 17:44:01" from the database beside
	    dates written "17 Nov 2026", so the same instant was spelled two
	    ways on one screen. The seconds go: nothing on these pages is
	    argued to the second. */
	function fmt_when($when) {
		if ($when === null || trim((string) $when) === '') { return VIEW_DASH; }
		$stamp = strtotime((string) $when);
		return $stamp === false ? VIEW_DASH : date('j M Y H:i', $stamp);
	}

	/** 13 Nov, for a dense table where the year is in the heading. */
	function fmt_date_short($date) {
		if ($date === null || trim((string) $date) === '') { return VIEW_DASH; }
		$stamp = strtotime((string) $date);
		return $stamp === false ? VIEW_DASH : date('j M', $stamp);
	}

	/* ---------------------------------------------------------------------
	   Assets.
	   --------------------------------------------------------------------- */

	/**
	 * A local stylesheet or script URL, with the file's own timestamp on it.
	 *
	 * The page was deployed and the browser went on using the stylesheet it
	 * already had. /css/app.css never changes its name, so a browser that
	 * has it has no reason to ask again, and a deploy that changes the CSS
	 * or the JavaScript shows the old one - which is what made a correct
	 * two-column page look like the old centred one for a day, and cost a
	 * round of "it is stale on the server" when it was not.
	 *
	 * The modification time of the file in public/ is the version: it
	 * changes exactly when the file does, needs nothing kept in step by
	 * hand, and a deploy that copies the file changes it by copying.
	 *
	 * The path is the URL path, from the document root ("/css/app.css").
	 * A file that is not there gets no version rather than a guess: on a
	 * fresh clone public/css and public/js are empty until
	 * tools/fetch_assets.sh has run, and a page should still render.
	 */
	function asset_url($path) {

		$path = '/' . ltrim((string) $path, '/');
		$file = dirname(__DIR__) . '/public' . $path;

		/* Read rather than trusted: open_basedir, a missing file and a
		   deploy mid-request all come back false, and none of them is a
		   reason to fail to draw the page. */
		$stamp = @filemtime($file);

		return htmlspecialchars($stamp === false ? $path : $path . '?v=' . $stamp,
		                        ENT_QUOTES, 'UTF-8');
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
			/* A score nobody has worked out. The scored ones are not here:
			   they are a continuous scale now, mixed in oklch between the
			   two ends below, and a browser is the only thing that can tell
			   you what colour a score of 62 comes out as. The phone-width
			   test asks one, in both colour schemes. */
			'score-none'   => ['#4b5563', '#ffffff'],
			/* Status. One style each, so "PAPER" never looks like a warning
			   and "halted" never looks like a label. */
			'status-paper' => ['#1d4ed8', '#ffffff'],
			'status-off'   => ['#4b5563', '#ffffff'],
			'status-halt'  => ['#b91c1c', '#ffffff'],
			'status-due'   => ['#b45309', '#ffffff'],
		];
	}

	/**
	 * The two ends of the score scale, as oklch.
	 *
	 * 0 is the first, 100 the second, and every value between them is
	 * color-mix(in oklch, ...) of the two - a continuous scale rather than
	 * four steps, so 49 and 50 stop being different colours for no reason a
	 * reader can see.
	 *
	 * oklch rather than sRGB because mixing in sRGB goes through mud: red to
	 * green passes through a dark grey-brown in the middle, and the
	 * lightness wanders, so the text on the badge is readable at the ends
	 * and not in the middle. In oklch the lightness is held - both ends are
	 * L 0.52 - so white text measures between 5.1:1 and 6.1:1 the whole way
	 * across, which is AA at every value.
	 *
	 * Here as well as in app.css so a test can name them; the stylesheet is
	 * what the browser reads.
	 */
	function view_score_scale() {
		return ['low' => 'oklch(0.52 0.18 25)', 'high' => 'oklch(0.52 0.15 148)'];
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

		if (score_band($score) === 'score-none') {
			return view_missing_html($label . ' has not been scored for this share');
		}

		/* The score itself, as a custom property, and the stylesheet mixes
		   the colour from it. The four fixed bands are gone from the badge;
		   the band word stays, in the tooltip, because "strong" is what a
		   reader wants in words and a colour cannot say it. */
		$value = max(0, min(100, (int) round((float) $score)));

		$title = $label . ' ' . fmt_score($score) . '/100: '
		       . ($higher_is_better ? 'higher is better' : 'lower is better')
		       . ' (' . score_band_word($score) . ')';

		return '<span class="sm-badge sm-score" style="--score: ' . $value . '" title="'
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
		           'halted' => 'status-halt', 'due' => 'status-due',
		           'ok' => 'status-ok'];
		$words  = ['paper' => 'PAPER', 'off' => 'disabled',
		           'halted' => 'halted', 'due' => 'rebalance tonight',
		           'ok' => 'OK'];
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
			return ['date' => null, 'level' => 'red', 'words' => 'No prices loaded',
			        'title' => 'No prices have been loaded for this exchange.',
			        'last_run' => null, 'behind' => null];
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

		/* The date the prices describe and the time the job that loaded them
		   ran are two different facts. The home page used to print them as
		   one - "Statistics Refreshed: 2026-11-14 09:12:03", where the date
		   was the market's and the time was the server's - and the box that
		   did it was the only thing in "System Messages". The box is gone;
		   the two facts are here, still named separately: the as-of date on
		   the badge, the run time in what it says when you point at it. */
		$ran = function_exists('get_last_run') ? get_last_run() : null;
		$title = 'Prices as of the ' . fmt_date($latest) . ' close';
		$title .= $ran === null
		        ? '. No run of the statistics job is recorded.'
		        : ', updated ' . $ran . '.';

		return ['date' => $latest, 'level' => $level, 'words' => $words,
		        'title' => $title, 'last_run' => $ran, 'behind' => $behind];
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

	/**
	 * The user's colour scheme: 'system', 'light' or 'dark'.
	 *
	 * Cached in the session like the username: wanted on every page, cannot
	 * change underneath a session except through theme.php, which updates
	 * the cache when it does. 'system' where the column is not there yet, so
	 * a host one deploy behind follows the machine rather than failing.
	 */
	function view_theme() {
		if (isset($_SESSION["sm_theme"])) { return (string) $_SESSION["sm_theme"]; }
		if (empty($_SESSION["id"])) { return 'system'; }

		static $has_column = null;
		if ($has_column === null) {
			$found = query("select count(*) n from information_schema.columns
			                 where table_schema=database() and table_name='users'
			                   and column_name='theme'");
			$has_column = count($found) && (int) $found[0]['n'] > 0;
		}
		if (!$has_column) { return 'system'; }

		$rows = query("select theme from users where id=?", $_SESSION["id"]);
		$theme = count($rows) ? (string) $rows[0]['theme'] : 'system';
		if (!in_array($theme, ['system', 'light', 'dark'], true)) { $theme = 'system'; }
		$_SESSION["sm_theme"] = $theme;
		return $theme;
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
			$body .= '<div><span class="sm-badge sm-score" style="--score: ' . $example . '">'
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
