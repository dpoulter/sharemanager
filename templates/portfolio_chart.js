/*
 * The performance chart on the Portfolio page.
 *
 * Two lines: what the account has been worth night by night, and the equal
 * weight FTSE 350 the paper strategies are measured against, rebased to
 * start at the same figure. The buttons change the span without reloading
 * the page.
 *
 * The same library as the quote page's chart, loaded by that page's script
 * tag. If it is missing - a server where tools/fetch_assets.sh has not run -
 * this draws nothing and says so, rather than throwing and taking the rest
 * of the page's JavaScript with it.
 *
 * Nothing from the server is put on the page as markup. The two things that
 * reach it are numbers and a note the server chose, and the note goes
 * through textContent.
 */
(function () {
	"use strict";

	var root   = document.getElementById("portfolio-chart");
	var status = document.getElementById("portfolio-chart-status");
	if (!root) { return; }

	if (typeof LightweightCharts === "undefined") {
		/* 300px of empty card above a sentence saying the chart is not
		   there. The box goes with it: the old page's "No performance
		   history available" sat inside exactly such a box, which is what
		   this rebuild is meant to stop. */
		root.style.display = "none";
		if (status) { status.textContent = "The chart library did not load."; }
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
		height: 320,
		layout: { background: { color: "transparent" }, textColor: token("--sm-chart-text", "#333") },
		grid: { vertLines: { color: token("--sm-chart-grid", "#eee") }, horzLines: { color: token("--sm-chart-grid", "#eee") } },
		rightPriceScale: { borderColor: token("--sm-chart-border", "#ccc") },
		timeScale: { borderColor: token("--sm-chart-border", "#ccc") },
		crosshair: { mode: 0 },
		/* Pounds, because a portfolio is pounds. The default would print
		   181404.83 beside a summary strip reading GBP181,404.83. */
		localization: {
			priceFormatter: function (value) {
				return "£" + Number(value).toLocaleString("en-GB", {
					minimumFractionDigits: 0, maximumFractionDigits: 0
				});
			}
		}
	});

	var mine = chart.addLineSeries({ color: "#0d6efd", lineWidth: 2, title: "Portfolio" });
	var bench = chart.addLineSeries({ color: "#6f42c1", lineWidth: 1, title: "FTSE 350 equal weight" });

	function resize() { chart.applyOptions({ width: root.clientWidth }); }
	window.addEventListener("resize", resize);

	function load(days) {

		if (status) { status.textContent = "Loading…"; }

		fetch("portfolio_data.php?days=" + encodeURIComponent(days),
		      { credentials: "same-origin" })
			.then(function (response) { return response.json(); })
			.then(function (data) {

				if (!data || data.ok !== true) {
					if (status) { status.textContent = "The performance history could not be read."; }
					return;
				}

				mine.setData(data.portfolio || []);
				bench.setData(data.benchmark || []);

				/* Nothing to draw is not an error, and an empty chart box
				   says less than a sentence does. */
				if (!data.portfolio || data.portfolio.length < 2) {
					root.style.display = "none";
				}
				else {
					root.style.display = "";
					resize();
					chart.timeScale().fitContent();
				}

				if (status) { status.textContent = data.note || ""; }
			})
			.catch(function () {
				if (status) { status.textContent = "The performance history could not be reached."; }
			});
	}

	document.querySelectorAll("[data-portfolio-span]").forEach(function (button) {
		button.addEventListener("click", function () {
			document.querySelectorAll("[data-portfolio-span]").forEach(function (other) {
				other.classList.remove("active");
			});
			button.classList.add("active");
			load(button.getAttribute("data-portfolio-span"));
		});
	});

	resize();
	load(365);
}());
