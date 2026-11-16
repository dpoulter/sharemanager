<?php

    /**
     * What the account has been worth, as JSON, for the chart on the
     * Portfolio page.
     *
     *   portfolio_data.php?days=365
     *
     * Two series: the account's own nightly total, and the equal weight
     * FTSE 350 the paper strategies are measured against, rebased to start
     * at the same figure so the two lines can be compared. An absolute
     * index level beside a portfolio total compares nothing.
     *
     * config.php applies the same authentication gate as every other page
     * here: this script is not on its allow list, so an anonymous request is
     * redirected to login.php. A portfolio is the most personal thing in
     * this application and there is no second copy of the gate here to drift
     * out of step with the first.
     *
     * The series is the session's own. The user id comes from the session
     * rather than from the query string, so there is no account number to
     * change.
     */

    require("../includes/config.php");
    require("../includes/home_functions.php");
    require_once("../includes/strategy_functions.php");
    require_once("../includes/portfolio_view.php");

    header("Content-Type: application/json");
    header("X-Content-Type-Options: nosniff");

    /* One of five spans, matched against the list. A number somebody can
       type is a number somebody can type 100000 into, and this reads every
       snapshot in the range. */
    $spans = [30, 91, 182, 365, 1825];
    $days  = isset($_GET["days"]) ? (int) $_GET["days"] : 365;
    if (!in_array($days, $spans, true)) { $days = 365; }

    $series = portfolio_series($_SESSION["id"], $days);

    $benchmark = [];
    if (count($series) >= 2) {
        $benchmark = portfolio_benchmark($series[0]['date'],
                                         $series[count($series) - 1]['date'],
                                         $series[0]['total']);
    }

    $point = function ($row) {
        return ["time" => $row['date'], "value" => round((float) $row['total'], 2)];
    };

    print(json_encode([
        "ok"        => true,
        "days"      => $days,
        "portfolio" => array_map($point, $series),
        "benchmark" => array_map($point, $benchmark),
        /* Said here rather than guessed at by the page: "no history" and
           "the benchmark has not been built" are different absences and the
           chart says a different thing for each. */
        "note"      => count($series) < 2
                     ? "The nightly valuation job has not recorded two days for this account yet."
                     : (count($benchmark) ? "" : "No benchmark has been built for this period."),
    ]));

?>
