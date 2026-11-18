/**
 * scripts.js
 *
 Global JavaScript, if any.
 */
/* The #btnAddrow handler that used to be here is gone.
 *
 * It held an unterminated string literal - a "<tr>...<input name='price_paid[]'"
 * that ran off the end of the line and continued on the next - so this whole
 * file was a SyntaxError and the browser executed none of it. That is the
 * reason the navbar suggestions never appeared and, since it was added later,
 * the reason the tooltip setup at the bottom never ran either.
 *
 * It was dead code even so: no template contains a #btnAddrow button (only
 * archive/templates/testheader.php, which nothing renders), and the row it built was
 * never appended to anything - $("<tr>...") with no .appendTo() makes a
 * detached element and drops it.
 */

/* A CSS colour from the page's own tokens, resolved to something a chart
 * library can parse.
 *
 * app.css writes every token with light-dark(), so the page follows the
 * machine with one value instead of two blocks. getPropertyValue() hands
 * back what is written - "light-dark(#333333, #c7cbd1)" - and TradingView
 * Lightweight Charts throws "Cannot parse color" on it. The throw happened
 * inside createChart, so every chart in the application drew nothing at
 * all from the day the tokens went modern: the Strategies page showed its
 * heading, its two toggles and an empty box with no message.
 *
 * The browser knows what the token means, so it is asked: the var is set as
 * the colour of a hidden element and the computed colour read back, which
 * is a plain rgb() whatever function produced it. A token the stylesheet
 * does not define, or a value the browser leaves unresolved, gives the
 * caller's fallback rather than something the chart cannot read.
 */
function smChartColour(name, fallback) {

    var raw = '';
    try { raw = (getComputedStyle(document.documentElement).getPropertyValue(name) || '').trim(); }
    catch (e) { raw = ''; }

    if (raw === '') { return fallback; }

    var value = '';
    try {
        var probe = document.createElement('span');
        probe.style.display = 'none';
        probe.style.color = 'var(' + name + ')';
        (document.body || document.documentElement).appendChild(probe);
        value = (getComputedStyle(probe).color || '').trim();
        probe.parentNode.removeChild(probe);
    }
    catch (e) { value = ''; }

    if (value.indexOf('rgb') === 0) { return value; }

    /* Unresolved. A plain colour can go as it is; a function this browser
       understood and this did not cannot. */
    return raw.indexOf('(') === -1 ? raw : fallback;
}

function killMe(el){
    return el.parentNode.removeChild(el);
}
function getParentByTagName(el,tag){
    tag=tag.toLowerCase();
    while(el&&el.nodeName.toLowerCase()!=tag){
        el=el.parentNode;
    }
    return el||null;
}
function delRow(el){
//alert("Delete");
    killMe(getParentByTagName(el,'tr'));
//alert("Delete row");
//$(this).closest("tr").remove();
}


/* The navbar share search box.
 *
 * Two things stopped this from ever producing a suggestion.
 *
 * The selector was '#symbolInput .typeahead' - a descendant selector, so it
 * looked for a .typeahead *inside* an element with id symbolInput. The navbar
 * input (templates/header.php) has class typeahead and no such ancestor, so
 * the selector matched nothing.
 *
 * And it ran at parse time. header.php loads this file in <head>, so the
 * navbar did not exist yet; even with the right selector there was nothing to
 * bind to. It is inside a ready handler now.
 *
 * The source was also a hard-coded ['AAA','BBB','CCC'] fed through a
 * substring matcher over that array. It is a remote lookup against
 * symbol_search.php, which searches stock_symbols.
 */
$(document).ready(function () {

    //If the vendored assets did not land, bind nothing rather than throwing
    //and taking the rest of this file (the tooltip setup below) with it.
    if (typeof Bloodhound === 'undefined' || !$.fn.typeahead) { return; }

    var symbols = new Bloodhound({
        datumTokenizer: function (datum) {
            return Bloodhound.tokenizers.whitespace(datum.symbol + ' ' + datum.name);
        },
        queryTokenizer: Bloodhound.tokenizers.whitespace,
        remote: {
            url: 'symbol_search.php?term=%QUERY',
            wildcard: '%QUERY'
        }
    });

    $('input.typeahead').typeahead({
        hint: true,
        highlight: true,
        minLength: 1
    }, {
        name: 'symbols',
        limit: 10,
        source: symbols,
        //What goes in the box when a suggestion is picked. quote.php accepts
        //this "SYMBOL Name" form, and the select handler below narrows it to
        //the symbol anyway, so neither depends on the other.
        display: function (datum) { return datum.symbol + ' ' + datum.name; },
        templates: {
            //Built with .text() rather than string concatenation: a company
            //name is data from the database and does not belong in markup.
            suggestion: function (datum) {
                return $('<div></div>')
                    .append($('<strong></strong>').text(datum.symbol))
                    .append(document.createTextNode(' '))
                    .append($('<span class="text-muted"></span>').text(datum.name || ''));
            },
            notFound: '<div class="tt-suggestion text-muted px-2">No matching share</div>'
        }
    });

    /* Picking a suggestion has to run the lookup, not just fill the box.
     *
     * The submit is deliberately not $(form).trigger('submit'). That depends
     * on jQuery choosing to call the native method afterwards, and it does
     * nothing at all when a control in the form is named "submit", because
     * form.submit is then that element rather than the method. Calling
     * through the prototype cannot be shadowed and does not care which
     * jQuery is loaded.
     *
     * requestSubmit() is preferred because it fires the submit event and
     * honours validation the way a real button press does; submit() is the
     * fallback for anything older.
     */
    function submitSearch(input, symbol) {
        var $input = $(input);

        //The bare symbol, so the server has nothing to parse. quote.php
        //accepts the "SYMBOL Name" form too, so this is tidiness rather than
        //something the lookup depends on.
        if (symbol) { $input.typeahead('val', symbol); }

        var form = $input.closest('form')[0] || document.getElementById('search');
        if (!form) { return; }

        //One submission per selection. The Enter handler below and the select
        //event can both fire for the same keypress.
        if (form.getAttribute('data-sm-submitting') === '1') { return; }
        form.setAttribute('data-sm-submitting', '1');

        var submit = HTMLFormElement.prototype.requestSubmit
                  || HTMLFormElement.prototype.submit;
        submit.call(form);
    }

    /* typeahead:select is 0.11; typeahead:selected is what 0.10 emits and 0.11
     * still emits alongside it. Binding both means this works whichever
     * version is vendored, and the autocomplete pair covers accepting the
     * inline hint. The duplicate on 0.11 is absorbed by the guard above.
     */
    $('input.typeahead').on(
        'typeahead:select typeahead:selected typeahead:autocomplete typeahead:autocompleted',
        function (ev, datum) {
            submitSearch(this, datum && datum.symbol);
        });

    /* Enter with a suggestion highlighted. typeahead calls preventDefault()
     * on that keypress so it can select the suggestion itself, which stops
     * the browser submitting the form - the box fills in and nothing happens.
     *
     * The timeout lets typeahead's own handler run first: when it selects a
     * suggestion the handler above submits with the symbol, and this one then
     * finds the guard set and does nothing. With no suggestion highlighted
     * nothing cancels the keypress, the browser submits, and this is a
     * harmless second attempt on a page that is already leaving.
     */
    $('input.typeahead').on('keydown', function (ev) {
        if (ev.which !== 13 && ev.key !== 'Enter') { return; }
        var input = this;
        window.setTimeout(function () { submitSearch(input, null); }, 0);
    });
});

/* The AI outlook panel.
 *
 * The panel is drawn server side from the last saved outlook. This only runs
 * when Generate is pressed: one POST to ai_outlook.php, a spinner while it
 * waits, and the answer rendered in place.
 *
 * Everything from the response is put on the page with textContent. The model
 * writes that text, and what the model writes is shaped by news headlines
 * written by strangers, so none of it is ever handed to innerHTML.
 */
$(document).ready(function () {

    function el(tag, text, className) {
        var node = document.createElement(tag);
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        if (className) { node.className = className; }
        return node;
    }

    function bulletList(points) {
        var ul = document.createElement('ul');
        points.forEach(function (point) { ul.appendChild(el('li', point)); });
        return ul;
    }

    function labelled(label, text) {
        var p = el('p', null, 'mb-1');
        p.appendChild(el('strong', label));
        p.appendChild(document.createTextNode(' ' + String(text)));
        return p;
    }

    function badgeClass(stance) {
        if (stance === 'positive') { return 'badge text-bg-success'; }
        if (stance === 'negative') { return 'badge text-bg-danger'; }
        return 'badge text-bg-secondary';
    }

    function render(body, data) {
        body.textContent = '';

        var outlook = data.outlook || {};

        var stanceLine = el('p', null, 'mb-2');
        var stance = String(outlook.stance || '');
        stanceLine.appendChild(el('span', stance.charAt(0).toUpperCase() + stance.slice(1),
                                  badgeClass(stance)));
        body.appendChild(stanceLine);

        body.appendChild(el('p', outlook.summary || ''));

        if ((outlook.positives || []).length) {
            body.appendChild(labelled('For', ''));
            body.appendChild(bulletList(outlook.positives));
        }
        if ((outlook.risks || []).length) {
            body.appendChild(labelled('Against', ''));
            body.appendChild(bulletList(outlook.risks));
        }
        if (outlook.action) { body.appendChild(labelled('Action', outlook.action)); }
        if (outlook.watch)  { body.appendChild(labelled('Watch',  outlook.watch)); }

        var notes = (data.notes_used || []).length
            ? 'Notes used: ' + data.notes_used.join(', ') + '.'
            : 'No vault notes were available.';
        var cost = typeof data.cost === 'number' ? data.cost.toFixed(4) : '0.0000';

        body.appendChild(el('p',
            notes + ' Data as of ' + (data.as_of_date || 'unknown') + '. '
            + (data.model || '') + ', generated ' + (data.created_at || '') + ', '
            + 'cost $' + cost + '. Not financial advice.',
            'text-muted small mb-0 sm-outlook-meta'));
    }

    $('.sm-outlook').each(function () {
        var panel   = $(this);
        var button  = panel.find('.sm-outlook-generate');
        var spinner = panel.find('.sm-outlook-spinner');
        var error   = panel.find('.sm-outlook-error');
        var body    = panel.find('.sm-outlook-body');

        button.on('click', function () {
            button.prop('disabled', true);
            spinner.removeClass('d-none');
            error.addClass('d-none').text('');

            var params = new URLSearchParams();
            if (panel.data('scope') === 'market') { params.set('scope', 'market'); }
            else { params.set('symbol', panel.data('symbol')); }

            fetch('ai_outlook.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString(),
                credentials: 'same-origin'
            }).then(function (response) {
                //A session that expired mid-request redirects to the login
                //page, so the body is HTML and json() would throw something
                //unhelpful.
                return response.text().then(function (text) {
                    try { return JSON.parse(text); }
                    catch (e) {
                        return { ok: false, error: 'The server did not return an outlook. '
                                                 + 'You may have been logged out - reload and try again.' };
                    }
                });
            }).then(function (data) {
                if (data && data.ok) { render(body[0], data); }
                else { error.removeClass('d-none').text((data && data.error) || 'The outlook could not be generated.'); }
            }).catch(function (e) {
                error.removeClass('d-none').text('Could not reach the server: ' + e.message);
            }).then(function () {
                spinner.addClass('d-none');
                button.prop('disabled', false);
            });
        });
    });
});

/* Bootstrap tooltips are opt-in: an element with data-bs-toggle="tooltip" does
 * nothing until it is initialised. The quote page marks up tooltips on the
 * valuation badges and nothing ever initialised them, so under Bootstrap 4 they
 * silently did not work either. Same for popovers.
 */
document.addEventListener('DOMContentLoaded', function () {
    if (typeof bootstrap === 'undefined') { return; }
    document.querySelectorAll('[data-bs-toggle="tooltip"]')
            .forEach(function (el) { new bootstrap.Tooltip(el); });
    document.querySelectorAll('[data-bs-toggle="popover"]')
            .forEach(function (el) { new bootstrap.Popover(el); });
});

/* Sortable tables.
 *
 * Any table with class sm-table-sort: a click on a header cell carrying
 * data-sort ("number" or "text") sorts the body by that column, and a second
 * click reverses it. The sort key is the cell's data-value attribute when it
 * has one, so a column showing "1,350.6p" or a coloured badge sorts on the
 * number behind it rather than on the string.
 *
 * In the browser rather than by reloading with an order in the query string:
 * these are tables of a few hundred rows that somebody flicks between
 * columns on, and a round trip per click buys nothing.
 *
 * Rows with no value sort to the bottom whichever way the column is pointing.
 * A share with no six month figure is not the worst performer, it is a share
 * with no six month figure, and putting it at the top of the losers would be
 * the page telling the reader something untrue.
 */
document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll('table.sm-table-sort').forEach(function (table) {

        var headers = table.querySelectorAll('thead th[data-sort]');

        headers.forEach(function (header, column) {

            header.setAttribute('role', 'button');
            header.setAttribute('tabindex', '0');

            function sort() {

                var body = table.tBodies[0];
                if (!body) { return; }

                var kind = header.getAttribute('data-sort');
                var descending = header.classList.contains('sm-sorted-asc');

                headers.forEach(function (other) {
                    other.classList.remove('sm-sorted-asc', 'sm-sorted-desc');
                });
                header.classList.add(descending ? 'sm-sorted-desc' : 'sm-sorted-asc');
                header.setAttribute('aria-sort', descending ? 'descending' : 'ascending');

                function key(row) {
                    var cell = row.cells[column];
                    if (!cell) { return null; }
                    var raw = cell.hasAttribute('data-value')
                        ? cell.getAttribute('data-value')
                        : cell.textContent;
                    raw = String(raw === null ? '' : raw).trim();
                    if (raw === '' || raw === '–') { return null; }
                    if (kind !== 'number') { return raw.toLowerCase(); }
                    //Strip anything that is not part of a number: p, %, commas,
                    //the pound sign. A leading minus and a decimal point stay.
                    var number = parseFloat(raw.replace(/[^0-9.\-]/g, ''));
                    return isNaN(number) ? null : number;
                }

                var rows = Array.prototype.slice.call(body.rows);
                rows.sort(function (a, b) {
                    var ka = key(a), kb = key(b);
                    if (ka === null && kb === null) { return 0; }
                    if (ka === null) { return 1; }
                    if (kb === null) { return -1; }
                    if (ka < kb) { return descending ? 1 : -1; }
                    if (ka > kb) { return descending ? -1 : 1; }
                    return 0;
                });

                //Rows that are not data - an empty-state message spanning the
                //table, a totals row - stay where they are.
                rows.forEach(function (row) {
                    if (row.classList.contains('sm-total-row')) { return; }
                    body.appendChild(row);
                });
                rows.forEach(function (row) {
                    if (row.classList.contains('sm-total-row')) { body.appendChild(row); }
                });
            }

            header.addEventListener('click', sort);
            header.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); sort(); }
            });
        });
    });
});

/* The strategies page's phone menu, and its tabs.
 *
 * The menu is a form with a select and a Go button, so it works with
 * JavaScript off. With JavaScript on, choosing submits it and the button is
 * not needed, so it goes away.
 *
 * The tabs are real links - strategies.php?s=x&tab=orders renders that tab
 * server side - carrying Bootstrap's data-bs-toggle as well, so a click
 * switches without a reload. The address is then put right by hand, because
 * Bootstrap switched the pane without navigating and a URL that says
 * "holdings" while the page shows orders cannot be bookmarked or shared.
 */
document.addEventListener('DOMContentLoaded', function () {

    var form = document.querySelector('form.sm-menu-phone');
    if (form) {
        var select = form.querySelector('select');
        var go = form.querySelector('.sm-menu-go');
        if (select) {
            select.addEventListener('change', function () { form.submit(); });
            if (go) { go.classList.add('d-none'); }
        }
    }

    if (!window.history || !window.history.replaceState) { return; }

    document.querySelectorAll('.nav-tabs a[data-bs-toggle="tab"][href]')
        .forEach(function (link) {
            link.addEventListener('shown.bs.tab', function () {
                try { window.history.replaceState(null, '', link.getAttribute('href')); }
                catch (e) { /* a browser that will not have it keeps the old address */ }
            });
        });
});
