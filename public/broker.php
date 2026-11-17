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

        $result = broker_sync(['exchange' => $exchange,
                               'instruments' => isset($_POST["instruments"])]);

        $_SESSION["broker_sync"] = ['status'  => $result['status'],
                                    'message' => $result['message'],
                                    'problems' => $result['problems'],
                                    'ran'     => $result['ran']];
        redirect("broker.php");
    }

    if (isset($_SESSION["broker_sync"])) {
        $synced = $_SESSION["broker_sync"];
        unset($_SESSION["broker_sync"]);
    }

    $status = broker_status();

    /* Everything below reads tables. With the connection off, or before the
       first sync, they are empty and the page says what to do about it
       rather than showing nothing. */
    render("broker.php", [
        "title"      => "Broker",
        "status"     => $status,
        "synced"     => $synced,
        "values"     => broker_latest_values(),
        "positions"  => broker_latest_positions(),
        "orders"     => broker_recent_orders(BROKER_T212, 20),
        "dividends"  => broker_recent_dividends(BROKER_T212, 20),
        "unmatched"  => broker_ready() ? broker_unmatched_symbols(BROKER_T212, $exchange) : [],
        "ambiguous"  => broker_ready() ? broker_ambiguous_instruments() : [],
    ]);

?>
