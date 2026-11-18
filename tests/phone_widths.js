/*
 * Every page at 390px.
 *
 *   node tests/phone_widths.js
 *
 * The application is used on a phone and had never been looked at on one.
 * What goes wrong is always the same thing: a table wider than the screen
 * makes the page itself scroll sideways, which takes the navbar with it -
 * so on a phone there is no way back to Home from a page you have scrolled.
 * A table that scrolls inside its own card does not do that.
 *
 * This renders each page at 390 by 844 - an iPhone 12, the narrowest screen
 * worth designing for - and fails if the document is wider than the viewport.
 * It also opens a modal and checks that it fits, because a modal wider than
 * the screen cannot be dismissed: its close button is off the right edge.
 *
 * Run by tests/run_tests.sh when Playwright and a browser are present, and
 * skipped with a message when they are not: this is a check on the layout,
 * not on the queries, and a host without a browser should still be able to
 * run the suite.
 *
 * It starts its own PHP server, on a port of its own so it cannot collide
 * with the one tests.php runs.
 */
'use strict';

const { spawn } = require('child_process');
const path = require('path');

const PORT = Number(process.env.SM_PHONE_PORT || 8299);
const BASE = `http://127.0.0.1:${PORT}`;
const REPO = path.resolve(__dirname, '..');

const PAGES = [
	['the dashboard',        'index.php'],
	['the portfolio',        'performance.php'],
	['a quote page',         'quote.php?symbol=AAA'],
	['the screens',          'screen_list.php'],
	['a screen run',         'screen_list.php?screen=2&run=1'],
	['in the news',          'in_the_news.php'],
	['the strategies',       'strategies.php'],
	['a ranking',            'rankings.php?score=momentum'],
	['the cash history',     'cash_history.php'],
	['the dividends',        'dividends.php'],
	['the transactions',     'edit.php'],
	['the broker',           'broker.php'],
];

let passed = 0, failed = 0;
const failures = [];

function check(name, ok, detail) {
	if (ok) { passed++; console.log(`  \x1b[32mPASS\x1b[0m  ${name}`); }
	else {
		failed++; failures.push(name);
		console.log(`  \x1b[31mFAIL\x1b[0m  ${name}${detail ? '\n          ' + detail : ''}`);
	}
}

function skip(why) {
	console.log(`  \x1b[33mSKIP\x1b[0m  phone widths: ${why}`);
	process.exit(0);
}

(async () => {

	let chromium;
	try { ({ chromium } = require('playwright')); }
	catch (e) { skip('playwright is not installed'); }

	const env = Object.assign({}, process.env, {
		SM_DB_NAME: process.env.SM_TEST_DB   || 'sharemanager_test',
		SM_DB_HOST: process.env.SM_TEST_HOST || '127.0.0.1',
		SM_DB_USER: process.env.SM_TEST_USER || 'smtest',
		SM_DB_PASS: process.env.SM_TEST_PASS || 'smtest',
		SM_DISPLAY_ERRORS: '1',
	});

	const server = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', path.join(REPO, 'public'),
	                             '-d', 'opcache.enable=0'],
	                     { env, stdio: 'ignore', detached: false });

	const stop = () => { try { server.kill(); } catch (e) { /* already gone */ } };
	process.on('exit', stop);

	let browser;
	try {
		//Wait for the server rather than guessing at a delay.
		let up = false;
		for (let i = 0; i < 40 && !up; i++) {
			await new Promise(r => setTimeout(r, 250));
			try {
				const response = await fetch(`${BASE}/login.php`);
				up = response.ok;
			} catch (e) { /* not listening yet */ }
		}
		if (!up) { skip(`no PHP server answered on ${BASE}`); }

		try { browser = await chromium.launch(); }
		catch (e) { skip('no browser available: ' + e.message); }

		/* Both colour schemes. The page follows the machine, so a layout
		   checked only in light is a layout half checked - and the score
		   badges are mixed colours whose contrast has to hold in each. */
		for (const scheme of ['light', 'dark']) {

		const context = await browser.newContext({
			viewport: { width: 390, height: 844 },
			deviceScaleFactor: 1,
			isMobile: true,
			hasTouch: true,
			colorScheme: scheme,
		});
		const page = await context.newPage();

		const scriptErrors = [];
		page.on('pageerror', e => scriptErrors.push(e.message));

		await page.goto(`${BASE}/login.php`, { waitUntil: 'domcontentloaded' });
		await page.fill('input[name=username]', 'tester');
		await page.fill('input[name=password]', 'testpass');
		await page.click('form[action="login.php"] button[type=submit]');
		await page.waitForLoadState('domcontentloaded');

		for (const [what, url] of PAGES) {

			await page.goto(`${BASE}/${url}`, { waitUntil: 'networkidle' }).catch(() => {});

			const measured = await page.evaluate(() => {
				const doc = document.documentElement;
				//Which element is sticking out, if any - a bare number is not
				//enough to go and fix it with.
				let widest = null, widestBy = 0;
				document.querySelectorAll('body *').forEach(el => {
					const over = Math.round(el.getBoundingClientRect().right - doc.clientWidth);
					if (over > widestBy) {
						widestBy = over;
						widest = el.tagName.toLowerCase()
						       + (el.className && typeof el.className === 'string'
						          ? '.' + el.className.trim().split(/\s+/).join('.') : '');
					}
				});
				return {
					overflow: doc.scrollWidth - doc.clientWidth,
					widest, widestBy,
					fatal: /Fatal error|Parse error/.test(document.body.innerText),
				};
			});

			check(`${scheme}: ${what} renders at 390px`, !measured.fatal);
			/* One pixel of slack: a border on a full width element rounds up
			   on some zoom levels and that is not a layout fault. */
			check(`${scheme}: ${what} does not scroll the page sideways`,
			      measured.overflow <= 1,
			      `${measured.overflow}px over; widest is ${measured.widest} (+${measured.widestBy}px)`);
		}

		/* The status badges carry white text on their own background,
		   which is the one place in the house style where a colour has to
		   clear a contrast ratio against something drawn under it rather
		   than against the page. The scores are bars now and are measured
		   in tests.php against the surface they sit on. */
		await page.goto(`${BASE}/index.php`, { waitUntil: 'networkidle' });
		const contrast = await page.evaluate(() => {
			const probe = document.createElement('div');
			probe.style.position = 'absolute';
			probe.style.visibility = 'hidden';
			document.body.appendChild(probe);

			const canvas = document.createElement('canvas').getContext('2d');
			function srgb(css) {
				canvas.fillStyle = '#000';
				canvas.fillStyle = css;
				canvas.fillRect(0, 0, 1, 1);
				const d = canvas.getImageData(0, 0, 1, 1).data;
				return [d[0], d[1], d[2]];
			}
			function lum(c) {
				const v = c.map(x => x / 255)
				           .map(x => x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4));
				return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
			}
			function ratio(a, b) {
				const la = lum(a), lb = lum(b);
				return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
			}

			const out = [];
			for (const name of ['sm-status-paper', 'sm-status-off', 'sm-status-halt',
			                    'sm-status-due', 'sm-status-ok']) {
				const badge = document.createElement('span');
				badge.className = 'sm-badge ' + name;
				badge.textContent = 'x';
				probe.appendChild(badge);
				const style = getComputedStyle(badge);
				out.push({ score: name,
				           ratio: ratio(srgb(style.backgroundColor), srgb(style.color)) });
			}

			probe.remove();
			return out;
		});

		const low = contrast.filter(c => c.ratio < 4.5);
		check(`${scheme}: every status badge passes AA against its own text`,
		      low.length === 0,
		      contrast.map(c => `${c.score}:${c.ratio.toFixed(2)}`).join(' '));

		/* The theme the page is actually in, so a failure above cannot be a
		   test that checked light twice. */
		const applied = await page.evaluate(() => ({
			attr: document.documentElement.getAttribute('data-bs-theme'),
			body: getComputedStyle(document.body).backgroundColor,
		}));
		check(`${scheme}: the page is in the ${scheme} scheme`,
		      applied.attr === scheme, JSON.stringify(applied));

		/* The navbar has to collapse, or the links are simply invisible. */
		await page.goto(`${BASE}/index.php`, { waitUntil: 'networkidle' });
		const toggler = await page.locator('.navbar-toggler').first();
		check('the navbar collapses behind a toggler', await toggler.isVisible());
		await toggler.click();
		await page.waitForTimeout(500);
		check('and opening it shows the pages',
		      await page.locator('.navbar-collapse a[href="screen_list.php"]').first().isVisible());

		/* A modal wider than the screen cannot be dismissed: its close button
		   is off the right edge. */
		await page.goto(`${BASE}/performance.php`, { waitUntil: 'networkidle' });
		await page.locator('[data-bs-target="#buyModal"]').first().click();
		await page.waitForTimeout(600);
		const modal = await page.evaluate(() => {
			const dialog = document.querySelector('#buyModal .modal-dialog');
			if (!dialog) { return null; }
			const box = dialog.getBoundingClientRect();
			const close = document.querySelector('#buyModal .btn-close');
			const closeBox = close ? close.getBoundingClientRect() : null;
			return {
				right: Math.round(box.right),
				width: Math.round(box.width),
				viewport: document.documentElement.clientWidth,
				closeOnScreen: closeBox ? closeBox.right <= document.documentElement.clientWidth : false,
			};
		});
		check('a modal fits the screen', modal !== null && modal.right <= modal.viewport + 1,
		      JSON.stringify(modal));
		check('and its close button is reachable', modal !== null && modal.closeOnScreen,
		      JSON.stringify(modal));

		check(`${scheme}: no page threw a script error`, scriptErrors.length === 0,
		      scriptErrors.join(' | '));

		await context.close();
		}

		/* The things that fold away are <details>, so they open with
		   JavaScript off. A Bootstrap collapse does not. */
		const bare = await browser.newContext({
			viewport: { width: 1280, height: 900 },
			javaScriptEnabled: false,
		});
		const barePage = await bare.newPage();
		await barePage.goto(`${BASE}/login.php`, { waitUntil: 'domcontentloaded' });
		await barePage.fill('input[name=username]', 'tester');
		await barePage.fill('input[name=password]', 'testpass');
		await barePage.click('form[action="login.php"] button[type=submit]');
		await barePage.waitForLoadState('domcontentloaded');

		/* Nothing on any page is a Bootstrap collapse any more. That is the
		   thing that does not work without JavaScript, and it is easier to
		   be sure of by asking every page than by finding one example of
		   each. */
		const collapses = [];
		for (const [, url] of PAGES) {
			await barePage.goto(`${BASE}/${url}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
			const n = await barePage.evaluate(() =>
				document.querySelectorAll('[data-bs-toggle="collapse"]:not(.navbar-toggler)').length);
			if (n > 0) { collapses.push(`${url}:${n}`); }
		}
		check('with JavaScript off nothing is waiting on a collapse script',
		      collapses.length === 0, collapses.join(' '));

		/* And the thing that replaced them opens. On the broker page: the
		   strategies page gives its explanation a view of its own in the
		   menu now, which is a link rather than a fold. */
		await barePage.goto(`${BASE}/broker.php`, { waitUntil: 'domcontentloaded' });
		const how = await barePage.locator('details.sm-reveal > summary').first();
		check('"How this works" is a details', await how.count() > 0);
		if (await how.count() > 0) {
			await how.click();
			check('and it opens with no script on the page',
			      await barePage.evaluate(() =>
			          document.querySelector('details.sm-reveal').open) === true);
		}

		await barePage.goto(`${BASE}/strategies.php?s=_how`, { waitUntil: 'domcontentloaded' });
		check('and the strategies page\'s explanation is a page of its own',
		      (await barePage.locator('text=How this works').count()) > 0
		      && (await barePage.locator('text=No real money is ever at risk').count()) > 0);

		/* The strategies page at 390px: one control above the content, not
		   two columns side by side, and every view reachable from it. The
		   menu is a form with a select, so it works with no script at all -
		   which is what this context is. */
		const narrow = await browser.newContext({
			viewport: { width: 390, height: 844 },
			isMobile: true, hasTouch: true, javaScriptEnabled: false,
		});
		const narrowPage = await narrow.newPage();
		await narrowPage.goto(`${BASE}/login.php`, { waitUntil: 'domcontentloaded' });
		await narrowPage.fill('input[name=username]', 'tester');
		await narrowPage.fill('input[name=password]', 'testpass');
		await narrowPage.click('form[action="login.php"] button[type=submit]');
		await narrowPage.waitForLoadState('domcontentloaded');
		await narrowPage.goto(`${BASE}/strategies.php`, { waitUntil: 'domcontentloaded' });

		const menuVisible = await narrowPage.locator('#sm-menu-select').isVisible();
		const sideVisible = await narrowPage.locator('nav.sm-side-menu').isVisible()
			.catch(() => false);
		check('at 390px the menu is one control above the content',
		      menuVisible === true && sideVisible === false,
		      `select ${menuVisible}, side menu ${sideVisible}`);
		check('and it works without a script, through its own button',
		      await narrowPage.locator('form.sm-menu-phone button[type=submit]').count() > 0);

		/* The summary is one block per strategy, each cell carrying the
		   heading it lost: eleven columns at 390px is a table scrolled
		   sideways with its headings off the screen. */
		const stacked = await narrowPage.evaluate(() => {
			const cell = document.querySelector('table.sm-stack tbody td[data-label]');
			if (!cell) { return null; }
			return { display: getComputedStyle(cell).display,
			         label: getComputedStyle(cell, '::before').content };
		});
		check('and the summary table stacks, with every cell labelled',
		      stacked !== null && stacked.display === 'flex'
		      && /strategy|mode|value/i.test(stacked.label || ''),
		      JSON.stringify(stacked));

		/* And each view answers at that width. */
		for (const where of ['', '?s=_backtests', '?s=_how']) {
			await narrowPage.goto(`${BASE}/strategies.php${where}`, { waitUntil: 'domcontentloaded' });
			const measured = await narrowPage.evaluate(() => ({
				over: document.documentElement.scrollWidth - document.documentElement.clientWidth,
				fatal: /Fatal error|Parse error/.test(document.body.innerText),
			}));
			check(`the ${where === '' ? 'overview' : where} view fits a phone`,
			      !measured.fatal && measured.over <= 1,
			      `${measured.over}px over`);
		}
		await narrow.close();

		/* And on a laptop. The brief asked for a wide page with a 240px
		   menu beside it; what was deployed sat in the old centred
		   container, about 870px of a 1372px window, so the menu was
		   150px and truncated "Trend following (Trading 212 pr" with a
		   scrollbar under itself while the summary and backtest tables
		   were cut off on the right. Nothing at 1,280px or above should
		   need to be scrolled sideways to be read. */
		const laptop = await browser.newContext({
			viewport: { width: 1280, height: 900 },
			deviceScaleFactor: 1,
		});
		const laptopPage = await laptop.newPage();

		/* Nothing a page loads may come from anywhere but this site. The
		   typeface is the reason this is checked: a stylesheet that asks
		   fonts.googleapis.com for Inter tells Google which pages a
		   private site serves, and the page sits in a fallback face until
		   the request comes back. */
		const offSite = new Set();
		laptopPage.on('request', request => {
			const url = request.url();
			if (!url.startsWith(BASE) && !url.startsWith('data:') && !url.startsWith('blob:')) {
				offSite.add(url.split('?')[0]);
			}
		});

		await laptopPage.goto(`${BASE}/login.php`, { waitUntil: 'domcontentloaded' });
		await laptopPage.fill('input[name=username]', 'tester');
		await laptopPage.fill('input[name=password]', 'testpass');
		await laptopPage.click('form[action="login.php"] button[type=submit]');
		await laptopPage.waitForLoadState('domcontentloaded');

		const laptopViews = [['the overview', ''],
		                     ['a strategy', '?s=trend_following'],
		                     ['the backtests', '?s=_backtests']];

		for (const [what, where] of laptopViews) {

			await laptopPage.goto(`${BASE}/strategies.php${where}`,
			                      { waitUntil: 'networkidle' }).catch(() => {});

			const wide = await laptopPage.evaluate(() => {
				const doc = document.documentElement;
				const menu = document.querySelector('nav.sm-side-menu');
				/* A table inside .table-responsive is allowed to scroll in
				   its own box on a phone; at 1,280px it should not have to.
				   Two pixels of slack for sub-pixel rounding. */
				const scrollers = [];
				document.querySelectorAll('.sm-view-col .table-responsive').forEach(box => {
					const over = box.scrollWidth - box.clientWidth;
					if (over > 2) {
						scrollers.push((box.querySelector('table')
						                ? box.querySelector('table').className : 'table')
						               + ' +' + over + 'px');
					}
				});
				/* And no menu entry is cut off: the name wraps onto a
				   second line instead. */
				const clipped = [];
				document.querySelectorAll('.sm-menu-name').forEach(name => {
					const style = getComputedStyle(name);
					if (name.scrollWidth - name.clientWidth > 1
					    || style.textOverflow === 'ellipsis') {
						clipped.push(name.textContent.trim().slice(0, 40));
					}
				});
				return {
					page: doc.scrollWidth - doc.clientWidth,
					menuWidth: menu ? Math.round(menu.getBoundingClientRect().width) : null,
					menuScrolls: menu ? (menu.scrollWidth - menu.clientWidth > 1
					                     || menu.scrollHeight - menu.clientHeight > 1) : null,
					menuOverflow: menu ? getComputedStyle(menu).overflowY : null,
					scrollers, clipped,
					fatal: /Fatal error|Parse error/.test(document.body.innerText),
				};
			});

			check(`${what} renders at 1,280px`, !wide.fatal);
			check(`${what} does not scroll the page sideways at 1,280px`,
			      wide.page <= 1, `${wide.page}px over`);
			check(`and no table in ${what} has to be scrolled sideways at 1,280px`,
			      wide.scrollers.length === 0, wide.scrollers.join(', '));
			check(`the menu beside ${what} is 240px wide, not a tenth of the window`,
			      wide.menuWidth !== null && Math.abs(wide.menuWidth - 240) <= 1,
			      String(wide.menuWidth));
			check(`and draws no scrollbar of its own beside ${what}`,
			      wide.menuScrolls === false && wide.menuOverflow === 'visible',
			      `${wide.menuScrolls} / ${wide.menuOverflow}`);
			check(`and no strategy's name is cut off beside ${what}`,
			      wide.clipped.length === 0, wide.clipped.join(' | '));
		}

		/* Status is the second column, because it is the thing to look at
		   first and the eleventh column is off the right-hand edge of a
		   laptop. */
		await laptopPage.goto(`${BASE}/strategies.php`, { waitUntil: 'networkidle' });
		const headings = await laptopPage.evaluate(() =>
			Array.from(document.querySelectorAll('table.sm-stack thead th'))
			     .map(th => th.textContent.trim()));
		check('Status is the second column of the summary, next to the name',
		      headings[0] === 'Strategy' && headings[1] === 'Status',
		      headings.join(' | '));
		const statusOnScreen = await laptopPage.evaluate(() => {
			const cell = document.querySelector('table.sm-stack tbody td[data-label="Status"]');
			if (!cell) { return null; }
			return cell.getBoundingClientRect().right
			       <= document.documentElement.clientWidth;
		});
		check('and it is on the screen without scrolling', statusOnScreen === true,
		      String(statusOnScreen));

		/* Every page the laptop context visited, which is the login page,
		   the three strategies views and the quote page below. */
		await laptopPage.goto(`${BASE}/quote.php?symbol=AAA`, { waitUntil: 'networkidle' });
		await laptopPage.waitForTimeout(500);
		check('no page asks anybody but this site for anything',
		      offSite.size === 0, Array.from(offSite).join(' | '));

		/* And the typeface is one of the things it serves. */
		const fontUsed = await laptopPage.evaluate(async () => {
			const loaded = [];
			document.fonts.forEach(face => loaded.push(face.family));
			return { families: Array.from(new Set(loaded)),
			         body: getComputedStyle(document.body).fontFamily };
		});
		check('the pages are set in the typeface the house style asks for',
		      /Inter/.test(fontUsed.body) && fontUsed.families.indexOf('Inter') !== -1,
		      JSON.stringify(fontUsed));

		await laptop.close();

		/* The outlook is the other one. It only draws its fold when there is
		   an outlook to fold, so the panel is asked for its markup rather
		   than for an open triangle. */
		await barePage.goto(`${BASE}/index.php`, { waitUntil: 'domcontentloaded' });
		const panel = await barePage.evaluate(() => {
			const el = document.querySelector('.sm-outlook');
			if (!el) { return null; }
			return { collapses: el.querySelectorAll('[data-bs-toggle="collapse"]').length,
			         empty: /No outlook yet/.test(el.textContent),
			         details: el.querySelectorAll('details.sm-reveal').length };
		});
		check('the outlook panel has no collapse left in it',
		      panel !== null && panel.collapses === 0, JSON.stringify(panel));
		check('and folds into a details once there is one to fold',
		      panel !== null && (panel.empty || panel.details > 0), JSON.stringify(panel));

		await bare.close();
	}
	finally {
		if (browser) { await browser.close().catch(() => {}); }
		stop();
	}

	console.log();
	if (failed === 0) { console.log(`\x1b[32mALL PASSED\x1b[0m  ${passed} passed, 0 failed\n`); }
	else {
		console.log(`\x1b[31mFAILURES\x1b[0m  ${passed} passed, ${failed} failed`);
		console.log('  failed: ' + failures.join('\n          ') + '\n');
	}
	process.exit(failed === 0 ? 0 : 1);
})();
