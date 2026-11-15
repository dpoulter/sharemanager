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
 * templates/testheader.php, which nothing renders), and the row it built was
 * never appended to anything - $("<tr>...") with no .appendTo() makes a
 * detached element and drops it.
 */

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
