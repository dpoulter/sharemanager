<?php

/**
 * A stand-in for Investegate, for the test suite.
 *
 *   SM_STUB_DIR=/tmp/rns php -S 127.0.0.1:8129 tools/investegate_stub.php
 *
 * No test makes a live request. Investegate's terms of use are for a private
 * investor reading announcements in a personal capacity; a test suite
 * hammering it on every run is neither, and a suite that depends on somebody
 * else's site is a suite that fails on a Sunday for reasons of its own.
 *
 * The control directory decides what it answers:
 *
 *   site_mode       ok | empty | limit | 429 | 500 | login   (default ok)
 *   listing.html    the body served for /company/<TIDM>
 *   announcement.html   the body served for /announcement/...
 *   robots.txt      the body served for /robots.txt
 *   requests        appended to, one line per request, so a test can assert
 *                   the order, the throttle and the User-Agent
 *
 * The built-in bodies are shaped like the real ones as the brief describes
 * them - the announcement URL pattern, a date beside each link - and nothing
 * here is copied from Investegate.
 */

$dir = getenv('SM_STUB_DIR') ?: sys_get_temp_dir();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
/* site_mode, not mode: tools/anthropic_stub.php reads "mode" from the same
   control directory, and a test that wants a stubbed score and a stubbed
   listing at the same time cannot set one file to two values. */
$mode = trim((string) @file_get_contents("$dir/site_mode")) ?: 'ok';

@file_put_contents("$dir/requests",
                   microtime(true) . " " . $path . " "
                   . ($_SERVER['HTTP_USER_AGENT'] ?? '-') . "\n",
                   FILE_APPEND);

function stub_body($dir, $name, $fallback) {
    $file = "$dir/$name";
    return file_exists($file) ? (string) file_get_contents($file) : $fallback;
}

/* robots.txt first: the fetcher asks for it before anything else. */
if ($path === '/robots.txt') {
    header('Content-Type: text/plain');
    print(stub_body($dir, 'robots.txt',
          "User-agent: *\nDisallow: /login\nDisallow: /register\n"
        . "Disallow: /callback\nDisallow: /search-company\nDisallow: /ads\n"));
    exit;
}

if ($mode === '429') { http_response_code(429); print('slow down'); exit; }
if ($mode === '500') { http_response_code(500); print('server error'); exit; }

/* The limit modal, and the login page, are both short pages carrying the
   wording and no announcement. That shape is the thing under test. */
if ($mode === 'limit') {
    header('Content-Type: text/html');
    print('<html><body><div class="modal"><h2>Announcement limit reached</h2>'
        . '<p>You have reached your announcement limit for today.</p></div></body></html>');
    exit;
}
if ($mode === 'login') {
    header('Content-Type: text/html');
    print('<html><body><h1>Sign in</h1><p>Please sign in to continue.</p></body></html>');
    exit;
}

header('Content-Type: text/html');

if (strpos($path, '/company/') === 0) {
    /* A company page that lists nothing. Not an error: a company can go a
       month without announcing anything, and a test that wants to exercise
       the scorer without the fetcher finding new work needs this. */
    if ($mode === 'empty') {
        print('<html><body><h1>No announcements</h1></body></html>');
        exit;
    }
    print(stub_body($dir, 'listing.html', stub_default_listing($path)));
    exit;
}

if (strpos($path, '/announcement/') === 0) {
    print(stub_body($dir, 'announcement.html', stub_default_announcement()));
    exit;
}

http_response_code(404);
print('<html><body>Not found</body></html>');

/* ------------------------------------------------------------------------ */

/* A date $days ago, written the way a listing writes it. */
function stub_when($days, $time = '07:00', $format = 'j M Y') {
    return gmdate($format, strtotime("-$days day")) . ' ' . $time;
}

function stub_default_listing($path) {

    $tidm = strtoupper(rawurldecode(substr($path, strlen('/company/'))));
    $slug = 'example-company';
    $rows = '';

    /* A trading update worth reading, a buyback filing that is not, and a
       director purchase that is - the three cases the skip list is about.
       Newest first, as a company page lists them.

       Dated from today rather than from a fixed day, so the suite does not
       quietly stop testing the fetcher the month these dates fall outside
       the job's window. */
    $items = [
        ['9001', 'rns', 'trading-update',            stub_when(0)],
        ['9002', 'rns', 'transaction-in-own-shares', stub_when(1, '16:35')],
        ['9003', 'eqs', 'director-pdmr-shareholding-purchase-of-shares', stub_when(2)],
    ];

    foreach ($items as [$id, $source, $title, $when]) {
        $rows .= '<tr><td class="date">' . $when . '</td>'
               . '<td><a href="/announcement/' . $source . '/' . $slug . '--'
               . rawurlencode($tidm) . '/' . $title . '/' . $id . '">'
               . ucwords(str_replace('-', ' ', $title)) . '</a></td></tr>';
    }

    return '<html><head><title>' . htmlspecialchars($tidm) . ' announcements</title></head>'
         . '<body><nav><a href="/login">Sign in</a></nav>'
         . '<h1>' . htmlspecialchars($tidm) . '</h1>'
         . '<table><tbody>' . $rows . '</tbody></table></body></html>';
}

function stub_default_announcement() {

    /* Long enough to pass RNS_MIN_BODY_CHARS, with a date at the top, an
       Investegate AI summary to be stripped, and a figure to be picked up. */
    return '<html><head><style>.a{color:red}</style><script>var x=1;</script></head>'
         . '<body><nav><a href="/login">Sign in</a></nav>'
         . '<h1>Trading Update</h1>'
         . '<div><h3>Summary by AI BETA</h3><p>A machine wrote this bit and it should '
         . 'never reach the scorer.</p></div>'
         . '<p>' . stub_when(0, '07:00', 'j F Y') . '</p>'
         . '<p>Example Company plc ("the Company") today announces a trading update for '
         . 'the four months ended 31 October 2026.</p>'
         . '<p>Trading has been ahead of the board&rsquo;s expectations. Revenue for the '
         . 'period grew 7% to &pound;1.2bn, with order intake of &pound;1.8bn. The board '
         . 'now expects underlying operating profit for the full year to be above the top '
         . 'end of the range previously guided.</p>'
         . '<p>The Company continues to expect free cash flow of around &pound;300m and '
         . 'remains confident in its medium term targets. A further update will be given '
         . 'with the full year results in March 2027.</p>'
         . '<footer>Investegate</footer></body></html>';
}
