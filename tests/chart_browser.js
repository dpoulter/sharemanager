/*
 * The charts, in a browser.
 *
 *   node tests/chart_browser.js <base-url> <user> <password>
 *
 * Run by tests/tests.php, which sets up two nights of values first and
 * skips this when node, Playwright or the vendored front-end assets are
 * missing. It prints "PASS <name>" and "FAIL <name>: <detail>" lines for
 * tests.php to count, like tests/typeahead_browser.js.
 *
 * Why a browser. Every chart in the application drew nothing from the day
 * app.css started writing its tokens with light-dark(): the chart library
 * cannot parse "light-dark(#333333, #c7cbd1)", so it threw from inside
 * createChart and the page showed a heading, two toggles and an empty box
 * with no message. Nothing that reads source could see it - the markup,
 * the JSON and both scripts were correct - and the page still returned
 * 200 with every figure on it. Only a browser knows whether a chart drew.
 *
 * So the question asked here is the one a reader asks: is there a chart
 * in the box, and if there is not, does the page say why.
 */
'use strict';

const BASE = process.argv[2] || 'http://127.0.0.1:8099';
const USER = process.argv[3] || 'tester';
const PASS = process.argv[4] || 'testpass';

let failed = 0;

function check(name, ok, detail) {
	if (ok) { console.log('PASS ' + name); }
	else { failed++; console.log('FAIL ' + name + ': ' + (detail || '')); }
}

(async () => {

	let chromium;
	try { ({ chromium } = require('playwright')); }
	catch (e) { console.log('SKIP playwright is not installed'); process.exit(0); }

	const browser = await chromium.launch();

	try {
		/* Dark, because that is where a chart that writes its own white
		   background and its own light grid looks wrong, and because the
		   token that could not be parsed is the one that differs between
		   the two schemes. */
		const context = await browser.newContext({
			viewport: { width: 1280, height: 900 },
			colorScheme: 'dark',
		});
		const page = await context.newPage();

		const errors = [];
		page.on('pageerror', e => errors.push(e.message));

		await page.goto(`${BASE}/login.php`, { waitUntil: 'domcontentloaded' });
		await page.fill('input[name=username]', USER);
		await page.fill('input[name=password]', PASS);
		await page.click('form[action="login.php"] button[type=submit]');
		await page.waitForLoadState('domcontentloaded');

		/* ---- the strategies chart, with two nights of history ---- */

		await page.goto(`${BASE}/strategies.php`, { waitUntil: 'networkidle' });
		await page.waitForTimeout(500);

		const strategy = await page.evaluate(() => {
			const root = document.getElementById('strategy-chart');
			const status = document.getElementById('strategy-chart-status');
			const data = document.getElementById('strategy-chart-data');
			let lines = 0;
			try { lines = JSON.parse((data && data.textContent) || '[]').length; }
			catch (e) { lines = -1; }
			const canvases = root
				? Array.from(root.querySelectorAll('canvas'))
				       .map(c => Math.round(c.getBoundingClientRect().height))
				: [];
			return {
				present: !!root,
				lines: lines,
				height: root ? Math.round(root.getBoundingClientRect().height) : 0,
				tallest: canvases.length ? Math.max.apply(null, canvases) : 0,
				status: status ? status.textContent.trim() : null,
				legend: document.querySelectorAll('#strategy-chart + div span').length,
			};
		});

		check('the strategies page has two nights to draw', strategy.lines >= 2,
		      JSON.stringify(strategy));
		check('the chart is drawn rather than left as an empty box',
		      strategy.height > 100 && strategy.tallest > 100,
		      `box ${strategy.height}px, tallest canvas ${strategy.tallest}px`);
		check('and it says what the lines are measured from',
		      !!strategy.status && strategy.status.length > 0,
		      JSON.stringify(strategy.status));
		check('with a legend naming every line', strategy.legend >= strategy.lines,
		      `${strategy.legend} tags for ${strategy.lines} lines`);

		/* The colours come from the page, resolved to something the library
		   can read. A token it cannot parse is what emptied every chart. */
		const colour = await page.evaluate(() => {
			if (typeof smChartColour !== 'function') { return { helper: false }; }
			return { helper: true,
			         text: smChartColour('--sm-chart-text', '#333'),
			         missing: smChartColour('--sm-no-such-token', '#abcdef') };
		});
		check('a page colour reaches the chart as a colour, not as light-dark()',
		      colour.helper === true && /^rgb/.test(colour.text || ''),
		      JSON.stringify(colour));
		check('and a token the stylesheet does not define falls back',
		      colour.missing === '#abcdef', JSON.stringify(colour));

		/* ---- and the other two charts, which share the helper ---- */

		for (const [what, url, id] of [['the quote', 'quote.php?symbol=AAA', 'price-chart'],
		                               ['the portfolio', 'performance.php', 'portfolio-chart']]) {
			await page.goto(`${BASE}/${url}`, { waitUntil: 'networkidle' });
			await page.waitForTimeout(500);
			const drawn = await page.evaluate((elementId) => {
				const root = document.getElementById(elementId);
				if (!root) { return { present: false }; }
				const canvases = Array.from(root.querySelectorAll('canvas'))
				                      .map(c => Math.round(c.getBoundingClientRect().height));
				return { present: true,
				         height: Math.round(root.getBoundingClientRect().height),
				         tallest: canvases.length ? Math.max.apply(null, canvases) : 0 };
			}, id);
			/* A page with no data for its chart draws no chart, and that is
			   not this test's business: an empty box where the element is
			   on the page is. */
			check(`${what} chart is drawn too`,
			      drawn.present === false || (drawn.height > 100 && drawn.tallest > 100),
			      JSON.stringify(drawn));
		}

		check('and no chart script threw', errors.length === 0, errors.join(' | '));

		await context.close();
	}
	finally {
		await browser.close().catch(() => {});
	}

	/* tests.php counts the PASS and FAIL lines; the exit status only says
	   whether the run itself got that far. */
	process.exit(0);
})();
