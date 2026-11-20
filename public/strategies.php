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
     * NO ORDER GOES TO A LIVE ACCOUNT FROM HERE. What this page does reach
     * is the form that arms one (public/strategy_live.php) and the form
     * that approves a night's batch, which is the point: arming is a
     * decision a person makes in their own logged in session, with the
     * password typed again and the strategy's name typed in full, and this
     * is the only place in the application where it can be made. The
     * sending itself is a cron job that this page cannot reach
     * (includes/send_live_orders.php). The dry run endpoint computes a plan
     * and writes nothing at all, and the kill switch stops even that.
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
    /* Arming, approval and the live caps, for the live account's panel.
       broker_orders.php requires this too; named here because this page is
       what draws it. */
    require_once("../includes/broker_live.php");
    /* The stage of each strategy, derived: Draft, Backtested, Practice or
       Live. New files, so nothing of it is added to strategy_functions.php. */
    require_once("../includes/strategy_stage.php");
    /* Backtests asked for from this page: queued here, replayed by a job. */
    require_once("../includes/backtest_runs.php");
    /* Starting on practice and stepping down: both POSTs handled below. */
    require_once("../includes/practice_start.php");
    require_once("../includes/stage_stepdown.php");
    /* What a retired practice strategy left at the broker, and the two steps
       that sell it. */
    require_once("../includes/practice_clear.php");

    /* CLEAR A HALT. The one POST this page takes: a halted strategy's form,
       with the password and the strategy's name typed again. It is handled
       the way arming is - the function decides, this says what happened and
       sends the reader back - and it is a web POST and nothing else, so
       there is no job or command that calls it. Clearing does not arm. */
    if (strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? 'GET')) === 'POST'
        && is_string($_POST['do'] ?? null) && $_POST['do'] === 'clear_halt') {

        $clear_strategy = is_string($_POST['strategy'] ?? null) ? trim($_POST['strategy']) : '';

        $result = broker_clear_halt($clear_strategy, [
            'user_id'    => (int) ($_SESSION['id'] ?? 0),
            'password'   => is_string($_POST['password'] ?? null) ? $_POST['password'] : '',
            'typed_name' => is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '',
        ]);

        $_SESSION['sm_live_message'] = ['ok' => (bool) $result['ok'],
            'text' => $result['ok']
                    ? "The halt is cleared. The strategy is not armed: arming is its own form."
                    : "The halt was not cleared: " . $result['why'],
            'strategy' => $clear_strategy];
        redirect("strategies.php?s=" . urlencode($clear_strategy));
    }

    /* CLEAR THE PRACTICE ACCOUNT (two requests). The function decides: nothing
       is sent in the request that plans, and the sells go only through the
       practice sender. */
    if (strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? 'GET')) === 'POST'
        && ($_POST['do'] ?? null) === 'practice_clear') {

        $result = practice_clear([
            'user_id' => (int) ($_SESSION['id'] ?? 0),
            'step'    => is_string($_POST['step'] ?? null) ? (int) $_POST['step'] : 0,
        ]);

        $_SESSION['sm_live_message'] = ['ok' => (bool) $result['ok'], 'strategy' => '',
            'text' => $result['ok'] ? $result['words'] : "The practice account was not cleared: " . $result['why']];
        redirect("strategies.php");
    }

    /* ASK FOR A BACKTEST. A POST that only queues a run: the move has to be
       open in strategy_stage() on the server, and nothing is replayed in
       this request. */
    if (strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? 'GET')) === 'POST'
        && is_string($_POST['do'] ?? null) && $_POST['do'] === 'backtest') {

        $asked = is_string($_POST['strategy'] ?? null) ? trim($_POST['strategy']) : '';

        $result = backtest_request([
            'user_id'    => (int) ($_SESSION['id'] ?? 0),
            'strategy'   => $asked,
            'from'       => is_string($_POST['from'] ?? null) ? trim($_POST['from']) : '',
            'to'         => is_string($_POST['to'] ?? null) ? trim($_POST['to']) : '',
            'cash'       => is_string($_POST['cash'] ?? null) ? trim($_POST['cash']) : '',
            'cost_model' => is_string($_POST['cost_model'] ?? null) ? trim($_POST['cost_model']) : '',
        ]);

        $_SESSION['sm_live_message'] = ['ok' => (bool) $result['ok'], 'strategy' => $asked,
            'text' => $result['ok']
                    ? "Backtest queued as run " . $result['run_id'] . ". The Mac mini research engine runs it, one at a time."
                    : "The backtest was not queued: " . $result['why']];
        $left = strategy_account($asked);
        redirect("strategies.php?s=" . urlencode($asked) . "&tab=backtest"
                 . (($left !== null && $left['enabled'] !== 'Y') ? "&disabled=1" : ""));
    }

    /* START ON PRACTICE (two requests) AND STEP DOWN. Each is a POST that the
       function decides: the move has to be open in strategy_stage() on the
       server, so a crafted POST for a closed one fails there. None of them
       arms, sends, sells, cancels or clears a halt. */
    if (strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? 'GET')) === 'POST'
        && in_array($_POST['do'] ?? null, ['practice_start', 'stepdown'], true)) {

        $moved = is_string($_POST['strategy'] ?? null) ? trim($_POST['strategy']) : '';

        if ($_POST['do'] === 'practice_start') {
            $result = practice_start([
                'user_id'  => (int) ($_SESSION['id'] ?? 0),
                'strategy' => $moved,
                'step'     => is_string($_POST['step'] ?? null) ? (int) $_POST['step'] : 0,
                'capital'  => is_string($_POST['capital'] ?? null) ? trim($_POST['capital']) : '',
            ]);
            if (!$result['ok']) { $words = "Not started on practice: " . $result['why']; }
            else if ($result['step'] === 1) {
                $words = "The practice account is made and switched OFF. Nothing was sent or recorded."
                       . " Tonight's plan would be: " . implode('; ', array_slice($result['plan'], 0, 20))
                       . ". Press Switch on to start it.";
            }
            else { $words = "Switched on. The practice account trades from the next run."; }
        }
        else {
            $result = stage_stepdown([
                'user_id'  => (int) ($_SESSION['id'] ?? 0),
                'strategy' => $moved,
                'step'     => is_string($_POST['step'] ?? null) ? trim($_POST['step']) : '',
            ]);
            $words = $result['ok'] ? $result['words'] : "Not stepped down: " . $result['why'];
        }

        $_SESSION['sm_live_message'] = ['ok' => (bool) $result['ok'], 'strategy' => $moved, 'text' => $words];
        /* A strategy whose account is switched off is listed behind the
           disabled toggle, and this is the page its Switch on button is on. */
        $left = strategy_account($moved);
        redirect("strategies.php?s=" . urlencode($moved)
                 . (($left !== null && $left['enabled'] !== 'Y') ? "&disabled=1" : ""));
    }

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
            "wide"       => true,
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
            "live_waiting" => [],
            "live_message" => null,
            "stages"     => [],
            "drafts"     => [],
            "draft"      => null,
            "pa_state"   => null,
            "pa_plan"    => null,
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

    /* Every strategy with rules, by stage - including the ones with no
       account, which are in none of the lists above and so used to be
       nowhere on this page. */
    $stages = strategy_stage_list();
    $drafts = [];
    foreach ($stages as $name => $info) {
        if (!$info['has_account'] && !in_array($name, $strategies, true)) { $drafts[] = $info; }
    }
    $draft = null;

    /* The practice account, once per page: who holds it, what is left on it
       and whether clearing it is open. */
    $pa_state = practice_account_state();
    $pa_plan  = practice_clear_plan();

    if (isset($VIEWS[$want])) { $view = $want; }
    else if ($want !== '' && in_array($want, $strategies, true)) {
        $view   = '_strategy';
        $chosen = $want;
    }
    else if ($want !== '' && isset($stages[$want]) && !$stages[$want]['has_account']) {
        /* Rules and no account: its own view, with the stepper and no
           trading anywhere on it. */
        $view  = '_draft';
        $draft = $stages[$want];
    }

    $TABS = ['holdings' => 'Holdings', 'orders' => 'Orders', 'trades' => 'Trades',
             'backtest' => 'Backtest', 'rules' => 'Rules'];

    $tab = strtolower(trim((string) ($_GET['tab'] ?? 'holdings')));
    if (!isset($TABS[$tab])) { $tab = 'holdings'; }

    /* The menu and the summary table are the same rows: one strategy's
       return and status cannot say one thing in the list and another in the
       table. Built once, for every view, because the menu is on all of
       them. */
    /* One order for the menu and for the table: paper, then practice, then
       live, each by name, with a halted strategy at the top of its group.
       They were sorted by two different things, so the third entry in the
       menu was the first row in the table. */
    $summary = strategy_order(strategy_summary($strategies, $as_of, $exchange));

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
        /* Arming, the batch waiting for an approval, and the caps - null
           for every account that is not a live one, so the paper and
           practice cards draw none of it. */
        $card['live'] = broker_live_state($chosen);
    }

    render("strategies.php", [
        "title"     => "Strategies",
        "wide"      => true,
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
        /* Every live account with a batch nobody has decided about, for the
           line at the top of the page: an exit waiting for an approval is
           costing money while it waits. */
        "pa_state"  => $pa_state,
        "pa_plan"   => $pa_plan,
        "stages"    => $stages,
        "drafts"    => $drafts,
        "draft"     => $draft,
        "live_waiting" => broker_live_waiting(),
        /* What the last arm, disarm, approval or refusal said. Read once:
           a message that survived a reload would be a message about
           something that happened a reload ago. */
        "live_message" => (function () {
            if (!isset($_SESSION['sm_live_message'])) { return null; }
            $message = $_SESSION['sm_live_message'];
            unset($_SESSION['sm_live_message']);
            return $message;
        })(),
    ]);

?>
