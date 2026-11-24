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
		/* Through smChartColour() in scripts.js, which asks the browser
		   what the token resolves to. The value app.css writes is
		   light-dark(...), which this library cannot parse: it threw
		   inside createChart and the chart drew nothing. */
		return (typeof smChartColour === "function")
			? smChartColour(name, fallback) : fallback;
	}

	/* Everything that can throw, in one place. A chart library given a
	   colour it cannot parse throws from inside createChart, and what the
	   reader got was the heading, the two toggles and an empty box: no
	   line, and no word about why. The figures above the chart do not need
	   it, so a failure here is a sentence, not a blank. */
	var MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun",
	              "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

	/* A chart time as day, month and optionally year: "18 Nov". The
	   library hands back a "YYYY-MM-DD" string, a business day object or
	   a timestamp depending on where it came from, so all three are read. */
	function dayMonth(time, withYear) {
		var y, m, d;
		if (typeof time === "string") {
			var parts = time.split("-");
			y = +parts[0]; m = +parts[1]; d = +parts[2];
		}
		else if (time && typeof time === "object") {
			y = time.year; m = time.month; d = time.day;
		}
		else {
			var date = new Date(Number(time) * 1000);
			y = date.getUTCFullYear(); m = date.getUTCMonth() + 1; d = date.getUTCDate();
		}
		return d + " " + MONTHS[m - 1] + (withYear ? " " + y : "");
	}

	/* The end labels sit in a column to the right of the plot, so the
	   y-axis numbers go on the left where nothing sits on them. */
	var LABEL_WIDTH = (root.clientWidth && root.clientWidth < 576) ? 110 : 170;
	var LABEL_GAP = 16;

	function build() {

		root.style.position = "relative";
		var plot = document.createElement("div");
		plot.className = "sm-chart-plot";
		plot.style.marginRight = LABEL_WIDTH + "px";
		var labels = document.createElement("div");
		labels.className = "sm-chart-labels";
		labels.style.width = LABEL_WIDTH + "px";
		root.appendChild(plot);
		root.appendChild(labels);

		var HEIGHT = 360;

		var chart = LightweightCharts.createChart(plot, {
			height: HEIGHT,
			/* Transparent, so the chart is the card's colour in either
			   scheme. It used to be #ffffff with a #f0f0f0 grid written in,
			   which is a white box in the middle of a dark page. */
			layout: { background: { color: "transparent" }, textColor: token("--sm-chart-text", "#333") },
			grid: { vertLines: { color: token("--sm-chart-grid", "#eee") },
			        horzLines: { color: token("--sm-chart-grid", "#eee") } },
			leftPriceScale: { visible: true, borderColor: token("--sm-chart-border", "#ccc"),
			                  scaleMargins: { top: 0.08, bottom: 0.08 } },
			rightPriceScale: { visible: false },
			timeScale: { borderColor: token("--sm-chart-border", "#ccc"),
			             tickMarkFormatter: function (time) { return dayMonth(time, false); } },
			localization: { timeFormatter: function (time) { return dayMonth(time, true); } },
			crosshair: { mode: 1 }
		});

		/* Enough colours for the strategies anybody is going to run at once, and
		   the benchmark last so it is always the same colour whatever is above
		   it. Grey, deliberately: it is the thing being measured against, not
		   another contender. */
		var colours = [token("--sm-chart-close", "#0b5a70"), "#198754",
		               token("--sm-chart-ma50", "#c26a00"),
		               token("--sm-chart-ma200", "#7a4fb5"), "#d63384", "#0dcaf0"];
		var benchmarkColour = token("--sm-muted", "#6c757d");

		var legend = document.createElement("div");
		legend.className = "mt-2 sm-chart-legend";
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
				priceScaleId: "left",
				/* The end labels are drawn beside the chart, stacked so
				   they never overlap; the library's own would. */
				lastValueVisible: false,
				priceLineVisible: false
			};
			if (kind === "backtest") {
				options.lineStyle = 2;             //dashed: a replay, not a record
				options.lineWidth = 1;
			}

			//textContent throughout, so a strategy named after a tag is a
			//strategy named after a tag rather than a tag.
			var tag = document.createElement("span");
			tag.className = "sm-legend-item me-3";
			var swatch = document.createElement("span");
			swatch.className = "sm-legend-swatch" + (kind === "backtest" ? " sm-legend-dashed" : "");
			swatch.style.borderColor = colour;
			if (kind !== "backtest") { swatch.style.background = colour; }
			var words = document.createElement("span");
			words.textContent = String(line.legend || line.name || "");
			tag.appendChild(swatch);
			tag.appendChild(words);
			legend.appendChild(tag);

			var label = document.createElement("div");
			label.className = "sm-chart-label";
			label.style.color = colour;
			labels.appendChild(label);

			drawn.push({ line: line, kind: kind, series: chart.addLineSeries(options),
			             tag: tag, label: label, last: null });
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

		/* The end labels: name and value at the height of each line's last
		   point, pushed apart so no two are closer than LABEL_GAP, and kept
		   inside the plot. */
		function placeLabels() {
			var items = [];
			drawn.forEach(function (entry) {
				if (entry.last === null) { entry.label.style.display = "none"; return; }
				var y = entry.series.priceToCoordinate(entry.last);
				if (y === null) { entry.label.style.display = "none"; return; }
				entry.label.style.display = "";
				items.push({ entry: entry, y: y });
			});
			items.sort(function (a, b) { return a.y - b.y; });
			var bottom = HEIGHT - 30;              //above the time axis
			for (var i = 0; i < items.length; i++) {
				if (i > 0 && items[i].y < items[i - 1].y + LABEL_GAP) { items[i].y = items[i - 1].y + LABEL_GAP; }
			}
			for (var j = items.length - 1; j >= 0; j--) {
				var limit = (j === items.length - 1) ? bottom : items[j + 1].y - LABEL_GAP;
				if (items[j].y > limit) { items[j].y = limit; }
			}
			items.forEach(function (item) {
				item.entry.label.style.top = Math.max(0, Math.round(item.y - LABEL_GAP / 2)) + "px";
			});
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
				entry.last = null;
				if (visible) {
					var data = rebase(entry.line.points || [], from);
					entry.series.setData(data);
					if (data.length) { entry.last = data[data.length - 1].value; }
					//The name may be cut short; the value never is.
					entry.label.textContent = "";
					var nameSpan = document.createElement("span");
					nameSpan.className = "sm-chart-label-name";
					nameSpan.textContent = String(entry.line.name || "");
					var valueSpan = document.createElement("span");
					valueSpan.textContent = entry.last === null ? "" : entry.last.toFixed(1);
					entry.label.title = nameSpan.textContent + " " + valueSpan.textContent;
					entry.label.appendChild(nameSpan);
					entry.label.appendChild(valueSpan);
				}
			});

			chart.timeScale().fitContent();

			var dateLabel = document.getElementById("chart-common-date");
			if (dateLabel) { dateLabel.textContent = from === null ? "" : " (" + dayMonth(from, false) + ")"; }

			say(common && from !== null
				? "All lines start at 100 on " + dayMonth(from, true)
				  + ", the first night every strategy has a value."
				: "Each line starts at 100 on its own first night.");

			window.requestAnimationFrame(placeLabels);
		}

		if (commonBox) { commonBox.addEventListener("change", redraw); }
		if (backtestBox) { backtestBox.addEventListener("change", redraw); }

		redraw();

		chart.timeScale().subscribeVisibleLogicalRangeChange(function () {
			window.requestAnimationFrame(placeLabels);
		});

		/* Follow the container's width, which is the only thing that changes
		   when the window does: the height is fixed above. */
		window.addEventListener("resize", function () {
			chart.applyOptions({ width: plot.clientWidth });
			window.requestAnimationFrame(placeLabels);
		});
	}

	try { build(); }
	catch (e) {
		say("The chart could not be drawn (" + (e && e.message ? e.message : e)
		    + "); the figures above do not need it.");
	}

}());
