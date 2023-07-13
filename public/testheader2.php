<!DOCTYPE html>

<html>

    <head>

     
<title></title>
    <script src="/js/jquery-1.10.2.min.js"></script>
    <script type="text/javascript" src="/js/typeahead.jquery.js"></script>
    <script src="/js/hogan.js"></script>
         
     <script type="text/javascript">
        $(document).ready(function () {


            //Single DataSet

            $('input.counties').typeahead({
                name: 'countries',
                local: ["Unites States", "Mexico", "Canada", "Cuba", "Guatemala"]

            });
            $('input.counties1').typeahead({
                name: 'countries1',
                local: ["Unites States", "Mexico", "Canada", "Cuba", "Guatemala"]

            });

            // Multiple DataSet

            $('input.countries-cities').typeahead([
                {
                    name: 'Canada',
                    local: ["Toronto", "Montreal", "Calgary", "Ottawa", "Edmonton", "Peterborough"],
                    header: '<h3>Canada</h3>'

                },
                {
                    name: 'United States',
                    local: ["New York", "Los Angeles", "Chicago", "Houston", "Philadelphia"],
                    header: '<h3>United States</h3>'

                }

            ]);

            //Custom Template

            $('.WordpressPosts').typeahead({
                name: 'Wordpress',
                valueKey: 'name',
                local: [
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
    '<p class="repo-tag">{{tag}}</p>',
    '<p class="repo-name">{{name}}</p>',
    '<p class="repo-description">{{description}}</p>'
                ].join(''),
                engine: Hogan
            });
        });
    </script>
</head>
<body>
    <div align="center">
        <div>
            <h3>
                A Simple AutoComplete Using jQuery
            </h3>
            <p>
                by Arunkumar Gudelli
            </p>
            <p><a href="http://www.arungudelli.com/2013/10/simple-jquery-autocomplete-search-tutorial" target="_blank">Go Back to Tutorial</a></p>
        </div>
        <br />
        <div style="height:800px">
            <div style="width: 1000px; height: 300px;">
                <div style="float: left; width: 500px">
                    <h4>Uses Single DataSet</h4>
                    <input class="counties typeahead" type="text" placeholder="Countries">
                </div>
                <div style="float: left; width: 500px">
                    <h4>Uses Multiple DataSets</h4>
                    <input class="countries-cities typeahead" type="text" placeholder="Countries And Cities">
                </div>
            </div>
            <div class="CustomTemplate">
                <h4>Uses Custom Template For rendering Suggesstions</h4>
                <input class="WordpressPosts typeahead" type="text" placeholder="My Wordpress Posts" />
            </div>
        </div>
    </div>
    
    </body>