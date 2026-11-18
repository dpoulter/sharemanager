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
			/* Status, and only status. A score is a bar from 0 to 100 with
			   the number beside it now, drawn in the three signal colours
			   of the house palette - view_house_tokens() holds those, and
			   the suite measures them against what they are drawn on.

			   One style each, so "PAPER" never looks like a warning and
			   "halted" never looks like a label. */
			'status-paper' => ['#1d4ed8', '#ffffff'],
			'status-off'   => ['#4b5563', '#ffffff'],
			'status-halt'  => ['#b91c1c', '#ffffff'],
			'status-due'   => ['#b45309', '#ffffff'],
		];
	}

	/**
	 * The house palette, as the stylesheet writes it: name => [light, dark].
	 *
	 * Here as well as in app.css because PHP cannot read a stylesheet and a
	 * stylesheet cannot call PHP. The suite checks that every value below
	 * appears in app.css and that every pair that carries text measures
	 * 4.5:1 in both schemes, so a colour changed in one place and not the
	 * other fails the suite rather than quietly giving one page its own
	 * scheme.
	 */
	function view_house_tokens() {
		return [
			'ground'     => ['#f1f3f6', '#14181d'],
			'panel'      => ['#ffffff', '#1c2229'],
			'band'       => ['#f5f7f9', '#222a32'],
			'rule'       => ['#dde2e8', '#323b45'],
			'rule-soft'  => ['#e6eaef', '#2a323b'],
			'rule-hair'  => ['#eef1f4', '#262e36'],
			'ink'        => ['#1b2430', '#e3e8ee'],
			'ink-2'      => ['#3a4553', '#c2cad4'],
			'muted'      => ['#4e5968', '#9ba6b4'],
			'nav'        => ['#17212b', '#10161c'],
			'link'       => ['#0b5a70', '#6fc6dd'],
			'link-hover' => ['#083f4f', '#9bdcec'],
			'pos'        => ['#1a7f4b', '#4ade80'],
			'neg'        => ['#b4322a', '#f87171'],
			'caution'    => ['#9a6200', '#e0a42b'],
			'alert-bg'   => ['#fbf1ef', '#3a1f1c'],
			'alert-rule' => ['#ebcfcb', '#5b2e29'],
			'alert-ink'  => ['#7a1f19', '#f3b4ae'],
			'chart-close' => ['#0b5a70', '#6fc6dd'],
			'chart-ma50'  => ['#c26a00', '#e5a34a'],
			'chart-ma200' => ['#7a4fb5', '#b79af0'],
		];
	}

	/** One token's value in the scheme asked for ('light' or 'dark'). */
	function view_token($name, $scheme = 'light') {
		$tokens = view_house_tokens();
		if (!isset($tokens[$name])) { return null; }
		return $tokens[$name][$scheme === 'dark' ? 1 : 0];
	}

	/**
	 * Which of the three signals a score out of 100 carries.
	 *
	 * Three bands, not four: a bar 6px high cannot show more, and the
	 * number beside it says the rest. 0-29 is bad, 30-59 is middling,
	 * 60 and over is good.
	 */
	function view_signal_band($score) {
		if (view_blank($score)) { return 'none'; }
		$value = (float) $score;
		if ($value < 30) { return 'low'; }
		if ($value < 60) { return 'mid'; }
		return 'high';
	}

	/** The class for a signal dot in that band. */
	function view_dot_class($score) {
		return ['low' => 'sm-dot-neg', 'mid' => 'sm-dot-caution',
		        'high' => 'sm-dot-pos', 'none' => ''][view_signal_band($score)];
	}

	/**
	 * A signal dot: 9px of colour beside a figure, never instead of one.
	 *
	 * $band is a score out of 100, or one of 'low', 'mid', 'high' where the
	 * caller has already decided - a close below its 200-day average is bad
	 * news whatever percentile it is in.
	 */
	function view_dot_html($band, $title = null) {

		$class = in_array($band, ['low', 'mid', 'high'], true)
		       ? ['low' => 'sm-dot-neg', 'mid' => 'sm-dot-caution', 'high' => 'sm-dot-pos'][$band]
		       : view_dot_class($band);

		if ($class === '') { return ''; }

		return '<span class="sm-dot ' . $class . '" aria-hidden="true"'
		     . ($title === null ? '' : ' title="' . htmlspecialchars($title, ENT_QUOTES)
		                               . '" data-bs-toggle="tooltip"')
		     . '></span>';
	}

	/**
	 * A comparison bar: a 0 to 100 track with the fill banded by value.
	 *
	 * The width is clamped to 3% at the bottom, so a score of 1 is a mark
	 * rather than an empty track that reads as missing data, and to 100 at
	 * the top. Returns the track alone; view_bar_row_html() is the row it
	 * is usually drawn in.
	 */
	function view_bar_html($score) {

		if (view_blank($score)) { return '<span class="sm-bar" aria-hidden="true"></span>'; }

		$value = max(0, min(100, (float) $score));
		$width = max(3, $value);
		$class = ['low' => 'sm-bar-low', 'mid' => 'sm-bar-mid',
		          'high' => 'sm-bar-high', 'none' => ''][view_signal_band($value)];

		return '<span class="sm-bar" aria-hidden="true"><span class="sm-bar-fill ' . $class
		     . '" style="width: ' . rtrim(rtrim(number_format($width, 1, '.', ''), '0'), '.')
		     . '%"></span></span>';
	}

	/**
	 * Name, bar and number on one row: the score box, and the cheapness and
	 * rank columns of the Value and Quality tables.
	 */
	function view_bar_row_html($name, $score, $options = []) {

		$href  = isset($options['href']) ? $options['href'] : null;
		$total = !empty($options['total']);

		$label = ($href === null)
		       ? ($total ? '<strong>' . htmlspecialchars($name) . '</strong>'
		                 : htmlspecialchars($name))
		       : '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '">'
		         . htmlspecialchars($name) . '</a>';

		return '<div class="sm-bar-row' . ($total ? ' sm-scorebox-total' : '') . '">'
		     . '<span>' . $label . '</span>'
		     . view_bar_html($score)
		     . '<span class="sm-bar-value">'
		     . (view_blank($score) ? htmlspecialchars(VIEW_DASH) : htmlspecialchars(fmt_score($score)))
		     . '</span></div>';
	}

	/** An outlined tag: the index, the industry, whether it is held. */
	function view_tag_html($text, $href = null) {
		$inner = htmlspecialchars((string) $text);
		return ($href === null)
		     ? '<span class="sm-tag">' . $inner . '</span>'
		     : '<a class="sm-tag" href="' . htmlspecialchars($href, ENT_QUOTES) . '">'
		       . $inner . '</a>';
	}

	/**
	 * A red to green scale with a marker, and a label at each end.
	 *
	 * For a figure whose meaning is where it sits between two ends rather
	 * than a rank: Piotroski from 0 to 9, Altman's distress and safe zones.
	 * The marker is clamped to the track, because an Altman Z of 12 is off
	 * the end of the scale rather than a reason to draw outside the box.
	 */
	function view_scale_html($value, $min, $max, $left, $right) {

		if (view_blank($value)) { return ''; }

		$span = (float) $max - (float) $min;
		$at   = ($span <= 0) ? 50.0
		      : max(0, min(100, (((float) $value - (float) $min) / $span) * 100));

		return '<div class="sm-scale" aria-hidden="true"><span class="sm-scale-mark" style="left: '
		     . rtrim(rtrim(number_format($at, 1, '.', ''), '0'), '.') . '%"></span></div>'
		     . '<div class="sm-scale-ends"><span>' . htmlspecialchars($left) . '</span>'
		     . '<span>' . htmlspecialchars($right) . '</span></div>';
	}

	/**
	 * The strip that says a rule is met or broken, in one sentence.
	 *
	 * Green when it is met: a policy that only speaks when it is broken
	 * leaves a reader unsure whether it was checked at all.
	 */
	function view_alert_html($ok, $sentence, $lead = null) {
		return '<div class="sm-alert' . ($ok ? ' sm-alert-ok' : '') . '">'
		     . '<span class="sm-dot" aria-hidden="true"></span>'
		     . '<span>' . ($lead === null ? '' : '<strong>' . htmlspecialchars($lead) . '</strong> ')
		     . htmlspecialchars($sentence) . '</span></div>';
	}

	/** Moves and returns, as text on the page background rather than badges. */
	function view_text_colours() {
		return [
			'up'      => '#1a7f4b',
			'down'    => '#b4322a',
			'flat'    => '#4e5968',
			'muted'   => '#4e5968',
			/* The sentiment bands, darkened to carry text. rns_band's own
			   colours are the swatch on "In the news" and stay as they are;
			   orange on white does not pass AA with a word written on it. */
			'bad'     => '#b91c1c',
			'poor'    => '#c2410c',
			'neutral' => '#4b5563',
			'good'    => '#4a7510',
			'strong'  => '#1a7f4b',
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
	 * A score in a table cell: a short bar and the number beside it.
	 *
	 * It was a coloured block with the figure written on it, mixed along
	 * a continuous red-to-green scale. The block told a reader the colour
	 * of a score and nothing else; where 49 sits between 0 and 100 is
	 * what a score is, and a bar says that. The tooltip is not decoration
	 * either: a bare 82 does not say what is being scored or which way is
	 * better, and this application has one scale that runs the other way.
	 */
	function score_badge_html($score, $label = 'Score', $higher_is_better = true) {

		/* An empty track and a dash, rather than a dash on its own: four
		   scores in one cell have to line up down the column, and a row
		   where one of them is missing should read as a gap in that
		   score rather than as a different shape of row. */
		if (view_blank($score)) {
			return '<span class="sm-score-cell sm-missing" title="'
			     . htmlspecialchars($label . ' has not been scored for this share', ENT_QUOTES)
			     . '" data-bs-toggle="tooltip">' . view_bar_html(null)
			     . '<span class="sm-score-number">' . htmlspecialchars(VIEW_DASH)
			     . '</span></span>';
		}

		$title = $label . ' ' . fmt_score($score) . '/100: '
		       . ($higher_is_better ? 'higher is better' : 'lower is better')
		       . ' (' . score_band_word($score) . ')';

		return '<span class="sm-score-cell" title="' . htmlspecialchars($title, ENT_QUOTES)
		     . '" data-bs-toggle="tooltip">'
		     . view_bar_html(max(0, min(100, (float) $score)))
		     . '<span class="sm-score-number">' . htmlspecialchars(fmt_score($score))
		     . '</span></span>';
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

	/**
	 * A return stored as a fraction, printed as the percentage it is.
	 *
	 * strategy_card() returns -0.008 for a strategy down 0.8%, and the
	 * dashboard printed it through move_pct_html() - which takes a
	 * percentage - so every strategy on the home page read "-0.0%" while
	 * the strategies page beside it read "-0.8%". Same figure, two
	 * readings, and the one on the front page was the wrong one.
	 */
	function move_fraction_html($fraction, $dp = 1) {
		if (view_blank($fraction)) { return view_missing_html("there is nothing to measure yet"); }
		return '<span class="' . move_class($fraction) . '">'
		     . htmlspecialchars(fmt_percent_of($fraction, $dp)) . '</span>';
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
	 * Sentiment: a dot, the figure and the word.
	 *
	 * It was a coloured swatch and the figure written in the band's own
	 * colour - the meaning carried twice by colour and not once by a
	 * word. The dot sits beside the number rather than instead of it, and
	 * the word says in English what the colour says in green, so a reader
	 * who cannot tell two greens apart reads the same thing as everybody
	 * else.
	 */
	function sentiment_html($value) {

		[$swatch, $word] = view_sentiment_band($value);

		/* rns_band's five bands onto the three signals. Neutral is the
		   middle one rather than nothing: "nobody said anything much" is
		   an answer. */
		$bands = ['bad' => 'low', 'poor' => 'low', 'neutral' => 'mid',
		          'good' => 'high', 'strong' => 'high'];

		/* fmt_sentiment() already writes the figure and the word -
		   "+0.60 strong" - which is the pair the mockup shows beside the
		   dot. */
		return view_dot_html(isset($bands[$word]) ? $bands[$word] : 'mid',
		                     'Sentiment ' . fmt_sentiment($value) . ' on a scale of -1 to +1')
		     . ' <span class="sm-sentiment">' . htmlspecialchars(fmt_sentiment($value))
		     . '</span>';
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
			$body .= '<div class="sm-bar-row"><span>' . $range . '</span>'
			       . view_bar_html($example)
			       . '<span class="sm-bar-value">' . score_band_word($example) . '</span></div>';
		}
		$body .= '</div>';
		return '<a href="#" class="sm-score-key small" role="button" tabindex="0"'
		     . ' data-bs-toggle="popover" data-bs-html="true" data-bs-trigger="focus"'
		     . ' data-bs-title="How to read the scores"'
		     . ' data-bs-content="' . htmlspecialchars($body, ENT_QUOTES) . '">'
		     . 'How to read the scores</a>';
	}

?>
