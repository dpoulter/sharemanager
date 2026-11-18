<?php

    /**
     * Explain one price move and return it as JSON.
     *
     * POST symbol=<ticker> from=YYYY-MM-DD to=YYYY-MM-DD [regenerate=1]
     *
     * The only thing that calls this is the Explain button on the chart.
     * Loading a page never does, so an idle site costs nothing, and the same
     * range is answered from the cache until somebody asks for it again.
     *
     * config.php applies the same authentication gate as every other page
     * here: this script is not on its allow list, so an anonymous request is
     * redirected to login.php and never reaches the API. There is no second
     * copy of the rule here to drift out of step with it.
     */

    require("../includes/config.php");
    require_once("../includes/ai_explain.php");

    /* Always off here, whatever SM_DISPLAY_ERRORS says. A warning printed
       into the body would leave the panel parsing PHP's HTML as JSON and
       showing nothing at all. Errors still go to the log. */
    ini_set("display_errors", "0");
    ini_set("log_errors", "1");

    header("Content-Type: application/json");
    header("X-Content-Type-Options: nosniff");

    function ai_explain_reply($status, $payload) {
        http_response_code($status);
        print(json_encode($payload));
        exit;
    }

    //POST only. A generation costs money and writes a row, so it is not
    //something a link, a prefetch or a crawler should be able to trigger.
    if (($_SERVER["REQUEST_METHOD"] ?? '') !== "POST") {
        header("Allow: POST");
        ai_explain_reply(405, ["ok" => false, "error" => "Use POST to explain a move."]);
    }

    $field = function ($name) {
        return (isset($_POST[$name]) && is_string($_POST[$name])) ? trim($_POST[$name]) : '';
    };

    $symbol = strtoupper($field("symbol"));
    if ($symbol === '' || !preg_match('/^[A-Z0-9.\-]{1,50}$/', $symbol)) {
        ai_explain_reply(400, ["ok" => false, "error" => "Give a symbol."]);
    }

    /* A date, in one format, or nothing doing. strtotime() would read
       "yesterday" and "+1 month", and an explanation whose range depends on
       when it was asked for is cached under a key that means nothing. */
    $a_date = function ($name) use ($field) {
        $raw = $field($name);
        $parsed = DateTime::createFromFormat('Y-m-d', $raw);
        if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
            ai_explain_reply(400, ["ok" => false,
                                   "error" => "'" . $name . "' must be a date in YYYY-MM-DD form."]);
        }
        return $raw;
    };

    $from = $a_date("from");
    $to   = $a_date("to");

    if ($from > $to) {
        ai_explain_reply(400, ["ok" => false, "error" => "The range starts after it ends."]);
    }

    $rows = query("select symbol from stock_symbols where symbol=? and exchange=?",
                  $symbol, session_exchange());
    if (!count($rows)) {
        ai_explain_reply(404, ["ok" => false, "error" => "There is no such share on this exchange."]);
    }

    /* The request takes as long as the model takes. PHP holds the session
       file locked for the whole of a request, so without this the owner's
       other tabs would sit and wait for it. $_SESSION stays readable; it
       just stops being written back, and nothing below writes to it. */
    session_write_close();

    /* Any fatal below would otherwise reach the browser as HTML in a response
       the panel is trying to parse as JSON. A shutdown handler turns that
       into the same error shape as everything else. */
    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error === null) { return; }
        if (!in_array($error["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) { return; }
        //The message can carry a file path, so it is logged rather than sent.
        error_log("ai_explain: fatal: " . $error["message"]);
        if (!headers_sent()) { http_response_code(500); }
        print(json_encode(["ok" => false, "error" => "The explanation could not be generated."]));
    });

    $regenerate = ($field("regenerate") === '1');

    //Always 200 for a handled failure: the panel reads ok and error, and a
    //non-2xx would have fetch() callers guessing whether there is a body.
    ai_explain_reply(200, explain_generate($symbol, $from, $to, $regenerate));
