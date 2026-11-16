<?php

    /**
     * The paper strategies: what each one holds, what it has done, and how
     * it compares with the market.
     *
     *   strategies.php            the cards, holdings and trades
     *   strategy_dry_run.php      what tonight's run would do, in a popup
     *
     * PAPER ONLY. Nothing reachable from this page can place a real order.
     * The dry run endpoint calls strategy_run_night($strategy, $as_of,
     * true), which computes a plan and writes nothing at all - no targets,
     * no orders, no cash movement - and the kill switch stops even that.
     *
     * config.php applies the same authentication gate as every other page
     * here. There is no second copy of it to drift out of step with the
     * first.
     *
     * This replaces the 2023 "Backing Winners" page, which drove its own
     * model out of strategy_shares and shared nothing with the paper
     * execution engine. It is kept in archive/public/strategies.php.
     */

    require("../includes/config.php");
    require_once("../includes/strategy_functions.php");

    $exchange = session_exchange();

    /* The as of date every figure on the page is read at: the last day
       prices were loaded. Not today - on a Sunday there is no close, and a
       page that asks for one gets an empty book and says the strategy sold
       everything. */
    $as_of = last_trading_date($exchange);
    if ($as_of === null) { $as_of = date('Y-m-d'); }

    if (!strategy_rules_ready()) {
        render("strategies.php", [
            "title"      => "Strategies",
            "as_of"      => $as_of,
            "ready"      => false,
            "cards"      => [],
            "benchmark"  => [],
            "halted"     => strategy_halted(),
            "switch_file" => STRATEGY_KILL_SWITCH_FILE,
            "switch_readable" => strategy_kill_switch_readable(),
        ]);
        exit;
    }

    $strategies = strategy_strategies();

    /* A disabled strategy still has a history worth looking at, so the page
       shows every account with rules rather than only the ones that will run
       tonight. The card says which is which. */
    foreach (query("select a.strategy from strategy_accounts a, strategy_rules r
                     where r.strategy=a.strategy and a.enabled<>'Y' order by a.strategy") as $row) {
        if (!strategy_reserved($row['strategy'])) { $strategies[] = $row['strategy']; }
    }

    /* The dry run lives at strategy_dry_run.php now and opens in a popup
       over this page. It used to be a POST back to here that printed the
       engine's own lines in a <pre> block at the top - hard to read, and
       sitting away from the strategy it belonged to. There is deliberately
       no second route: two ways to ask the same question drift apart. */

    $cards = [];
    foreach ($strategies as $strategy) {
        $card = strategy_card($strategy, $exchange);
        $card['holdings_rows'] = strategy_holdings($strategy, $as_of, $exchange);
        $card['trades']        = strategy_trades($strategy, 50);
        $card['costs']         = strategy_costs_paid($strategy);
        /* The backtests of the same rules, newest first, shown under the
           live card and labelled as backtests. Three at most: the useful
           comparison is between the last few runs, not all of them. */
        $card['backtests']     = strategy_backtests($strategy, 3);
        $cards[] = $card;
    }

    render("strategies.php", [
        "title"     => "Strategies",
        "as_of"     => $as_of,
        "ready"     => true,
        "cards"     => $cards,
        "benchmark" => strategy_series(STRATEGY_BENCHMARK),
        /* The switch, and whether PHP can see where it lives: "not halted"
           from a path open_basedir blocks is an assumption, and the page
           should not present an assumption as an answer. */
        "halted"    => strategy_halted(),
        "switch_file" => STRATEGY_KILL_SWITCH_FILE,
        "switch_readable" => strategy_kill_switch_readable(),
    ]);

?>
