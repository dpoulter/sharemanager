<?php

    /**
     * What tonight's run would do for one strategy, as JSON, for the popup
     * on the strategy page.
     *
     *   POST strategy_dry_run.php   strategy=trending_value
     *
     * WRITES NOTHING. strategy_dry_run() calls strategy_run_night() with its
     * dry run flag, which computes the selection, the targets and the plan
     * and stores none of them - no targets, no orders, no values, no cash
     * movement. There is no "run for real" here and there is not going to
     * be: the nightly job is the only thing that trades.
     *
     * config.php applies the same authentication gate as every other page
     * here - this script is not on its allow list, so an anonymous request
     * is redirected to login.php and never reaches a strategy. A paper book
     * is still a statement about what somebody is doing with their money.
     *
     * POST rather than GET because it is an action rather than a view, even
     * though it changes nothing: a GET that somebody can link to would be
     * fetched by every crawler and every link preview, and each fetch runs
     * a screen over the whole universe.
     */

    require("../includes/config.php");
    require_once("../includes/strategy_functions.php");

    header("Content-Type: application/json");
    header("X-Content-Type-Options: nosniff");

    function dry_run_fail($message, $status = 400) {
        http_response_code($status);
        print(json_encode(["ok" => false, "error" => $message]));
        exit;
    }

    if (($_SERVER["REQUEST_METHOD"] ?? 'GET') !== 'POST') {
        header("Allow: POST");
        dry_run_fail("Ask for this with POST.", 405);
    }

    if (!strategy_rules_ready()) {
        dry_run_fail("The strategy tables are not there yet; apply sql/strategy_rules.sql.", 503);
    }

    $exchange = session_exchange();

    /* Which strategies may be asked about: the ones that would run tonight,
       plus the ones with rules that are switched off - a disabled strategy
       still has a history worth inspecting. Anything else is refused rather
       than looked up, so the parameter cannot name a backtest account or a
       table. */
    $allowed = strategy_strategies();

    foreach (query("select a.strategy from strategy_accounts a, strategy_rules r
                     where r.strategy=a.strategy and a.enabled<>'Y' order by a.strategy") as $row) {
        if (!strategy_reserved($row['strategy'])) { $allowed[] = $row['strategy']; }
    }

    $strategy = (isset($_POST["strategy"]) && is_string($_POST["strategy"]))
              ? trim($_POST["strategy"]) : "";

    if ($strategy === "" || !in_array($strategy, $allowed, true)) {
        dry_run_fail("That is not a strategy on this account.", 404);
    }

    /* The date every figure is read at: the last day prices were loaded.
       Not today - on a Sunday there is no close, and a dry run against one
       would report selling everything. */
    $as_of = last_trading_date($exchange);

    if ($as_of === null) {
        dry_run_fail("No prices have been loaded, so there is nothing to plan against.", 503);
    }

    $report = strategy_dry_run($strategy, $as_of, $exchange);

    $report["ok"] = true;
    $report["footnote"] = "Prices are the " . $as_of . " close. Orders fill at the next trading"
                        . " day's close, so actual quantities and costs can differ slightly.";

    print(json_encode($report));

?>
