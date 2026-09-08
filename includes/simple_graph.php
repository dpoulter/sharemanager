<?php
//Minimal SVG line chart used when the jpgraph library is not installed.
//stockgraph.php and performance_graph.php prefer jpgraph; this keeps the
//<img> from rendering as a broken icon when the vendored library is absent.
//
//SVG rather than GD on purpose: GD ships as a separate package (php-gd) that
//is often not installed, and a missing extension is a fatal error inside an
//<img>, which the browser can only show as a broken image. Nothing here needs
//an extension.

//Escape text for use in SVG content.
function svg_text($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, "UTF-8");
}

//Send $body wrapped in an SVG document of the given size.
function svg_send($body, $width, $height) {
	header("Content-Type: image/svg+xml");
	header("Cache-Control: no-store");
	print('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
	print('<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $width . '" height="' . (int) $height . '"'
	      . ' viewBox="0 0 ' . (int) $width . ' ' . (int) $height . '"'
	      . ' font-family="Helvetica, Arial, sans-serif">' . "\n");
	print('<rect width="100%" height="100%" fill="#ffffff"/>' . "\n");
	print($body);
	print('</svg>' . "\n");
}

//An SVG carrying $message. Used for "no data" and for error states.
function graph_message_svg($message, $width = 600, $height = 330) {
	$body = '<text x="' . ($width / 2) . '" y="' . ($height / 2) . '" text-anchor="middle"'
	      . ' font-size="13" fill="#777777">' . svg_text($message) . '</text>' . "\n";
	svg_send($body, $width, $height);
}

//Axes, gridlines and value labels shared by both charts. Returns the SVG for
//the frame; the caller adds the series on top.
function svg_frame($left, $top, $plot_w, $plot_h, $min, $max) {
	$out = "";
	for ($i = 0; $i <= 4; $i++) {
		$y = $top + $plot_h * $i / 4;
		$out .= '<line x1="' . $left . '" y1="' . $y . '" x2="' . ($left + $plot_w) . '" y2="' . $y
		      . '" stroke="#e6e6e6" stroke-width="1"/>' . "\n";
		$label = number_format($max - ($max - $min) * $i / 4, 2);
		$out .= '<text x="' . ($left - 8) . '" y="' . ($y + 4) . '" text-anchor="end" font-size="11"'
		      . ' fill="#6e6e6e">' . svg_text($label) . '</text>' . "\n";
	}
	$out .= '<line x1="' . $left . '" y1="' . $top . '" x2="' . $left . '" y2="' . ($top + $plot_h)
	      . '" stroke="#6e6e6e" stroke-width="1"/>' . "\n";
	$out .= '<line x1="' . $left . '" y1="' . ($top + $plot_h) . '" x2="' . ($left + $plot_w)
	      . '" y2="' . ($top + $plot_h) . '" stroke="#6e6e6e" stroke-width="1"/>' . "\n";
	return $out;
}

//Date labels at both ends and the midpoint of the x-axis.
function svg_date_labels($dates, $left, $top, $plot_w, $plot_h) {
	$n = count($dates);
	if ($n < 2) { return ""; }
	$out = "";
	foreach (array(0, (int) (($n - 1) / 2), $n - 1) as $i) {
		$x = $left + $plot_w * $i / ($n - 1);
		$anchor = ($i === 0) ? "start" : (($i === $n - 1) ? "end" : "middle");
		$out .= '<text x="' . $x . '" y="' . ($top + $plot_h + 18) . '" text-anchor="' . $anchor
		      . '" font-size="11" fill="#6e6e6e">' . svg_text($dates[$i]->format('d-M-y')) . '</text>' . "\n";
	}
	return $out;
}

//The polyline for one series, scaled into the plot area.
function svg_series($values, $left, $top, $plot_w, $plot_h, $min, $max, $colour) {
	$points = array();
	$n = count($values);
	for ($i = 0; $i < $n; $i++) {
		$x = $left + ($n > 1 ? $plot_w * $i / ($n - 1) : 0);
		$y = $top + $plot_h - $plot_h * ($values[$i] - $min) / ($max - $min);
		$points[] = round($x, 2) . ',' . round($y, 2);
	}
	return '<polyline fill="none" stroke="' . $colour . '" stroke-width="2" stroke-linejoin="round"'
	     . ' points="' . implode(' ', $points) . '"/>' . "\n";
}

//Pad a min/max pair so the line does not touch the frame, and so a flat series
//still has a range to scale against.
function svg_bounds($values) {
	$min = min($values); $max = max($values);
	if ($max == $min) { return array($min - 1, $max + 1); }
	$pad = ($max - $min) * 0.05;
	return array($min - $pad, $max + $pad);
}

//Draw a dated line series. $dates is an array of DateTime, $values numeric.
function simple_line_svg($dates, $values, $width = 600, $height = 330) {
	$values = array_values(array_map('floatval', $values));
	$dates = array_values($dates);
	$points = min(count($dates), count($values));
	if ($points < 2) {
		graph_message_svg("No price history available", $width, $height);
		return;
	}
	$values = array_slice($values, 0, $points);
	$dates = array_slice($dates, 0, $points);

	$left = 60; $top = 15; $plot_w = $width - $left - 15; $plot_h = $height - $top - 35;
	list($min, $max) = svg_bounds($values);

	$body  = svg_frame($left, $top, $plot_w, $plot_h, $min, $max);
	$body .= svg_date_labels($dates, $left, $top, $plot_w, $plot_h);
	$body .= svg_series($values, $left, $top, $plot_w, $plot_h, $min, $max, "#1f77b4");
	svg_send($body, $width, $height);
}

//Draw several dated series on shared axes, with a small legend.
function simple_multi_line_svg($series, $width = 800, $height = 400) {
	$all = array();
	foreach ($series as $s) { foreach ($s["values"] as $v) { $all[] = (float) $v; } }
	if (count($all) < 2) {
		graph_message_svg("No performance history available", $width, $height);
		return;
	}

	$left = 70; $top = 15; $plot_w = $width - $left - 15; $plot_h = $height - $top - 60;
	list($min, $max) = svg_bounds($all);
	$palette = array("#1f77b4", "#d62728", "#2ca02c", "#9467bd");

	$body = svg_frame($left, $top, $plot_w, $plot_h, $min, $max);
	$body .= svg_date_labels(array_values($series[0]["dates"]), $left, $top, $plot_w, $plot_h);

	$legend_x = $left;
	foreach ($series as $index => $s) {
		$colour = $palette[$index % count($palette)];
		$values = array_values(array_map("floatval", $s["values"]));
		if (count($values) > 1) {
			$body .= svg_series($values, $left, $top, $plot_w, $plot_h, $min, $max, $colour);
		}
		$body .= '<rect x="' . $legend_x . '" y="' . ($height - 22) . '" width="11" height="11" fill="' . $colour . '"/>' . "\n";
		$body .= '<text x="' . ($legend_x + 17) . '" y="' . ($height - 13) . '" font-size="11" fill="#6e6e6e">'
		       . svg_text($s["label"]) . '</text>' . "\n";
		$legend_x += 40 + 7 * strlen($s["label"]);
	}

	svg_send($body, $width, $height);
}

//True when the vendored jpgraph library is available to require.
function jpgraph_available($dir = 'jpgraph-4.2.0/src') {
	return file_exists($dir . '/jpgraph.php');
}

/**
 * Report a failure inside an image endpoint as a picture rather than as HTML.
 *
 * A fatal in a script the browser loaded through <img src> produces an error
 * page the browser cannot render, so the only symptom is a broken icon and the
 * cause is invisible without reading the server log. Buffer the output, and if
 * the script dies or prints anything that is not an image, draw the message
 * instead.
 */
function graph_error_trap($width = 600, $height = 330) {
	//display_errors would write the message into the image body before the
	//shutdown handler ever runs.
	ini_set("display_errors", "0");
	ob_start();

	register_shutdown_function(function () use ($width, $height) {
		$body = ob_get_clean();
		$error = error_get_last();
		$fatal = $error !== null
		         && in_array($error["type"], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true);

		if (!$fatal && $body !== "" && $body !== false) {
			print($body);
			return;
		}

		$message = $fatal ? $error["message"] : "The chart produced no output";
		//One line, short enough to read in the space an image occupies.
		$message = trim(preg_replace('/\s+/', ' ', (string) $message));
		if (strlen($message) > 120) { $message = substr($message, 0, 117) . "..."; }
		error_log("stock chart failed: " . $message);
		graph_message_svg($message, $width, $height);
	});
}
?>
