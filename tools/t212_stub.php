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
 *   t212_place       ok | reject | reject_detail | reject_empty | slow | timeout
 *                    | 500 | forbidden, for the
 *                    stage 2 order path
 *   t212_next_order_id  the id the next accepted order comes back with
 *   t212_orders      appended to, one line per order body that arrived
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
    '/api/v0/equity/orders/market'        => 'place',
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

/* One path takes a POST: the stage 2 market order. Everything else answers
   GET and nothing else, so a stray write shows up as a 405 here rather than
   being quietly accepted. */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && !($method === 'POST' && $path === '/api/v0/equity/orders/market')) {
    stub_answer(405, json_encode(['error' => 'this stub answers GET, and POST to one path']));
}
if ($method === 'GET' && $path === '/api/v0/equity/orders/market') {
    stub_answer(405, json_encode(['error' => 'the order path takes a POST']));
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
    /* The real shape nests the instrument and the money:
         {"instrument":{...},"quantity":...,"currentPrice":<pence>,
          "averagePricePaid":<pence>,
          "walletImpact":{"totalCost":<pounds>,"currentValue":<pounds>,
                          "unrealizedProfitLoss":<pounds>}}
       A test that wants holdings writes positions.json in that shape. */
    stub_answer(200, stub_json($dir, 'positions', []));
}

if ($call === 'instruments') {
    stub_answer(200, stub_json($dir, 'instruments', []));
}

if ($call === 'pending') {
    stub_answer(200, stub_json($dir, 'pending', []));
}

/* The order itself. Records what arrived - the ticker, the quantity, and
   that a quantity is signed - so a test can assert a sell went as a negative
   number without the stub having to know what a sell is.

   t212_place controls the answer:
     ok (default)  accepted, with an id from t212_next_order_id or a made-up one
     reject        400 with a message about the quantity, which is how a share
                   that does not deal in fractions refuses one
     timeout       no answer at all, so the caller cannot know whether it
                   arrived - the case the whole SENT/UNKNOWN dance is for
     500           the same question, from the other direction */
if ($call === 'place') {

    $body = file_get_contents('php://input');
    @file_put_contents("$dir/t212_orders", $body . "\n", FILE_APPEND);

    $mode = stub_mode($dir, 'place');
    if (is_file("$dir/t212_place")) {
        $mode = trim((string) @file_get_contents("$dir/t212_place")) ?: 'ok';
    }

    if ($mode === 'slow') {
        /* A real timeout: the order is taken and the answer never comes
           back in time. Pair it with SM_T212_TIMEOUT=1 so a test costs a
           second rather than twenty. */
        sleep((int) (getenv('SM_STUB_SLEEP') ?: 3));
        stub_answer(200, json_encode(['id' => 'late-' . substr(sha1((string) $body), 0, 8),
                                      'status' => 'WORKING']));
    }
    if ($mode === 'timeout') {
        /* The other shape of the same question: an answer arrives and is
           not JSON, so the order may well have been taken and its id is
           unknown from here. */
        header('HTTP/1.1 200 OK');
        print('{"partial":');
        exit;
    }
    if ($mode === '500') { stub_answer(500, json_encode(['error' => 'our end'])); }
    if ($mode === 'reject') {
        stub_answer(400, json_encode(['code' => 'QuantityPrecisionInvalid',
                                      'message' => 'quantity has too many decimal places']));
    }
    if ($mode === 'forbidden') {
        stub_answer(403, json_encode(['code' => 'Forbidden']));
    }
    /* A refusal with none of code or message, the shape a problem-details
       body has: the words are in title and detail. The first sender to meet
       one recorded "no reason given" and could not tell the share deals in
       whole shares only. */
    if ($mode === 'reject_detail') {
        stub_answer(400, json_encode(['type' => 'about:blank', 'title' => 'Bad Request',
                                      'status' => 400,
                                      'detail' => 'The quantity is not valid for this instrument']));
    }
    /* And one with nothing to say at all. */
    if ($mode === 'reject_empty') { stub_answer(400, ''); }

    $sent = json_decode((string) $body, true);
    $id = is_file("$dir/t212_next_order_id")
        ? trim((string) file_get_contents("$dir/t212_next_order_id"))
        : 'stub-' . substr(sha1((string) $body . microtime()), 0, 10);

    /* The accepted order joins the pending list, so a poll finds it there
       until a test moves it into the history. */
    $pending = json_decode(stub_json($dir, 'pending', []), true);
    if (!is_array($pending)) { $pending = []; }
    /* The shape the real pending list has: flat, with the instrument nested
       beside it, a side of its own and a createdAt carrying an offset. */
    $quantity = is_array($sent) ? ($sent['quantity'] ?? null) : null;
    $ticker   = is_array($sent) ? ($sent['ticker'] ?? null) : null;
    $placed   = ['id' => $id, 'strategy' => 'QUANTITY', 'type' => 'MARKET',
                 'ticker' => $ticker,
                 'quantity' => $quantity === null ? null : abs((float) $quantity),
                 'filledQuantity' => 0, 'status' => 'NEW', 'currency' => 'GBP',
                 'extendedHours' => false, 'initiatedFrom' => 'API',
                 'side' => ($quantity !== null && $quantity < 0) ? 'SELL' : 'BUY',
                 'createdAt' => date('c'),
                 'instrument' => ['ticker' => $ticker, 'name' => 'Stub PLC',
                                  'isin' => 'GB00STUB0001', 'currency' => 'GBX']];

    $pending[] = $placed;
    @file_put_contents("$dir/pending.json", json_encode($pending));

    stub_answer(200, json_encode($placed));
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
