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
    //The AI outlook and the pieces under it. Loading these calls nothing and
    //reaches no network; only outlook_generate() does.
    require_once("llm.php");
    require_once("vault.php");
    require_once("ai_outlook.php");

    $INCLUDES = dirname(__DIR__) . '/includes';
    $passed = 0; $failed = 0; $failures = [];

    /**
     * The first keyword structured outputs do not support, or null.
     *
     * The documented exclusions: recursive schemas, numerical constraints,
     * string constraints, complex array constraints, and
     * additionalProperties set to anything but false.
     */
    function outlook_schema_unsupported_keyword($node) {
        if (!is_array($node)) { return null; }
        foreach (['minimum','maximum','exclusiveMinimum','exclusiveMaximum','multipleOf',
                  'minLength','maxLength','pattern',
                  'minItems','maxItems','uniqueItems','contains',
                  'minProperties','maxProperties','patternProperties',
                  'if','then','else','not','oneOf'] as $keyword) {
            if (array_key_exists($keyword, $node)) { return $keyword; }
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $found = outlook_schema_unsupported_keyword($child);
                if ($found !== null) { return $found; }
            }
        }
        return null;
    }

    /**
     * Is additionalProperties false on every object in a JSON schema?
     *
     * Checked recursively rather than on the top level alone: a nested object
     * left open is the same hole, just harder to see.
     */
    function outlook_schema_objects_all_closed($node) {
        if (!is_array($node)) { return true; }
        if (($node['type'] ?? null) === 'object' && ($node['additionalProperties'] ?? null) !== false) {
            return false;
        }
        foreach ($node as $child) {
            if (is_array($child) && !outlook_schema_objects_all_closed($child)) { return false; }
        }
        return true;
    }

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

    /* The date the nightly job calculates for: the last day prices were
       loaded, which is what get_statistics.php resolves through
       resolve_asof_date(). Every test that writes statistics by hand needs
       the same date the job will rank.

       It used to be spelled "yesterday" in seven places, which agreed with
       the job only because the job said yesterday too. Run on a Sunday both
       were wrong together - and then the job was fixed to follow the prices
       and the suite was the only thing still asking for a Saturday.

       Queried each time rather than cached: fixtures in the trend section add
       and remove price history, and a stale answer here would rank one date
       while the test asserted on another. */
    function asof_date() {
        return (string) scalar("select max(date) from historical_prices where exchange='XLON'");
    }

    /* Applies one of sql/*.sql, minus its closing report. The trailing
       selects leave an unbuffered result set behind and the next exec() dies
       on it; they change nothing, so they are skipped. Used to put a
       migration's definitions back after a test has replaced one of them. */
    function apply_migration($name) {
        $sql = (string) file_get_contents(dirname(__DIR__) . '/sql/' . $name);
        foreach (explode(';', preg_replace('/^\s*--.*$/m', '', $sql)) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '' && stripos($stmt, 'select') !== 0) { db()->exec($stmt); }
        }
    }

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
    /* The as of row is a trading day; the run row is a moment. They used to be
       told apart by being different dates, which held only because the as of
       date was the calendar day before the run. It is the last trading day
       now, so on a weekday with prices loaded for today the two share a date
       and the difference is the time of day: the marker is midnight because a
       trading day has no time of day, and the run row is when the job started.

       This is also the bug behind "Statistics Refreshed: 2026-11-14 09:12:03"
       on the dashboard - the marker carried the run's clock, so the page
       printed one date and another date's time as though they were one fact. */
    check('the as of row is a date and the run row is a timestamp',
          substr((string)$asof_row, 11) === '00:00:00'
          && substr((string)$run_row, 11) !== '00:00:00',
          "run=$run_row asof=$asof_row");

    /* The regression this guards: readers must resolve the as of marker, not the
       run row. Resolving the run row lands a day ahead of the data and empties
       the dashboard. */
    $resolved   = scalar("select date(max(job_date)) from jobs where job_name='get_statistics_asof'");
    $score_date = scalar("select max(date) from statistics where indicator='momentum_score'");
    check('the as of marker matches the date the scores were written at',
          $resolved !== false && $resolved === $score_date, "resolved=$resolved scores=$score_date");
    /* Named here so that a job calculating for a different date than the one
       the fixtures are written at says so, rather than surfacing three
       sections later as an empty array in some unrelated assertion. */
    check('the job calculates for the date the fixtures are written at',
          $resolved === asof_date(),
          "job says $resolved, fixtures are written at " . asof_date());

    $wrong = scalar("select max(job_date) from jobs where job_name='get_statistics'");
    check('resolving the run row instead would miss the scores (regression guard)',
          (int)scalar("select count(*) from statistics where date=?", [$wrong]) === 0,
          "the run row $wrong matched statistics rows");

    /* get_statistics_asof carries the as of date itself, so subtracting a day
       from it lands a day before the statistics and returns nothing. The quote
       page's momentum, growth and value panels, the relative valuation tables
       and the Piotroski and Altman variables all did that. */
    $offset = [];
    foreach (glob(dirname(__DIR__) . '/{includes,public}/*.php', GLOB_BRACE) as $file) {
        foreach (explode("\n", (string)file_get_contents($file)) as $n => $line) {
            if (strpos($line, 'get_statistics_asof') !== false
                && preg_match('/INTERVAL\s+[1-9]\d*\s+DAY/i', $line)) {
                $offset[] = basename($file) . ':' . ($n + 1);
            }
        }
    }
    check('nothing offsets the as of marker by a day', count($offset) === 0,
          implode(', ', $offset));

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

    echo "\n--- EODHD quote adapter ---\n";

    /* lookup() reads $json["data"][0]["close"], so the adapter has to produce
       that envelope from EODHD's flat quote object. */
    $rows = eodhd_quote_to_rows(['code' => 'VOD.LSE', 'close' => 72.5, 'timestamp' => 1757260800], 'VOD');
    check('a valid quote maps to the envelope lookup expects',
          count($rows) === 1 && $rows[0]['symbol'] === 'VOD' && (float)$rows[0]['close'] === 72.5);

    /* EODHD reports "NA" rather than null for a field it has no value for.
       Treating that as a price would write the string into historical_prices. */
    check('a close of "NA" is rejected rather than treated as a price',
          eodhd_quote_to_rows(['code' => 'XXX.LSE', 'close' => 'NA'], 'XXX') === []);
    check('a quote with no close is rejected', eodhd_quote_to_rows(['code' => 'XXX.LSE'], 'XXX') === []);
    check('a non-array response is rejected', eodhd_quote_to_rows(null, 'XXX') === []);
    check('an empty-string close is rejected', eodhd_quote_to_rows(['close' => ''], 'XXX') === []);

    echo "\n--- provider_field indicator mapping ---\n";

    db()->exec("truncate stock_info");
    db()->exec("delete from screen_indicators where name in ('pe','roe_ttm','shareholder_yield')");
    db()->exec("insert into screen_indicators (name,provider_field,description,enabled,order_number) values
        ('pe','Valuation.TrailingPE','pe','Y',1),
        ('roe_ttm','Highlights.ReturnOnEquityTTM','roe_ttm','Y',2),
        ('shareholder_yield',null,'shareholder_yield','Y',3)");
    db()->exec("insert into stock_info (symbol,asofdate,attribute,value) values
        ('AAA','2026-01-01','Valuation.TrailingPE','12.5'),
        ('AAA','2026-01-01','Highlights.ReturnOnEquityTTM','0.185'),
        ('AAA','2026-01-01','shareholder_yield','0.04'),
        ('AAA','2026-01-01','Valuation.PriceSalesTTM','1.9')");

    /* The query get_api_stats.py runs. It must return the application's own key
       from screen_indicators.name, never the provider's field name, because the
       result is written to statistics.indicator and the scoring SQL in
       get_statistics.php matches on those names. */
    $mapped = rows("select sti.symbol, sci.name, sti.value
                    from screen_indicators sci, stock_info sti
                    where sci.enabled='Y'
                      and sti.attribute = coalesce(nullif(sci.provider_field,''), sci.name)
                      and sti.symbol='AAA' and sti.asofdate='2026-01-01'
                    order by order_number");
    $by_name = [];
    foreach ($mapped as $r) { $by_name[$r['name']] = $r['value']; }

    check('a provider field maps to the application indicator name',
          isset($by_name['pe']) && (float)$by_name['pe'] === 12.5,
          json_encode($by_name));
    check('a second provider field maps correctly',
          isset($by_name['roe_ttm']) && (float)$by_name['roe_ttm'] === 0.185);
    check('an indicator with no provider_field still matches on name',
          isset($by_name['shareholder_yield']) && (float)$by_name['shareholder_yield'] === 0.04);
    check('the provider field name is never returned as the indicator',
          !isset($by_name['Valuation.TrailingPE']) && !isset($by_name['Highlights.ReturnOnEquityTTM']));
    check('an unmapped attribute is ignored',
          !in_array('Valuation.PriceSalesTTM', array_keys($by_name), true) && count($by_name) === 3,
          json_encode(array_keys($by_name)));

    /* The regression this guards: matching on name alone, as the loader did
       before provider_field existed, finds nothing once the provider's field
       names differ from the application's. */
    $old_way = rows("select sci.name from screen_indicators sci, stock_info sti
                     where sci.enabled='Y' and sci.name = sti.attribute
                       and sti.symbol='AAA' and sti.asofdate='2026-01-01'");
    check('matching on name alone would miss the mapped indicators (regression guard)',
          count($old_way) === 1, 'got ' . count($old_way) . ' rows, expected only shareholder_yield');

    /* shareholder_yield stood in above for an indicator with no
       provider_field, and the bare row that replaced it has none of the
       settings sql/shareholder_yield.sql gives it. Later sections rank on it,
       so the migration's definition goes back rather than being restated
       here, where it would drift. */
    apply_migration('shareholder_yield.sql');
    check('the shareholder yield definition is back as the migration leaves it',
          scalar("select screen_function from screen_indicators where name='shareholder_yield'")
          === 'calc_shareholder_yield');

    echo "\n--- paper execution: sizing and costs ---\n";

    require_once($INCLUDES . '/strategy_functions.php');

    $STRATEGY = 'test_top5';
    $ASOF     = null;   // set below to a date the seeded price history covers

    function paper_reset($cash = 100000) {
        global $STRATEGY;
        db()->exec("delete from strategy_orders where strategy='$STRATEGY'");
        db()->exec("delete from strategy_positions where strategy='$STRATEGY'");
        db()->exec("delete from strategy_targets where strategy='$STRATEGY'");
        db()->exec("delete from strategy_accounts where strategy='$STRATEGY'");
        db()->exec("insert into strategy_accounts (strategy,cash,currency,mode,enabled,created_at)
                    values ('$STRATEGY',$cash,'GBP','PAPER','Y',now())");
    }
    function paper_targets($as_of, $symbols) {
        global $STRATEGY;
        db()->exec("delete from strategy_targets where strategy='$STRATEGY' and as_of_date='$as_of'");
        $weight = round(1.0 / count($symbols), 6);
        foreach ($symbols as $symbol) {
            db()->exec("insert into strategy_targets (strategy,as_of_date,symbol,exchange,target_weight,created_at)
                        values ('$STRATEGY','$as_of','$symbol','XLON',$weight,now())");
        }
    }
    function paper_run($as_of, $env = []) {
        global $STRATEGY;
        return run_script('run_paper_execution.php', [$STRATEGY, $as_of], $env);
    }

    /* Pick a date with at least one further session after it, so fills have a
       next price to use. */
    /* DISTINCT matters: there is one row per symbol per day, so without it an
       offset walks rows of the same date and lands on the last session, which
       has nothing after it to fill against. */
    $ASOF = scalar("select distinct date from historical_prices where exchange='XLON'
                    order by date desc limit 1 offset 5");
    $SYMBOLS = ['AAA','BBB','CCC','DDD','EEE'];

    paper_reset();
    paper_targets($ASOF, $SYMBOLS);
    paper_run($ASOF);

    $filled = (int)scalar("select count(*) from strategy_orders where strategy=? and status='FILLED'", [$STRATEGY]);
    $rejected = (int)scalar("select count(*) from strategy_orders where strategy=? and status='REJECTED'", [$STRATEGY]);
    /* Sizing must reserve dealing costs. Sizing five positions at a full 20%
       of the account leaves nothing for stamp duty, spread and commission, so
       the last order is rejected for want of cash and the book silently runs
       one name short. */
    check('an all-cash rebalance fills every target', $filled === 5 && $rejected === 0,
          "filled=$filled rejected=$rejected");
    check('cash after a full rebalance is small but not negative',
          (float)scalar("select cash from strategy_accounts where strategy=?", [$STRATEGY]) >= 0);

    /* Buys pay stamp duty, sells do not. */
    $buy = rows("select stamp_duty, commission, slippage, consideration, total_cost
                 from strategy_orders where strategy=? and side='BUY' and status='FILLED' limit 1", [$STRATEGY])[0];
    check('a buy pays 0.5% stamp duty',
          abs((float)$buy['stamp_duty'] - (float)$buy['consideration'] * 0.005) < 0.01);
    check('a buy costs more than its consideration',
          (float)$buy['total_cost'] < 0 && abs((float)$buy['total_cost']) > (float)$buy['consideration']);

    $costs = strategy_costs('SELL', 100, 10.0);
    check('a sell pays no stamp duty', (float)$costs['stamp_duty'] === 0.0);
    check('a sell returns less than its consideration',
          $costs['total_cost'] > 0 && $costs['total_cost'] < $costs['consideration']);

    /* An order decided on the as-of date cannot transact at that date's close. */
    $fill_dates = rows("select distinct fill_date from strategy_orders where strategy=? and status='FILLED'", [$STRATEGY]);
    check('fills happen after the as-of date, never at it',
          count($fill_dates) > 0 && $fill_dates[0]['fill_date'] > $ASOF,
          "as_of=$ASOF fill={$fill_dates[0]['fill_date']}");

    echo "\n--- paper execution: idempotency and crash recovery ---\n";

    $cash_before = scalar("select cash from strategy_accounts where strategy=?", [$STRATEGY]);
    $orders_before = (int)scalar("select count(*) from strategy_orders where strategy=?", [$STRATEGY]);
    paper_run($ASOF);
    $cash_after = scalar("select cash from strategy_accounts where strategy=?", [$STRATEGY]);
    $orders_after = (int)scalar("select count(*) from strategy_orders where strategy=?", [$STRATEGY]);
    /* Re-running values the account after costs, so targets come out a share or
       two lower and the correction is a SELL. If the order id included the
       side that SELL would be a new key and the re-run would quietly trade
       again, paying commission and stamp to shave a share off each holding. */
    check('re-running the same rebalance places no new orders', $orders_before === $orders_after,
          "$orders_before -> $orders_after");
    check('re-running the same rebalance does not move cash', $cash_before === $cash_after,
          "$cash_before -> $cash_after");

    /* The idempotency check above passes even with side back in the order id,
       because the minimum order value independently suppresses the one or two
       share correction. So test the invariant directly: one order per symbol
       per rebalance, whichever way it points. Without this, lowering
       STRATEGY_MIN_ORDER_VALUE would silently bring the double-trade back. */
    paper_reset();
    $first  = strategy_record_order($STRATEGY, $ASOF, ['symbol'=>'AAA','exchange'=>'XLON','side'=>'BUY','quantity'=>10]);
    $second = strategy_record_order($STRATEGY, $ASOF, ['symbol'=>'AAA','exchange'=>'XLON','side'=>'SELL','quantity'=>3]);
    check('one order per symbol per rebalance, regardless of side',
          $first !== null && $second === null,
          "first=" . var_export($first, true) . " second=" . var_export($second, true));
    check('the refused order left no second row',
          (int)scalar("select count(*) from strategy_orders where strategy=? and symbol='AAA'", [$STRATEGY]) === 1);

    paper_reset();
    paper_targets($ASOF, $SYMBOLS);
    paper_run($ASOF);
    $orders_after = (int)scalar("select count(*) from strategy_orders where strategy=?", [$STRATEGY]);

    /* Simulate dying after the order was recorded but before it was filled.
       That means the fill never happened at all, so the cash it consumed must
       be given back as well as the position removed - otherwise the resume is
       correctly refused for want of cash and the test proves nothing.
       total_cost is negative for a buy, so subtracting it restores the cash. */
    db()->exec("update strategy_accounts a, strategy_orders o
                   set a.cash = a.cash - o.total_cost
                 where a.strategy = o.strategy and o.strategy='$STRATEGY'
                   and o.symbol='CCC' and o.status='FILLED'");
    db()->exec("update strategy_orders set status='PENDING', fill_date=null, fill_price=null, total_cost=null
                where strategy='$STRATEGY' and symbol='CCC'");
    db()->exec("update strategy_positions set quantity=0 where strategy='$STRATEGY' and symbol='CCC'");
    paper_run($ASOF);
    check('a pending order left by a crashed run is resumed',
          scalar("select status from strategy_orders where strategy=? and symbol='CCC'", [$STRATEGY]) === 'FILLED');
    check('resuming does not create a duplicate order',
          (int)scalar("select count(*) from strategy_orders where strategy=?", [$STRATEGY]) === $orders_after);
    check('the resumed order restores the position',
          (int)scalar("select quantity from strategy_positions where strategy=? and symbol='CCC'", [$STRATEGY]) > 0);

    echo "\n--- paper execution: refusing to trade ---\n";

    $switch = sys_get_temp_dir() . '/sharemanager_test_stop_trading';
    touch($switch);
    [$out] = paper_run($ASOF, ['STRATEGY_KILL_SWITCH_FILE' => $switch]);
    unlink($switch);
    check('the kill switch halts the run', stripos($out, 'HALTED') !== false, $out);

    db()->exec("update strategy_positions set quantity=-5 where strategy='$STRATEGY' and symbol='AAA'");
    [$out] = paper_run($ASOF);
    check('a negative position blocks trading', stripos($out, 'RECONCILIATION FAILED') !== false, $out);
    db()->exec("update strategy_positions set quantity=1 where strategy='$STRATEGY' and symbol='AAA'");

    /* A weight over the cap must abort the whole rebalance, not be trimmed. */
    paper_reset();
    db()->exec("insert into strategy_targets (strategy,as_of_date,symbol,exchange,target_weight,created_at)
                values ('$STRATEGY','$ASOF','AAA','XLON',0.9,now())");
    [$out] = paper_run($ASOF);
    check('a target over the position limit is refused',
          stripos($out, 'exceeds the') !== false
          && (int)scalar("select count(*) from strategy_orders where strategy=?", [$STRATEGY]) === 0, $out);

    /* Deploying idle cash is all buys and no sells. Measuring turnover as gross
       traded value would score that 100% and block every new account; the
       standard min(buys,sells) scores it zero. */
    paper_reset();
    paper_targets($ASOF, $SYMBOLS);
    $plan = strategy_plan_orders($STRATEGY, $ASOF);
    check('deploying cash is not blocked by the turnover limit',
          count($plan['orders']) === 5 && count($plan['errors']) === 0,
          json_encode($plan['errors']));

    /* Adjustments too small to be worth the dealing costs are skipped. */
    paper_run($ASOF);
    $before = (int)scalar("select count(*) from strategy_orders where strategy=?", [$STRATEGY]);
    db()->exec("update strategy_positions set quantity=quantity-1 where strategy='$STRATEGY' and symbol='AAA'");
    paper_targets($ASOF . '', $SYMBOLS);
    $plan = strategy_plan_orders($STRATEGY, $ASOF);
    $tiny = 0;
    foreach ($plan['orders'] as $o) { if ($o['quantity'] * $o['reference_price'] < 250) { $tiny++; } }
    check('orders below the minimum value are not planned', $tiny === 0,
          json_encode($plan['orders']));

    /* Long-only: the paper broker must never go short. */
    paper_reset();
    db()->exec("insert into strategy_orders (client_order_id,strategy,as_of_date,symbol,exchange,side,quantity,status,created_at)
                values ('short-test','$STRATEGY','$ASOF','AAA','XLON','SELL',999,'PENDING',now())");
    strategy_fill_order('short-test');
    check('selling more than is held is rejected, not shorted',
          scalar("select status from strategy_orders where client_order_id='short-test'") === 'REJECTED'
          && (int)scalar("select ifnull(sum(quantity),0) from strategy_positions where strategy=?", [$STRATEGY]) === 0);

    db()->exec("delete from strategy_orders where strategy='$STRATEGY'");
    db()->exec("delete from strategy_positions where strategy='$STRATEGY'");
    db()->exec("delete from strategy_targets where strategy='$STRATEGY'");
    db()->exec("delete from strategy_accounts where strategy='$STRATEGY'");

    echo "\n--- every PHP file parses ---\n";

    /* A syntax sweep, because PHP only reports a parse error when something
       actually includes the file. PHPMailerAutoload.php declared a function
       named __autoload, which PHP 8 rejects at compile time even inside a
       branch that can never run, so the file would not parse at all and
       reset_passwd.php was dead - and nothing noticed, because no other page
       requires it. */
    $repo = dirname(__DIR__);
    $unparseable = [];
    $checked = 0;
    foreach (['includes', 'public', 'templates'] as $dir) {
        foreach (glob("$repo/$dir/*.php") as $file) {
            $checked++;
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $lint, $rc);
            if ($rc !== 0) { $unparseable[] = basename($dir) . '/' . basename($file); }
            $lint = [];
        }
    }
    /* templates/criteria.php is a stale duplicate of public/criteria.php: a full
       page sitting in the templates directory, with an unmatched brace, that
       nothing renders. Pinned rather than excluded, so a NEW unparseable file
       fails here, and so does fixing or deleting this one - at which point
       remove it from the list. */
    $known_broken = ['templates/criteria.php'];
    sort($unparseable);
    sort($known_broken);
    check("all $checked PHP files parse under PHP " . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION
          . ' (except ' . count($known_broken) . ' known)',
          $unparseable === $known_broken,
          'unparseable now: ' . (implode(', ', $unparseable) ?: 'none')
          . ' | expected: ' . implode(', ', $known_broken));

    echo "\n--- no library file emits anything of its own ---\n";

    /* includes/constants.php had a blank line after its closing "?>". PHP
       swallows exactly one newline after the tag and emits every byte after
       that, so the second newline was written to the front of every single
       response - harmless in HTML, fatal in an image, where a byte before the
       PNG header is a malformed file and the browser shows a broken icon. The
       graph endpoints were the visible symptom; the cause was in a file that
       has nothing to do with graphs.

       Checked with PHP's own tokenizer rather than by searching for the last
       "?>": class.phpmailer.php contains "?>" inside a regular expression
       literal 107KB before the end of the file, so a string search finds the
       wrong one and reads the rest of the library as trailing output.

       The rule is about whitespace, not about markup. Several files in
       includes/ are HTML fragments whose whole job is to emit markup after the
       tag; what none of them may have is stray blank lines, which are never
       deliberate and always leak.

       The tokenizer counts the newline PHP swallows as part of the close tag,
       so a whitespace-only trailing token is exactly the bytes that reach the
       browser: "?>\\n" produces no trailing token at all, "?>\\n\\n" produces one
       holding a single newline, and that newline is the byte that broke the
       images. Any whitespace tail at all is therefore a leak. */
    $leaking = [];
    foreach (glob(dirname(__DIR__) . '/includes/*.php') as $file) {
        $tokens = token_get_all((string)file_get_contents($file));
        $last = end($tokens);
        if (!is_array($last) || $last[0] !== T_INLINE_HTML) { continue; }
        $tail = $last[1];
        /* Markup is the file's purpose; whitespace is an accident. */
        if (trim($tail) !== '') { continue; }
        if ($tail !== '') {
            $leaking[] = basename($file) . ' (' . strlen($tail) . ' bytes: '
                       . str_replace(["\n", "\r", "\t"], ['\n', '\r', '\t'], $tail) . ')';
        }
    }
    check('no file in includes/ emits whitespace after its final ?>',
          count($leaking) === 0, implode(', ', $leaking));

    echo "\n--- EODHD settings degrade instead of fatalling ---\n";

    check('the api key accessor exists', function_exists('eodhd_api_key'));
    check('the exchange accessor exists', function_exists('eodhd_exchange'));
    check('the exchange defaults to LSE', eodhd_exchange() === 'LSE');

    /* public/constants.php is gitignored, so a deployment can be running a
       constants.php that predates these constants. Referencing them directly
       made that a fatal on every page touching a quote. */
    $stub = sys_get_temp_dir() . '/sm_no_eodhd_' . getmypid();
    @mkdir($stub);
    file_put_contents("$stub/constants.php", "<?php\n"
        . "define('DATABASE','" . DATABASE . "'); define('SERVER','" . SERVER . "');\n"
        . "define('USERNAME','" . USERNAME . "'); define('PASSWORD','" . PASSWORD . "');\n"
        . "define('SMTP_HOST',''); define('SMTP_USERNAME',''); define('SMTP_PASSWORD','');\n"
        . "define('SMTP_PORT','587'); define('SITE_URL',''); define('DEBUG_LOG','N');\n");
    $probe = "$stub/probe.php";
    file_put_contents($probe, "<?php\n"
        . "require_once('functions.php');\n"
        . "echo 'key=[' . eodhd_api_key() . '] exchange=[' . eodhd_exchange() . ']';\n");
    exec('php -d include_path=' . escapeshellarg($stub . PATH_SEPARATOR . $repo . '/includes')
         . ' ' . escapeshellarg($probe) . ' 2>&1', $probe_out, $probe_rc);
    $probe_text = implode(' ', $probe_out);
    check('a constants.php without the EODHD settings does not fatal',
          $probe_rc === 0 && stripos($probe_text, 'error') === false, $probe_text);
    check('the missing key reads as empty, the documented unavailable state',
          strpos($probe_text, 'key=[]') !== false, $probe_text);
    check('the exchange still falls back to LSE',
          strpos($probe_text, 'exchange=[LSE]') !== false, $probe_text);
    @unlink($probe); @unlink("$stub/constants.php"); @rmdir($stub);

    echo "\n--- the two market data providers ---\n";

    /* Both providers are supported and the choice is a setting. These cover the
       mapping each one needs; the HTTP calls are not exercised, so no key and
       no network are needed. The FMP records are real VOD.L responses captured
       by python/fmp_probe.py. */

    check('the provider defaults to eodhd when unset or nonsense',
          data_provider() === 'eodhd');

    /* Each provider spells a London ticker differently, and stock_symbols holds
       a third spelling (the MIC). Getting this wrong asks for a symbol the
       provider has never heard of and the page silently shows the last close. */
    check('a bare code gets the suffix the selected provider wants',
          provider_symbol('VOD') === 'VOD.LSE');
    check('an already-suffixed code is left alone',
          provider_symbol('VOD.L') === 'VOD.L');

    $fmp_quote = [[
        "symbol" => "VOD.L", "name" => "Vodafone Group Plc", "price" => 116.95,
        "yearHigh" => 132.072, "yearLow" => 88.398, "marketCap" => 27095677700,
        "exchange" => "LSE", "previousClose" => 118.05, "timestamp" => 1794588534,
    ]];
    $rows = fmp_quote_to_rows($fmp_quote, 'VOD');
    check('an FMP quote yields one row for the bare symbol',
          count($rows) === 1 && $rows[0]['symbol'] === 'VOD');
    check('the price comes through as the close',
          $rows[0]['close'] === 116.95);
    check('the timestamp becomes a date',
          $rows[0]['date'] === date('Y-m-d', 1794588534));

    /* FMP answers an unknown ticker with an empty list rather than an error.
       Read as a quote that would be a price of zero. */
    check('an empty FMP list is no quote, not a zero price',
          fmp_quote_to_rows([], 'VOD') === []);
    check('an FMP error object is no quote',
          fmp_quote_to_rows(["Error Message" => "Invalid API KEY."], 'VOD') === []);
    check('a non-numeric FMP price is rejected',
          fmp_quote_to_rows([["symbol" => "VOD.L", "price" => "NA"]], 'VOD') === []);

    /* EODHD's own mapping must keep working: both providers are supported. */
    check('an EODHD quote still maps',
          count(eodhd_quote_to_rows(["close" => 101.5, "timestamp" => 1794588534], 'VOD')) === 1);
    check('EODHD "NA" is still rejected',
          eodhd_quote_to_rows(["close" => "NA"], 'VOD') === []);

    /* The price series arrives flat from both providers today, and nested from
       FMP's older shape. An unreadable body has to be distinguishable from a
       symbol that simply has no prices: they get different counters. */
    $flat = [["date" => "2026-09-30", "close" => 122.7], ["date" => "2026-09-29", "close" => 124.1]];
    check('a flat price series passes through',
          provider_prices_to_rows($flat) === $flat);
    check('a series nested under historical is unwrapped',
          provider_prices_to_rows(["symbol" => "VOD.L", "historical" => $flat]) === $flat);
    check('an empty series is no prices, not a failure',
          provider_prices_to_rows([]) === []);
    check('an error body is a failure, not an empty series',
          provider_prices_to_rows(["Error Message" => "Legacy Endpoint"]) === null);
    check('a non-array body is a failure',
          provider_prices_to_rows("not json") === null);

    echo "\n--- dead pages stay out of the docroot ---\n";

    /* public/get_share_prices.php was a 2023 copy that nothing linked to and
       that answered 500, failing to require constants.php. screening.php read
       price_momentum, the legacy table calc_momentum stopped writing to, and
       rendered templates/screen.php with variables that template does not use.
       Both are archived; anything in public/ is a URL somebody can reach. */
    foreach (['get_share_prices.php', 'screening.php'] as $gone) {
        check("public/$gone is not served",
              !file_exists(dirname(__DIR__) . "/public/$gone"));
        check("$gone is kept in the archive",
              file_exists(dirname(__DIR__) . "/archive/public/$gone"));
    }

    echo "\n--- a negative valuation ratio must not rank as the cheapest ---\n";

    /* pe, price_sales_ratio and price_book_ratio rank value ASC, so negatives
       sorted first and scored as the best value in the index: on the live
       FTSE 350 for 2026-11-13 the six best P/E percentiles were all negative,
       Spire Healthcare at -527 and Eurowag at -257, and Hg Capital Trust
       reached value_score 99 on negative book value. */

    reset_calculated();
    db()->exec("delete from screen_indicators where name='pe'");
    db()->exec("insert into screen_indicators
                  (name, description, enabled, order_number, calc_rank, rank_zero,
                   rank_order, rank_negative)
                values ('pe','Price / earnings','Y',1,'Y','N','value ASC','EXCLUDE')");

    $asof = asof_date();
    $pes = ['AAA' => -527.0, 'BBB' => -3.0, 'CCC' => 5.0, 'DDD' => 12.0, 'EEE' => 40.0];
    foreach ($pes as $sym => $v) {
        db()->prepare("insert into statistics (symbol,date,indicator,value,exchange)
                       values (?,?,'pe',?,'XLON')")->execute([$sym, $asof, $v]);
    }
    run_script('get_statistics.php');

    $pct = [];
    foreach (array_keys($pes) as $sym) {
        $pct[$sym] = scalar("select percentile from statistics
                              where symbol=? and indicator='pe' and date=?", [$sym, $asof]);
    }

    /* The headline requirement: a negative P/E must not outrank a positive one. */
    /* Guarded: if nothing was ranked at all, min() of an empty list is a fatal
       and the suite stops with a PHP error instead of a failing check. */
    $ranked_positives = array_filter([$pct['CCC'], $pct['DDD'], $pct['EEE']],
                                     fn($p) => $p !== null);
    check('the positives were ranked at all, so the comparison below means something',
          count($ranked_positives) === 3,
          'ranked ' . count($ranked_positives) . ' of 3 for ' . $asof);
    $worst_positive = count($ranked_positives) ? min($ranked_positives) : null;
    $negatives_above = array_filter(['AAA' => $pct['AAA'], 'BBB' => $pct['BBB']],
                                    fn($p) => $p !== null && $worst_positive !== null
                                              && $p > $worst_positive);
    check('a negative P/E does not rank above a positive one',
          count($negatives_above) === 0,
          'negatives ' . json_encode($negatives_above) . ' beat ' . $worst_positive);

    /* EXCLUDE means unranked, so the score queries use their neutral 50
       rather than treating a loss-maker as either cheap or expensive. */
    check('an excluded negative is left unranked rather than scored',
          $pct['AAA'] === null && $pct['BBB'] === null,
          'AAA=' . var_export($pct['AAA'], true) . ' BBB=' . var_export($pct['BBB'], true));
    check('the cheapest positive still ranks best',
          $pct['CCC'] !== null && $pct['CCC'] >= $pct['EEE'],
          "CCC={$pct['CCC']} EEE={$pct['EEE']}");

    /* LAST is the alternative: ranked, but behind everything non-negative. */
    db()->exec("update screen_indicators set rank_negative='LAST' where name='pe'");
    db()->exec("update statistics set percentile=null, stat_rank=null where indicator='pe'");
    run_script('get_statistics.php');
    $last = [];
    foreach (array_keys($pes) as $sym) {
        $last[$sym] = scalar("select percentile from statistics
                               where symbol=? and indicator='pe' and date=?", [$sym, $asof]);
    }
    check('LAST ranks negatives behind every positive',
          $last['AAA'] !== null && $last['BBB'] !== null
          && max($last['AAA'], $last['BBB']) < min($last['CCC'], $last['DDD'], $last['EEE']),
          json_encode($last));

    /* NORMAL is still available, and is what every other indicator uses. */
    db()->exec("update screen_indicators set rank_negative='NORMAL' where name='pe'");
    db()->exec("update statistics set percentile=null, stat_rank=null where indicator='pe'");
    run_script('get_statistics.php');
    $normal = scalar("select percentile from statistics
                       where symbol='AAA' and indicator='pe' and date=?", [$asof]);
    check('NORMAL leaves the old behaviour reachable', $normal !== null);

    /* rank_order is interpolated into an ORDER BY, and initial_data.sql set it
       to 1, which is not a sort expression. */
    db()->exec("update screen_indicators set rank_order='1; drop table statistics' where name='pe'");
    db()->exec("update statistics set percentile=null, stat_rank=null where indicator='pe'");
    [$out_bad, ] = run_script('get_statistics.php');
    check('an unusable rank_order is refused rather than interpolated',
          (int)scalar("select count(*) from information_schema.tables
                        where table_schema=? and table_name='statistics'", [DATABASE]) === 1
          && stripos($out_bad, 'Fatal error') === false);
    db()->exec("update screen_indicators set rank_order='value ASC' where name='pe'");

    echo "\n--- a re-run clears the ranks it is about to replace ---\n";

    /* The rank write is an UPDATE of the rows the ranking query returns, so a
       row the query stops returning keeps whatever it was given last time.
       That is exactly the rows EXCLUDE drops. On the N100 the negatives had
       already been ranked under NORMAL, so after switching to EXCLUDE they
       kept their old percentiles and a negative P/E went on scoring as the
       cheapest share in the index - the fix was in place and looked like it
       had done nothing.

       The sequence below is the one that happened: rank once with negatives
       included, switch to EXCLUDE, rank again. Nothing clears the table in
       between, because in production nothing does. */
    reset_calculated();
    db()->exec("delete from screen_indicators where name='pe'");
    db()->exec("insert into screen_indicators
                  (name, description, enabled, order_number, calc_rank, rank_zero,
                   rank_order, rank_negative)
                values ('pe','Price / earnings','Y',1,'Y','N','value ASC','NORMAL')");

    $asof2 = asof_date();
    $pes2 = ['AAA' => -527.0, 'BBB' => -3.0, 'CCC' => 5.0, 'DDD' => 12.0, 'EEE' => 40.0];
    foreach ($pes2 as $sym => $v) {
        db()->prepare("insert into statistics (symbol,date,indicator,value,exchange)
                       values (?,?,'pe',?,'XLON')")->execute([$sym, $asof2, $v]);
    }

    run_script('get_statistics.php');
    $before = scalar("select percentile from statistics
                       where symbol='AAA' and indicator='pe' and date=?", [$asof2]);
    check('a negative ranks under NORMAL, so there is a stale value to leave behind',
          $before !== null, 'AAA percentile was ' . var_export($before, true));

    db()->exec("update screen_indicators set rank_negative='EXCLUDE' where name='pe'");
    run_script('get_statistics.php');

    $after = [];
    foreach (array_keys($pes2) as $sym) {
        $after[$sym] = scalar("select percentile from statistics
                                where symbol=? and indicator='pe' and date=?", [$sym, $asof2]);
    }
    $after_rank = scalar("select stat_rank from statistics
                           where symbol='AAA' and indicator='pe' and date=?", [$asof2]);

    check('switching to EXCLUDE clears the percentile the earlier run left',
          $after['AAA'] === null && $after['BBB'] === null,
          'AAA=' . var_export($after['AAA'], true) . ' BBB=' . var_export($after['BBB'], true)
          . ' (was ' . var_export($before, true) . ')');
    check('switching to EXCLUDE clears the stale stat_rank too',
          $after_rank === null, 'AAA stat_rank=' . var_export($after_rank, true));
    check('the rows that are still ranked keep getting a percentile',
          $after['CCC'] !== null && $after['DDD'] !== null && $after['EEE'] !== null,
          json_encode($after));

    /* The same re-run must be stable: ranking twice with no change in between
       must not move a percentile, which it would if the clear removed a value
       the ranking query then failed to put back. */
    $twice_before = scalar("select percentile from statistics
                             where symbol='CCC' and indicator='pe' and date=?", [$asof2]);
    run_script('get_statistics.php');
    $twice_after = scalar("select percentile from statistics
                            where symbol='CCC' and indicator='pe' and date=?", [$asof2]);
    check('ranking twice in a row leaves the same percentile',
          $twice_before !== null && $twice_before === $twice_after,
          var_export($twice_before, true) . ' then ' . var_export($twice_after, true));

    echo "\n--- the ranking settings survive a rebuild ---\n";

    /* calc_rank was 'N' for the four provider indicators, so they never got a
       percentile and value_score and quality_score were 0 for every share.
       Set by hand on the live database; it has to be in the repo. */
    $fmp_sql = (string)file_get_contents(dirname(__DIR__) . '/sql/fmp_migration.sql');
    foreach (['pe', 'price_sales_ratio', 'price_book_ratio', 'roe_ttm'] as $ind) {
        check("the migration turns calc_rank on for $ind",
              preg_match("/calc_rank='Y'[^;]*'" . preg_quote($ind, '/') . "'/s", $fmp_sql) === 1);
    }
    check('the migration adds the rank_negative column',
          stripos($fmp_sql, 'add column if not exists rank_negative') !== false);
    check('the migration excludes negatives for the ASC valuation ratios',
          preg_match("/rank_negative='EXCLUDE'[^;]*price_book_ratio/s", $fmp_sql) === 1);

    /* initial_data.sql set calc_rank by indicator_id - 140, 141, 142 - which
       match nothing on a fresh install, so the momentum indicators never got
       a percentile there either. */
    $initial = (string)file_get_contents(dirname(__DIR__) . '/sql/initial_data.sql');
    check('initial_data.sql sets calc_rank by name, not by a hard-coded id',
          preg_match("/calc_rank='Y'[^;]*where\s+name='3mnth'/is", $initial) === 1
          && preg_match("/indicator_id`?='1(40|41|42)'/", $initial) !== 1);
    check('initial_data.sql gives rank_order a sort expression, not a number',
          preg_match("/rank_order\s*=\s*1\b/", $initial) !== 1);

    echo "\n--- the scores are safe to re-run, and all four get written ---\n";

    /* get_statistics.php inserted the four scores without clearing the date
       first, so a second run for one date appended a whole fresh set: two runs
       for 2026-11-13 left 700 momentum_score rows where there should be 350.
       And overall_score was never written at all, because its query joined
       screen_indicators on si.name = s.indicator while summing three scores
       that are not rows in that table. */

    reset_calculated();
    run_script('get_momentum_statistics.php');
    [$out1, ] = run_script('get_statistics.php');
    check('get_statistics.php completes on a first run',
          stripos($out1, 'Fatal error') === false, substr($out1, -300));

    $after_one = [];
    foreach (['momentum_score','value_score','quality_score','overall_score'] as $score) {
        $after_one[$score] = (int)scalar(
            "select count(*) from statistics where indicator=?", [$score]);
    }

    /* The bug the FTSE 350 load hit: the overall ranking silently produced
       nothing, so the dashboard's Overall Top Ten had no data to rank. */
    check('overall_score is written at all', $after_one['overall_score'] > 0,
          'rows: ' . var_export($after_one, true));
    /* value_score and quality_score need indicators this seed does not define -
       it carries only the three momentum ones - so they are legitimately zero
       here. What matters is that overall_score summed the component scores
       that do exist rather than coming back empty. */
    check('overall_score covers every symbol that has a component score',
          $after_one['overall_score'] === $after_one['momentum_score'],
          var_export($after_one, true));

    /* Running the same date twice must leave the same rows, not twice as many. */
    run_script('get_statistics.php');
    $duplicated = [];
    foreach ($after_one as $score => $before) {
        $now = (int)scalar("select count(*) from statistics where indicator=?", [$score]);
        if ($now !== $before) { $duplicated[] = "$score $before->$now"; }
    }
    check('re-running get_statistics.php does not duplicate any score',
          count($duplicated) === 0, implode(', ', $duplicated));

    $dupe_keys = (int)scalar(
        "select count(*) from (select symbol,date,indicator,exchange from statistics
          where indicator like '%\\_score' group by symbol,date,indicator,exchange
          having count(*)>1) d");
    check('no symbol has two rows for the same score on the same date',
          $dupe_keys === 0, "$dupe_keys duplicated keys");

    /* The value and quality queries legitimately join screen_indicators - they
       select real indicators, and quality filters on si.category - so the fix
       must not have removed those. */
    $stats_src = (string)file_get_contents(dirname(__DIR__) . '/includes/get_statistics.php');
    check('the quality query still joins screen_indicators for its category',
          preg_match('/category\s*=\s*10/', $stats_src) === 1
          && substr_count($stats_src, 'screen_indicators si') >= 2);

    echo "\n--- a moving average needs a full window ---\n";

    /* calc_moving_avg() divided the sum by $numDays however many prices came
       back, so a share with less history than the window got an average scaled
       down by the shortfall. 138 days of prices divided by 200 is an answer 31%
       too low, which against a trend screen is not a small error in the right
       direction: the average sits well below the price, so a newly listed share
       passes "close above its 200 day average" on arithmetic alone. One of the
       350 symbols on the live exchange (MNO, 138 days) is in that position.

       These four series are built by hand so the expected averages can be
       worked out on paper rather than read back out of the thing under test. */
    $trend_exchange = 'XLON';
    $trend_asof = asof_date();   // the date the job uses

    /* [symbol, [prices oldest..newest]]. Linear series, so the mean of any
       tail is just the mean of its ends. */
    $series = [];
    //Rising: 250 prices, 100..349. close 349, ma200 249.5, ma50 324.5.
    $series['TUP'] = array_map(fn($i) => 100.0 + $i, range(0, 249));
    //Falling: 250 prices, 349..100. close 100, ma200 199.5, ma50 124.5.
    $series['TDN'] = array_map(fn($i) => 349.0 - $i, range(0, 249));
    //A long decline with a spike on the last day only: the close clears the
    //200 day average while the 50 day average is still below it. This is the
    //share screen 1 holds and screen 2 refuses.
    $series['TMX'] = array_map(fn($i) => $i === 249 ? 400.0 : 300.0 - $i * 0.6, range(0, 249));
    //138 prices, the MNO case: enough for a 50 day average, not for a 200 day.
    $series['TSH'] = array_map(fn($i) => 100.0 + $i, range(0, 137));
    //20 prices: too short for either average.
    $series['TVS'] = array_map(fn($i) => 100.0 + $i, range(0, 19));

    $ins_sym = db()->prepare("insert into stock_symbols
                                (symbol,name,description,exchange,enabled,sector,market,industry_group,industry,logo)
                              values (?,?,?,?,'Y','Trend','Main Market','Trend','Trend',null)");
    $ins_px  = db()->prepare("insert into historical_prices (symbol,exchange,date,price) values (?,?,?,?)");

    db()->exec("delete from stock_symbols where sector='Trend'");
    db()->exec("delete from historical_prices where symbol in ('TUP','TDN','TMX','TSH','TVS')");
    foreach ($series as $sym => $prices) {
        $ins_sym->execute([$sym, "$sym Trend PLC", "$sym Trend PLC ordinary shares", $trend_exchange]);
        $n = count($prices);
        foreach ($prices as $i => $price) {
            /* Consecutive days ending on the as of date. The averages count
               rows rather than calendar days, so this keeps the window size
               and the arithmetic easy to check by hand. */
            $ins_px->execute([$sym, $trend_exchange,
                              date('Y-m-d', strtotime($trend_asof . ' -' . ($n - 1 - $i) . ' day')),
                              round($price, 4)]);
        }
    }

    /* The averages, computed in process. $_SESSION["exchange"] is what
       session_exchange() reads and every one of these queries filters on. */
    $saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = $trend_exchange;
    db()->exec("delete from statistics where indicator in ('close_price','ma50','ma200')");
    foreach (array_keys($series) as $sym) {
        close_price($trend_asof, $sym);
        ma50($trend_asof, $sym);
        ma200($trend_asof, $sym);
    }

    $stat = function ($sym, $ind) use ($trend_asof) {
        $v = scalar("select value from statistics where symbol=? and indicator=? and date=? and exchange='XLON'",
                    [$sym, $ind, $trend_asof]);
        return $v === false || $v === null ? null : (float)$v;
    };

    foreach ([['TUP', 349.0, 324.5, 249.5],
              ['TDN', 100.0, 124.5, 199.5],
              ['TMX', 400.0, 170.288, 211.547]] as [$sym, $close, $ma50, $ma200]) {
        check("$sym close is the latest price", abs($stat($sym, 'close_price') - $close) < 0.0005,
              'got ' . var_export($stat($sym, 'close_price'), true) . ' want ' . $close);
        check("$sym 50 day average is the mean of the last 50 prices",
              abs($stat($sym, 'ma50') - $ma50) < 0.001,
              'got ' . var_export($stat($sym, 'ma50'), true) . ' want ' . $ma50);
        check("$sym 200 day average is the mean of the last 200 prices",
              abs($stat($sym, 'ma200') - $ma200) < 0.001,
              'got ' . var_export($stat($sym, 'ma200'), true) . ' want ' . $ma200);
    }

    /* The fix. 138 prices is not a 200 day average at any price, and the old
       code would have written sum/200 = 116.265 against a close of 237, which
       reads as a share trading 104% above its long term trend. */
    check('a 138 day history gets no 200 day average at all',
          $stat('TSH', 'ma200') === null,
          'got ' . var_export($stat('TSH', 'ma200'), true));
    check('a 138 day history still gets a 50 day average, which it has the prices for',
          $stat('TSH', 'ma50') !== null && abs($stat('TSH', 'ma50') - 212.5) < 0.001,
          'got ' . var_export($stat('TSH', 'ma50'), true) . ' want 212.5');
    check('the short average it does get is not the scaled-down one',
          $stat('TSH', 'ma200') === null || abs($stat('TSH', 'ma200') - 116.265) > 0.001,
          'got ' . var_export($stat('TSH', 'ma200'), true));
    check('a 20 day history gets neither average',
          $stat('TVS', 'ma50') === null && $stat('TVS', 'ma200') === null,
          'ma50=' . var_export($stat('TVS', 'ma50'), true)
          . ' ma200=' . var_export($stat('TVS', 'ma200'), true));
    check('a 20 day history still gets a close, which is a real number',
          abs($stat('TVS', 'close_price') - 119.0) < 0.0005,
          'got ' . var_export($stat('TVS', 'close_price'), true));

    /* Re-running must not leave a stale value behind. A run that refuses to
       write is only idempotent if it also clears what an earlier run wrote,
       otherwise the bad value outlives the data that produced it. */
    db()->prepare("insert into statistics (symbol,indicator,value,date,exchange)
                   values ('TSH','ma200',116.265,?,'XLON')")->execute([$trend_asof]);
    ma200($trend_asof, 'TSH');
    check('a re-run clears a value an earlier run should not have written',
          $stat('TSH', 'ma200') === null,
          'got ' . var_export($stat('TSH', 'ma200'), true));

    echo "\n--- the nightly job calculates the trend indicators ---\n";

    /* Item 4 of the ask: the three indicators have to exist for the date
       build_screen() uses. get_momentum_statistics.php was the obvious place
       to look and is the wrong one - it steps through monthly as of dates
       (min_date + 3 months, then P1M), so its dates coincide with the daily
       screening date only by chance. get_statistics.php is the right one: it
       calculates every enabled indicator for yesterday and then records that
       same date as the get_statistics_asof job, which is exactly what
       build_screen() reads. Registering the three with a screen_function is
       therefore all that was needed, and this proves it rather than assuming
       it. */
    db()->exec("delete from statistics where indicator in ('close_price','ma50','ma200')");
    run_script('get_statistics.php');

    $job_asof = scalar("select date(max(job_date)) from jobs where job_name='get_statistics_asof'");
    check('the job records the as of date the screens read',
          $job_asof === $trend_asof, "job says $job_asof, expected $trend_asof");

    foreach (['close_price', 'ma50', 'ma200'] as $ind) {
        check("the nightly job writes $ind for that date",
              (int)scalar("select count(*) from statistics where indicator=? and date=?",
                          [$ind, $job_asof]) > 0);
    }
    check('the job calculates them without ranking them',
          (int)scalar("select count(*) from statistics
                        where indicator in ('close_price','ma50','ma200')
                          and (percentile is not null or stat_rank is not null)") === 0,
          'a price has no business carrying a percentile');

    /* Re-running the job must not duplicate rows: statistics has a non-unique
       index on (symbol, indicator, date, exchange), so nothing in the database
       would stop a second insert. */
    $before_rows = (int)scalar("select count(*) from statistics
                                 where indicator in ('close_price','ma50','ma200')");
    run_script('get_statistics.php');
    $after_rows = (int)scalar("select count(*) from statistics
                                where indicator in ('close_price','ma50','ma200')");
    check('re-running the job writes no duplicate rows',
          $before_rows === $after_rows && $before_rows > 0,
          "$before_rows rows then $after_rows");
    $dups = rows("select symbol, indicator, count(*) n from statistics
                   where indicator in ('close_price','ma50','ma200')
                   group by symbol, indicator, date, exchange having n > 1");
    check('no symbol has two values for one indicator and date',
          count($dups) === 0, json_encode(array_slice($dups, 0, 5)));

    echo "\n--- the two trend screens return the right shares ---\n";

    $screen1 = (int)scalar("select id from screen where name='Trend following (200-day)'");
    $screen2 = (int)scalar("select id from screen where name='Trend following (50/200)'");
    check('the migration created both screens', $screen1 > 0 && $screen2 > 0,
          "ids $screen1 and $screen2");

    /* The expected membership, worked out from historical_prices rather than
       from the statistics rows the job just wrote. This is the same sum the
       check figures came from, so if the screens and this disagree, one of the
       two is wrong and the test says which share. */
    $expect1 = $expect2 = [];
    $closest = PHP_FLOAT_MAX;
    foreach (rows("select symbol from stock_symbols where enabled='Y' and exchange='XLON' order by symbol") as $r) {
        $p = rows("select price from historical_prices
                    where symbol=? and exchange='XLON' and date<=? order by date desc limit 200",
                  [$r['symbol'], $trend_asof]);
        if (count($p) < 200) { continue; }
        $px    = array_map('floatval', array_column($p, 'price'));
        $close = $px[0];
        $ma200 = round(array_sum($px) / 200, 6);
        $ma50  = round(array_sum(array_slice($px, 0, 50)) / 50, 6);
        $closest = min($closest, abs($close - $ma200), abs($ma50 - $ma200));
        if ($close > $ma200) {
            $expect1[] = $r['symbol'];
            if ($ma50 > $ma200) { $expect2[] = $r['symbol']; }
        }
    }
    /* If anything sat within a rounding error of its average the comparison
       could fall either way and the test would be flaky rather than wrong. */
    check('no share sits on the knife edge of its own average, so the sets are definite',
          $closest > 0.01, 'closest margin ' . $closest);

    $flatten = function ($screen_id) {
        $out = [];
        foreach (build_screen($screen_id, null) as $row) {
            $out[] = is_array($row) ? ($row['symbol'] ?? reset($row)) : $row;
        }
        sort($out);
        return array_values(array_unique($out));
    };
    sort($expect1); sort($expect2);

    $got1 = $flatten($screen1);
    $got2 = $flatten($screen2);

    check('the 200-day screen returns exactly the shares above their 200 day average',
          $got1 === $expect1,
          'got ' . json_encode($got1) . ' expected ' . json_encode($expect1));
    check('the 50/200 screen returns exactly the shares that also have ma50 above ma200',
          $got2 === $expect2,
          'got ' . json_encode($got2) . ' expected ' . json_encode($expect2));

    /* The hand built series, named individually: a set comparison passes if
       both sides are wrong in the same way, and these are the four cases the
       rule is actually about. */
    check('a share in an uptrend is held by both screens',
          in_array('TUP', $got1, true) && in_array('TUP', $got2, true));
    check('a share below its 200 day average is dropped by both - the sell signal',
          !in_array('TDN', $got1, true) && !in_array('TDN', $got2, true));
    check('a share above its 200 day average but with ma50 below it is held only by the 200-day screen',
          in_array('TMX', $got1, true) && !in_array('TMX', $got2, true));
    check('a share with too little history is in neither screen, not falsely in both',
          !in_array('TSH', $got1, true) && !in_array('TSH', $got2, true));
    check('the 50/200 screen is a subset of the 200-day screen',
          count(array_diff($got2, $got1)) === 0,
          'only in 50/200: ' . json_encode(array_values(array_diff($got2, $got1))));

    /* The second screen is a filter, so it must actually remove something,
       otherwise the two screens are the same screen and the test above is
       satisfied by a tautology. */
    check('the 50/200 screen is stricter than the 200-day screen',
          count($got2) < count($got1), count($got1) . ' vs ' . count($got2));

    //Leave the universe as it was found.
    db()->exec("delete from stock_symbols where sector='Trend'");
    db()->exec("delete from historical_prices where symbol in ('TUP','TDN','TMX','TSH','TVS')");
    db()->exec("delete from statistics where symbol in ('TUP','TDN','TMX','TSH','TVS')");
    if ($saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $saved_exchange; }

    echo "\n--- investment funds are left out of the value, quality and overall rankings ---\n";

    /* An investment trust owns other companies' shares, so its P/E is its
       holdings' earnings seen through a share price and a discount, and its
       price/book is the discount to net asset value written as a ratio.
       Ranked against operating companies they are noise: on the live FTSE 350
       for 2026-11-13 three of the ten best overall scores and two of the ten
       best value scores were trusts, and Hg Capital Trust reached value_score
       99 on negative book value.

       Which sectors are funds is data (fund_sectors) and which indicators
       funds are ranked on is data too (screen_indicators.rank_funds), both
       from sql/fund_sectors.sql. Momentum is untouched throughout: a price
       series is a price series whoever owns the assets behind it. */

    $fund_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = 'XLON';

    $fund_sym = 'LLL';   // moved in and out of a fund sector below
    $fund_reit = 'KKK';  // a REIT: an operating property company, ranked as normal

    /* pe feeds the value score, roe_ttm the quality score (category 10). Both
       are set rank_funds='N' the way the migration sets them on the live
       database. */
    db()->exec("delete from screen_indicators where name in ('pe','roe_ttm')");
    db()->exec("insert into screen_indicators
                  (name, description, enabled, order_number, calc_rank, rank_zero,
                   rank_order, rank_negative, rank_funds, category)
                values ('pe','Price / earnings','Y',1,'Y','N','value ASC','EXCLUDE','N',12),
                       ('roe_ttm','Return on equity','Y',1,'Y','N','value DESC','NORMAL','N',10)");

    /* The value score is built from category 12 with calc_rank='Y' and the
       quality score from category 10, so an indicator written by hand needs
       the category the migration would have given it, or it is in neither. */

    db()->exec("update stock_symbols set sector='Real Estate' where symbol='$fund_reit'");

    $fund_asof = asof_date();

    /* The fund is the cheapest P/E and the best return on equity of the lot,
       so leaving it in changes both the order and the denominator. A test
       where it made no difference would pass whatever the code did. */
    $fund_pe  = ['AAA' => 5.0, 'BBB' => 10.0, 'CCC' => 15.0, 'DDD' => 20.0,
                 'EEE' => 25.0, 'HHH' => 40.0, $fund_reit => 30.0, $fund_sym => 1.0];
    $fund_roe = ['AAA' => 5.0, 'BBB' => 10.0, 'CCC' => 15.0, 'DDD' => 20.0,
                 'EEE' => 25.0, 'HHH' => 40.0, $fund_reit => 30.0, $fund_sym => 99.0];

    /* Runs the nightly job over a universe where $sector is $fund_sym's sector
       and $include_fund says whether the fund has figures at all, then reports
       the percentiles the companies were given. */
    $fund_run = function ($sector, $include_fund = true) use ($fund_pe, $fund_roe, $fund_asof, $fund_sym) {
        reset_calculated();
        db()->prepare("update stock_symbols set sector=? where symbol=?")
            ->execute([$sector, $fund_sym]);
        $ins = db()->prepare("insert into statistics (symbol,date,indicator,value,exchange)
                              values (?,?,?,?,'XLON')");
        foreach (['pe' => $fund_pe, 'roe_ttm' => $fund_roe] as $ind => $values) {
            foreach ($values as $sym => $v) {
                if (!$include_fund && $sym === $fund_sym) { continue; }
                $ins->execute([$sym, $fund_asof, $ind, $v]);
            }
        }
        run_script('get_momentum_statistics.php');
        [$out, ] = run_script('get_statistics.php');
        return $out;
    };
    $fund_percentiles = function ($indicator) use ($fund_asof) {
        $out = [];
        foreach (rows("select symbol, percentile from statistics
                        where indicator=? and date=? order by symbol",
                      [$indicator, $fund_asof]) as $r) {
            $out[$r['symbol']] = $r['percentile'] === null ? null : (float)$r['percentile'];
        }
        return $out;
    };

    // (1) The fund counted as an ordinary company: the old behaviour.
    $fund_out = $fund_run('Sector 0');
    check('the job completes with a fund in the universe',
          stripos($fund_out, 'Fatal error') === false, substr($fund_out, -300));
    $fund_as_company = $fund_percentiles('pe');

    // (2) The fund left out of the data entirely: companies ranked alone.
    $fund_run('Sector 0', false);
    $fund_absent = $fund_percentiles('pe');

    // (3) The fund present, in a fund sector: must match (2), not (1).
    $fund_out = $fund_run('Investment Trusts');
    check('the job completes with a fund sector in play',
          stripos($fund_out, 'Fatal error') === false, substr($fund_out, -300));
    $fund_as_fund = $fund_percentiles('pe');

    check('a fund gets no percentile on an indicator it is not ranked on',
          array_key_exists($fund_sym, $fund_as_fund) && $fund_as_fund[$fund_sym] === null,
          'LLL percentile ' . var_export($fund_as_fund[$fund_sym] ?? 'missing', true));

    check('the fund\'s raw value is still stored, only the rank is withheld',
          (float)scalar("select value from statistics where symbol=? and indicator='pe' and date=?",
                        [$fund_sym, $fund_asof]) === 1.0);

    $fund_companies = function ($set) use ($fund_sym) {
        unset($set[$fund_sym]);
        return $set;
    };
    check('a fund is out of the denominator: companies rank exactly as if it were not listed',
          $fund_companies($fund_as_fund) == $fund_companies($fund_absent),
          'with fund ' . json_encode($fund_companies($fund_as_fund))
          . ' without ' . json_encode($fund_companies($fund_absent)));
    check('counting the fund would have changed those percentiles (the test has teeth)',
          $fund_companies($fund_as_company) != $fund_companies($fund_absent),
          'as company ' . json_encode($fund_companies($fund_as_company)));

    /* A REIT is an operating property company with rent, debt and a margin.
       Its sector is not in fund_sectors and it must rank like any other. */
    check('a REIT is still ranked',
          isset($fund_as_fund[$fund_reit]) && $fund_as_fund[$fund_reit] !== null,
          'KKK percentile ' . var_export($fund_as_fund[$fund_reit] ?? 'missing', true));
    check('a REIT still gets a value score',
          (int)scalar("select count(*) from statistics
                        where symbol=? and indicator='value_score' and date=?",
                      [$fund_reit, $fund_asof]) === 1);

    $fund_score_rows = function ($symbol) use ($fund_asof) {
        return (int)scalar("select count(*) from statistics where symbol=? and date=?
                             and indicator in ('value_score','quality_score','overall_score')",
                           [$symbol, $fund_asof]);
    };
    check('a fund gets no value, quality or overall score row at all',
          $fund_score_rows($fund_sym) === 0, $fund_score_rows($fund_sym) . ' rows');
    check('a company still gets all three', $fund_score_rows('AAA') === 3,
          $fund_score_rows('AAA') . ' rows');

    /* Momentum is explicitly kept. The fund has a price history like any other
       share and belongs in the momentum series and the trend screens. */
    foreach (['3mnth', '6mnth', '12mnth'] as $fund_mom) {
        check("a fund is still ranked on $fund_mom",
              scalar("select percentile from statistics where symbol=? and indicator=? and date=?",
                     [$fund_sym, $fund_mom, $fund_asof]) !== null);
    }
    check('a fund still gets a momentum score',
          (int)scalar("select count(*) from statistics
                        where symbol=? and indicator='momentum_score' and date=?",
                      [$fund_sym, $fund_asof]) === 1);
    foreach (['close_price', 'ma50', 'ma200'] as $fund_trend) {
        check("a fund still gets $fund_trend, so the trend screens can see it",
              (int)scalar("select count(*) from statistics where symbol=? and indicator=? and date=?",
                          [$fund_sym, $fund_trend, $fund_asof]) === 1);
    }

    /* Momentum must be numerically untouched, not merely present: the fund is
       in the momentum denominator and leaving it there is the whole point. */
    $fund_momentum_as_fund = $fund_percentiles('3mnth');
    $fund_run('Sector 0');
    $fund_momentum_as_company = $fund_percentiles('3mnth');
    check('momentum percentiles are identical whether or not the share is a fund',
          $fund_momentum_as_fund == $fund_momentum_as_company,
          json_encode($fund_momentum_as_fund) . ' vs ' . json_encode($fund_momentum_as_company));

    echo "\n--- a fund the sector list does not catch ---\n";

    /* A sector list catches most funds and misses the rest. On the live FTSE
       350 FGT files under "Equity Investments", VEIL and VOF under "General
       Financial" and SEQI under "Financial Services"; all four are
       investment companies, none is in fund_sectors, and all four reached
       the value top ten on buyback yields no operating company could post.
       FMP's Profile.isFund flags 92 of the 350, including those, so a symbol
       is a fund if the provider says so or its sector says so. */

    db()->prepare("update stock_symbols set sector='Equity Investments', is_fund='Y'
                    where symbol=?")->execute([$fund_sym]);
    check('its sector is not one of the fund sectors',
          (int)scalar("select count(*) from fund_sectors where sector='Equity Investments'") === 0);
    check('but the provider flags it, so it is a fund', is_fund($fund_sym) === true);

    run_script('get_statistics.php');
    check('a flagged fund is left out of the rankings like any other',
          scalar("select percentile from statistics where symbol=? and indicator='pe' and date=?",
                 [$fund_sym, $fund_asof]) === null);
    check('and gets no value, quality or overall score',
          $fund_score_rows($fund_sym) === 0, $fund_score_rows($fund_sym) . ' rows');
    check('while a company in the same sector is ranked as normal',
          scalar("select percentile from statistics where symbol='AAA' and indicator='pe' and date=?",
                 [$fund_asof]) !== null);

    /* The flag off and the sector not in the list: back to being a company. */
    db()->prepare("update stock_symbols set is_fund='N' where symbol=?")->execute([$fund_sym]);
    check('with the flag off and no fund sector it is a company again',
          is_fund($fund_sym) === false);
    run_script('get_statistics.php');
    check('and it is ranked and scored again',
          scalar("select percentile from statistics where symbol=? and indicator='pe' and date=?",
                 [$fund_sym, $fund_asof]) !== null
          && $fund_score_rows($fund_sym) === 3);

    /* Either source on its own is enough. */
    db()->prepare("update stock_symbols set sector='Investment Trusts', is_fund='N'
                    where symbol=?")->execute([$fund_sym]);
    check('a fund sector alone still makes it a fund', is_fund($fund_sym) === true);
    db()->prepare("update stock_symbols set is_fund=null where symbol=?")->execute([$fund_sym]);
    check('an unknown flag does not undo the sector list', is_fund($fund_sym) === true);

    echo "\n--- moving a symbol in and out of a fund sector ---\n";

    /* Item 8 of the ask. Nothing is left behind by the move: the job clears
       the ranks and the score rows for the date before writing them, so the
       next run is the whole answer. */
    check('out of a fund sector, the symbol is ranked and scored again',
          scalar("select percentile from statistics where symbol=? and indicator='pe' and date=?",
                 [$fund_sym, $fund_asof]) !== null
          && $fund_score_rows($fund_sym) === 3,
          'percentile ' . var_export(scalar("select percentile from statistics
                                              where symbol=? and indicator='pe' and date=?",
                                            [$fund_sym, $fund_asof]), true)
          . ', ' . $fund_score_rows($fund_sym) . ' score rows');

    /* Moving it back in, without clearing anything by hand: the ranks and
       scores it held as a company have to go. This is the failure mode the
       EXCLUDE fix hit - an UPDATE only touches the rows the ranking query
       returns, so a row that stops being returned keeps its old percentile. */
    db()->prepare("update stock_symbols set sector='Investment Trusts' where symbol=?")
        ->execute([$fund_sym]);
    run_script('get_statistics.php');
    check('back in a fund sector, the ranks it held as a company are gone',
          scalar("select percentile from statistics where symbol=? and indicator='pe' and date=?",
                 [$fund_sym, $fund_asof]) === null);
    check('back in a fund sector, the scores it held as a company are gone',
          $fund_score_rows($fund_sym) === 0, $fund_score_rows($fund_sym) . ' rows');

    echo "\n--- the fund exclusion is safe to re-run ---\n";

    $fund_snapshot = function () use ($fund_asof) {
        return rows("select symbol, indicator, value, stat_rank, percentile from statistics
                      where date=? order by symbol, indicator", [$fund_asof]);
    };
    $fund_before = $fund_snapshot();
    run_script('get_statistics.php');
    $fund_after = $fund_snapshot();
    check('running the same date twice leaves the statistics identical',
          $fund_before == $fund_after,
          count($fund_before) . ' rows then ' . count($fund_after));

    echo "\n--- the dashboard panels show companies only ---\n";

    /* The three score panels. The fund has no score row to find, so this would
       pass on the ranking change alone - which is why a row is planted first:
       between deploying this and the next nightly run the newest statistics
       date still holds the rows the old code wrote, and the dashboard reads
       that date. */
    db()->prepare("insert into statistics (symbol,date,indicator,value,exchange)
                   values (?,?,'value_score',99,'XLON'),(?,?,'quality_score',99,'XLON'),
                          (?,?,'overall_score',99,'XLON')")
        ->execute([$fund_sym, $fund_asof, $fund_sym, $fund_asof, $fund_sym, $fund_asof]);

    $fund_in = function ($rows) use ($fund_sym) {
        foreach ($rows as $r) { if ($r['symbol'] === $fund_sym) { return true; } }
        return false;
    };
    foreach (['value' => 'get_value_topten', 'quality' => 'get_quality_topten',
              'overall' => 'get_overall_topten'] as $fund_label => $fund_fn) {
        $fund_rows = $fund_fn();
        check("the $fund_label top ten leaves a fund out even with a stale score row",
              count($fund_rows) > 0 && !$fund_in($fund_rows),
              json_encode(array_column($fund_rows, 'symbol')));
    }

    /* Momentum is unchanged, including whether a fund appears in it. */
    $fund_momentum_top = array_column(get_momentum_topten(), 'symbol');
    /* Said out loud, because the comparison below is vacuous if the fund was
       never in the list to begin with. */
    check('a fund can still reach the momentum top ten',
          in_array($fund_sym, $fund_momentum_top, true), json_encode($fund_momentum_top));
    db()->prepare("update stock_symbols set sector='Sector 0' where symbol=?")->execute([$fund_sym]);
    check('the momentum top ten is the same list whether or not a share is a fund',
          array_column(get_momentum_topten(), 'symbol') === $fund_momentum_top,
          json_encode($fund_momentum_top));
    db()->prepare("update stock_symbols set sector='Investment Trusts' where symbol=?")
        ->execute([$fund_sym]);

    echo "\n--- funds are out of the sector and industry comparison sets ---\n";

    check('is_fund() reads the sector list rather than a hard-coded name',
          is_fund($fund_sym) === true && is_fund('AAA') === false && is_fund($fund_reit) === false);

    /* price_valuation is append only - the job never clears it - and the
       INDUSTRY averages are not cleared either, because that delete is scoped
       by exchange and the industry rows carry none. Both are left over from
       the runs above, so start from empty. */
    db()->exec("truncate price_valuation");
    $fund_run('Investment Trusts');

    /* A fund sector is all funds, so it now has no median of its own, and a
       fund is not valued off the companies that share its industry either. */
    check('no median is published for a fund sector',
          (int)scalar("select count(*) from statistic_averages
                        where category='SECTOR' and sector='Investment Trusts'") === 0);
    check('a company sector still gets its medians',
          (int)scalar("select count(*) from statistic_averages
                        where category='SECTOR' and sector='Sector 0'") > 0);
    check('a fund gets no relative valuation',
          (int)scalar("select count(*) from price_valuation where symbol=? and date=?",
                      [$fund_sym, $fund_asof]) === 0);

    /* The medians themselves must not count funds. The fund shares an industry
       with companies in this seed, so if it were counted the industry median
       would move. */
    $fund_industry = scalar("select industry from stock_symbols where symbol=?", [$fund_sym]);
    $fund_median_as_fund = scalar("select value from statistic_averages
                                    where category='INDUSTRY' and indicator='pe' and industry=?",
                                  [$fund_industry]);
    db()->exec("truncate price_valuation");
    $fund_run('Sector 0');
    $fund_median_as_company = scalar("select value from statistic_averages
                                       where category='INDUSTRY' and indicator='pe' and industry=?",
                                     [$fund_industry]);
    check('an industry median moves when the fund is counted, so excluding it matters',
          $fund_median_as_fund !== $fund_median_as_company,
          "fund excluded $fund_median_as_fund, included $fund_median_as_company");

    echo "\n--- the settings are configuration, not code ---\n";

    $fund_sql_src = (string)file_get_contents(dirname(__DIR__) . '/sql/fund_sectors.sql');
    foreach (['Investment Trusts', 'Closed End Investments', 'Collective Investments'] as $fund_seeded) {
        check("sql/fund_sectors.sql seeds '$fund_seeded'",
              (int)scalar("select count(*) from fund_sectors where sector=?", [$fund_seeded]) === 1);
    }
    check('the migration matches indicators by name, not by id',
          preg_match('/indicator_id/i', $fund_sql_src) !== 1);

    /* No sector name may appear in the application's own SQL: adding a fund
       sector has to be a row, not a release. */
    $fund_hardcoded = [];
    foreach (['includes/get_statistics.php', 'includes/share_functions.php'] as $fund_file) {
        $fund_code = php_strip_whitespace(dirname(__DIR__) . '/' . $fund_file);
        foreach (rows("select sector from fund_sectors") as $fund_row) {
            if (stripos($fund_code, $fund_row['sector']) !== false) {
                $fund_hardcoded[] = $fund_file . ' names ' . $fund_row['sector'];
            }
        }
    }
    check('no fund sector is named in the application code',
          count($fund_hardcoded) === 0, implode(', ', $fund_hardcoded));

    /* Applying the migration twice must change nothing. The live database
       already has it; a rebuild or a re-run must not double the rows or undo
       the settings. */
    /* The trailing selects are the migration's own report of what it did.
       Replaying them through PDO leaves an unbuffered result set behind and
       the next exec() dies on it, so only the statements that change
       something are replayed - which is all this is asking about. */
    $fund_statements = array_filter(array_map('trim',
        explode(';', preg_replace('/^\s*--.*$/m', '', $fund_sql_src))),
        function ($stmt) { return $stmt !== '' && stripos($stmt, 'select') !== 0; });
    foreach ([1, 2] as $fund_pass) {
        foreach ($fund_statements as $fund_stmt) { db()->exec($fund_stmt); }
    }
    check('the migration is re-runnable: the sector list does not double',
          (int)scalar("select count(*) from fund_sectors") === 3,
          scalar("select count(*) from fund_sectors") . ' rows');
    check('the migration is re-runnable: rank_funds stays set',
          (int)scalar("select count(*) from screen_indicators
                        where name in ('pe','price_sales_ratio','price_book_ratio','roe_ttm')
                          and rank_funds='N'") === (int)scalar(
              "select count(*) from screen_indicators
                where name in ('pe','price_sales_ratio','price_book_ratio','roe_ttm')"));
    check('the momentum indicators keep rank_funds=Y',
          (int)scalar("select count(*) from screen_indicators
                        where name in ('3mnth','6mnth','12mnth') and rank_funds='Y'") === 3);

    echo "\n--- the job survives code arriving before the migration ---\n";

    /* deploy/deploy.sh pulls code and restarts PHP; it does not run
       migrations. Without a guard the nightly job would die on an unknown
       table rather than carrying on the way it did the night before. */
    /* tests/schema.sql does not know about fund_sectors, so a run that died
       midway through this section would leave the renamed table behind and
       the rename below would fail on the next run rather than on the bug. */
    db()->exec("drop table if exists fund_sectors_pending");
    db()->exec("rename table fund_sectors to fund_sectors_pending");
    db()->exec("truncate message_log");
    [$fund_degraded, ] = run_script('get_statistics.php');
    check('the job does not fatal when fund_sectors is missing',
          stripos($fund_degraded, 'Fatal error') === false
          && stripos($fund_degraded, "doesn't exist") === false,
          substr($fund_degraded, -300));
    check('it says in the log that the migration has not been applied',
          (int)scalar("select count(*) from message_log
                        where message_text like '%fund_sectors.sql has not been applied%'") > 0);
    check('and it ranks the way it did before, rather than silently dropping everyone',
          scalar("select percentile from statistics where symbol='AAA' and indicator='pe' and date=?",
                 [$fund_asof]) !== null);
    db()->exec("rename table fund_sectors_pending to fund_sectors");

    //Leave the universe as it was found.
    db()->prepare("update stock_symbols set sector=? where symbol=?")
        ->execute(['Sector ' . (array_search($fund_sym, ['AAA','BBB','CCC','DDD','EEE','FFF','GGG','HHH','III','JJJ','KKK','LLL'], true) % 3), $fund_sym]);
    db()->prepare("update stock_symbols set sector=? where symbol=?")
        ->execute(['Sector ' . (array_search($fund_reit, ['AAA','BBB','CCC','DDD','EEE','FFF','GGG','HHH','III','JJJ','KKK','LLL'], true) % 3), $fund_reit]);
    db()->exec("delete from screen_indicators where name in ('pe','roe_ttm')");
    db()->exec("truncate price_valuation");
    reset_calculated();
    /* Put the statistics back. Later sections load the dashboard over HTTP and
       a truncated statistics table leaves it empty, which fails them for a
       reason that has nothing to do with what they test. */
    run_script('get_momentum_statistics.php');
    run_script('get_statistics.php');
    if ($fund_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $fund_saved_exchange; }

    echo "\n--- the relative valuations are safe to re-run ---\n";

    /* calc_implied_valuation() inserted into price_valuation with no delete in
       front of it, so every run appended another copy of every row. On the
       live database eight runs of get_statistics.php left 15,888 rows where
       there are 1,986 distinct ones, and the AI outlook was handed twenty-four
       relative_to_sector rows for RR instead of three.

       statistic_averages had the same shape of bug from the other end: the
       INDUSTRY insert left exchange null while the clean-up deleted "where
       date<=? and exchange=?", so industry rows were never removed - 5,704
       rows for one date where there are 920 distinct ones. */

    $pv_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = 'XLON';
    $pv_asof = asof_date();
    $pv_symbols = ['AAA','BBB','CCC','DDD','EEE','FFF','GGG','HHH','III','JJJ','KKK','LLL'];

    reset_calculated();
    db()->exec("truncate price_valuation");

    /* One sector and one industry wide enough to have a median. The seed
       spreads twelve symbols over three sectors of four, and four members is
       three peers, which is below the floor. */
    db()->exec("update stock_symbols set sector='Peer Sector', industry='Peer Industry'
                 where exchange='XLON'");

    db()->exec("delete from screen_indicators where name='pe'");
    db()->exec("insert into screen_indicators
                  (name, description, enabled, order_number, calc_rank, rank_zero,
                   rank_order, rank_negative, rank_funds, category)
                values ('pe','Price / earnings','Y',1,'Y','N','value ASC','EXCLUDE','N',12)");

    $pv_insert = db()->prepare("insert into statistics (symbol,date,indicator,value,exchange)
                                values (?,?,'pe',?,'XLON')");
    foreach ($pv_symbols as $pv_i => $pv_sym) {
        $pv_insert->execute([$pv_sym, $pv_asof, 5 + $pv_i * 2]);
    }

    run_script('get_statistics.php');

    $pv_count = function ($table) use ($pv_asof) {
        return (int)scalar("select count(*) from $table where date=?", [$pv_asof]);
    };
    $pv_before = $pv_count('price_valuation');
    $sa_before = $pv_count('statistic_averages');
    check('the first run writes relative valuations at all', $pv_before > 0);
    check('the first run writes medians at all', $sa_before > 0);

    run_script('get_statistics.php');

    check('re-running the date leaves price_valuation the same size',
          $pv_count('price_valuation') === $pv_before,
          $pv_before . ' rows then ' . $pv_count('price_valuation'));
    check('re-running the date leaves statistic_averages the same size',
          $pv_count('statistic_averages') === $sa_before,
          $sa_before . ' rows then ' . $pv_count('statistic_averages'));

    /* Counting rows is not enough on its own: a run that wrote nothing at all
       would also leave the count unchanged. */
    check('no symbol has two price_valuation rows for the same indicator and type',
          (int)scalar("select count(*) from (select symbol,date,indicator,type from price_valuation
                        group by symbol,date,indicator,type having count(*)>1) d") === 0);
    check('no average is published twice for the same group and indicator',
          (int)scalar("select count(*) from (select date,category,indicator,sector,industry,exchange
                         from statistic_averages group by date,category,indicator,sector,industry,exchange
                        having count(*)>1) d") === 0);

    /* The industry rows specifically, because they were the ones the delete
       never matched. */
    $pv_industry = (int)scalar("select count(*) from statistic_averages
                                 where category='INDUSTRY' and date=?", [$pv_asof]);
    check('industry medians are written', $pv_industry > 0);
    run_script('get_statistics.php');
    check('industry medians are cleared on a re-run rather than appended',
          (int)scalar("select count(*) from statistic_averages
                        where category='INDUSTRY' and date=?", [$pv_asof]) === $pv_industry,
          $pv_industry . ' rows then '
          . scalar("select count(*) from statistic_averages where category='INDUSTRY' and date=?", [$pv_asof]));
    check('every average row carries the exchange the delete matches on',
          (int)scalar("select count(*) from statistic_averages where exchange is null") === 0,
          scalar("select count(*) from statistic_averages where exchange is null") . ' rows with none');

    echo "\n--- a peer median excludes the share and needs a real sample ---\n";

    /* Aerospace & Defense has four members on the live FTSE 350 - BAB 23.3,
       BA 26.9, MRO 37.9, RR 37.5 - and the old query, which took the upper of
       the two middle values and counted the share itself, returned RR's own
       37.52 as the sector median for RR. The implied price then came back as
       the current price, 1351, and the figure meant nothing. */

    $pm_all = "('PM1','PM2','PM3','PM4','PM5','PM6','PM7')";
    db()->exec("delete from statistics where symbol in $pm_all");
    db()->exec("delete from stock_symbols where symbol in $pm_all");

    $pm_values = ['PM1' => 1.0, 'PM2' => 2.0, 'PM3' => 3.0, 'PM4' => 4.0,
                  'PM5' => 5.0, 'PM6' => 100.0, 'PM7' => 6.0];
    $pm_symbol = db()->prepare("insert into stock_symbols (symbol,name,exchange,enabled,sector,industry)
                                values (?,?,'XLON','Y','Median Test','Median Industry')");
    $pm_stat = db()->prepare("insert into statistics (symbol,date,indicator,value,exchange)
                              values (?,?,'pe',?,'XLON')");
    foreach ($pm_values as $pm_sym => $pm_v) {
        $pm_symbol->execute([$pm_sym, $pm_sym . ' Test PLC']);
        $pm_stat->execute([$pm_sym, $pv_asof, $pm_v]);
    }

    /* Seven members, so any one of them has six peers. For PM6 those are
       1,2,3,4,5,6: an even count, and neither middle value is the answer. */
    check('an even number of peers takes the mean of the two middle values',
          peer_median('pe', $pv_asof, 'sector', 'Median Test', 'PM6') === 3.5,
          var_export(peer_median('pe', $pv_asof, 'sector', 'Median Test', 'PM6'), true));

    /* For PM3 the peers are 1,2,4,5,6,100 and the median is 4.5. Counting PM3
       itself would make it seven values and 4.0, so this says the share is
       left out rather than merely that the arithmetic is a median. */
    check('the share is left out of its own median',
          peer_median('pe', $pv_asof, 'sector', 'Median Test', 'PM3') === 4.5,
          var_export(peer_median('pe', $pv_asof, 'sector', 'Median Test', 'PM3'), true));

    check('the industry grouping works the same way',
          peer_median('pe', $pv_asof, 'industry', 'Median Industry', 'PM6') === 3.5);

    //Six members, so five peers for PM6: 1,2,3,4,5, an odd count.
    db()->exec("delete from statistics where symbol='PM7'");
    db()->exec("delete from stock_symbols where symbol='PM7'");
    check('an odd number of peers takes the middle value',
          peer_median('pe', $pv_asof, 'sector', 'Median Test', 'PM6') === 3.0,
          var_export(peer_median('pe', $pv_asof, 'sector', 'Median Test', 'PM6'), true));
    check('five peers is enough to publish a median',
          peer_median('pe', $pv_asof, 'sector', 'Median Test', 'PM6') !== null);

    //Five members, so four peers: below the floor.
    db()->exec("delete from statistics where symbol='PM5'");
    db()->exec("delete from stock_symbols where symbol='PM5'");
    check('four peers is not a sample, so nothing is published',
          peer_median('pe', $pv_asof, 'sector', 'Median Test', 'PM6') === null,
          var_export(peer_median('pe', $pv_asof, 'sector', 'Median Test', 'PM6'), true));
    check('the floor is five peers', PEER_MEDIAN_MIN_PEERS === 5);

    /* A disabled symbol is not a peer, and neither is a fund. */
    db()->exec("update stock_symbols set enabled='N' where symbol='PM4'");
    check('a disabled symbol is not counted as a peer',
          peer_median('pe', $pv_asof, 'sector', 'Median Test', 'PM6') === null);
    db()->exec("update stock_symbols set enabled='Y' where symbol='PM4'");

    db()->exec("delete from statistics where symbol in $pm_all");
    db()->exec("delete from stock_symbols where symbol in $pm_all");

    echo "\n--- too few peers means no implied price, not a circular one ---\n";

    /* End to end: back to the seed's three sectors of four, where every group
       is below the floor, and nothing should be written at all. */
    db()->exec("truncate price_valuation");
    foreach ($pv_symbols as $pv_i => $pv_sym) {
        db()->prepare("update stock_symbols set sector=?, industry=? where symbol=?")
            ->execute(['Small ' . ($pv_i % 3), 'Small Industry ' . ($pv_i % 3), $pv_sym]);
    }
    run_script('get_statistics.php');
    check('a four member sector produces no relative valuation',
          $pv_count('price_valuation') === 0,
          $pv_count('price_valuation') . ' rows');
    check('and no relative_valuation statistic is left over from the wider run',
          (int)scalar("select count(*) from statistics
                        where date=? and indicator in ('relative_valuation','relative_industry_valuation')",
                      [$pv_asof]) === 0);

    echo "\n--- the outlook is told what the implied price is ---\n";

    /* The model was sent price_valuation.value under the name "relative",
       beside the ratios it is derived from, and said the figures did not
       reconcile. It is an implied price in pence: what the share would cost
       if its ratio matched the median of its peers. */
    db()->exec("update stock_symbols set sector='Peer Sector', industry='Peer Industry'
                 where exchange='XLON'");
    run_script('get_statistics.php');

    $pv_inputs = outlook_share_inputs('AAA', $pv_asof);
    check('the outlook gets relative valuations to talk about',
          count($pv_inputs['relative_to_sector']) > 0);

    $pv_row = $pv_inputs['relative_to_sector'][0];
    check('the implied price is named as an implied price in pence',
          array_key_exists('implied_price_pence', $pv_row)
          && !array_key_exists('relative', $pv_row),
          json_encode(array_keys($pv_row)));
    check('the distance from the current price is given rather than left to the model',
          array_key_exists('pct_vs_price', $pv_row), json_encode(array_keys($pv_row)));

    $pv_close = $pv_inputs['price']['close'];
    check('pct_vs_price is the implied price against the close the panel also shows',
          $pv_close !== null && $pv_row['implied_price_pence'] !== null
          && abs($pv_row['pct_vs_price']
                 - round((($pv_row['implied_price_pence'] / $pv_close) - 1) * 100, 2)) < 0.005,
          json_encode([$pv_row['implied_price_pence'], $pv_close, $pv_row['pct_vs_price']]));

    $pv_prompt = outlook_system_prompt('share');
    check('the prompt says what the implied price is, and is not',
          stripos($pv_prompt, 'implied_price_pence') !== false
          && stripos($pv_prompt, 'not a forecast') !== false);

    /* The quote page says the same thing in words rather than leaving the
       reader to work out what a bare number in a column headed "Price" is. */
    $pv_quote = (string)file_get_contents(dirname(__DIR__) . '/templates/quote.php');
    check('the quote page labels the sector figure an implied price',
          strpos($pv_quote, 'Implied price at sector median') !== false);
    check('the quote page labels the industry figure an implied price',
          strpos($pv_quote, 'Implied price at industry median') !== false);

    echo "\n--- get_statistics_test.php is out of the live tree ---\n";

    /* An earlier copy of the sector median loop, referenced by nothing. It
       wrote a get_statistics_asof row into jobs, which is the marker the
       dashboard, the screens and the quote page resolve their as of date
       from, so running it by hand would have moved the whole application's
       idea of today. */
    check('get_statistics_test.php is no longer in includes',
          !file_exists($INCLUDES . '/get_statistics_test.php'));
    check('it is kept in the archive',
          file_exists(dirname(__DIR__) . '/archive/includes/get_statistics_test.php'));
    $pv_referenced = [];
    foreach (glob(dirname(__DIR__) . '/{includes,public,templates}/*.php', GLOB_BRACE) as $pv_file) {
        if (strpos((string)file_get_contents($pv_file), 'get_statistics_test') !== false) {
            $pv_referenced[] = basename($pv_file);
        }
    }
    check('nothing in the live tree refers to it', count($pv_referenced) === 0,
          implode(', ', $pv_referenced));

    //Leave the universe as it was found.
    foreach ($pv_symbols as $pv_i => $pv_sym) {
        db()->prepare("update stock_symbols set sector=?, industry=? where symbol=?")
            ->execute(['Sector ' . ($pv_i % 3), 'Industry ' . ($pv_i % 4), $pv_sym]);
    }
    db()->exec("delete from screen_indicators where name='pe'");
    db()->exec("truncate price_valuation");
    reset_calculated();
    run_script('get_momentum_statistics.php');
    run_script('get_statistics.php');
    if ($pv_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $pv_saved_exchange; }

    echo "\n--- a peer median leaves out a ratio that has no meaning ---\n";

    /* rank_negative='EXCLUDE' says a negative P/E does not mean cheap and does
       not mean dear: the ratio has no meaning for a company with no earnings.
       The rankings have applied that since the EXCLUDE work, but the medians
       the peers are measured against still counted the loss-maker, so it
       dragged the comparison down for everyone in the sector.

       The floor is counted after that filter, not before: four usable peers
       and a loss-maker is four peers. */

    $mn_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = 'XLON';
    $mn_asof = asof_date();
    $mn_all  = "('MN1','MN2','MN3','MN4','MN5','MN6','MN7')";

    reset_calculated();
    db()->exec("delete from statistics where symbol in $mn_all");
    db()->exec("delete from stock_symbols where symbol in $mn_all");
    db()->exec("delete from screen_indicators where name in ('pe','roe_ttm')");
    db()->exec("insert into screen_indicators
                  (name, description, enabled, order_number, calc_rank, rank_zero,
                   rank_order, rank_negative, rank_funds, category)
                values ('pe','Price / earnings','Y',1,'Y','N','value ASC','EXCLUDE','N',12),
                       ('roe_ttm','Return on equity','Y',2,'Y','N','value DESC','NORMAL','N',10)");

    /* MN1 is the loss-maker, MN2 reported a zero, MN3..MN7 are five ordinary
       companies. roe_ttm carries the same numbers but ranks NORMAL, where a
       negative return is a real figure and stays in. */
    $mn_pe  = ['MN1' => -5.0, 'MN2' => 0.0, 'MN3' => 1.0, 'MN4' => 2.0,
               'MN5' => 3.0, 'MN6' => 4.0, 'MN7' => 5.0];
    $mn_roe = ['MN1' => -5.0, 'MN3' => 1.0, 'MN4' => 2.0,
               'MN5' => 3.0, 'MN6' => 4.0, 'MN7' => 5.0];

    $mn_symbol = db()->prepare("insert into stock_symbols (symbol,name,exchange,enabled,sector,industry,market)
                                values (?,?,'XLON','Y','Median Test','Median Industry','FTSE 250')");
    foreach (array_keys($mn_pe) as $mn_sym) { $mn_symbol->execute([$mn_sym, $mn_sym . ' Test PLC']); }
    $mn_stat = db()->prepare("insert into statistics (symbol,date,indicator,value,exchange)
                              values (?,?,?,?,'XLON')");
    foreach ($mn_pe as $mn_sym => $mn_v)  { $mn_stat->execute([$mn_sym, $mn_asof, 'pe', $mn_v]); }
    foreach ($mn_roe as $mn_sym => $mn_v) { $mn_stat->execute([$mn_sym, $mn_asof, 'roe_ttm', $mn_v]); }

    /* From MN1's point of view the peers are MN2 (a zero, which rank_zero
       already dropped) and MN3..MN7: five values, 1 to 5, median 3. */
    check('the five ordinary peers are what the median is taken over',
          count(median_sample('pe', $mn_asof, 'sector', 'Median Test', 'MN1')) === 5,
          json_encode(median_sample('pe', $mn_asof, 'sector', 'Median Test', 'MN1')));
    check('a loss-maker gets the median of the companies that have earnings',
          peer_median('pe', $mn_asof, 'sector', 'Median Test', 'MN1') === 3.0,
          var_export(peer_median('pe', $mn_asof, 'sector', 'Median Test', 'MN1'), true));

    /* From MN3's point of view the usable peers are MN4..MN7: four of them, so
       nothing is published. Counting the loss-maker and the zero would make it
       six and hand back 2.5 - which is the answer this is here to refuse. */
    check('a non-positive peer is left out where the ratio has no meaning',
          !in_array(-5.0, median_sample('pe', $mn_asof, 'sector', 'Median Test', 'MN3'), true)
          && !in_array(0.0, median_sample('pe', $mn_asof, 'sector', 'Median Test', 'MN3'), true),
          json_encode(median_sample('pe', $mn_asof, 'sector', 'Median Test', 'MN3')));
    check('the five peer floor is applied after that filter, not before',
          peer_median('pe', $mn_asof, 'sector', 'Median Test', 'MN3') === null,
          var_export(peer_median('pe', $mn_asof, 'sector', 'Median Test', 'MN3'), true));

    /* The same share, on an indicator that ranks NORMAL: a negative return on
       equity is a real number and counts, so MN3 has five peers and a median. */
    check('a negative value still counts where rank_negative is not EXCLUDE',
          in_array(-5.0, median_sample('roe_ttm', $mn_asof, 'sector', 'Median Test', 'MN3'), true)
          && peer_median('roe_ttm', $mn_asof, 'sector', 'Median Test', 'MN3') === 3.0,
          json_encode(median_sample('roe_ttm', $mn_asof, 'sector', 'Median Test', 'MN3')));

    echo "\n--- the stored medians are the same medians ---\n";

    /* statistic_averages came from a SQL expression that took the upper of the
       two middle values for an even count, counted funds and counted a
       negative P/E. So the "Sector" column on the quote page and the peer
       median beside it were two different definitions wearing one word. */

    check('a whole-group median includes the share, which is the difference',
          count(median_sample('pe', $mn_asof, 'sector', 'Median Test', null))
          === count(median_sample('pe', $mn_asof, 'sector', 'Median Test', 'MN3')) + 1);

    /* Six usable values, 1 to 5 plus one more: an even count, and the answer
       is not a member's own figure. */
    db()->prepare("insert into statistics (symbol,date,indicator,value,exchange)
                   values ('MN2',?,'pe',6.0,'XLON')")->execute([$mn_asof]);
    db()->exec("delete from statistics where symbol='MN2' and indicator='pe' and value=0");
    check('an even count averages the two middle values rather than taking the upper',
          group_median('pe', $mn_asof, 'sector', 'Median Test') === 3.5,
          var_export(group_median('pe', $mn_asof, 'sector', 'Median Test'), true));
    check('the upper of the two middle values, which is what the old SQL returned, is not it',
          group_median('pe', $mn_asof, 'sector', 'Median Test') !== 4.0);

    check('a market median is the same arithmetic over the whole exchange',
          group_median('pe', $mn_asof, 'market') === median_of(median_sample('pe', $mn_asof, 'market', null)));

    db()->exec("delete from statistics where symbol in $mn_all");
    db()->exec("delete from stock_symbols where symbol in $mn_all");

    /* End to end, on the seeded universe: twelve symbols in one sector with
       P/Es 5, 7 ... 27. The median of twelve values is the mean of the sixth
       and seventh, 16. The old rule returned the seventh, 17. */
    $mn_symbols = ['AAA','BBB','CCC','DDD','EEE','FFF','GGG','HHH','III','JJJ','KKK','LLL'];
    reset_calculated();
    db()->exec("truncate price_valuation");
    db()->exec("update stock_symbols set sector='Peer Sector', industry='Peer Industry'
                 where exchange='XLON'");
    $mn_insert = db()->prepare("insert into statistics (symbol,date,indicator,value,exchange)
                                values (?,?,'pe',?,'XLON')");
    foreach ($mn_symbols as $mn_i => $mn_sym) { $mn_insert->execute([$mn_sym, $mn_asof, 5 + $mn_i * 2]); }
    run_script('get_statistics.php');

    $mn_stored = function ($category) use ($mn_asof) {
        $v = scalar("select value from statistic_averages
                      where category=? and indicator='pe' and date=?", [$category, $mn_asof]);
        return $v === false || $v === null ? null : (float)$v;
    };
    check('the stored sector median is the mean of the two middle values',
          $mn_stored('SECTOR') === 16.0, var_export($mn_stored('SECTOR'), true));
    check('it is not the upper middle value the old query returned',
          $mn_stored('SECTOR') !== 17.0);
    check('the stored market median is worked out the same way',
          $mn_stored('MARKET') === 16.0, var_export($mn_stored('MARKET'), true));
    check('and so is the industry median',
          $mn_stored('INDUSTRY') === 16.0, var_export($mn_stored('INDUSTRY'), true));

    /* Funds are out of the stored medians too. Taking the highest P/E out
       leaves eleven values and a median of 15. */
    db()->exec("update stock_symbols set sector='Investment Trusts' where symbol='LLL'");
    run_script('get_statistics.php');
    check('a fund is not counted in the stored market median',
          $mn_stored('MARKET') === 15.0, var_export($mn_stored('MARKET'), true));
    db()->exec("update stock_symbols set sector='Peer Sector' where symbol='LLL'");

    /* And a negative P/E is out of them, for the same reason it is out of the
       peer median: AAA at -50 would otherwise pull the median down a place. */
    db()->prepare("update statistics set value=-50 where symbol='AAA' and indicator='pe' and date=?")
        ->execute([$mn_asof]);
    run_script('get_statistics.php');
    check('a negative P/E is not counted in the stored median either',
          $mn_stored('MARKET') === 17.0, var_export($mn_stored('MARKET'), true));
    db()->prepare("update statistics set value=5 where symbol='AAA' and indicator='pe' and date=?")
        ->execute([$mn_asof]);

    /* The two columns are labelled, so the page says which median is which. */
    $mn_quote = (string)file_get_contents(dirname(__DIR__) . '/templates/quote.php');
    check('the whole-group columns say "median"',
          strpos($mn_quote, 'Sector median') !== false
          && strpos($mn_quote, 'Market median') !== false);
    check('the peer columns say the share is left out of them',
          substr_count($mn_quote, 'peer median (excl. this share)') === 2,
          substr_count($mn_quote, 'peer median (excl. this share)') . ' occurrences');

    echo "\n--- the as of date is the last trading day, not yesterday ---\n";

    /* Run by hand on Sunday 15 November, "today - 1" wrote a set of statistics
       for Saturday the 14th: no fundamentals are loaded for a Saturday, so no
       ratios, so an overall score that was momentum alone - and the dashboard
       moved onto that date, because the marker said it was the newest there
       was. The prices are the only thing that knows when the market was last
       open. */

    db()->exec("delete from historical_prices where exchange='TSTX'");
    $td_price = db()->prepare("insert into historical_prices (symbol,exchange,date,price) values ('TST','TSTX',?,?)");
    //Monday 9 to Friday 13 November 2026. Nothing on the 14th or the 15th.
    foreach (['2026-11-09','2026-11-10','2026-11-11','2026-11-12','2026-11-13'] as $td_i => $td_date) {
        $td_price->execute([$td_date, 100 + $td_i]);
    }

    check('the as of date is the last day prices were loaded for',
          last_trading_date('TSTX') === '2026-11-13', var_export(last_trading_date('TSTX'), true));
    check('a run the day after the weekend starts lands on the Friday, not the Saturday',
          resolve_asof_date(null, 'TSTX') === '2026-11-13',
          var_export(resolve_asof_date(null, 'TSTX'), true));
    check('the Saturday is refused if it is asked for by name',
          resolve_asof_date('2026-11-14', 'TSTX') === null);
    check('a date with prices is accepted',
          resolve_asof_date('2026-11-11', 'TSTX') === '2026-11-11');
    foreach (['yesterday', '13/11/2026', '2026-11-13 00:00:00', '2026-02-30',
              '2026-2-3', '; drop table statistics'] as $td_bad) {
        check("'" . $td_bad . "' is refused rather than interpreted",
              resolve_asof_date($td_bad, 'TSTX') === null);
    }
    check('an exchange with no prices at all has no as of date',
          resolve_asof_date(null, 'NOSUCH') === null);
    db()->exec("delete from historical_prices where exchange='TSTX'");

    //End to end. The seeded universe has prices, so the job uses the newest.
    $td_latest = (string)scalar("select max(date) from historical_prices where exchange='XLON'");
    db()->exec("truncate jobs");
    run_script('get_statistics.php');
    check('the job calculates for the newest priced date',
          scalar("select date(max(job_date)) from jobs where job_name='get_statistics_asof'") === $td_latest,
          'marker ' . scalar("select date(max(job_date)) from jobs where job_name='get_statistics_asof'")
          . ', newest price ' . $td_latest);

    //A date with prices, given on the command line.
    $td_earlier = (string)scalar("select max(date) from historical_prices
                                   where exchange='XLON' and date < ?", [$td_latest]);
    db()->exec("truncate jobs");
    [$td_out, $td_rc] = run_script('get_statistics.php', [$td_earlier]);
    check('a date argument with prices is used',
          $td_rc === 0
          && scalar("select date(max(job_date)) from jobs where job_name='get_statistics_asof'") === $td_earlier,
          'rc ' . $td_rc . ', marker '
          . scalar("select date(max(job_date)) from jobs where job_name='get_statistics_asof'"));

    //A date without prices is refused, and nothing is written for it.
    db()->exec("truncate jobs");
    [$td_refused, $td_rc2] = run_script('get_statistics.php', ['2020-01-01']);
    check('a date argument with no prices is refused', $td_rc2 !== 0, 'rc ' . $td_rc2);
    check('it says why rather than failing silently',
          stripos($td_refused, 'refusing to run') !== false, $td_refused);
    check('nothing is calculated for the refused date',
          (int)scalar("select count(*) from statistics where date='2020-01-01'") === 0
          && (int)scalar("select count(*) from jobs where job_name='get_statistics_asof'") === 0);
    [, $td_rc3] = run_script('get_statistics.php', ['not-a-date']);
    check('a date argument that is not a date is refused', $td_rc3 !== 0, 'rc ' . $td_rc3);

    //Put the marker back for everything after this.
    run_script('get_statistics.php');

    echo "\n--- the dashboard reports the as of date and the run time separately ---\n";

    check('the as of date is a date, with no time of day',
          preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)get_last_update()) === 1,
          var_export(get_last_update(), true));
    check('the run time is a timestamp',
          preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)get_last_run()) === 1,
          var_export(get_last_run(), true));
    check('the as of date is the date the statistics carry',
          (string)get_last_update() === (string)scalar("select max(date) from statistics
                                                         where indicator='momentum_score'"));
    /* Comments stripped first. The template explains what the old label got
       wrong, so it contains the old label - and searching the raw file finds
       the explanation and reports that nothing changed. This is the fifth time
       that trap has bitten in this codebase; it is always the comment. */
    $mn_dash = php_strip_whitespace(dirname(__DIR__) . '/templates/quote_form.php');
    check('the dashboard names the two facts rather than running them together',
          strpos($mn_dash, 'Prices as of') !== false
          && strpos($mn_dash, 'updated') !== false
          && strpos($mn_dash, 'Statistics Refreshed') === false,
          strpos($mn_dash, 'Statistics Refreshed') !== false
              ? 'the old label is still there' : 'the new wording is missing');
    check('index.php supplies both to the template',
          preg_match('/"last_run"\s*=>/', (string)file_get_contents(dirname(__DIR__) . '/public/index.php')) === 1);

    echo "\n--- the outlook knows which index the share is in ---\n";

    /* The owner's policy notes have a universe rule about the FTSE 350. With
       nothing in the inputs to check a share against, the model reported that
       FTSE 350 membership was missing from the data on every single share. */
    db()->exec("update stock_symbols set market='FTSE 100' where symbol='AAA'");
    db()->exec("update stock_symbols set market='FTSE 250' where symbol='BBB'");
    $mn_in_100 = outlook_share_inputs('AAA', $mn_asof);
    $mn_in_250 = outlook_share_inputs('BBB', $mn_asof);
    check('the outlook inputs carry the index the share is in',
          ($mn_in_100['index'] ?? null) === 'FTSE 100'
          && ($mn_in_250['index'] ?? null) === 'FTSE 250',
          json_encode([$mn_in_100['index'] ?? null, $mn_in_250['index'] ?? null]));
    check('it comes from stock_symbols.market rather than being guessed',
          preg_match('/"index"\s*=>\s*\$info\[.market.\]/',
                     (string)file_get_contents($INCLUDES . '/ai_outlook.php')) === 1);
    db()->exec("update stock_symbols set market='Main Market' where symbol in ('AAA','BBB')");

    //Leave the universe as it was found.
    foreach ($mn_symbols as $mn_i => $mn_sym) {
        db()->prepare("update stock_symbols set sector=?, industry=? where symbol=?")
            ->execute(['Sector ' . ($mn_i % 3), 'Industry ' . ($mn_i % 4), $mn_sym]);
    }
    db()->exec("delete from screen_indicators where name in ('pe','roe_ttm')");
    db()->exec("truncate price_valuation");
    reset_calculated();
    run_script('get_momentum_statistics.php');
    run_script('get_statistics.php');
    if ($mn_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $mn_saved_exchange; }

    echo "\n--- FMP's price ratios are converted out of the reporting currency ---\n";

    /* FMP's ratios-ttm and key-metrics-ttm divide the London price by
       per-share figures taken from accounts kept in the company's own
       currency, and nothing converted between them. Sterling reporters were
       right and everyone else was understated by the whole exchange rate:

         RR    reports GBP   P/E 37.52   correct
         SHEL  reports USD   P/E  8.43   true about 11.1
         BGEO  reports GEL   P/E  2.38   true about  8.6

       62 of 272 companies with statements report in something other than
       sterling, and seven of the thirty places in the value, quality and
       overall top tens were held by one of them. */

    $cx_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = 'XLON';
    $cx_asof = asof_date();
    $cx_all  = "('CGB','CUS','CGE','CNO')";

    /* Runs one of the Python jobs against the test database. They read the
       SM_DB_* names; run_tests.sh configures the throwaway database under
       SM_TEST_*. */
    $run_python = function ($script, $args = []) {
        $cmd = 'SM_DB_HOST=' . escapeshellarg(SERVER)
             . ' SM_DB_NAME=' . escapeshellarg(DATABASE)
             . ' SM_DB_USER=' . escapeshellarg(USERNAME)
             . ' SM_DB_PASS=' . escapeshellarg(PASSWORD)
             . ' python3 ' . escapeshellarg(dirname(__DIR__) . '/python/' . $script);
        foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
        exec($cmd . ' 2>&1', $out, $rc);
        return [implode("\n", $out), $rc];
    };

    reset_calculated();
    db()->exec("delete from stock_info");
    db()->exec("delete from fx_rates");
    db()->exec("delete from stock_symbols where symbol in $cx_all");
    db()->exec("delete from screen_indicators where name in ('pe','roe_ttm')");
    db()->exec("insert into screen_indicators
                  (name, provider_field, description, enabled, order_number,
                   calc_rank, rank_zero, rank_order, rank_negative, fx_adjust, category)
                values
                  ('pe','Ratios.priceToEarningsRatioTTM','Price / earnings','Y',1,
                   'Y','N','value ASC','EXCLUDE','MULTIPLY',12),
                  ('roe_ttm','KeyMetrics.returnOnEquityTTM','Return on equity','Y',2,
                   'Y','N','value DESC','NORMAL','NONE',10)");

    /* Four companies with identical provider figures and different reporting
       currencies, so any difference in the answer is the conversion and
       nothing else. CNO reports in a currency no rate could be found for. */
    $cx_ccy = ['CGB' => 'GBP', 'CUS' => 'USD', 'CGE' => 'GEL', 'CNO' => 'ZWL'];
    $cx_sym = db()->prepare("insert into stock_symbols (symbol,name,exchange,enabled,sector,industry,market,report_ccy,report_ccy_asof)
                             values (?,?,'XLON','Y','Currency Test','Currency Industry','FTSE 250',?,?)");
    $cx_info = db()->prepare("insert into stock_info (symbol,asofdate,attribute,value) values (?,?,?,?)");
    foreach ($cx_ccy as $cx_s => $cx_c) {
        $cx_sym->execute([$cx_s, $cx_s . ' Test PLC', $cx_c, $cx_asof]);
        $cx_info->execute([$cx_s, $cx_asof, 'Ratios.priceToEarningsRatioTTM', '8.43']);
        $cx_info->execute([$cx_s, $cx_asof, 'KeyMetrics.returnOnEquityTTM', '0.25']);
    }

    /* GBPUSD is quoted on the plan. GBPGEL is not - the endpoint answers [] -
       so the loader crosses it through the dollar and records that it did. */
    $cx_rate = db()->prepare("insert into fx_rates (date,base,quote_ccy,rate,source) values (?,'GBP',?,?,?)");
    $cx_rate->execute([$cx_asof, 'USD', 1.3212, 'quote']);
    $cx_rate->execute([$cx_asof, 'GEL', 3.5, 'cross:USD']);

    [$cx_out, $cx_rc] = $run_python('get_api_stats.py');
    check('get_api_stats.py runs against the database', $cx_rc === 0, substr($cx_out, -500));

    $cx_value = function ($symbol, $indicator) use ($cx_asof) {
        $v = scalar("select value from statistics where symbol=? and indicator=? and date=? and exchange='XLON'",
                    [$symbol, $indicator, $cx_asof]);
        return ($v === false || $v === null) ? null : (float)$v;
    };

    check('a sterling reporter is untouched',
          $cx_value('CGB', 'pe') === 8.43, var_export($cx_value('CGB', 'pe'), true));
    check('a dollar reporter is multiplied by GBPUSD',
          $cx_value('CUS', 'pe') === 11.137716, var_export($cx_value('CUS', 'pe'), true));
    check('and that is the figure the provider did not give',
          $cx_value('CUS', 'pe') !== 8.43);
    check('a lari reporter is multiplied by the crossed rate',
          $cx_value('CGE', 'pe') === 29.505, var_export($cx_value('CGE', 'pe'), true));
    check('the crossed rate is recorded as crossed, not as a quote',
          scalar("select source from fx_rates where date=? and quote_ccy='GEL'", [$cx_asof]) === 'cross:USD');

    /* No rate means no value. Writing the provider's figure anyway would rank
       the company a third cheaper than it is, which is worse than leaving it
       out of that ranking for the day. */
    check('a reporter with no rate gets no price ratio at all',
          $cx_value('CNO', 'pe') === null, var_export($cx_value('CNO', 'pe'), true));
    check('it is reported rather than passed over in silence',
          stripos($cx_out, 'NO RATE') !== false && strpos($cx_out, 'ZWL') !== false,
          substr($cx_out, -400));

    /* A ratio that is one currency over the same currency is not touched, and
       a missing rate must not suppress it either. */
    foreach (array_keys($cx_ccy) as $cx_s) {
        check("return on equity is unchanged for $cx_s",
              $cx_value($cx_s, 'roe_ttm') === 0.25, var_export($cx_value($cx_s, 'roe_ttm'), true));
    }

    /* The rate that was used, beside the values it was used on. */
    check('the rate used is recorded against the symbol',
          $cx_value('CUS', 'fx_rate') === 1.3212 && $cx_value('CGE', 'fx_rate') === 3.5);
    check('a sterling reporter gets no rate row, because none was applied',
          $cx_value('CGB', 'fx_rate') === null);

    /* The provider's own figure is still on record: stock_info is written by
       the loader and the correction happens on the way into statistics, so
       raw value, rate and reporting currency are all still there to check
       the arithmetic against. */
    check("the provider's raw value is kept for auditing",
          scalar("select value from stock_info where symbol='CUS' and asofdate=? and attribute='Ratios.priceToEarningsRatioTTM'",
                 [$cx_asof]) === '8.43');

    //Re-running must not convert twice.
    $run_python('get_api_stats.py');
    check('a second run leaves the converted value alone rather than converting it again',
          $cx_value('CUS', 'pe') === 11.137716, var_export($cx_value('CUS', 'pe'), true));
    check('and writes no second row for the same symbol and indicator',
          (int)scalar("select count(*) from statistics where symbol='CUS' and indicator='pe' and date=?",
                      [$cx_asof]) === 1);

    /* The rankings see the corrected figures, because the correction happens
       before get_statistics.php ever looks: on the live data the cheapest P/E
       in the index was a dollar reporter that is not cheap. */
    run_script('get_statistics.php');
    $cx_rank = function ($symbol) use ($cx_asof) {
        return scalar("select percentile from statistics where symbol=? and indicator='pe' and date=?",
                      [$symbol, $cx_asof]);
    };
    check('the dollar reporter is ranked on the converted figure, behind the sterling one',
          $cx_rank('CGB') !== null && $cx_rank('CUS') !== null
          && (float)$cx_rank('CGB') > (float)$cx_rank('CUS'),
          'CGB ' . var_export($cx_rank('CGB'), true) . ', CUS ' . var_export($cx_rank('CUS'), true));
    check('the reporter with no rate is not ranked on that ratio at all',
          $cx_rank('CNO') === null || $cx_rank('CNO') === false);

    echo "\n--- the page and the model are told about the conversion ---\n";

    check('the reporting currency is read back from the symbol',
          report_currency('CUS') === 'USD' && report_currency('CGB') === 'GBP');
    check('the rate is read back for the date',
          fx_rate_for($cx_asof, 'USD') === 1.3212
          && fx_rate_for($cx_asof, 'GBP') === 1.0);
    check('a currency with no rate for the date reads as no rate, not as one',
          fx_rate_for($cx_asof, 'ZWL') === null);

    $cx_note = report_currency_note('CUS', $cx_asof);
    check('a non-sterling reporter has a note to show',
          is_array($cx_note) && $cx_note['currency'] === 'USD' && $cx_note['rate'] === 1.3212,
          json_encode($cx_note));
    check('a sterling reporter has nothing to say',
          report_currency_note('CGB', $cx_asof) === null);

    $cx_quote_src = php_strip_whitespace(dirname(__DIR__) . '/templates/quote.php');
    check('the quote page says what the company reports in and at what rate',
          strpos($cx_quote_src, 'Reports in ') !== false
          && strpos($cx_quote_src, 'ratios converted at ') !== false);
    check('and says so only when something was converted',
          preg_match('/if\s*\(\s*!empty\(\$currency_note\)/', $cx_quote_src) === 1);

    $cx_inputs = outlook_share_inputs('CUS', $cx_asof);
    check('the outlook inputs carry the reporting currency',
          ($cx_inputs['report_ccy'] ?? null) === 'USD', json_encode($cx_inputs['report_ccy'] ?? null));
    check('and the rate the ratios were converted at',
          ($cx_inputs['fx_rate_from_gbp'] ?? null) === 1.3212,
          json_encode($cx_inputs['fx_rate_from_gbp'] ?? null));
    $cx_gbp_inputs = outlook_share_inputs('CGB', $cx_asof);
    check('a sterling reporter is sent GBP and a rate of one',
          ($cx_gbp_inputs['report_ccy'] ?? null) === 'GBP'
          && ($cx_gbp_inputs['fx_rate_from_gbp'] ?? null) === 1.0);
    $cx_prompt = outlook_system_prompt('share');
    check('the model is told the ratios are already converted',
          stripos($cx_prompt, 'already been converted') !== false
          && stripos($cx_prompt, 'Do not convert them again') !== false);

    echo "\n--- the price load comes before anything that resolves a date ---\n";

    /* The as of date is the newest date in historical_prices, so the session's
       prices have to be loaded before the fundamentals: the loader stamps
       stock_info with that date and get_api_stats.py reads it back at exactly
       that date. With the price load last, the Python half of the run keyed on
       the previous session and the PHP half on the new one, and the join
       between them finds nothing when they disagree. */
    $cx_cron = implode("\n", array_filter(
        explode("\n", (string) @file_get_contents($INCLUDES . '/refresh_all.sh')),
        function ($line) { return !preg_match('/^\s*#/', $line) && trim($line) !== ''; }));
    $cx_line = function ($needle) use ($cx_cron) {
        foreach (explode("\n", $cx_cron) as $i => $line) {
            if (strpos($line, $needle) !== false) { return $i; }
        }
        return -1;
    };
    check('the nightly run loads prices before the fundamentals',
          $cx_line('get_share_prices.php') >= 0
          && $cx_line('get_share_prices.php') < $cx_line('fetch_fmp_fundamentals.py')
          && $cx_line('get_share_prices.php') < $cx_line('fetch_eodhd_fundamentals.py'),
          'prices on line ' . $cx_line('get_share_prices.php')
          . ', fmp on ' . $cx_line('fetch_fmp_fundamentals.py'));
    check('and before the job that reads the loader back',
          $cx_line('get_share_prices.php') < $cx_line('get_api_stats.py'));
    check('and before the statistics job that ranks the result',
          $cx_line('get_share_prices.php') < $cx_line('get_statistics.php'));

    echo "\n--- the currency settings are configuration, not code ---\n";

    $cx_migration = (string)file_get_contents(dirname(__DIR__) . '/sql/report_currency.sql');
    check('the migration matches indicators by name, not by id',
          preg_match('/indicator_id/i', $cx_migration) !== 1);
    foreach (['pe' => 'MULTIPLY', 'price_sales_ratio' => 'MULTIPLY',
              'price_book_ratio' => 'MULTIPLY', 'roe_ttm' => 'NONE'] as $cx_ind => $cx_mode) {
        check("$cx_ind is configured $cx_mode",
              preg_match('/fx_adjust=\'' . $cx_mode . '\'[^;]*\b' . preg_quote($cx_ind, '/') . '\b/s',
                         $cx_migration) === 1);
    }
    /* Applying it twice must change nothing. */
    $cx_statements = array_filter(array_map('trim',
        explode(';', preg_replace('/^\s*--.*$/m', '', $cx_migration))),
        function ($stmt) { return $stmt !== '' && stripos($stmt, 'select') !== 0; });
    foreach ([1, 2] as $cx_pass) {
        foreach ($cx_statements as $cx_stmt) { db()->exec($cx_stmt); }
    }
    check('the migration is re-runnable',
          (int)scalar("select count(*) from screen_indicators where name='pe' and fx_adjust='MULTIPLY'") === 1);
    check('enterprise_value_to_ebitda is left alone, with the reason written down',
          stripos($cx_migration, 'enterprise_value_to_ebitda is left at NONE') !== false);

    //Leave the universe as it was found.
    db()->exec("delete from stock_symbols where symbol in $cx_all");
    db()->exec("delete from statistics where symbol in $cx_all");
    db()->exec("delete from stock_info");
    db()->exec("delete from fx_rates");
    db()->exec("delete from screen_indicators where name in ('pe','roe_ttm')");
    reset_calculated();
    run_script('get_momentum_statistics.php');
    run_script('get_statistics.php');
    if ($cx_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $cx_saved_exchange; }

    echo "\n--- shareholder yield is a figure rather than a constant ---\n";

    /* calc_shareholder_yield() adds buyback_yield and div_yield together out
       of statistics, and neither was mapped to anything, so nothing ever
       wrote them: it added nought to nought and stored it, for every share,
       every night. shareholder_yield was then one of six names in the value
       score's hard-coded list, where a row with no percentile counts as the
       neutral 50 - so it diluted every score by the same amount and said
       nothing about anything.

       Two of the other five, enterprise_value_to_ebitda and
       price_free_cash_flow_per_share, are not rows in screen_indicators at
       all. The value score was in fact P/E, price/sales and price/book, and a
       constant. */

    $sy_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = 'XLON';
    $sy_asof = asof_date();
    $sy_all  = "('YUS','YGB','YGE','YNO')";

    $run_python_sy = function ($script) {
        $cmd = 'SM_DB_HOST=' . escapeshellarg(SERVER)
             . ' SM_DB_NAME=' . escapeshellarg(DATABASE)
             . ' SM_DB_USER=' . escapeshellarg(USERNAME)
             . ' SM_DB_PASS=' . escapeshellarg(PASSWORD)
             . ' python3 ' . escapeshellarg(dirname(__DIR__) . '/python/' . $script);
        exec($cmd . ' 2>&1', $out, $rc);
        return [implode("\n", $out), $rc];
    };

    reset_calculated();
    db()->exec("delete from stock_info");
    db()->exec("delete from fx_rates");
    db()->exec("delete from stock_symbols where symbol in $sy_all");

    /* The migration registered div_yield, buyback_yield and shareholder_yield
       already; run_tests.sh applies it. These are the three price ratios it
       moved into category 12 beside them. */
    db()->exec("delete from screen_indicators where name in ('pe','price_sales_ratio','price_book_ratio')");
    db()->exec("insert into screen_indicators
                  (name, provider_field, description, enabled, order_number,
                   calc_rank, rank_zero, rank_order, rank_negative, rank_funds,
                   fx_adjust, category)
                values
                  ('pe','Ratios.priceToEarningsRatioTTM','Price / earnings','Y',1,
                   'Y','N','value ASC','EXCLUDE','N','MULTIPLY',12),
                  ('price_sales_ratio','Ratios.priceToSalesRatioTTM','Price / sales','Y',2,
                   'Y','N','value ASC','EXCLUDE','N','MULTIPLY',12),
                  ('price_book_ratio','Ratios.priceToBookRatioTTM','Price / book','Y',3,
                   'Y','N','value ASC','EXCLUDE','N','MULTIPLY',12)");

    check('the migration registered the two components and the total',
          (int)scalar("select count(*) from screen_indicators
                        where name in ('div_yield','buyback_yield','shareholder_yield')
                          and category=12 and rank_funds='N' and rank_order='value DESC'") === 3);
    check('only the total is ranked, not the halves',
          scalar("select calc_rank from screen_indicators where name='shareholder_yield'") === 'Y'
          && scalar("select calc_rank from screen_indicators where name='div_yield'") === 'N'
          && scalar("select calc_rank from screen_indicators where name='buyback_yield'") === 'N');
    check('the total is calculated rather than fetched',
          scalar("select screen_function from screen_indicators where name='shareholder_yield'")
          === 'calc_shareholder_yield');
    /* Only one of the two is converted, and it is not the obvious one. FMP's
       dividendYieldTTM arrives currency-consistent - SHEL came back at 3.11%
       on the live run, which is $1.43 against a price near $49 - and
       dividing it by GBPUSD made it 2.35%. buyback_yield is this
       application's own arithmetic over a sterling market cap, and that one
       really does mix. */
    check('the provider dividend yield is left exactly as it arrives',
          scalar("select fx_adjust from screen_indicators where name='div_yield'") === 'NONE');
    check('the buyback yield, which this application works out, is converted',
          scalar("select fx_adjust from screen_indicators where name='buyback_yield'") === 'DIVIDE');
    check('and the total is not converted again on top of them',
          scalar("select fx_adjust from screen_indicators where name='shareholder_yield'") === 'NONE');

    /* Three reporters and one with no rate. The provider figures are what FMP
       would send: a yield computed from a reporting-currency dividend over a
       sterling price, so too large by the exchange rate.

       These are stub figures in the shape of a real response, chosen so the
       corrected yields land on the published ones - SHEL about 4%, BGEO about
       5% - not numbers read back off the live API. */
    $sy_ccy = ['YUS' => 'USD', 'YGB' => 'GBP', 'YGE' => 'GEL', 'YNO' => 'ZWL'];
    $sy_raw = ['YUS' => ['div' => 0.031100, 'buy' => 0.064341],   // SHEL shaped
               'YGB' => ['div' => 0.004500, 'buy' => 0.010500],   // RR shaped
               'YGE' => ['div' => 0.023100, 'buy' => 0.035800],   // BGEO shaped
               'YNO' => ['div' => 0.030000, 'buy' => 0.010000]];
    $sy_rate = ['USD' => 1.3212, 'GBP' => 1.0, 'GEL' => 3.58, 'ZWL' => null];

    $sy_sym = db()->prepare("insert into stock_symbols (symbol,name,exchange,enabled,sector,industry,market,report_ccy,report_ccy_asof)
                             values (?,?,'XLON','Y','Yield Test','Yield Industry','FTSE 100',?,?)");
    $sy_info = db()->prepare("insert into stock_info (symbol,asofdate,attribute,value) values (?,?,?,?)");
    foreach ($sy_ccy as $sy_s => $sy_c) {
        $sy_sym->execute([$sy_s, $sy_s . ' Test PLC', $sy_c, $sy_asof]);
        $sy_info->execute([$sy_s, $sy_asof, 'Ratios.dividendYieldTTM', (string)$sy_raw[$sy_s]['div']]);
        $sy_info->execute([$sy_s, $sy_asof, 'Derived.buybackYieldTTM', (string)$sy_raw[$sy_s]['buy']]);
        //A P/E each as well, so the value score has more than one component.
        $sy_info->execute([$sy_s, $sy_asof, 'Ratios.priceToEarningsRatioTTM', '10.0']);
    }
    $sy_fx = db()->prepare("insert into fx_rates (date,base,quote_ccy,rate,source) values (?,'GBP',?,?,?)");
    $sy_fx->execute([$sy_asof, 'USD', 1.3212, 'quote']);
    $sy_fx->execute([$sy_asof, 'GEL', 3.58, 'cross:USD']);

    [$sy_out, $sy_rc] = $run_python_sy('get_api_stats.py');
    check('get_api_stats.py writes the components', $sy_rc === 0, substr($sy_out, -400));

    $sy_value = function ($symbol, $indicator) use ($sy_asof) {
        $v = scalar("select value from statistics where symbol=? and indicator=? and date=? and exchange='XLON'",
                    [$symbol, $indicator, $sy_asof]);
        return ($v === false || $v === null) ? null : (float)$v;
    };

    /* The provider's dividend yield is already currency-consistent, so it
       must come through untouched whoever reports in what. Converting it
       turned SHEL's 3.11% into 2.35% and BGEO's 2.31% into 0.68%; a sterling
       reporter like LGEN was unaffected either way, which is why it took a
       non-sterling one to show it. */
    check('a dollar reporter\'s dividend yield arrives exactly as the provider sent it',
          $sy_value('YUS', 'div_yield') === 0.0311,
          var_export($sy_value('YUS', 'div_yield'), true));
    check('and a lari reporter\'s',
          $sy_value('YGE', 'div_yield') === 0.0231,
          var_export($sy_value('YGE', 'div_yield'), true));
    check('and a sterling reporter\'s',
          $sy_value('YGB', 'div_yield') === 0.0045);

    /* The buyback yield does mix: repurchases from the accounts over a
       sterling market cap. Too large by the rate, the opposite way round
       from a P/E. */
    check('a dollar reporter\'s buyback yield is divided by the rate',
          abs($sy_value('YUS', 'buyback_yield') - 0.064341 / 1.3212) < 1e-6,
          var_export($sy_value('YUS', 'buyback_yield'), true));
    check('a lari reporter\'s uses the crossed rate',
          abs($sy_value('YGE', 'buyback_yield') - 0.0358 / 3.58) < 1e-6,
          var_export($sy_value('YGE', 'buyback_yield'), true));
    check('a sterling reporter\'s is untouched',
          $sy_value('YGB', 'buyback_yield') === 0.0105);
    check('dividing is not the same as multiplying (the two paths are distinct)',
          $sy_value('YUS', 'buyback_yield') < 0.064341
          && $sy_value('YUS', 'pe') > 10.0,
          'buyback ' . var_export($sy_value('YUS', 'buyback_yield'), true)
          . ', pe ' . var_export($sy_value('YUS', 'pe'), true));
    check('a reporter with no rate still gets the yield that needs no rate',
          $sy_value('YNO', 'div_yield') === 0.03
          && $sy_value('YNO', 'buyback_yield') === null,
          json_encode([$sy_value('YNO', 'div_yield'), $sy_value('YNO', 'buyback_yield')]));

    //The total, calculated by get_statistics.php from the two components.
    run_script('get_statistics.php');

    foreach (['YUS', 'YGB', 'YGE'] as $sy_s) {
        $sy_total = $sy_value($sy_s, 'shareholder_yield');
        $sy_sum   = $sy_value($sy_s, 'div_yield') + $sy_value($sy_s, 'buyback_yield');
        check("$sy_s's shareholder yield is its dividend plus its buybacks",
              $sy_total !== null && abs($sy_total - $sy_sum) < 1e-6,
              var_export($sy_total, true) . ' vs ' . $sy_sum);
        check("and it is not zero, which is what it always used to be",
              $sy_total > 0.0, var_export($sy_total, true));
    }

    /* The yields the stub figures produce, printed so the arithmetic can be
       checked against the published numbers rather than taken on trust. */
    echo "      stub yields (fractions, as the provider sends them):\n";
    foreach (['YUS' => 'SHEL-shaped (USD)', 'YGB' => 'RR-shaped (GBP)',
              'YGE' => 'BGEO-shaped (GEL)'] as $sy_s => $sy_label) {
        printf("        %-20s div %.4f  buyback %.4f  shareholder %.4f  (%.2f%%)\n",
               $sy_label, $sy_value($sy_s, 'div_yield'), $sy_value($sy_s, 'buyback_yield'),
               $sy_value($sy_s, 'shareholder_yield'),
               $sy_value($sy_s, 'shareholder_yield') * 100);
    }
    check('the dollar reporter\'s dividend yield is its published one, about 3.1%',
          abs($sy_value('YUS', 'div_yield') - 0.031) < 0.0005,
          var_export($sy_value('YUS', 'div_yield'), true));
    check('and its buyback yield about 4.9%, which is $14bn against $284bn',
          abs($sy_value('YUS', 'buyback_yield') - 0.0487) < 0.0005,
          var_export($sy_value('YUS', 'buyback_yield'), true));
    check('and the lari reporter\'s dividend yield about 2.3%',
          abs($sy_value('YGE', 'div_yield') - 0.0231) < 0.0005,
          var_export($sy_value('YGE', 'div_yield'), true));

    check('a company with one component gets it alone rather than nothing',
          $sy_value('YNO', 'shareholder_yield') === 0.03,
          var_export($sy_value('YNO', 'shareholder_yield'), true));

    /* And one with neither still gets no row, rather than the old zero. */
    db()->prepare("delete from statistics where symbol='YNO' and date=?
                    and indicator in ('div_yield','buyback_yield','shareholder_yield')")
        ->execute([$sy_asof]);
    run_script('get_statistics.php');
    check('a company with neither component gets no shareholder yield row, not a zero',
          $sy_value('YNO', 'shareholder_yield') === null,
          var_export($sy_value('YNO', 'shareholder_yield'), true));

    //It is ranked, so it counts in the value score.
    check('the total is ranked',
          scalar("select percentile from statistics where symbol='YUS'
                   and indicator='shareholder_yield' and date=?", [$sy_asof]) !== null);
    check('the halves are not',
          scalar("select percentile from statistics where symbol='YUS'
                   and indicator='div_yield' and date=?", [$sy_asof]) === null);
    check('a higher yield ranks better than a lower one',
          (float)scalar("select percentile from statistics where symbol='YUS'
                          and indicator='shareholder_yield' and date=?", [$sy_asof])
          > (float)scalar("select percentile from statistics where symbol='YGB'
                            and indicator='shareholder_yield' and date=?", [$sy_asof]));

    //A fund is not ranked on it.
    db()->exec("update stock_symbols set sector='Investment Trusts' where symbol='YGE'");
    run_script('get_statistics.php');
    check('a fund is not ranked on shareholder yield',
          scalar("select percentile from statistics where symbol='YGE'
                   and indicator='shareholder_yield' and date=?", [$sy_asof]) === null);
    check('but it still has the figure, which is a fact about it',
          $sy_value('YGE', 'shareholder_yield') !== null);
    db()->exec("update stock_symbols set sector='Yield Test' where symbol='YGE'");

    echo "\n--- one score row per company, however many runs ---\n";

    /* One run wrote two value_score rows for every company: 542 rows for 271
       companies on the live run for 2026-11-13. The three score queries
       grouped by symbol AND si.rank_zero, and category 12 has both values in
       it now - the price ratios are 'N', the yields are 'Y' - so each symbol
       came out of the inner select twice and was inserted twice.

       The suite had a duplicate check already and it passed, because the
       seeded universe had no category 12 indicator at all and so no
       value_score rows to duplicate. This section has the mixed set, which
       is the shape that breaks it. */

    reset_calculated();
    db()->exec("truncate price_valuation");
    $sy_ratio2 = db()->prepare("insert into statistics (symbol,date,indicator,value,exchange) values (?,?,?,?,'XLON')");
    foreach (['YUS' => 1.0, 'YGB' => 2.0, 'YGE' => 3.0, 'YNO' => 4.0] as $sy_s => $sy_pe) {
        $sy_ratio2->execute([$sy_s, $sy_asof, 'pe', $sy_pe]);
        $sy_ratio2->execute([$sy_s, $sy_asof, 'div_yield', 0.03]);
        $sy_ratio2->execute([$sy_s, $sy_asof, 'buyback_yield', 0.01]);
    }
    check('the fixture has both rank_zero values in the value category, which is what broke it',
          (int)scalar("select count(distinct rank_zero) from screen_indicators
                        where enabled='Y' and category=12 and calc_rank='Y'") === 2,
          scalar("select group_concat(distinct rank_zero) from screen_indicators
                   where enabled='Y' and category=12 and calc_rank='Y'"));

    run_script('get_momentum_statistics.php');
    run_script('get_statistics.php');

    $sy_dupes = function () use ($sy_asof) {
        /* "rows" is reserved in MariaDB 10.11, so the column is not called
           that however well it would read. */
        return rows("select indicator, count(*) written, count(distinct symbol) companies
                       from statistics where date=? and indicator like '%\\_score'
                      group by indicator having count(*) <> count(distinct symbol)",
                    [$sy_asof]);
    };
    check('one run writes one row per company for every score',
          count($sy_dupes()) === 0, json_encode($sy_dupes()));
    check('and value_score is among the scores it wrote, so that is not vacuous',
          (int)scalar("select count(*) from statistics where date=? and indicator='value_score'",
                      [$sy_asof]) > 0);

    $sy_counts = [];
    foreach (rows("select indicator, count(*) n from statistics
                    where date=? and indicator like '%\\_score' group by indicator", [$sy_asof]) as $sy_r) {
        $sy_counts[$sy_r['indicator']] = (int)$sy_r['n'];
    }
    run_script('get_statistics.php');
    $sy_after = [];
    foreach (rows("select indicator, count(*) n from statistics
                    where date=? and indicator like '%\\_score' group by indicator", [$sy_asof]) as $sy_r) {
        $sy_after[$sy_r['indicator']] = (int)$sy_r['n'];
    }
    /* quality_score is legitimately absent here: nothing in this fixture is
       in category 10. The three that are written have to be unchanged. */
    check('a second run for the same date leaves every score count where it was',
          $sy_counts === $sy_after
          && isset($sy_counts['value_score'], $sy_counts['momentum_score'],
                   $sy_counts['overall_score']),
          json_encode($sy_counts) . ' then ' . json_encode($sy_after));
    check('and still one row per company',
          count($sy_dupes()) === 0, json_encode($sy_dupes()));

    echo "\n--- the value score is built from what exists ---\n";

    run_script('get_statistics.php');

    $sy_components = rows("select name from screen_indicators
                            where enabled='Y' and category=12 and calc_rank='Y' order by name");
    check('the components come from the database, not from the query',
          array_column($sy_components, 'name') === ['pe','price_book_ratio','price_sales_ratio','shareholder_yield'],
          json_encode(array_column($sy_components, 'name')));
    $sy_stats_src = php_strip_whitespace($INCLUDES . '/get_statistics.php');
    check('the hard-coded list is gone from the value ranking',
          strpos($sy_stats_src, "'enterprise_value_to_ebitda','price_free_cash_flow_per_share'") === false
          && strpos($sy_stats_src, "si.category=12 and si.calc_rank='Y'") !== false);

    /* A share missing a component must not be scored down for the gap, and
       the way to show that is a case where summing and averaging disagree
       about the order.

       Four shares, with the percentiles the ranking will give them:

         YUS  pe 1                     -> 75
         YGB  pe 2, price/sales 1      -> 50, 50
         YGE  pe 3, price/sales 2      -> 25,  0
         YNO  pe 4                     ->  0

       Averaged, YUS is the best of them at 75 and YGB second at 50.
       Summed, YGB is first on 100 and YUS second on 75 - better not because
       it is cheaper but because it has one more figure. */
    db()->exec("delete from statistics where indicator in
                  ('pe','price_sales_ratio','price_book_ratio','value_score',
                   'div_yield','buyback_yield','shareholder_yield')");
    $sy_ratio = db()->prepare("insert into statistics (symbol,date,indicator,value,exchange) values (?,?,?,?,'XLON')");
    foreach (['YUS' => 1.0, 'YGB' => 2.0, 'YGE' => 3.0, 'YNO' => 4.0] as $sy_s => $sy_pe) {
        $sy_ratio->execute([$sy_s, $sy_asof, 'pe', $sy_pe]);
    }
    foreach (['YGB' => 1.0, 'YGE' => 2.0] as $sy_s => $sy_ps) {
        $sy_ratio->execute([$sy_s, $sy_asof, 'price_sales_ratio', $sy_ps]);
    }
    run_script('get_statistics.php');

    $sy_score = function ($symbol) use ($sy_asof) {
        $v = scalar("select value from statistics where symbol=? and indicator='value_score' and date=?",
                    [$symbol, $sy_asof]);
        return ($v === false || $v === null) ? null : (float)$v;
    };
    $sy_pct = function ($symbol, $indicator) use ($sy_asof) {
        $v = scalar("select percentile from statistics where symbol=? and indicator=? and date=?",
                    [$symbol, $indicator, $sy_asof]);
        return ($v === false || $v === null) ? null : (float)$v;
    };

    check('the components ranked as the arithmetic above assumes',
          $sy_pct('YUS','pe') === 75.0 && $sy_pct('YGB','pe') === 50.0
          && $sy_pct('YGB','price_sales_ratio') === 50.0
          && $sy_pct('YGE','price_sales_ratio') === 0.0,
          json_encode([$sy_pct('YUS','pe'), $sy_pct('YGB','pe'),
                       $sy_pct('YGB','price_sales_ratio'), $sy_pct('YGE','price_sales_ratio')]));
    check('a share with only one component is still scored',
          $sy_score('YUS') !== null && $sy_score('YNO') !== null,
          json_encode([$sy_score('YUS'), $sy_score('YNO')]));
    check('the cheaper share with fewer figures beats the dearer one with more',
          $sy_score('YUS') > $sy_score('YGB'),
          'YUS ' . var_export($sy_score('YUS'), true)
          . ', YGB ' . var_export($sy_score('YGB'), true)
          . ' - summing rather than averaging puts YGB first on count alone');
    check('the score is an average in the query, not only in its effect',
          preg_match('/sum\(if\(percentile is null,50,percentile\)\)\/count\(\*\)/', $sy_stats_src) === 1);

    echo "\n--- the outlook is sent the yields ---\n";

    db()->exec("delete from statistics where indicator='value_score'");
    run_script('get_statistics.php');
    $sy_inputs = outlook_share_inputs('YUS', $sy_asof);
    foreach (['dividend_yield', 'buyback_yield', 'shareholder_yield'] as $sy_key) {
        check("the outlook inputs carry $sy_key",
              array_key_exists($sy_key, $sy_inputs['valuation']),
              json_encode(array_keys($sy_inputs['valuation'])));
    }
    check('the shareholder yield sent is the converted one',
          abs($sy_inputs['valuation']['shareholder_yield']['value']
              - $sy_value('YUS', 'shareholder_yield')) < 1e-9);
    check('and the model is told they are fractions rather than percentages',
          stripos($sy_inputs['valuation']['yield_note'], 'fractions') !== false);

    //Leave the universe as it was found.
    db()->exec("delete from stock_symbols where symbol in $sy_all");
    db()->exec("delete from statistics where symbol in $sy_all");
    db()->exec("delete from stock_info");
    db()->exec("delete from fx_rates");
    db()->exec("delete from screen_indicators where name in ('pe','price_sales_ratio','price_book_ratio')");
    reset_calculated();
    run_script('get_momentum_statistics.php');
    run_script('get_statistics.php');
    if ($sy_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $sy_saved_exchange; }

    echo "\n--- the files nothing uses are out of the live tree ---\n";

    /* Each was checked for an include, a require, a render, a link, a cron
       entry and a mention in refresh_all.sh before it moved. */
    $arch_moved = [
        'includes/get_api_stats.php'            => 'archive/includes/get_api_stats.php',
        'includes/test_calc_valuation.php'      => 'archive/includes/test_calc_valuation.php',
        'includes/test_earnings_growth.php'     => 'archive/includes/test_earnings_growth.php',
        'includes/test_rank.php'                => 'archive/includes/test_rank.php',
        'includes/share_functions.php.080418'   => 'archive/includes/share_functions.php.080418',
        'includes/share_functions.php.230623'   => 'archive/includes/share_functions.php.230623',
        'templates/share_functions.php'         => 'archive/templates/share_functions.php',
        'templates/quote.php.111118'            => 'archive/templates/quote.php.111118',
        'templates/testheader.php'              => 'archive/templates/testheader.php',
    ];
    $arch_repo = dirname(__DIR__);
    foreach ($arch_moved as $arch_was => $arch_now) {
        check("$arch_was is out of the live tree", !file_exists("$arch_repo/$arch_was"));
        check("it is kept at $arch_now", file_exists("$arch_repo/$arch_now"));
    }

    /* Nothing in what is served or run refers to any of them. Searched by
       path rather than by basename: "share_functions.php" is the live library
       and matches everywhere, which is why the templates copy looked
       referenced until it was looked at properly. */
    $arch_referenced = [];
    $arch_live = array_merge(
        glob("$arch_repo/{includes,public,templates}/*.php", GLOB_BRACE),
        glob("$arch_repo/{includes,public,templates}/*.{js,sh}", GLOB_BRACE),
        glob("$arch_repo/public/js/*.js"));
    foreach ($arch_live as $arch_file) {
        /* A path that points into archive/ is not a reference to a live file,
           and templates/scripts.js has a comment that names one. Searching
           the raw text finds the comment and reports the wrong answer; this
           is the sixth time that trap has bitten here, and it is always the
           comment. */
        $arch_text = preg_replace('#archive/\S+#', '',
                                  (string)file_get_contents($arch_file));
        foreach ($arch_moved as $arch_was => $arch_now) {
            $arch_name = basename($arch_was);
            /* The library's own basename is shared with the live copy, so for
               that one only a path into templates/ counts as a reference. */
            $arch_needle = ($arch_name === 'share_functions.php') ? 'templates/share_functions.php' : $arch_name;
            if (strpos($arch_text, $arch_needle) !== false) {
                $arch_referenced[] = basename($arch_file) . ' -> ' . $arch_was;
            }
        }
    }
    check('nothing served or run refers to an archived file',
          count($arch_referenced) === 0, implode(', ', $arch_referenced));

    $arch_cron = (string)@file_get_contents($INCLUDES . '/refresh_all.sh');
    foreach (array_keys($arch_moved) as $arch_was) {
        check('the nightly run does not call ' . basename($arch_was),
              strpos($arch_cron, basename($arch_was)) === false);
    }

    check('the archive says what each one was and why it went',
          substr_count((string)file_get_contents("$arch_repo/archive/README.md"),
                       'get_api_stats.php') >= 1
          && strpos((string)file_get_contents("$arch_repo/archive/README.md"), 'testheader.php') !== false);

    /* get_sector_average() and get_industry_average() read statistic_averages
       for calc_implied_valuation(), which uses peer_median() now. */
    $arch_lib = php_strip_whitespace($INCLUDES . '/share_functions.php');
    check('the two superseded average readers are gone',
          strpos($arch_lib, 'function get_sector_average') === false
          && strpos($arch_lib, 'function get_industry_average') === false);
    $arch_callers = [];
    foreach ($arch_live as $arch_file) {
        if (preg_match('/\bget_(sector|industry)_average\s*\(/',
                       php_strip_whitespace($arch_file))) {
            $arch_callers[] = basename($arch_file);
        }
    }
    check('and nothing called them', count($arch_callers) === 0, implode(', ', $arch_callers));

    echo "\n--- Piotroski, Altman and growth come from the provider ---\n";

    /* This application worked Piotroski and Altman out for itself from
       financial_statement_values, which is empty on the live database. So
       neither score was ever written, the quote page's health card had been
       commented out for years, and the three links behind it called onclick
       handlers that exist in no script. earnings_growth was the same from
       the other end: registered, never filled, because its calculation
       needs EPS estimates no loader writes.

       What is not moving: close_price, ma50, ma200 and the momentum series
       stay local. The provider's 200-day average for RR is 1338.8005,
       identical to this application's, and the history behind it is what the
       screens and backtests read. */

    $hs_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = 'XLON';
    $hs_asof = asof_date();
    $hs_all  = "('HMAN','HBNK','HLOW','HMID')";

    $run_python_hs = function ($script) {
        $cmd = 'SM_DB_HOST=' . escapeshellarg(SERVER)
             . ' SM_DB_NAME=' . escapeshellarg(DATABASE)
             . ' SM_DB_USER=' . escapeshellarg(USERNAME)
             . ' SM_DB_PASS=' . escapeshellarg(PASSWORD)
             . ' python3 ' . escapeshellarg(dirname(__DIR__) . '/python/' . $script);
        exec($cmd . ' 2>&1', $out, $rc);
        return [implode("\n", $out), $rc];
    };

    reset_calculated();
    db()->exec("delete from stock_info");
    db()->exec("delete from fx_rates");
    db()->exec("delete from stock_symbols where symbol in $hs_all");

    check('the migration registered both scores in the quality category',
          (int)scalar("select count(*) from screen_indicators
                        where name in ('piotroski_score','altman_z')
                          and category=10 and calc_rank='Y' and rank_order='value DESC'
                          and rank_funds='N' and fx_adjust='NONE'") === 2);
    check('and the two growth rates, unranked, in their own category',
          (int)scalar("select count(*) from screen_indicators
                        where name in ('revenue_growth','earnings_growth')
                          and category=11 and calc_rank='N'") === 2);
    check('earnings_growth is mapped to the provider rather than calculated',
          scalar("select provider_field from screen_indicators where name='earnings_growth'")
          === 'Derived.epsGrowth'
          && scalar("select ifnull(screen_function,'') from screen_indicators
                      where name='earnings_growth'") === '');
    check('the scores this application used to work out itself are disabled',
          (int)scalar("select count(*) from screen_indicators
                        where name in ('piotroski_fscore','altman_zscore','altman_zscore_nonman')
                          and enabled='Y'") === 0);

    /* Three companies: a manufacturer, a bank and a weak manufacturer. roe
       is there so the quality score has a second component to average. */
    db()->exec("delete from screen_indicators where name='roe_ttm'");
    db()->exec("insert into screen_indicators
                  (name, provider_field, description, enabled, order_number,
                   calc_rank, rank_zero, rank_order, rank_negative, rank_funds,
                   fx_adjust, category)
                values ('roe_ttm','KeyMetrics.returnOnEquityTTM','Return on equity','Y',2,
                        'Y','N','value DESC','NORMAL','N','NONE',10)");

    /* Arranged so that summing and averaging disagree about the order, which
       is the only way to show that the quality score averages. The component
       percentiles that follow from these, over four symbols for roe and
       Piotroski and three for Altman:

                  roe   piotroski   altman      average   sum
         HBNK      75          75   (excluded)       75   150
         HMID      50          50          67        56   167
         HMAN      25          25          33        28    83
         HLOW       0           0           0         0     0

       So the bank, scored on the two measures that apply to it, is first on
       an average and second on a sum - behind a company that is worse on
       both of them and has one more figure. */
    $hs_rows = ['HMAN' => ['sector' => 'Industrials',        'p' => 7, 'z' => 3.09, 'roe' => 0.10],
                'HMID' => ['sector' => 'Industrials',        'p' => 8, 'z' => 4.00, 'roe' => 0.20],
                'HBNK' => ['sector' => 'Financial Services', 'p' => 9, 'z' => 0.40, 'roe' => 0.30],
                'HLOW' => ['sector' => 'Industrials',        'p' => 2, 'z' => 1.10, 'roe' => 0.05]];
    $hs_sym  = db()->prepare("insert into stock_symbols (symbol,name,exchange,enabled,sector,industry,market,report_ccy,report_ccy_asof,is_fund)
                              values (?,?,'XLON','Y',?,'Health Industry','FTSE 250','GBP',?,'N')");
    $hs_info = db()->prepare("insert into stock_info (symbol,asofdate,attribute,value) values (?,?,?,?)");
    foreach ($hs_rows as $hs_s => $hs_r) {
        $hs_sym->execute([$hs_s, $hs_s . ' Test PLC', $hs_r['sector'], $hs_asof]);
        $hs_info->execute([$hs_s, $hs_asof, 'Scores.piotroskiScore', (string)$hs_r['p']]);
        $hs_info->execute([$hs_s, $hs_asof, 'Scores.altmanZScore', (string)$hs_r['z']]);
        $hs_info->execute([$hs_s, $hs_asof, 'KeyMetrics.returnOnEquityTTM', (string)$hs_r['roe']]);
        $hs_info->execute([$hs_s, $hs_asof, 'Growth.revenueGrowth', '0.0512']);
        $hs_info->execute([$hs_s, $hs_asof, 'Derived.epsGrowth', '0.1834']);
    }

    [$hs_out, $hs_rc] = $run_python_hs('get_api_stats.py');
    check('get_api_stats.py loads them', $hs_rc === 0, substr($hs_out, -400));

    $hs_value = function ($symbol, $indicator) use ($hs_asof) {
        $v = scalar("select value from statistics where symbol=? and indicator=? and date=? and exchange='XLON'",
                    [$symbol, $indicator, $hs_asof]);
        return ($v === false || $v === null) ? null : (float)$v;
    };
    $hs_pct = function ($symbol, $indicator) use ($hs_asof) {
        $v = scalar("select percentile from statistics where symbol=? and indicator=? and date=?",
                    [$symbol, $indicator, $hs_asof]);
        return ($v === false || $v === null) ? null : (float)$v;
    };

    check('the Piotroski score arrives as the provider sent it',
          $hs_value('HMAN', 'piotroski_score') === 7.0,
          var_export($hs_value('HMAN', 'piotroski_score'), true));
    check('and the Altman Z',
          $hs_value('HMAN', 'altman_z') === 3.09,
          var_export($hs_value('HMAN', 'altman_z'), true));
    check('neither is touched by the currency conversion, being unitless',
          $hs_value('HBNK', 'altman_z') === 0.40 && $hs_value('HLOW', 'piotroski_score') === 2.0);
    check('revenue growth is loaded',
          $hs_value('HMAN', 'revenue_growth') === 0.0512);
    check('and EPS growth, under the indicator that was never filled',
          $hs_value('HMAN', 'earnings_growth') === 0.1834,
          var_export($hs_value('HMAN', 'earnings_growth'), true));

    run_script('get_statistics.php');

    check('the Piotroski score is ranked, so it counts in the quality score',
          $hs_pct('HMAN', 'piotroski_score') !== null
          && $hs_pct('HMAN', 'piotroski_score') > $hs_pct('HLOW', 'piotroski_score'),
          json_encode([$hs_pct('HMAN', 'piotroski_score'), $hs_pct('HLOW', 'piotroski_score')]));
    check('the growth rates are not ranked, because nothing scores on them',
          $hs_pct('HMAN', 'revenue_growth') === null
          && $hs_pct('HMAN', 'earnings_growth') === null);

    echo "\n--- Altman Z is not a measure of a bank ---\n";

    /* Altman's Z was fitted on manufacturers and reads a bank's balance
       sheet as distress: leverage is the business, not a warning. HBNK's Z
       of 0.40 would be the worst in the set and would drag its quality score
       down for being a bank. */
    check('a manufacturer is ranked on it',
          $hs_pct('HMAN', 'altman_z') !== null && $hs_pct('HLOW', 'altman_z') !== null);
    check('a financial is not',
          $hs_pct('HBNK', 'altman_z') === null,
          var_export($hs_pct('HBNK', 'altman_z'), true));
    check('but the figure is still stored, so the page can show it',
          $hs_value('HBNK', 'altman_z') === 0.40);
    check('the exclusion is a row, not a name in the code',
          (int)scalar("select count(*) from indicator_sector_exclusions
                        where indicator='altman_z' and sector='Financial Services'") === 1);
    $hs_lib = php_strip_whitespace($INCLUDES . '/share_functions.php');
    $hs_job = php_strip_whitespace($INCLUDES . '/get_statistics.php');
    check('no sector name appears in the application code',
          strpos($hs_lib, 'Financial Services') === false
          && strpos($hs_job, 'Financial Services') === false);
    check('and the reason is on record for the page to quote',
          stripos((string)sector_exclusion_reason('altman_z', 'HBNK'), 'leverage') !== false,
          var_export(sector_exclusion_reason('altman_z', 'HBNK'), true));
    check('a manufacturer has no such reason',
          sector_exclusion_reason('altman_z', 'HMAN') === null);

    echo "\n--- the quality score averages what applies ---\n";

    /* Two components for the bank, three for the manufacturers. Summing
       would score the bank down for the one that does not apply to it. */
    $hs_components = function ($symbol) use ($hs_asof) {
        return (int)scalar("select count(*) from statistics s, screen_indicators si
                             where s.indicator=si.name and s.symbol=? and s.date=?
                               and si.category=10 and si.enabled='Y' and si.calc_rank='Y'
                               and s.percentile is not null", [$symbol, $hs_asof]);
    };
    check('the bank is scored on two components and the manufacturers on three',
          $hs_components('HBNK') === 2 && $hs_components('HMAN') === 3
          && $hs_components('HMID') === 3,
          json_encode([$hs_components('HBNK'), $hs_components('HMAN'), $hs_components('HMID')]));

    $hs_quality = function ($symbol) use ($hs_asof) {
        $v = scalar("select value from statistics where symbol=? and indicator='quality_score' and date=?",
                    [$symbol, $hs_asof]);
        return ($v === false || $v === null) ? null : (float)$v;
    };
    check('all four are scored',
          $hs_quality('HMAN') !== null && $hs_quality('HBNK') !== null
          && $hs_quality('HMID') !== null && $hs_quality('HLOW') !== null,
          json_encode([$hs_quality('HMAN'), $hs_quality('HMID'),
                       $hs_quality('HBNK'), $hs_quality('HLOW')]));
    check('the components ranked as the table above assumes',
          $hs_pct('HBNK', 'roe_ttm') === 75.0 && $hs_pct('HMID', 'roe_ttm') === 50.0
          && $hs_pct('HBNK', 'piotroski_score') === 75.0
          && $hs_pct('HMID', 'piotroski_score') === 50.0
          && $hs_pct('HMID', 'altman_z') === 67.0,
          json_encode([$hs_pct('HBNK','roe_ttm'), $hs_pct('HMID','roe_ttm'),
                       $hs_pct('HBNK','piotroski_score'), $hs_pct('HMID','piotroski_score'),
                       $hs_pct('HMID','altman_z')]));
    /* The bank is better than HMID on both measures that apply to it. On an
       average it is first; on a sum HMID's third component carries it past,
       which is a reward for having a figure rather than for being sound. */
    check('a bank beats a company that is worse on both measures that apply to it',
          $hs_quality('HBNK') > $hs_quality('HMID'),
          'HBNK ' . var_export($hs_quality('HBNK'), true)
          . ', HMID ' . var_export($hs_quality('HMID'), true)
          . ' - summing rather than averaging puts HMID first on count alone');
    check('the quality score is an average in the query, not only in its effect',
          preg_match('/category = 10[^"]*sum\(if\(percentile is null,50,percentile\)\)\/count\(\*\)/s', $hs_job) === 1
          || preg_match('/sum\(if\(percentile is null,50,percentile\)\)\/count\(\*\)[^"]*category = 10/s', $hs_job) === 1);

    echo "\n--- the quote page shows the provider's scores ---\n";

    $hs_health = get_health_scores('HMAN');
    check('the page reader returns both scores with their percentiles',
          $hs_health['piotroski_score']['value'] === 7.0
          && $hs_health['altman_z']['value'] === 3.09
          && $hs_health['altman_z']['percentile'] !== null,
          json_encode($hs_health));
    $hs_bank = get_health_scores('HBNK');
    check('and says why the bank\'s Altman is not ranked',
          $hs_bank['altman_z']['value'] === 0.40
          && $hs_bank['altman_z']['percentile'] === null
          && stripos((string)$hs_bank['altman_z']['not_ranked'], 'leverage') !== false,
          json_encode($hs_bank['altman_z']));

    $hs_tpl = php_strip_whitespace(dirname(__DIR__) . '/templates/quote.php');
    check('the health card is no longer commented out',
          strpos($hs_tpl, 'Financial Health') !== false);
    check('the links that called handlers nothing defines are gone',
          strpos($hs_tpl, 'load_piotroski_modal') === false
          && strpos($hs_tpl, 'load_altman_modal') === false
          && strpos($hs_tpl, 'load_altman_nonman_modal') === false);
    check('and so are the modals that read a table nothing writes',
          strpos($hs_tpl, 'piotroski_variables') === false
          && strpos($hs_tpl, 'altman_variables') === false
          && strpos($hs_tpl, 'altman_nonman_variables') === false);
    check('the page says when a score is shown but not ranked',
          strpos($hs_tpl, 'not ranked') !== false
          && strpos($hs_tpl, 'does not count towards the quality score') !== false);

    echo "\n--- the calculations they replace are gone ---\n";

    /* Nothing in the served tree may still call them: the functions have
       been deleted, so a caller is a fatal rather than a wrong number. */
    $hs_dead = ['calc_piotroski_fscore', 'calc_altman_zscore', 'calc_altman_zscore_nonman',
                'get_piotroski_fscore', 'get_altman_zscore', 'get_altman_zscore_nonman',
                'get_piotroski_variables', 'get_altman_variables', 'get_altman_nonman_variables'];
    $hs_live = array_merge(
        glob(dirname(__DIR__) . '/{includes,public,templates}/*.php', GLOB_BRACE),
        glob(dirname(__DIR__) . '/{includes,public,templates}/*.{js,sh}', GLOB_BRACE));
    $hs_found = [];
    foreach ($hs_live as $hs_file) {
        $hs_text = php_strip_whitespace($hs_file);
        foreach ($hs_dead as $hs_fn) {
            if (preg_match('/\b' . preg_quote($hs_fn, '/') . '\s*\(/', $hs_text)) {
                $hs_found[] = basename($hs_file) . ' -> ' . $hs_fn;
            }
        }
    }
    check('nothing defines or calls the removed calculations',
          count($hs_found) === 0, implode(', ', $hs_found));
    check('nothing in the live tree writes health_indicators any more',
          count(array_filter($hs_live, function ($f) {
              return strpos(php_strip_whitespace($f), 'into health_indicators') !== false;
          })) === 0);
    /* The nightly job must not try to dispatch to a function that is gone. */
    check('no indicator is registered against a removed calculation',
          (int)scalar("select count(*) from screen_indicators
                        where screen_function in ('calc_piotroski_fscore','calc_altman_zscore',
                                                  'calc_altman_zscore_nonman')") === 0);

    echo "\n--- the outlook is sent the scores and the growth ---\n";

    $hs_inputs = outlook_share_inputs('HMAN', $hs_asof);
    check('the outlook carries the Piotroski score and the Altman Z',
          ($hs_inputs['health']['piotroski_score'] ?? null) === 7.0
          && ($hs_inputs['health']['altman_z'] ?? null) === 3.09,
          json_encode($hs_inputs['health'] ?? null));
    check('and the two growth rates',
          ($hs_inputs['growth']['revenue_growth'] ?? null) === 0.0512
          && ($hs_inputs['growth']['eps_growth'] ?? null) === 0.1834,
          json_encode($hs_inputs['growth'] ?? null));
    check('with a note on how to read them',
          stripos($hs_inputs['health']['altman_z_note'], 'not meaningful for financials') !== false
          && stripos($hs_inputs['growth']['growth_note'], 'fractions') !== false);

    //Leave the universe as it was found.
    db()->exec("delete from stock_symbols where symbol in $hs_all");
    db()->exec("delete from statistics where symbol in $hs_all");
    db()->exec("delete from stock_info");
    db()->exec("delete from screen_indicators where name='roe_ttm'");
    reset_calculated();
    run_script('get_momentum_statistics.php');
    run_script('get_statistics.php');
    if ($hs_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $hs_saved_exchange; }

    echo "\n--- the price log names the provider that actually ran ---\n";

    /* An FMP run logged "EODHD 2021-11-15 to 2026-11-13: 350 symbols loaded". */
    $share_src = (string)file_get_contents(dirname(__DIR__) . '/includes/share_functions.php');
    $at = strpos($share_src, 'symbols loaded');
    $summary = $at === false ? '' : substr($share_src, max(0, $at - 400), 500);
    check('the price summary does not hard-code a provider name',
          stripos($summary, '"EODHD ') === false && stripos($summary, '"FMP ') === false,
          trim(substr($summary, 0, 160)));
    check('the price summary reports the configured provider',
          strpos($summary, 'data_provider()') !== false);

    echo "\n--- no function is defined in both public/ and includes/ ---\n";

    /* Visiting /share_screen.php was a fatal: public/share_screen.php defined
       insert_criteria, then config.php pulled in includes/share_screen.php,
       which declared it again. */
    $defined = [];
    foreach (['public', 'includes'] as $dir) {
        foreach (glob(dirname(__DIR__) . "/$dir/*.php") as $file) {
            $src = (string)file_get_contents($file);
            if (preg_match_all('/^\s*function\s+([A-Za-z_]\w*)\s*\(/m', $src, $m)) {
                foreach ($m[1] as $fn) { $defined[$fn][$dir] = basename($file); }
            }
        }
    }
    $clashes = [];
    foreach ($defined as $fn => $where) {
        if (count($where) > 1) {
            $clashes[] = $fn . ' (' . implode(', ', $where) . ')';
        }
    }
    check('no function is declared in both public/ and includes/',
          count($clashes) === 0, implode('; ', $clashes));

    echo "\n--- the investing vault: which notes go to the model ---\n";

    /* The vault is the owner's own notes, synced from elsewhere, and it is
       what makes an outlook theirs rather than generic. It is also allowed to
       be absent, so every one of these has to hold with and without it. */
    $vault = sys_get_temp_dir() . '/smvault' . getmypid();
    @mkdir($vault, 0777, true);
    $write_note = function ($name, $tags, $body) use ($vault) {
        file_put_contents("$vault/$name.md", "---\ntags: [" . implode(', ', $tags) . "]\n---\n" . $body);
    };
    array_map('unlink', glob("$vault/*.md") ?: []);
    $write_note('position-sizing', ['policy', 'risk'],  'Never more than 5% in one name.');
    $write_note('what-i-look-at',  ['indicators'],      'Trend first, then quality, then price.');
    $write_note('industrials',     ['industrials'],     'Cyclical; check the order book.');
    $write_note('trend-following', ['trend-following'], 'Hold above the 200 day average.');
    $write_note('market-regime',   ['market'],          'Breadth leads the index.');
    $write_note('unrelated',       ['recipes'],         'Not about investing at all.');

    $all_notes = vault_notes($vault);
    check('every note in the vault is read', count($all_notes) === 6, count($all_notes) . ' notes');
    check('frontmatter tags are parsed', in_array('policy', $all_notes[3]['tags'] ?? [], true)
          || in_array('policy', array_merge(...array_column($all_notes, 'tags')), true));

    /* A block list is the other way people write frontmatter tags, and the
       owner's vault is hand written. */
    file_put_contents("$vault/block-style.md", "---\ntitle: Block\ntags:\n  - policy\n  - sizing\nmore: yes\n---\nBody here.");
    $block = null;
    foreach (vault_notes($vault) as $note) { if ($note['name'] === 'block-style') { $block = $note; } }
    check('tags written as a block list are parsed too',
          $block !== null && in_array('policy', $block['tags'], true) && in_array('sizing', $block['tags'], true),
          json_encode($block['tags'] ?? null));
    @unlink("$vault/block-style.md");

    $share_tags = outlook_wanted_tags('share', 'Industrials', ['Trend following (200-day)']);
    $picked = vault_select($share_tags, vault_notes($vault));
    sort($picked['names']);
    check('a share outlook takes policy, indicators, its sector and its screens',
          $picked['names'] === ['industrials', 'position-sizing', 'trend-following', 'what-i-look-at'],
          json_encode($picked['names']));
    check('notes about nothing relevant are left out',
          !in_array('unrelated', $picked['names'], true) && !in_array('market-regime', $picked['names'], true));

    /* A screen name becomes a tag, so one note can cover a strategy without
       being renamed every time a screen is. */
    check('a screen name becomes a tag', outlook_slug('Trend following (200-day)') === 'trend-following',
          outlook_slug('Trend following (200-day)'));
    check('the other trend screen maps to the same note',
          outlook_slug('Trend following (50/200)') === 'trend-following');

    $market = vault_select(outlook_wanted_tags('market'), vault_notes($vault));
    sort($market['names']);
    check('a market outlook takes policy, indicators and market notes',
          $market['names'] === ['market-regime', 'position-sizing', 'what-i-look-at'],
          json_encode($market['names']));

    /* The word budget. Policy is how the owner invests: an outlook that
       quietly stopped applying it would look exactly like one that did. */
    $write_note('fat-sector',   ['industrials'],     str_repeat('sector ', 400));
    $write_note('fat-strategy', ['trend-following'], str_repeat('strategy ', 400));
    $write_note('fat-policy',   ['policy'],          str_repeat('policy ', 400));

    $before = vault_select($share_tags, vault_notes($vault));
    check('without a budget every matching note is sent', $before['words'] > 1000, $before['words'] . ' words');

    /* The budget is a parameter so the rule can be exercised directly; a
       constant cannot be redefined mid-process, and production passes
       nothing and gets SM_VAULT_WORD_BUDGET. */
    $tight = vault_select($share_tags, vault_notes($vault), 900);
    check('over the budget, the sector note is dropped first',
          !in_array('fat-sector', $tight['names'], true)
          && in_array('fat-strategy', $tight['names'], true),
          'kept ' . json_encode($tight['names']));

    $tighter = vault_select($share_tags, vault_notes($vault), 450);
    check('then the strategy note goes too',
          !in_array('fat-sector', $tighter['names'], true)
          && !in_array('fat-strategy', $tighter['names'], true),
          'kept ' . json_encode($tighter['names']));

    $tightest = vault_select($share_tags, vault_notes($vault), 1);
    check('policy notes are never dropped, however tight the budget',
          in_array('fat-policy', $tightest['names'], true)
          && in_array('position-sizing', $tightest['names'], true)
          && in_array('what-i-look-at', $tightest['names'], true),
          'kept ' . json_encode($tightest['names']));
    check('dropping a note is reported rather than silent',
          count($tightest['dropped']) > 0, json_encode($tightest['dropped']));
    check('a note is dropped whole, never cut in half',
          count(array_filter($tightest['notes'], fn($n) => substr($n['body'], -1) === '.'
                                                        || strpos($n['body'], 'policy ') === 0)) > 0);

    array_map('unlink', glob("$vault/fat-*.md") ?: []);

    //A vault that is not there at all. This is the normal state on a host
    //that has not synced yet, and the panel still has to work.
    $absent = vault_select($share_tags, vault_notes($vault . '-does-not-exist'));
    check('a missing vault selects nothing and does not fail',
          $absent['names'] === [] && $absent['words'] === 0);
    check('a missing vault produces no notes text', vault_text($absent) === '');

    echo "\n--- the news lookup searches for the company, not the ticker ---\n";

    /* The feed was searched for "LSE:RR" and "LON:RR". Nobody writes about a
       company that way, and for a two letter ticker it matched nothing: RR
       returned no usable articles at all. */
    db()->exec("delete from stock_symbols where symbol in ('RR','QQ1')");
    db()->prepare("insert into stock_symbols (symbol,name,description,exchange,enabled,sector,market,industry_group,industry,logo)
                   values (?,?,?,'XLON','Y','Industrials','Main Market','Industrials','Aerospace',null)")
         ->execute(['RR', 'Rolls-Royce Holdings plc', 'x']);

    $query = news_query('RR');
    check('the news query uses the company name', strpos($query, 'Rolls-Royce Holdings') !== false, $query);
    check('the company name is quoted, so the words stay together',
          strpos($query, '"Rolls-Royce Holdings"') !== false, $query);
    check('the legal suffix is dropped, which an exact phrase would exclude on',
          stripos($query, 'plc') === false, $query);
    check('the query says shares, which is what makes the results financial',
          strpos($query, 'shares') !== false, $query);
    check('the old ticker form is gone', strpos($query, 'LSE:') === false && strpos($query, 'LON:') === false, $query);

    /* A symbol with no name on file still has to produce a usable search. */
    db()->prepare("insert into stock_symbols (symbol,name,description,exchange,enabled,sector,market,industry_group,industry,logo)
                   values (?,?,?,'XLON','Y','x','x','x','x',null)")->execute(['QQ1', '', 'x']);
    check('a share with no name on file falls back to the ticker',
          strpos(news_query('QQ1'), 'QQ1') !== false, news_query('QQ1'));

    check('the feed url encodes the query rather than pasting it in',
          strpos(news_feed_url('"BT Group" shares'), rawurlencode('"BT Group" shares')) !== false,
          news_feed_url('"BT Group" shares'));

    db()->exec("delete from stock_symbols where symbol in ('RR','QQ1')");

    echo "\n--- the news fetch must not ask for keep-alive ---\n";

    /* PHP's http stream wrapper has no keep-alive support. It opens a socket,
       writes the request and reads to end of file. Ask for Keep-Alive, and a
       server that honours it never closes the socket, so there is no end of
       file: the wrapper blocks until its read timeout and then hands back the
       body it had all along. The feed redirects, so that cost is paid twice.
       On the N100 this was 6.2 seconds per search against 0.2-0.6 with close,
       and it made the quote page take 13 seconds, 12.4 of them in here.

       tools/news_stub.php honours whatever the client asks for, so this is
       measured rather than asserted from the source. Two instances: the stub
       is single threaded and the keep-alive case holds its socket open, which
       would otherwise block the next connection. */
    $news_dir = sys_get_temp_dir() . '/smnews' . getmypid();
    @mkdir($news_dir, 0777, true);
    @unlink("$news_dir/news_requests");

    $news_port_close = 8331;
    $news_port_keep  = 8332;
    $news_stub = function ($port) use ($news_dir) {
        return (int) trim((string) shell_exec(
            'SM_STUB_DIR=' . escapeshellarg($news_dir) . ' SM_NEWS_STUB_HOLD=6 php '
            . escapeshellarg(dirname(__DIR__) . '/tools/news_stub.php')
            . ' 127.0.0.1:' . $port . ' > /dev/null 2>&1 & echo $!'));
    };
    $news_pid_close = $news_stub($news_port_close);
    $news_pid_keep  = $news_stub($news_port_keep);
    usleep(700000);

    db()->exec("delete from stock_symbols where symbol='RRZ'");
    db()->prepare("insert into stock_symbols (symbol,name,description,exchange,enabled,sector,market,industry_group,industry,logo)
                   values (?,?,?,'XLON','Y','Industrials','Main Market','Industrials','Aerospace',null)")
         ->execute(['RRZ', 'Rolls-Royce Holdings plc', 'x']);

    /* Run in a subprocess: SM_NEWS_BASE_URL is read into a constant when
       constants.php is first loaded, so it cannot be changed in this one. */
    $fetch_news = function ($port) use ($INCLUDES) {
        $body = 'require("constants.php"); require("functions.php"); require("share_functions.php");'
              . ' $_SESSION["exchange"]="XLON";'
              . ' $t=microtime(true); $a=get_articles("RRZ");'
              . ' printf("%.3f %d", microtime(true)-$t, count($a));';
        $out = (string) shell_exec(
            'SM_NEWS_BASE_URL=' . escapeshellarg("http://127.0.0.1:$port")
            . ' SM_TEST_DB=' . escapeshellarg(DATABASE)
            . ' SM_TEST_HOST=' . escapeshellarg(SERVER)
            . ' SM_TEST_USER=' . escapeshellarg(USERNAME)
            . ' SM_TEST_PASS=' . escapeshellarg(PASSWORD)
            . ' php -d include_path=' . escapeshellarg(__DIR__ . '/fixtures' . PATH_SEPARATOR . $INCLUDES)
            . ' -r ' . escapeshellarg($body) . ' 2>/dev/null');
        //The jobs print as they go, so the timing is the last line.
        $last = trim(substr($out, strrpos($out, "\n") === false ? 0 : strrpos($out, "\n")));
        $parts = preg_split('/\s+/', $last);
        return ["seconds" => (float) ($parts[0] ?? -1), "articles" => (int) ($parts[1] ?? -1)];
    };

    $fast = $fetch_news($news_port_close);
    check('the news fetch returns articles', $fast['articles'] > 0, json_encode($fast));
    check('the news fetch returns promptly rather than waiting out its timeout',
          $fast['seconds'] >= 0 && $fast['seconds'] < 1.5,
          $fast['seconds'] . 's');
    check('a blocked publisher is still filtered out', $fast['articles'] === 2,
          $fast['articles'] . ' articles, expected 2 of 3');

    /* What actually went over the wire. */
    $sent_requests = array_values(array_filter(array_map(
        function ($line) { return json_decode($line, true); },
        explode("\n", (string) @file_get_contents("$news_dir/news_requests")))));

    $connections = array_map(function ($r) { return strtolower($r['headers']['connection'] ?? ''); }, $sent_requests);
    check('the request does not ask for keep-alive',
          count($connections) > 0 && !in_array('keep-alive', $connections, true),
          json_encode($connections));
    check('it asks for the connection to be closed',
          count(array_filter($connections, function ($c) { return $c === 'close'; })) === count($connections),
          json_encode($connections));

    /* One search. It used to run two, differing only by an exchange prefix on
       a ticker nobody writes, and each one paid the timeout. The redirect is
       a second leg of the same search, not a second search. */
    $searches = array_values(array_unique(array_filter(array_map(
        function ($r) { return strpos($r['target'], 'followed=1') === false ? $r['target'] : null; },
        $sent_requests))));
    check('one search is made, not two', count($searches) === 1, json_encode($searches));
    check('that search is for the company name',
          count($searches) === 1 && strpos(rawurldecode($searches[0]), '"Rolls-Royce Holdings" shares') !== false,
          json_encode(array_map('rawurldecode', $searches)));

    /* The control. Without this the check above could pass against a server
       that closes the connection regardless of what was asked, and would
       prove nothing about the header. Here the same stub is asked for
       keep-alive and the fetch stalls until the timeout and comes back empty,
       which is exactly what the quote page was doing. */
    $ka_body = 'ini_set("default_socket_timeout","10");'
             . ' $ctx=stream_context_create(["http"=>["header"=>"Accept: */*\r\nConnection: Keep-Alive\r\n",'
             . ' "method"=>"GET","timeout"=>2]]);'
             . ' $t=microtime(true); $b=@file_get_contents('
             . var_export("http://127.0.0.1:$news_port_keep/rss/search?q=x", true) . ',false,$ctx);'
             . ' printf("%.3f %d", microtime(true)-$t, strlen((string)$b));';
    $ka_out = trim((string) shell_exec('php -r ' . escapeshellarg($ka_body) . ' 2>/dev/null'));
    $ka = preg_split('/\s+/', $ka_out);
    check('asking for keep-alive is what stalls, so the check above has teeth',
          isset($ka[0]) && (float) $ka[0] >= 1.5 && (int) ($ka[1] ?? -1) === 0,
          'took ' . ($ka[0] ?? '?') . 's and returned ' . ($ka[1] ?? '?') . ' bytes');

    if ($news_pid_close > 0) { exec("kill $news_pid_close 2>/dev/null"); }
    if ($news_pid_keep  > 0) { exec("kill $news_pid_keep 2>/dev/null"); }
    db()->exec("delete from stock_symbols where symbol='RRZ'");

    /* And the source, so a future edit that reintroduces the header is caught
       even on a host where the stub cannot bind a port.

       Comments are stripped first: the comment in get_articles explaining why
       the header is wrong contains the word, and searching the raw file finds
       the explanation and reports the bug as still present. */
    $stripped_share = php_strip_whitespace($INCLUDES . '/share_functions.php');
    $articles_src = substr($stripped_share, strpos($stripped_share, 'function get_articles'));
    $articles_src = substr($articles_src, 0, strpos($articles_src, 'function news_feed_url'));
    check('no keep-alive header survives in the code itself',
          stripos($articles_src, 'keep-alive') === false, 'found it in get_articles');
    check('the code asks for the connection to be closed',
          stripos($articles_src, 'close') !== false);

    echo "\n--- the outlook request and what comes back ---\n";

    /* What the model is asked for. A JSON schema sent as
       output_config.format, not a tool: tool_choice is rejected outright on
       claude-fable-5-2 with HTTP 400, so forcing a tool is not a portable way
       to get a structured answer. */
    $schema = outlook_schema();
    check('the schema requires every field the panel draws',
          $schema['required'] === ['stance','summary','positives','risks','action','watch'],
          json_encode($schema['required']));
    check('stance is an enum, not free text',
          $schema['properties']['stance']['enum'] === ['positive','neutral','negative']);
    /* The count is asked for in the description, not with minItems: array
       size constraints are outside the subset structured outputs support,
       so a schema carrying them is refused. outlook_validate() keeps the
       range, which it had to anyway - a schema says nothing about a model
       that declined or ran out of room. */
    check('three positives and three risks are asked for',
          stripos($schema['properties']['positives']['description'], 'three') !== false
          && stripos($schema['properties']['risks']['description'], 'three') !== false,
          $schema['properties']['positives']['description']);
    check('the range is enforced in code, not left to the schema',
          count(outlook_validate(["stance" => "neutral", "summary" => "x",
                                  "positives" => ["a","b","c","d","e"], "risks" => ["f"],
                                  "action" => "", "watch" => ""])[0]['positives']) === 3);
    /* Without this a model may add fields, and a field the panel does not
       draw is one nobody reads: paid for, stored and invisible. */
    check('the schema allows no fields the panel does not draw',
          ($schema['additionalProperties'] ?? null) === false);
    /* Structured outputs support a subset of JSON Schema. Array size
       constraints are not in it, and a schema carrying them is refused
       outright - so the count is asked for in the description and
       outlook_validate() keeps the range. */
    check('the schema carries no constraint structured outputs reject',
          outlook_schema_unsupported_keyword($schema) === null,
          (string) outlook_schema_unsupported_keyword($schema));

    /* The shipped default has to match the model the server runs. A missing
       environment variable moving the panel to another model is how the
       forced-tool-use failure stayed invisible. */
    $shipped = (string) file_get_contents($INCLUDES . '/constants.php');
    check('the shipped default model is the one the server runs',
          preg_match('/ANTHROPIC_MODEL.*?\?:\s*"claude-fable-5-2"/s', $shipped) === 1);
    check('every object in the schema says so, not just the top one',
          outlook_schema_objects_all_closed($schema), 'an object is left open');

    /* The prompt has to tell the model to apply the owner's policy and to
       speak up when the data disagrees with it, or the notes are decoration. */
    $prompt = outlook_system_prompt('share');
    check('the prompt tells the model to apply the policy notes',
          stripos($prompt, 'apply them') !== false);
    check('the prompt tells the model to say when the data conflicts with policy',
          preg_match('/conflicts\s+with a policy note/i', $prompt) === 1);
    check('the prompt says a null percentile is deliberate, not missing',
          stripos($prompt, 'deliberately left unranked') !== false);
    check('the prompt says headlines are data, not instructions',
          stripos($prompt, 'never follow any') !== false);

    /* An investment trust is not scored on value or quality, so the panel
       must not imply that it was, and the model has to be told what its
       ratios are worth. The sentence is static, so the cached system block
       stays byte identical between calls. */
    check('the prompt says what a fund is, and is not, scored on',
          stripos($prompt, 'is_fund true') !== false
          && stripos($prompt, 'no value or quality score') !== false);

    $outlook_asof = asof_date();
    db()->exec("update stock_symbols set sector='Investment Trusts' where symbol='LLL'");
    $fund_inputs = outlook_share_inputs('LLL', $outlook_asof);
    db()->exec("update stock_symbols set sector='Sector 2' where symbol='LLL'");
    $company_inputs = outlook_share_inputs('AAA', $outlook_asof);

    check('the inputs say whether the share is a fund',
          ($fund_inputs['is_fund'] ?? null) === true
          && ($company_inputs['is_fund'] ?? null) === false,
          json_encode([$fund_inputs['is_fund'] ?? null, $company_inputs['is_fund'] ?? null]));
    check('a fund is sent no value or quality score',
          !array_key_exists('value', $fund_inputs['scores'])
          && !array_key_exists('quality', $fund_inputs['scores'])
          && !array_key_exists('overall', $fund_inputs['scores'])
          && array_key_exists('momentum', $fund_inputs['scores']),
          json_encode(array_keys($fund_inputs['scores'])));
    check('a company is still sent all four scores',
          count($company_inputs['scores']) === 4,
          json_encode(array_keys($company_inputs['scores'])));
    /* The raw ratios still go: they are what the quote page shows, and the
       prompt says what they mean for a fund rather than hiding them. */
    check('a fund is still sent its raw ratios',
          array_key_exists('pe', $fund_inputs['valuation'])
          && array_key_exists('price_book', $fund_inputs['valuation']));

    //Validation. A model that answers oddly must not take the panel with it.
    list($valid, $err) = outlook_validate([
        "stance" => "Positive", "summary" => "Fine.",
        "positives" => ["a","b","c"], "risks" => ["d","e","f"],
        "action" => "Hold.", "watch" => "The average."]);
    check('a good answer validates', $err === null && $valid['stance'] === 'positive');

    list($vc, $ec) = outlook_validate([
        "stance" => "POSITIVE", "summary" => "Fine.",
        "positives" => ["a"], "risks" => ["b"], "action" => "", "watch" => ""]);
    check('a stance in a different case is still that stance',
          $ec === null && $vc['stance'] === 'positive', (string) $ec);

    list($v2, $e2) = outlook_validate(["stance" => "wildly bullish", "summary" => "x"]);
    check('an unrecognised stance is refused', $v2 === null && $e2 !== null, (string) $e2);
    list($v3, $e3) = outlook_validate(["stance" => "neutral", "summary" => "   "]);
    check('an empty summary is refused', $v3 === null && $e3 !== null, (string) $e3);
    list($v4, $e4) = outlook_validate("not an object at all");
    check('a non-object is refused', $v4 === null && $e4 !== null, (string) $e4);
    list($v5, $e5) = outlook_validate(["stance" => "neutral", "summary" => "x",
                                       "positives" => [], "risks" => []]);
    check('an answer with nothing for or against is refused', $v5 === null && $e5 !== null, (string) $e5);

    list($v6, $e6) = outlook_validate(["stance" => "neutral",
                                       "summary" => str_repeat('word ', 200),
                                       "positives" => ["a"], "risks" => ["b"],
                                       "action" => "", "watch" => ""]);
    check('an over-long summary is trimmed rather than thrown away',
          $e6 === null && count(preg_split('/\s+/', $v6['summary'], -1, PREG_SPLIT_NO_EMPTY)) <= 101,
          $e6 === null ? 'trimmed' : (string) $e6);

    //Cost. The panel shows it, so it has to be the real arithmetic.
    $cost = llm_cost(["input_tokens" => 1000000, "cached_tokens" => 0,
                      "cache_write_tokens" => 0, "output_tokens" => 0]);
    check('a million input tokens costs the input rate', abs($cost - 8.00) < 0.0001, (string) $cost);
    $cost = llm_cost(["input_tokens" => 0, "cached_tokens" => 1000000,
                      "cache_write_tokens" => 0, "output_tokens" => 0]);
    check('a million cached tokens costs the cache read rate', abs($cost - 0.20) < 0.0001, (string) $cost);
    $cost = llm_cost(["input_tokens" => 0, "cached_tokens" => 0,
                      "cache_write_tokens" => 0, "output_tokens" => 1000000]);
    check('a million output tokens costs the output rate', abs($cost - 40.00) < 0.0001, (string) $cost);
    /* All four rates have to describe the same model. A set that mixes two
       prices nothing correctly, and the cache figures are the ones that get
       left behind - which under-reports exactly the calls the caching was
       added to make cheap. */
    check('the cache rates belong to the same model as the input rate',
          abs((float) ANTHROPIC_PRICE_CACHE_READ - (float) ANTHROPIC_PRICE_INPUT * 0.025) < 0.001
          && abs((float) ANTHROPIC_PRICE_CACHE_WRITE - (float) ANTHROPIC_PRICE_INPUT * 1.25) < 0.001,
          'input ' . ANTHROPIC_PRICE_INPUT . ', read ' . ANTHROPIC_PRICE_CACHE_READ
          . ', write ' . ANTHROPIC_PRICE_CACHE_WRITE);
    check('reading the cache is far cheaper than sending it again',
          llm_cost(["cached_tokens" => 100000]) < llm_cost(["input_tokens" => 100000]) / 10);

    //No key is a state, not a failure.
    check('with no key configured nothing is sent', !llm_configured());
    $nokey = llm_generate('system', '', 'user', outlook_schema());
    check('with no key the caller gets a message, not an exception',
          $nokey['ok'] === false && stripos($nokey['error'], 'no api key') !== false, $nokey['error']);
    check('a failed generation reports no cost', $nokey['cost'] == 0.0);

    echo "\n--- the deploy script ---\n";

    /* Pushed and deployed are different things, and the gap between them
       cost a session: the server answered with code three commits old while
       every report of the fix was true. */
    $deploy = dirname(__DIR__) . '/deploy/deploy.sh';
    check('there is a deploy script', file_exists($deploy));
    /* Comments stripped before any ordering or presence check. The header
       explains what the script does, so it contains every phrase the checks
       look for - searching the raw file finds the explanation and reports
       the wrong answer. This is the fourth time that trap has bitten in this
       codebase; it is always the comment. */
    $deploy_src = implode("\n", array_filter(
        explode("\n", (string) @file_get_contents($deploy)),
        function ($line) { return !preg_match('/^\s*#/', $line) && trim($line) !== ''; }));
    check('the deploy script parses',
          trim((string) shell_exec('bash -n ' . escapeshellarg($deploy) . ' 2>&1')) === '');
    /* Compared as commands, not as phrases: the script's own error messages
       mention "git checkout" too, so searching the text finds a string
       literal and gets the order wrong. */
    $deploy_line = function ($command) use ($deploy_src) {
        foreach (explode("\n", $deploy_src) as $i => $line) {
            if (preg_match('/^\s*' . preg_quote($command, '/') . '\b/', $line)) { return $i; }
        }
        return -1;
    };
    check('it fetches before it checks out',
          $deploy_line('git fetch') >= 0 && $deploy_line('git checkout') >= 0
          && $deploy_line('git fetch') < $deploy_line('git checkout'),
          'fetch on line ' . $deploy_line('git fetch')
          . ', checkout on line ' . $deploy_line('git checkout'));
    check('it pulls after checking out, not before',
          $deploy_line('git pull') > $deploy_line('git checkout'));
    check('it restarts php only after the pull',
          $deploy_line('systemctl restart') > $deploy_line('git pull'));
    check('it restarts php-fpm, without which a pull changes nothing served',
          preg_match('/systemctl restart/', $deploy_src) === 1
          && strpos($deploy_src, 'php8.3-fpm') !== false);
    check('it prints the commit it landed on',
          strpos($deploy_src, 'git log -1 --oneline') !== false);
    /* Refuses on a modified tracked file, runs past an untracked one.
       "git status --porcelain" reported both, so public/webfonts/ - fetched by
       tools/fetch_assets.sh, which is deliberately not in the repository -
       made the tree look dirty and the deploy would not run at all. A file git
       is not tracking cannot be lost by a pull.

       Run for real in a throwaway repository rather than matched in the
       source. It gets as far as "git fetch origin", which fails there because
       there is no remote, and that is past the point being tested. */
    check('it checks only tracked files for local changes',
          preg_match('/git diff --quiet/', $deploy_src) === 1
          && preg_match('/git diff --cached --quiet/', $deploy_src) === 1,
          'the dirty check is not the tracked-files one');
    check('it no longer treats an untracked file as a dirty tree',
          strpos($deploy_src, 'git status --porcelain') === false);

    $deploy_dir = sys_get_temp_dir() . '/sm_deploy_' . getmypid();
    exec('rm -rf ' . escapeshellarg($deploy_dir));
    mkdir($deploy_dir . '/deploy', 0700, true);
    copy($deploy, $deploy_dir . '/deploy/deploy.sh');
    chmod($deploy_dir . '/deploy/deploy.sh', 0700);
    file_put_contents($deploy_dir . '/tracked.txt', "one\n");
    $git = 'git -C ' . escapeshellarg($deploy_dir)
         . ' -c user.email=t@example.com -c user.name=test';
    exec($git . ' init -q 2>&1');
    exec($git . ' add -A 2>&1');
    exec($git . ' commit -qm first 2>&1');

    $run_deploy = function () use ($deploy_dir) {
        exec('SM_APP_DIR=' . escapeshellarg($deploy_dir)
             . ' SM_PHP_SERVICE=sm-not-a-real-service'
             . ' bash ' . escapeshellarg($deploy_dir . '/deploy/deploy.sh')
             . ' some-branch 2>&1', $out, $rc);
        return [implode("\n", $out), $rc];
    };

    //A clean tree: it should get as far as fetching.
    [$deploy_clean, ] = $run_deploy();
    check('a clean checkout gets past the local-changes check',
          strpos($deploy_clean, 'refusing to pull') === false
          && strpos($deploy_clean, 'deploy: fetching') !== false,
          $deploy_clean);

    //An untracked file is not a reason to stop.
    mkdir($deploy_dir . '/public/webfonts', 0700, true);
    file_put_contents($deploy_dir . '/public/webfonts/fa-solid-900.woff2', "x");
    [$deploy_untracked, ] = $run_deploy();
    check('an untracked file does not stop the deploy',
          strpos($deploy_untracked, 'refusing to pull') === false
          && strpos($deploy_untracked, 'deploy: fetching') !== false,
          $deploy_untracked);

    //A modified tracked file is.
    file_put_contents($deploy_dir . '/tracked.txt', "edited by hand\n");
    [$deploy_dirty, $deploy_rc] = $run_deploy();
    check('a modified tracked file stops the deploy',
          strpos($deploy_dirty, 'refusing to pull') !== false && $deploy_rc === 1,
          'rc ' . $deploy_rc . ': ' . $deploy_dirty);
    check('and it says which file, without listing untracked ones',
          strpos($deploy_dirty, 'tracked.txt') !== false
          && strpos($deploy_dirty, 'webfonts') === false,
          $deploy_dirty);

    //A staged change counts too, even with the working tree matching HEAD.
    exec($git . ' checkout -- tracked.txt 2>&1');
    file_put_contents($deploy_dir . '/tracked.txt', "staged\n");
    exec($git . ' add tracked.txt 2>&1');
    file_put_contents($deploy_dir . '/tracked.txt', "one\n");
    [$deploy_staged, ] = $run_deploy();
    check('a staged change stops the deploy as well',
          strpos($deploy_staged, 'refusing to pull') !== false, $deploy_staged);

    exec('rm -rf ' . escapeshellarg($deploy_dir));

    /* The assets the deploy no longer trips over are ignored by git as well,
       so they do not show up as untracked in the first place. */
    $deploy_ignore = (string) @file_get_contents(dirname(__DIR__) . '/.gitignore');
    foreach (['public/webfonts/', 'public/jpgraph-4.4.4/', 'public/jpgraph-4.2.0'] as $deploy_path) {
        check("$deploy_path is gitignored",
              in_array($deploy_path, array_map('trim', explode("\n", $deploy_ignore)), true),
              $deploy_path . ' is not listed');
    }
    check('it stops on the first error rather than carrying on',
          preg_match('/set -euo pipefail/', $deploy_src) === 1);
    /* A deploy script that quietly ran migrations would one day run them
       against the wrong database. */
    check('it runs no migrations and touches no database',
          stripos($deploy_src, 'mariadb ') === false && stripos($deploy_src, 'mysql ') === false);

    echo "\n--- the index the market outlook reads ---\n";

    /* An index is addressed by its own code: FMP wants ^FTSE, not ^FTSE.L. */
    check('an index code takes no exchange suffix', provider_symbol('^FTSE') === '^FTSE');
    check('an ordinary ticker still gets one', provider_symbol('III') !== 'III');
    check('the index list is the FTSE 100', index_symbols() === ['^FTSE']);

    /* It must not be in stock_symbols: everything there is ranked by the
       screens, counted in the breadth figures and offered by the search box. */
    check('the index is not part of the tradeable universe',
          (int) scalar("select count(*) from stock_symbols where symbol like '^%'") === 0);
    $prices_src = (string) file_get_contents($INCLUDES . '/get_share_prices.php');
    check('the nightly price job loads the index', strpos($prices_src, 'get_index_prices') !== false);

    echo "\n--- pages render without a fatal ---\n";

    /* Drives the site over HTTP as a logged in user. Everything above tests the
       jobs and the queries; this is the only thing that catches a page which
       parses fine and still dies on the way to the browser - a missing include,
       a column nothing writes, an undefined function. */
    $docroot = dirname(__DIR__) . '/public';
    $port = 8099;
    $include_path = __DIR__ . '/fixtures' . PATH_SEPARATOR . dirname(__DIR__) . '/includes';
    /* The AI outlook talks to tools/anthropic_stub.php, never the real API:
       it costs money, needs a key, and would make the suite depend on what a
       model happens to say today. The stub's control directory lets a test
       pick the answer, and records the request so the prompt can be asserted.

       ANTHROPIC_API_KEY is a recognisable fake, so a test can prove it never
       appears in a response or a log. */
    $ai_dir   = sys_get_temp_dir() . '/smai' . getmypid();
    $ai_vault = sys_get_temp_dir() . '/smaivault' . getmypid();
    @mkdir($ai_dir, 0777, true);
    @mkdir($ai_vault, 0777, true);
    file_put_contents("$ai_dir/mode", "ok");
    file_put_contents("$ai_vault/house-policy.md",
                      "---\ntags: [policy]\n---\nNever more than 5% in one name.");
    file_put_contents("$ai_vault/signals.md",
                      "---\ntags: [indicators]\n---\nTrend first, then quality.");

    $ai_key  = 'sk-ant-test-NOT-A-REAL-KEY-9f3a';
    $ai_port = $port + 20;

    $server = 'EODHD_BASE_URL=http://127.0.0.1:' . ($port + 10)
            . ' EODHD_API_KEY=test-stub-token'
            . ' ANTHROPIC_BASE_URL=http://127.0.0.1:' . $ai_port
            . ' ANTHROPIC_API_KEY=' . escapeshellarg($ai_key)
            . ' ANTHROPIC_TIMEOUT=5'
            . ' SM_VAULT_DIR=' . escapeshellarg($ai_vault)
            /* config.php defaults display_errors to off, which is right for a
               public host. Half the checks below read warnings out of the page,
               so without this they see a clean page and pass whatever the
               application does. */
            . ' SM_DISPLAY_ERRORS=1'
            . ' php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docroot)
            . ' -d include_path=' . escapeshellarg($include_path)
            /* php -S runs under the cli-server SAPI, where opcache.enable applies
               rather than opcache.enable_cli, so without this it serves stale code. */
            . ' -d opcache.enable=0 -d default_socket_timeout=5 -d display_errors=1'
            . ' > /dev/null 2>&1 & echo $!';
    $pid = (int)trim(shell_exec($server));

    /* The quote page calls the price API. Without a stub every symbol looks
       invalid, so start one on its own port - php -S serves one request at a
       time, and a server calling a stub inside itself would deadlock. */
    $stub_port = $port + 10;
    $stub_cmd = 'SM_TEST_DB=' . escapeshellarg(DATABASE)
              . ' SM_SANDBOX_DB=' . escapeshellarg(DATABASE)
              . ' SM_SANDBOX_HOST=' . escapeshellarg(SERVER)
              . ' SM_SANDBOX_USER=' . escapeshellarg(USERNAME)
              . ' SM_SANDBOX_PASS=' . escapeshellarg(PASSWORD)
              . ' php -S 127.0.0.1:' . $stub_port . ' '
              . escapeshellarg(dirname(__DIR__) . '/tools/eodhd_stub.php')
              . ' > /dev/null 2>&1 & echo $!';
    $stub_pid = (int)trim(shell_exec($stub_cmd));

    $ai_stub_cmd = 'SM_STUB_DIR=' . escapeshellarg($ai_dir)
                 . ' SM_STUB_SLEEP=8'
                 . ' php -S 127.0.0.1:' . $ai_port . ' '
                 . escapeshellarg(dirname(__DIR__) . '/tools/anthropic_stub.php')
                 . ' > /dev/null 2>&1 & echo $!';
    $ai_stub_pid = (int)trim(shell_exec($ai_stub_cmd));

    $base = "http://127.0.0.1:$port";
    $up = false;
    for ($i = 0; $i < 40 && !$up; $i++) {
        usleep(250000);
        $probe = @file_get_contents("$base/login.php", false, stream_context_create(['http' => ['timeout' => 2]]));
        $up = ($probe !== false);
    }

    if (!$up) {
        check('the test web server starts', false, "could not reach $base");
    }
    else {
        $jar = tempnam(sys_get_temp_dir(), 'smjar');
        /* -L matters: a page that correctly redirects returns an empty body
           without it, which reads as a broken page rather than a working one. */
        $curl = function ($url, $post = null, $follow = true) use ($jar) {
            $cmd = 'curl -s -m 30 -b ' . escapeshellarg($jar) . ' -c ' . escapeshellarg($jar);
            if ($follow) { $cmd .= ' -L'; }
            if ($post !== null) { $cmd .= ' -d ' . escapeshellarg($post); }
            return shell_exec($cmd . ' ' . escapeshellarg($url) . ' 2>/dev/null');
        };

        $curl("$base/login.php");
        $curl("$base/login.php", 'username=tester&password=testpass');

        $pages = ['index.php','performance.php','dividends.php','cash_history.php',
                  'edit.php','topup.php','screen_list.php','quote.php','criteria.php',
                  'criteria_list.php','screen_criteria.php','sector_companies.php',
                  'strategies.php','sell.php','buy.php','backtest.php',
                  'backtest_results.php','statistics.php','statistics_form.php','download_prices.php',
                  'register.php','reset_passwd.php'];
        $fatal = [];
        foreach ($pages as $page) {
            $body = (string)$curl("$base/$page");
            if (preg_match('/(Fatal error|Parse error)/i', $body)) { $fatal[] = $page; }
        }
        check(count($pages) . ' pages render without a fatal', count($fatal) === 0,
              'fatal: ' . implode(', ', $fatal));

        /* The crawl above only ever loaded quote.php bare, with no symbol, so it
           never reached get_share_info() and missed three defects at once: a
           missing stock_symbols column, a print_r dumping the API response onto
           the page, and a price read before the lookup()-returned-false guard.
           Load it the way the dashboard links to it. */
        $quote_fatal = $quote_warn = $quote_dump = [];
        foreach (['AAA', 'HHH', 'LLL'] as $sym) {
            $body = (string)$curl("$base/quote.php?symbol=$sym&page=quote_form.php");
            if (preg_match('/(Fatal error|Parse error)/i', $body)) { $quote_fatal[] = $sym; }
            /* PHP renders these as "<b>Warning</b>:  ...", so a pattern of
                   "Warning:" never matches and the check passes whatever the
                   page does. Match both forms, and cover "Undefined array key",
                   which is a different message from "Undefined variable". */
                if (preg_match('/(Warning<\/b>|Warning:|Deprecated<\/b>|Deprecated:|Undefined array key)/i', $body)) { $quote_warn[] = $sym; }
            /* print_r output has a distinctive shape and should never reach the
               browser; the log is where it belongs. */
            if (preg_match('/Array\s*\(\s*\[/', $body))         { $quote_dump[]  = $sym; }
        }
        check('a quote page loads for a real symbol', count($quote_fatal) === 0,
              'fatal for: ' . implode(', ', $quote_fatal));
        check('a quote page raises no warnings', count($quote_warn) === 0,
              'warnings for: ' . implode(', ', $quote_warn));
        check('a quote page does not print a debug dump', count($quote_dump) === 0,
              'dump on: ' . implode(', ', $quote_dump));

        /* The quote page is the deepest read path in the application: profile,
           valuation, ratings, health scores and three financial statements. It
           reached none of that while every symbol looked invalid, so assert it
           renders the content rather than only that it does not crash. */
        $q = (string)$curl("$base/quote.php?symbol=AAA&page=quote_form.php");
        foreach (['Revenue' => 'income statement',
                  'Total Assets' => 'balance sheet',
                  'Operating Cash Flow' => 'cash flow statement'] as $needle => $what) {
            check("the quote page renders the $what", strpos($q, $needle) !== false);
        }
        check('the quote page is substantial, not a stub', strlen($q) > 20000,
              strlen($q) . ' bytes');

        /* A fund has no value, quality or overall score. get_scores() hands
           back its neutral 50 for each, which on the page reads as a real
           middling score rather than an absent one. */
        check('a company page shows the overall score rather than a fund notice',
              strpos($q, 'ShareRank') !== false
              && stripos($q, 'Not scored: investment fund') === false);

        db()->exec("update stock_symbols set sector='Investment Trusts' where symbol='LLL'");
        $fund_page = (string)$curl("$base/quote.php?symbol=LLL&page=quote_form.php");
        db()->exec("update stock_symbols set sector='Sector 2' where symbol='LLL'");

        check('a fund page says it is not scored, where the badges would be',
              substr_count($fund_page, 'Not scored: investment fund') === 2,
              substr_count($fund_page, 'Not scored: investment fund') . ' occurrences');
        check('a fund page shows no value, quality or ShareRank badge',
              strpos($fund_page, '#ValueModal') === false
              && strpos($fund_page, '#QualityModal') === false
              && strpos($fund_page, 'ShareRank') === false);
        /* Momentum is still scored, so that badge stays. */
        check('a fund page still shows its momentum score',
              strpos($fund_page, '#MomentumModal') !== false);
        check('the fund page is a real page, not an error', strlen($fund_page) > 20000,
              strlen($fund_page) . ' bytes');

        /* The chart on the quote page is an <img> pointing at stockgraph.php,
           which required the jpgraph library unconditionally. jpgraph is not
           vendored here, so the require fatalled and the browser showed a broken
           image. The fallback draws SVG rather than using GD, which ships as a
           separate package that is often absent - and a missing extension is
           itself a fatal inside an <img>. Both endpoints must return a picture
           whether or not either library is installed. */
        foreach (['stockgraph.php?symbol=AAA&timespan=6m'      => 'price chart',
                  'performance_graph.php?session_id=1&timespan=1y' => 'performance chart'] as $u => $what) {
            $img = (string)$curl("$base/$u");
            /* The image must be the FIRST byte of the body, not merely present
               in it. A stray newline from an include's trailing blank line sat
               in front of the PNG header and the browser showed a broken image,
               while a check for "contains <svg" passed throughout. */
            check("the $what endpoint returns an image",
                  strncmp($img, '<?xml', 5) === 0 || strncmp($img, '<svg', 4) === 0
                  || strncmp($img, "\x89PNG", 4) === 0,
                  'body starts: ' . json_encode(substr($img, 0, 60)));
            check("the $what draws a line, not just a frame",
                  strpos($img, '<polyline') !== false || strncmp($img, "\x89PNG", 4) === 0);
        }
        /* An empty portfolio.
           A session with no holdings gives two empty series. jpgraph raises on
           an empty data array ("Empty input data array", error 25121); inside
           an <img> that is a picture of an exception where a "no data yet"
           message belongs.

           The bug was invisible to this suite because jpgraph is not vendored
           here: jpgraph_available() is false, so only the SVG fallback ever
           ran, and the fallback has always handled empty data. The N100 has
           jpgraph installed, so the broken branch was the one never taken.
           Stub the library - a LinePlot that raises on empty input, as the real
           one does - and the jpgraph path runs and can be asserted on.

           php -S chdirs to its docroot, and jpgraph_available() resolves its
           path relative to the working directory, so the stub goes in public/
           and is removed again below. */
        $stub_lib = $docroot . '/jpgraph-4.2.0/src';
        @mkdir($stub_lib, 0777, true);
        file_put_contents($stub_lib . '/jpgraph.php', <<<'STUB'
<?php
class JpGraphException extends Exception {}
class JpGraphStubAxis {
	public $scale;
	function __construct() { $this->scale = new JpGraphStubScale(); }
	function SetLabelAngle($a) {}
}
class JpGraphStubScale { function SetDateFormat($f) {} }
class Graph {
	public $xaxis;
	function __construct($w, $h) { $this->xaxis = new JpGraphStubAxis(); }
	function SetScale($s) {}
	function SetMargin($a, $b, $c, $d) {}
	function Add($p) {}
	//A real Stroke() writes a PNG; the magic bytes are all this needs.
	function Stroke() { header("Content-Type: image/png"); print("\x89PNG\r\n\x1a\n"); }
}
STUB
        );
        file_put_contents($stub_lib . '/jpgraph_line.php', <<<'STUB'
<?php
class LinePlot {
	function __construct($ydata, $xdata = array()) {
		if (!is_array($ydata) || count($ydata) === 0) {
			throw new JpGraphException('Empty input data array specified for plot. Must have at least one data point.');
		}
	}
	function SetLegend($l) {}
}
STUB
        );
        file_put_contents($stub_lib . '/jpgraph_bar.php',  "<?php\n");
        file_put_contents($stub_lib . '/jpgraph_date.php', "<?php\n");

        /* 999999 is deliberately a session that has never been valued, which is
           the same two empty arrays as a new portfolio. */
        $empty_img = (string)$curl("$base/performance_graph.php?session_id=999999&timespan=1y");
        check('an empty portfolio still returns a picture',
              strncmp($empty_img, '<?xml', 5) === 0 || strncmp($empty_img, '<svg', 4) === 0
              || strncmp($empty_img, "\x89PNG", 4) === 0,
              'body starts: ' . json_encode(substr($empty_img, 0, 80)));
        check('an empty portfolio is not a picture of jpgraph\'s exception',
              stripos($empty_img, 'Empty input data array') === false
              && stripos($empty_img, 'xception') === false,
              'body: ' . json_encode(substr(strip_tags($empty_img), 0, 160)));
        check('an empty portfolio says it has no history',
              stripos($empty_img, 'No performance history') !== false,
              'body: ' . json_encode(substr(strip_tags($empty_img), 0, 160)));

        /* And the guard must not swallow a portfolio that does have history: a
           check for "returns a picture" alone would pass just as well if the
           endpoint drew "no data" for everybody. */
        db()->exec("delete from performance where session_id=424242");
        foreach ([['-8 day', 120.50, 1800.00], ['-1 day', 240.75, 1950.00]] as [$when, $pf, $hd]) {
            db()->prepare("insert into performance
                             (session_id, performance_date, total_value, total_profit, total_holding, cash)
                           values (424242, ?, ?, ?, ?, 0)")
                ->execute([date('Y-m-d', strtotime($when)), $hd, $pf, $hd]);
        }
        $full_img = (string)$curl("$base/performance_graph.php?session_id=424242&timespan=1y");
        check('a portfolio with history strokes a real chart through jpgraph',
              strncmp($full_img, "\x89PNG", 4) === 0,
              'body starts: ' . json_encode(substr($full_img, 0, 80)));
        check('a portfolio with history is not told it has none',
              stripos($full_img, 'No performance history') === false,
              'body: ' . json_encode(substr(strip_tags($full_img), 0, 160)));
        db()->exec("delete from performance where session_id=424242");

        /* Leave the docroot as it was found: a stub library left in public/
           would change which branch every later run takes. */
        foreach (glob($stub_lib . '/*.php') as $f) { @unlink($f); }
        @rmdir($stub_lib);
        @rmdir($docroot . '/jpgraph-4.2.0');
        check('the stub library is removed again',
              !file_exists($docroot . '/jpgraph-4.2.0'));

        /* GD is the dependency the fallback exists to avoid. */
        $graph_src = (string)file_get_contents(dirname(__DIR__) . '/includes/simple_graph.php');
        check('the chart fallback needs no PHP extension',
              !preg_match('/\bimage(create|png|line|string)/', $graph_src));

        /* get_articles() fetches an external feed and used to exit on failure,
           which took the whole quote page down with it. */
        $share_src = (string)file_get_contents(dirname(__DIR__) . '/includes/share_functions.php');
        $articles_src = substr($share_src, strpos($share_src, 'function get_articles'));
        $articles_src = substr($articles_src, 0, strpos($articles_src, 'function avg_share_cost'));
        check('the news fetch cannot end the request it runs inside',
              strpos($articles_src, 'exit;') === false);
        check('the news fetch is bounded by a timeout',
              strpos($articles_src, '"timeout"') !== false);

        /* The News tab runs on every quote page load. Its User-Agent was built
           from curl_version() even though the fetch is file_get_contents, so
           ext-curl only supplied a version string - and php-curl is a separate
           package, so on a host without it the call was a fatal that took the
           whole page down. simplexml (php-xml) and allow_url_fopen are the same
           shape of dependency. Run the function under each of those hosts. */
        $include_path = __DIR__ . '/fixtures' . PATH_SEPARATOR . $INCLUDES;
        $body = 'require("functions.php"); require("share_functions.php");'
              . ' $a = get_articles("AAA");'
              . ' echo is_array($a) ? "ARRAY" : "NOT-ARRAY";';
        foreach (['-d disable_functions=curl_version,curl_init,curl_exec,curl_setopt,curl_close'
                      => 'ext-curl',
                  '-d disable_functions=simplexml_load_string' => 'ext-simplexml',
                  '-d allow_url_fopen=0'                       => 'allow_url_fopen'] as $flag => $what) {
            $probe_out = (string)shell_exec('php -d include_path=' . escapeshellarg($include_path)
                                            . ' ' . $flag . ' -r ' . escapeshellarg($body) . ' 2>&1');
            check("the quote page survives a host without $what",
                  strpos($probe_out, 'ARRAY') !== false
                  && stripos($probe_out, 'Fatal error') === false,
                  trim(substr($probe_out, 0, 200)));
        }
        check('the news tab renders rather than being commented out',
              strpos($q, 'id="news"') !== false
              && preg_match('/id="news".{0,400}(No news articles|<a href=)/s', $q) === 1);

        /* index.php also renders templates/quote.php when given a symbol, and
           passed six of the thirty variables the template reads: every panel it
           did not know about rendered undefined, 74 warnings for one symbol.
           It hands the symbol to quote.php now, which builds them all. */
        $via_index = (string)$curl("$base/index.php?symbol=AAA");
        check('index.php with a symbol renders the quote page cleanly',
              !preg_match('/(Warning<\/b>|Warning:|Undefined variable|Trying to access array offset)/i', $via_index)
              && strlen($via_index) > 20000,
              strlen($via_index) . ' bytes');

        /* ----------------------------------------------------------------
           The navbar share lookup.

           quote.php read the symbol as substr($term, 0, strpos($term, ' ')).
           A bare symbol has no space, so strpos() returned false, substr()
           read that as 0, and the symbol came out empty: "III" was an
           invalid symbol and only the "III 3i Group" form the suggestions
           produce ever worked - and the suggestions never appeared, so in
           practice nothing worked.
           ---------------------------------------------------------------- */

        /* Two shares the seeded universe does not have: one with the dash a
           London ticker is stored with, one whose name is worth searching
           for. */
        db()->exec("delete from stock_symbols where symbol in ('BT-A','RR')");
        db()->prepare("insert into stock_symbols
                         (symbol,name,description,exchange,enabled,sector,market,industry_group,industry,logo)
                       values (?,?,?,'XLON','Y','Telecoms','Main Market','Telecoms','Telecoms',null)")
             ->execute(['BT-A', 'BT Group plc', 'BT Group plc ordinary shares']);
        db()->prepare("insert into stock_symbols
                         (symbol,name,description,exchange,enabled,sector,market,industry_group,industry,logo)
                       values (?,?,?,'XLON','Y','Industrials','Main Market','Aerospace','Aerospace',null)")
             ->execute(['RR', 'Rolls-Royce Holdings plc', 'Rolls-Royce Holdings plc ordinary shares']);
        /* Both need prices. get_share_info() returns false when lookup() has
           no price for the symbol, and quote.php then apologises - so without
           these two the test would be measuring the absence of a price feed
           rather than whether the search resolved. tools/eodhd_stub.php serves
           whatever is in historical_prices. */
        db()->exec("delete from historical_prices where symbol in ('BT-A','RR')");
        $ins_trend_px = db()->prepare("insert into historical_prices (symbol,exchange,date,price)
                                       values (?,'XLON',?,?)");
        /* Not $base: that name holds the test server's URL in this scope, and
           using it here overwrote it with 615.0, so every later $curl() in the
           suite addressed a host called "615". */
        foreach (['BT-A' => 142.5, 'RR' => 615.0] as $sym => $start_price) {
            for ($d = 20; $d >= 1; $d--) {
                $ins_trend_px->execute([$sym, date('Y-m-d', strtotime("-$d day")),
                                        round($start_price + (20 - $d) * 0.5, 4)]);
            }
        }

        $search = function ($term) use ($curl, $base) {
            return (string)$curl("$base/quote.php", 'symbol=' . urlencode($term));
        };
        //The quote page puts "<exchange>:<symbol>" in the title, which is the
        //shortest proof of which share was actually resolved.
        $resolved = function ($body) {
            return preg_match('/Share Portfolio Manager XLON:([A-Z0-9.\-]+)/', $body, $m)
                   ? $m[1] : null;
        };

        foreach ([['III',              'III', 'a bare symbol'],
                  ['iii',              'III', 'a symbol in lower case'],
                  ['III III Test PLC', 'III', 'the "SYMBOL Name" a suggestion puts in the box'],
                  ['  III  ',          'III', 'a symbol with stray spaces'],
                  ['BT.A',             'BT-A', 'the dotted form a London ticker is written in'],
                  ['rolls',            'RR',   'part of a company name'],
                  ['Rolls-Royce',      'RR',   'more of a company name']] as [$term, $want, $what]) {
            $body = $search($term);
            check("searching $what finds the share ($term -> $want)",
                  $resolved($body) === $want && stripos($body, 'Sorry!') === false,
                  'resolved to ' . var_export($resolved($body), true)
                  . (stripos($body, 'Sorry!') !== false ? ' (apologised)' : ''));
        }

        /* Several shares fit. Every seeded name ends "Test PLC", so this is
           ambiguous and the page should offer the choice rather than guess. */
        $many = $search('Test PLC');
        preg_match_all('/quote\.php\?symbol=([A-Za-z0-9.%\-]+)/', $many, $links);
        check('an ambiguous term lists the matches instead of guessing',
              count(array_unique($links[1])) >= 12 && $resolved($many) === null,
              count(array_unique($links[1])) . ' links, resolved to '
              . var_export($resolved($many), true));
        check('the list names the shares, not just their symbols',
              strpos($many, 'Test PLC') !== false && stripos($many, 'Sorry!') === false);
        /* A link from that list has to work, or the choice leads nowhere.
           Guarded on there being a link at all: with an empty $links the
           comparison was null === null and passed while proving nothing. */
        check('the match list produced a link to follow', count($links[1]) > 0,
              'no quote.php?symbol= links in ' . strlen($many) . ' bytes');
        if (count($links[1]) > 0) {
            $picked = (string)$curl("$base/quote.php?symbol=" . urlencode($links[1][0]));
            check('a link from the match list opens that share',
                  $resolved($picked) === $links[1][0],
                  'wanted ' . $links[1][0] . ' got ' . var_export($resolved($picked), true));
        }

        /* No match keeps the existing apology. */
        $none = $search('NOSUCHSHARE');
        check('a term matching nothing still apologises',
              stripos($none, 'Sorry!') !== false && stripos($none, 'Invalid Symbol') !== false
              && $resolved($none) === null);
        check('an empty search asks for a symbol rather than listing everything',
              stripos((string)$search(''), 'must enter a symbol') !== false);

        /* The session exchange scopes the search. ZZZ is seeded on LON and
           must not be reachable from an XLON session. */
        $other_exchange = $search('ZZZ');
        check('a share on another exchange is not found',
              stripos($other_exchange, 'Sorry!') !== false && $resolved($other_exchange) === null,
              'resolved to ' . var_export($resolved($other_exchange), true));

        /* ---------------- the suggestion endpoint ---------------- */

        $suggest = (string)$curl("$base/symbol_search.php?term=III");
        $decoded = json_decode($suggest, true);
        check('the suggestion endpoint returns JSON',
              is_array($decoded), 'got ' . json_encode(substr($suggest, 0, 120)));
        check('a suggestion carries both the symbol and the name',
              is_array($decoded) && count($decoded) > 0
              && isset($decoded[0]['symbol']) && isset($decoded[0]['name'])
              && $decoded[0]['symbol'] === 'III',
              json_encode(array_slice((array)$decoded, 0, 3)));
        check('the endpoint matches on company name too, not only the symbol',
              is_array(json_decode((string)$curl("$base/symbol_search.php?term=rolls"), true))
              && json_decode((string)$curl("$base/symbol_search.php?term=rolls"), true)[0]['symbol'] === 'RR');
        $wide = json_decode((string)$curl("$base/symbol_search.php?term=Test"), true);
        check('the endpoint caps the list rather than returning the universe',
              is_array($wide) && count($wide) <= 10 && count($wide) > 1,
              'returned ' . (is_array($wide) ? count($wide) : 'non-array'));
        check('the endpoint body is JSON from its first byte',
              strncmp($suggest, '[', 1) === 0 || strncmp($suggest, '{', 1) === 0,
              'starts: ' . json_encode(substr($suggest, 0, 40)));
        /* A wildcard typed into the box is a search term, not a pattern. */
        /* Encoded, so curl sends a literal percent rather than leaving the
           server to guess at a stray one - an unencoded %, read as empty,
           would make this pass without testing anything. The same for the
           underscore, which matches any single character. */
        $pct = json_decode((string)$curl("$base/symbol_search.php?term=" . urlencode('%')), true);
        check('a LIKE wildcard is searched for rather than expanded',
              is_array($pct) && count($pct) === 0,
              'returned ' . (is_array($pct) ? json_encode($pct) : 'non-array'));
        $us = json_decode((string)$curl("$base/symbol_search.php?term=" . urlencode('II_')), true);
        check('an underscore is a character to find, not a single-character wildcard',
              is_array($us) && count($us) === 0,
              'returned ' . (is_array($us) ? json_encode($us) : 'non-array'));

        /* Anonymous requests must not reach the share universe. config.php's
           gate covers this file because it is not on the allow list, which is
           the point: there is no second copy of the rule here to drift. */
        $anon_jar = tempnam(sys_get_temp_dir(), 'smanon');
        $anon = (string)shell_exec('curl -s -m 30 -b ' . escapeshellarg($anon_jar)
                                   . ' -c ' . escapeshellarg($anon_jar) . ' -L '
                                   . escapeshellarg("$base/symbol_search.php?term=III") . ' 2>/dev/null');
        /* Not a search for the word "symbol": header.php renders the navbar on
           the login page too, and its box is name="symbol", so looking for
           that string finds the login form and fails for the wrong reason.
           What matters is that no share data comes back and the request ends
           at the login form. */
        check('the suggestion endpoint refuses an anonymous request',
              json_decode($anon, true) === null
              && strpos($anon, '{"symbol":') === false
              && stripos($anon, 'name="password"') !== false,
              'body: ' . json_encode(substr($anon, 0, 160)));
        $anon_code = trim((string)shell_exec('curl -s -o /dev/null -w "%{http_code}" -m 30 '
                                             . escapeshellarg("$base/symbol_search.php?term=III") . ' 2>/dev/null'));
        check('an anonymous request is redirected to the login page',
              $anon_code === '302', "status $anon_code");
        @unlink($anon_jar);

        /* ---------------- the AI outlook panel ---------------- */

        $ai_mode = function ($mode) use ($ai_dir) { file_put_contents("$ai_dir/mode", $mode); };
        $ai_last_request = function () use ($ai_dir) {
            $raw = @file_get_contents("$ai_dir/last_request");
            return $raw === false ? null : json_decode($raw, true);
        };
        $ai_post = function ($fields) use ($curl, $base) {
            return (string) $curl("$base/ai_outlook.php", $fields);
        };
        $ai_json = function ($fields) use ($ai_post) { return json_decode($ai_post($fields), true); };

        $ai_mode('ok');

        /* ai_outlook is created by sql/ai_outlook.sql rather than by
           schema.sql, so unlike every other table it survives a reload and
           carries the previous run's rows into this one. */
        db()->exec("truncate ai_outlook");

        /* Nothing may call the API without being asked. An idle site, a
           crawler or a page refresh must cost nothing. */
        @unlink("$ai_dir/last_request");
        $curl("$base/quote.php?symbol=III&page=quote_form.php");
        $curl("$base/index.php");
        check('loading a page never calls the API',
              !file_exists("$ai_dir/last_request"));

        $quote_page = (string)$curl("$base/quote.php?symbol=III&page=quote_form.php");
        check('the quote page shows an AI outlook panel',
              strpos($quote_page, 'sm-outlook') !== false && strpos($quote_page, 'AI outlook') !== false);
        check('with nothing generated the panel says so',
              strpos($quote_page, 'No outlook yet') !== false);
        $home_page = (string)$curl("$base/index.php");
        check('the home page shows a market outlook panel',
              strpos($home_page, 'Market outlook') !== false && strpos($home_page, 'sm-outlook') !== false);

        /* The endpoint. A generation spends money and writes a row, so it is
           not something a link or a prefetch should reach. */
        $get_body = (string)$curl("$base/ai_outlook.php");
        check('the endpoint refuses GET',
              strpos($get_body, 'Use POST') !== false, substr($get_body, 0, 120));
        $get_code = trim((string)shell_exec('curl -s -o /dev/null -w "%{http_code}" -m 20 -b '
                   . escapeshellarg($jar) . ' ' . escapeshellarg("$base/ai_outlook.php") . ' 2>/dev/null'));
        check('a GET answers 405', $get_code === '405', "status $get_code");

        $anon_ai_jar = tempnam(sys_get_temp_dir(), 'smanonai');
        $anon_ai = (string)shell_exec('curl -s -m 20 -b ' . escapeshellarg($anon_ai_jar)
                   . ' -c ' . escapeshellarg($anon_ai_jar) . ' -L -d symbol=III '
                   . escapeshellarg("$base/ai_outlook.php") . ' 2>/dev/null');
        check('the endpoint refuses an anonymous request',
              json_decode($anon_ai, true) === null && stripos($anon_ai, 'name="password"') !== false,
              'body: ' . json_encode(substr($anon_ai, 0, 120)));
        @unlink("$ai_dir/last_request");
        check('an anonymous request never reaches the API',
              !file_exists("$ai_dir/last_request"));
        @unlink($anon_ai_jar);

        /* One click, one call. */
        @unlink("$ai_dir/last_request");
        $generated = $ai_json('symbol=III');
        check('Generate returns an outlook',
              is_array($generated) && ($generated['ok'] ?? false) === true,
              json_encode(array_slice((array) $generated, 0, 2)));
        check('Generate calls the API exactly once', file_exists("$ai_dir/last_request"));
        check('the outlook has every part the panel draws',
              isset($generated['outlook']['stance'], $generated['outlook']['summary'],
                    $generated['outlook']['positives'], $generated['outlook']['risks'],
                    $generated['outlook']['action'], $generated['outlook']['watch']));

        /* The request itself: caching is the difference between a few pence
           and a few pounds over a week of clicking. */
        $sent = $ai_last_request();
        check('the system prompt is sent as a cacheable block',
              isset($sent['system'][0]['cache_control']['type'])
              && $sent['system'][0]['cache_control']['type'] === 'ephemeral',
              json_encode($sent['system'][0]['cache_control'] ?? null));
        check('the vault notes are inside that cached block',
              strpos($sent['system'][0]['text'] ?? '', 'Never more than 5%') !== false);
        check('the share data is in the user message, not the cached block',
              strpos($sent['messages'][0]['content'] ?? '', 'III') !== false
              && strpos($sent['system'][0]['text'] ?? '', '"symbol": "III"') === false);
        /* Structured outputs, not a forced tool. The live API answers HTTP
           400 on claude-fable-5-2 for tool_choice type "tool", so sending
           one is not a portable way to get a structured answer - and it is
           the configured model on the server. */
        check('the answer is constrained by a json schema',
              ($sent['output_config']['format']['type'] ?? '') === 'json_schema',
              json_encode($sent['output_config'] ?? null));
        check('the schema sent is the one the panel draws from',
              ($sent['output_config']['format']['schema']['required'] ?? [])
              === ['stance','summary','positives','risks','action','watch'],
              json_encode($sent['output_config']['format']['schema']['required'] ?? null));
        check('the request sends no tool_choice',
              !array_key_exists('tool_choice', $sent), json_encode($sent['tool_choice'] ?? null));
        check('the request sends no tools either',
              !array_key_exists('tools', $sent));
        /* Structured outputs need no beta header. */
        $hdrs_now = json_decode((string)@file_get_contents("$ai_dir/last_headers"), true);
        check('no beta header is sent',
              !isset($hdrs_now['anthropic-beta']),
              json_encode($hdrs_now['anthropic-beta'] ?? null));
        check('the model is the configured one', ($sent['model'] ?? '') === 'claude-sonnet-5-5');

        /* The key goes to the API and nowhere else. */
        $sent_headers = json_decode((string)@file_get_contents("$ai_dir/last_headers"), true);
        check('the key is sent to the API', ($sent_headers['x-api-key'] ?? '') === $ai_key);
        check('the API version header is sent',
              ($sent_headers['anthropic-version'] ?? '') === '2023-06-01');
        check('the key never reaches the browser',
              strpos(json_encode($generated), $ai_key) === false
              && strpos($quote_page, $ai_key) === false);
        check('the key is not written to the message log',
              (int) scalar("select count(*) from message_log where message_text like ?", ['%' . $ai_key . '%']) === 0);
        check('the key is not in the request body either, only the header',
              strpos(json_encode($sent), $ai_key) === false);

        /* Saved, with the usage and the cost. */
        $saved = rows("select * from ai_outlook where scope='share' and symbol='III' order by id desc limit 1");
        check('the generation is saved', count($saved) === 1);
        if (count($saved)) {
            check('the saved row records the model and the tokens',
                  $saved[0]['model'] === 'claude-sonnet-5-5'
                  && (int) $saved[0]['output_tokens'] > 0,
                  json_encode(array_intersect_key($saved[0], array_flip(['model','input_tokens','output_tokens']))));
            check('the saved row records what it cost', (float) $saved[0]['cost_usd'] > 0);
            check('the saved row keeps the inputs the model was shown',
                  strpos((string) $saved[0]['inputs_json'], '"symbol"') !== false);
            check('the saved row lists the notes used',
                  strpos((string) $saved[0]['notes_used'], 'house-policy') !== false,
                  (string) $saved[0]['notes_used']);
        }

        $reloaded = (string)$curl("$base/quote.php?symbol=III&page=quote_form.php");
        check('the panel then shows the saved outlook with its date',
              strpos($reloaded, 'No outlook yet') === false
              && strpos($reloaded, 'Not financial advice') !== false
              && strpos($reloaded, 'cost $') !== false);

        /* History is kept: the panel shows the latest, the table keeps both. */
        $ai_json('symbol=III');
        check('a second generation is kept as history, not an overwrite',
              (int) scalar("select count(*) from ai_outlook where scope='share' and symbol='III'") >= 2);

        /* A repeat click should read the cache rather than resend the vault. */
        $ai_mode('cached');
        $ai_json('symbol=III');
        $cached_row = rows("select * from ai_outlook order by id desc limit 1");
        check('a cached call is recorded as cache reads, and costs less',
              (int) $cached_row[0]['cached_tokens'] > 0
              && (float) $cached_row[0]['cost_usd'] < (float) $saved[0]['cost_usd'],
              'cached ' . $cached_row[0]['cached_tokens'] . ' tokens, $'
              . $cached_row[0]['cost_usd'] . ' vs $' . $saved[0]['cost_usd']);
        $ai_mode('ok');

        /* The market panel. */
        $market = $ai_json('scope=market');
        check('the market scope generates an outlook',
              is_array($market) && ($market['ok'] ?? false) === true);
        $market_sent = $ai_last_request();
        check('the market prompt carries index and breadth figures, not one share',
              strpos($market_sent['messages'][0]['content'] ?? '', 'breadth') !== false
              && strpos($market_sent['messages'][0]['content'] ?? '', 'Market data') !== false);
        check('the market outlook is saved with no symbol',
              (int) scalar("select count(*) from ai_outlook where scope='market' and symbol is null") >= 1);

        /* Every failure has to read as a sentence, not a blank panel. */
        foreach ([['unauthorized', 'rejected the key'],
                  ['rate_limited', 'Rate limited'],
                  ['server_error', 'had a problem'],
                  ['not_json',     'not JSON'],
                  ['prose',        'expected form'],
                  ['no_text',      'returned no answer'],
                  ['truncated',    'ran out of room'],
                  ['refusal',      'declined to answer'],
                  ['bad_stance',   'unrecognised stance'],
                  ['empty_summary','empty summary']] as [$mode, $expected]) {
            $ai_mode($mode);
            /* Not $failed: tests.php runs at file scope, so that name is
               the suite's own failure counter and assigning to it here
               silently broke the totals. */
            $ai_error = $ai_json('symbol=III');
            check("the $mode case explains itself",
                  is_array($ai_error) && ($ai_error['ok'] ?? true) === false
                  && stripos($ai_error['error'] ?? '', $expected) !== false,
                  json_encode($ai_error));
            check("the $mode case returns JSON, not a PHP error page",
                  is_array($ai_error),
                  'decoded ' . (is_array($ai_error) ? 'yes' : 'no'));
        }

        /* A model that thinks before it answers.
           On claude-fable-5-2 the first content block is a thinking block
           with no text field at all, and the answer is behind it. Reading
           content[0] gets that block and content[0]["text"] gets nothing, so
           the answer looks missing when it is simply second. The client takes
           the first block whose type is text, and this is what proves it
           rather than the code looking right. */
        $ai_mode('thinking');
        $thought = $ai_json('symbol=III');
        check('a thinking block before the answer does not hide it',
              is_array($thought) && ($thought['ok'] ?? false) === true
              && ($thought['outlook']['stance'] ?? '') === 'positive',
              json_encode(array_slice((array) $thought, 0, 2)));
        check('the model that answered is recorded, not the one configured',
              ($thought['model'] ?? '') === 'claude-fable-5-2', (string) ($thought['model'] ?? ''));

        /* The stub has to refuse what the real API refuses, or it reports
           every request as fine and the first anyone hears otherwise is in
           production - which is exactly how the forced-tool-use failure got
           as far as the server. These send schemas and a tool_choice the
           API rejects, straight at the stub, and expect a 400 back. */
        $raw_post = function ($payload) use ($ai_port) {
            $context = stream_context_create(["http" => [
                "method" => "POST",
                "header" => "content-type: application/json\r\nx-api-key: k\r\n"
                          . "anthropic-version: 2023-06-01\r\n",
                "content" => json_encode($payload),
                "timeout" => 10, "ignore_errors" => true,
            ]]);
            $http_response_header = [];
            $body = @file_get_contents("http://127.0.0.1:$ai_port/v1/messages", false, $context);
            $status = 0;
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int) $m[1]; }
            }
            return ["status" => $status, "body" => (string) $body];
        };

        $good = ["model" => "m", "max_tokens" => 4000, "messages" => [],
                 "output_config" => ["format" => ["type" => "json_schema",
                     "schema" => ["type" => "object", "additionalProperties" => false,
                                  "properties" => ["a" => ["type" => "string"]]]]]];
        $accepted = $raw_post($good);
        check('the stub accepts a schema the API would accept',
              $accepted['status'] === 200, 'status ' . $accepted['status']);

        foreach ([['minItems',  ["type"=>"array","items"=>["type"=>"string"],"minItems"=>3]],
                  ['maxItems',  ["type"=>"array","items"=>["type"=>"string"],"maxItems"=>3]],
                  ['minLength', ["type"=>"string","minLength"=>5]],
                  ['maximum',   ["type"=>"integer","maximum"=>10]]] as [$keyword, $property]) {
            $bad = $good;
            $bad['output_config']['format']['schema']['properties']['a'] = $property;
            $refused = $raw_post($bad);
            check("the stub refuses a schema carrying $keyword, as the API does",
                  $refused['status'] === 400 && stripos($refused['body'], $keyword) !== false,
                  'status ' . $refused['status'] . ' ' . substr($refused['body'], 0, 90));
        }

        $open = $good;
        $open['output_config']['format']['schema']['additionalProperties'] = true;
        $open_result = $raw_post($open);
        check('the stub refuses an object that allows extra properties',
              $open_result['status'] === 400, 'status ' . $open_result['status']);

        $forced = $good;
        $forced['tool_choice'] = ["type" => "tool", "name" => "outlook"];
        $forced_result = $raw_post($forced);
        check('the stub refuses forced tool use, as this model does',
              $forced_result['status'] === 400
              && stripos($forced_result['body'], 'not supported for this model') !== false,
              'status ' . $forced_result['status'] . ' ' . substr($forced_result['body'], 0, 90));

        /* And the schema the panel actually sends passes that same check. */
        $live = $good;
        $live['output_config']['format']['schema'] = outlook_schema();
        $live_result = $raw_post($live);
        check('the schema the panel sends is one the stub accepts',
              $live_result['status'] === 200,
              'status ' . $live_result['status'] . ' ' . substr($live_result['body'], 0, 120));

        /* Every generation is logged, not only the ones that go wrong.
           Without a line per success there is nothing to compare the
           truncations against: "it happened twice" means nothing until you
           know whether that was out of three runs or three hundred. */
        db()->exec("delete from message_log where module='llm'");
        $ai_mode('ok');
        $ai_json('symbol=III');
        $info = rows("select message_text from message_log where module='llm' order by id desc limit 1");
        check('a successful generation is logged too, not just a failure',
              count($info) === 1 && strpos($info[0]['message_text'], 'level=INFO') === 0,
              count($info) ? $info[0]['message_text'] : 'nothing logged');
        foreach (['stop_reason=', 'output_tokens=', 'thinking_tokens=',
                  'input_tokens=', 'cached_tokens=', 'duration_ms='] as $field) {
            check("the generation line carries $field",
                  count($info) === 1 && strpos($info[0]['message_text'], $field) !== false,
                  count($info) ? $info[0]['message_text'] : '');
        }
        check('the duration is a real measurement, not a placeholder',
              count($info) === 1
              && preg_match('/duration_ms=(\d+)/', $info[0]['message_text'], $dm) === 1
              && (int) $dm[1] >= 0 && (int) $dm[1] < 60000,
              count($info) ? $info[0]['message_text'] : '');
        check('a successful generation logs no warning',
              (int) scalar("select count(*) from message_log
                             where module='llm' and message_text like 'level=WARN%'") === 0);
        check('the log line carries no key, no prompt and no note text',
              count($info) === 1 && strpos($info[0]['message_text'], $ai_key) === false
              && stripos($info[0]['message_text'], 'Never more than 5%') === false);

        /* An API failure is a thing that happened, so it is counted too. */
        db()->exec("delete from message_log where module='llm'");
        $ai_mode('rate_limited');
        $ai_json('symbol=III');
        $warned = rows("select message_text from message_log where module='llm' order by id desc limit 1");
        check('a refused request is logged as a warning with its status',
              count($warned) === 1 && strpos($warned[0]['message_text'], 'level=WARN') === 0
              && strpos($warned[0]['message_text'], 'status=429') !== false,
              count($warned) ? $warned[0]['message_text'] : 'nothing logged');

        /* A truncated generation is logged, so how often it happens can be
           counted before deciding whether 4000 tokens is enough. */
        db()->exec("delete from message_log where module='llm'");
        $ai_mode('truncated');
        $ai_json('symbol=III');
        $logged = rows("select message_text from message_log
                         where module='llm' and message_text like 'level=WARN%'
                         order by id desc limit 1");
        check('a truncated generation is logged, not only shown',
              count($logged) === 1 && strpos($logged[0]['message_text'], 'stop_reason=max_tokens') !== false,
              count($logged) ? $logged[0]['message_text'] : 'nothing logged');
        check('a truncation is both counted and warned about',
              (int) scalar("select count(*) from message_log
                             where module='llm' and message_text like 'level=INFO%'") === 1
              && (int) scalar("select count(*) from message_log
                                where module='llm' and message_text like 'level=WARN%'") === 1);
        /* output_tokens includes the thinking tokens, so on a truncation it
           sits at the limit and says little on its own. thinking_tokens is
           the figure that answers how much of the budget went to thinking
           rather than to the answer, so the line is no use without it. */
        check('the log line carries the numbers needed to judge the budget',
              count($logged) === 1
              && strpos($logged[0]['message_text'], 'max_tokens=4000') !== false
              && strpos($logged[0]['message_text'], 'output_tokens=4000') !== false
              && strpos($logged[0]['message_text'], 'thinking_tokens=3900') !== false,
              count($logged) ? $logged[0]['message_text'] : '');
        check('thinking is counted inside output, not added to it',
              count($logged) === 1
              && (int) preg_replace('/.*thinking_tokens=(\d+).*/', '$1', $logged[0]['message_text'])
                 <= (int) preg_replace('/.*output_tokens=(\d+).*/', '$1', $logged[0]['message_text']),
              count($logged) ? $logged[0]['message_text'] : '');
        check('the log line carries no key and no prompt',
              count($logged) === 1 && strpos($logged[0]['message_text'], $ai_key) === false
              && stripos($logged[0]['message_text'], 'Rolls') === false);
        $ai_mode('ok');

        /* The schema enum, echoed in another case. The comparison is case
           insensitive, so this is a good answer rather than a rejected one. */
        $ai_mode('stance_case');
        $upper = $ai_json('symbol=III');
        check('a stance in a different case is accepted, not refused',
              is_array($upper) && ($upper['ok'] ?? false) === true
              && ($upper['outlook']['stance'] ?? '') === 'positive',
              json_encode(array_slice((array) $upper, 0, 2)));

        /* Out of output budget. Thinking tokens come out of max_tokens, so a
           budget that fits the answer but not the thinking in front of it
           cuts the JSON off part way. A half-written outlook is not a cheaper
           outlook, and "the shape was wrong" would be the wrong thing to
           tell someone whose answer was simply cut short. */
        $ai_mode('truncated');
        $cut = $ai_json('symbol=III');
        check('a truncated answer is an error, not an empty panel',
              is_array($cut) && ($cut['ok'] ?? true) === false,
              json_encode($cut));
        check('and it says the model ran out of room, not that the shape was wrong',
              stripos($cut['error'] ?? '', 'ran out of room') !== false
              && stripos($cut['error'] ?? '', 'max_tokens') !== false,
              (string) ($cut['error'] ?? ''));

        /* A refusal is not a malformed answer either. */
        $ai_mode('refusal');
        $declined = $ai_json('symbol=III');
        check('a refusal says the model declined, not that parsing failed',
              is_array($declined) && ($declined['ok'] ?? true) === false
              && stripos($declined['error'] ?? '', 'declined') !== false,
              (string) ($declined['error'] ?? ''));

        /* The budget itself, on the wire. */
        $ai_mode('ok');
        $ai_json('symbol=III');
        $budget_sent = $ai_last_request();
        check('the request asks for enough room for thinking and an answer',
              (int) ($budget_sent['max_tokens'] ?? 0) >= 4000,
              'max_tokens=' . var_export($budget_sent['max_tokens'] ?? null, true));
        /* Passed explicitly: the suite's own configuration is already 4000,
           so calling it with no argument would pass whether the floor exists
           or not. Too small a budget does not save anything - it truncates
           the answer, and a truncated answer is billed and discarded. */
        check('the budget cannot be configured below that floor',
              llm_max_tokens(100) === 4000 && llm_max_tokens(0) === 4000,
              llm_max_tokens(100) . ' from 100, ' . llm_max_tokens(0) . ' from 0');
        check('a larger configured budget is honoured',
              llm_max_tokens(8000) === 8000, (string) llm_max_tokens(8000));

        /* A timeout. ANTHROPIC_TIMEOUT is 5s for the suite and the stub
           sleeps 8, so this exercises the real path rather than a mock. */
        $ai_mode('slow');
        $timed_out = $ai_json('symbol=III');
        check('a timeout says so rather than hanging the panel',
              is_array($timed_out) && ($timed_out['ok'] ?? true) === false
              && stripos($timed_out['error'] ?? '', 'did not respond') !== false,
              json_encode($timed_out));
        $ai_mode('ok');

        /* Model output is untrusted: it is shaped by headlines written by
           strangers, and it is drawn on a logged-in page. */
        $ai_mode('injection');
        $nasty = $ai_json('symbol=III');
        check('output carrying markup is still accepted as text',
              is_array($nasty) && ($nasty['ok'] ?? false) === true);
        $nasty_page = (string)$curl("$base/quote.php?symbol=III&page=quote_form.php");
        check('model output is escaped, so markup in it cannot become markup',
              strpos($nasty_page, '<script>alert("xss")</script>') === false
              && strpos($nasty_page, '&lt;script&gt;') !== false);
        check('an attribute break in model output is escaped too',
              strpos($nasty_page, '"><img src=x') === false);
        $ai_mode('ok');

        /* A bad symbol must not reach the API at all. */
        @unlink("$ai_dir/last_request");
        $unknown = $ai_json('symbol=NOSUCHSHARE');
        check('an unknown symbol is refused before the API is called',
              is_array($unknown) && ($unknown['ok'] ?? true) === false
              && !file_exists("$ai_dir/last_request"),
              json_encode($unknown));
        $missing = $ai_json('');
        check('a request with no symbol and no scope is refused',
              is_array($missing) && ($missing['ok'] ?? true) === false);

        /* The vault is synced from elsewhere and may simply not be there. */
        $hidden = $ai_vault . '-away';
        rename($ai_vault, $hidden);
        $no_vault = $ai_json('symbol=III');
        check('a missing vault still produces an outlook',
              is_array($no_vault) && ($no_vault['ok'] ?? false) === true,
              json_encode(array_slice((array) $no_vault, 0, 2)));
        $no_vault_sent = $ai_last_request();
        check('and the prompt says the notes were not available',
              stripos($no_vault_sent['system'][0]['text'] ?? '', 'not available on this host') !== false);
        rename($hidden, $ai_vault);

        /* ---------------- the search box, in a real browser ----------------

           The checks below this read source, and both bugs in this box were
           invisible to that: first a scripts.js that looked right and never
           parsed, then a selection that filled the box without submitting.
           Only a browser catches those, so one drives the box here when it
           can.

           Skipped unless node, playwright and the vendored front-end assets
           are all present. public/js is in .gitignore and filled by
           tools/fetch_assets.sh, so a fresh clone has no jQuery to run and
           there is nothing to drive. */
        $browser_js = dirname(__DIR__) . '/tests/typeahead_browser.js';
        $assets = [dirname(__DIR__) . '/public/js/jquery-3.3.1.min.js',
                   dirname(__DIR__) . '/public/js/typeahead.jquery.js',
                   dirname(__DIR__) . '/public/js/bloodhound.min.js',
                   dirname(__DIR__) . '/public/js/scripts.js'];
        $missing = array_values(array_filter($assets, fn($f) => !file_exists($f)));

        exec('command -v node >/dev/null 2>&1 && node -e "require(\'playwright\')" 2>&1 || echo NO_PLAYWRIGHT',
             $pw_out, $pw_rc);
        $no_playwright = in_array('NO_PLAYWRIGHT', $pw_out, true) || $pw_rc !== 0;

        if ($no_playwright) {
            echo "  \033[33mSKIP\033[0m  the search box in a browser (node or playwright not installed)\n";
        }
        elseif ($missing) {
            echo "  \033[33mSKIP\033[0m  the search box in a browser (run tools/fetch_assets.sh first; missing "
                 . implode(', ', array_map('basename', $missing)) . ")\n";
        }
        else {
            /* The served copy of scripts.js is what the browser will load.
               fetch_assets.sh makes it, but it is a copy, so refresh it here
               rather than testing whatever was left behind by an earlier run. */
            @copy(dirname(__DIR__) . '/templates/scripts.js', dirname(__DIR__) . '/public/js/scripts.js');
            @copy(dirname(__DIR__) . '/templates/typeahead.css', dirname(__DIR__) . '/public/css/typeahead.css');

            $cmd = 'node ' . escapeshellarg($browser_js) . ' ' . escapeshellarg($base)
                 . ' tester testpass RR rol 2>&1';
            $browser_out = [];
            exec($cmd, $browser_out);
            foreach ($browser_out as $line) {
                if (strpos($line, 'PASS ') === 0)      { check(substr($line, 5), true); }
                elseif (strpos($line, 'FAIL ') === 0)  {
                    $parts = explode(': ', substr($line, 5), 2);
                    check($parts[0], false, $parts[1] ?? '');
                }
            }
            if (!array_filter($browser_out, fn($l) => strpos($l, 'PASS ') === 0 || strpos($l, 'FAIL ') === 0)) {
                check('the browser run produced results', false,
                      implode(' | ', array_slice($browser_out, 0, 4)));
            }
        }

        /* ---------------- the script that wires it up ---------------- */

        /* Comments are stripped before these string searches. The comment
           explaining a fixed selector contains the broken selector, so a
           plain strpos() over the file finds the explanation and reports the
           bug as still present - the same trap the provider tests hit with
           md5(90*13+id). */
        $strip_js = function ($src) {
            $src = preg_replace('#/\\*.*?\\*/#s', '', $src);
            return preg_replace('#^\\s*//.*$#m', '', $src);
        };
        $js = $strip_js((string)file_get_contents(dirname(__DIR__) . '/templates/scripts.js'));
        /* The whole file was a SyntaxError: an unterminated string literal in
           a #btnAddrow handler ran off the end of its line. The browser
           executed none of it, which is why neither the suggestions nor the
           tooltip setup ever ran. node is not a dependency of this suite, so
           the check is skipped where it is absent rather than passing
           vacuously. */
        exec('command -v node >/dev/null 2>&1 && node --check '
             . escapeshellarg(dirname(__DIR__) . '/templates/scripts.js') . ' 2>&1 || echo NO_NODE',
             $node_out, $node_rc);
        $node_said = implode("\n", $node_out);
        if (trim($node_said) === 'NO_NODE') {
            echo "  \033[33mSKIP\033[0m  scripts.js parses (node not installed)\n";
        }
        else {
            check('scripts.js parses, so the browser runs any of it',
                  $node_rc === 0, $node_said);
        }
        check('the typeahead is not bound to a selector that matches nothing',
              strpos($js, '#symbolInput .typeahead') === false);
        check('the typeahead is bound after the document is ready, not in <head>',
              preg_match('/\$\(document\)\.ready\(/', $js) === 1
              && strpos(substr($js, 0, strpos($js, ".typeahead({")), '$(document).ready(') !== false);
        check('the suggestions come from the database, not a hard-coded list',
              strpos($js, "['AAA','BBB','CCC']") === false
              && strpos($js, 'symbol_search.php?term=%QUERY') !== false);
        check('picking a suggestion submits the form',
              strpos($js, 'typeahead:select') !== false
              && preg_match('/closest\([\'"]form[\'"]\)/', $js) === 1);
        /* typeahead.js ships no CSS, so without this the list is invisible. */
        $tacss = (string)file_get_contents(dirname(__DIR__) . '/templates/typeahead.css');
        check('the suggestion list has a stylesheet, and the page loads it',
              strpos($tacss, '.tt-menu') !== false
              && strpos((string)file_get_contents(dirname(__DIR__) . '/templates/header.php'),
                        'css/typeahead.css') !== false);
        check('fetch_assets.sh installs that stylesheet',
              strpos((string)file_get_contents(dirname(__DIR__) . '/tools/fetch_assets.sh'),
                     'typeahead.css') !== false);

        /* The footer loaded the Bootstrap 4.1.3 CDN bundle on top of the
           Bootstrap 5 bundle header.php loads. It was already commented out,
           so this pins it rather than fixing it. */
        $footer = preg_replace('/<!--.*?-->/s', '',
                               (string)file_get_contents(dirname(__DIR__) . '/templates/footer.php'));
        check('the footer loads no second Bootstrap',
              strpos($footer, 'stackpath') === false && strpos($footer, '4.1.3') === false);

        db()->exec("delete from stock_symbols where symbol in ('BT-A','RR')");

        /* ratings() returned null for a symbol the ratings job had not reached,
           and the Ratings tab then read five offsets off it. */
        check('a symbol with no rating row still returns the rating keys',
              array_keys(ratings('ZZZZ')) ===
              ['momentum_rating','growth_rating','value_rating','quality_rating','overall_rating']);

        /* An unknown symbol takes the lookup()-returns-false path, which is what
           the misplaced log line tripped over. */
        $unknown = (string)$curl("$base/quote.php?symbol=ZZZZ&page=quote_form.php");
        check('an unknown symbol is handled without a warning or fatal',
              !preg_match('/(Fatal error|Parse error|Warning<\/b>|Warning:|Undefined array key)/i', $unknown),
              substr(strip_tags($unknown), 0, 150));

        /* quote.php used to fall back to rendering quote_form.php, which is the
           dashboard template and needs five variables index.php builds. Passing
           only a title left every panel iterating an undefined variable. Both
           fallbacks now go somewhere that can populate what it renders. */
        $noSymbol = (string)$curl("$base/quote.php");
        check('quote.php with no symbol lands somewhere populated',
              !preg_match('/Undefined variable/i', $noSymbol)
              && substr_count($noSymbol, 'quote.php?symbol=') >= 10,
              substr(strip_tags($noSymbol), 0, 160));

        /* The general form: a template that iterates a variable it was not
           given. Catches the next caller that renders a template half-populated,
           rather than only the case already fixed. */
        $undef = [];
        foreach ($pages as $page) {
            $body = (string)$curl("$base/$page");
            if (preg_match('/Undefined (variable|array key)/i', $body)) { $undef[] = $page; }
        }
        foreach (['quote.php', 'quote.php?symbol=AAA', 'quote.php?symbol=ZZZZ',
                  'index.php?symbol=AAA'] as $u) {
            $body = (string)$curl("$base/$u");
            if (preg_match('/Undefined (variable|array key)/i', $body)) { $undef[] = $u; }
        }
        /* screening.php was the one known exception here: it rendered
           templates/screen.php with MomentumList, TrendList and EarningsGrowth,
           while that template iterates the $stats and $indicators that
           screen_list.php passes. It was linked from nowhere and read
           price_momentum, the legacy table calc_momentum stopped writing to,
           so it is archived and the exception is gone. */
        $known_undef = [];
        sort($undef); sort($known_undef);
        check('no page renders a template with a variable it was not given',
              $undef === $known_undef,
              'now: ' . (implode(', ', $undef) ?: 'none') . ' | expected: ' . implode(', ', $known_undef));

        /* A freshly registered account is the case the seeded user cannot cover.
           register.php logs the user straight in without going through
           login.php, and it did not set users.default_exchange or
           $_SESSION["exchange"], so a new account got "Undefined array key
           exchange" warnings and four empty dashboard panels. */
        $newuser = 'reg' . substr((string)time(), -6);
        $regjar  = tempnam(sys_get_temp_dir(), 'smreg');
        $regcurl = function ($url, $post = null) use ($regjar) {
            $cmd = 'curl -s -m 30 -b ' . escapeshellarg($regjar) . ' -c ' . escapeshellarg($regjar);
            if ($post !== null) { $cmd .= ' -d ' . escapeshellarg($post); }
            return shell_exec($cmd . ' -L ' . escapeshellarg($url) . ' 2>/dev/null');
        };
        $regcurl("$base/register.php");
        $regcurl("$base/register.php",
                 "username=$newuser&password=pw123456&confirmation=pw123456&useremail=$newuser@example.com");

        check('registering stores a default exchange on the account',
              scalar("select default_exchange from users where username=?", [$newuser]) === 'XLON',
              var_export(scalar("select default_exchange from users where username=?", [$newuser]), true));

        $fresh = (string)$regcurl("$base/index.php");
        check('a newly registered account sees no undefined-key warning',
              stripos($fresh, 'Undefined array key') === false,
              implode(' | ', array_slice(array_filter(explode("\n", strip_tags($fresh)),
                       fn($l) => stripos($l, 'Undefined') !== false), 0, 2)));
        check('a newly registered account gets a populated dashboard',
              substr_count($fresh, 'quote.php?symbol=') >= 10,
              substr_count($fresh, 'quote.php?symbol=') . ' rows');

        /* Everything above walks the site as the seeded user, who owns a full
           portfolio, so it never saw the pages that assume the account has
           history. A new account has none: get_portfolio_overview() returned
           null for it and performance.php read three offsets off that, then
           handed the nulls to number_format. Walk the same pages as the account
           that has just registered. */
        $fresh_warn = [];
        foreach ($pages as $page) {
            $body = (string)$regcurl("$base/$page");
            if (preg_match('/(Warning<\/b>|Deprecated<\/b>|Fatal error|Undefined variable)/i', $body)) {
                $fresh_warn[] = $page;
            }
        }
        sort($fresh_warn);
        check('an account with no history can walk the site',
              $fresh_warn === $known_undef,
              'now: ' . implode(', ', $fresh_warn) . ' | expected: ' . implode(', ', $known_undef));

        /* The case that survived the first fix: a session that already existed
           before it. Setting the key only at register and login time leaves
           every open session broken until the user logs out, which is not
           obvious from a page full of warnings. Reading through
           session_exchange() makes a missing key a default instead. */
        check('a missing session key falls back rather than warning',
              session_exchange() === 'XLON');
        $saved = $_SESSION["exchange"] ?? null;
        unset($_SESSION["exchange"]);
        check('session_exchange() covers an unset key', session_exchange() === 'XLON');
        $_SESSION["exchange"] = "";
        check('session_exchange() covers an empty key', session_exchange() === 'XLON');
        $_SESSION["exchange"] = "JSE";
        check('session_exchange() honours a real value', session_exchange() === 'JSE');
        if ($saved === null) { unset($_SESSION["exchange"]); } else { $_SESSION["exchange"] = $saved; }

        /* Reading the key directly is fine in a batch script that sets it first.
           The bug is reading a key you did not set, which is what every web page
           does: it inherits whatever the session happens to hold. Flag only
           that, or this comes back next time someone adds a page. */
        $raw = [];
        foreach (array_merge(glob(dirname(__DIR__) . '/includes/*.php'),
                             glob(dirname(__DIR__) . '/public/*.php'),
                             glob(dirname(__DIR__) . '/templates/*.php')) as $file) {
            if (basename($file) === 'functions.php') { continue; }   // holds the accessor
            $src = file_get_contents($file);
            $assign = '/\$_SESSION\s*\[\s*[\'"]exchange[\'"]\s*\]\s*=(?!=)/';
            $any    = '/\$_SESSION\s*\[\s*[\'"]exchange[\'"]\s*\]/';
            $sets   = preg_match($assign, $src);
            $reads  = preg_match($any, preg_replace($assign, '', $src));
            if ($reads && !$sets) { $raw[] = basename($file); }
        }
        check('nothing reads the session exchange without setting it',
              count($raw) === 0, implode(', ', $raw));

        /* And the same account after a real login round trip, which takes the
           other code path. */
        $regcurl("$base/logout.php");
        $regcurl("$base/login.php");
        $regcurl("$base/login.php", "username=$newuser&password=pw123456");
        $relogged = (string)$regcurl("$base/index.php");
        check('the same account works after logging out and back in',
              stripos($relogged, 'Undefined array key') === false
              && substr_count($relogged, 'quote.php?symbol=') >= 10);

        db()->prepare("delete from users where username=?")->execute([$newuser]);
        @unlink($regjar);

        /* search.php carried the one live SQL injection in the application and is
           now archived out of the document root, so there is nothing left to
           probe over HTTP. What is still worth asserting is that no page still
           in public/ concatenates request data into a query. */
        $interpolating = [];
        foreach (glob(dirname(__DIR__) . '/public/*.php') as $page) {
            $src = file_get_contents($page);
            if (preg_match('/query\s*\(\s*"[^"]*"\s*\.\s*\$_(GET|POST|REQUEST|COOKIE)/', $src)
                || preg_match('/query\s*\(\s*"[^"]*\$_(GET|POST|REQUEST|COOKIE)/', $src)) {
                $interpolating[] = basename($page);
            }
        }
        /* ---------------------------------------------------------------
           Things that only matter once the site is reachable from the
           internet. Every one of these was exploitable as it stood.
           --------------------------------------------------------------- */

        /* The login gate matched the tail of PHP_SELF, which is SCRIPT_NAME
           with PATH_INFO appended. Under php-fpm behind Caddy or nginx a
           request for /index.php/login.php runs index.php with PHP_SELF ending
           in login.php, so an unauthenticated caller got the page. */
        $gate_src = (string)file_get_contents(dirname(__DIR__) . '/includes/config.php');
        check('the login gate does not read PHP_SELF',
              strpos($gate_src, 'PHP_SELF') === false
              || strpos($gate_src, 'preg_match("{(?:login') === false);
        $jar2 = tempnam(sys_get_temp_dir(), 'smj');
        $anon = function ($url) use ($jar2) {
            return (string)shell_exec('curl -s -m 30 -c ' . escapeshellarg($jar2)
                                      . ' ' . escapeshellarg($url) . ' 2>/dev/null');
        };
        foreach (['index.php', 'index.php/login.php', 'performance.php/register.php'] as $path) {
            $body = $anon("$base/$path");
            check("an anonymous request for /$path does not reach a logged in page",
                  strpos($body, 'Top Ten') === false && strpos($body, 'Log Out') === false,
                  substr(strip_tags($body), 0, 120));
        }
        @unlink($jar2);

        /* crypt($password,'sharemanager') is a fixed two character DES salt, so
           identical passwords hash identically for every account and everything
           past the eighth character is discarded. */
        check('a password longer than eight characters is not truncated',
              hash_password('correcthorsebattery') !== hash_password('correctho')
              && verify_password('correcthorsebattery', hash_password('correcthorsebattery'))
              && !verify_password('correctho', hash_password('correcthorsebattery')));
        check('two accounts with the same password get different hashes',
              hash_password('samepassword') !== hash_password('samepassword'));
        check('the legacy crypt hashes still verify, so nobody is locked out',
              verify_password('testpass', crypt('testpass', 'sharemanager'))
              && !verify_password('wrongpass', crypt('testpass', 'sharemanager')));
        check('a legacy hash is marked for upgrade and a new one is not',
              password_needs_upgrade(crypt('testpass', 'sharemanager'))
              && !password_needs_upgrade(hash_password('testpass')));

        /* The seeded user carries a legacy hash, so logging in should replace
           it in place - that is how the weak hashes drain away. */
        db()->exec("update users set hash = " . db()->quote(crypt('testpass', 'sharemanager'))
                   . " where username = 'tester'");
        $jar3 = tempnam(sys_get_temp_dir(), 'smj');
        shell_exec('curl -s -m 30 -c ' . escapeshellarg($jar3) . ' -b ' . escapeshellarg($jar3)
                   . ' -d ' . escapeshellarg('username=tester&password=testpass')
                   . ' -o /dev/null ' . escapeshellarg("$base/login.php") . ' 2>/dev/null');
        $stored = scalar("select hash from users where username='tester'");
        check('logging in upgrades a legacy hash in place',
              !password_needs_upgrade($stored) && verify_password('testpass', $stored),
              substr((string)$stored, 0, 30));
        check('the upgraded hash still lets the same password in',
              verify_password('testpass', $stored));
        @unlink($jar3);

        /* The reset link carried md5(90*13+id) - md5(1170 + the user id) - which
           anyone can compute for any account, on a page deliberately exempt from
           the login gate. */
        /* Match the code, not the prose: the comments that explain the old
           scheme quote it by name. php_strip_whitespace drops comments. */
        $reset_src = php_strip_whitespace(dirname(__DIR__) . '/includes/functions.php')
                   . php_strip_whitespace(dirname(__DIR__) . '/public/reset_passwd.php');
        check('no reset token is derived from the user id',
              strpos($reset_src, 'md5(90*13+id)') === false
              && strpos($reset_src, "md5(90*13+\$row['id'])") === false);
        $uid = (int)scalar("select id from users where username='tester'");
        check('the old guessable token is rejected',
              reset_token_user(md5(1170 + $uid)) === null);
        $tok = create_reset_token($uid);
        check('a freshly minted token resolves to its account',
              (int)reset_token_user($tok) === $uid);
        check('the raw token is never stored, only its hash',
              (int)scalar("select count(*) from password_resets where token_hash = ?", [$tok]) === 0
              && (int)scalar("select count(*) from password_resets where token_hash = ?",
                             [hash('sha256', $tok)]) === 1);
        check('tokens are long and random, not a digest of anything guessable',
              strlen($tok) === 64 && $tok !== create_reset_token($uid));
        db()->exec("update password_resets set used_at = now() where user_id = $uid and used_at is null");
        check('a used token stops working', reset_token_user($tok) === null);
        $expired = create_reset_token($uid);
        db()->exec("update password_resets set expires_at = date_sub(now(), interval 1 hour)"
                   . " where user_id = $uid and used_at is null");
        check('an expired token stops working', reset_token_user($expired) === null);

        /* A warning on a public page carries the filesystem path and often part
           of the query that failed. */
        check('errors are not displayed on screen unless asked for',
              strpos($gate_src, 'getenv("SM_DISPLAY_ERRORS")') !== false
              && strpos($gate_src, 'ini_set("display_errors", true)') === false);
        check('the session cookie is httponly and samesite',
              strpos($gate_src, '"httponly" => true') !== false
              && strpos($gate_src, '"samesite" => "Lax"') !== false);
        $login_src = (string)file_get_contents(dirname(__DIR__) . '/public/login.php');
        check('the session id is regenerated when the session gains privilege',
              strpos($login_src, 'session_regenerate_id(true)') !== false);

        /* ---------------------------------------------------------------
           Bootstrap 5. The upgrade from 4 renamed every behavioural data
           attribute and several classes, and a leftover renames to nothing -
           the element simply stops working, silently, with no error anywhere.
           --------------------------------------------------------------- */
        $markup = [];
        foreach (array_merge(glob(dirname(__DIR__) . '/templates/*.php'),
                             glob(dirname(__DIR__) . '/public/*.php')) as $f) {
            if (strpos(basename($f), 'bootstrap') !== false) { continue; }
            $markup[$f] = (string)file_get_contents($f);
        }
        $all = implode("\n", $markup);

        $stale = [];
        foreach (['data-toggle=', 'data-dismiss=', 'data-target=', 'data-parent='] as $attr) {
            foreach ($markup as $f => $src) {
                if (strpos($src, $attr) !== false) { $stale[] = basename($f) . ':' . rtrim($attr, '='); }
            }
        }
        check('no Bootstrap 4 data attributes survive the upgrade', count($stale) === 0,
              implode(', ', array_unique($stale)));

        $dropped = [];
        foreach (['badge-success', 'badge-danger', 'badge-warning', 'badge-info',
                  'sr-only', 'form-group', 'jumbotron', 'table-condensed',
                  'form-inline', 'class="close"'] as $cls) {
            if (strpos($all, $cls) !== false) { $dropped[] = $cls; }
        }
        check('no classes Bootstrap 5 removed survive the upgrade', count($dropped) === 0,
              implode(', ', $dropped));

        /* The navbar collapses on small screens and had no button to reopen it,
           so the whole navigation was unreachable on a phone. */
        $header = $markup[dirname(__DIR__) . '/templates/header.php'] ?? '';
        check('the navbar has a toggler, so it is usable on a phone',
              strpos($header, 'navbar-toggler') !== false
              && strpos($header, 'data-bs-target="#navbarNavDropdown"') !== false);

        /* Tooltips are opt-in in both 4 and 5: the quote page marks them up and
           nothing initialised them, so they never worked. */
        $js = (string)file_get_contents(dirname(__DIR__) . '/templates/scripts.js');
        check('tooltips are initialised, not just marked up',
              strpos($js, 'bootstrap.Tooltip') !== false);

        check('the asset fetcher pulls Bootstrap 5',
              preg_match('#/bootstrap/5\.#',
                         (string)file_get_contents(dirname(__DIR__) . '/tools/fetch_assets.sh')) === 1);

        check('no served page interpolates request data into SQL',
              count($interpolating) === 0, implode(', ', $interpolating));

        @unlink($jar);
    }

    if ($pid > 0) { exec("kill $pid 2>/dev/null"); }
    if (!empty($stub_pid)) { exec("kill $stub_pid 2>/dev/null"); }
    if (!empty($ai_stub_pid)) { exec("kill $ai_stub_pid 2>/dev/null"); }

    /* The counters live at file scope, which is also where every test's own
       variables live, so a test that uses $passed, $failed or $failures as a
       scratch name overwrites them and the totals quietly stop meaning
       anything. That has happened twice. If they no longer agree, say so
       rather than printing a number nobody can trust. */
    if (!is_int($failed) || !is_int($passed) || !is_array($failures) || count($failures) !== $failed) {
        printf("\n\033[31mBROKEN COUNTERS\033[0m  the suite's own tallies were overwritten by a test: "
               . "passed=%s failed=%s failures=%s\n"
               . "  Something assigned to \$passed, \$failed or \$failures at file scope.\n\n",
               var_export($passed, true), var_export($failed, true), var_export($failures, true));
        exit(1);
    }

    printf("\n%s  %d passed, %d failed\n\n",
           $failed === 0 ? "\033[32mALL PASSED\033[0m" : "\033[31mFAILURES\033[0m", $passed, $failed);
    if ($failed > 0) { echo "  failed: " . implode("\n          ", $failures) . "\n\n"; }
    exit($failed === 0 ? 0 : 1);

?>
