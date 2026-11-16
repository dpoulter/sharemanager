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

		const context = await browser.newContext({
			viewport: { width: 390, height: 844 },
			deviceScaleFactor: 1,
			isMobile: true,
			hasTouch: true,
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

			check(`${what} renders at 390px`, !measured.fatal);
			/* One pixel of slack: a border on a full width element rounds up
			   on some zoom levels and that is not a layout fault. */
			check(`${what} does not scroll the page sideways`,
			      measured.overflow <= 1,
			      `${measured.overflow}px over; widest is ${measured.widest} (+${measured.widestBy}px)`);
		}

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

		check('no page threw a script error', scriptErrors.length === 0,
		      scriptErrors.join(' | '));

		await context.close();
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
