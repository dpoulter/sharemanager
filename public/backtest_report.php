<?php

    /**
     * A Mac mini backtest run's report, for a logged in user. The file name is
     * the one the server chose when the result was posted; the page only asks
     * for a run id.
     */

    require("../includes/config.php");
    require_once("../includes/backtest_runs.php");

    $run = backtest_run_get((int) ($_GET['run'] ?? 0));
    $path = ($run !== null && $run['status'] === 'DONE') ? backtest_report_path($run) : null;
    if ($path === null) {
        http_response_code(404);
        echo "There is no report for that run.";
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
    header('X-Content-Type-Options: nosniff');
    readfile($path);
