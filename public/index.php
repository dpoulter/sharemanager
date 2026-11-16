<?php

    /**
     * The dashboard.
     *
     * Reads: the portfolio strip, the market breadth, the week's
     * announcements, the strategies and the four ranked lists. Writes
     * nothing and calls no model - the outlook on the page is whatever was
     * last generated, and generating a new one is a button on it.
     *
     * A symbol arriving here (the old search form posted to this page)
     * is handed to quote.php rather than looked up here: quote.php builds
     * the thirty variables its template needs and this page built six, so
     * every panel it did not know about rendered undefined.
     */

    require("../includes/config.php");
    require("../includes/home_functions.php");
    require_once("../includes/rns.php");
    require_once("../includes/ai_outlook.php");
    require_once("../includes/strategy_functions.php");

    $symbol = null;
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        if (empty($_POST["symbol"])) { apologize("You must enter a symbol."); }
        $symbol = $_POST["symbol"];
    }
    else if (isset($_GET["symbol"])) { $symbol = $_GET["symbol"]; }

    if ($symbol !== null) {
        write_log("index", "symbol=$symbol");
        $query = ["symbol" => $symbol];
        if (isset($_GET["screen_id"])) { $query["screen_id"] = $_GET["screen_id"]; }
        redirect("quote.php?" . http_build_query($query));
    }

    $exchange = session_exchange();

    /* The date every figure on the page is read at: the last close that was
       loaded. Not today - on a Sunday there is no close, and the page would
       report an empty market. */
    $as_of = last_trading_date($exchange);
    if ($as_of === null) { $as_of = date('Y-m-d'); }

    /* The breadth figures, from the table the nightly job fills. They used
       to be worked out here on every page load, pivoting the whole universe
       twice - 2.6 seconds of a 3.2 second page, for figures that change once
       a night. */
    $breadth = market_breadth($as_of, $exchange);

    /* Seven trading days back, counted off the stored closes. */
    $news_from = home_news_window($as_of, 7, $exchange);

    $ranked = [];
    foreach (array_keys(home_scores()) as $score) {
        $ranked[$score] = home_ranked($score, 10, $exchange);
    }

    render("home.php", [
        "title"            => "Dashboard",
        "as_of"            => $as_of,
        "portfolio"        => home_portfolio_strip($_SESSION["id"], $exchange),
        "breadth"          => $breadth,
        "breadth_history"  => home_breadth_history($as_of, 6, $exchange),
        "news"             => home_news_week($news_from, $as_of, 5, $exchange),
        "news_from"        => $news_from,
        "strategies"       => home_strategy_rows($exchange),
        "scores"           => home_scores(),
        "ranked"           => $ranked,
    ]);

?>
