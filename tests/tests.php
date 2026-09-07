<?php

    /**
     * Regression suite for the momentum batch jobs and the dashboard queries.
     *
     * Plain PHP with no framework, matching the rest of the project. Run it
     * through tests/run_tests.sh, which creates the database, loads the schema,
     * seeds it and puts tests/fixtures first on the include path.
     *
     * Each test resets the tables it writes to, so tests are order independent.
     */

    require_once("constants.php");
    require_once("functions.php");
    require_once("share_functions.php");

    $INCLUDES = dirname(__DIR__) . '/includes';
    $passed = 0; $failed = 0; $failures = [];

    function check($name, $ok, $detail = '') {
        global $passed, $failed, $failures;
        if ($ok) { $passed++; printf("  \033[32mPASS\033[0m  %s\n", $name); }
        else     { $failed++; $failures[] = $name;
                   printf("  \033[31mFAIL\033[0m  %s%s\n", $name, $detail === '' ? '' : "\n          $detail"); }
    }

    function db() {
        static $pdo;
        if (!isset($pdo)) {
            $pdo = new PDO("mysql:dbname=" . DATABASE . ";host=" . SERVER, USERNAME, PASSWORD);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return $pdo;
    }
    function scalar($sql, $params = []) { $s = db()->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
    function rows($sql, $params = [])   { $s = db()->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); }
    function reset_calculated() { db()->exec("truncate statistics"); db()->exec("truncate message_log");
                                  db()->exec("truncate jobs"); db()->exec("truncate statistic_averages"); }

    /* Runs one of the application's batch scripts the way cron would, with the
       test fixtures ahead of includes/ on the include path. Returns [stdout+stderr, exit code]. */
    function run_script($script, $args = [], $env = []) {
        global $INCLUDES;
        $include_path = __DIR__ . '/fixtures' . PATH_SEPARATOR . $INCLUDES;
        $cmd = '';
        // The name of an environment assignment must not be quoted: sh treats
        // 'NAME'=value as a command word, not an assignment.
        foreach ($env as $k => $v) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k)) { throw new Exception("bad env name $k"); }
            $cmd .= $k . '=' . escapeshellarg($v) . ' ';
        }
        $cmd .= 'php -d include_path=' . escapeshellarg($include_path) . ' ' . escapeshellarg("$INCLUDES/$script");
        foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
        exec($cmd . ' 2>&1', $out, $rc);
        return [implode("\n", $out), $rc];
    }

    echo "\n--- momentum as of date generation ---\n";

    /* The refactor in get_momentum_statistics.php replaced an inline loop with a
       generated list of dates. PHP's month arithmetic overflows (Nov 30 + 3
       months is Mar 2), so the dates must still come from repeated date_add
       rather than a single multi month add, or history shifts. */
    function reference_dates($min, $max) {
        $out = []; $interval = new DateInterval('P1M');
        $d = date_add(date_create($min), new DateInterval('P3M'));
        $end = date_create($max);
        while ($d <= $end) { $out[] = date_format($d, 'Y-m-d'); date_add($d, $interval); }
        return $out;
    }
    function single_add_dates($min, $max) {
        $out = []; $start = date_add(date_create($min), new DateInterval('P3M')); $end = date_create($max);
        $n = 0;
        while (true) { $d = clone $start;
                       if ($n > 0) { $d->add(new DateInterval('P' . $n . 'M')); }
                       if ($d > $end) break; $out[] = date_format($d, 'Y-m-d'); $n++; }
        return $out;
    }
    /* 2024-02-29 + 3 months lands on the 29th, and 2025 has no 29th of February,
       so the repeated add slips to 2025-03-01 while a single add stays on the
       29th. The range has to run past that month or the two agree and the guard
       below proves nothing. */
    $ref    = reference_dates('2024-02-29', '2025-12-31');
    $single = single_add_dates('2024-02-29', '2025-12-31');
    check('month overflow makes repeated add differ from single add (guard is meaningful)',
          $ref !== $single,
          'sequences agreed, so the next check cannot detect a regression');

    reset_calculated();
    run_script('get_momentum_statistics.php', ['2000-01-01', '2100-01-01']);
    $sym = 'AAA';
    $bounds = rows("select min(date) mn, max(date) mx from historical_prices where symbol=? and exchange='XLON'", [$sym])[0];
    $expected = reference_dates($bounds['mn'], $bounds['mx']);
    $actual = array_column(rows("select distinct date from statistics where symbol=? order by date", [$sym]), 'date');
    check('backfill produces exactly the original as of date sequence',
          $expected === $actual,
          'expected ' . count($expected) . ' dates, got ' . count($actual));

    echo "\n--- incremental vs backfill ---\n";

    reset_calculated();
    run_script('get_momentum_statistics.php');
    $incremental_dates = (int)scalar("select count(distinct date) from statistics");
    $per_symbol = rows("select symbol, count(distinct date) n from statistics group by symbol");
    $all_one = true; foreach ($per_symbol as $r) { if ((int)$r['n'] !== 1) { $all_one = false; } }
    check('nightly run calculates exactly one as of date per symbol', $all_one && count($per_symbol) > 0);
    check('nightly run uses the latest as of date',
          $incremental_dates === 1 && $actual !== [] && scalar("select max(date) from statistics") === end($expected));

    reset_calculated();
    run_script('get_momentum_statistics.php', ['2000-01-01', '2100-01-01']);
    $backfill_rows = (int)scalar("select count(*) from statistics");
    reset_calculated();
    run_script('get_momentum_statistics.php');
    $nightly_rows = (int)scalar("select count(*) from statistics");
    check('nightly run is far cheaper than a backfill',
          $nightly_rows > 0 && $backfill_rows > $nightly_rows * 10,
          "nightly=$nightly_rows backfill=$backfill_rows");

    reset_calculated();
    run_script('get_momentum_statistics.php', ['2024-01-01', '2024-03-31']);
    $in_range = (int)scalar("select count(*) from statistics where date < '2024-01-01' or date > '2024-03-31'");
    check('an explicit range writes nothing outside its bounds', $in_range === 0);

    echo "\n--- exchange scoping ---\n";

    reset_calculated();
    run_script('get_momentum_statistics.php', ['2000-01-01', '2100-01-01']);
    check('only the XLON exchange is written',
          rows("select distinct exchange from statistics") === [['exchange' => 'XLON']]);
    check('the LON symbol is never calculated',
          (int)scalar("select count(*) from statistics where symbol='ZZZ'") === 0);

    echo "\n--- idempotency ---\n";

    reset_calculated();
    $counts = [];
    for ($i = 0; $i < 3; $i++) { run_script('get_momentum_statistics.php'); $counts[] = (int)scalar("select count(*) from statistics"); }
    $dupes = (int)scalar("select count(*) from (select symbol,date,indicator from statistics group by symbol,date,indicator having count(*)>1) d");
    check('repeated nightly runs do not duplicate rows',
          $counts[0] === $counts[1] && $counts[1] === $counts[2] && $dupes === 0,
          'row counts ' . implode(',', $counts) . " duplicate keys $dupes");

    echo "\n--- date argument validation ---\n";

    foreach ([['not-a-date','2024-01-01','unparseable start'],
              ['2024-01-01','rubbish','unparseable end'],
              ['2024-13-45','2024-06-01','month and day out of range'],
              ['2024-02-30','2024-06-01','day out of range for the month'],
              ['2024-2-1','2024-06-01','not zero padded']] as [$a, $b, $label]) {
        reset_calculated();
        [$out] = run_script('get_momentum_statistics.php', [$a, $b]);
        check("rejects $label ($a)",
              stripos($out, 'Invalid') !== false && (int)scalar("select count(*) from statistics") === 0, $out);
    }
    reset_calculated();
    [$out] = run_script('get_momentum_statistics.php', ['2024-12-31', '2024-01-01']);
    check('rejects a reversed range',
          stripos($out, 'after end date') !== false && (int)scalar("select count(*) from statistics") === 0, $out);
    reset_calculated();
    [$out] = run_script('get_momentum_statistics.php', ['2024-02-29', '2024-06-01']);
    check('accepts a valid leap day', stripos($out, 'Invalid') === false && (int)scalar("select count(*) from statistics") > 0, $out);

    echo "\n--- debug logging gate ---\n";

    reset_calculated();
    run_script('get_momentum_statistics.php', ['2000-01-01', '2100-01-01']);
    $quiet = (int)scalar("select count(*) from message_log");
    reset_calculated();
    run_script('get_momentum_statistics.php', ['2000-01-01', '2100-01-01'], ['SM_TEST_DEBUG_LOG' => 'Y']);
    $loud = (int)scalar("select count(*) from message_log");
    check('DEBUG_LOG off keeps a backfill to a single summary row', $quiet === 1, "got $quiet rows");
    check('DEBUG_LOG on restores per row tracing', $loud > $quiet * 100, "off=$quiet on=$loud");

    echo "\n--- jobs table and the dashboard ---\n";

    reset_calculated();
    run_script('get_momentum_statistics.php');
    [$out, $rc] = run_script('get_statistics.php');
    check('get_statistics.php completes without a fatal', stripos($out, 'Fatal error') === false, substr($out, -400));

    $run_row  = scalar("select max(job_date) from jobs where job_name='get_statistics'");
    $asof_row = scalar("select max(job_date) from jobs where job_name='get_statistics_asof'");
    check('log_job records when the job ran', $run_row !== false && $run_row !== null);
    check('the as of date is recorded under its own job_name', $asof_row !== false && $asof_row !== null);
    check('the run row and the as of row are different dates',
          substr((string)$run_row, 0, 10) !== substr((string)$asof_row, 0, 10),
          "run=$run_row asof=$asof_row");

    /* The regression this guards: readers must resolve the as of marker, not the
       run row. Resolving the run row lands a day ahead of the data and empties
       the dashboard. */
    $resolved   = scalar("select date(date_sub(max(job_date),INTERVAL 0 DAY)) from jobs where job_name='get_statistics_asof'");
    $score_date = scalar("select max(date) from statistics where indicator='momentum_score'");
    check('the as of marker matches the date the scores were written at',
          $resolved !== false && $resolved === $score_date, "resolved=$resolved scores=$score_date");
    $wrong = scalar("select date(date_sub(max(job_date),INTERVAL 0 DAY)) from jobs where job_name='get_statistics'");
    check('resolving the run row instead would miss the scores (regression guard)', $wrong !== $score_date);

    // The dashboard reads through $_SESSION["exchange"], set from users.default_exchange at login.
    $_SESSION['exchange'] = scalar("select default_exchange from users where username='tester'");
    check('momentum top ten returns rows', count(get_momentum_topten()) > 0);
    check('get_last_update reports the as of date, not the run time',
          substr((string)get_last_update(), 0, 10) === $score_date,
          'got ' . get_last_update() . " expected $score_date");

    /* The four panels must all resolve the same as of date. get_overall_topten
       used INTERVAL 1 DAY where the others use 0, which left it a day behind. */
    db()->exec("insert into statistics (symbol,date,indicator,value,exchange)
                select symbol, '" . $score_date . "', ind, 50, 'XLON'
                from stock_symbols, (select 'value_score' ind union select 'quality_score' union select 'overall_score') i
                where exchange='XLON'");
    foreach (['momentum' => 'get_momentum_topten', 'value' => 'get_value_topten',
              'quality'  => 'get_quality_topten',  'overall' => 'get_overall_topten'] as $label => $fn) {
        check("$label top ten resolves the same as of date and returns rows", count($fn()) > 0);
    }

    printf("\n%s  %d passed, %d failed\n\n",
           $failed === 0 ? "\033[32mALL PASSED\033[0m" : "\033[31mFAILURES\033[0m", $passed, $failed);
    if ($failed > 0) { echo "  failed: " . implode("\n          ", $failures) . "\n\n"; }
    exit($failed === 0 ? 0 : 1);

?>
