<?php

    /**
     * One score, every share that has it, ranked.
     *
     *   rankings.php?score=momentum
     *
     * The dashboard shows ten of each. This is the rest: the question "who
     * else is near the top" had no page to answer it, so the only way to see
     * the eleventh was to run a screen.
     *
     * The score is one of four names, matched against the list rather than
     * used as a column: anything else is refused rather than looked up.
     */

    require("../includes/config.php");
    require("../includes/home_functions.php");

    $scores = home_scores();

    $score = (isset($_GET["score"]) && is_string($_GET["score"])) ? $_GET["score"] : 'overall';
    if (!isset($scores[$score])) { $score = 'overall'; }

    $exchange = session_exchange();

    render("rankings.php", [
        "title"   => $scores[$score]['title'] . " ranking",
        "scores"  => $scores,
        "score"   => $score,
        "as_of"   => home_ranked_asof($score, $exchange),
        "rows"    => home_ranked($score, null, $exchange),
    ]);

?>
