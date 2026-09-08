<?php

    /**
     * config.php
     *
     * Computer Science 50
     * Problem Set 7
     *
     * Configures pages.
     */

    // Show errors on screen only when asked to. On a host reachable from the
    // internet a warning carries the filesystem path and often a fragment of
    // the query that failed, so the default is to log them and show nothing.
    // Set SM_DISPLAY_ERRORS=1 in the server environment for a sandbox.
    ini_set("display_errors", getenv("SM_DISPLAY_ERRORS") === "1" ? "1" : "0");
    ini_set("log_errors", "1");
    error_reporting(E_ALL);

    // requirements
    require("constants.php");
    require("functions.php");
    require("share_functions.php");

    // Session cookies. httponly keeps the id away from any script that manages
    // to run on the page, samesite blunts cross site posts, and secure is set
    // whenever the request arrived over TLS.
    $sm_https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
             || (($_SERVER["SERVER_PORT"] ?? null) == 443)
             || (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https");
    session_set_cookie_params([
        "httponly" => true,
        "samesite" => "Lax",
        "secure"   => $sm_https,
    ]);

    // enable sessions
    session_start();

    /* The page being served, for the authentication gate below.
     *
     * This used to read $_SERVER["PHP_SELF"] and match the tail of it. PHP_SELF
     * is SCRIPT_NAME with PATH_INFO appended, so a request for
     * /index.php/login.php runs index.php with PHP_SELF set to
     * "/index.php/login.php" - which ends in login.php, so the gate let it
     * through unauthenticated. That is every server: php-fpm behind Caddy or
     * nginx, and the built in server too. SCRIPT_NAME is the script itself,
     * with no path info, so the tail cannot be forged from the URL.
     */
    $sm_script = basename($_SERVER["SCRIPT_NAME"] ?? ($_SERVER["SCRIPT_FILENAME"] ?? ""));

    // Nothing here takes path info, so a request carrying it is an attempt to
    // confuse something downstream about which script is running.
    if (!empty($_SERVER["PATH_INFO"])) {
        http_response_code(404);
        exit;
    }

    // require authentication for most pages
    if (!in_array($sm_script, ["login.php", "logout.php", "register.php", "reset_passwd.php"], true))
    {
        if (empty($_SESSION["id"]))
        {
            redirect("login.php");
        }
    }

?>
