<?php

/**
 * A stand-in for the EODHD API, for the sandbox.
 *
 * Serves the two endpoints the application calls, in the shapes EODHD
 * documents, built from the seeded price history:
 *
 *   GET /real-time/{CODE}.LSE          latest quote
 *   GET /eod/{CODE}.LSE?from=&to=      daily prices
 *
 * Run it as its own server, on its own port:
 *
 *   php -S 127.0.0.1:8090 tools/eodhd_stub.php
 *
 * It must be a separate process from the application. php -S handles one
 * request at a time, so an application server calling a stub inside itself
 * would wait for a response it cannot produce until the current request ends.
 *
 * Database settings come from the same SM_SANDBOX_* variables tools/sandbox.sh
 * uses. Nothing here writes.
 */

header('Content-Type: application/json');

$db   = getenv('SM_SANDBOX_DB')   ?: 'sharemanager_sandbox';
$host = getenv('SM_SANDBOX_HOST') ?: '127.0.0.1';
$user = getenv('SM_SANDBOX_USER') ?: 'smtest';
$pass = getenv('SM_SANDBOX_PASS') ?: 'smtest';

try {
    $pdo = new PDO("mysql:dbname=$db;host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'stub cannot reach the database']);
    exit;
}

$path  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts = array_values(array_filter(explode('/', $path)));

/* EODHD addresses London as CODE.LSE; the database keys on the MIC. */
function bare_code($ticker) {
    return strtoupper(strpos($ticker, '.') === false ? $ticker : substr($ticker, 0, strpos($ticker, '.')));
}

/* A real key is required, so the stub rejects a missing one too. Otherwise a
   deployment that forgot to set it would look fine here and fail in production. */
if (($_GET['api_token'] ?? '') === '') {
    http_response_code(401);
    echo json_encode(['error' => 'no api_token']);
    exit;
}

$endpoint = $parts[0] ?? '';
$symbol   = bare_code($parts[1] ?? '');

if ($symbol === '') {
    http_response_code(404);
    echo json_encode(['error' => 'no symbol']);
    exit;
}

if ($endpoint === 'real-time') {

    $row = $pdo->prepare("select date, price from historical_prices
                          where symbol=? and price is not null
                          order by date desc limit 1");
    $row->execute([$symbol]);
    $latest = $row->fetch(PDO::FETCH_ASSOC);

    if (!$latest) {
        /* EODHD answers an unknown code with "NA" in every field rather than a
           404, which is why eodhd_quote_to_rows has to reject the string. */
        echo json_encode(['code' => strtoupper($parts[1]), 'timestamp' => 'NA', 'open' => 'NA',
                          'high' => 'NA', 'low' => 'NA', 'close' => 'NA', 'volume' => 'NA',
                          'previousClose' => 'NA', 'change' => 'NA', 'change_p' => 'NA']);
        exit;
    }

    $prev = $pdo->prepare("select price from historical_prices
                           where symbol=? and date < ? and price is not null
                           order by date desc limit 1");
    $prev->execute([$symbol, $latest['date']]);
    $previous = (float)($prev->fetchColumn() ?: $latest['price']);
    $close    = (float)$latest['price'];

    echo json_encode([
        'code'          => strtoupper($parts[1]),
        'timestamp'     => strtotime($latest['date'] . ' 16:30:00'),
        'gmtoffset'     => 0,
        'open'          => round($previous, 4),
        'high'          => round(max($close, $previous) * 1.004, 4),
        'low'           => round(min($close, $previous) * 0.996, 4),
        'close'         => round($close, 4),
        'volume'        => 250000 + (crc32($symbol) % 750000),
        'previousClose' => round($previous, 4),
        'change'        => round($close - $previous, 4),
        'change_p'      => $previous ? round(($close - $previous) / $previous * 100, 4) : 0,
    ]);
    exit;
}

if ($endpoint === 'eod') {

    $from = $_GET['from'] ?? '1900-01-01';
    $to   = $_GET['to']   ?? '2999-12-31';

    $rows = $pdo->prepare("select date, price from historical_prices
                           where symbol=? and date between ? and ? and price is not null
                           order by date asc");
    $rows->execute([$symbol, $from, $to]);

    $out = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $price = (float)$r['price'];
        $out[] = [
            'date'           => $r['date'],
            'open'           => round($price * 0.998, 4),
            'high'           => round($price * 1.006, 4),
            'low'            => round($price * 0.994, 4),
            'close'          => round($price, 4),
            'adjusted_close' => round($price, 4),
            'volume'         => 250000 + (crc32($symbol . $r['date']) % 750000),
        ];
    }

    echo json_encode($out);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'unknown endpoint']);
