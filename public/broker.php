<?php

    /**
     * The broker connection: what Trading 212 says the account holds.
     *
     * READ ONLY. Nothing reachable from this page can place, amend or cancel
     * an order. The Sync now button calls broker_sync(), which makes GET
     * requests and writes to the broker_* tables; the client it uses has no
     * method that could post anything.
     *
     * This page does not touch the manual portfolio. Transactions, Dividends
     * and Deposit Cash are still the portfolio the Portfolio pages show, and
     * the two are deliberately not merged: one is what Dale typed in, the
     * other is what the broker says, and the first thing anybody will want to
     * do with them is compare them.
     *
     * config.php applies the same authentication gate as every other page
     * here, so the account's positions are behind the login like everything
     * else.
     */

    require("../includes/config.php");
    require_once("../includes/broker.php");

    $exchange = session_exchange();

    /* Sync now. A POST, because it goes and does something, and then a
       redirect so that a refresh does not sync again. */
    $synced = null;
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["sync"])) {

        /* Every account that is switched on, the way the nightly job does
           it: a Sync now that read one of two would leave the page half
           refreshed and give no sign of which half. */
        $synced_all = [];
        foreach (t212_environments_to_read() as $environment) {
            $result = broker_sync(['exchange' => $exchange,
                                   'environment' => $environment,
                                   'instruments' => isset($_POST["instruments"])]);
            $synced_all[] = ['label'   => $result['label'],
                             'status'  => $result['status'],
                             'message' => $result['message'],
                             'problems' => $result['problems'],
                             'ran'     => $result['ran']];
        }
        $_SESSION["broker_sync"] = $synced_all;
        redirect("broker.php");
    }

    if (isset($_SESSION["broker_sync"])) {
        $synced = $_SESSION["broker_sync"];
        unset($_SESSION["broker_sync"]);
    }

    /* One block per account, because two are read now and a position in
       the practice account is not a position in the live one. An account
       that has never been read still gets a block, saying so: "no rows" and
       "switched off" are different states and the page has to tell them
       apart. */
    $accounts = [];
    foreach (['demo', 'live'] as $label) {

        $environment = ($label === 'demo') ? 'demo' : 'live-read';

        $accounts[] = [
            "label"      => $label,
            "words"      => t212_label_words($label),
            "on"         => t212_environment_on($environment),
            "status"     => broker_status(new T212Client(['env' => $environment])),
            "run"        => broker_ready() ? broker_last_run(BROKER_T212, $label) : null,
            "values"     => broker_latest_values(BROKER_T212, $label),
            "positions"  => broker_latest_positions(BROKER_T212, $label),
            "orders"     => broker_recent_orders(BROKER_T212, 20, $label),
            "dividends"  => broker_recent_dividends(BROKER_T212, 20, $label),
            "unmatched"  => broker_ready()
                          ? broker_unmatched_symbols(BROKER_T212, $exchange, $label) : [],
            "ambiguous"  => broker_ready() ? broker_ambiguous_instruments(BROKER_T212, $label) : [],
        ];
    }

    /* Everything below reads tables. With the connection off, or before the
       first sync, they are empty and the page says what to do about it
       rather than showing nothing. */
    render("broker.php", [
        "title"      => "Broker",
        "accounts"   => $accounts,
        "synced"     => $synced,
        "live_orders_on" => T212_LIVE_ORDERS_ON,
    ]);

?>
