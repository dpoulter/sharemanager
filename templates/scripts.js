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
    }).on('typeahead:select', function (ev, datum) {
        //Submit the symbol on its own, so the server has nothing to parse.
        $(this).typeahead('val', datum.symbol);
        $(this).closest('form').trigger('submit');
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
