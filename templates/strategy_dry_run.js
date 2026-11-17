/*
 * The "Dry run tonight" popup on the strategy page.
 *
 * Asks strategy_dry_run.php what tonight's run would do and draws it as a
 * table: sells first, then buys, with the reason for each in plain words.
 * That endpoint writes nothing, and there is no "run for real" button here
 * or anywhere else - the nightly job is the only thing that trades.
 *
 * Everything from the server goes into the page through textContent or
 * through a number formatter, never through innerHTML. A strategy name and
 * a company name are both data somebody can edit, and a reason is a string
 * the server chose; none of them is markup. The one exception is the
 * skeleton of the table, which is built element by element rather than
 * assembled from strings.
 *
 * Degrading: with JavaScript off the button does nothing and the figures on
 * the cards are all still there. The modal says what went wrong rather than
 * spinning for ever.
 */
(function () {
	"use strict";

	var modalElement = document.getElementById("dry-run-modal");
	var body = document.getElementById("dry-run-body");
	var title = document.getElementById("dry-run-title");
	var footnote = document.getElementById("dry-run-footnote");

	if (!modalElement || !body || typeof bootstrap === "undefined") { return; }

	var modal = new bootstrap.Modal(modalElement);

	function clear(node) {
		while (node.firstChild) { node.removeChild(node.firstChild); }
	}

	function element(tag, className, text) {
		var node = document.createElement(tag);
		if (className) { node.className = className; }
		if (text !== undefined && text !== null) { node.textContent = String(text); }
		return node;
	}

	/* Money and percentages, formatted here rather than on the server: the
	   server sends numbers, which is what a number is. */
	function money(value, places) {
		if (value === null || value === undefined) { return "–"; }
		return Number(value).toLocaleString("en-GB", {
			minimumFractionDigits: places === undefined ? 2 : places,
			maximumFractionDigits: places === undefined ? 2 : places
		});
	}

	function whole(value) {
		if (value === null || value === undefined) { return "–"; }
		return Number(value).toLocaleString("en-GB");
	}

	//A number of shares, which on a Trading 212 account may be a fraction:
	//0.44 of a share has to read as 0.44 rather than as 0.
	function shares(value) {
		if (value === null || value === undefined) { return "–"; }
		var number = Number(value);
		if (Math.abs(number - Math.round(number)) < 0.0000001) {
			return Math.round(number).toLocaleString("en-GB");
		}
		return number.toLocaleString("en-GB", { maximumFractionDigits: 4 });
	}

	function percent(value) {
		if (value === null || value === undefined) { return "–"; }
		return (Number(value) * 100).toFixed(1) + "%";
	}

	function spinner(strategyName) {
		title.textContent = "Dry run: " + strategyName;
		footnote.textContent = "";
		clear(body);
		var waiting = element("div", "d-flex align-items-center");
		var spin = element("span", "spinner-border spinner-border-sm me-2");
		spin.setAttribute("role", "status");
		waiting.appendChild(spin);
		waiting.appendChild(element("span", null, "Working out what tonight would do…"));
		body.appendChild(waiting);
	}

	function alertBox(kind, text) {
		return element("div", "alert alert-" + kind + " py-2 mb-3", text);
	}

	function tradeTable(trades) {

		var wrapper = element("div", "table-responsive");
		var table = element("table", "table table-sm table-striped align-middle");
		var head = element("thead");
		var headRow = element("tr");

		["Side", "Share", "Company", "Shares", "Price £", "Value £",
		 "Est. costs £", "Weight now → target", "Reason"].forEach(function (label, i) {
			var cell = element("th", i >= 3 && i <= 7 ? "text-end" : null, label);
			headRow.appendChild(cell);
		});

		head.appendChild(headRow);
		table.appendChild(head);

		var tbody = element("tbody");

		trades.forEach(function (trade) {

			var row = element("tr");

			var side = element("td");
			side.appendChild(element("span",
				"badge " + (trade.side === "SELL" ? "bg-danger" : "bg-success"), trade.side));
			row.appendChild(side);

			var share = element("td");
			var link = element("a", null, trade.symbol);
			//The quote page, in a new tab, so the popup is not lost.
			link.href = "quote.php?symbol=" + encodeURIComponent(trade.symbol);
			link.target = "_blank";
			link.rel = "noopener noreferrer";
			share.appendChild(link);
			row.appendChild(share);

			row.appendChild(element("td", null, trade.company || ""));
			row.appendChild(element("td", "text-end", shares(trade.quantity)));
			row.appendChild(element("td", "text-end", money(trade.price, 4)));
			row.appendChild(element("td", "text-end", money(trade.value)));
			row.appendChild(element("td", "text-end",
				trade.costs ? money(trade.costs.total) : "–"));
			row.appendChild(element("td", "text-end",
				percent(trade.weight_now) + " → " + percent(trade.weight_target)));
			row.appendChild(element("td", null, trade.reason));

			tbody.appendChild(row);
		});

		table.appendChild(tbody);
		wrapper.appendChild(table);

		return wrapper;
	}

	function totalsBlock(totals) {

		var list = element("div", "row g-3");

		[["Buys", whole(totals.buys)],
		 ["Sells", whole(totals.sells)],
		 ["Value bought £", money(totals.value_bought)],
		 ["Value sold £", money(totals.value_sold)],
		 ["Est. costs £", money(totals.costs.total)],
		 ["Cash before £", money(totals.cash_before)],
		 ["Cash after £", money(totals.cash_after)],
		 ["Holdings", whole(totals.holdings_before) + " → " + whole(totals.holdings_after)]
		].forEach(function (pair) {
			var column = element("div", "col-6 col-md-3");
			column.appendChild(element("div", "text-muted small", pair[0]));
			column.appendChild(element("div", "h6 mb-0", pair[1]));
			list.appendChild(column);
		});

		var split = element("p", "text-muted small mt-2 mb-0",
			"Costs: commission £" + money(totals.costs.commission)
			+ ", stamp duty £" + money(totals.costs.stamp_duty)
			+ ", spread £" + money(totals.costs.slippage) + ".");

		var block = element("div", "mt-3");
		block.appendChild(element("h6", null, "Totals"));
		block.appendChild(list);
		block.appendChild(split);

		if (totals.trim_expected) {
			block.appendChild(element("p", "text-muted small mb-0",
				"Cash runs out before the last purchase at these prices, so the engine will"
				+ " buy fewer shares of it than planned."));
		}

		return block;
	}

	function draw(report) {

		clear(body);

		title.textContent = "Dry run: " + report.strategy;
		footnote.textContent = report.footnote || "";

		var header = element("p", "text-muted mb-3");
		header.appendChild(element("span", null,
			"As at " + report.as_of + ". "));
		header.appendChild(element("strong", null,
			report.rebalance
				? "Tonight is a rebalance."
				: "No rebalance tonight; next on " + (report.next_rebalance || "a later date") + "."));
		if (!report.rebalance) {
			header.appendChild(element("span", null, " Only the exit checks run."));
		}
		body.appendChild(header);

		if (report.halted) {
			body.appendChild(alertBox("danger",
				"Trading is halted: the kill switch file exists, so the nightly run would place"
				+ " nothing."));
		}

		(report.errors || []).forEach(function (error) {
			body.appendChild(alertBox("danger", error));
		});

		if (!report.trades || !report.trades.length) {
			body.appendChild(alertBox("secondary", "No trades tonight."));
			return;
		}

		body.appendChild(tradeTable(report.trades));
		body.appendChild(totalsBlock(report.totals));
	}

	function ask(strategyName) {

		spinner(strategyName);
		modal.show();

		var parameters = new URLSearchParams();
		parameters.set("strategy", strategyName);

		fetch("strategy_dry_run.php", {
			method: "POST",
			headers: {"Content-Type": "application/x-www-form-urlencoded"},
			body: parameters.toString(),
			credentials: "same-origin"
		}).then(function (response) {
			return response.json().then(function (data) {
				return {status: response.status, data: data};
			});
		}).then(function (answer) {
			if (!answer.data || answer.data.ok !== true) {
				clear(body);
				body.appendChild(alertBox("danger",
					(answer.data && answer.data.error)
						? answer.data.error
						: "The dry run could not be worked out (" + answer.status + ")."));
				return;
			}
			draw(answer.data);
		}).catch(function () {
			clear(body);
			body.appendChild(alertBox("danger",
				"The dry run could not be reached. The figures on the page are unaffected."));
		});
	}

	document.querySelectorAll("[data-dry-run]").forEach(function (button) {
		button.addEventListener("click", function () {
			ask(button.getAttribute("data-dry-run") || "");
		});
	});
}());
