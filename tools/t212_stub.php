<?php

/**
 * A stand-in for the Trading 212 API, for the test suite.
 *
 *   SM_STUB_DIR=/tmp/t212 php -S 127.0.0.1:8371 tools/t212_stub.php
 *
 * No test makes a live request. The live key reads a real account with real
 * money in it, and a suite that calls somebody's broker on every run is not a
 * suite anybody should run.
 *
 * Nothing here is copied from Trading 212. The shapes are what the brief
 * describes - Basic auth, a bare array for the lists, {items, nextPagePath}
 * for the cursor-paged history, x-ratelimit headers on every answer - and the
 * field names are the ones includes/broker.php knows, which is the point: a
 * test that invents the field names proves nothing about the real API, but it
 * does prove the client pages, dedupes, masks and handles 401, 403, 429 and
 * 500 the way it says it does.
 *
 * The control directory decides what it answers:
 *
 *   t212_mode        ok | 401 | 403 | 429 | 500 | garbage   (default ok)
 *   t212_mode_<call> the same, for one call only, where <call> is summary,
 *                    positions, instruments, orders or dividends
 *   t212_429_once    present: the first request answers 429 with a reset of
 *                    1 second and the file is removed, so the retry succeeds
 *   t212_auth        "key:secret" that must arrive, or the answer is 401
 *   <call>.json      the body served for that call
 *   orders_page2.json  present: the orders call pages, and the first page
 *                    points at the second
 *   t212_requests    appended to, one line per request: the time, the path,
 *                    the query and whether credentials arrived. Never the
 *                    credentials themselves, in a file a person might paste
 *                    somewhere.
 */

$dir  = getenv('SM_STUB_DIR') ?: sys_get_temp_dir();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$query = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?: '';

$calls = [
    '/api/v0/equity/account/summary'      => 'summary',
    '/api/v0/equity/positions'            => 'positions',
    '/api/v0/equity/metadata/instruments' => 'instruments',
    '/api/v0/equity/history/orders'       => 'orders',
    '/api/v0/equity/history/dividends'    => 'dividends',
    '/api/v0/equity/orders'               => 'pending',
];
$call = isset($calls[$path]) ? $calls[$path] : null;

/* The Authorization header, present or not. The value is deliberately not
   recorded: see the note above. */
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
@file_put_contents("$dir/t212_requests",
                   microtime(true) . ' ' . $_SERVER['REQUEST_METHOD'] . ' ' . $path
                   . ' ' . $query . ' auth=' . ($auth === '' ? 'absent' : 'present')
                   . ' ua=' . ($_SERVER['HTTP_USER_AGENT'] ?? '-') . "\n",
                   FILE_APPEND);

function stub_mode($dir, $call) {
    foreach (["$dir/t212_mode_$call", "$dir/t212_mode"] as $file) {
        if (is_file($file)) {
            $mode = trim((string) @file_get_contents($file));
            if ($mode !== '') { return $mode; }
        }
    }
    return 'ok';
}

function stub_json($dir, $name, $fallback) {
    $file = "$dir/$name.json";
    if (is_file($file)) { return (string) file_get_contents($file); }
    return json_encode($fallback);
}

function stub_answer($status, $body, $reset = 30) {
    http_response_code($status);
    header('Content-Type: application/json');
    header('x-ratelimit-remaining: 42');
    header('x-ratelimit-reset: ' . $reset);
    print($body);
    exit;
}

/* Stage 1 reads. A stub that happily accepted a POST would hide exactly the
   mistake the suite is checking for. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    stub_answer(405, json_encode(['error' => 'this stub answers GET only']));
}

if ($call === null) {
    stub_answer(404, json_encode(['error' => 'no such path']));
}

/* Credentials, where the test asked for them to be checked. */
$want = is_file("$dir/t212_auth") ? trim((string) file_get_contents("$dir/t212_auth")) : '';
if ($want !== '') {
    $given = preg_match('~^Basic\s+(\S+)$~i', $auth, $m) ? base64_decode($m[1]) : '';
    if ($given !== $want) {
        stub_answer(401, json_encode(['error' => 'the key was not accepted']));
    }
}

/* One 429, then the file is gone and the retry gets an answer. */
if (is_file("$dir/t212_429_once")) {
    @unlink("$dir/t212_429_once");
    stub_answer(429, json_encode(['error' => 'slow down']), 1);
}

$mode = stub_mode($dir, $call);
if ($mode === '401')     { stub_answer(401, json_encode(['error' => 'unauthorised'])); }
if ($mode === '403')     { stub_answer(403, json_encode(['error' => 'the key lacks the scope'])); }
if ($mode === '429')     { stub_answer(429, json_encode(['error' => 'slow down']), 1); }
if ($mode === '500')     { stub_answer(500, json_encode(['error' => 'our end'])); }
if ($mode === 'garbage') { stub_answer(200, '<html>not json at all</html>'); }

if ($call === 'summary') {
    /* The shape the live account really answered on 17 November 2026,
       figures changed. It nests, which is the thing worth reproducing: the
       first version of this stub answered a flat object, so the suite
       agreed with the code and the live account did not. */
    stub_answer(200, stub_json($dir, 'summary', [
        'id'       => 52612242,
        'currency' => 'GBP',
        'totalValue' => 1250.00,
        'cash'       => ['availableToTrade' => 250.00,
                         'reservedForOrders' => 0,
                         'inPies' => 0],
        'investments' => ['currentValue' => 1000.00,
                          'totalCost' => 950.00,
                          'realizedProfitLoss' => 12.50,
                          'unrealizedProfitLoss' => 50.00],
    ]));
}

if ($call === 'positions') {
    stub_answer(200, stub_json($dir, 'positions', []));
}

if ($call === 'instruments') {
    stub_answer(200, stub_json($dir, 'instruments', []));
}

if ($call === 'pending') {
    stub_answer(200, stub_json($dir, 'pending', []));
}

/* The cursor-paged history. The second page exists only if the test wrote
   one, and the cursor the first page gives is a path, as the brief says. */
if ($call === 'orders' || $call === 'dividends') {

    $cursor = isset($_GET['cursor']) ? (string) $_GET['cursor'] : '';
    $page   = $cursor === '' ? 1 : 2;

    if ($page === 2) {
        stub_answer(200, stub_json($dir, $call . '_page2',
                   ['items' => [], 'nextPagePath' => null]));
    }

    $body = json_decode(stub_json($dir, $call, ['items' => [], 'nextPagePath' => null]), true);
    if (!is_array($body)) { $body = ['items' => [], 'nextPagePath' => null]; }
    if (!isset($body['items'])) { $body = ['items' => $body, 'nextPagePath' => null]; }

    if (is_file("$dir/{$call}_page2.json")) {
        $body['nextPagePath'] = '/api/v0/equity/history/'
                              . ($call === 'orders' ? 'orders' : 'dividends')
                              . '?cursor=next&limit=50';
    }

    stub_answer(200, json_encode($body));
}

stub_answer(404, json_encode(['error' => 'no such path']));
