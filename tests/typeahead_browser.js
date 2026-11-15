/**
 * The navbar search box, driven in a real browser.
 *
 * Everything else about it is asserted by reading source: that scripts.js
 * parses, that the selector is not the one that matched nothing, that the
 * binding happens after the document is ready. None of that proves a
 * suggestion appears or that picking one runs the lookup, and the two bugs
 * this file exists for were both invisible to source reading - a file that
 * looked right and never ran, then a selection that filled the box without
 * submitting.
 *
 * Driven from tests/tests.php against the php -S server it already has up,
 * and skipped unless node, playwright and the vendored front-end assets are
 * all present - public/js is in .gitignore and filled by
 * tools/fetch_assets.sh, so a fresh clone has no jQuery to run.
 *
 *   node tests/typeahead_browser.js <base-url> <username> <password> <symbol> <name-fragment>
 *
 * Prints one "PASS <name>" or "FAIL <name>: <detail>" per line.
 */

const BASE = process.argv[2];
const USER = process.argv[3];
const PASS = process.argv[4];
const SYMBOL = process.argv[5] || 'RR';
const FRAGMENT = process.argv[6] || 'rol';

const BOX = 'form#search input[name="symbol"]';
let failed = 0;

function check(name, ok, detail) {
    if (ok) { console.log('PASS ' + name); }
    else { failed++; console.log('FAIL ' + name + ': ' + (detail || '')); }
}

(async () => {
    const { chromium } = require('playwright');
    const browser = await chromium.launch();

    async function session() {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', e => errors.push(String(e)));
        await page.goto(BASE + '/login.php', { waitUntil: 'domcontentloaded' });
        await page.fill('input[name="username"]', USER);
        await page.fill('input[name="password"]', PASS);
        //The navbar's Lookup button comes first in the DOM, so the login
        //button has to be named by its own form.
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.click('form[action="login.php"] button[type="submit"]'),
        ]);
        await page.waitForSelector(BOX);
        await page.click(BOX);
        return { page, errors };
    }

    try {
        // ---- the suggestions appear at all ----
        {
            const { page, errors } = await session();
            check('typeahead binds to the navbar search box',
                  await page.$('.twitter-typeahead') !== null,
                  'no .twitter-typeahead wrapper; page errors: ' + JSON.stringify(errors));

            await page.type(BOX, FRAGMENT, { delay: 60 });
            let suggestions = [];
            try {
                await page.waitForSelector('.tt-menu .tt-suggestion', { timeout: 10000 });
                suggestions = await page.$$eval('.tt-menu .tt-suggestion', els => els.map(e => e.textContent.trim()));
            } catch (e) { /* reported by the check below */ }

            check('a company name search suggests the share',
                  suggestions.some(t => t.indexOf(SYMBOL) === 0),
                  JSON.stringify(suggestions) + ' page errors: ' + JSON.stringify(errors));

            /* typeahead.js ships no stylesheet, so without templates/typeahead.css
               the list is there but invisible: no background, no layer. */
            const menu = await page.$('.tt-menu');
            if (menu) {
                const seen = await menu.evaluate(el => {
                    const s = getComputedStyle(el), r = el.getBoundingClientRect();
                    return { bg: s.backgroundColor, w: Math.round(r.width), h: Math.round(r.height) };
                });
                check('the suggestion list is styled and has a size',
                      seen.w > 50 && seen.h > 10
                      && seen.bg !== 'rgba(0, 0, 0, 0)' && seen.bg !== 'transparent',
                      JSON.stringify(seen));
            }
            else { check('the suggestion list is styled and has a size', false, 'no .tt-menu'); }

            check('no script error on the page', errors.length === 0, JSON.stringify(errors));
            await page.close();
        }

        // ---- every way of choosing one has to run the lookup ----
        const ways = [
            ['clicking a suggestion runs the lookup', async page => {
                await page.type(BOX, FRAGMENT, { delay: 60 });
                await page.waitForSelector('.tt-menu .tt-suggestion', { timeout: 10000 });
                await page.click('.tt-menu .tt-suggestion');
            }, 'XLON:' + SYMBOL],
            ['Enter on a highlighted suggestion runs the lookup', async page => {
                await page.type(BOX, FRAGMENT, { delay: 60 });
                await page.waitForSelector('.tt-menu .tt-suggestion', { timeout: 10000 });
                await page.press(BOX, 'ArrowDown');
                await page.waitForTimeout(150);
                await page.press(BOX, 'Enter');
            }, 'XLON:' + SYMBOL],
            ['Enter with the menu open but nothing highlighted runs the lookup', async page => {
                await page.type(BOX, FRAGMENT, { delay: 60 });
                await page.waitForSelector('.tt-menu .tt-suggestion', { timeout: 10000 });
                await page.press(BOX, 'Enter');
            }, 'XLON:' + SYMBOL],
            ['Enter on a bare symbol runs the lookup', async page => {
                await page.type(BOX, 'III', { delay: 50 });
                await page.waitForTimeout(400);
                await page.press(BOX, 'Enter');
            }, 'XLON:III'],
            ['the Lookup button still works', async page => {
                await page.type(BOX, 'III', { delay: 50 });
                await page.waitForTimeout(300);
                await page.click('form#search button[type="submit"]');
            }, 'XLON:III'],
        ];

        for (const [name, act, wantTitle] of ways) {
            const { page, errors } = await session();
            const before = page.url();
            try { await act(page); } catch (e) { /* the assertion reports it */ }
            await page.waitForTimeout(2500);
            const title = await page.title().catch(() => '');
            check(name, page.url() !== before && title.indexOf(wantTitle) !== -1,
                  'url ' + (page.url() === before ? 'unchanged' : 'changed')
                  + ', title ' + JSON.stringify(title)
                  + (errors.length ? ', errors ' + JSON.stringify(errors) : ''));
            await page.close();
        }

        /* The reason the submit goes through HTMLFormElement.prototype rather
           than jQuery: a control named "submit" shadows form.submit, and
           $(form).trigger('submit') then silently does nothing. */
        {
            const { page } = await session();
            const shadowed = await page.evaluate(() => {
                const f = document.getElementById('search');
                f.querySelector('button[type="submit"]').setAttribute('name', 'submit');
                return typeof f.submit;
            });
            check('a control named "submit" shadows form.submit', shadowed === 'object', 'typeof was ' + shadowed);

            /* Clicking a suggestion, not Enter: Enter with no menu open is
               submitted by the browser itself, so it would pass whatever this
               code does. A click is only ever submitted by the handler, so
               this is the one path that actually tests the mechanism. */
            const before = page.url();
            await page.type(BOX, FRAGMENT, { delay: 60 });
            try {
                await page.waitForSelector('.tt-menu .tt-suggestion', { timeout: 10000 });
                await page.click('.tt-menu .tt-suggestion');
            } catch (e) { /* the assertion reports it */ }
            await page.waitForTimeout(2500);
            const title = await page.title().catch(() => '');
            check('the lookup still submits when form.submit is shadowed',
                  page.url() !== before && title.indexOf('XLON:' + SYMBOL) !== -1,
                  'url ' + (page.url() === before ? 'unchanged' : 'changed') + ', title ' + JSON.stringify(title));
            await page.close();
        }
    }
    catch (e) {
        check('the browser run completed', false, String((e && e.message) || e));
    }
    finally {
        await browser.close();
    }

    process.exit(failed === 0 ? 0 : 1);
})();
