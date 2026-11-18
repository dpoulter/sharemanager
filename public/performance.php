<?php

    /**
     * The portfolio.
     *
     * Reads: the summary strip, the holdings with their scores and trend,
     * the closed positions, the dividends and the cash book. The
     * performance chart fetches its own series from portfolio_data.php, so
     * this page does not carry five years of nightly totals in its markup.
     *
     * The Timespan / Type / Go form this page used to carry is gone with the
     * image chart it drove.
     */

    require("../includes/config.php");
    require("../includes/home_functions.php");
    require("../includes/portfolio_view.php");
    require_once("../includes/strategy_functions.php");

    $exchange = session_exchange();

    render("portfolio.php", [
        "title"     => "Portfolio",
        "summary"   => portfolio_summary($_SESSION["id"], $exchange),
        "holdings"  => portfolio_holdings($_SESSION["id"], $exchange),
        "sold"      => portfolio_sold($_SESSION["id"]),
        "dividends" => portfolio_dividends($_SESSION["id"]),
        "cash"      => portfolio_cash($_SESSION["id"]),
    ]);

?>
