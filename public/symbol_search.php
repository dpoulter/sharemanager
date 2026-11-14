<?php

    /**
     * Symbol suggestions for the navbar search box, as JSON.
     *
     * [{"symbol":"III","name":"3i Group plc"}, ...], at most ten.
     *
     * config.php applies the same authentication gate as every other page
     * here: this script is not on its allow list, so an anonymous request is
     * redirected to login.php and never reaches the query. That is deliberate
     * - the share universe is behind the login like everything else, and
     * there is no second copy of the gate here to drift out of step with it.
     */

    require("../includes/config.php");

    /* is_string matters: ?term[]=x makes $_GET["term"] an array, and casting
       one to string raises "Array to string conversion", which would be
       written into the body and leave the client parsing a warning as JSON. */
    $term = (isset($_GET["term"]) && is_string($_GET["term"])) ? $_GET["term"] : "";

    $matches = search_symbols($term, 10);

    $out = array();
    foreach ($matches as $match) {
        $out[] = array("symbol" => $match["symbol"], "name" => (string) $match["name"]);
    }

    header("Content-Type: application/json");
    //The browser is told not to guess at the type. A symbol or company name
    //is attacker-influenced text as far as this endpoint knows.
    header("X-Content-Type-Options: nosniff");
    print(json_encode($out));

    /* No closing tag: a newline after one is written to the front of the
       response, which would make this invalid JSON. See includes/constants.php. */
