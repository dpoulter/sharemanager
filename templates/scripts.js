/**
 * scripts.js
 *
 Global JavaScript, if any.
 */
$( document ).ready(function() {
         var tbl = $("#tbl");
         $("#btnAddrow").click(function(){
             $("<tr><td><input type=text id='symbolInput' class='typeahead' name='symbol[]'</input></td><td><input type=text name='shares[]'</input></td><td><input name='price_paid[]'
</input></td><td><input type=text name='commission[]'</input></td></tr>");
    });
});

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


var substringMatcher = function(strs) {
  return function findMatches(q, cb) {
    var matches, substringRegex;
 
    // an array that will be populated with substring matches
    matches = [];
 
    // regex used to determine if a string contains the substring `q`
    substrRegex = new RegExp(q, 'i');
 
    // iterate through the pool of strings and for any string that
    // contains the substring `q`, add it to the `matches` array
    $.each(strs, function(i, str) {
      if (substrRegex.test(str)) {
        matches.push(str);
      }
    });
 
    cb(matches);
  };
};


var symbols=['AAA','BBB','CCC'];

$('#symbolInput .typeahead').typeahead({
  hint: true,
  highlight: true,
  minLength: 1
},
{
  name: 'symbols',
  source: substringMatcher(symbols)
});

