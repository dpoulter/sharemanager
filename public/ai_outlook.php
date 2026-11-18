<?php

    /**
     * Generate one AI outlook and return it as JSON.
     *
     * POST symbol=<ticker>   an outlook for one share
     * POST scope=market      an outlook for the index
     *
     * The only thing that calls this is the Generate button. Loading a page
     * never does, so an idle site costs nothing.
     *
     * config.php applies the same authentication gate as every other page
     * here: this script is not on its allow list, so an anonymous request is
     * redirected to login.php and never reaches the API. There is no second
     * copy of the rule here to drift out of step with it.
     */

    require("../includes/config.php");
    require_once("../includes/ai_outlook.php");

    /* Always off here, whatever SM_DISPLAY_ERRORS says. A warning or a fatal
       printed into the body would leave the panel parsing PHP's HTML as
       JSON and showing nothing at all; the shutdown handler below turns a
       fatal into a readable message instead. Errors still go to the log. */
    ini_set("display_errors", "0");
    ini_set("log_errors", "1");

    header("Content-Type: application/json");
    header("X-Content-Type-Options: nosniff");

    function ai_outlook_reply($status, $payload) {
        http_response_code($status);
        print(json_encode($payload));
        exit;
    }

    //POST only. A generation costs money and writes a row, so it is not
    //something a link, a prefetch or a crawler should be able to trigger.
    if (($_SERVER["REQUEST_METHOD"] ?? '') !== "POST") {
        header("Allow: POST");
        ai_outlook_reply(405, ["ok" => false, "error" => "Use POST to generate an outlook."]);
    }

    $scope  = (isset($_POST["scope"]) && is_string($_POST["scope"])) ? trim($_POST["scope"]) : '';
    $symbol = (isset($_POST["symbol"]) && is_string($_POST["symbol"])) ? trim($_POST["symbol"]) : '';

    if ($scope !== 'market' && $symbol === '') {
        ai_outlook_reply(400, ["ok" => false, "error" => "Give a symbol, or scope=market."]);
    }
    $scope = $scope === 'market' ? 'market' : 'share';

    /* The request takes as long as the model takes. PHP holds the session
       file locked for the whole of a request, so without this the owner's
       other tabs would sit and wait for a generation to finish before they
       could render. $_SESSION stays readable afterwards; it just stops being
       written back, and nothing below writes to it. */
    session_write_close();

    /* Any fatal below would otherwise reach the browser as HTML in a response
       the panel is trying to parse as JSON, and the panel would show nothing
       at all. A shutdown handler turns that into the same error shape as
       everything else. */
    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error === null) { return; }
        if (!in_array($error["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) { return; }
        //The message can carry a file path, so it is logged rather than sent.
        error_log("ai_outlook: fatal: " . $error["message"]);
        if (!headers_sent()) { http_response_code(500); }
        print(json_encode(["ok" => false, "error" => "The outlook could not be generated."]));
    });

    $result = outlook_generate($scope, $scope === 'market' ? null : $symbol);

    //Always 200 for a handled failure: the panel reads ok and error, and a
    //non-2xx would have fetch() callers guessing whether there is a body.
    ai_outlook_reply(200, $result);
