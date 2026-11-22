<?php

    /**
     * Every page, against a database with nothing in it.
     *
     *   php tests/empty_db.php
     *
     * The suite's own database is seeded: a portfolio, a universe, a month
     * of statistics, scored announcements. That is the easy case. The hard
     * one is the first night after a deploy, when the tables exist and the
     * jobs have not run - and every empty state on every page is being seen
     * for the first time by somebody who has just set the application up.
     *
     * So this builds a second database from the same schema and the same
     * migrations, puts one user in it and nothing else, and asks for every
     * page. A page passes if it comes back without a fatal, without a PHP
     * warning or notice, and without printing the word "Undefined" - which
     * is what an unguarded array index looks like on screen.
     *
     * It empties the suite's own database rather than building a second
     * one: the test user has create and drop on that database and on no
     * other, which is right. So this runs last, after tests.php, and
     * run_tests.sh reloads the schema at the start of every run.
     */

    require_once("constants.php");

    $passed = 0; $failed = 0; $failures = [];

    function check($name, $ok, $detail = '') {
        global $passed, $failed, $failures;
        if ($ok) { $passed++; printf("  \033[32mPASS\033[0m  %s\n", $name); }
        else     { $failed++; $failures[] = $name;
                   printf("  \033[31mFAIL\033[0m  %s%s\n", $name, $detail === '' ? '' : "\n          $detail"); }
    }

    $host = getenv("SM_TEST_HOST") ?: '127.0.0.1';
    $user = getenv("SM_TEST_USER") ?: 'smtest';
    $pass = getenv("SM_TEST_PASS") ?: 'smtest';
    $name = getenv("SM_TEST_DB") ?: 'sharemanager_test';

    /* The suite's own database, emptied - not a second one. The test user
       has create and drop on this database and on no other, which is right,
       and run_tests.sh reloads the schema at the start of every run, so
       leaving it empty at the end costs nothing.

       This runs last for that reason. */
    if (substr($name, -5) !== '_test') {
        printf("  \033[31mFAIL\033[0m  refusing to empty '%s': it is not a test database\n", $name);
        exit(1);
    }

    $repo = dirname(__DIR__);

    try {
        $pdo = new PDO("mysql:dbname=$name;host=$host", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    catch (Throwable $e) {
        printf("  \033[33mSKIP\033[0m  empty database: cannot connect (%s)\n", $e->getMessage());
        exit(0);
    }

    /* What a first night looks like: the schema and the migrations applied,
       and every job yet to run. The reference tables the migrations fill -
       the indicators, the screens, the strategy rules - stay, because on a
       real first night they are there. Everything a job writes goes.

       Named rather than "every table but users": a table added later should
       make somebody decide which side of this line it is on, rather than
       being emptied silently. */
    $emptied = ['historical_prices', 'statistics', 'statistic_averages', 'stock_symbols',
                'stock_info', 'rns_announcements', 'news_headlines', 'jobs',
                'purchases', 'shares', 'dividends', 'cash_history', 'performance',
                'portfolio_performance', 'strategy_targets', 'strategy_orders',
                'strategy_values', 'strategy_backtest_runs', 'ai_outlook',
                'price_valuation', 'price_momentum', 'market_breadth',
                'message_log', 'history',
                'financial_statement_values', 'fin_stmts'];

    $present = [];
    foreach ($pdo->query("select table_name from information_schema.tables
                           where table_schema=database()") as $row) {
        $present[] = $row['table_name'] ?? $row['TABLE_NAME'];
    }

    foreach ($emptied as $table) {
        if (in_array($table, $present, true)) { $pdo->exec("truncate table `$table`"); }
    }

    /* One user, so there is somebody to log in as. */
    $pdo->exec("delete from users");
    $pdo->prepare("insert into users (id, username, hash, email, cash, default_exchange)
                   values (1, 'tester', ?, 'tester@example.invalid', 0, 'XLON')")
        ->execute([password_hash('testpass', PASSWORD_DEFAULT)]);

    $port = (int) (getenv("SM_EMPTY_PORT") ?: 8399);
    $include_path = "$repo/tests/fixtures" . PATH_SEPARATOR . "$repo/includes";

    $server = 'SM_DB_NAME=' . escapeshellarg($name)
            . ' SM_DB_HOST=' . escapeshellarg($host)
            . ' SM_DB_USER=' . escapeshellarg($user)
            . ' SM_DB_PASS=' . escapeshellarg($pass)
            . ' SM_DISPLAY_ERRORS=1'
            . ' php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg("$repo/public")
            . ' -d include_path=' . escapeshellarg($include_path)
            . ' -d opcache.enable=0 -d display_errors=1 -d error_reporting=' . (E_ALL)
            . ' > /dev/null 2>&1 & echo $!';
    $pid = (int) trim((string) shell_exec($server));

    $base = "http://127.0.0.1:$port";
    $up = false;
    for ($i = 0; $i < 40 && !$up; $i++) {
        usleep(250000);
        $up = @file_get_contents("$base/login.php", false,
                                 stream_context_create(['http' => ['timeout' => 2]])) !== false;
    }

    if (!$up) {
        check('the empty-database server starts', false, "nothing answered on $base");
    }
    else {
        $jar = tempnam(sys_get_temp_dir(), 'smempty');
        $curl = function ($url, $post = null) use ($jar) {
            $cmd = 'curl -s -m 30 -L -b ' . escapeshellarg($jar) . ' -c ' . escapeshellarg($jar);
            if ($post !== null) { $cmd .= ' -d ' . escapeshellarg($post); }
            return (string) shell_exec($cmd . ' ' . escapeshellarg($url) . ' 2>/dev/null');
        };

        $curl("$base/login.php");
        $curl("$base/login.php", 'username=tester&password=testpass');

        $pages = ['index.php', 'performance.php', 'dividends.php', 'cash_history.php',
                  'edit.php', 'topup.php', 'screen_list.php', 'quote.php',
                  'criteria.php', 'criteria_list.php', 'screen_criteria.php', 'screen_create.php',
                  'sector_companies.php', 'strategies.php', 'sell.php', 'buy.php',
                  'statistics.php', 'statistics_form.php', 'download_prices.php',
                  'in_the_news.php', 'rankings.php', 'portfolio_data.php',
                  'broker.php'];

        $broken = []; $noisy = [];

        foreach ($pages as $page) {
            $body = $curl("$base/$page");
            if (preg_match('/(Fatal error|Parse error)/i', $body)) { $broken[] = $page; }
            /* A warning on an empty database is an unguarded index or an
               unchecked row count, and it is the thing this exists to find. */
            if (preg_match('/(Warning:|Notice:|Deprecated:|Undefined (variable|index|array key|property))/i',
                           $body, $hit)) {
                $noisy[] = $page . ' (' . trim($hit[0]) . ')';
            }
        }

        check(count($pages) . ' pages render against an empty database',
              count($broken) === 0, 'fatal: ' . implode(', ', $broken));
        check('and none of them warns about something that is not there',
              count($noisy) === 0, implode("\n          ", $noisy));

        /* The empty states themselves: a page with nothing to show should
           say so in words rather than drawing an empty table. */
        $home = $curl("$base/index.php");
        check('the dashboard says there are no holdings rather than showing zero',
              stripos($home, 'No holdings') !== false, substr($home, 0, 200));
        $portfolio = $curl("$base/performance.php");
        check('the portfolio offers a first purchase rather than an empty table',
              stripos($portfolio, 'Nothing held') !== false
              && stripos($portfolio, 'Add a purchase') !== false);
        $news = $curl("$base/in_the_news.php");
        check('the news page says the job has not run rather than nothing at all',
              stripos($news, 'give it a night') !== false);

        @unlink($jar);
    }

    if ($pid > 0) { exec("kill $pid 2>/dev/null"); }

    printf("\n%s  %d passed, %d failed\n\n",
           $failed === 0 ? "\033[32mALL PASSED\033[0m" : "\033[31mFAILURES\033[0m", $passed, $failed);
    if ($failed > 0) { echo "  failed: " . implode("\n          ", $failures) . "\n\n"; }
    exit($failed === 0 ? 0 : 1);

?>
