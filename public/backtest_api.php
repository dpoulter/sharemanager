<?php

    /**
     * The door the Mac mini uses: claim the next backtest, post a result,
     * post a failure. See includes/backtest_api.php. No session and no login
     * page: the caller is checked by its Tailscale address and the token.
     */

    ini_set("display_errors", "0");
    ini_set("log_errors", "1");
    error_reporting(E_ALL);

    require("../includes/backtest_api.php");

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    [$status, $answer] = backtest_api_handle([
        'method'        => $_SERVER['REQUEST_METHOD'] ?? '',
        'remote_addr'   => $_SERVER['REMOTE_ADDR'] ?? '',
        'authorization' => $auth,
        'body'          => (string) file_get_contents('php://input', false, null, 0, 16 * 1024 * 1024),
    ]);

    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($answer, JSON_UNESCAPED_SLASHES);
