<?php

    /**
     * Light, dark or the system setting.
     *
     *   POST theme.php   theme=dark
     *
     * Stored on the user rather than in the browser, so it is the same on
     * the phone and the desktop and so the page can be served with the right
     * theme already on it. A theme applied by script after the page has
     * painted is a white flash on every load.
     *
     * POST and a redirect rather than JSON, so the menu works with
     * JavaScript off. config.php applies the same authentication gate as
     * every other page here.
     *
     * The value is matched against the three it can be; anything else is
     * refused rather than stored.
     */

    require("../includes/config.php");

    if (($_SERVER["REQUEST_METHOD"] ?? 'GET') !== 'POST') {
        header("Allow: POST");
        http_response_code(405);
        apologize("Ask for this with POST.");
    }

    $theme = (isset($_POST["theme"]) && is_string($_POST["theme"])) ? $_POST["theme"] : '';

    if (!in_array($theme, ['system', 'light', 'dark'], true)) {
        apologize("That is not a theme.");
    }

    query("update users set theme=? where id=?", $theme, $_SESSION["id"]);
    $_SESSION["sm_theme"] = $theme;

    /* Back where it was asked for, so changing the theme does not also move
       you to another page. Only a path on this site: a Location built from
       the request is a redirect somebody else can aim. */
    $back = (isset($_POST["from"]) && is_string($_POST["from"])) ? $_POST["from"] : '';
    if ($back === '' || !preg_match('~^[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_\-=&%.+]*)?$~', $back)) {
        $back = 'index.php';
    }

    redirect($back);

?>
