<?php

    /**
     * Create a strategy from a screen.
     *
     *   GET  strategy_create.php?screen=<id>   the form
     *   POST strategy_create.php               screen=<id> name=... holdings=... and the rest
     *
     * The form is the Create strategy button on the screens page. What a POST
     * makes is rules and NO account: nothing can trade until the strategy is
     * backtested and started on practice, from its own page. includes/
     * strategy_create.php checks every field on the server; this file reads
     * the form and says what happened.
     *
     * config.php applies the same authentication gate as every other page.
     */

    require("../includes/config.php");
    require_once("../includes/strategy_functions.php");
    require_once("../includes/strategy_create.php");

    $field = function ($name) {
        return (isset($_POST[$name]) && is_string($_POST[$name])) ? trim($_POST[$name]) : '';
    };

    $message  = null;
    $screen_id = (int) ($_SERVER["REQUEST_METHOD"] === 'POST' ? $field('screen') : ($_GET['screen'] ?? 0));

    if ($_SERVER["REQUEST_METHOD"] === 'POST') {

        $result = strategy_create_from_screen([
            'user_id'       => (int) ($_SESSION['id'] ?? 0),
            'screen_id'     => $screen_id,
            'name'          => $field('name'),
            'holdings'      => $field('holdings'),
            'rank_by'       => $field('rank_by'),
            'hold_rank'     => $field('hold_rank'),
            'max_turnover'  => $field('max_turnover'),
            'rebalance'     => $field('rebalance'),
            'exit_rules'    => (isset($_POST['exit_rules']) && is_array($_POST['exit_rules']))
                             ? array_filter($_POST['exit_rules'], 'is_string') : [],
            'exclude_funds' => $field('exclude_funds') === '' ? 'N' : $field('exclude_funds'),
        ]);

        if ($result['ok']) {
            $_SESSION['sm_live_message'] = ['ok' => true, 'strategy' => $result['strategy'],
                'text' => "Strategy created. It has rules and no account, so nothing can trade."
                        . " Backtest it, then start it on practice."];
            redirect("strategies.php?s=" . urlencode($result['strategy']));
        }
        $message = $result['why'];
    }

    $screens = $screen_id > 0 ? query("select id, name, description from screen where id=?", $screen_id) : [];
    if (!count($screens)) {
        http_response_code(404);
        apologize("There is no such screen.");
    }

    render("strategy_create.php", [
        "title"   => "Create strategy",
        "screen"  => $screens[0],
        "message" => $message,
        "ranks"   => strategy_create_rank_options(),
        "exits"   => strategy_create_exit_options(),
        "posted"  => $_SERVER["REQUEST_METHOD"] === 'POST' ? $_POST : [],
    ]);

?>
