<?php
//Minimal GD line chart used when the jpgraph library is not installed.
//stockgraph.php and performance_graph.php prefer jpgraph; this keeps the
//<img> from rendering as a broken icon when the vendored library is absent.

//Send a PNG carrying $message. Used for "no data" and for error states.
function graph_message_png($message, $width = 600, $height = 330) {
	$im = imagecreatetruecolor($width, $height);
	$bg = imagecolorallocate($im, 255, 255, 255);
	$fg = imagecolorallocate($im, 120, 120, 120);
	imagefilledrectangle($im, 0, 0, $width, $height, $bg);
	imagerectangle($im, 0, 0, $width - 1, $height - 1, imagecolorallocate($im, 200, 200, 200));
	$text = (string) $message;
	imagestring($im, 3, (int) (($width - imagefontwidth(3) * strlen($text)) / 2), (int) ($height / 2) - 7, $text, $fg);
	header("Content-Type: image/png");
	imagepng($im);
	imagedestroy($im);
}

//Draw a dated line series. $dates is an array of DateTime, $values numeric.
function simple_line_png($dates, $values, $width = 600, $height = 330) {
	$values = array_values(array_map('floatval', $values));
	$dates = array_values($dates);
	$points = min(count($dates), count($values));
	if ($points < 2) {
		graph_message_png("No price history available", $width, $height);
		return;
	}

	$left = 55; $right = 15; $top = 15; $bottom = 45;
	$plot_w = $width - $left - $right;
	$plot_h = $height - $top - $bottom;

	$min = min($values); $max = max($values);
	if ($max == $min) { $max = $min + 1; $min = $min - 1; }
	$pad = ($max - $min) * 0.05;
	$min -= $pad; $max += $pad;

	$im = imagecreatetruecolor($width, $height);
	$white = imagecolorallocate($im, 255, 255, 255);
	$grid  = imagecolorallocate($im, 225, 225, 225);
	$axis  = imagecolorallocate($im, 110, 110, 110);
	$line  = imagecolorallocate($im, 31, 119, 180);
	imagefilledrectangle($im, 0, 0, $width, $height, $white);

	//Horizontal gridlines with value labels.
	for ($i = 0; $i <= 4; $i++) {
		$y = (int) ($top + $plot_h * $i / 4);
		imageline($im, $left, $y, $left + $plot_w, $y, $grid);
		$label = number_format($max - ($max - $min) * $i / 4, 2);
		imagestring($im, 2, $left - 5 - imagefontwidth(2) * strlen($label), $y - 7, $label, $axis);
	}
	imageline($im, $left, $top, $left, $top + $plot_h, $axis);
	imageline($im, $left, $top + $plot_h, $left + $plot_w, $top + $plot_h, $axis);

	//Date labels at both ends and the midpoint.
	foreach (array(0, (int) (($points - 1) / 2), $points - 1) as $i) {
		$label = $dates[$i]->format('d-M-y');
		$x = (int) ($left + $plot_w * $i / ($points - 1));
		$x = max($left, min($left + $plot_w - imagefontwidth(2) * strlen($label), $x - imagefontwidth(2) * strlen($label) / 2));
		imagestringup($im, 2, (int) $x, $height - 5, $label, $axis);
	}

	$prev_x = null; $prev_y = null;
	for ($i = 0; $i < $points; $i++) {
		$x = (int) ($left + $plot_w * $i / ($points - 1));
		$y = (int) ($top + $plot_h - $plot_h * ($values[$i] - $min) / ($max - $min));
		if ($prev_x !== null) {
			imagesetthickness($im, 2);
			imageline($im, $prev_x, $prev_y, $x, $y, $line);
		}
		$prev_x = $x; $prev_y = $y;
	}

	header("Content-Type: image/png");
	imagepng($im);
	imagedestroy($im);
}

//Draw two dated series on shared axes, with a small legend.
function simple_multi_line_png($series, $width = 800, $height = 400) {
	$all = array();
	foreach ($series as $s) { foreach ($s["values"] as $v) { $all[] = (float) $v; } }
	if (count($all) < 2) {
		graph_message_png("No performance history available", $width, $height);
		return;
	}

	$left = 65; $right = 15; $top = 15; $bottom = 55;
	$plot_w = $width - $left - $right;
	$plot_h = $height - $top - $bottom;
	$min = min($all); $max = max($all);
	if ($max == $min) { $max = $min + 1; $min = $min - 1; }
	$pad = ($max - $min) * 0.05;
	$min -= $pad; $max += $pad;

	$im = imagecreatetruecolor($width, $height);
	$white = imagecolorallocate($im, 255, 255, 255);
	$grid  = imagecolorallocate($im, 225, 225, 225);
	$axis  = imagecolorallocate($im, 110, 110, 110);
	$palette = array(imagecolorallocate($im, 31, 119, 180), imagecolorallocate($im, 214, 39, 40));
	imagefilledrectangle($im, 0, 0, $width, $height, $white);

	for ($i = 0; $i <= 4; $i++) {
		$y = (int) ($top + $plot_h * $i / 4);
		imageline($im, $left, $y, $left + $plot_w, $y, $grid);
		$label = number_format($max - ($max - $min) * $i / 4, 2);
		imagestring($im, 2, $left - 5 - imagefontwidth(2) * strlen($label), $y - 7, $label, $axis);
	}
	imageline($im, $left, $top, $left, $top + $plot_h, $axis);
	imageline($im, $left, $top + $plot_h, $left + $plot_w, $top + $plot_h, $axis);

	//Date labels from the first series, which spans the same window as the rest.
	$axis_dates = array_values($series[0]["dates"]);
	if (count($axis_dates) > 1) {
		$n = count($axis_dates);
		foreach (array(0, (int) (($n - 1) / 2), $n - 1) as $i) {
			$label = $axis_dates[$i]->format('d-M-y');
			$x = $left + $plot_w * $i / ($n - 1) - imagefontwidth(2) * strlen($label) / 2;
			$x = max($left, min($left + $plot_w - imagefontwidth(2) * strlen($label), $x));
			imagestring($im, 2, (int) $x, $top + $plot_h + 6, $label, $axis);
		}
	}

	$legend_x = $left + 5;
	foreach ($series as $index => $s) {
		$colour = $palette[$index % count($palette)];
		$values = array_values(array_map("floatval", $s["values"]));
		$dates = array_values($s["dates"]);
		$points = min(count($dates), count($values));
		$prev_x = null; $prev_y = null;
		for ($i = 0; $i < $points; $i++) {
			$x = (int) ($left + ($points > 1 ? $plot_w * $i / ($points - 1) : 0));
			$y = (int) ($top + $plot_h - $plot_h * ($values[$i] - $min) / ($max - $min));
			if ($prev_x !== null) {
				imagesetthickness($im, 2);
				imageline($im, $prev_x, $prev_y, $x, $y, $colour);
			}
			$prev_x = $x; $prev_y = $y;
		}
		imagefilledrectangle($im, $legend_x, $height - 20, $legend_x + 10, $height - 12, $colour);
		imagestring($im, 2, $legend_x + 15, $height - 22, $s["label"], $axis);
		$legend_x += 30 + imagefontwidth(2) * strlen($s["label"]);
	}

	header("Content-Type: image/png");
	imagepng($im);
	imagedestroy($im);
}

//True when the vendored jpgraph library is available to require.
function jpgraph_available($dir = 'jpgraph-4.2.0/src') {
	return file_exists($dir . '/jpgraph.php');
}
?>
