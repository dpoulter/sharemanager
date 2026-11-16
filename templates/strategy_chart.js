/*
 * The strategy chart: every paper strategy and the benchmark on one axis,
 * each line rebased to 100 at its own start.
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
 * Degrading: if the library did not load, or JavaScript is off, the cards
 * above still carry every figure. The status line says which happened
 * rather than leaving an empty box.
 */
(function () {
	"use strict";

	var root = document.getElementById("strategy-chart");
	if (!root) { return; }

	var status = document.getElementById("strategy-chart-status");
	var holder = document.getElementById("strategy-chart-data");

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

	var chart = LightweightCharts.createChart(root, {
		height: 360,
		layout: { background: { color: "#ffffff" }, textColor: "#333" },
		grid: { vertLines: { color: "#f0f0f0" }, horzLines: { color: "#f0f0f0" } },
		rightPriceScale: { borderColor: "#ccc" },
		timeScale: { borderColor: "#ccc" },
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

	series.forEach(function (line, index) {

		var isBenchmark = /equal weighted/i.test(line.name || "");
		var colour = isBenchmark ? benchmarkColour : colours[index % colours.length];

		var drawn = chart.addLineSeries({
			color: colour,
			lineWidth: isBenchmark ? 1 : 2,
			title: String(line.name || "")
		});

		drawn.setData(line.points || []);

		var tag = document.createElement("span");
		tag.className = "me-3";
		tag.style.color = colour;
		//textContent, so a strategy named after a tag is a strategy named
		//after a tag rather than a tag.
		tag.textContent = "■ " + String(line.name || "");
		legend.appendChild(tag);
	});

	root.parentNode.insertBefore(legend, root.nextSibling);

	chart.timeScale().fitContent();

	/* Follow the container's width, which is the only thing that changes
	   when the window does: the height is fixed above. */
	window.addEventListener("resize", function () {
		chart.applyOptions({ width: root.clientWidth });
	});

	say("Each line starts at 100 on its own first night.");
}());
