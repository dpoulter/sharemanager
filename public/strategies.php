<?php

    /**
     * The strategies: what each one holds, what it has done, and how it
     * compares with the market.
     *
     *   strategies.php                      the overview: summary and chart
     *   strategies.php?s=<strategy>&tab=... one strategy, with its tabs
     *   strategies.php?s=_backtests         every backtest run, compared
     *   strategies.php?s=_how               what all of this means
     *   strategy_dry_run.php                what the next run would do
     *
     * A menu on the left and one view on the right, rather than every
     * strategy stacked down the page: with three strategies the third was
     * two screens below the first, and the thing somebody opens the page to
     * do - look at one of them - meant scrolling past the others.
     *
     * The view is in the address, so it can be bookmarked, the back button
     * works, and the dashboard can link straight at a strategy.
     *
     * PAPER AND PRACTICE ONLY. Nothing reachable from this page can place a
     * real order. The dry run endpoint computes a plan and writes nothing at
     * all, and the kill switch stops even that.
     *
     * config.php applies the same authentication gate as every other page
     * here. There is no second copy of it to drift out of step with the
     * first.
     */

    require("../includes/config.php");
    require_once("../includes/strategy_functions.php");
    /* For the agreement line on a practice card. The nightly job has no use
       for the broker layer, so strategy_functions.php does not require it;
       a page that wants to say whether the two books agree does. */
    require_once("../includes/broker_orders.php");

    $exchange = session_exchange();

    /* The as of date every figure on the page is read at: the last day
       prices were loaded. Not today - on a Sunday there is no close, and a
       page that asks for one gets an empty book and says the strategy sold
       everything. */
    $as_of = last_trading_date($exchange);
    if ($as_of === null) { $as_of = date('Y-m-d'); }

    /* The views that are not a strategy. Underscore first, which is what
       strategy_reserved() already means by a name the engine must not treat
       as a strategy, so one of these can never collide with one. */
    $VIEWS = ['_overview' => 'Overview', '_backtests' => 'Backtests',
              '_how' => 'How this works'];

    if (!strategy_rules_ready()) {
        render("strategies.php", [
            "title"      => "Strategies",
            "as_of"      => $as_of,
            "ready"      => false,
            "view"       => '_overview',
            "chosen"     => null,
            "tab"        => 'holdings',
            "menu"       => [],
            "summary"    => [],
            "pairs"      => [],
            "chart"      => [],
            "card"       => null,
            "backtests"  => [],
            "disabled_count" => 0,
            "show_disabled"  => false,
            "benchmark"  => [],
            "halted"     => strategy_halted(),
            "switch_file" => STRATEGY_KILL_SWITCH_FILE,
            "switch_readable" => strategy_kill_switch_readable(),
        ]);
        exit;
    }

    $strategies = strategy_strategies();

    /* A disabled strategy still has a history worth looking at, so the menu
       can show every account with rules rather than only the ones that will
       run tonight - but it is folded away behind a toggle rather than
       sitting among the live ones. A card that will never trade again looks
       exactly like one that trades tomorrow. */
    $disabled = [];
    foreach (query("select a.strategy from strategy_accounts a, strategy_rules r
                     where r.strategy=a.strategy and a.enabled<>'Y' order by a.strategy") as $row) {
        if (!strategy_reserved($row['strategy'])) { $disabled[] = $row['strategy']; }
    }

    $show_disabled = ($_GET['disabled'] ?? '') === '1';
    if ($show_disabled) { $strategies = array_merge($strategies, $disabled); }

    /* Which view. An unknown strategy, or a disabled one that is not being
       shown, falls back to the overview rather than erroring: a bookmark
       that has gone stale should land somewhere, not on a message. */
    $want   = trim((string) ($_GET['s'] ?? ''));
    $view   = '_overview';
    $chosen = null;

    if (isset($VIEWS[$want])) { $view = $want; }
    else if ($want !== '' && in_array($want, $strategies, true)) {
        $view   = '_strategy';
        $chosen = $want;
    }

    $TABS = ['holdings' => 'Holdings', 'orders' => 'Orders', 'trades' => 'Trades',
             'backtest' => 'Backtest', 'rules' => 'Rules'];

    $tab = strtolower(trim((string) ($_GET['tab'] ?? 'holdings')));
    if (!isset($TABS[$tab])) { $tab = 'holdings'; }

    /* The menu and the summary table are the same rows: one strategy's
       return and status cannot say one thing in the list and another in the
       table. Built once, for every view, because the menu is on all of
       them. */
    $summary = strategy_summary($strategies, $as_of, $exchange);

    $pairs = [];
    $chart = [];
    $card  = null;
    $backtests = [];

    if ($view === '_overview') {

        foreach (strategy_pairs($strategies) as $pair) {
            $pairs[] = strategy_pair_compare($pair[0], $pair[1], $as_of, $exchange);
        }

        /* The chart's lines, as stored totals. The rebasing is done in the
           browser, because the toggle between "each line at its own start"
           and "all of them at one date" is the same data read two ways. */
        foreach ($summary as $row) {
            $points = [];
            foreach (strategy_series($row['strategy']) as $point) {
                $points[] = ['time' => $point['date'], 'total' => (float) $point['total']];
            }
            if (count($points) > 1) {
                $chart[] = ['name' => strategy_display_name($row['strategy']),
                            'kind' => 'strategy', 'points' => $points];
            }
        }

        $benchmark_points = [];
        foreach (strategy_series(STRATEGY_BENCHMARK) as $point) {
            $benchmark_points[] = ['time' => $point['date'], 'total' => (float) $point['total']];
        }
        if (count($benchmark_points) > 1) {
            $chart[] = ['name' => 'FTSE 350 (equal weighted)',
                        'kind' => 'benchmark', 'points' => $benchmark_points];
        }

        /* And the backtests, dashed and off by default: a replay of five
           years next to three nights of trading would otherwise be the
           first thing a reader's eye went to. */
        foreach ($summary as $row) {
            foreach (strategy_backtest_rows($row['strategy'], 10) as $run) {
                if ($run['status'] !== 'DONE') { continue; }
                if (!$run['matches_live']) { continue; }
                $points = [];
                foreach (strategy_series((string) $run['account']) as $point) {
                    $points[] = ['time' => $point['date'], 'total' => (float) $point['total']];
                }
                if (count($points) > 1) {
                    $chart[] = ['name' => strategy_display_name($row['strategy']) . ' (backtest)',
                                'kind' => 'backtest', 'points' => $points];
                }
                break;
            }
        }
    }

    if ($view === '_backtests') { $backtests = strategy_backtest_rows(null, 100); }

    if ($view === '_strategy') {

        /* Only the chosen strategy's rows, which is the point of the menu:
           the page used to read every holding, order and trade of every
           strategy to draw one of them. */
        foreach ($summary as $row) {
            if ($row['strategy'] === $chosen) { $card = $row; }
        }

        $card['holdings_rows']  = strategy_holdings($chosen, $as_of, $exchange);
        $card['targets']        = strategy_target_weights($chosen);
        $card['exit_warnings']  = strategy_exit_warnings($chosen, $as_of);
        $card['pending']        = strategy_pending_orders($chosen);
        /* The outstanding orders' prices, in one query rather than one a
           row: the planned value of an order is its quantity at the last
           close, and fifteen of them were fifteen round trips. */
        $pending_symbols = [];
        foreach ($card['pending'] as $order) { $pending_symbols[] = (string) $order['symbol']; }
        $card['pending_prices'] = strategy_prices_for($pending_symbols, $exchange, $as_of);
        $card['trades']         = strategy_trades($chosen, 200);
        $card['costs']          = strategy_costs_paid($chosen);
        $card['backtests']      = strategy_backtest_rows($chosen, 50);
        $card['dealing']        = strategy_mode_dealing($card['mode']);
        /* Before the first fill there is nothing to value, and a row of
           dashes reads as a strategy that lost everything rather than one
           that has not started. The date it will start is the next
           rebalance from today. */
        $card['starts_on'] = ($card['start'] === null && $card['rules'] !== null)
                           ? strategy_next_rebalance($card['rules'], $as_of) : null;
    }

    render("strategies.php", [
        "title"     => "Strategies",
        "as_of"     => $as_of,
        "ready"     => true,
        "view"      => $view,
        "views"     => $VIEWS,
        "chosen"    => $chosen,
        "tab"       => $tab,
        "tabs"      => $TABS,
        "summary"   => $summary,
        "pairs"     => $pairs,
        "chart"     => $chart,
        "card"      => $card,
        "backtests" => $backtests,
        "disabled_count" => count($disabled),
        "show_disabled"  => $show_disabled,
        "benchmark" => strategy_series(STRATEGY_BENCHMARK),
        /* The switch, and whether PHP can see where it lives: "not halted"
           from a path open_basedir blocks is an assumption, and the page
           should not present an assumption as an answer. */
        "halted"    => strategy_halted(),
        "switch_file" => STRATEGY_KILL_SWITCH_FILE,
        "switch_readable" => strategy_kill_switch_readable(),
    ]);

?>
