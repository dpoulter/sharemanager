<?php

    /**
     * The door the Mac mini publishes prices and statistics through: symbols,
     * batch, close, abort. See includes/data_import.php. Separate from
     * backtest_api.php. No session and no login page: the caller is checked by
     * its Tailscale address and the token.
     */

    ini_set("display_errors", "0");
    ini_set("log_errors", "1");
    error_reporting(E_ALL);

    /* A full publish's close replaces five years of rows in one transaction:
       let it finish even if the caller stops waiting. The Mac mini asks
       `symbols` afterwards to learn whether it landed. */
    set_time_limit(0);
    ignore_user_abort(true);

    require("../includes/data_import.php");

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    [$status, $answer] = data_import_handle([
        'method'        => $_SERVER['REQUEST_METHOD'] ?? '',
        'remote_addr'   => $_SERVER['REMOTE_ADDR'] ?? '',
        'authorization' => $auth,
        'body'          => (string) file_get_contents('php://input', false, null, 0, 64 * 1024 * 1024),
    ]);

    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($answer, JSON_UNESCAPED_SLASHES);
