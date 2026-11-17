/*
 * The strategy chart: every strategy and the benchmark on one axis.
 *
 * Drawn with TradingView Lightweight Charts (Apache-2.0), served from
 * /js/lightweight-charts.standalone.production.js the same way the quote
 * page's chart is. tools/fetch_assets.sh downloads the library and copies
 * this file to public/js/.
 *
 * The data arrives as JSON in a <script type="application/json"> block
 * rather than as generated JavaScript: a strategy name is configuration
 * somebody can edit, so it is data here too. Names go into the legend
 * through textContent, never through innerHTML.
 *
 * The points are stored totals, not percentages. The rebasing is done here
 * because there are two ways to read the same series and a toggle between
 * them: each line from its own first night, which is what "how has this
 * strategy done" means, or every line from one date, which is the only way
 * to compare two strategies that started in different months.
 *
 * Degrading: if the library did not load, or JavaScript is off, the table
 * above still carries every figure. The status line says which happened
 * rather than leaving an empty box.
 */
(function () {
	"use strict";

	var root = document.getElementById("strategy-chart");
	if (!root) { return; }

	var status = document.getElementById("strategy-chart-status");
	var holder = document.getElementById("strategy-chart-data");
	var commonBox = document.getElementById("chart-common-base");
	var backtestBox = document.getElementById("chart-backtests");

	function say(message) {
		if (status) { status.textContent = message; }
	}

	if (typeof LightweightCharts === "undefined") {
		say("Chart unavailable; the figures above do not need it.");
		return;
	}

	var series = [];
	try {
		series = JSON.parse((holder && holder.textContent) || "[]");
	}
	catch (e) {
		say("Chart data could not be read.");
		return;
	}

	if (!series.length) {
		say("Nothing to draw yet.");
		return;
	}

	/* The page's own colours, so a chart in dark mode is not black lines on
	   a black card. Read at build time from the custom properties app.css
	   sets; the fallbacks are the values the charts used to carry. */
	function token(name, fallback) {
		var value = getComputedStyle(document.documentElement).getPropertyValue(name);
		value = (value || "").trim();
		return value === "" ? fallback : value;
	}

	var chart = LightweightCharts.createChart(root, {
		height: 360,
		layout: { background: { color: "#ffffff" }, textColor: token("--sm-chart-text", "#333") },
		grid: { vertLines: { color: "#f0f0f0" }, horzLines: { color: "#f0f0f0" } },
		rightPriceScale: { borderColor: token("--sm-chart-border", "#ccc") },
		timeScale: { borderColor: token("--sm-chart-border", "#ccc") },
		crosshair: { mode: 1 }
	});

	/* Enough colours for the strategies anybody is going to run at once, and
	   the benchmark last so it is always the same colour whatever is above
	   it. Grey, deliberately: it is the thing being measured against, not
	   another contender. */
	var colours = ["#0d6efd", "#198754", "#fd7e14", "#6f42c1", "#d63384", "#0dcaf0"];
	var benchmarkColour = "#6c757d";

	var legend = document.createElement("div");
	legend.className = "mt-2";
	root.parentNode.insertBefore(legend, root.nextSibling);

	var drawn = [];
	var colourIndex = 0;

	series.forEach(function (line) {

		var kind = String(line.kind || "strategy");
		var colour = kind === "benchmark"
			? benchmarkColour
			: colours[(colourIndex++) % colours.length];

		var options = {
			color: colour,
			lineWidth: kind === "strategy" ? 2 : 1,
			title: String(line.name || "")
		};
		if (kind === "backtest") {
			options.lineStyle = 2;             //dashed: a replay, not a record
			options.lineWidth = 1;
		}

		var tag = document.createElement("span");
		tag.className = "me-3";
		tag.style.color = colour;
		//textContent, so a strategy named after a tag is a strategy named
		//after a tag rather than a tag.
		tag.textContent = (kind === "backtest" ? "┄ " : "■ ") + String(line.name || "");
		legend.appendChild(tag);

		drawn.push({ line: line, kind: kind, series: chart.addLineSeries(options), tag: tag });
	});

	/* The latest first date across the lines being shown: the earliest date
	   all of them have a value for, which is where a common base has to be
	   if no line is to start part way up the axis. */
	function commonStart(shown) {
		var latest = null;
		shown.forEach(function (entry) {
			var points = entry.line.points || [];
			if (!points.length) { return; }
			var first = points[0].time;
			if (latest === null || first > latest) { latest = first; }
		});
		return latest;
	}

	function rebase(points, from) {
		var out = [];
		var base = null;
		for (var i = 0; i < points.length; i++) {
			var point = points[i];
			if (from !== null && point.time < from) { continue; }
			var total = Number(point.total);
			if (base === null) {
				if (!(total > 0)) { continue; }
				base = total;
			}
			out.push({ time: point.time, value: Math.round(100 * total / base * 10000) / 10000 });
		}
		return out;
	}

	function redraw() {

		var showBacktests = !!(backtestBox && backtestBox.checked);
		var common = !!(commonBox && commonBox.checked);

		var shown = drawn.filter(function (entry) {
			return entry.kind !== "backtest" || showBacktests;
		});

		var from = common ? commonStart(shown) : null;

		drawn.forEach(function (entry) {
			var visible = shown.indexOf(entry) !== -1;
			entry.series.applyOptions({ visible: visible });
			entry.tag.style.display = visible ? "" : "none";
			if (visible) { entry.series.setData(rebase(entry.line.points || [], from)); }
		});

		chart.timeScale().fitContent();

		say(common
			? "Every line starts at 100 on " + (from === null ? "its own first night" : from) + "."
			: "Each line starts at 100 on its own first night.");
	}

	if (commonBox) { commonBox.addEventListener("change", redraw); }
	if (backtestBox) { backtestBox.addEventListener("change", redraw); }

	redraw();

	/* Follow the container's width, which is the only thing that changes
	   when the window does: the height is fixed above. */
	window.addEventListener("resize", function () {
		chart.applyOptions({ width: root.clientWidth });
	});
}());
