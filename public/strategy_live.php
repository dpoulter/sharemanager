<?php

    /**
     * Arming, disarming, and approving a live account's orders.
     *
     *   POST strategy_live.php  do=arm       strategy=... password=... name=...
     *                                        cap=250 days=35
     *   POST strategy_live.php  do=disarm    strategy=...
     *   POST strategy_live.php  do=approve   strategy=... batch=... password=...
     *   POST strategy_live.php  do=reject    strategy=... batch=... reason=...
     *   POST strategy_live.php  do=approval  strategy=... on=1|0 password=...
     *
     * This is the form on the strategy's page and nothing else. Arming a
     * live account is the one decision in this application that lets real
     * money move, and it is made by a person who is logged in, typing the
     * app password again and the strategy's name in full - so it is a POST
     * from a browser with a session, never a link, never a job, never the
     * command line. includes/broker_live.php refuses anything else: Jarvis
     * deploys and tests; arming is Dale's.
     *
     * Everything here hands straight to includes/broker_live.php, which
     * does the deciding. This file reads the form, says what happened and
     * sends the reader back to where they were.
     *
     * config.php applies the same authentication gate as every other page
     * here: an anonymous POST is redirected to login.php and reaches none
     * of this.
     */

    require("../includes/config.php");
    require_once("../includes/strategy_functions.php");
    require_once("../includes/broker_live.php");

    /* POST only, and said with the right status rather than with a page: a
       GET that armed anything would be a GET that a link preview could
       fire. */
    if (($_SERVER["REQUEST_METHOD"] ?? 'GET') !== 'POST') {
        header("Allow: POST");
        http_response_code(405);
        apologize("Arming and approving are forms on the strategy's page, so they are"
                . " a POST and nothing else.");
    }

    $field = function ($name) {
        return (isset($_POST[$name]) && is_string($_POST[$name])) ? trim($_POST[$name]) : '';
    };

    $strategy = $field('strategy');
    $action   = strtolower($field('do'));

    /* Which strategies may be named: the ones with accounts, by name, so
       the parameter cannot reach a backtest account or anything else. */
    $allowed = [];
    foreach (query("select strategy from strategy_accounts") as $row) {
        if (!strategy_reserved($row['strategy'])) { $allowed[] = (string) $row['strategy']; }
    }

    if (!in_array($strategy, $allowed, true)) {
        http_response_code(404);
        apologize("There is no strategy called that.");
    }

    /* Back where they were, with a line saying what happened. The message
       is in the session rather than in the address: it can carry a refusal
       with a reason in it, and a reason in a URL is a reason somebody can
       put there themselves. */
    $back = function ($ok, $message) use ($strategy) {
        $_SESSION['sm_live_message'] = ['ok' => (bool) $ok, 'text' => (string) $message,
                                        'strategy' => $strategy];
        redirect("strategies.php?s=" . urlencode($strategy)
                 . "&tab=" . ($ok ? 'holdings' : 'orders'));
    };

    $user_id = (int) ($_SESSION['id'] ?? 0);

    if (!broker_live_ready()) {
        $back(false, "The live tables are not there yet: apply sql/strategy_live.sql.");
    }

    if ($action === 'arm') {

        $result = broker_live_arm($strategy, [
            'user_id'     => $user_id,
            'password'    => (string) ($_POST['password'] ?? ''),
            'typed_name'  => $field('name'),
            'capital_cap' => (float) str_replace([',', "\xc2\xa3"], '', $field('cap')),
            'days'        => $field('days') === '' ? null : (int) $field('days'),
            'reason'      => $field('reason'),
        ]);

        $back($result['ok'], $result['ok']
            ? ("Armed. " . broker_live_words($strategy))
            : ("Not armed: " . $result['why']));
    }

    if ($action === 'disarm') {

        /* One click, no password. Disarmed is the safe direction, so it is
           the easy one: anybody logged in can stop a live account trading,
           and only Dale can start one. */
        $users = query("select username from users where id=?", $user_id);
        $who = count($users) ? (string) $users[0]['username'] : null;

        $result = broker_live_disarm($strategy, $who, "disarmed on the strategy's page");

        $back(true, $result['disarmed'] > 0
            ? "Disarmed. Orders are planned and not sent until it is armed again."
            : "That account was not armed.");
    }

    if ($action === 'approve' || $action === 'reject') {

        $batch = $field('batch');

        $result = ($action === 'approve')
                ? broker_live_approve($strategy, $batch, [
                      'user_id'  => $user_id,
                      'password' => (string) ($_POST['password'] ?? ''),
                      'reason'   => $field('reason')])
                : broker_live_reject($strategy, $batch, [
                      'user_id'  => $user_id,
                      'reason'   => $field('reason') === '' ? 'rejected on the page'
                                                            : $field('reason')]);

        if (!$result['ok']) { $back(false, "Nothing changed: " . $result['why']); }

        $back(true, $action === 'approve'
            ? ($result['orders'] . " order(s) approved. They are sent in this session,"
               . " while the market is open, and expire at the close if they are not.")
            : ($result['orders'] . " order(s) rejected and cancelled. Tonight's run plans"
               . " afresh."));
    }

    if ($action === 'approval') {

        $on = $field('on') === '1';

        $result = broker_live_set_approval($strategy, $on, [
            'user_id'  => $user_id,
            'password' => (string) ($_POST['password'] ?? ''),
        ]);

        $back($result['ok'], $result['ok']
            ? ($on ? "Every batch now waits for your approval."
                   : "This account now sends what it plans without asking. Its orders"
                     . " still need it to be armed, and still obey every cap.")
            : ("Nothing changed: " . $result['why']));
    }

    http_response_code(400);
    apologize("That is not something this form does.");

?>
