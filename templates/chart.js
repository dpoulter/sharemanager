/*
 * The price chart on the quote page, with a dot on every day that had news.
 *
 * Drawn with TradingView Lightweight Charts (Apache-2.0), served from
 * /js/lightweight-charts.standalone.production.js, which tools/fetch_assets.sh
 * downloads. Served from here rather than from a CDN because everything else
 * on this page is, and because a chart that stops working when somebody
 * else's CDN does is not an improvement on a PNG.
 *
 * Nothing in here writes a headline into HTML. Titles, summaries and
 * publishers are somebody else's text: they go in through textContent, and
 * the only attribute set from them is href, which is checked for a scheme
 * first. The page the markers are drawn on is the same page that lists them.
 *
 * Degrading: if the library did not load, or JavaScript is off, the <noscript>
 * and the fallback image keep the old stockgraph.php chart. The container
 * says which happened rather than sitting there empty.
 */
(function () {
	"use strict";

	var root = document.getElementById("price-chart");
	if (!root) { return; }

	var fallback = document.getElementById("price-chart-fallback");
	var panel    = document.getElementById("news-panel");
	var filter   = document.getElementById("news-filter");
	var status   = document.getElementById("chart-status");
	var symbol   = root.getAttribute("data-symbol") || "";

	function say(message) {
		if (status) { status.textContent = message; }
	}

	if (typeof LightweightCharts === "undefined") {
		//The library is not there. Leave the old image in place and say so.
		say("Interactive chart unavailable; showing the static one.");
		return;
	}

	if (fallback) { fallback.style.display = "none"; }

	var chart = LightweightCharts.createChart(root, {
		height: 320,
		layout: { background: { color: "transparent" }, textColor: "#333" },
		grid: { vertLines: { color: "#eee" }, horzLines: { color: "#eee" } },
		rightPriceScale: { borderColor: "#ccc" },
		timeScale: { borderColor: "#ccc" },
		crosshair: { mode: 0 },
		/* London prices are pence, and the rest of the page writes them as
		   "1,350.6p". The axis was printing the library's default, 1350.60,
		   so the chart and the header beside it were quoting the same price
		   two ways. */
		localization: {
			priceFormatter: function (value) {
				return Number(value).toLocaleString("en-GB", {
					minimumFractionDigits: 1, maximumFractionDigits: 1
				}) + "p";
			}
		}
	});

	var priceLine = chart.addLineSeries({ color: "#0d6efd", lineWidth: 2, title: "Close" });
	var ma50Line  = chart.addLineSeries({ color: "#fd7e14", lineWidth: 1, title: "50 day" });
	var ma200Line = chart.addLineSeries({ color: "#6f42c1", lineWidth: 1, title: "200 day" });

	var loaded = { markers: [] };
	var chosenImpact = "";

	function sentimentColour(sentiment) {
		if (sentiment === null || sentiment === undefined) { return "#9e9e9e"; }
		if (sentiment > 0.15)  { return "#198754"; }
		if (sentiment < -0.15) { return "#dc3545"; }
		return "#9e9e9e";
	}

	function markerSize(count) {
		//Three sizes. A day with eleven headlines is not eleven times the
		//news of a day with one, and a dot that grows without limit stops
		//being readable long before it stops growing.
		if (count >= 5) { return 2; }
		if (count >= 2) { return 1; }
		return 0;
	}

	function matchesFilter(day) {
		if (chosenImpact === "") { return true; }
		return (day.impact_types || []).indexOf(chosenImpact) !== -1;
	}

	function drawMarkers() {
		var marks = [];
		loaded.markers.forEach(function (day) {
			if (!matchesFilter(day)) { return; }
			marks.push({
				time: day.date,
				position: "belowBar",
				color: sentimentColour(day.sentiment),
				shape: "circle",
				size: markerSize(day.count),
				//Lightweight Charts renders this as canvas text, never as
				//HTML, so it cannot carry markup off the page.
				text: day.count === 1 ? "1" : String(day.count)
			});
		});
		priceLine.setMarkers(marks);
	}

	function dayFor(time) {
		for (var i = 0; i < loaded.markers.length; i++) {
			if (loaded.markers[i].date === time) { return loaded.markers[i]; }
		}
		return null;
	}

	function safeHref(link) {
		//Only http and https reach an href. A javascript: or data: link in a
		//feed is the one thing in here that could do something.
		var text = String(link || "");
		return /^https?:\/\//i.test(text) ? text : null;
	}

	function element(tag, className, text) {
		var node = document.createElement(tag);
		if (className) { node.className = className; }
		if (text !== undefined && text !== null) { node.textContent = String(text); }
		return node;
	}

	function showDay(day) {
		if (!panel) { return; }
		panel.innerHTML = "";

		if (!day) {
			panel.appendChild(element("p", "text-body-secondary",
				"Click a dot on the chart to read that day's headlines."));
			return;
		}

		panel.appendChild(element("h6", null,
			day.count + (day.count === 1 ? " headline on " : " headlines on ") + day.date));

		var list = element("ul", "list-group list-group-flush");

		day.articles.forEach(function (article) {
			if (chosenImpact !== "" && article.impact_type !== chosenImpact) { return; }

			var item = element("li", "list-group-item");

			var heading = element("div", null);
			var href = safeHref(article.link);
			if (href) {
				var link = element("a", null, article.title);
				link.href = href;
				link.target = "_blank";
				link.rel = "noopener noreferrer";
				heading.appendChild(link);
			} else {
				heading.appendChild(element("span", null, article.title));
			}
			item.appendChild(heading);

			var meta = [];
			if (article.publisher)   { meta.push(article.publisher); }
			if (article.impact_type) { meta.push(article.impact_type); }
			if (article.sentiment !== null && article.sentiment !== undefined) {
				meta.push("sentiment " + article.sentiment);
			}
			if (meta.length) {
				item.appendChild(element("small", "text-body-secondary d-block", meta.join(" · ")));
			}

			if (article.summary) { item.appendChild(element("p", "mb-1", article.summary)); }
			if (article.bull_point) {
				item.appendChild(element("small", "d-block text-success", "Bull: " + article.bull_point));
			}
			if (article.bear_point) {
				item.appendChild(element("small", "d-block text-danger", "Bear: " + article.bear_point));
			}
			if (!article.scored) {
				item.appendChild(element("small", "d-block text-body-secondary",
					"Not scored yet."));
			}

			list.appendChild(item);
		});

		panel.appendChild(list);
	}

	chart.subscribeClick(function (param) {
		if (!param || !param.time) { return; }
		showDay(dayFor(param.time));
	});

	if (filter) {
		filter.addEventListener("change", function () {
			chosenImpact = filter.value || "";
			drawMarkers();
			showDay(null);
		});
	}

	function load(from, to) {
		say("Loading…");
		var url = "chart_data.php?symbol=" + encodeURIComponent(symbol)
		        + "&from=" + encodeURIComponent(from) + "&to=" + encodeURIComponent(to);

		fetch(url, { credentials: "same-origin" })
			.then(function (response) { return response.json(); })
			.then(function (data) {
				if (!data || !data.ok) {
					say((data && data.error) ? data.error : "The chart data could not be loaded.");
					return;
				}
				priceLine.setData(data.prices || []);
				ma50Line.setData(data.ma50 || []);
				ma200Line.setData(data.ma200 || []);
				loaded = data;
				drawMarkers();
				showDay(null);
				chart.timeScale().fitContent();
				var withNews = (data.markers || []).length;
				say(data.prices.length + " days, " + withNews
				    + (withNews === 1 ? " with news" : " days with news"));
			})
			.catch(function () { say("The chart data could not be loaded."); });
	}

	function isoDaysAgo(days) {
		var when = new Date();
		when.setDate(when.getDate() - days);
		return when.toISOString().slice(0, 10);
	}

	var spans = document.querySelectorAll("[data-chart-span]");
	Array.prototype.forEach.call(spans, function (button) {
		button.addEventListener("click", function (event) {
			event.preventDefault();
			Array.prototype.forEach.call(spans, function (other) {
				other.classList.remove("active");
			});
			button.classList.add("active");
			load(isoDaysAgo(parseInt(button.getAttribute("data-chart-span"), 10)),
			     new Date().toISOString().slice(0, 10));
		});
	});

	//A year to start with, which is what the old chart defaulted to.
	load(isoDaysAgo(365), new Date().toISOString().slice(0, 10));

	//The explain panel asks the chart what range is on screen.
	window.smChartRange = function () {
		var range = chart.timeScale().getVisibleRange();
		if (!range) { return null; }
		return { from: String(range.from), to: String(range.to) };
	};
})();

/*
 * "Why did it move?" - the panel under the chart.
 *
 * Separate from the chart itself so that a browser without the charting
 * library still gets the from/to pickers and the button: the explanation is
 * about a date range, and a date range does not need a chart to express.
 *
 * Nothing is sent until the button is pressed. The same range comes back
 * from the cache; Regenerate is the way to pay for it again.
 */
(function () {
	"use strict";

	var go     = document.getElementById("explain-go");
	var again  = document.getElementById("explain-again");
	var panel  = document.getElementById("explain-panel");
	var fromIn = document.getElementById("explain-from");
	var toIn   = document.getElementById("explain-to");

	if (!go || !panel || !fromIn || !toIn) { return; }

	var symbol = go.getAttribute("data-symbol") || "";

	function element(tag, className, text) {
		var node = document.createElement(tag);
		if (className) { node.className = className; }
		if (text !== undefined && text !== null) { node.textContent = String(text); }
		return node;
	}

	function isoDaysAgo(days) {
		var when = new Date();
		when.setDate(when.getDate() - days);
		return when.toISOString().slice(0, 10);
	}

	//Default to what the chart is showing, or the last month.
	if (typeof window.smChartRange === "function") {
		var range = window.smChartRange();
		if (range) { fromIn.value = range.from; toIn.value = range.to; }
	}
	if (!fromIn.value) { fromIn.value = isoDaysAgo(30); }
	if (!toIn.value)   { toIn.value = new Date().toISOString().slice(0, 10); }

	function show(data) {
		panel.innerHTML = "";

		if (!data || !data.ok) {
			panel.appendChild(element("div", "alert alert-warning py-2 mb-0",
				(data && data.error) ? data.error : "The explanation could not be generated."));
			return;
		}

		var answer = data.explanation || {};
		var card = element("div", "card card-default");
		var body = element("div", "card-body");

		body.appendChild(element("p", "mb-2", answer.summary || ""));

		if ((answer.drivers || []).length) {
			var list = element("ul", "mb-2");
			answer.drivers.forEach(function (driver) {
				list.appendChild(element("li", null,
					driver.date + ": " + driver.reason + " (" + driver.source + ")"));
			});
			body.appendChild(list);
		} else {
			body.appendChild(element("p", "text-body-secondary mb-2",
				"No stored headline in this range accounts for the move."));
		}

		if (answer.market_vs_stock) {
			body.appendChild(element("p", "mb-2", answer.market_vs_stock));
		}

		var meta = "Confidence: " + (answer.confidence || "unknown");
		if (answer.caveat) { meta += " · " + answer.caveat; }
		body.appendChild(element("small", "d-block text-body-secondary", meta));

		var cost = element("small", "d-block text-body-secondary",
			(data.cached ? "From the cache, generated " + (data.created_at || "earlier")
			             : "Cost $" + Number(data.cost || 0).toFixed(4))
			+ " · " + (data.model || "") + " · Not financial advice.");
		body.appendChild(cost);

		card.appendChild(body);
		panel.appendChild(card);
		if (again) { again.style.display = ""; }
	}

	function ask(regenerate) {
		panel.innerHTML = "";
		panel.appendChild(element("p", "text-body-secondary mb-0",
			regenerate ? "Asking again…" : "Working it out…"));

		var body = "symbol=" + encodeURIComponent(symbol)
		         + "&from=" + encodeURIComponent(fromIn.value)
		         + "&to=" + encodeURIComponent(toIn.value)
		         + (regenerate ? "&regenerate=1" : "");

		fetch("ai_explain.php", {
			method: "POST",
			credentials: "same-origin",
			headers: { "Content-Type": "application/x-www-form-urlencoded" },
			body: body
		})
			.then(function (response) { return response.json(); })
			.then(show)
			.catch(function () { show(null); });
	}

	go.addEventListener("click", function () { ask(false); });
	if (again) { again.addEventListener("click", function () { ask(true); }); }
})();
