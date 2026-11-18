<!DOCTYPE html>

<html>

    <head>

        <link href="/css/bootstrap.min.css" rel="stylesheet"/>
        <link href="/css/bootstrap-theme.min.css" rel="stylesheet"/>
        <link href="/css/styles1.css" rel="stylesheet"/>

        <?php if (isset($title)): ?>
            <title>Share Portfolio Manager <?= htmlspecialchars($title) ?></title>
        <?php else: ?>
            <title>Share Portfolio Manager</title>
        <?php endif ?>

        



    
        <script src="/js/jquery-1.10.2.min.js"></script>
        <script src="/js/bootstrap.min.js"></script>
		 <script src="/js/bloodhound.min.js"></script>
               <!-- https://github.com/twitter/typeahead.js/ -->

        <script src="/js/typeahead.jquery.js"></script>
        <script src="/js/scripts.js:2"></script>
        <script src="/js/scripts2.js:3"></script>
        <!--Hogan -->
        <script src="http://twitter.github.com/hogan.js/builds/3.0.1/hogan-3.0.1.js"></script>
     <script type="text/javascript">   
 /**
 * scripts.js
 *
 Global JavaScript, if any.
 */
 



$(document).ready(function() {
   

 var symbols = new Bloodhound({
  datumTokenizer: Bloodhound.tokenizers.whitespace,
  queryTokenizer: Bloodhound.tokenizers.whitespace,
  // url points to a json file that contains an array of country names, see
  // https://github.com/twitter/typeahead.js/blob/gh-pages/data/countries.json
  prefetch: 'symbols10.php'
});

var tags =[
		  { "tag": "HTML5", "name": "HTML5 LocalStorage API", "description": "HTML5 LocalStorage API,Client Side Storage" },
        { "tag": "HTML5", "name": "HTML5 GeoLocations API", "description": "HTML5 GeoLocations API,Used to Find Location" },
        { "tag": "JavaScript", "name": "JavaScript Tips And Tricks", "description": "Some Useful Javascript tips and tricks" },
        { "tag": "JavaScript", "name": "JavaScript Tutorials", "description": "JavaScript Tutorials" },
        { "tag": "CSS3", "name": "CSS3 Animations", "description": "CSS3 Animations" },
        { "tag": "CSS3", "name": "CSS3 Tutorial", "description": "CSS3 Tutorial" }
];


$('#search1 .typeahead').typeahead(
{
  name: 'symbols',
  valueKey: 'name',
  source: symbols,
  template: [
    '<div> {{name}} - {{name}} </div>' ].join(''),
  engine: Hogan
});


$('.glyphicon-edit').click(function(states){
  alert('Are you sure');
});	

$('.WordpressPwosts').typeahead({
    name: 'Wordpress',
    valueKey: 'name'local: [
{
    "tag": "HTML5", "name": "HTML5 LocalStorage API", "description": "HTML5 LocalStorage API,Client Side Storage", "value": "HTML5",
    "tokens": ['HTML5']
},
        {
            "tag": "HTML5", "name": "HTML5 GeoLocations API", "description": "HTML5 GeoLocations API,Used to Find Location", "value": "HTML5",
            "tokens": ['HTML5']
        },
        {
            "tag": "JavaScript", "name": "JavaScript Tips And Tricks", "description": "Some Useful Javascript tips and tricks", "value": "JavaScript",
            "tokens": ['JavaScript']
        },
        {
            "tag": "JavaScript", "name": "JavaScript Tutorials", "description": "JavaScript Tutorials", "value": "JavaScript",
            "tokens": ['JavaScript']
        },
        {
            "tag": "CSS3", "name": "CSS3 Animations", "description": "CSS3 Animations", "value": "CSS3",
            "tokens": ['CSS3']
        },
        {
            "tag": "CSS3", "name": "CSS3 Tutorial", "description": "CSS3 Tutorial", "value": "CSS3",
            "tokens": ['CSS3']
        }
                ],
                template: [
    '<p {{tag}}></p>',
    '<p {{name}}></p>',
    '<p {{description}}></p>'
                ].join(''),
                engine: Hogan
            });
            
            
         $('input.counties').typeahead({
      name: 'countries',
      local: ["Unites States", "Mexico", "Canada", "Cuba", "Guatemala"]
});

 
});
      
});










</script>
   </head>     
   
   <body>

        <div class="container">

            <div id="top">
             <h1>Share Portfolio Manager</h1>
            </div>
            <div id="middle" class="navigation">
              <ul class="nav nav-pills">
                 <li role="presentation" class="active"><a href="index.php">Home</a></li>
                <li class="dropdown"><a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Portfolio<span class="caret"></span></a>
			<ul class="dropdown-menu" role="menu">
				<li><a href="performance.php">Overview</a></li>
				<li><a href="edit.php">Transactions</a></li>
				<li><a href="dividends.php">Dividends</a></li>
				<li><a href="topup.php">Deposit Cash</a></li>
			</ul>
		</li>
		<li class="dropdown"><a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Screens<span class="caret"></span></a>
          		<ul class="dropdown-menu" role="menu">
				<li><a href="screen_list.php">List Screens</a></li>
				</ul>
        </li>          		
		<li role="presentation"><a href="strategies.php">Strategies</a></li>
		<li role="presentation"><a href="backtest.php">Back Testing</a></li>
		<li role="presentation"><a href="logout.php">Log Out</a></li>
             </ul>
            </div>
            </p>

<form role="form" action="quote.php" method="post">
<div id="search">
	 <div class="form-group col-md-4" id="search1">
				<input  class="form-control typeahead"  type="text" name="symbol" placeholder="Enter Symbol"/>
		</div>
		<div class="form-group">
				<button type="submit" class="btn btn-default">Lookup</button>
        </div>
<div>
        <div class="CustomTemplate">
                <h4>Uses Custom Template For rendering Suggesstions</h4>
                <input class="WordpressPosts typeahead" type="text" placeholder="My Wordpress Posts" />
            </div>
            
            <input class="countries" type="text" placeholder="Countries">
            
  
	
</form>
