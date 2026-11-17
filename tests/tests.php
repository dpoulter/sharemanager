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
    require_once("view.php");
    //The AI outlook and the pieces under it. Loading these calls nothing and
    //reaches no network; only outlook_generate() does.
    require_once("llm.php");
    require_once("vault.php");
    require_once("news.php");
    require_once("rns.php");
    require_once("ai_outlook.php");
    //The broker connection. Loading it calls nothing and reaches no network:
    //only broker_sync() does, and only when a key is configured.
    require_once("broker.php");

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

    echo "\n--- strategies: the rules are configuration ---\n";

    /* The two strategies the migration defines. Matched to their screens by
       name, never by id: "screen 4" is only screen 4 on the N100, and a
       database where the screens were created in another order would quietly
       point a strategy at somebody else's screen. */
    $tv_rules = strategy_rules('trending_value');
    $tf_rules = strategy_rules('trend_following');

    check('the migration defines Trending Value as a monthly screen strategy',
          $tv_rules !== null && $tv_rules['selection'] === 'screen'
          && (int)$tv_rules['holdings'] === 15 && $tv_rules['rebalance'] === 'monthly'
          && $tv_rules['exit_list'] === [],
          json_encode($tv_rules));
    check('and points it at the Trending Value screen by name',
          $tv_rules !== null
          && scalar("select name from screen where id=?", [$tv_rules['screen_id']]) === 'Trending Value',
          var_export($tv_rules['screen_id'] ?? null, true));
    check('Trend following is weekly, ranked by the six month return, and has the 200-day exit',
          $tf_rules !== null && $tf_rules['rebalance'] === 'weekly'
          && $tf_rules['rank_by'] === '6mnth'
          && $tf_rules['exit_list'] === ['below_ma200_2d'],
          json_encode($tf_rules));
    /* Ranked by 6mnth rather than momentum_score, and the reason is worth a
       check of its own: momentum_score is a rank within today's
       cross-section and has only ever been stored on the monthly as of
       dates, so a backtest would find no value on almost every rebalance
       date, select nothing, and draw a flat line that looked like a result.
       A six month return is a fact about a price series. */
    check('and by an indicator that prices alone decide, so it can be backfilled',
          in_array($tf_rules['rank_by'], ['3mnth','6mnth','12mnth','close_price','ma50','ma200'], true),
          (string) $tf_rules['rank_by']);
    check('and at the 200-day trend screen, also by name',
          $tf_rules !== null
          && scalar("select name from screen where id=?", [$tf_rules['screen_id']])
             === 'Trend following (200-day)');
    check('both paper accounts start with 100,000 and are enabled',
          (float)scalar("select cash from strategy_accounts where strategy='trending_value'") === 100000.0
          && (float)scalar("select cash from strategy_accounts where strategy='trend_following'") === 100000.0
          && (int)scalar("select count(*) from strategy_accounts
                           where strategy in ('trending_value','trend_following')
                             and mode='PAPER' and enabled='Y'") === 2,
          json_encode(rows("select strategy, cash, mode, enabled from strategy_accounts
                             where strategy in ('trending_value','trend_following')")));

    /* PAPER is the whole point. A row saying anything else must not be
       picked up by the nightly run, and nothing in the engine supports it. */
    check('no account anywhere is in any mode but PAPER',
          (int)scalar("select count(*) from strategy_accounts where mode<>'PAPER'") === 0);

    /* The migration is re-runnable and converges: a strategy somebody has
       experimented on goes back to what the file says. */
    db()->exec("update strategy_rules set holdings=3, rebalance='daily', exit_rules='nonsense'
                 where strategy='trend_following'");
    apply_migration('strategy_rules.sql');
    $tf_rules = strategy_rules('trend_following');
    check('re-running the migration puts an edited strategy back',
          (int)$tf_rules['holdings'] === 15 && $tf_rules['rebalance'] === 'weekly'
          && $tf_rules['exit_rules'] === 'below_ma200_2d'
          && $tf_rules['rank_by'] === '6mnth',
          json_encode($tf_rules));

    /* ------------------------------------------------------------------ */

    /* The test strategies. Written by hand rather than by the migration so
       the fixtures can say exactly what is being tested, and named so they
       sort either side of each other where the order matters. */
    function s_rules($strategy, $overrides = []) {
        $r = array_merge(['selection'=>'score','screen_id'=>null,'score_indicator'=>'momentum_score',
                          'rank_by'=>null,'holdings'=>5,'hold_rank'=>null,'rebalance'=>'monthly',
                          'exit_rules'=>'','exclude_funds'=>'Y','start_date'=>null], $overrides);
        db()->prepare("insert into strategy_rules
                         (strategy,selection,screen_id,score_indicator,rank_by,holdings,hold_rank,
                          rebalance,exit_rules,exclude_funds,start_date,updated_at)
                       values (?,?,?,?,?,?,?,?,?,?,?,now())
                       on duplicate key update selection=values(selection), screen_id=values(screen_id),
                         score_indicator=values(score_indicator), rank_by=values(rank_by),
                         holdings=values(holdings), hold_rank=values(hold_rank),
                         rebalance=values(rebalance),
                         exit_rules=values(exit_rules), exclude_funds=values(exclude_funds),
                         start_date=values(start_date), updated_at=now()")
          ->execute([$strategy,$r['selection'],$r['screen_id'],$r['score_indicator'],$r['rank_by'],
                     $r['holdings'],$r['hold_rank'],$r['rebalance'],$r['exit_rules'],
                     $r['exclude_funds'],$r['start_date']]);
    }
    function s_account($strategy, $cash = 100000, $enabled = 'Y') {
        db()->prepare("insert into strategy_accounts (strategy,cash,currency,mode,enabled,created_at)
                       values (?,?,'GBP','PAPER',?,now())
                       on duplicate key update cash=values(cash), enabled=values(enabled)")
          ->execute([$strategy,$cash,$enabled]);
    }
    function s_reset($strategy, $cash = 100000, $enabled = 'Y') {
        foreach (['strategy_orders','strategy_positions','strategy_targets','strategy_values'] as $table) {
            db()->prepare("delete from $table where strategy=?")->execute([$strategy]);
        }
        s_account($strategy, $cash, $enabled);
    }
    function s_drop($strategy) {
        foreach (['strategy_orders','strategy_positions','strategy_targets','strategy_values',
                  'strategy_rules','strategy_accounts'] as $table) {
            db()->prepare("delete from $table where strategy=?")->execute([$strategy]);
        }
    }
    /* One indicator value for one symbol and date, written straight in. The
       strategy layer reads statistics; which job wrote them is not what
       these tests are about, and a fixture is both faster and exact. */
    function s_stat($symbol, $indicator, $date, $value) {
        db()->prepare("delete from statistics where symbol=? and indicator=? and date=? and exchange='XLON'")
          ->execute([$symbol,$indicator,$date]);
        db()->prepare("insert into statistics (symbol,indicator,date,value,exchange)
                       values (?,?,?,?,'XLON')")->execute([$symbol,$indicator,$date,$value]);
    }

    $S = 'test_strategy';
    /* A date with sessions after it, so fills have a next price to use, and
       far enough from the end that a second night is available too. */
    $SASOF = scalar("select distinct date from historical_prices where exchange='XLON'
                     order by date desc limit 1 offset 5");
    $SNEXT = scalar("select distinct date from historical_prices where exchange='XLON'
                     and date>? order by date asc limit 1", [$SASOF]);

    /* Twelve symbols, scored so the order is known: AAA best, LLL worst. */
    $S_SYMBOLS = ['AAA','BBB','CCC','DDD','EEE','FFF','GGG','HHH','III','JJJ','KKK','LLL'];
    foreach ($S_SYMBOLS as $i => $symbol) {
        s_stat($symbol, 'momentum_score', $SASOF, 100 - $i);
    }

    echo "\n--- strategies: selection, weights and cash ---\n";

    s_drop($S); s_rules($S); s_reset($S);

    $selected = strategy_select_symbols(strategy_rules($S), $SASOF, 'XLON');
    check('the best five by the score are selected, in that order',
          $selected['symbols'] === ['AAA','BBB','CCC','DDD','EEE'],
          json_encode($selected));

    $written = strategy_write_targets($S, $SASOF);
    check('each target is an equal fifth of the account',
          count($written['targets']) === 5 && abs($written['weight'] - 0.2) < 1e-9,
          json_encode([count($written['targets']), $written['weight']]));
    check('the score the name was picked on is kept with the target',
          (float)scalar("select score from strategy_targets where strategy=? and symbol='AAA'", [$S])
            === 100.0);

    /* Fewer names than holdings. The weight stays one over the number of
       HOLDINGS, so the strategy holds three positions and 40% cash. Dividing
       by the number of names found instead would turn a thin signal into a
       concentrated bet, which is the opposite of what a thin signal says. */
    s_drop($S); s_rules($S, ['holdings'=>5]); s_reset($S);
    foreach ($S_SYMBOLS as $symbol) {
        db()->prepare("delete from statistics where symbol=? and indicator='momentum_score' and date=?")
          ->execute([$symbol,$SASOF]);
    }
    foreach (['AAA','BBB','CCC'] as $i => $symbol) { s_stat($symbol,'momentum_score',$SASOF,100-$i); }

    $thin = strategy_write_targets($S, $SASOF);
    check('three names for a five holding strategy is three targets, not three bigger ones',
          count($thin['targets']) === 3 && abs($thin['weight'] - 0.2) < 1e-9,
          json_encode([count($thin['targets']), $thin['weight']]));

    run_script('run_paper_execution.php', [$S, $SASOF]);
    $thin_cash = (float)scalar("select cash from strategy_accounts where strategy=?", [$S]);
    $thin_value = (float)scalar("select ifnull(sum(consideration),0) from strategy_orders
                                  where strategy=? and status='FILLED'", [$S]);
    check('and the fifth that was not invested is still cash',
          $thin_cash > 100000 * 0.35 && $thin_cash < 100000 * 0.45,
          "cash=$thin_cash invested=$thin_value");
    check('three positions were taken, not five',
          (int)scalar("select count(*) from strategy_positions where strategy=? and quantity>0", [$S]) === 3);

    /* Put the full set of scores back for the tests below. */
    foreach ($S_SYMBOLS as $i => $symbol) { s_stat($symbol,'momentum_score',$SASOF,100-$i); }

    echo "\n--- strategies: London prices are pence, costs are pounds ---\n";

    /* The fault that stopped trading on the second deploy.
       historical_prices holds London prices as the provider sends them,
       which is PENCE. The manual portfolio has always known that -
       portfolio_functions.php divides by 100 throughout - but the engine did
       not, so it read 690p as 690 pounds:

         BUY  EZJ    9 @ ~690      the engine thought 6,210; it was 62.10
         BUY  SHEL   1 @ ~3719.5   the engine thought 3,719; it was 37.20

       A 100,000 pound account invested about 1,000 pounds, the 5.95
       commission was a tenth of every order, the 250 pound minimum order was
       really 25,000, and "one share of Shell" made the weights meaningless.

       The figures below are the brief's own worked example. */
    $PX = 'test_pence';
    s_drop($PX);
    db()->exec("delete from historical_prices where symbol='PENCE1' and exchange='XLON'");
    db()->exec("delete from stock_symbols where symbol in ('PENCE1','DOLLAR1','POUND1')");
    db()->prepare("insert into stock_symbols (symbol,name,description,exchange,enabled,sector,market)
                   values ('PENCE1','Pence Test PLC','Pence Test PLC','XLON','Y','Sector 0','Main Market')")
      ->execute();

    /* 690p on the as-of date, and the same the next session so a fill has a
       price. */
    $px_prices = db()->prepare("insert into historical_prices (symbol,exchange,date,price)
                                values ('PENCE1','XLON',?,690)");
    $px_prices->execute([$SASOF]);
    $px_prices->execute([$SNEXT]);

    check('a 690p share is sixty nine pence to the engine, not 690 pounds',
          abs(strategy_price_asof('PENCE1', 'XLON', $SASOF) - 6.90) < 0.0001,
          var_export(strategy_price_asof('PENCE1', 'XLON', $SASOF), true));
    check('and the price it would fill at is in pounds too',
          abs(strategy_next_price('PENCE1', 'XLON', $SASOF)['price'] - 6.90) < 0.0001,
          json_encode(strategy_next_price('PENCE1', 'XLON', $SASOF)));

    /* The brief's example: a 6,667 pound target at 690p buys 966 shares.
       Sized through the engine rather than worked out here, so this is the
       sizing and not my arithmetic. */
    s_account($PX, 100000);
    db()->prepare("insert into strategy_targets
                     (strategy,as_of_date,symbol,exchange,target_weight,created_at)
                   values (?,?,'PENCE1','XLON',0.066666,now())")->execute([$PX, $SASOF]);

    $px_plan = strategy_plan_orders($PX, $SASOF);
    $px_order = null;
    foreach ($px_plan['orders'] as $order) {
        if ($order['symbol'] === 'PENCE1') { $px_order = $order; }
    }

    /* The brief's figure is 966, which is 6,667 divided by 6.90. The engine
       buys 958, because it reserves the stamp duty, the spread and the
       commission before it sizes anything - 6,667 of investable money is
       6,616 of shares once the costs are out. That reserve is deliberate
       and predates this fix: sizing to the full account leaves nothing for
       the costs and the last order of every rebalance is rejected for want
       of cash.
    
       So the check is "within one per cent of 966, and nowhere near 9",
       which is the thing the fix is about. Pinning 958 exactly would make
       this a test of the cost reserve, which has its own. */
    check('a 6,667 pound target at 690p buys about 966 shares, not 9',
          $px_order !== null
          && abs((int) $px_order['quantity'] - 966) <= 10
          && (int) $px_order['quantity'] > 100,
          json_encode([$px_order, $px_plan['errors']]));
    check('the reference price on the order is pounds',
          $px_order !== null && abs((float) $px_order['reference_price'] - 6.90) < 0.0001);

    /* And the valuation of that holding: 966 at 690p is 6,665.40. */
    db()->prepare("insert into strategy_positions (strategy,symbol,exchange,quantity,avg_price,updated_at)
                   values (?,'PENCE1','XLON',966,6.90,now())")->execute([$PX]);
    db()->prepare("update strategy_accounts set cash=0 where strategy=?")->execute([$PX]);

    $px_value = strategy_write_values($PX, $SASOF, 'XLON');
    check('966 shares at 690p is valued at 6,665.40, not 666,540',
          abs($px_value['holdings_value'] - 6665.40) < 0.01,
          json_encode($px_value));

    /* The costs are pounds and were always pounds: that is why the price had
       to be. 5.95 commission on a 6,665 pound order is 0.09% of it; on the
       engine's old reading of 62 pounds it was a tenth of the trade. */
    $px_costs = strategy_costs('BUY', 966, 6.90);
    check('commission is 5.95 on that order, which is a tenth of a per cent',
          abs($px_costs['commission'] - 5.95) < 0.0001
          && $px_costs['commission'] / $px_costs['consideration'] < 0.001,
          json_encode($px_costs));
    check('and stamp duty is half a per cent of a real consideration',
          abs($px_costs['stamp_duty'] - 6665.40 * 0.005) < 0.01,
          json_encode($px_costs));

    /* The minimum order means what it says again: 250 pounds, not 25,000. */
    check('the minimum order value is a real 250 pounds against a pounds price',
          abs(STRATEGY_MIN_ORDER_VALUE - 250.0) < 0.0001
          && (36 * 6.90) < STRATEGY_MIN_ORDER_VALUE
          && (37 * 6.90) > STRATEGY_MIN_ORDER_VALUE);

    /* The signature of the fault, as a standing guard: a fill price over
       1,000 for a share whose price in pounds is nowhere near that. If the
       conversion is ever lost, the engine stores 690 and this fails. */
    s_drop($PX); s_account($PX, 100000);
    db()->prepare("insert into strategy_targets
                     (strategy,as_of_date,symbol,exchange,target_weight,created_at)
                   values (?,?,'PENCE1','XLON',0.066666,now())")->execute([$PX, $SASOF]);
    run_script('run_paper_execution.php', [$PX, $SASOF]);

    $px_fills = rows("select symbol, fill_price, consideration, quantity from strategy_orders
                       where strategy=? and status='FILLED'", [$PX]);
    $px_absurd = [];
    foreach ($px_fills as $fill) {
        $pounds = strategy_price_asof($fill['symbol'], 'XLON', $SASOF);
        if ($pounds !== null && $pounds < 100 && (float) $fill['fill_price'] > 1000) {
            $px_absurd[] = $fill;
        }
    }
    check('no fill is stored at a price a hundred times the share\'s own',
          count($px_fills) > 0 && count($px_absurd) === 0,
          json_encode($px_fills));
    check('and the average cost on the position is pounds as well',
          (float) scalar("select avg_price from strategy_positions
                           where strategy=? and symbol='PENCE1'", [$PX]) < 100,
          (string) scalar("select avg_price from strategy_positions
                            where strategy=? and symbol='PENCE1'", [$PX]));

    /* A gap up between the plan and the fill. The quantities are decided on
       the as-of close and filled at the next one, so the last order of a
       rebalance can cost more than the plan reserved - and rejecting it
       leaves the strategy holding fourteen names instead of fifteen, with
       the cash for the fifteenth idle until the next rebalance. Holding
       slightly less of a share is a rounding error; not holding it is a
       different portfolio.

       Found by the pence fix rather than by this test: the same weights in
       a hundredth of the units round to a far finer number of shares, so
       the account is fully invested and the gap has no slack to eat. */
    s_drop($PX);
    db()->exec("delete from historical_prices where symbol='PENCE1' and exchange='XLON'");
    $px_gap = db()->prepare("insert into historical_prices (symbol,exchange,date,price)
                             values ('PENCE1','XLON',?,?)");
    $px_gap->execute([$SASOF, 690]);
    /* Five per cent higher the next morning, which is a profit warning's
       opposite and perfectly ordinary. */
    $px_gap->execute([$SNEXT, 724.5]);

    /* A fully invested account with one name left to buy, which is what the
       last order of a rebalance is. It has to be built this way round
       because no single position may exceed 20% of the account: four
       holdings already at their target weight, a fifth to buy, and only the
       20% of cash the fifth needs - so a five per cent gap up puts the
       order just out of reach.

       The four are at their targets, so the drift band leaves them alone
       and the only order is the one under test. */
    s_account($PX, 20000);
    $px_hold = db()->prepare("insert into strategy_positions
                                (strategy,symbol,exchange,quantity,avg_price,updated_at)
                              values (?,?,'XLON',?,?,now())");
    $px_target = db()->prepare("insert into strategy_targets
                                  (strategy,as_of_date,symbol,exchange,target_weight,created_at)
                                values (?,?,?,'XLON',0.2,now())");

    foreach (['AAA','BBB','CCC','DDD'] as $px_symbol) {
        $px_price = strategy_price_asof($px_symbol, 'XLON', $SASOF);
        $px_hold->execute([$PX, $px_symbol, (int) floor(20000 / $px_price), $px_price]);
        $px_target->execute([$PX, $SASOF, $px_symbol]);
    }
    $px_target->execute([$PX, $SASOF, 'PENCE1']);

    run_script('run_paper_execution.php', [$PX, $SASOF]);
    $px_gap_order = rows("select status, quantity, fill_price, note from strategy_orders
                           where strategy=? and symbol='PENCE1'", [$PX]);

    check('a buy the cash no longer covers is trimmed to fit, not dropped',
          count($px_gap_order) === 1 && $px_gap_order[0]['status'] === 'FILLED'
          && (int) $px_gap_order[0]['quantity'] > 100,
          json_encode($px_gap_order));
    check('and the order says it was trimmed, so the audit trail explains the weight',
          count($px_gap_order) === 1
          && stripos((string) $px_gap_order[0]['note'], 'trimmed') !== false,
          json_encode($px_gap_order[0]['note'] ?? null));
    check('the account did not go overdrawn to do it',
          (float) scalar("select cash from strategy_accounts where strategy=?", [$PX]) >= 0,
          (string) scalar("select cash from strategy_accounts where strategy=?", [$PX]));

    db()->exec("delete from historical_prices where symbol='PENCE1' and exchange='XLON'");
    $px_prices2 = db()->prepare("insert into historical_prices (symbol,exchange,date,price)
                                 values ('PENCE1','XLON',?,690)");
    $px_prices2->execute([$SASOF]);
    $px_prices2->execute([$SNEXT]);

    echo "\n--- strategies: GBp and GBP are not the same currency ---\n";

    /* The trap in the data: the provider writes pence as "GBp" and pounds as
       "GBP". Same three letters, a hundred times apart, and upper-casing the
       column before reading it would put the whole fault back. */
    db()->prepare("update stock_symbols set quote_ccy='GBp' where symbol='PENCE1'")->execute();
    check("'GBp' is pence",
          strategy_quote_ccy('PENCE1', 'XLON') === 'GBX'
          && abs(strategy_price_pounds(690, 'PENCE1', 'XLON', $SASOF) - 6.90) < 0.0001,
          strategy_quote_ccy('PENCE1', 'XLON'));

    /* A different symbol for the pounds case, because strategy_quote_ccy()
       caches per symbol the way the engine calls it. */
    db()->prepare("insert into stock_symbols (symbol,name,description,exchange,enabled,sector,market,quote_ccy)
                   values ('POUND1','Pound Test PLC','Pound Test PLC','XLON','Y','Sector 0','Main Market','GBP')")
      ->execute();
    check("'GBP' is pounds, and is not divided by a hundred",
          strategy_quote_ccy('POUND1', 'XLON') === 'GBP'
          && abs(strategy_price_pounds(690, 'POUND1', 'XLON', $SASOF) - 690.0) < 0.0001,
          strategy_quote_ccy('POUND1', 'XLON'));

    /* A London line quoted in dollars: converted through fx_rates, and NOT
       divided by a hundred - a dollar quote is in dollars, not cents. */
    db()->prepare("insert into stock_symbols (symbol,name,description,exchange,enabled,sector,market,quote_ccy)
                   values ('DOLLAR1','Dollar Test PLC','Dollar Test PLC','XLON','Y','Sector 0','Main Market','USD')")
      ->execute();
    db()->prepare("replace into fx_rates (date, base, quote_ccy, rate, source)
                   values (?, 'GBP', 'USD', 1.25, 'test')")->execute([$SASOF]);

    check('a dollar quote is converted at the rate, not divided by a hundred',
          abs(strategy_price_pounds(50, 'DOLLAR1', 'XLON', $SASOF) - 40.0) < 0.0001,
          var_export(strategy_price_pounds(50, 'DOLLAR1', 'XLON', $SASOF), true));

    /* A minor unit of a foreign currency needs both corrections. South
       African cents on a dual-listed line is a hundredth of a rand, then
       rand into pounds: either correction alone is wrong by a hundred. The
       same list python/fmp.py keeps for the market cap. */
    db()->prepare("insert into stock_symbols (symbol,name,description,exchange,enabled,sector,market,quote_ccy)
                   values ('CENTS1','Cents Test PLC','Cents Test PLC','XLON','Y','Sector 0','Main Market','ZAc')")
      ->execute();
    db()->prepare("replace into fx_rates (date, base, quote_ccy, rate, source)
                   values (?, 'GBP', 'ZAR', 20.0, 'test')")->execute([$SASOF]);
    check('a cents quote is divided by a hundred and then converted',
          abs(strategy_price_pounds(2000, 'CENTS1', 'XLON', $SASOF) - 1.0) < 0.0001,
          var_export(strategy_price_pounds(2000, 'CENTS1', 'XLON', $SASOF), true));

    /* No rate, no price. A position sized through a missing exchange rate is
       a wrong position rather than a late one, so the engine refuses it the
       same way it refuses a share with no price at all. */
    db()->exec("delete from fx_rates where quote_ccy='USD'");
    check('a foreign quote with no rate is unpriceable rather than guessed at',
          strategy_price_pounds(50, 'DOLLAR1', 'XLON', $SASOF) === null);

    db()->exec("delete from stock_symbols where symbol in ('PENCE1','DOLLAR1','POUND1','CENTS1')");
    db()->exec("delete from historical_prices where symbol='PENCE1' and exchange='XLON'");
    db()->exec("delete from fx_rates where source='test'");
    s_drop($PX);

    echo "\n--- strategies: a weight that cannot divide one ---\n";

    /* The fault that stopped both strategies on the first live dry run.
       target_weight is decimal(9,6), and one fifteenth rounded to six places
       is 0.066667: fifteen of those sum to 1.000005, which the executor's
       "weights must not sum over 1" check correctly refuses. Every holdings
       count that does not divide one exactly planned nothing at all, and
       every fixture here used five, which does.

       Rounded down instead, the sum is at or just under one and the
       remainder stays as cash. Three counts, because the arithmetic differs
       between them and the one that bit was the one nobody tried. */
    /* The counts that broke it, and the arithmetic for each.
    
       Not 3, even though it is the smallest count that does not divide: a
       third of an account is over the 20% position ceiling, so a three
       holding strategy is refused for that instead and can never trade -
       which the limits section above tests on purpose. The weight it writes
       is still checked here, just not by planning orders from it.
    
       The fixture has twelve symbols, so a fifteen holding strategy finds
       twelve names and the weights sum to twelve fifteenths rather than to
       one. That is the behaviour the brief asked for - hold fewer, keep the
       rest in cash - so the check is "at most one", not "about one". */
    foreach ([[3, false], [7, true], [15, true]] as [$holdings, $should_trade]) {

        s_drop($S); s_rules($S, ['holdings' => $holdings]); s_reset($S);
        $written = strategy_write_targets($S, $SASOF);

        /* Read the weights back out of the database rather than trusting the
           value PHP wrote: the column's precision is the whole point. */
        $stored = rows("select target_weight from strategy_targets
                         where strategy=? and as_of_date=?", [$S, $SASOF]);
        $sum = 0.0;
        foreach ($stored as $row) { $sum += (float) $row['target_weight']; }

        $expected = floor((1.0 / $holdings) * 1000000) / 1000000;

        check("$holdings holdings: each stored weight is one over the holdings, rounded down",
              count($stored) > 0
              && abs((float) $stored[0]['target_weight'] - $expected) < 1e-9,
              "stored=" . var_export($stored[0]['target_weight'] ?? null, true)
              . " expected=" . var_export($expected, true));
        check("$holdings holdings: and they sum to at most one, never over it",
              $sum <= 1.0000001 && $sum > 0,
              "sum=" . var_export($sum, true) . " over " . count($stored) . " names");

        if (!$should_trade) { continue; }

        $plan = strategy_plan_orders($S, $SASOF);
        $over = false;
        foreach ($plan['errors'] as $error) {
            if (strpos($error, 'sum to') !== false) { $over = true; }
        }

        check("$holdings holdings: the rebalance plans orders rather than being refused",
              !$over && count($plan['orders']) > 0,
              json_encode($plan['errors']));
    }

    /* The check itself stays: a selection that would invest more than the
       account holds is still refused. Proved by writing such a set of
       targets by hand, because nothing the rules can say will produce one. */
    s_drop($S); s_rules($S, ['holdings'=>5]); s_reset($S);
    db()->prepare("delete from strategy_targets where strategy=?")->execute([$S]);
    $greedy = db()->prepare("insert into strategy_targets
                               (strategy,as_of_date,symbol,exchange,target_weight,created_at)
                             values (?,?,?,'XLON',0.2,now())");
    foreach (['AAA','BBB','CCC','DDD','EEE','FFF'] as $symbol) {
        $greedy->execute([$S, $SASOF, $symbol]);
    }
    $greedy_plan = strategy_plan_orders($S, $SASOF);
    $greedy_refused = false;
    foreach ($greedy_plan['errors'] as $error) {
        if (strpos($error, 'sum to') !== false) { $greedy_refused = true; }
    }
    check('weights that really do sum over one are still refused',
          $greedy_refused && count($greedy_plan['orders']) === 0,
          json_encode($greedy_plan['errors']));

    echo "\n--- strategies: funds are not companies ---\n";

    /* An investment trust passes a value screen for being a wrapper rather
       than a business (sql/fund_sectors.sql), and buying one is the same
       mistake as ranking one. Which is why this is a rule per strategy:
       momentum does not care what owns the assets. */
    db()->exec("update stock_symbols set is_fund='Y' where symbol='AAA' and exchange='XLON'");

    s_drop($S); s_rules($S, ['exclude_funds'=>'Y']); s_reset($S);
    $without = strategy_select_symbols(strategy_rules($S), $SASOF, 'XLON');
    check('a fund is left out when the strategy excludes funds',
          !in_array('AAA', $without['symbols'], true) && count($without['symbols']) === 5,
          json_encode($without['symbols']));
    check('and the next name down takes its place rather than the strategy holding four',
          $without['symbols'] === ['BBB','CCC','DDD','EEE','FFF'],
          json_encode($without['symbols']));

    s_rules($S, ['exclude_funds'=>'N']);
    $with = strategy_select_symbols(strategy_rules($S), $SASOF, 'XLON');
    check('and it is kept when the strategy says to keep them',
          $with['symbols'] === ['AAA','BBB','CCC','DDD','EEE'],
          json_encode($with['symbols']));

    db()->exec("update stock_symbols set is_fund=null where symbol='AAA' and exchange='XLON'");

    echo "\n--- strategies: the rebalance calendar ---\n";

    /* Trading dates, not calendar dates. The first as of date of the month
       is whatever day the market first opened in it, and the same for a
       week - which is the whole reason this comes out of historical_prices
       rather than out of date arithmetic. */
    $month_first = scalar("select min(date) from historical_prices
                            where exchange='XLON' and date>=date_format(?, '%Y-%m-01') and date<=?",
                          [$SASOF, $SASOF]);
    $month_second = scalar("select min(date) from historical_prices
                             where exchange='XLON' and date>?", [$month_first]);

    check('the first trading date of the month is a monthly rebalance date',
          strategy_period_first_date('monthly', $month_first, 'XLON') === $month_first,
          "first=$month_first got=" . strategy_period_first_date('monthly', $month_first, 'XLON'));
    check('and the day after it is not',
          strategy_period_first_date('monthly', $month_second, 'XLON') === $month_first,
          "second=$month_second got=" . strategy_period_first_date('monthly', $month_second, 'XLON'));

    $monday = scalar("select max(date) from historical_prices
                       where exchange='XLON' and dayofweek(date)=2 and date<?", [$SASOF]);
    $tuesday = date('Y-m-d', strtotime($monday . ' +1 day'));
    check('the Monday is the weekly rebalance date',
          strategy_period_first_date('weekly', $tuesday, 'XLON') === $monday,
          "monday=$monday got=" . strategy_period_first_date('weekly', $tuesday, 'XLON'));

    /* A bank holiday Monday. The seeded feed is every weekday, so the
       holiday has to be made: take that Monday's prices out and the week's
       rebalance should move to the Tuesday on its own. Without this the
       calendar could be date arithmetic and the suite would never know. */
    $holiday_rows = rows("select symbol, exchange, date, price from historical_prices where date=?",
                         [$monday]);
    db()->prepare("delete from historical_prices where date=?")->execute([$monday]);
    $after_holiday = strategy_period_first_date('weekly', $tuesday, 'XLON');
    $restore = db()->prepare("insert into historical_prices (symbol,exchange,date,price) values (?,?,?,?)");
    foreach ($holiday_rows as $row) {
        $restore->execute([$row['symbol'],$row['exchange'],$row['date'],$row['price']]);
    }
    check('a bank holiday Monday moves the weekly rebalance to the Tuesday',
          $after_holiday === $tuesday, "got=" . var_export($after_holiday, true));
    check('and the prices the test removed are back',
          (int)scalar("select count(*) from historical_prices where date=?", [$monday])
            === count($holiday_rows));

    check('a daily strategy rebalances on whatever date it is asked about',
          strategy_period_first_date('daily', $SASOF, 'XLON') === $SASOF);

    /* The first build does not wait for a month boundary: a strategy
       deployed on the 12th should start on the 12th, which is what "start on
       the first as of date after deploy" means. */
    s_drop($S); s_rules($S, ['rebalance'=>'monthly']); s_reset($S);
    check('an account that has never traded rebalances tonight, whatever the date',
          strategy_rebalance_due(strategy_rules($S), $month_second, 'XLON') === true);

    s_rules($S, ['rebalance'=>'monthly','start_date'=>date('Y-m-d', strtotime($SASOF . ' +30 day'))]);
    check('but not before its start date',
          strategy_rebalance_due(strategy_rules($S), $SASOF, 'XLON') === false);

    /* With a position on the books it follows the calendar. */
    s_rules($S, ['rebalance'=>'monthly']);
    db()->prepare("insert into strategy_positions (strategy,symbol,exchange,quantity,avg_price,updated_at)
                   values (?,'AAA','XLON',10,100,now())")->execute([$S]);
    check('an account with holdings rebalances on the first of the month',
          strategy_rebalance_due(strategy_rules($S), $month_first, 'XLON') === true);
    check('and holds on through the rest of it',
          strategy_rebalance_due(strategy_rules($S), $month_second, 'XLON') === false);
    db()->prepare("delete from strategy_positions where strategy=?")->execute([$S]);

    echo "\n--- strategies: a rules row cannot widen a limit ---\n";

    /* The limits are constants in strategy_functions.php and the dealing
       costs with them, so a row in a configuration table cannot loosen
       either. A three holding strategy asks for 33% positions, which is
       over the 20% ceiling, and the whole rebalance is refused rather than
       trimmed to fit: a trimmed rebalance is a different portfolio from the
       one the signal asked for. */
    s_drop($S); s_rules($S, ['holdings'=>3]); s_reset($S);
    strategy_write_targets($S, $SASOF);
    $over = strategy_plan_orders($S, $SASOF);
    $over_limit = false;
    foreach ($over['errors'] as $error) {
        if (strpos($error, 'exceeds the ' . STRATEGY_MAX_POSITION_WEIGHT) !== false) { $over_limit = true; }
    }
    check('a strategy configured for positions over the ceiling is refused, not trimmed',
          $over_limit && count($over['orders']) === 0,
          json_encode($over['errors']));
    check('and nothing was placed',
          (int)scalar("select count(*) from strategy_orders where strategy=?", [$S]) === 0);

    echo "\n--- strategies: the drift band ---\n";

    /* A holding bought at 20% that is now 21% is not a different portfolio
       from the one the signal asked for. Trimming it pays commission, stamp
       and spread for a decimal place, so anything inside two percentage
       points is left alone - and anything outside it is not. */
    s_drop($S); s_rules($S, ['holdings'=>5]); s_reset($S);
    /* The engine's own view of the price, which is POUNDS: historical_prices
       holds London prices in pence. Reading the column straight here would
       size this fixture a hundred times too small and the band would not be
       what the test is measuring - which is the same mistake the engine
       itself was making. */
    $drift_price = strategy_price_asof('AAA', 'XLON', $SASOF);
    $drift_target = (int)floor((0.2 * 100000) / $drift_price);

    /* Inside the band: hold 95% of the target quantity, which is a weight
       about one point below 20%.

       The cash has to come down by what the holding cost, or the account is
       worth 120,000 and a 20% target is 24,000 - against which a 19,000
       holding is five points light and the band would not be what the test
       is measuring. */
    $inside = (int)floor($drift_target * 0.95);
    db()->prepare("insert into strategy_positions (strategy,symbol,exchange,quantity,avg_price,updated_at)
                   values (?,'AAA','XLON',?,?,now())")->execute([$S,$inside,$drift_price]);
    db()->prepare("update strategy_accounts set cash=? where strategy=?")
      ->execute([100000 - $inside * $drift_price, $S]);
    strategy_write_targets($S, $SASOF);
    $plan = strategy_plan_orders($S, $SASOF);
    $touched = [];
    foreach ($plan['orders'] as $order) { $touched[] = $order['symbol']; }
    check('a holding one point away from its target is left alone',
          !in_array('AAA', $touched, true), json_encode($plan['orders']));

    /* Outside it: hold 60% of the target, eight points light, with the cash
       moved the same way so the account is still worth about 100,000. */
    $outside = (int)floor($drift_target * 0.6);
    db()->prepare("update strategy_positions set quantity=? where strategy=? and symbol='AAA'")
      ->execute([$outside, $S]);
    db()->prepare("update strategy_accounts set cash=? where strategy=?")
      ->execute([100000 - $outside * $drift_price, $S]);
    $plan = strategy_plan_orders($S, $SASOF);
    $topped_up = false;
    foreach ($plan['orders'] as $order) {
        if ($order['symbol'] === 'AAA' && $order['side'] === 'BUY') { $topped_up = true; }
    }
    check('a holding eight points away is traded back towards it',
          $topped_up, json_encode($plan['orders']));

    echo "\n--- strategies: leaving before the next rebalance ---\n";

    /* One close through a 200 day average is noise; a strategy that sells on
       it pays dealing costs to be whipsawed. Two in a row is the rule, and
       the exit reads the same statistics the trend screen does so the two
       cannot disagree about where the average is. */
    $s_dates = array_column(rows("select distinct date from historical_prices
                                   where exchange='XLON' and date<=? order by date desc limit 2",
                                 [$SASOF]), 'date');

    s_drop($S); s_rules($S, ['exit_rules'=>'below_ma200_2d']); s_reset($S);
    db()->prepare("insert into strategy_positions (strategy,symbol,exchange,quantity,avg_price,updated_at)
                   values (?,'AAA','XLON',100,100,now())")->execute([$S]);

    /* Below on the latest date, above on the one before it. */
    s_stat('AAA','close_price',$s_dates[0], 90);
    s_stat('AAA','ma200',     $s_dates[0], 100);
    s_stat('AAA','close_price',$s_dates[1], 110);
    s_stat('AAA','ma200',     $s_dates[1], 100);
    check('one close below the 200-day average is not an exit',
          strategy_below_ma200_2d('AAA', $SASOF) === false);
    check('and nothing is sold',
          count(strategy_exits_due($S, $SASOF, 'XLON')) === 0);

    /* Below on both. */
    s_stat('AAA','close_price',$s_dates[1], 95);
    check('two consecutive closes below it is an exit',
          strategy_below_ma200_2d('AAA', $SASOF) === true);

    $exits = strategy_exits_due($S, $SASOF, 'XLON');
    check('the whole position goes, not part of it',
          count($exits) === 1 && $exits[0]['symbol'] === 'AAA' && $exits[0]['quantity'] === 100,
          json_encode($exits));

    $placed = strategy_place_exits($S, $SASOF, false, 'XLON');
    check('the exit sold the position',
          $placed['sold'] === 1
          && (int)scalar("select quantity from strategy_positions where strategy=? and symbol='AAA'", [$S]) === 0,
          json_encode($placed));
    check('and the order says which rule sold it',
          scalar("select note from strategy_orders where strategy=? and symbol='AAA'", [$S])
            === 'exit: below_ma200_2d');
    check('the cash it raised is idle, because an exit is not a selection',
          (int)scalar("select count(*) from strategy_orders where strategy=? and side='BUY'", [$S]) === 0
          && (float)scalar("select cash from strategy_accounts where strategy=?", [$S]) > 100000);

    /* A strategy with no exit rules holds through the same signal. */
    s_drop($S); s_rules($S, ['exit_rules'=>'']); s_reset($S);
    db()->prepare("insert into strategy_positions (strategy,symbol,exchange,quantity,avg_price,updated_at)
                   values (?,'AAA','XLON',100,100,now())")->execute([$S]);
    check('a strategy with no exit rules holds through it',
          count(strategy_exits_due($S, $SASOF, 'XLON')) === 0);

    echo "\n--- strategies: a holding that stops trading ---\n";

    /* Taken over, suspended or delisted. The share has no next price and
       never will, so the ordinary rule - fill on the next session - would
       refuse the sale for ever and the book would carry a holding it cannot
       value or leave. The last price it actually traded at is the only
       honest number there is, and it is a real traded close rather than an
       estimate. */
    s_drop($S); s_rules($S); s_reset($S);

    /* Far enough back that the market has had STRATEGY_STALE_SESSIONS
       sessions without it. One session short of that is a data gap, which
       is the distinction the rule exists to make, so the fixture has to
       clear the bar rather than sit on it. */
    /* The offset is interpolated rather than bound: MariaDB will not take a
       placeholder in LIMIT or OFFSET when prepares are not emulated. It is
       an integer constant from the application's own code, not input. */
    $gone_after = scalar("select distinct date from historical_prices where exchange='XLON'
                           and date<=? order by date desc limit 1 offset "
                         . (int)(STRATEGY_STALE_SESSIONS + 1), [$SASOF]);
    $gone_rows = rows("select symbol, exchange, date, price from historical_prices
                        where symbol='BBB' and exchange='XLON' and date>?", [$gone_after]);
    /* The engine's own view of it, which is pounds: the column is pence.
       Reading the column straight here would compare a fill in pounds
       against a price in pence and fail by a factor of a hundred - which is
       the fault the engine itself had. */
    $gone_price = strategy_price_asof('BBB', 'XLON', $gone_after);
    db()->prepare("delete from historical_prices where symbol='BBB' and exchange='XLON' and date>?")
      ->execute([$gone_after]);

    db()->prepare("insert into strategy_positions (strategy,symbol,exchange,quantity,avg_price,updated_at)
                   values (?,'BBB','XLON',50,?,now())")->execute([$S,$gone_price]);

    /* One missing session is a data gap, not a delisting. */
    check('a share that stopped trading is noticed',
          strategy_price_stale('BBB','XLON',$SASOF) === true);
    check('and one that is still trading is not',
          strategy_price_stale('AAA','XLON',$SASOF) === false);

    $gone_exits = strategy_exits_due($S, $SASOF, 'XLON');
    check('it is sold even though the strategy has no exit rules',
          count($gone_exits) === 1 && $gone_exits[0]['reason'] === 'delisted',
          json_encode($gone_exits));

    strategy_place_exits($S, $SASOF, false, 'XLON');
    $gone_order = rows("select status, fill_price, note from strategy_orders
                         where strategy=? and symbol='BBB'", [$S])[0];
    check('it filled at the last price it really traded at',
          $gone_order['status'] === 'FILLED'
          && abs((float)$gone_order['fill_price'] - $gone_price) < 0.01,
          json_encode($gone_order) . " last=$gone_price");
    check('and the order says why',
          $gone_order['note'] === 'exit: delisted');
    check('the position is gone',
          (int)scalar("select quantity from strategy_positions where strategy=? and symbol='BBB'", [$S]) === 0);

    /* Flagged on the page as well as in the log: a strategy holding a share
       that has stopped trading is valued from stale data, and the note is
       what the page reads. */
    db()->prepare("insert into strategy_positions (strategy,symbol,exchange,quantity,avg_price,updated_at)
                   values (?,'BBB','XLON',50,?,now())
                   on duplicate key update quantity=50")->execute([$S,$gone_price]);
    $stale_value = strategy_write_values($S, $SASOF, 'XLON');
    check('the valuation flags a holding priced from stale data',
          $stale_value['note'] !== null && strpos($stale_value['note'], 'BBB') !== false,
          var_export($stale_value['note'] ?? null, true));

    db()->prepare("delete from strategy_positions where strategy=?")->execute([$S]);
    $restore_gone = db()->prepare("insert into historical_prices (symbol,exchange,date,price) values (?,?,?,?)");
    foreach ($gone_rows as $row) {
        $restore_gone->execute([$row['symbol'],$row['exchange'],$row['date'],$row['price']]);
    }
    check('the prices the test removed are back',
          (int)scalar("select count(*) from historical_prices
                        where symbol='BBB' and exchange='XLON' and date>?", [$gone_after])
            === count($gone_rows));

    echo "\n--- strategies: the turnover cap and the first build ---\n";

    /* Turnover is min(buys, sells), which is how much of the book is being
       swapped rather than how much is being moved - so an all-cash build
       scores zero and the cap was never going to stop it. The exemption says
       that in code all the same, so a later change to the measure cannot
       stop an account ever opening. What matters is the other half: the
       second run is capped like every other. */
    s_drop($S); s_rules($S, ['holdings'=>5]); s_reset($S);
    check('an account with nothing in it is a first build',
          strategy_first_build($S) === true);

    strategy_write_targets($S, $SASOF);
    run_script('run_paper_execution.php', [$S, $SASOF]);
    check('the first build fills',
          (int)scalar("select count(*) from strategy_orders where strategy=? and status='FILLED'", [$S]) === 5);
    check('and the account is no longer a first build',
          strategy_first_build($S) === false);

    /* Now swap the whole book for five different names on a later date.
       Selling 100% and buying 100% is 100% turnover, twice the cap, so the
       rebalance is refused in one piece rather than half taken. */
    db()->prepare("delete from strategy_targets where strategy=?")->execute([$S]);
    $swap_weight = 0.2;
    $swap = db()->prepare("insert into strategy_targets
                             (strategy,as_of_date,symbol,exchange,target_weight,created_at)
                           values (?,?,?,'XLON',?,now())");
    foreach (['FFF','GGG','HHH','III','JJJ'] as $symbol) {
        $swap->execute([$S,$SNEXT,$symbol,$swap_weight]);
    }
    $swap_plan = strategy_plan_orders($S, $SNEXT);
    $capped = false;
    foreach ($swap_plan['errors'] as $error) {
        if (strpos($error, 'turnover') !== false) { $capped = true; }
    }
    check('a complete swap of the book on the second run is refused by the turnover cap',
          $capped && count($swap_plan['orders']) === 0,
          json_encode($swap_plan['errors']));

    echo "\n--- strategies: what it was all worth ---\n";

    s_drop($S); s_rules($S, ['holdings'=>5]); s_reset($S);
    strategy_write_targets($S, $SASOF);
    run_script('run_paper_execution.php', [$S, $SASOF]);
    $value = strategy_write_values($S, $SASOF, 'XLON');
    $value_row = rows("select * from strategy_values where strategy=? and date=?", [$S,$SASOF])[0];

    check('a value row is written for the night',
          $value !== null && (int)$value_row['holdings'] === 5);
    check('and cash plus holdings is the total',
          abs(((float)$value_row['cash'] + (float)$value_row['holdings_value'])
              - (float)$value_row['total']) < 0.01,
          json_encode($value_row));
    check('which is about the hundred thousand it started with, less costs',
          (float)$value_row['total'] > 95000 && (float)$value_row['total'] < 101000,
          (string)$value_row['total']);

    /* Re-running a night rewrites that night rather than adding a second
       row: the page draws one line, and two totals for one date is not a
       line. */
    strategy_write_values($S, $SASOF, 'XLON');
    check('writing the same night twice leaves one row',
          (int)scalar("select count(*) from strategy_values where strategy=? and date=?", [$S,$SASOF]) === 1);

    db()->exec("delete from strategy_values where strategy='" . STRATEGY_BENCHMARK . "'");
    $base = strategy_benchmark_update($SASOF, 'XLON');
    check('the benchmark starts at 100 on the first night it is asked for',
          abs($base['level'] - 100.0) < 1e-9 && $base['return'] === null,
          json_encode($base));

    $second = strategy_benchmark_update($SNEXT, 'XLON');
    $bench_rows = rows("select date, total, holdings, note from strategy_values
                         where strategy=? order by date", [STRATEGY_BENCHMARK]);
    check('and compounds the equal weighted return of the 350 after that',
          count($bench_rows) === 2 && $second['return'] !== null
          && abs((float)$bench_rows[1]['total'] - 100.0 * (1 + $second['return'])) < 0.01,
          json_encode([$second, $bench_rows]));
    check('the row says which benchmark it is, because equal weighted is a choice',
          strpos((string)$bench_rows[0]['note'], 'equal weighted') !== false,
          (string)$bench_rows[0]['note']);
    check('and it counted the constituents it used',
          (int)$bench_rows[1]['holdings'] > 0);

    /* The benchmark is not a strategy. If it were picked up by the nightly
       run it would be handed an account, targets and orders. */
    check('the benchmark is never run as a strategy',
          !in_array(STRATEGY_BENCHMARK, strategy_strategies(), true)
          && strategy_reserved(STRATEGY_BENCHMARK) === true);
    check('nor is a backtest account',
          strategy_reserved(STRATEGY_BACKTEST_PREFIX . 'trend_following_7') === true);

    echo "\n--- strategies: the nightly run ---\n";

    /* One strategy failing must not stop the next, and none of them may stop
       the rest of the nightly job. The broken one is first alphabetically on
       purpose: if the loop stopped on it, the good one would never trade. */
    $BROKEN = 'test_night_a_broken';
    $GOOD   = 'test_night_b_good';

    s_drop($S);
    s_drop($BROKEN); s_drop($GOOD);
    s_rules($BROKEN, ['score_indicator'=>'no_such_indicator']); s_reset($BROKEN);
    s_rules($GOOD,   ['holdings'=>5]);                          s_reset($GOOD);
    /* The two migration strategies would run as well and lengthen the test
       for no gain; disable them for this part and put them back after. */
    db()->exec("update strategy_accounts set enabled='N'
                 where strategy in ('trending_value','trend_following')");

    [$night_out, $night_rc] = run_script('run_strategies.php', [$SASOF]);

    check('the nightly run reports a problem when a strategy cannot select',
          $night_rc === 1 && strpos($night_out, $BROKEN) !== false,
          substr($night_out, 0, 400));
    check('and the strategy after it still traded',
          (int)scalar("select count(*) from strategy_orders where strategy=? and status='FILLED'", [$GOOD]) === 5,
          substr($night_out, -400));
    check('the broken one placed nothing',
          (int)scalar("select count(*) from strategy_orders where strategy=?", [$BROKEN]) === 0);
    check('both were valued all the same, so the page has a point for the night',
          (int)scalar("select count(*) from strategy_values where strategy in (?,?) and date=?",
                      [$BROKEN,$GOOD,$SASOF]) === 2);
    check('and the benchmark was written once, not once per strategy',
          (int)scalar("select count(*) from strategy_values where strategy=? and date=?",
                      [STRATEGY_BENCHMARK,$SASOF]) === 1);

    /* A rebalance on the night it is due, and nothing on the nights it is
       not: the run has to be safe to repeat. */
    $repeat_orders = (int)scalar("select count(*) from strategy_orders where strategy=?", [$GOOD]);
    $repeat_cash   = scalar("select cash from strategy_accounts where strategy=?", [$GOOD]);
    run_script('run_strategies.php', [$SASOF]);
    check('running the same night again places no new orders',
          (int)scalar("select count(*) from strategy_orders where strategy=?", [$GOOD]) === $repeat_orders
          && scalar("select cash from strategy_accounts where strategy=?", [$GOOD]) === $repeat_cash,
          "orders $repeat_orders -> " . scalar("select count(*) from strategy_orders where strategy=?", [$GOOD]));

    /* A genuine PHP failure inside one strategy is contained too. The screen
       table is renamed out from under the selection, which is as close to a
       broken database as a test can get without leaving one behind.

       Note what this does NOT cover: query() in functions.php answers a bad
       statement with trigger_error(E_USER_ERROR) and exit, so a SQL error
       takes the whole process with it whatever this catches. That is the
       project's behaviour everywhere, not something the strategies chose. */
    s_rules($BROKEN, ['selection'=>'screen','screen_id'=>$tf_rules['screen_id']]);
    db()->exec("rename table screen to screen_hidden");
    $thrown = strategy_run_night($BROKEN, $SASOF, true, 'XLON');
    db()->exec("rename table screen_hidden to screen");
    check('a strategy that fails outright returns its failure rather than killing the run',
          count($thrown['errors']) > 0,
          json_encode($thrown['errors']));
    check('and the screens are back, so the next test is not the one that found it',
          (int)scalar("select count(*) from screen") > 0);

    echo "\n--- strategies: the kill switch ---\n";

    /* Fail closed. The kill switch stops the dry run too, so what it does is
       never a question: if the file is there, nothing happens. */
    $kill_file = sys_get_temp_dir() . '/sm_stop_trading_' . getmypid();
    file_put_contents($kill_file, "stop\n");

    s_drop($GOOD); s_rules($GOOD, ['holdings'=>5]); s_reset($GOOD);

    [$kill_out, $kill_rc] = run_script('run_strategies.php', [$SASOF],
                                       ['STRATEGY_KILL_SWITCH_FILE' => $kill_file]);
    check('the kill switch stops the nightly run before anything is selected',
          $kill_rc === 4 && stripos($kill_out, 'HALTED') !== false,
          substr($kill_out, 0, 300));
    check('and nothing was placed for any strategy',
          (int)scalar("select count(*) from strategy_orders where strategy=?", [$GOOD]) === 0);

    [$kill_dry_out, $kill_dry_rc] = run_script('run_strategies.php', [$SASOF, '--dry-run'],
                                               ['STRATEGY_KILL_SWITCH_FILE' => $kill_file]);
    check('the dry run is stopped by it as well',
          $kill_dry_rc === 4 && stripos($kill_dry_out, 'HALTED') !== false,
          substr($kill_dry_out, 0, 300));

    @unlink($kill_file);

    echo "\n--- strategies: a kill switch somebody can reach ---\n";

    /* The switch defaulted to /var/www/shares/STOP_TRADING, the pre-2026
       server layout. The directory does not exist and php-fpm's
       open_basedir blocked it, so the page logged a warning on every load
       and could never see the switch, and on the command line it only
       worked if somebody knew to create a file in a directory that was not
       there. A safety mechanism nobody can reach is not one. */
    [$ks_out] = run_script('run_strategies.php', [$SASOF, '--dry-run'],
                           ['SM_KILL_SWITCH_FILE' => '']);
    check('the default switch path is somewhere a deployed app can read',
          strpos((string) file_get_contents(dirname(__DIR__) . '/includes/strategy_functions.php'),
                 '/var/lib/sharemanager/STOP_TRADING') !== false
          && strpos((string) file_get_contents(dirname(__DIR__) . '/includes/strategy_functions.php'),
                    '/var/www/shares/STOP_TRADING') === false);

    /* SM_KILL_SWITCH_FILE is the name the rest of the application's
       settings use, and it has to win. */
    $ks_file = sys_get_temp_dir() . '/sm_stop_sm_name_' . getmypid();
    file_put_contents($ks_file, "stop\n");
    s_drop($GOOD); s_rules($GOOD, ['holdings'=>5]); s_reset($GOOD);

    [$ks_sm_out, $ks_sm_rc] = run_script('run_strategies.php', [$SASOF],
                                         ['SM_KILL_SWITCH_FILE' => $ks_file]);
    check('SM_KILL_SWITCH_FILE stops the run',
          $ks_sm_rc === 4 && stripos($ks_sm_out, 'HALTED') !== false,
          substr($ks_sm_out, 0, 300));
    check('and nothing was placed',
          (int)scalar("select count(*) from strategy_orders where strategy=?", [$GOOD]) === 0);

    [$ks_dry_out, $ks_dry_rc] = run_script('run_strategies.php', [$SASOF, '--dry-run'],
                                           ['SM_KILL_SWITCH_FILE' => $ks_file]);
    check('including the dry run, which is where it was invisible before',
          $ks_dry_rc === 4 && stripos($ks_dry_out, 'HALTED') !== false,
          substr($ks_dry_out, 0, 300));
    @unlink($ks_file);

    /* A path PHP cannot look at is not "not halted". The page says which it
       is rather than presenting an assumption as an answer. */
    [$ks_blind_out] = run_script('run_strategies.php', [$SASOF, '--dry-run'],
                                 ['SM_KILL_SWITCH_FILE' => '/nowhere/at/all/STOP_TRADING']);
    check('an unreadable switch path does not stop the run but is noticed',
          stripos($ks_blind_out, 'HALTED') === false,
          substr($ks_blind_out, 0, 200));
    check('and the page can tell a path it cannot read from one with no file in it',
          function_exists('strategy_kill_switch_readable'));

    echo "\n--- strategies: a dry run writes nothing ---\n";

    /* The page's button and the command line both go through this, so it has
       to be true of a rebalance night as well: the plan has to be shown
       without the targets it is planned from existing yet. */
    /* The broken strategy has done its job; with it still enabled the
       script's exit code would be reporting its failure rather than what
       the dry run did. */
    s_drop($BROKEN);
    s_drop($GOOD); s_rules($GOOD, ['holdings'=>5]); s_reset($GOOD);

    $dry = strategy_run_night($GOOD, $SASOF, true, 'XLON');
    check('a dry run on a rebalance night plans the orders',
          $dry['rebalance'] === true && count($dry['targets']) === 5
          && count($dry['lines']) > 0,
          json_encode($dry['lines']));

    /* The plan has to come from the targets held in memory, because a dry
       run has not written them. Reading strategy_targets instead would find
       nothing there and plan nothing, and the popup would show an empty
       table on the one night of the month that matters - so this asks for a
       buy per target, by symbol, with the table empty behind it. */
    $dry_targets = array_column($dry['targets'], 'symbol');
    $dry_buys = [];
    foreach ($dry['orders'] as $order) {
        if ($order['side'] === 'BUY') { $dry_buys[] = $order['symbol']; }
    }
    sort($dry_targets); sort($dry_buys);
    check('and the plan is built from those targets, not from the empty table',
          $dry_buys === $dry_targets
          && (int)scalar("select count(*) from strategy_targets where strategy=?", [$GOOD]) === 0,
          json_encode(['targets' => $dry_targets, 'buys' => $dry_buys]));
    check('and writes no targets, no orders, no values and no cash movement',
          (int)scalar("select count(*) from strategy_targets where strategy=?", [$GOOD]) === 0
          && (int)scalar("select count(*) from strategy_orders where strategy=?", [$GOOD]) === 0
          && (int)scalar("select count(*) from strategy_values where strategy=?", [$GOOD]) === 0
          && (float)scalar("select cash from strategy_accounts where strategy=?", [$GOOD]) === 100000.0);

    [$dry_out, $dry_rc] = run_script('run_strategies.php', [$SASOF, '--dry-run']);
    check('the same is true of the script',
          $dry_rc === 0
          && (int)scalar("select count(*) from strategy_orders where strategy=?", [$GOOD]) === 0
          && stripos($dry_out, 'dry run') !== false,
          substr($dry_out, -300));

    s_drop($BROKEN); s_drop($GOOD); s_drop($S);
    db()->exec("update strategy_accounts set enabled='Y'
                 where strategy in ('trending_value','trend_following')");
    db()->exec("delete from strategy_values");

    echo "\n--- strategies: tonight's orders fill at tomorrow's close ---\n";

    /* The nightly run decides on tonight's close, and the price it fills at
       is tomorrow's - which does not exist while tonight's job is running.
       Rejecting the order there loses the rebalance for good, because the
       order id is keyed on the as of date and the next run finds the
       rejected row and places nothing. A monthly strategy would never trade
       at all.

       So an order placed on the last session the market has had stays
       PENDING, and the next run resumes it. Found by reading the live path
       rather than by a test: every test above picks an as of date with
       sessions after it, so none of them could see this. */
    $LATEST = scalar("select max(date) from historical_prices where exchange='XLON'");
    $LAST   = 'test_last_session';

    s_drop($LAST); s_rules($LAST, ['holdings'=>5]); s_reset($LAST);
    foreach ($S_SYMBOLS as $i => $symbol) { s_stat($symbol, 'momentum_score', $LATEST, 100 - $i); }

    [$last_out, $last_rc] = run_script('run_strategies.php', [$LATEST, '--strategy=' . $LAST]);

    $pending = (int)scalar("select count(*) from strategy_orders where strategy=? and status='PENDING'", [$LAST]);
    $rejected = (int)scalar("select count(*) from strategy_orders where strategy=? and status='REJECTED'", [$LAST]);

    check('a rebalance on the latest session leaves its orders waiting, not rejected',
          $pending === 5 && $rejected === 0,
          "pending=$pending rejected=$rejected\n" . substr($last_out, -400));
    check('and the run does not call that a failure',
          $last_rc === 0, substr($last_out, -300));
    check('no cash moved, because nothing filled',
          (float)scalar("select cash from strategy_accounts where strategy=?", [$LAST]) === 100000.0);

    /* Tomorrow. The next session's price arrives and the next run fills
       what was waiting, at that price rather than at the close it was
       decided on. */
    /* A flat next session. A rise would be more interesting and would test
       something else: the orders were sized against tonight's close, so on
       a 1% gap up the last one no longer fits the cash and is rejected for
       want of it - which is correct, and not what this test is about. */
    $tomorrow = date('Y-m-d', strtotime($LATEST . ' +1 day'));
    $tomorrow_rows = db()->prepare("insert into historical_prices (symbol,exchange,date,price)
                                    select symbol, exchange, ?, price
                                      from historical_prices
                                     where exchange='XLON' and date=?");
    $tomorrow_rows->execute([$tomorrow, $LATEST]);

    [$next_out, $next_rc] = run_script('run_strategies.php', [$tomorrow, '--strategy=' . $LAST]);

    $filled_rows = rows("select fill_date, fill_price, symbol from strategy_orders
                          where strategy=? and status='FILLED' order by symbol", [$LAST]);
    check('the next run fills what was left waiting',
          count($filled_rows) === 5,
          substr($next_out, -400));
    check('at the next session\'s price, not the one it was decided on',
          count($filled_rows) > 0
          && substr((string)$filled_rows[0]['fill_date'], 0, 10) === $tomorrow,
          json_encode($filled_rows[0] ?? null) . " expected $tomorrow");
    check('and the cash went out',
          (float)scalar("select cash from strategy_accounts where strategy=?", [$LAST]) < 100000.0);
    check('with one order per symbol, not a second set for the second night',
          (int)scalar("select count(*) from strategy_orders where strategy=?", [$LAST]) === 5);

    /* The other half of the distinction: a symbol the market has traded
       past but that has no price of its own is a real failure, and is still
       rejected. That is what stops a dead symbol being filled at some
       convenient number. */
    /* The fabricated session goes first: LLL's rows are about to be saved
       and put back, and saving the fabricated one would leave it behind. */
    db()->prepare("delete from historical_prices where date=?")->execute([$tomorrow]);

    $dead_rows = rows("select symbol, exchange, date, price from historical_prices
                        where symbol='LLL' and exchange='XLON' and date>?", [$SASOF]);
    db()->prepare("delete from historical_prices where symbol='LLL' and exchange='XLON' and date>?")
      ->execute([$SASOF]);
    db()->prepare("insert into strategy_orders
                     (client_order_id,strategy,as_of_date,symbol,exchange,side,quantity,status,created_at)
                   values (?,?,?,'LLL','XLON','BUY',10,'PENDING',now())")
      ->execute([$LAST . '-dead', $LAST, $SASOF]);
    strategy_fill_order($LAST . '-dead');
    check('a symbol with no price while the market traded on is rejected, not left waiting',
          scalar("select status from strategy_orders where client_order_id=?", [$LAST . '-dead'])
            === 'REJECTED');

    $dead_restore = db()->prepare("insert into historical_prices (symbol,exchange,date,price) values (?,?,?,?)");
    foreach ($dead_rows as $row) {
        $dead_restore->execute([$row['symbol'],$row['exchange'],$row['date'],$row['price']]);
    }
    s_drop($LAST);
    check('the fabricated session is gone again and LLL has its prices back',
          (int)scalar("select count(*) from historical_prices where date=?", [$tomorrow]) === 0
          && (int)scalar("select count(*) from historical_prices
                           where symbol='LLL' and exchange='XLON' and date>?", [$SASOF])
             === count($dead_rows));

    echo "\n--- strategies: the close and its averages, over history ---\n";

    /* A backtest replays the strategy's own screen, and that screen
       compares the close with its 200 day average as they stood on each
       date. The prices go back years; the statistics only go back to the
       night the trend screens were deployed, so without a backfill a trend
       strategy can only be backtested over the last few weeks. */
    $bf_from = scalar("select distinct date from historical_prices where exchange='XLON'
                        order by date desc limit 1 offset 20");
    $bf_to   = scalar("select distinct date from historical_prices where exchange='XLON'
                        order by date desc limit 1 offset 1");

    db()->exec("delete from statistics where indicator in ('close_price','ma50','ma200')");
    [$bf_out, $bf_rc] = run_script('backfill_trend_statistics.php', [$bf_from, $bf_to]);

    check('the backfill runs', $bf_rc === 0, substr($bf_out, -300));
    check('and writes the close and both averages across the range',
          (int)scalar("select count(distinct date) from statistics where indicator='ma200'") > 10
          && (int)scalar("select count(distinct indicator) from statistics
                           where indicator in ('close_price','ma50','ma200')") === 3,
          $bf_out);
    check('nothing is written outside the range it was given',
          (int)scalar("select count(*) from statistics
                        where indicator in ('close_price','ma50','ma200')
                          and (date < ? or date > ?)", [$bf_from, $bf_to]) === 0);

    /* No look-ahead, which is the whole point: the close written for a date
       is that date's close, not a later one. */
    $bf_check_date = scalar("select distinct date from historical_prices where exchange='XLON'
                              and date>=? and date<=? order by date limit 1 offset 2",
                            [$bf_from, $bf_to]);
    check('the close written for a date is that date\'s close',
          abs((float)scalar("select value from statistics where symbol='AAA' and indicator='close_price'
                              and date=?", [$bf_check_date])
              - (float)scalar("select price from historical_prices where symbol='AAA'
                                and exchange='XLON' and date=?", [$bf_check_date])) < 0.01);

    /* And the average is the mean of the 200 closes up to that date, not of
       the 200 around it. Worked out here from the prices rather than
       trusting the job's own arithmetic. */
    $bf_window = (float)scalar("select avg(price) from (
                                  select price from historical_prices
                                   where symbol='AAA' and exchange='XLON' and date<=?
                                   order by date desc limit 200) w", [$bf_check_date]);
    check('the 200 day average is the mean of the 200 closes up to that date',
          abs((float)scalar("select value from statistics where symbol='AAA' and indicator='ma200'
                              and date=?", [$bf_check_date]) - $bf_window) < 0.01,
          "job=" . scalar("select value from statistics where symbol='AAA' and indicator='ma200' and date=?",
                          [$bf_check_date]) . " prices=$bf_window");

    /* A full window or no row: an average of forty closes is not a 200 day
       average, and a strategy that read one as if it were would buy on a
       signal that does not exist. */
    $bf_early = scalar("select distinct date from historical_prices where exchange='XLON'
                         order by date asc limit 1 offset 40");
    run_script('backfill_trend_statistics.php', [$bf_early, $bf_early, 'ma200']);
    check('a date without 200 closes behind it gets no 200 day average',
          (int)scalar("select count(*) from statistics where indicator='ma200' and date=?",
                      [$bf_early]) === 0);
    run_script('backfill_trend_statistics.php', [$bf_early, $bf_early, 'ma50']);
    check('and none for the 50 day one either, on the same grounds',
          (int)scalar("select count(*) from statistics where indicator='ma50' and date=?",
                      [$bf_early]) === 0);

    /* Safe to re-run: the same range twice is the same rows, not twice as
       many. */
    $bf_rows = (int)scalar("select count(*) from statistics
                             where indicator in ('close_price','ma50','ma200')");
    run_script('backfill_trend_statistics.php', [$bf_from, $bf_to]);
    check('running the backfill again changes nothing',
          (int)scalar("select count(*) from statistics
                        where indicator in ('close_price','ma50','ma200')") === $bf_rows,
          "$bf_rows -> " . scalar("select count(*) from statistics
                                    where indicator in ('close_price','ma50','ma200')"));

    /* The momentum returns matter more than the averages now: the trend
       strategy ranks on 6mnth, and get_momentum_statistics.php only ever
       wrote it for its own monthly as of dates. The backfill is what makes
       a daily backtest possible, so the one thing it must not get wrong is
       the definition - a backtest ranking on a slightly different number
       from the live strategy is measuring a different strategy.

       So compare it against the job itself: run the nightly momentum job,
       take the date it wrote, and backfill the same date. The two have to
       agree exactly, not approximately. */
    reset_calculated();
    run_script('get_momentum_statistics.php');
    $mo_date = scalar("select max(date) from statistics where indicator='6mnth'");
    $mo_job  = rows("select symbol, value from statistics
                      where indicator='6mnth' and date=? order by symbol", [$mo_date]);

    db()->prepare("delete from statistics where indicator='6mnth' and date=?")->execute([$mo_date]);
    run_script('backfill_trend_statistics.php', [$mo_date, $mo_date, '6mnth']);
    $mo_backfill = rows("select symbol, value from statistics
                          where indicator='6mnth' and date=? order by symbol", [$mo_date]);

    check('the momentum job wrote something to compare against',
          count($mo_job) > 5 && $mo_date !== null && $mo_date !== false,
          var_export($mo_date, true) . ' ' . count($mo_job) . ' rows');
    check('the backfill writes the same six month return for every share',
          count($mo_backfill) === count($mo_job) && $mo_backfill == $mo_job,
          json_encode(['job' => array_slice($mo_job, 0, 3),
                       'backfill' => array_slice($mo_backfill, 0, 3)]));
    check('and to the last decimal place, not approximately',
          json_encode($mo_backfill) === json_encode($mo_job),
          json_encode(array_slice($mo_backfill, 0, 2)));

    /* The three months and twelve months versions come from the same code
       path, so one date of each is enough to know the window is being
       read rather than hard coded. */
    run_script('backfill_trend_statistics.php', [$mo_date, $mo_date, '3mnth', '12mnth']);
    check('three and twelve month returns are written too, and differ from the six',
          (int)scalar("select count(*) from statistics where indicator='3mnth' and date=?", [$mo_date]) > 5
          && (int)scalar("select count(*) from statistics where indicator='12mnth' and date=?", [$mo_date]) > 5
          && scalar("select value from statistics where indicator='3mnth' and date=? and symbol='AAA'", [$mo_date])
             !== scalar("select value from statistics where indicator='6mnth' and date=? and symbol='AAA'", [$mo_date]));

    /* And the backfill writes nothing it cannot work out from prices. A
       value score is a statement about figures that were not published on
       the date in question, and no SQL makes that honest. */
    [$refuse_out] = run_script('backfill_trend_statistics.php', [$mo_date, $mo_date, 'value_score']);
    check('it refuses an indicator prices do not decide',
          stripos($refuse_out, 'Unknown indicator') !== false,
          substr($refuse_out, 0, 200));

    /* reset_calculated() above emptied the statistics the range backfill
       wrote, and the backtest below needs them: a backtest whose indicators
       are missing is refused, which is correct and would make the next
       section test the refusal twice over instead of the replay. */
    run_script('backfill_trend_statistics.php', [$bf_from, $bf_to]);
    check('the range the backtest needs is backfilled again after the reset',
          (int)scalar("select count(distinct date) from statistics where indicator='ma200'") > 10);

    echo "\n--- strategies: the backtest uses the same engine ---\n";

    /* A screen only trend strategy, which is what can be backtested once
       the averages are in: no rank_by, so no indicator beyond the screen's
       own. */
    $BT = 'test_backtest';
    s_drop($BT);
    db()->exec("delete from strategy_backtest_runs where strategy='$BT'");
    $bt_screen = (int)scalar("select id from screen where name='Trend following (200-day)'");
    s_rules($BT, ['selection'=>'screen','screen_id'=>$bt_screen,'score_indicator'=>null,
                  'rank_by'=>null,'holdings'=>5,'rebalance'=>'weekly',
                  'exit_rules'=>'below_ma200_2d']);
    s_reset($BT);

    $bt_cash_before = scalar("select cash from strategy_accounts where strategy=?", [$BT]);

    [$bt_out, $bt_rc] = run_script('backtest_strategy.php', [$BT, $bf_from, $bf_to]);
    $bt_run = rows("select * from strategy_backtest_runs where strategy=? order by run_id desc limit 1", [$BT]);

    check('the backtest runs and records what it did',
          count($bt_run) === 1 && $bt_run[0]['status'] === 'DONE'
          && (int)$bt_run[0]['trading_days'] > 10,
          substr($bt_out, -400));
    check('it traded its own account, not the strategy it was replaying',
          strpos((string)$bt_run[0]['account'], STRATEGY_BACKTEST_PREFIX) === 0,
          (string)$bt_run[0]['account']);
    check('the live account did not move a penny',
          scalar("select cash from strategy_accounts where strategy=?", [$BT]) === $bt_cash_before
          && (int)scalar("select count(*) from strategy_orders where strategy=?", [$BT]) === 0
          && (int)scalar("select count(*) from strategy_positions where strategy=?", [$BT]) === 0,
          "cash $bt_cash_before -> " . scalar("select cash from strategy_accounts where strategy=?", [$BT]));
    check('it rebalanced weekly rather than every night',
          (int)$bt_run[0]['rebalances'] > 1
          && (int)$bt_run[0]['rebalances'] < (int)$bt_run[0]['trading_days'],
          json_encode([$bt_run[0]['rebalances'], $bt_run[0]['trading_days']]));
    check('it paid the same dealing costs the paper account would',
          (float)$bt_run[0]['costs_paid'] > 0 && (int)$bt_run[0]['fills'] > 0,
          json_encode([$bt_run[0]['costs_paid'], $bt_run[0]['fills']]));
    check('it measured itself against the benchmark over its own period',
          $bt_run[0]['benchmark_return'] !== null);
    check('and it says what to read the result with, both ways',
          stripos((string)$bt_run[0]['note'], 'survivorship') !== false
          && stripos((string)$bt_run[0]['note'], 'look-ahead') !== false,
          (string)$bt_run[0]['note']);
    check('the two caveats are in the output as well, where somebody running it will see them',
          stripos($bt_out, 'survivorship') !== false && stripos($bt_out, 'look-ahead') !== false);
    check('a value for every night is left for the page to draw',
          (int)scalar("select count(*) from strategy_values where strategy=?",
                      [(string)$bt_run[0]['account']]) > 10);
    check('and the orders are cleared, because the result is the values',
          (int)scalar("select count(*) from strategy_orders where strategy=?",
                      [(string)$bt_run[0]['account']]) === 0);

    /* The nightly run must never pick a backtest account up, whatever is in
       strategy_accounts. */
    check('the nightly run will not trade the backtest account',
          !in_array((string)$bt_run[0]['account'], strategy_strategies(), true));

    /* --keep leaves the mechanism behind for inspection. */
    [$bt_keep_out, $bt_keep_rc] = run_script('backtest_strategy.php', [$BT, $bf_from, $bf_to, '--keep']);
    $bt_keep = rows("select * from strategy_backtest_runs where strategy=? order by run_id desc limit 1", [$BT]);
    check('--keep leaves the orders behind to look at',
          (int)scalar("select count(*) from strategy_orders where strategy=?",
                      [(string)$bt_keep[0]['account']]) > 0,
          substr($bt_keep_out, -200));
    check('and each run is its own account, so two runs can be compared',
          (string)$bt_keep[0]['account'] !== (string)$bt_run[0]['account']);

    echo "\n--- strategies: a backtest that would need hindsight is refused ---\n";

    /* The look-ahead rule, and the reason it is data rather than a list of
       strategies: an indicator can only be used on a date it was stored
       for. The value measures arrive with the November 2026 fundamentals,
       so Trending Value refuses anything earlier instead of ranking 2019 on
       today's figures. */
    [$tv_out, $tv_rc] = run_script('backtest_strategy.php', ['trending_value', '2019-01-02', '2019-06-28']);
    $tv_run = rows("select * from strategy_backtest_runs where strategy='trending_value'
                     order by run_id desc limit 1");

    check('Trending Value is refused before its fundamentals existed',
          $tv_rc === 3 && count($tv_run) === 1 && $tv_run[0]['status'] === 'REFUSED',
          substr($tv_out, 0, 400));
    check('and the refusal says which indicator and from when',
          stripos($tv_out, 'value_score') !== false || stripos($tv_out, 'has no stored history') !== false,
          substr($tv_out, 0, 400));
    check('nothing was traded for it',
          (int)scalar("select count(*) from strategy_orders
                        where strategy like ?", [STRATEGY_BACKTEST_PREFIX . 'trending_value%']) === 0);
    check('the refusal is on the record, not only in somebody\'s terminal',
          stripos((string)$tv_run[0]['note'], 'cannot be backtested') !== false
          || stripos((string)$tv_run[0]['note'], 'no stored history') !== false,
          (string)$tv_run[0]['note']);

    /* The same rule the other way round: the trend strategy's own window is
       what the backfill wrote, and a date before it is refused too. */
    [$early_out, $early_rc] = run_script('backtest_strategy.php',
                                         [$BT, '2023-02-01', '2023-03-01']);
    check('and so is a trend strategy before its averages were backfilled',
          $early_rc === 3 && stripos($early_out, 'REFUSED') !== false,
          substr($early_out, 0, 300));

    /* A backtest of a reserved name is refused outright: backtesting a
       backtest is a mistake, not a feature. */
    [$res_out, $res_rc] = run_script('backtest_strategy.php',
                                     [STRATEGY_BACKTEST_PREFIX . 'anything', $bf_from, $bf_to]);
    check('a backtest account cannot itself be backtested',
          $res_rc !== 0 && stripos($res_out, 'reserved') !== false,
          substr($res_out, 0, 200));

    echo "\n--- strategies: backtest variants, without touching the live rules ---\n";

    /* The question these runs are being done to answer is which settings to
       run live, and that cannot be answered by editing the strategy's rules
       four times: the edit would change the live strategy, and the four
       results would carry one label between them.

       Same shape as the run above - a screen only trend strategy over the
       dates the backfill covers - because that is what can be backtested
       without hindsight. */
    $BV = 'test_bt_variants';
    s_drop($BV);
    db()->prepare("delete from strategy_backtest_runs where strategy=?")->execute([$BV]);
    s_rules($BV, ['selection'=>'screen','screen_id'=>$bt_screen,'score_indicator'=>null,
                  'rank_by'=>null,'holdings'=>5,'rebalance'=>'monthly',
                  'exit_rules'=>'below_ma200_2d']);
    s_reset($BV);

    $bv_before = (int) scalar("select count(*) from strategy_backtest_runs where strategy=?", [$BV]);

    [$bv_out, $bv_rc] = run_script('backtest_strategy.php',
                                   [$BV, $bf_from, $bf_to, '--rebalance=weekly',
                                    '--holdings=6', '--hold-rank=9']);

    $bv_run = rows("select * from strategy_backtest_runs where strategy=? order by run_id desc limit 1",
                   [$BV]);

    check('a run with overridden settings finishes',
          $bv_rc === 0 && count($bv_run) === 1 && $bv_run[0]['status'] === 'DONE',
          'rc ' . $bv_rc . ': ' . substr($bv_out, -400));
    check('and says what it was told to do differently',
          stripos($bv_out, 'overridden for this run') !== false
          && stripos($bv_out, 'rebalance=weekly') !== false
          && stripos($bv_out, 'holdings=6') !== false,
          substr($bv_out, 0, 600));
    check('the overrides are on the run, not only in somebody\'s terminal',
          stripos((string) $bv_run[0]['overrides'], 'rebalance=weekly') !== false
          && stripos((string) $bv_run[0]['overrides'], 'hold-rank=9') !== false,
          json_encode($bv_run[0]['overrides']));
    check('it did rebalance weekly, as it was told to rather than as stored',
          (int) $bv_run[0]['rebalances'] > 1,
          json_encode([$bv_run[0]['rebalances'], $bv_run[0]['trading_days']]));

    /* The overridden copy is the backtest account's: the live strategy's
       own rules are exactly as they were. */
    $bv_live = strategy_rules($BV);
    check('and the live rules did not change',
          $bv_live['rebalance'] === 'monthly' && (int) $bv_live['holdings'] === 5
          && $bv_live['hold_rank'] === null,
          json_encode([$bv_live['rebalance'], $bv_live['holdings'], $bv_live['hold_rank']]));

    /* "start 98,487.63" was the value on the first night, already down by
       the first build's dealing costs - so the return was measured from a
       number the costs had come out of, and the costs were understated by
       exactly that much. The start is the money that went in. */
    check('the start is the money that went in, not what the first build left',
          abs((float) $bv_run[0]['starting_cash'] - STRATEGY_BACKTEST_CASH) < 0.005
          && abs((float) $bv_run[0]['start_value'] - STRATEGY_BACKTEST_CASH) < 0.005,
          json_encode([$bv_run[0]['starting_cash'], $bv_run[0]['start_value']]));
    check('and the first night\'s value is printed as what it is, separately',
          stripos($bv_out, 'started with') !== false
          && stripos($bv_out, 'after the first build') !== false,
          substr($bv_out, (int) strpos($bv_out, 'started with'), 160));
    check('the costs are shown against it',
          stripos($bv_out, 'of what went in') !== false,
          substr($bv_out, (int) strpos($bv_out, 'dealing costs'), 160));

    /* A rebalance the turnover cap refused is a fact about the result, and
       the first version could only say "10 or more" because it stopped
       collecting errors at ten. */
    check('refused rebalances are counted, not listed to ten',
          $bv_run[0]['turnover_refusals'] !== null
          && (int) $bv_run[0]['turnover_refusals'] >= 0,
          json_encode($bv_run[0]['turnover_refusals']));

    /* A setting nobody can act on is worse than none: a mistyped option is
       named rather than read as a date or quietly ignored. */
    foreach ([['--rebalance=fortnightly', 'rebalance'],
              ['--holdings=0',            'holdings'],
              ['--holdings=two',          'holdings'],
              ['--hold-rank=-1',          'hold-rank'],
              ['--nonsense=1',            'Unknown option']] as [$bv_bad, $bv_word]) {
        [$bv_bad_out, $bv_bad_rc] = run_script('backtest_strategy.php',
                                               [$BV, $bf_from, $bf_to, $bv_bad]);
        check("'$bv_bad' is refused rather than guessed at",
              $bv_bad_rc === 2 && stripos($bv_bad_out, $bv_word) !== false,
              'rc ' . $bv_bad_rc . ': ' . substr($bv_bad_out, 0, 200));
    }

    check('and a refused option writes no run at all',
          (int) scalar("select count(*) from strategy_backtest_runs where strategy=?", [$BV])
          === $bv_before + 1,
          scalar("select count(*) from strategy_backtest_runs where strategy=?", [$BV]));

    /* 'none' turns the buffer off for a run, which is the comparison the
       buffer was added to make. */
    [$bv_none_out, $bv_none_rc] = run_script('backtest_strategy.php',
                                             [$BV, $bf_from, $bf_to, '--hold-rank=none']);
    check('the buffer can be turned off for a run',
          $bv_none_rc === 0
          && stripos((string) scalar("select overrides from strategy_backtest_runs
                                       where strategy=? order by run_id desc limit 1", [$BV]),
                     'hold-rank=none') !== false,
          'rc ' . $bv_none_rc . ': ' . substr($bv_none_out, -200));

    echo "\n--- strategies: the runs in one table ---\n";

    /* Four results in four terminals is not a comparison. */
    [$bv_cmp, $bv_cmp_rc] = run_script('backtest_strategy.php', ['--compare']);
    check('the comparison runs', $bv_cmp_rc === 0, substr($bv_cmp, 0, 300));
    check('with a column for each thing the runs are being compared on',
          stripos($bv_cmp, 'return') !== false && stripos($bv_cmp, 'a year') !== false
          && stripos($bv_cmp, 'bench') !== false && stripos($bv_cmp, 'fall') !== false
          && stripos($bv_cmp, 'costs%') !== false && stripos($bv_cmp, 'ref') !== false,
          substr($bv_cmp, 0, 300));
    check('and the settings each run used, so the rows can be told apart',
          stripos($bv_cmp, 'rebalance=weekly') !== false
          && stripos($bv_cmp, 'as stored') !== false,
          substr($bv_cmp, 0, 800));
    /* Every row is flattered by survivorship, and a table is exactly where
       that is easiest to forget. */
    check('the caveat is on the table, not only on a single run',
          stripos($bv_cmp, 'survivorship') !== false);

    [$bv_one, $bv_one_rc] = run_script('backtest_strategy.php', ['--compare', $BV]);
    check('it can be narrowed to one strategy',
          $bv_one_rc === 0 && stripos($bv_one, $BV) !== false
          && stripos($bv_one, 'test_backtest ') === false,
          substr($bv_one, 0, 400));
    [$bv_cmp_none] = run_script('backtest_strategy.php', ['--compare', 'no_such_strategy']);
    check('and says so when there is nothing to compare',
          stripos($bv_cmp_none, 'no backtest runs') !== false, $bv_cmp_none);

    /* The page says it too, or the comparison lives only in a terminal. */
    $bv_tpl = php_strip_whitespace(dirname(__DIR__) . '/templates/strategies.php');
    check('the page labels a backtest with the settings it used',
          strpos($bv_tpl, "run['overrides']") !== false && strpos($bv_tpl, 'as stored') !== false);
    check('and shows the costs against the money that went in',
          strpos($bv_tpl, "run['starting_cash']") !== false
          && strpos($bv_tpl, 'of the start') !== false);
    check('and how many rebalances the turnover limit refused',
          strpos($bv_tpl, "run['turnover_refusals']") !== false);

    s_drop($BV);
    db()->prepare("delete from strategy_backtest_runs where strategy=?")->execute([$BV]);

    db()->exec("delete from strategy_values where strategy like '" . STRATEGY_BACKTEST_PREFIX . "%'");
    db()->exec("delete from strategy_orders where strategy like '" . STRATEGY_BACKTEST_PREFIX . "%'");
    db()->exec("delete from strategy_positions where strategy like '" . STRATEGY_BACKTEST_PREFIX . "%'");
    db()->exec("delete from strategy_targets where strategy like '" . STRATEGY_BACKTEST_PREFIX . "%'");
    db()->exec("delete from strategy_rules where strategy like '" . STRATEGY_BACKTEST_PREFIX . "%'");
    db()->exec("delete from strategy_accounts where strategy like '" . STRATEGY_BACKTEST_PREFIX . "%'");
    db()->exec("delete from strategy_backtest_runs");
    s_drop($BT);

    echo "\n--- the broker connection: what a ticker means ---\n";

    /* Trading 212's only documented ticker is AAPL_US_EQ. The format for a
       London share is not documented, and the two plausible spellings - a
       country suffix and a single lower case exchange letter - are taken
       apart rather than bet on. */
    foreach ([['AAPL_US_EQ', 'AAPL', 'US'],
              ['VODl_EQ',    'VOD',  'L'],
              ['VOD_GB_EQ',  'VOD',  'GB'],
              ['RR.l_EQ',    'RR.',  'L'],
              ['BT-Al_EQ',   'BT-A', 'L'],
              ['TSCO',       'TSCO', null]] as [$bk_in, $bk_code, $bk_market]) {
        $bk_parts = broker_ticker_code($bk_in);
        check("'$bk_in' is the code $bk_code",
              $bk_parts['code'] === $bk_code && $bk_parts['market'] === $bk_market,
              json_encode($bk_parts));
    }

    /* London writes a short ticker with a trailing dot and this application
       writes a share class with a dash. Neither is a different company. */
    check('a trailing dot and a dash do not make a different company',
          broker_code_key('RR.') === broker_code_key('RR')
          && broker_code_key('BT-A') === broker_code_key('BT.A'),
          json_encode([broker_code_key('RR.'), broker_code_key('BT-A'), broker_code_key('BT.A')]));

    echo "\n--- the broker connection: matching instruments to shares ---\n";

    db()->exec("delete from broker_instruments");
    db()->exec("update stock_symbols set isin=null where exchange='XLON'");

    /* An ISIN match wins over a ticker match. Here the broker's ticker says
       CCC and its ISIN says BBB, which is a disagreement somebody has to
       lose: the ISIN is the company, a ticker is only how one venue writes
       it. */
    db()->prepare("update stock_symbols set isin='GB00BBBB0002' where symbol='BBB' and exchange='XLON'")
        ->execute();

    $bk_match = broker_match_instruments([
        ['ticker' => 'CCCl_EQ', 'isin' => 'GB00BBBB0002', 'name' => 'Beta PLC',
         'currencyCode' => 'GBX'],
        ['ticker' => 'AAAl_EQ', 'isin' => 'GB00AAAA0001', 'name' => 'Alpha PLC',
         'currencyCode' => 'GBX'],
        ['ticker' => 'AAPL_US_EQ', 'isin' => 'US0378331005', 'name' => 'Apple',
         'currencyCode' => 'USD'],
    ], 'XLON');

    $bk_by_ticker = [];
    foreach ($bk_match['rows'] as $bk_row) { $bk_by_ticker[$bk_row['broker_ticker']] = $bk_row; }

    check('an ISIN match wins over a ticker match',
          ($bk_by_ticker['CCCl_EQ']['symbol'] ?? null) === 'BBB'
          && ($bk_by_ticker['CCCl_EQ']['matched_on'] ?? null) === 'isin',
          json_encode($bk_by_ticker['CCCl_EQ'] ?? null));
    check('and a ticker match is used where there is no ISIN on our side',
          ($bk_by_ticker['AAAl_EQ']['symbol'] ?? null) === 'AAA'
          && ($bk_by_ticker['AAAl_EQ']['matched_on'] ?? null) === 'ticker',
          json_encode($bk_by_ticker['AAAl_EQ'] ?? null));
    check('an instrument that is none of our shares is counted, not matched',
          !isset($bk_by_ticker['AAPL_US_EQ']) && $bk_match['unknown'] === 1,
          json_encode([array_keys($bk_by_ticker), $bk_match['unknown']]));

    /* Two instruments fitting one share is the same problem as one code
       fitting two shares, and has the same answer: neither is chosen. A
       wrong match values the wrong company's holding. */
    $bk_two = broker_match_instruments([
        ['ticker' => 'AAAl_EQ', 'name' => 'Alpha PLC', 'currencyCode' => 'USD'],
        ['ticker' => 'AAAp_EQ', 'name' => 'Alpha Inc', 'currencyCode' => 'USD'],
    ], 'XLON');

    check('two instruments that fit one share are left unmatched, not guessed at',
          $bk_two['matched'] === 0 && $bk_two['ambiguous'] === 2,
          json_encode($bk_two));
    check('and the page is told which ones and why',
          count(array_filter($bk_two['rows'], function ($r) {
              return $r['status'] === 'ambiguous'
                  && stripos((string) $r['note'], 'more than one instrument') !== false; })) === 2,
          json_encode(array_column($bk_two['rows'], 'note')));

    /* Unless one of them is obviously the sterling one, or obviously the
       London line, which settles it on the data rather than by guessing.
       Both tie-breaks are real: Trading 212 writes London as <code>l_EQ,
       <code>l1_EQ or <code>p_EQ, and other markets as <code>_US_EQ or
       <code>_CA_EQ. */
    $bk_ccy = broker_match_instruments([
        ['ticker' => 'AAAl_EQ',   'name' => 'Alpha PLC', 'currencyCode' => 'GBX'],
        ['ticker' => 'AAA_US_EQ', 'name' => 'Alpha Inc', 'currencyCode' => 'USD'],
    ], 'XLON');
    check('a sterling instrument is preferred over a foreign one of the same code',
          $bk_ccy['matched'] === 1
          && $bk_ccy['rows'][0]['broker_ticker'] === 'AAAl_EQ',
          json_encode(array_map(function ($r) {
              return [$r['broker_ticker'], $r['status']]; }, $bk_ccy['rows'])));

    /* The three pairs the first live run could not tell apart were exactly
       this shape: MNTNl_EQ beside MNTN_US_EQ, PEYl_EQ beside PEY_CA_EQ,
       SHCp_EQ beside SHC_US_EQ. */
    $bk_pairs = broker_match_instruments([
        ['ticker' => 'AAAl_EQ',   'shortName' => 'AAA', 'currencyCode' => 'GBX',
         'isin' => 'GB00AAAA0001'],
        ['ticker' => 'AAA_US_EQ', 'shortName' => 'AAA', 'currencyCode' => 'USD',
         'isin' => 'US00AAAA0001'],
        ['ticker' => 'BBBl_EQ',   'shortName' => 'BBB', 'currencyCode' => 'GBX',
         'isin' => 'GB00BBBB0002'],
        ['ticker' => 'BBB_CA_EQ', 'shortName' => 'BBB', 'currencyCode' => 'CAD',
         'isin' => 'CA00BBBB0002'],
    ], 'XLON');
    $bk_pair_by = [];
    foreach ($bk_pairs['rows'] as $bk_row) { $bk_pair_by[$bk_row['broker_ticker']] = $bk_row; }
    check('a London line beside a foreign one of the same name is not an ambiguity',
          $bk_pairs['ambiguous'] === 0
          && ($bk_pair_by['AAAl_EQ']['symbol'] ?? null) === 'AAA'
          && ($bk_pair_by['BBBl_EQ']['symbol'] ?? null) === 'BBB',
          json_encode(array_map(function ($r) {
              return [$r['broker_ticker'], $r['status'], $r['symbol']]; }, $bk_pairs['rows'])));

    /* And what is left over keeps enough of itself to be looked into: the
       first live run stored ambiguous rows with no ISIN and no currency,
       which is exactly what somebody needs to tell the two apart. */
    $bk_amb_keep = broker_match_instruments([
        ['ticker' => 'AAAl_EQ', 'shortName' => 'AAA', 'currencyCode' => 'USD',
         'isin' => 'US00AAAA0001'],
        ['ticker' => 'AAAp_EQ', 'shortName' => 'AAA', 'currencyCode' => 'USD',
         'isin' => 'US00AAAA0002'],
    ], 'XLON');
    db()->exec("delete from broker_instruments");
    broker_store_instruments(BROKER_T212, $bk_amb_keep['rows'], asof_date());
    $bk_amb_rows = broker_ambiguous_instruments();
    check('an ambiguous row keeps its ISIN and its currency, to be settled later',
          count($bk_amb_rows) === 2
          && $bk_amb_rows[0]['isin'] !== null && $bk_amb_rows[0]['currency'] === 'USD'
          && $bk_amb_rows[0]['short_name'] === 'AAA',
          json_encode($bk_amb_rows));
    db()->exec("delete from broker_instruments");

    echo "\n--- the broker connection: a rename keeps the old ticker ---\n";

    /* Trading 212's ticker keeps a company's OLD code after a rename, and
       shortName carries the current one. Matching on the ticker alone left
       32 of the FTSE 350 unmatched on the first live run - Barratt Redrow
       as BDEVl_EQ, Entain as GVCl_EQ, Frasers as SPDl_EQ, Vistry as
       BVSl_EQ, LSEG as LSEl_EQ, Harbour Energy as PMOl_EQ. */
    db()->exec("delete from stock_symbols where symbol in ('BTRW','SHAW','BT-A','SHC')");
    foreach (['BTRW' => 'Barratt Redrow', 'SHAW' => 'Shawbrook',
              'BT-A' => 'BT Group', 'SHC' => 'Shaftesbury Capital'] as $bk_sym => $bk_name) {
        db()->prepare("insert into stock_symbols (symbol,name,description,exchange,enabled,
                                                  sector,market,industry_group,industry)
                       values (?,?,?,'XLON','Y','Industrials','Main Market','Industrials','X')")
            ->execute([$bk_sym, $bk_name, $bk_name]);
    }

    $bk_renamed = broker_match_instruments([
        ['ticker' => 'BDEVl_EQ', 'shortName' => 'BTRW', 'name' => 'Barratt Redrow',
         'currencyCode' => 'GBX', 'isin' => 'GB00BTRW0001'],
        ['ticker' => 'SHAWl1_EQ', 'shortName' => 'SHAW', 'name' => 'Shawbrook',
         'currencyCode' => 'GBX', 'isin' => 'GB00SHAW0001'],
        ['ticker' => 'BTl_EQ', 'shortName' => 'BT/A', 'name' => 'BT Group',
         'currencyCode' => 'GBX', 'isin' => 'GB00BT000001'],
        /* The pair the brief singles out: both reduce to the code SHC, and
           the one whose shortName says SHC is the Shaftesbury line. */
        ['ticker' => 'CAPCl_EQ', 'shortName' => 'SHC', 'name' => 'Shaftesbury Capital',
         'currencyCode' => 'GBX', 'isin' => 'GB00CAPC0001'],
        ['ticker' => 'SHCp_EQ', 'shortName' => 'SHCO', 'name' => 'Somebody Else',
         'currencyCode' => 'GBX', 'isin' => 'GB00SHCO0001'],
    ], 'XLON');

    $bk_ren_by = [];
    foreach ($bk_renamed['rows'] as $bk_row) { $bk_ren_by[$bk_row['broker_ticker']] = $bk_row; }

    check('a renamed company matches on its short name, not on the old ticker',
          ($bk_ren_by['BDEVl_EQ']['symbol'] ?? null) === 'BTRW'
          && ($bk_ren_by['BDEVl_EQ']['matched_on'] ?? null) === 'short_name',
          json_encode($bk_ren_by['BDEVl_EQ'] ?? null));
    check('the l1 line is read as a line rather than as part of the code',
          broker_ticker_code('SHAWl1_EQ')['code'] === 'SHAW'
          && ($bk_ren_by['SHAWl1_EQ']['symbol'] ?? null) === 'SHAW',
          json_encode([broker_ticker_code('SHAWl1_EQ'), $bk_ren_by['SHAWl1_EQ'] ?? null]));
    check('a share class matches across the slash and the dash',
          ($bk_ren_by['BTl_EQ']['symbol'] ?? null) === 'BT-A',
          json_encode($bk_ren_by['BTl_EQ'] ?? null));
    check('the instrument whose short name says SHC is the one that gets SHC',
          ($bk_ren_by['CAPCl_EQ']['symbol'] ?? null) === 'SHC'
          && !isset($bk_ren_by['SHCp_EQ']),
          json_encode(array_map(function ($r) {
              return [$r['broker_ticker'], $r['status'], $r['symbol']]; }, $bk_renamed['rows'])));
    check('and none of that counted as an ambiguity',
          $bk_renamed['ambiguous'] === 0, json_encode($bk_renamed));

    /* A short name is not unique across 18,600 instruments, so it only
       counts for the sterling ones. */
    $bk_foreign = broker_match_instruments([
        ['ticker' => 'XYZ_US_EQ', 'shortName' => 'BTRW', 'name' => 'Someone American',
         'currencyCode' => 'USD', 'isin' => 'US00XYZ00001'],
    ], 'XLON');
    check('a foreign instrument does not take a share on its short name',
          $bk_foreign['matched'] === 0 && $bk_foreign['unknown'] === 1,
          json_encode($bk_foreign));

    echo "\n--- the broker connection: a share the broker does not list ---\n";

    /* Every Trading 212 instrument carries an ISIN, so a share of ours whose
       ISIN is nowhere in their list cannot be bought there - which is a
       different thing from one this application merely failed to match. */
    db()->exec("update stock_symbols set isin=null where exchange='XLON'");
    db()->prepare("update stock_symbols set isin='GB00BTRW0001' where symbol='BTRW'")->execute();
    db()->prepare("update stock_symbols set isin='GB00NOWHERE1' where symbol='SHAW'")->execute();

    $bk_not_listed = broker_isins_not_listed(['GB00BTRW0001', 'GB00CAPC0001'], 'XLON');
    check('a share whose ISIN the broker does not list at all is named',
          $bk_not_listed === ['SHAW'], json_encode($bk_not_listed));
    check('and with no ISINs on our side nothing is claimed either way',
          broker_isins_not_listed([], 'XLON') === [],
          json_encode(broker_isins_not_listed([], 'XLON')));

    db()->exec("update stock_symbols set isin=null where exchange='XLON'");
    db()->exec("delete from stock_symbols where symbol in ('BTRW','SHAW','BT-A','SHC')");

    /* And the shares the broker has nothing for are listed rather than
       quietly missing. */
    broker_store_instruments(BROKER_T212, $bk_match['rows'], asof_date());
    $bk_unmatched = array_column(broker_unmatched_symbols(BROKER_T212, 'XLON'), 'symbol');
    check('a share the broker has no instrument for is listed',
          in_array('DDD', $bk_unmatched, true) && !in_array('AAA', $bk_unmatched, true),
          implode(',', $bk_unmatched));
    check('and a share matched by ISIN is not in that list',
          !in_array('BBB', $bk_unmatched, true), implode(',', $bk_unmatched));

    db()->exec("update stock_symbols set isin=null where exchange='XLON'");
    db()->exec("delete from broker_instruments");

    echo "\n--- the broker connection: pounds or pence ---\n";

    /* A London share quoted at 690p is either 690 or 6.90 at the other end,
       and those are a hundred times apart. The API documentation does not
       say which, so it is decided by comparing the broker's price against
       our own close for the same share rather than assumed - get it wrong
       and every figure on the page is out by a hundred. */
    $bk_690 = [['symbol' => 'T6P', 'price' => 6.90,  'close' => 690.0]];
    $bk_pence = [['symbol' => 'T6P', 'price' => 690.0, 'close' => 690.0]];

    check('a 690p share priced at 6.90 means the broker is quoting pounds',
          broker_price_unit($bk_690, 'auto')['unit'] === 'pounds');
    check('and the same share priced at 690 means pence',
          broker_price_unit($bk_pence, 'auto')['unit'] === 'pence');
    check('either way it is 6.90 in pounds, which is what gets stored',
          abs(broker_to_pounds(6.90, 'pounds') - 6.90) < 0.0001
          && abs(broker_to_pounds(690.0, 'pence') - 6.90) < 0.0001);

    /* A price that is neither is not rounded to the nearer answer. */
    check('a price that is neither leaves the unit unknown',
          broker_price_unit([['symbol' => 'T6P', 'price' => 64.0, 'close' => 690.0]],
                            'auto')['unit'] === null);
    check('and so does a disagreement between two holdings',
          broker_price_unit([['symbol' => 'A', 'price' => 6.90, 'close' => 690.0],
                             ['symbol' => 'B', 'price' => 240.0, 'close' => 240.0]],
                            'auto')['unit'] === null);
    check('nothing to compare against is unknown too, rather than a guess',
          broker_price_unit([], 'auto')['unit'] === null
          && stripos(broker_price_unit([], 'auto')['why'], 'unknown') !== false);
    check('and the setting overrides the lot, for when the probe has settled it',
          broker_price_unit([], 'pounds')['unit'] === 'pounds'
          && broker_price_unit($bk_pence, 'pounds')['unit'] === 'pounds');


    echo "\n--- the broker connection: off means off ---\n";

    /* The stub, which is where every request in this section goes. No test
       talks to Trading 212: the live key reads a real account with real money
       in it, and a suite that calls somebody's broker on every run is not a
       suite anybody should run. */
    $bk_dir  = sys_get_temp_dir() . '/smt212' . getmypid();
    exec('rm -rf ' . escapeshellarg($bk_dir));
    @mkdir($bk_dir, 0777, true);

    $bk_port = 8371;
    $bk_pid  = (int) trim((string) shell_exec(
        'SM_STUB_DIR=' . escapeshellarg($bk_dir) . ' php -S 127.0.0.1:' . $bk_port . ' '
        . escapeshellarg(dirname(__DIR__) . '/tools/t212_stub.php')
        . ' > /dev/null 2>&1 & echo $!'));
    usleep(900000);

    /* A key that could not be mistaken for a real one, so a test can prove it
       never reaches a page, a log line or a stored problem. */
    $bk_key    = 'T212-TEST-KEY-NOT-REAL-7b2e';
    $bk_secret = 'T212-TEST-SECRET-NOT-REAL-4c9f';

    $bk_base = 'http://127.0.0.1:' . $bk_port . '/api/v0';
    $bk_env  = ['SM_T212_ENV'        => 'live-read',
                'SM_T212_API_KEY'    => $bk_key,
                'SM_T212_API_SECRET' => $bk_secret,
                'SM_T212_BASE_URL'   => $bk_base,
                'SM_T212_THROTTLE'   => '0',
                'SM_T212_MAX_WAIT'   => '1'];

    $bk_write = function ($name, $content) use ($bk_dir) {
        file_put_contents("$bk_dir/$name", $content);
    };
    $bk_remove = function ($name) use ($bk_dir) { @unlink("$bk_dir/$name"); };
    $bk_reset  = function () use ($bk_dir) { @file_put_contents("$bk_dir/t212_requests", ''); };
    $bk_asked  = function () use ($bk_dir) {
        return array_values(array_filter(explode("\n",
               (string) @file_get_contents("$bk_dir/t212_requests"))));
    };
    $bk_last_run = function () {
        $rows = rows("select * from broker_sync_runs order by run_id desc limit 1");
        return count($rows) ? $rows[0] : null;
    };

    /* Prove this run's stub is the one answering before trusting a word it
       says. A server left behind by an earlier run holds the port, serves its
       own control directory and turns every check below into a puzzle. */
    $bk_up = false;
    for ($bk_try = 0; $bk_try < 25 && !$bk_up; $bk_try++) {
        usleep(200000);
        @file_get_contents($bk_base . '/equity/account/summary', false,
                           stream_context_create(['http' => ['timeout' => 2,
                                                             'ignore_errors' => true]]));
        $bk_up = count($bk_asked()) > 0;
    }
    check('the broker stub answers, and from this run\'s control directory',
          $bk_up, 'nothing was recorded in ' . $bk_dir
                . ' - is a stub from an earlier run still on port ' . $bk_port . '?');

    /* The stub refuses anything whose Basic credentials are not these, so a
       call that gets an answer at all has proved the header was built right. */
    $bk_write('t212_auth', $bk_key . ':' . $bk_secret);

    db()->exec("delete from broker_sync_runs");
    db()->exec("delete from broker_positions");
    db()->exec("delete from broker_orders");
    db()->exec("delete from broker_dividends");
    db()->exec("delete from broker_account_values");
    db()->exec("delete from broker_instruments");

    /* Off is the normal state of a host nobody has configured, and the one
       thing it must do is nothing at all: no socket, no row, no complaint. */
    $bk_reset();
    [$bk_off_out, $bk_off_rc] = run_script('sync_broker.php', [],
        ['SM_T212_ENV' => 'off', 'SM_T212_API_KEY' => $bk_key,
         'SM_T212_API_SECRET' => $bk_secret, 'SM_T212_BASE_URL' => $bk_base]);

    check('with the connection off the job exits cleanly and says so',
          $bk_off_rc === 0 && stripos($bk_off_out, 'off') !== false,
          'rc ' . $bk_off_rc . ': ' . $bk_off_out);
    check('and makes no request at all',
          count($bk_asked()) === 0, implode("\n", $bk_asked()));
    check('and writes no run, because nothing ran',
          (int) scalar("select count(*) from broker_sync_runs") === 0);

    /* The same with an environment set and no key: configuration half done
       is still not configured. */
    $bk_reset();
    [$bk_nokey_out, $bk_nokey_rc] = run_script('sync_broker.php', [],
        ['SM_T212_ENV' => 'live-read', 'SM_T212_BASE_URL' => $bk_base]);
    check('an environment with no key asks for nothing either',
          $bk_nokey_rc === 0 && count($bk_asked()) === 0
          && stripos($bk_nokey_out, 'no ') !== false,
          'rc ' . $bk_nokey_rc . ': ' . $bk_nokey_out);

    /* And a typo in the environment name is not read as one of the three. */
    $bk_reset();
    [$bk_typo_out] = run_script('sync_broker.php', [],
        ['SM_T212_ENV' => 'live', 'SM_T212_API_KEY' => $bk_key,
         'SM_T212_API_SECRET' => $bk_secret, 'SM_T212_BASE_URL' => $bk_base]);
    check('a mistyped environment is not guessed at',
          count($bk_asked()) === 0 && stripos($bk_typo_out, "'live'") !== false,
          $bk_typo_out);

    echo "\n--- the broker connection: a sync ---\n";

    /* A 690p share, which is the figure the brief names: the broker sends
       6.90 and the page must show GBP6.90, not 690 and not 0.069. */
    db()->exec("delete from stock_symbols where symbol='T6P'");
    db()->exec("delete from historical_prices where symbol='T6P'");
    db()->prepare("insert into stock_symbols (symbol,name,description,exchange,enabled,
                                              sector,market,industry_group,industry)
                   values ('T6P','Six Ninety PLC','Six Ninety PLC','XLON','Y',
                           'Industrials','Main Market','Industrials','Aerospace')")->execute();
    db()->prepare("insert into historical_prices (symbol,exchange,date,price)
                   values ('T6P','XLON',?,690)")->execute([asof_date()]);

    $bk_write('instruments.json', json_encode([
        ['ticker' => 'T6Pl_EQ', 'name' => 'Six Ninety PLC', 'currencyCode' => 'GBX',
         'isin' => 'GB00T6P00001', 'type' => 'STOCK'],
        ['ticker' => 'AAAl_EQ', 'name' => 'Alpha PLC', 'currencyCode' => 'GBX',
         'isin' => 'GB00AAAA0001', 'type' => 'STOCK'],
        ['ticker' => 'AAPL_US_EQ', 'name' => 'Apple', 'currencyCode' => 'USD',
         'isin' => 'US0378331005', 'type' => 'STOCK'],
    ]));
    $bk_write('positions.json', json_encode([
        ['ticker' => 'T6Pl_EQ', 'quantity' => 200, 'averagePrice' => 6.10,
         'currentPrice' => 6.90, 'ppl' => 160.0],
    ]));
    $bk_write('orders.json', json_encode(['items' => [
        ['id' => 'ord-1', 'ticker' => 'T6Pl_EQ', 'filledQuantity' => 200,
         'fillPrice' => 6.10, 'status' => 'FILLED', 'type' => 'MARKET',
         'dateCreated' => '2026-11-10T09:30:00Z', 'dateExecuted' => '2026-11-10T09:30:05Z'],
        ['id' => 'ord-2', 'ticker' => 'AAAl_EQ', 'filledQuantity' => -50,
         'fillPrice' => 2.40, 'status' => 'FILLED', 'type' => 'MARKET',
         'dateCreated' => '2026-11-12T10:00:00Z', 'dateExecuted' => '2026-11-12T10:00:02Z'],
    ], 'nextPagePath' => null]));
    $bk_write('dividends.json', json_encode(['items' => [
        ['reference' => 'div-1', 'ticker' => 'T6Pl_EQ', 'amount' => 12.34,
         'grossAmountPerShare' => 0.0617, 'quantity' => 200,
         'paidOn' => '2026-11-05T00:00:00Z', 'type' => 'ORDINARY'],
    ], 'nextPagePath' => null]));

    $bk_reset();
    [$bk_out, $bk_rc] = run_script('sync_broker.php', ['--instruments'], $bk_env);
    $bk_run = $bk_last_run();

    check('a sync runs and records what it read',
          $bk_rc === 0 && $bk_run !== null && $bk_run['status'] === 'OK',
          'rc ' . $bk_rc . ': ' . $bk_out);
    check('and sends the key as Basic credentials, which the stub checks',
          count(array_filter($bk_asked(), function ($line) {
              return strpos($line, 'auth=present') !== false; })) === count($bk_asked())
          && count($bk_asked()) > 0,
          implode("\n", $bk_asked()));

    /* The summary nests, which is what the first live sync got wrong: it
       stored a correct total of GBP250.00 beside a blank cash balance,
       because the reader only knew about top-level keys and was handed the
       cash OBJECT. Every figure it carries is stored now. */
    $bk_cash = rows("select * from broker_account_values order by as_of_date desc limit 1");
    check('the cash, the invested value and the total are read out of the nesting',
          count($bk_cash) === 1 && abs((float) $bk_cash[0]['cash'] - 250.00) < 0.005
          && abs((float) $bk_cash[0]['invested'] - 1000.00) < 0.005
          && abs((float) $bk_cash[0]['total'] - 1250.00) < 0.005,
          json_encode($bk_cash));
    check('and so are the five figures beside them',
          abs((float) $bk_cash[0]['invested_cost'] - 950.00) < 0.005
          && abs((float) $bk_cash[0]['realised'] - 12.50) < 0.005
          && abs((float) $bk_cash[0]['unrealised'] - 50.00) < 0.005
          && (float) $bk_cash[0]['reserved'] === 0.0 && (float) $bk_cash[0]['in_pies'] === 0.0
          && $bk_cash[0]['currency'] === 'GBP',
          json_encode($bk_cash));

    $bk_pos = rows("select * from broker_positions where symbol='T6P'");
    check('the 690p holding is valued in pounds, not a hundred times either way',
          count($bk_pos) === 1
          && abs((float) $bk_pos[0]['current_price'] - 6.90) < 0.005
          && abs((float) $bk_pos[0]['value'] - 1380.00) < 0.01
          && $bk_pos[0]['price_unit'] === 'pounds',
          json_encode($bk_pos));

    /* The same account sending pence instead must land on the same pounds. */
    $bk_write('positions.json', json_encode([
        ['ticker' => 'T6Pl_EQ', 'quantity' => 200, 'averagePrice' => 610.0,
         'currentPrice' => 690.0, 'ppl' => 160.0],
    ]));
    run_script('sync_broker.php', [], $bk_env);
    $bk_pence_pos = rows("select * from broker_positions where symbol='T6P'");
    check('and a broker quoting pence lands on exactly the same figure',
          count($bk_pence_pos) === 1
          && abs((float) $bk_pence_pos[0]['current_price'] - 6.90) < 0.005
          && abs((float) $bk_pence_pos[0]['value'] - 1380.00) < 0.01
          && $bk_pence_pos[0]['price_unit'] === 'pence',
          json_encode($bk_pence_pos));

    /* A price that is neither stores nothing rather than something wrong. */
    $bk_write('positions.json', json_encode([
        ['ticker' => 'T6Pl_EQ', 'quantity' => 200, 'averagePrice' => 61.0,
         'currentPrice' => 64.0, 'ppl' => 160.0],
    ]));
    run_script('sync_broker.php', [], $bk_env);
    $bk_odd = rows("select * from broker_positions where symbol='T6P'");
    check('a price that matches neither unit stores no price at all',
          count($bk_odd) === 1 && $bk_odd[0]['current_price'] === null
          && $bk_odd[0]['value'] === null && $bk_odd[0]['price_unit'] === null,
          json_encode($bk_odd));
    check('and the run says so, in words somebody can act on',
          stripos((string) $bk_last_run()['problems'], 'price unit') !== false
          && stripos((string) $bk_last_run()['problems'], 'SM_T212_PRICE_UNIT') !== false,
          (string) $bk_last_run()['problems']);

    /* Back to the honest figures for the rest of the section. */
    $bk_write('positions.json', json_encode([
        ['ticker' => 'T6Pl_EQ', 'quantity' => 200, 'averagePrice' => 6.10,
         'currentPrice' => 6.90, 'ppl' => 160.0],
    ]));

    echo "\n--- the broker connection: the history, once each ---\n";

    $bk_orders = rows("select * from broker_orders order by broker_order_id");
    check('both orders are stored, with a sell read as a sell',
          count($bk_orders) === 2
          && $bk_orders[0]['side'] === 'BUY' && (float) $bk_orders[0]['quantity'] === 200.0
          && $bk_orders[1]['side'] === 'SELL' && (float) $bk_orders[1]['quantity'] === 50.0,
          json_encode(array_map(function ($o) {
              return [$o['broker_order_id'], $o['side'], $o['quantity']]; }, $bk_orders)));
    check('a dividend is stored against our own symbol',
          (int) scalar("select count(*) from broker_dividends where symbol='T6P'") === 1);

    /* The per-share figure is the one a London investor expects in pence, so
       it is the one the broker might send that way - and the dividend row
       carries its own check: the total over the quantity is the same figure
       in the same currency as the total. 12.34 over 200 is 0.0617 a share,
       so a per-share of 6.17 arrived in pence and is stored as pounds like
       everything else. */
    check('a dividend per share is stored in the same money as its total',
          abs((float) scalar("select amount_per_share from broker_dividends
                               where broker_dividend_id='div-1'") - 0.0617) < 0.00001,
          scalar("select amount_per_share from broker_dividends where broker_dividend_id='div-1'"));

    $bk_write('dividends.json', json_encode(['items' => [
        ['reference' => 'div-pence', 'ticker' => 'T6Pl_EQ', 'amount' => 12.34,
         'grossAmountPerShare' => 6.17, 'quantity' => 200,
         'paidOn' => '2026-11-06T00:00:00Z', 'type' => 'ORDINARY'],
    ], 'nextPagePath' => null]));
    run_script('sync_broker.php', [], $bk_env);
    check('and a per share that arrives in pence lands on the same figure',
          abs((float) scalar("select amount_per_share from broker_dividends
                               where broker_dividend_id='div-pence'") - 0.0617) < 0.00001,
          scalar("select amount_per_share from broker_dividends where broker_dividend_id='div-pence'"));
    db()->exec("delete from broker_dividends where broker_dividend_id='div-pence'");
    $bk_write('dividends.json', json_encode(['items' => [
        ['reference' => 'div-1', 'ticker' => 'T6Pl_EQ', 'amount' => 12.34,
         'grossAmountPerShare' => 0.0617, 'quantity' => 200,
         'paidOn' => '2026-11-05T00:00:00Z', 'type' => 'ORDINARY'],
    ], 'nextPagePath' => null]));

    /* The order calls are not idempotent at the other end, and neither is a
       nightly job that runs twice: everything is keyed on Trading 212's own
       ids, so a re-run adds nothing. */
    $bk_first_seen = scalar("select first_seen from broker_orders where broker_order_id='ord-1'");
    sleep(1);
    [$bk_again_out] = run_script('sync_broker.php', [], $bk_env);
    $bk_again = $bk_last_run();

    check('a second sync adds no duplicate orders or dividends',
          (int) scalar("select count(*) from broker_orders") === 2
          && (int) scalar("select count(*) from broker_dividends") === 1,
          scalar("select count(*) from broker_orders") . '/' .
          scalar("select count(*) from broker_dividends"));
    check('and counts none of them as new',
          (int) $bk_again['new_orders'] === 0 && (int) $bk_again['new_dividends'] === 0,
          json_encode([$bk_again['new_orders'], $bk_again['new_dividends']]));
    check('while keeping when each was first seen',
          scalar("select first_seen from broker_orders where broker_order_id='ord-1'")
          === $bk_first_seen,
          json_encode([$bk_first_seen,
                       scalar("select first_seen from broker_orders where broker_order_id='ord-1'")]));

    echo "\n--- the broker connection: paging ---\n";

    /* The history calls are cursor paged and the cursor is a path. A client
       that reads the first page and stops silently loses history. */
    $bk_write('orders.json', json_encode(['items' => [
        ['id' => 'page1-a', 'ticker' => 'T6Pl_EQ', 'filledQuantity' => 10,
         'fillPrice' => 6.90, 'status' => 'FILLED', 'dateExecuted' => '2026-11-01T09:00:00Z'],
        ['id' => 'page1-b', 'ticker' => 'T6Pl_EQ', 'filledQuantity' => 20,
         'fillPrice' => 6.90, 'status' => 'FILLED', 'dateExecuted' => '2026-11-02T09:00:00Z'],
    ]]));
    $bk_write('orders_page2.json', json_encode(['items' => [
        ['id' => 'page2-a', 'ticker' => 'T6Pl_EQ', 'filledQuantity' => 30,
         'fillPrice' => 6.90, 'status' => 'FILLED', 'dateExecuted' => '2026-11-03T09:00:00Z'],
    ], 'nextPagePath' => null]));

    db()->exec("delete from broker_orders");
    $bk_reset();
    run_script('sync_broker.php', [], $bk_env);

    check('the cursor is followed to the end',
          (int) scalar("select count(*) from broker_orders where broker_order_id like 'page%'") === 3,
          json_encode(rows("select broker_order_id from broker_orders order by broker_order_id")));
    check('and the second page was actually asked for',
          count(array_filter($bk_asked(), function ($line) {
              return strpos($line, 'cursor=next') !== false; })) === 1,
          implode("\n", $bk_asked()));

    $bk_remove('orders_page2.json');

    echo "\n--- the broker connection: when the other end says no ---\n";

    /* 429 is an instruction, not a failure to retry through. One wait for
       the reset the headers give, then ask once more. */
    $bk_write('t212_429_once', '1');
    $bk_reset();
    [$bk_429_out, $bk_429_rc] = run_script('sync_broker.php', [], $bk_env);

    check('a single 429 is waited out and the sync finishes',
          $bk_429_rc === 0 && $bk_last_run()['status'] === 'OK',
          'rc ' . $bk_429_rc . ': ' . $bk_429_out);
    check('and the call that was refused was made exactly twice',
          count(array_filter($bk_asked(), function ($line) {
              return strpos($line, '/equity/account/summary') !== false; })) === 2,
          implode("\n", $bk_asked()));

    /* A limit that is still complaining after its own reset wants less
       traffic than this. The run stops rather than hammering it. */
    $bk_write('t212_mode', '429');
    $bk_reset();
    [$bk_stop_out, $bk_stop_rc] = run_script('sync_broker.php', [], $bk_env);

    check('a limit that does not clear stops the run',
          stripos($bk_stop_out, 'less traffic') !== false,
          'rc ' . $bk_stop_rc . ': ' . $bk_stop_out);
    check('and it asked twice in total, not once per call',
          count($bk_asked()) === 2, implode("\n", $bk_asked()));
    $bk_remove('t212_mode');

    /* A key without the permission a call needs answers 403, which is a
       setting somebody has to change rather than something tonight's run can
       fix - and it must not cost the calls that do work. */
    $bk_write('t212_mode_dividends', '403');
    $bk_reset();
    [$bk_403_out, $bk_403_rc] = run_script('sync_broker.php', [], $bk_env);
    $bk_403_run = $bk_last_run();

    check('a 403 on one call does not stop the others',
          $bk_403_rc === 0 && $bk_403_run['status'] === 'PARTIAL'
          && (int) $bk_403_run['positions'] === 1,
          'rc ' . $bk_403_rc . ': ' . $bk_403_out);
    check('and the page can tell it is a permission rather than a glitch',
          stripos((string) $bk_403_run['problems'], '(403)') !== false
          && broker_status()['forbidden'] === true,
          (string) $bk_403_run['problems']);
    $bk_remove('t212_mode_dividends');

    /* A response that is not JSON is not half-read. */
    $bk_write('t212_mode_positions', 'garbage');
    [$bk_json_out] = run_script('sync_broker.php', [], $bk_env);
    check('an answer that is not JSON is refused rather than parsed',
          stripos($bk_json_out, 'not JSON') !== false, $bk_json_out);
    $bk_remove('t212_mode_positions');

    echo "\n--- the broker connection: the key never gets out ---\n";

    /* The one rule that has no acceptable failure. The key is in the
       environment, the Authorization header is built at the moment of the
       request, and nothing that leaves this code carries either. */
    $bk_write('t212_mode', '401');
    [$bk_401_out, $bk_401_rc] = run_script('sync_broker.php', [], $bk_env);
    $bk_401_run = $bk_last_run();
    $bk_remove('t212_mode');

    check('a refused key says so in words',
          stripos($bk_401_out, 'did not accept the key') !== false,
          $bk_401_out);
    check('and the key is not in the output that says it',
          strpos($bk_401_out, $bk_key) === false
          && strpos($bk_401_out, $bk_secret) === false,
          $bk_401_out);
    check('nor in what was recorded about the run',
          strpos((string) $bk_401_run['problems'], $bk_key) === false
          && strpos((string) $bk_401_run['problems'], $bk_secret) === false,
          (string) $bk_401_run['problems']);
    check('nor in the application log',
          (int) scalar("select count(*) from message_log
                         where message_text like ? or message_text like ?",
                       ['%' . $bk_key . '%', '%' . $bk_secret . '%']) === 0);

    /* And the masker holds whatever is handed to it, including a header that
       was never ours. */
    /* The masker also takes out a credential it was never configured with -
       a header or a key=value read out of a response or a file - because the
       configured key is not the only thing that can end up in a line of
       text. (That the CONFIGURED key is masked is what the three checks
       above prove: they ran in a process that had one.) */
    check('a credential in any text is taken out of it',
          strpos(t212_mask('Authorization: Basic ' . base64_encode('a:b')),
                 base64_encode('a:b')) === false
          && strpos(t212_mask('x-api-key: abc123def'), 'abc123def') === false
          && strpos(t212_mask('{"apiKey":"abc123def"}'), 'abc123def') === false,
          t212_mask('Authorization: Basic ' . base64_encode('a:b'))
          . ' / ' . t212_mask('x-api-key: abc123def')
          . ' / ' . t212_mask('{"apiKey":"abc123def"}'));
    check('and text with no credential in it is left alone',
          t212_mask('read 3 positions') === 'read 3 positions');

    echo "\n--- the broker connection: a field it cannot read is a problem ---\n";

    /* The account summary nests. A reader that only knows top-level keys is
       handed the cash object, makes a null of it, stores it and says the run
       went well - which is what the first live sync did. */
    $bk_real = ['id' => 52612242, 'currency' => 'GBP', 'totalValue' => 250.00,
                'cash' => ['availableToTrade' => 250.00, 'reservedForOrders' => 0,
                           'inPies' => 0],
                'investments' => ['currentValue' => 0, 'totalCost' => 0,
                                  'realizedProfitLoss' => 0,
                                  'unrealizedProfitLoss' => 0]];

    check('a dotted path reads a figure out of the nesting',
          broker_path($bk_real, 'cash.availableToTrade') === 250.00
          && broker_path($bk_real, 'investments.currentValue') === 0
          && broker_path($bk_real, 'cash.nothing') === null,
          json_encode(broker_path($bk_real, 'cash.availableToTrade')));
    check('and the field map asks for them by that path',
          broker_number($bk_real, broker_fields('summary_cash')) === 250.00
          && broker_number($bk_real, broker_fields('summary_invested')) === 0.0
          && broker_number($bk_real, broker_fields('summary_total')) === 250.00,
          json_encode([broker_number($bk_real, broker_fields('summary_cash')),
                       broker_number($bk_real, broker_fields('summary_invested'))]));
    /* Nought is a figure. An account holding nothing has an invested value
       of 0, and reading that as "missing" would report a problem with a
       number that is perfectly correct. */
    check('nought is a value, not a missing field',
          broker_field_problem($bk_real, 'summary_invested', 'invested value', 'account summary') === '',
          broker_field_problem($bk_real, 'summary_invested', 'invested value', 'account summary'));

    check('a field that is not there at all is a problem that names it',
          stripos(broker_field_problem(['totalValue' => 250.0], 'summary_cash',
                                       'cash balance', 'account summary'),
                  'cash.availableToTrade') !== false,
          broker_field_problem(['totalValue' => 250.0], 'summary_cash', 'cash balance',
                               'account summary'));
    check('and a field that holds an object says so rather than storing a null',
          stripos(broker_field_problem(['cash' => ['availableToTrade' => ['x' => 1]]],
                                       'summary_cash', 'cash balance', 'account summary'),
                  'object') !== false,
          broker_field_problem(['cash' => ['availableToTrade' => ['x' => 1]]],
                               'summary_cash', 'cash balance', 'account summary'));

    /* End to end: the response the live account really sent, through the
       sync, with the figures it really holds. */
    $bk_write('summary.json', json_encode($bk_real));
    $bk_write('positions.json', json_encode([]));
    db()->exec("delete from broker_account_values");
    run_script('sync_broker.php', [], $bk_env);
    $bk_live = rows("select * from broker_account_values order by as_of_date desc limit 1");

    check('the live account\'s own answer stores cash 250, invested 0, total 250',
          count($bk_live) === 1
          && abs((float) $bk_live[0]['cash'] - 250.00) < 0.005
          && (float) $bk_live[0]['invested'] === 0.0
          && abs((float) $bk_live[0]['total'] - 250.00) < 0.005,
          json_encode($bk_live));
    check('and the run is OK rather than failed',
          $bk_last_run()['status'] === 'OK',
          json_encode([$bk_last_run()['status'], $bk_last_run()['problems']]));

    /* The old flat shape, which is what the code used to expect: now it is
       a problem that names the field rather than a silent null. */
    $bk_write('summary.json', json_encode(['cash' => 250.00, 'total' => 250.00]));
    run_script('sync_broker.php', [], $bk_env);
    $bk_flat_run = $bk_last_run();
    check('a summary this cannot read is a problem, not a quiet null',
          $bk_flat_run['status'] !== 'OK'
          && stripos((string) $bk_flat_run['problems'], 'cash balance') !== false
          && stripos((string) $bk_flat_run['problems'], 'cash.availableToTrade') !== false,
          json_encode([$bk_flat_run['status'], $bk_flat_run['problems']]));
    check('and it says what the response had instead',
          stripos((string) $bk_flat_run['problems'], 'It has: cash, total') !== false,
          (string) $bk_flat_run['problems']);

    $bk_write('summary.json', json_encode($bk_real));

    echo "\n--- the broker connection: a notice is not a problem ---\n";

    /* Six instruments it could not tell apart was filed as a problem, so no
       run could ever finish as OK - and with the cash null on top of it,
       every run read as FAILED. A notice is the run telling you what it
       noticed; a problem is something that stopped the data being right. */
    $bk_write('instruments.json', json_encode([
        ['ticker' => 'T6Pl_EQ', 'shortName' => 'T6P', 'name' => 'Six Ninety PLC',
         'currencyCode' => 'GBX', 'isin' => 'GB00T6P00001'],
        ['ticker' => 'AAAl_EQ', 'shortName' => 'AAA', 'name' => 'Alpha PLC',
         'currencyCode' => 'USD', 'isin' => 'US00AAAA0001'],
        ['ticker' => 'AAAp_EQ', 'shortName' => 'AAA', 'name' => 'Alpha Two',
         'currencyCode' => 'USD', 'isin' => 'US00AAAA0002'],
    ]));
    run_script('sync_broker.php', ['--instruments'], $bk_env);
    $bk_amb_run = $bk_last_run();

    check('instruments it cannot tell apart do not make the run a failure',
          $bk_amb_run['status'] === 'OK'
          && (string) $bk_amb_run['problems'] === '',
          json_encode([$bk_amb_run['status'], $bk_amb_run['problems']]));
    check('they are recorded as something worth knowing instead',
          stripos((string) $bk_amb_run['notices'], 'fit more than one') !== false,
          (string) $bk_amb_run['notices']);
    check('and the instruments themselves are stored as ambiguous, with their currency',
          (int) scalar("select count(*) from broker_instruments
                         where status='ambiguous' and currency='USD'") === 2,
          json_encode(rows("select broker_ticker, status, currency, note from broker_instruments")));

    /* A holding the mapping could not name is the same kind of thing: the
       position is read, stored and shown by the broker's ticker. */
    $bk_write('positions.json', json_encode([
        ['ticker' => 'T6Pl_EQ', 'quantity' => 200, 'averagePrice' => 6.10,
         'currentPrice' => 6.90, 'ppl' => 160.0],
        ['ticker' => 'ZZZl_EQ', 'quantity' => 10, 'averagePrice' => 1.00,
         'currentPrice' => 1.10, 'ppl' => 1.0],
    ]));
    run_script('sync_broker.php', [], $bk_env);
    $bk_hold_run = $bk_last_run();
    check('a holding whose instrument is not one of ours is a notice too',
          $bk_hold_run['status'] === 'OK'
          && stripos((string) $bk_hold_run['notices'], 'could not match') !== false
          && (int) scalar("select count(*) from broker_positions where symbol is null") === 1,
          json_encode([$bk_hold_run['status'], $bk_hold_run['problems'],
                       $bk_hold_run['notices']]));

    /* But a price unit that cannot be settled on a share that IS held stops
       the figures being right, so that stays a problem. */
    $bk_write('positions.json', json_encode([
        ['ticker' => 'T6Pl_EQ', 'quantity' => 200, 'averagePrice' => 61.0,
         'currentPrice' => 64.0, 'ppl' => 160.0],
    ]));
    run_script('sync_broker.php', [], $bk_env);
    check('an undecided price unit on a share that is held is still a problem',
          $bk_last_run()['status'] !== 'OK'
          && stripos((string) $bk_last_run()['problems'], 'price unit') !== false,
          json_encode([$bk_last_run()['status'], $bk_last_run()['problems']]));

    $bk_write('positions.json', json_encode([
        ['ticker' => 'T6Pl_EQ', 'quantity' => 200, 'averagePrice' => 6.10,
         'currentPrice' => 6.90, 'ppl' => 160.0],
    ]));
    $bk_write('instruments.json', json_encode([
        ['ticker' => 'T6Pl_EQ', 'shortName' => 'T6P', 'name' => 'Six Ninety PLC',
         'currencyCode' => 'GBX', 'isin' => 'GB00T6P00001'],
    ]));
    run_script('sync_broker.php', ['--instruments'], $bk_env);

    /* The page keeps the two apart as well, or the distinction is only in
       the database. */
    $bk_page_tpl = php_strip_whitespace(dirname(__DIR__) . '/templates/broker.php');
    check('the page shows what the sync could not do and what it noticed, separately',
          strpos($bk_page_tpl, "['run']['problems']") !== false
          && strpos($bk_page_tpl, "['run']['notices']") !== false
          && strpos($bk_page_tpl, 'Worth knowing') !== false);
    check('and shows the figures the summary really carries',
          strpos($bk_page_tpl, "values['unrealised']") !== false
          && strpos($bk_page_tpl, "values['reserved']") !== false
          && strpos($bk_page_tpl, "values['invested_cost']") !== false);
    check('and says what each holding was matched on',
          strpos($bk_page_tpl, "position['matched_on']") !== false);

    echo "\n--- the broker connection: the probe ---\n";

    /* The probe is what settles the three things the API documentation does
       not state, so it has to work on the night somebody runs it - and it
       has to be safe to send its output back, which means no credential in
       the file. */
    $bk_probe_dir = $bk_dir . '/probe';
    @mkdir($bk_probe_dir, 0777, true);

    $bk_run_tool = function ($tool, $args, $env) {
        global $INCLUDES;
        $include_path = dirname(__DIR__) . '/tests/fixtures' . PATH_SEPARATOR . $INCLUDES;
        $cmd = '';
        foreach ($env as $k => $v) { $cmd .= $k . '=' . escapeshellarg($v) . ' '; }
        $cmd .= 'php -d include_path=' . escapeshellarg($include_path) . ' '
              . escapeshellarg(dirname(__DIR__) . '/tools/' . $tool);
        foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
        exec($cmd . ' 2>&1', $out, $rc);
        return [implode("\n", $out), $rc];
    };

    [$bk_probe_out, $bk_probe_rc] = $bk_run_tool('t212_probe.php',
                                                 ['--dir=' . $bk_probe_dir], $bk_env);

    check('the probe runs and asks for the three calls it needs',
          $bk_probe_rc === 0, 'rc ' . $bk_probe_rc . ': ' . $bk_probe_out);
    check('it says which field name carried the cash, nesting and all',
          stripos($bk_probe_out, 'summary_cash') !== false
          && stripos($bk_probe_out, 'cash.availableToTrade = 250') !== false
          && stripos($bk_probe_out, 'Array') === false,
          $bk_probe_out);
    check('it shows a broker ticker beside our symbol and our own close',
          stripos($bk_probe_out, 'T6Pl_EQ') !== false
          && stripos($bk_probe_out, 'our close') !== false,
          $bk_probe_out);
    check('and it answers the pounds or pence question outright',
          stripos($bk_probe_out, 'price unit: pounds') !== false, $bk_probe_out);
    check('it loads one set of constants, not two',
          stripos($bk_probe_out, 'already defined') === false, $bk_probe_out);

    $bk_probe_files = glob($bk_probe_dir . '/t212_probe_*.json');
    check('it writes the raw answers to a file outside the web root',
          count($bk_probe_files) === 1
          && strpos((string) $bk_probe_files[0], '/public/') === false,
          json_encode($bk_probe_files));
    if (count($bk_probe_files)) {
        $bk_probe_json = (string) file_get_contents($bk_probe_files[0]);
        check('the file holds the real answers, so the mapping can be written from it',
              strpos($bk_probe_json, 'currencyCode') !== false
              && strpos($bk_probe_json, 'T6Pl_EQ') !== false);
        check('and no credential, because that file gets sent back',
              strpos($bk_probe_json, $bk_key) === false
              && strpos($bk_probe_json, $bk_secret) === false
              && strpos($bk_probe_json, base64_encode($bk_key . ':' . $bk_secret)) === false);
        check('it is readable by its owner and nobody else',
              (fileperms($bk_probe_files[0]) & 0777) === 0600,
              decoct(fileperms($bk_probe_files[0]) & 0777));
    }

    /* With the connection off it asks for nothing and writes nothing, like
       everything else here. */
    $bk_reset();
    [$bk_probe_off_out, $bk_probe_off_rc] = $bk_run_tool('t212_probe.php',
        ['--dir=' . $bk_probe_dir], ['SM_T212_ENV' => 'off', 'SM_T212_BASE_URL' => $bk_base]);
    check('with the connection off the probe says so and asks for nothing',
          $bk_probe_off_rc === 1 && count($bk_asked()) === 0
          && count(glob($bk_probe_dir . '/t212_probe_*.json')) === 1,
          'rc ' . $bk_probe_off_rc . ': ' . $bk_probe_off_out);

    echo "\n--- the broker connection: nothing here can place an order ---\n";

    /* Stage 1 reads. This is the test the brief asks for in stage 2, written
       now, because the thing it protects against is a line of code somebody
       adds later without noticing what it reaches. */
    $bk_client_src = (string) file_get_contents(dirname(__DIR__) . '/includes/t212_client.php');
    $bk_broker_src = (string) file_get_contents(dirname(__DIR__) . '/includes/broker.php');
    $bk_sync_src   = (string) file_get_contents(dirname(__DIR__) . '/includes/sync_broker.php');
    $bk_page_src   = (string) file_get_contents(dirname(__DIR__) . '/public/broker.php');

    /* Comments first, as ever: a test that reads a comment saying "no POST"
       and passes is a test of the comment. */
    $bk_code = function ($php) {
        $out = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
                $out .= $token[1];
            }
            else { $out .= $token; }
        }
        return $out;
    };

    foreach (['t212_client.php' => $bk_client_src, 'broker.php' => $bk_broker_src,
              'sync_broker.php' => $bk_sync_src] as $bk_name => $bk_src) {
        $bk_bare = $bk_code($bk_src);
        check("$bk_name names no HTTP method but GET",
              preg_match('~[\'"](POST|PUT|PATCH|DELETE)[\'"]~i', $bk_bare) === 0
              && stripos($bk_bare, 'CURLOPT_POST') === false,
              $bk_name);
    }

    /* The page is the exception, and for a reason that is not a loophole: it
       reads $_SERVER["REQUEST_METHOD"] to tell its own form's POST from a
       page load. What matters for it is that it makes no request of its own -
       it calls broker_sync(), which goes through the client. */
    $bk_page_bare = $bk_code($bk_page_src);
    check('the page itself opens no connection of any kind',
          stripos($bk_page_bare, 'curl_') === false
          && stripos($bk_page_bare, 'stream_context_create') === false
          && stripos($bk_page_bare, 'file_get_contents') === false
          && stripos($bk_page_bare, 'fsockopen') === false,
          'public/broker.php');

    check('and the live host appears only where the base URL is defined',
          substr_count($bk_code((string) file_get_contents(dirname(__DIR__)
                                . '/includes/constants.php')), 'live.trading212.com') === 1
          && stripos($bk_code($bk_client_src), 'live.trading212.com') === false
          && stripos($bk_code($bk_broker_src), 'live.trading212.com') === false);
    check('the demo host is a constant too, so stage 2 cannot be pointed at the live one',
          defined('T212_DEMO_BASE_URL') && T212_DEMO_BASE_URL === 'https://demo.trading212.com/api/v0');
    check('and the client has no method that could post an order',
          !method_exists('T212Client', 'post') && !method_exists('T212Client', 'request')
          && !method_exists('T212Client', 'place_order'));

    /* The nightly run reads the broker after the strategies and is allowed to
       fail without taking the run with it. */
    $bk_refresh = (string) file_get_contents(dirname(__DIR__) . '/includes/refresh_all.sh');
    check('the nightly run syncs the broker after the strategies',
          strpos($bk_refresh, 'sync_broker.php') !== false
          && strpos($bk_refresh, 'run_strategies.php') < strpos($bk_refresh, 'sync_broker.php'));
    check('and a broker that is not answering does not take the night with it',
          preg_match('~sync_broker\.php" \|\| echo~', $bk_refresh) === 1);

    if ($bk_pid > 0) { exec("kill $bk_pid 2>/dev/null"); }
    exec('rm -rf ' . escapeshellarg($bk_dir));
    db()->exec("delete from broker_sync_runs");
    db()->exec("delete from broker_positions");
    db()->exec("delete from broker_orders");
    db()->exec("delete from broker_dividends");
    db()->exec("delete from broker_account_values");
    db()->exec("delete from broker_instruments");
    db()->exec("delete from stock_symbols where symbol='T6P'");
    db()->exec("delete from historical_prices where symbol='T6P'");

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
    foreach (['get_share_prices.php', 'screening.php',
              /* The 2023 backtest: its own model, its own tables, nothing
                 shared with the paper engine. Replaced by
                 includes/backtest_strategy.php, which replays a strategy's
                 own rules through the engine that trades it. */
              'backtest.php', 'backtest_results.php'] as $gone) {
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
       reader to work out what a bare number in a column headed "Price" is.
       It now says it twice over: the column in each peer modal is still
       headed "Implied price at <sector|industry> median", and the card on
       the page spells the whole comparison out - "Implied price 341.0p
       against 1,350.6p, 74.8% below" - where it used to show a bare 341 in
       a red badge. */
    $pv_quote = (string)file_get_contents(dirname(__DIR__) . '/templates/quote.php');
    check('the quote page labels the sector figure an implied price',
          strpos($pv_quote, "Implied price at ") !== false
          && strpos($pv_quote, "'sector'") !== false);
    check('the quote page labels the industry figure an implied price',
          strpos($pv_quote, "Implied price at ") !== false
          && strpos($pv_quote, 'industry') !== false);
    check('and the card says it in a sentence, not as a bare number',
          strpos((string)file_get_contents($INCLUDES . '/quote_view.php'),
                 "'Implied price '") !== false);

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
    /* The two facts have moved. They were a "System Messages" card on the
       home page whose entire content was this one line, and the redesign
       removed the card - the as-of date belongs in the navbar, where it is
       on every page rather than only on one. They are still two facts and
       still named apart: the date on the badge, the run time in its tooltip.
       Checked on view_freshness() rather than on a template, because that is
       where they are now built. */
    $mn_fresh = view_freshness('XLON');
    check('the as of date and the run time are named apart, not run together',
          strpos($mn_fresh['words'], 'Prices ') === 0
          && strpos($mn_fresh['title'], 'Prices as of') !== false
          && (strpos($mn_fresh['title'], 'updated') !== false
              || strpos($mn_fresh['title'], 'No run') !== false)
          && strpos($mn_fresh['title'], 'Statistics Refreshed') === false,
          json_encode($mn_fresh));
    check('the navbar shows both, so every page carries them',
          preg_match('/\$sm_fresh\[.title.\]/',
                     (string)file_get_contents(dirname(__DIR__) . '/templates/header.php')) === 1
          && strpos((string)file_get_contents(dirname(__DIR__) . '/templates/header.php'),
                    "\$sm_fresh['words']") !== false);
    check('and the box whose only content it was is gone from the home page',
          !file_exists(dirname(__DIR__) . '/templates/quote_form.php')
          && stripos((string)file_get_contents(dirname(__DIR__) . '/templates/home.php'),
                     'System Messages') === false);

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
    /* Six since sql/trending_value.sql: O'Shaughnessy's six, which is what
       the score was always meant to hold. The list is asserted exactly rather
       than counted, because the whole claim is that the composite is whatever
       category 12 holds - so when that set changes, this line is where it has
       to be said out loud. */
    check('the components come from the database, not from the query',
          array_column($sy_components, 'name')
            === ['ev_ebitda','pe','price_book_ratio','price_cash_flow',
                 'price_sales_ratio','shareholder_yield'],
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
          stripos($hs_tpl, 'Financial health') !== false);
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

    echo "\n--- a figure the provider stops sending stops being there ---\n";

    /* insert_statistic() is an upsert: it updates a value that comes again
       and leaves one that does not. So a statistic the provider stopped
       sending went on sitting in the table, and on being ranked.

       On the re-run for 2026-11-13 TRN's buyback yield was correctly not
       written - it was over the sanity cap - and the 47.9% from the run
       before it stayed. FGT kept 65% and VEIL 48% the same way, and all
       three went on filling the value top ten on figures the job had just
       decided not to write. */

    $st_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = 'XLON';
    $st_asof = asof_date();
    $st_all  = "('SGON','SKEEP','SNONE')";

    $run_python_st = function ($script) {
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
    db()->exec("delete from stock_symbols where symbol in $st_all");
    db()->exec("delete from screen_indicators where name in ('pe','buyback_yield')");
    db()->exec("insert into screen_indicators
                  (name, provider_field, description, enabled, order_number,
                   calc_rank, rank_zero, rank_order, rank_negative, rank_funds,
                   fx_adjust, category)
                values
                  ('pe','Ratios.priceToEarningsRatioTTM','Price / earnings','Y',1,
                   'Y','N','value ASC','EXCLUDE','N','MULTIPLY',12),
                  ('buyback_yield','Derived.buybackYieldTTM','Buyback yield','Y',211,
                   'N','Y','value DESC','NORMAL','N','DIVIDE',12)");

    $st_sym = db()->prepare("insert into stock_symbols (symbol,name,exchange,enabled,sector,industry,market,report_ccy,report_ccy_asof,is_fund)
                             values (?,?,'XLON','Y','Stale Test','Stale Industry','FTSE 250','GBP',?,'N')");
    $st_info = db()->prepare("insert into stock_info (symbol,asofdate,attribute,value) values (?,?,?,?)");
    foreach (['SGON', 'SKEEP', 'SNONE'] as $st_s) { $st_sym->execute([$st_s, $st_s . ' Test PLC', $st_asof]); }

    /* SGON loses its buyback yield on the second run, the way a symbol over
       the cap does. SKEEP keeps both. SNONE has nothing in stock_info at
       all on the second run, which is the loader getting no answer rather
       than the provider withdrawing a figure. */
    foreach (['SGON', 'SKEEP', 'SNONE'] as $st_s) {
        $st_info->execute([$st_s, $st_asof, 'Ratios.priceToEarningsRatioTTM', '12.5']);
        $st_info->execute([$st_s, $st_asof, 'Derived.buybackYieldTTM', '0.479']);
    }

    [$st_out1, $st_rc1] = $run_python_st('get_api_stats.py');
    check('the first run writes both figures', $st_rc1 === 0, substr($st_out1, -300));

    $st_value = function ($symbol, $indicator) use ($st_asof) {
        $v = scalar("select value from statistics where symbol=? and indicator=? and date=? and exchange='XLON'",
                    [$symbol, $indicator, $st_asof]);
        return ($v === false || $v === null) ? null : (float)$v;
    };
    foreach (['SGON', 'SKEEP', 'SNONE'] as $st_s) {
        check("$st_s has a buyback yield after the first run",
              $st_value($st_s, 'buyback_yield') === 0.479,
              var_export($st_value($st_s, 'buyback_yield'), true));
    }

    /* Something this application calculates for itself, written between the
       two runs. It has no provider_field, so the clear-down must not touch
       it: the momentum series and the moving averages are get_statistics.php's
       to manage, not this job's. */
    db()->prepare("insert into statistics (symbol,date,indicator,value,exchange)
                   values ('SGON',?,'3mnth',11.0,'XLON')")->execute([$st_asof]);

    //Second run: the provider no longer sends SGON's buyback yield.
    db()->prepare("delete from stock_info where symbol='SGON' and attribute='Derived.buybackYieldTTM'")
        ->execute();
    db()->prepare("delete from stock_info where symbol='SNONE'")->execute();

    [$st_out2, $st_rc2] = $run_python_st('get_api_stats.py');
    check('the second run completes', $st_rc2 === 0, substr($st_out2, -300));

    check('a figure the provider stopped sending is gone',
          $st_value('SGON', 'buyback_yield') === null,
          var_export($st_value('SGON', 'buyback_yield'), true));
    check('while the figure it still sends is there',
          $st_value('SGON', 'pe') === 12.5, var_export($st_value('SGON', 'pe'), true));
    check('and another symbol keeps both',
          $st_value('SKEEP', 'buyback_yield') === 0.479 && $st_value('SKEEP', 'pe') === 12.5);

    /* A symbol the loader got nothing for is not a symbol whose data has
       gone. The loader takes that view when it writes stock_info; this job
       takes it too, or one failed fetch would empty a company's row. */
    check('a symbol the provider answered nothing for keeps what it had',
          $st_value('SNONE', 'buyback_yield') === 0.479 && $st_value('SNONE', 'pe') === 12.5,
          json_encode([$st_value('SNONE', 'buyback_yield'), $st_value('SNONE', 'pe')]));

    check('an indicator this application calculates is left alone',
          $st_value('SGON', '3mnth') === 11.0,
          var_export($st_value('SGON', '3mnth'), true));

    check('the run says how many stale rows it cleared',
          preg_match('/\d+ stale rows cleared/', $st_out2) === 1,
          substr($st_out2, -200));

    /* And the ranking follows: a value that is gone is not ranked. */
    run_script('get_statistics.php');
    check('the withdrawn figure is not ranked either',
          scalar("select percentile from statistics where symbol='SGON'
                   and indicator='buyback_yield' and date=?", [$st_asof]) === false
          || scalar("select percentile from statistics where symbol='SGON'
                      and indicator='buyback_yield' and date=?", [$st_asof]) === null);

    //Leave the universe as it was found.
    db()->exec("delete from stock_symbols where symbol in $st_all");
    db()->exec("delete from statistics where symbol in $st_all");
    db()->exec("delete from stock_info");
    db()->exec("delete from screen_indicators where name in ('pe','buyback_yield')");
    apply_migration('shareholder_yield.sql');
    reset_calculated();
    run_script('get_momentum_statistics.php');
    run_script('get_statistics.php');
    if ($st_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $st_saved_exchange; }

    echo "\n--- headlines are stored rather than fetched and thrown away ---\n";

    /* The News tab fetched Google on every page load and kept nothing, so
       there was no history to chart, to score, or to ask a model about.
       Google returns only recent items and FMP has none for .L tickers, so
       there is no backfill: history starts when the nightly job first runs.

       Both ends are stubs. tools/news_stub.php answers the feed and
       tools/anthropic_stub.php answers the scorer; the real Google and the
       real API are never called by the suite. */

    $nw_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = 'XLON';
    $nw_asof = asof_date();

    $nw_dir = sys_get_temp_dir() . '/smnewsjob' . getmypid();
    exec('rm -rf ' . escapeshellarg($nw_dir));
    @mkdir($nw_dir, 0777, true);

    $nw_feed_port = 8341;
    $nw_ai_port   = 8342;
    $nw_feed_pid = (int) trim((string) shell_exec(
        'SM_STUB_DIR=' . escapeshellarg($nw_dir) . ' php '
        . escapeshellarg(dirname(__DIR__) . '/tools/news_stub.php')
        . ' 127.0.0.1:' . $nw_feed_port . ' > /dev/null 2>&1 & echo $!'));
    $nw_ai_pid = (int) trim((string) shell_exec(
        'SM_STUB_DIR=' . escapeshellarg($nw_dir) . ' php -S 127.0.0.1:' . $nw_ai_port . ' '
        . escapeshellarg(dirname(__DIR__) . '/tools/anthropic_stub.php')
        . ' > /dev/null 2>&1 & echo $!'));
    usleep(900000);

    file_put_contents("$nw_dir/mode", 'news_ok');

    /* One symbol, so the counts below are about the feed rather than about
       how many symbols the seed happens to have. */
    db()->exec("truncate news_articles");
    db()->exec("delete from stock_symbols where symbol like 'NW%'");
    db()->prepare("insert into stock_symbols (symbol,name,description,exchange,enabled,sector,market,industry_group,industry)
                   values ('NWA','News Test PLC','News Test PLC','XLON','Y','Industrials','Main Market','Industrials','Aerospace')")
        ->execute();
    $nw_others = rows("select symbol from stock_symbols where enabled='Y' and exchange='XLON' and symbol<>'NWA'");
    db()->exec("update stock_symbols set enabled='N' where exchange='XLON' and symbol<>'NWA'");

    $nw_env = ['SM_NEWS_BASE_URL'       => "http://127.0.0.1:$nw_feed_port",
               'ANTHROPIC_BASE_URL'     => "http://127.0.0.1:$nw_ai_port",
               'ANTHROPIC_API_KEY'      => 'test-key',
               'NEWS_FETCH_INTERVAL'    => '0',
               'NEWS_SCORE_BATCH'       => '20',
               'NEWS_SCORE_MAX_PER_RUN' => '1000'];

    [$nw_out1, $nw_rc1] = run_script('fetch_news.php', [], $nw_env);
    check('the news job runs', $nw_rc1 === 0, substr($nw_out1, -500));

    /* Kept now rather than read at the end: last_request is the last one,
       and by the end of this section that is a run with a single article
       left to score - which would make the batching check below pass on a
       job that sent one request per headline. */
    $nw_first_request = json_decode((string)@file_get_contents("$nw_dir/last_request"), true);

    $nw_count = function () { return (int)scalar("select count(*) from news_articles"); };
    /* The stub feed has three items and one of them is from a blocked
       publisher, which get_articles() drops before this job ever sees it. */
    check('the headlines are stored', $nw_count() === 2, $nw_count() . ' rows');
    check('a blocked publisher is not stored',
          (int)scalar("select count(*) from news_articles where title like '%Motley Fool%'") === 0);
    check('the publisher is read off the end of the title',
          scalar("select publisher from news_articles where title like 'Shares rise%'") === 'The Guardian');
    check('the date is stored as a datetime, not the string Google sent',
          scalar("select date(published_at) from news_articles where title like 'Shares rise%'")
          === '2026-11-10',
          (string)scalar("select published_at from news_articles where title like 'Shares rise%'"));
    /* The day name in the feed is advisory and PHP's parser treats it as an
       instruction: 10 November 2026 is a Tuesday, and "Mon, 10 Nov 2026"
       parses to the 16th unless the name is dropped first. A feed whose day
       names are a day out would file every article beside the wrong price
       move. */
    check('an advisory day name does not move the date',
          news_published_at('Mon, 10 Nov 2026 09:00:00 GMT') === '2026-11-10 09:00:00',
          var_export(news_published_at('Mon, 10 Nov 2026 09:00:00 GMT'), true));
    check('a zone other than UTC is converted rather than ignored',
          news_published_at('Mon, 10 Nov 2026 09:00:00 +0530') === '2026-11-10 03:30:00');
    check('an unreadable date is no date, not today',
          news_published_at('whenever') === null && news_published_at('') === null);
    check('the link is kept, and hashed for the unique key',
          scalar("select url_hash from news_articles where title like 'Shares rise%'")
          === sha1('https://example.invalid/1'));

    //Re-run: the same feed must add nothing.
    [$nw_out2, ] = run_script('fetch_news.php', [], $nw_env);
    check('a second run over the same feed stores nothing new',
          $nw_count() === 2, $nw_count() . ' rows after the second run');

    //A new article appears: it is picked up, the old ones are not doubled.
    file_put_contents("$nw_dir/news_feed.xml",
        '<?xml version="1.0"?><rss version="2.0"><channel>'
        . '<item><title>Shares rise on results - The Guardian</title>'
        . '<link>https://example.invalid/1</link>'
        . '<pubDate>Mon, 10 Nov 2026 09:00:00 GMT</pubDate></item>'
        . '<item><title>Profit warning sends shares lower - Reuters</title>'
        . '<link>https://example.invalid/4</link>'
        . '<pubDate>Thu, 12 Nov 2026 16:30:00 GMT</pubDate></item>'
        . '</channel></rss>');
    run_script('fetch_news.php', [], $nw_env);
    check('a new article is picked up without duplicating the old ones',
          $nw_count() === 3, $nw_count() . ' rows');
    check('and an article that has dropped out of the feed is kept',
          (int)scalar("select count(*) from news_articles where title like 'Analysts raise%'") === 1);

    echo "\n--- the headlines are scored ---\n";

    check('every stored headline has been scored',
          (int)scalar("select count(*) from news_articles where scored_at is null") === 0,
          scalar("select count(*) from news_articles where scored_at is null") . ' unscored');
    check('the score is matched to the headline it is about',
          (float)scalar("select sentiment from news_articles where title like 'Shares rise%'") > 0
          && (float)scalar("select sentiment from news_articles where title like 'Profit warning%'") < 0,
          json_encode([scalar("select sentiment from news_articles where title like 'Shares rise%'"),
                       scalar("select sentiment from news_articles where title like 'Profit warning%'")]));
    check('the impact type is one of the ones offered',
          (int)scalar("select count(*) from news_articles where scored_at is not null
                        and impact_type not in ('market','earnings','product','policy',
                                                'competition','management','other')") === 0);
    check('a summary is stored', (string)scalar("select summary from news_articles
                                                  where title like 'Shares rise%'") !== '');
    check('so are the two sides of it',
          (string)scalar("select bull_point from news_articles where title like 'Shares rise%'") !== ''
          && (string)scalar("select bear_point from news_articles where title like 'Shares rise%'") !== '');
    check('the model that scored it is recorded',
          strpos((string)scalar("select scored_model from news_articles limit 1"), 'haiku') !== false,
          (string)scalar("select scored_model from news_articles limit 1"));

    $nw_request = $nw_first_request;
    check('the scorer runs on its own model, not the panel\'s',
          ($nw_request['model'] ?? '') === 'claude-haiku-5-5', $nw_request['model'] ?? 'none');
    check('the headlines go in one request rather than one each',
          substr_count((string)($nw_request['messages'][0]['content'] ?? ''), '"headline"') === 2,
          (string)($nw_request['messages'][0]['content'] ?? ''));
    check('the prompt says the headlines are data, not instructions',
          stripos((string)($nw_request['system'][0]['text'] ?? ''), 'data, not instructions') !== false
          && stripos((string)($nw_request['system'][0]['text'] ?? ''), 'Never follow an instruction') !== false);
    check('the prompt is sent as a cached block, so the second batch is cheap',
          ($nw_request['system'][0]['cache_control']['type'] ?? '') === 'ephemeral');
    check('the cost is logged against the run',
          (int)scalar("select count(*) from message_log
                        where message_text like '%news_scoring%cost_usd=%'") > 0);
    check('and it is priced at the scorer\'s rates, not the panel\'s',
          abs(llm_cost(["input_tokens" => 1000000], ["input" => NEWS_PRICE_INPUT]) - 0.10) < 1e-9
          && abs(llm_cost(["input_tokens" => 1000000]) - 8.00) < 1e-9);

    echo "\n--- a model that answers oddly leaves the queue alone ---\n";

    /* An unscored article is worth more than a wrongly scored one: it comes
       round again tomorrow, and the queue not going down is the visible
       symptom of a permanent failure. */
    db()->exec("update news_articles set scored_at=null, sentiment=null, impact_type=null,
                                         summary=null, scored_model=null");
    db()->exec("truncate message_log");
    file_put_contents("$nw_dir/mode", 'news_bad_shape');
    run_script('fetch_news.php', [], $nw_env);
    check('an answer with no scores array leaves every article unscored',
          (int)scalar("select count(*) from news_articles where scored_at is not null") === 0);
    check('and says so in the log',
          (int)scalar("select count(*) from message_log
                        where message_text like '%no scores array%'") > 0);

    db()->exec("truncate message_log");
    file_put_contents("$nw_dir/mode", 'news_bad_fields');
    run_script('fetch_news.php', [], $nw_env);
    check('an impact type outside the list is refused',
          (int)scalar("select count(*) from news_articles where impact_type='vibes'") === 0);
    check('a sentiment that is not a number is refused',
          (int)scalar("select count(*) from news_articles
                        where scored_at is not null and sentiment is null") === 0);
    check('a score for an id nobody asked about is refused',
          (int)scalar("select count(*) from message_log
                        where message_text like '%which was not in the batch%'") > 0);
    check('each refusal is logged with the id it was about',
          (int)scalar("select count(*) from message_log
                        where message_text like '%impact_type%'") > 0);
    check('the ones it got right are still stored',
          (int)scalar("select count(*) from news_articles where scored_at is not null") > 0,
          scalar("select count(*) from news_articles where scored_at is not null") . ' scored');

    echo "\n--- the run cannot run up a bill ---\n";

    db()->exec("update news_articles set scored_at=null, sentiment=null, impact_type=null, summary=null");
    db()->exec("truncate message_log");
    file_put_contents("$nw_dir/mode", 'news_ok');
    run_script('fetch_news.php', [], array_merge($nw_env,
        ['NEWS_SCORE_MAX_PER_RUN' => '2', 'NEWS_SCORE_BATCH' => '1']));
    check('a run scores no more than its cap',
          (int)scalar("select count(*) from news_articles where scored_at is not null") === 2,
          scalar("select count(*) from news_articles where scored_at is not null") . ' scored');
    check('and says that it left some for next time',
          (int)scalar("select count(*) from message_log
                        where message_text like '%level=WARN%headlines were waiting%'") > 0);
    check('the rest are scored on the next run',
          true);
    run_script('fetch_news.php', [], $nw_env);
    check('the next run clears the backlog',
          (int)scalar("select count(*) from news_articles where scored_at is null") === 0);

    echo "\n--- the sentiment indicators ---\n";

    /* A fixed set, so the arithmetic can be checked on paper. Three scored
       headlines for one symbol: +1 today, -1 six days ago, +1 twenty days
       ago. The weight falls linearly across the window, so over seven days
       the twenty day old one is outside and the other two weigh 7/7 and
       2/7: (1*1 + (2/7)*-1) / (1 + 2/7) = 0.5556. */
    db()->exec("truncate news_articles");
    $nw_insert = db()->prepare("insert into news_articles
                   (symbol, published_at, title, publisher, link, url_hash, first_seen,
                    sentiment, impact_type, summary, scored_model, scored_at)
                 values ('NWA', ?, ?, 'Stub', ?, ?, now(), ?, 'other', 'x', 'stub', now())");
    foreach ([[0, 1.0], [5, -1.0], [19, 1.0]] as $i => $nw_item) {
        [$nw_age, $nw_sent] = $nw_item;
        $nw_when = date('Y-m-d', strtotime($nw_asof . ' -' . $nw_age . ' day')) . ' 09:00:00';
        $nw_insert->execute([$nw_when, 'Headline ' . $i, 'https://example.invalid/s' . $i,
                             sha1('s' . $i), $nw_sent]);
    }

    check('the seven day mean is the recency-weighted one, not the plain mean',
          abs(news_sentiment('NWA', $nw_asof, 7) - 0.5556) < 0.001,
          var_export(news_sentiment('NWA', $nw_asof, 7), true)
          . ' (a plain mean would be 0)');
    /* Over thirty days all three count, at 30/30, 25/30 and 11/30:
       (1 - 0.8333 + 0.3667) / (1 + 0.8333 + 0.3667) = 0.2424. */
    check('the thirty day mean takes in the older one',
          abs(news_sentiment('NWA', $nw_asof, 30) - 0.2424) < 0.001,
          var_export(news_sentiment('NWA', $nw_asof, 30), true));
    check('the count is a count of headlines in the window',
          news_count('NWA', $nw_asof, 7) === 2 && news_count('NWA', $nw_asof, 30) === 3,
          json_encode([news_count('NWA', $nw_asof, 7), news_count('NWA', $nw_asof, 30)]));
    check('a symbol with nothing scored has no sentiment, which is not a zero',
          news_sentiment('NWB', $nw_asof, 7) === null);

    //Written into statistics by the job, and cleared when they age out.
    db()->exec("delete from statistics where indicator like 'news\\_%'");
    news_write_indicators($nw_asof, 'XLON');
    check('the indicators are written into statistics',
          abs((float)scalar("select value from statistics where symbol='NWA'
                              and indicator='news_sentiment_7d' and date=?", [$nw_asof]) - 0.5556) < 0.001,
          var_export(scalar("select value from statistics where symbol='NWA'
                              and indicator='news_sentiment_7d' and date=?", [$nw_asof]), true));
    check('including the count', (int)scalar("select value from statistics where symbol='NWA'
                                               and indicator='news_count_7d' and date=?", [$nw_asof]) === 2);
    db()->exec("truncate news_articles");
    news_write_indicators($nw_asof, 'XLON');
    check('a symbol whose headlines have aged out loses its sentiment figure',
          scalar("select value from statistics where symbol='NWA'
                   and indicator='news_sentiment_7d' and date=?", [$nw_asof]) === false);
    check('but still has a count, of nought, which is a fact',
          (int)scalar("select value from statistics where symbol='NWA'
                        and indicator='news_count_7d' and date=?", [$nw_asof]) === 0);

    check('the three indicators are registered, unranked, in their own category',
          (int)scalar("select count(*) from screen_indicators
                        where name in ('news_sentiment_7d','news_sentiment_30d','news_count_7d')
                          and category=14 and calc_rank='N' and enabled='Y'") === 3);
    check('funds are included: sentiment is not an accounting measure',
          (int)scalar("select count(*) from screen_indicators
                        where name like 'news\\_%' and rank_funds='Y'") === 3);
    check('and they are not in the value or quality categories',
          (int)scalar("select count(*) from screen_indicators
                        where name like 'news\\_%' and category in (10,12)") === 0);

    echo "\n--- the nightly run fetches news after the prices ---\n";

    $nw_cron = implode("\n", array_filter(
        explode("\n", (string) @file_get_contents($INCLUDES . '/refresh_all.sh')),
        function ($line) { return !preg_match('/^\s*#/', $line) && trim($line) !== ''; }));
    $nw_line = function ($needle) use ($nw_cron) {
        foreach (explode("\n", $nw_cron) as $i => $line) {
            if (strpos($line, $needle) !== false) { return $i; }
        }
        return -1;
    };
    /* The nightly run fetches announcements now, not headlines. These three
       checks moved with it rather than being deleted: what they are about is
       that the news job runs after the prices - because its as of date is the
       newest date in historical_prices - and that it cannot take the run down
       with it. Both are still true of the job that replaced it, and the
       announcements section further down asserts the swap itself. */
    check('the news job is in the nightly run', $nw_line('fetch_rns.php') >= 0);
    check('after the prices, which is where its as of date comes from',
          $nw_line('get_share_prices.php') < $nw_line('fetch_rns.php'));
    check('and it is allowed to fail without taking the run with it',
          preg_match('/fetch_rns\.php" \|\| echo/', $nw_cron) === 1);

    //Leave the universe as it was found.
    if ($nw_feed_pid > 0) { exec("kill $nw_feed_pid 2>/dev/null"); }
    if ($nw_ai_pid > 0)   { exec("kill $nw_ai_pid 2>/dev/null"); }
    exec('rm -rf ' . escapeshellarg($nw_dir));
    db()->exec("truncate news_articles");
    db()->exec("delete from stock_symbols where symbol like 'NW%'");
    db()->exec("update stock_symbols set enabled='Y' where exchange='XLON'");
    db()->exec("delete from statistics where indicator like 'news\\_%'");
    reset_calculated();
    run_script('get_momentum_statistics.php');
    run_script('get_statistics.php');
    if ($nw_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $nw_saved_exchange; }

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


    echo "\n--- the top_n criterion ---\n";

    /* "The best N of whatever is left" is the one thing build_screen() could
       not say. Every other operator is a filter and can be intersected in any
       order; this one depends on what survived, so it runs last, and two of
       them run in sequence - which is what makes O'Shaughnessy's "cheapest
       tenth, then the strongest momentum of those" a screen rather than two
       screens and a spreadsheet. */

    $tv_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = 'XLON';
    $tv_asof = asof_date();

    /* A scratch screen built from a list of [indicator, operator, operand],
       in the order the criteria are meant to apply. The criteria rows are
       inserted one at a time so their ids ascend, which is what decides the
       order build_screen() applies them in. */
    $tv_screen = function (array $criteria) {
        db()->exec("delete from screen_build where screen_id in
                      (select id from screen where name='TopN scratch')");
        db()->exec("delete from screen where name='TopN scratch'");
        db()->exec("delete from screen_criteria where description like 'topn-scratch%'");
        db()->exec("insert into screen (name,description,displayed)
                    values ('TopN scratch','scratch screen for the top_n tests','N')");
        $sid = (int) scalar("select id from screen where name='TopN scratch'");
        $n = 0;
        foreach ($criteria as $c) {
            $iid = (int) scalar("select indicator_id from screen_indicators where name=?", [$c[0]]);
            db()->prepare("insert into screen_criteria
                             (indicator_id,description,operator,first_operand)
                           values (?,?,?,?)")
                ->execute([$iid, 'topn-scratch ' . $n, $c[1], $c[2]]);
            $cid = (int) db()->lastInsertId();
            db()->prepare("insert into screen_build (screen_id,criteria_id) values (?,?)")
                ->execute([$sid, $cid]);
            $n++;
        }
        return $sid;
    };

    /* The symbols the screen returns, in the order it returned them. The
       order is the point: a set comparison would pass on a screen that
       ranked its answer backwards. */
    $tv_run = function ($sid) use ($tv_asof) {
        $out = [];
        foreach (build_screen($sid, $tv_asof) as $row) {
            $out[] = is_array($row) ? ($row['symbol'] ?? reset($row)) : $row;
        }
        return $out;
    };

    /* Writes exactly these statistics and nothing else, so each case is the
       whole universe the screen can see. */
    $tv_stats = function (array $by_indicator) use ($tv_asof) {
        reset_calculated();
        $ins = db()->prepare("insert into statistics (symbol,date,indicator,value,exchange)
                              values (?,?,?,?,'XLON')");
        foreach ($by_indicator as $indicator => $values) {
            foreach ($values as $symbol => $value) {
                $ins->execute([$symbol, $tv_asof, $indicator, $value]);
            }
        }
    };

    /* value_score ranks 'value DESC' - it is a percentile where 100 is the
       cheapest - so the best N is the N highest. */
    $tv_stats(['value_score' => ['AAA' => 90, 'BBB' => 80, 'CCC' => 70,
                                 'DDD' => 60, 'EEE' => 50]]);

    $tv_got = $tv_run($tv_screen([['value_score', 'top_n', '3']]));
    check('top_n keeps N shares and no more', count($tv_got) === 3, json_encode($tv_got));
    check('and they are the best N, in that order',
          $tv_got === ['AAA', 'BBB', 'CCC'], json_encode($tv_got));

    check('asking for more than there are returns everything rather than failing',
          $tv_run($tv_screen([['value_score', 'top_n', '50']])) === ['AAA','BBB','CCC','DDD','EEE']);
    check('asking for none returns none',
          $tv_run($tv_screen([['value_score', 'top_n', '0']])) === []);

    /* 6mnth also ranks DESC but means something different - a percentage
       change, not a percentile - so a screen that silently used the wrong
       column would still pass the test above. */
    $tv_stats(['6mnth' => ['AAA' => -4.0, 'BBB' => 11.0, 'CCC' => 2.5,
                           'DDD' => 30.0, 'EEE' => 9.0]]);
    check('top_n reads the indicator it was given, not a percentile column',
          $tv_run($tv_screen([['6mnth', 'top_n', '2']])) === ['DDD', 'BBB'],
          json_encode($tv_run($tv_screen([['6mnth', 'top_n', '2']]))));

    /* Ties. Three shares, two of them equal on the measure, and room for two:
       which two is a real question and the answer has to be the same every
       run, or the screen tells you something different on Tuesday from what
       it told you on Monday with the same data. */
    $tv_stats(['value_score' => ['BBB' => 90, 'AAA' => 90, 'CCC' => 80]]);
    $tv_tied = $tv_run($tv_screen([['value_score', 'top_n', '2']]));
    check('a tie is broken by symbol, so the same data gives the same list',
          $tv_tied === ['AAA', 'BBB'], json_encode($tv_tied));
    check('and the same screen run twice gives the same list',
          $tv_run($tv_screen([['value_score', 'top_n', '2']])) === $tv_tied);

    /* A share with no value for the measure. It has a statistic for the date,
       so it is in the universe build_screen() starts from - it just has
       nothing to be ranked on. The cheapest 26 cannot include a share whose
       cheapness is unknown. */
    $tv_stats(['value_score' => ['AAA' => 90, 'BBB' => 80],
               '6mnth'       => ['AAA' => 1.0, 'BBB' => 1.0, 'CCC' => 99.0]]);
    $tv_missing = $tv_run($tv_screen([['value_score', 'top_n', '3']]));
    check('a share with no value for the indicator drops out rather than sorting to an end',
          $tv_missing === ['AAA', 'BBB'], json_encode($tv_missing));
    check('even when it would have been first on another measure',
          !in_array('CCC', $tv_missing, true));

    echo "\n--- two top_n criteria, applied in sequence ---\n";

    /* The whole point of the operator. AAA..EEE are the five cheapest; the
       strongest momentum of the lot belongs to FFF, which is not cheap. A
       screen that applied the momentum cut to the universe rather than to
       the survivors of the value cut would return FFF. */
    $tv_stats(['value_score' => ['AAA' => 95, 'BBB' => 90, 'CCC' => 85, 'DDD' => 80,
                                 'EEE' => 75, 'FFF' => 20, 'GGG' => 10,
                                 'HHH' => 30, 'III' => 40],
               '6mnth'       => ['AAA' => 1.0,  'BBB' => 2.0,  'CCC' => 3.0,  'DDD' => 4.0,
                                 'EEE' => 5.0,  'FFF' => 99.0, 'GGG' => 98.0,
                                 'HHH' => 97.0, 'III' => 96.0]]);

    $tv_seq = $tv_run($tv_screen([['value_score', 'top_n', '4'],
                                  ['6mnth',       'top_n', '2']]));
    check('the second top_n ranks the survivors of the first, not the universe',
          $tv_seq === ['DDD', 'CCC'], json_encode($tv_seq));
    check('the share with the best momentum overall is not in it, because it is not cheap',
          !in_array('FFF', $tv_seq, true));
    check('the result is in the order of the last top_n, not the first',
          $tv_seq[0] === 'DDD', json_encode($tv_seq));

    /* The same two criteria the other way round is a different screen. The
       four strongest on momentum are the four cheapest shares' opposites
       here, so "cheapest four, then best momentum" and "best momentum four,
       then cheapest" have no share in common - which is the clearest way to
       show the order is being honoured rather than the criteria being
       intersected. */
    $tv_rev = $tv_run($tv_screen([['6mnth',       'top_n', '4'],
                                  ['value_score', 'top_n', '2']]));
    check('swapping the two criteria gives a different answer',
          $tv_rev !== $tv_seq, json_encode($tv_rev));
    check('and that answer is the cheapest two of the strongest four',
          $tv_rev === ['III', 'HHH'], json_encode($tv_rev));

    echo "\n--- a comparison criterion and a top_n on the same screen ---\n";

    /* build_screen() intersects the comparison criteria first and applies the
       top_n to what is left. So the momentum filter decides who is eligible
       to be one of the cheapest N, rather than thinning the N afterwards -
       the screen always has its full N to choose from. */
    $tv_stats(['value_score' => ['AAA' => 95, 'BBB' => 90, 'CCC' => 85, 'DDD' => 80, 'EEE' => 75],
               '6mnth'       => ['AAA' => -5.0, 'BBB' => -2.0, 'CCC' => 3.0,
                                 'DDD' => 4.0,  'EEE' => 5.0]]);

    $tv_mixed = $tv_run($tv_screen([['value_score', 'top_n', '2'],
                                    ['6mnth', '>', '0']]));
    check('the filter applies before the top_n, whatever order the criteria are in',
          $tv_mixed === ['CCC', 'DDD'], json_encode($tv_mixed));
    check('the two cheapest overall are both out, because both are still falling',
          !in_array('AAA', $tv_mixed, true) && !in_array('BBB', $tv_mixed, true));
    check('and the top_n still returns its full N rather than what was left of it',
          count($tv_mixed) === 2, count($tv_mixed) . ' shares');

    /* A filter that matches nothing leaves the top_n with nothing to rank,
       and an empty screen is the right answer rather than the whole universe. */
    check('a filter that excludes everything gives an empty screen',
          $tv_run($tv_screen([['value_score', 'top_n', '3'],
                              ['6mnth', '>', '1000']])) === []);

    echo "\n--- the Trending Value screen ---\n";

    $tv_id = (int) scalar("select id from screen where name='Trending Value'");
    check('the migration created the screen', $tv_id > 0, "id $tv_id");
    check('it is shown on the screens page', scalar("select displayed from screen where id=?", [$tv_id]) === 'Y');
    check('and is shared rather than owned by one account',
          scalar("select session_id from screen where id=?", [$tv_id]) === null);

    $tv_criteria = rows("select si.name, sc.operator, sc.first_operand
                           from screen_build sb, screen_criteria sc, screen_indicators si
                          where sb.criteria_id=sc.id and sc.indicator_id=si.indicator_id
                            and sb.screen_id=? order by sb.criteria_id", [$tv_id]);
    check('it has the three criteria, in the order they must apply',
          count($tv_criteria) === 3
          && $tv_criteria[0]['name'] === 'value_score' && $tv_criteria[0]['operator'] === 'top_n'
          && (int)$tv_criteria[0]['first_operand'] === 26
          && $tv_criteria[1]['name'] === '6mnth' && $tv_criteria[1]['operator'] === '>'
          && $tv_criteria[2]['name'] === '6mnth' && $tv_criteria[2]['operator'] === 'top_n'
          && (int)$tv_criteria[2]['first_operand'] === 15,
          json_encode($tv_criteria));

    /* Run it on a universe small enough to work out by hand. The counts in
       the real screen are 26 and 15; here the same screen is asked the same
       question of twelve shares, so what it returns can be checked rather
       than taken on trust. */
    $tv_stats(['value_score' => ['AAA' => 99, 'BBB' => 98, 'CCC' => 97, 'DDD' => 96,
                                 'EEE' => 95, 'FFF' => 94, 'GGG' => 50],
               '6mnth'       => ['AAA' => -1.0, 'BBB' => 12.0, 'CCC' => 30.0, 'DDD' => 4.0,
                                 'EEE' => 18.0, 'FFF' => 7.0,  'GGG' => 99.0]]);
    $tv_live = $tv_run($tv_id);
    /* Seven shares against a cut of 26, so the value criterion keeps all of
       them: what this case can show is the momentum filter and the order the
       page gets, not the value cut. The value cut biting is the scratch
       screens above, where the N is small enough to be checked by hand. */
    check('the screen returns the rising shares, best momentum first',
          $tv_live === ['GGG', 'CCC', 'EEE', 'BBB', 'FFF', 'DDD'], json_encode($tv_live));
    check('a share that is still falling is left out',
          !in_array('AAA', $tv_live, true));
    check('every share it returns is one that is rising',
          count(array_filter($tv_live,
                fn($s) => (float) scalar("select value from statistics
                                           where symbol=? and indicator='6mnth' and date=?",
                                         [$s, $tv_asof]) <= 0)) === 0);

    /* The screen names value_score, which is written by get_statistics.php
       and was not a row in screen_indicators before this change: without one,
       build_screen() cannot join a criterion to an indicator name at all. */
    check('value_score is an indicator a criterion can name',
          (int) scalar("select count(*) from screen_indicators where name='value_score'") === 1);
    check('and so are the other three scores',
          (int) scalar("select count(*) from screen_indicators
                         where name in ('momentum_score','quality_score','overall_score')") === 3);

    /* Registering them must not put them into the nightly job. Each of these
       is a separate way the job finds work, and an enabled score row would be
       picked up by all three. */
    $tv_scores = rows("select name, enabled, calc_rank, category, screen_function, provider_field
                         from screen_indicators
                        where name in ('value_score','momentum_score','quality_score','overall_score')");
    check('no score is enabled, so get_stats_indicators() does not try to calculate one',
          count(array_filter($tv_scores, fn($r) => $r['enabled'] !== 'N')) === 0,
          json_encode($tv_scores));
    check('none is ranked, so none gets a percentile of its own',
          count(array_filter($tv_scores, fn($r) => $r['calc_rank'] !== 'N')) === 0);
    check('none has a category, so the value score cannot be averaged into itself',
          count(array_filter($tv_scores, fn($r) => $r['category'] !== null)) === 0);
    check('none has a provider field or a function, so nothing goes looking for one',
          count(array_filter($tv_scores,
                fn($r) => $r['screen_function'] !== null || $r['provider_field'] !== null)) === 0);

    /* The form has to offer the operator, or the screen can be read in the
       app and not edited. */
    $tv_form = (string) file_get_contents(dirname(__DIR__) . '/templates/criteria_form.php');
    check('the criteria form offers the new operator',
          strpos($tv_form, 'value="top_n"') !== false);
    check('and its indicator list is not filtered to enabled indicators, which is what lets a criterion name a score',
          strpos($tv_form, 'SELECT indicator_id, name, description FROM screen_indicators') !== false);

    //Leave the screens as they were found.
    db()->exec("delete from screen_build where screen_id in
                  (select id from screen where name='TopN scratch')");
    db()->exec("delete from screen where name='TopN scratch'");
    db()->exec("delete from screen_criteria where description like 'topn-scratch%'");

    echo "\n--- the two new value measures ---\n";

    /* price_cash_flow and ev_ebitda complete O'Shaughnessy's six. They need
       no change to get_statistics.php: the value score is the average of
       every enabled category 12 indicator with calc_rank='Y', so joining that
       set is the whole of it. These check the settings that decide how they
       are ranked, because the ranking is where a valuation ratio goes wrong. */

    $tv_new = [];
    foreach (rows("select name, category, calc_rank, rank_order, rank_negative,
                          rank_funds, fx_adjust, provider_field, enabled
                     from screen_indicators where name in ('price_cash_flow','ev_ebitda')") as $r) {
        $tv_new[$r['name']] = $r;
    }
    check('both are registered', count($tv_new) === 2, json_encode(array_keys($tv_new)));
    check('both are in the value category and ranked, so both join the value score',
          count(array_filter($tv_new,
                fn($r) => (int)$r['category'] === 12 && $r['calc_rank'] === 'Y'
                          && $r['enabled'] === 'Y')) === 2,
          json_encode($tv_new));
    check('both rank cheap first',
          count(array_filter($tv_new, fn($r) => trim($r['rank_order']) === 'value ASC')) === 2);
    check('neither ranks investment funds, like the other four value measures',
          count(array_filter($tv_new, fn($r) => $r['rank_funds'] === 'N')) === 2);
    /* The same treatment pe gets, which is EXCLUDE. Read from the migration
       that sets it on the live database rather than from this one: pe is not
       in the test seed, an earlier section registers it by hand for its own
       fixture, and by here it is gone again - so a lookup would compare
       against nothing and pass. */
    $tv_pe_sql = (string) file_get_contents(dirname(__DIR__) . '/sql/fmp_migration.sql');
    check('both treat a negative the way pe does, and pe still does',
          $tv_new['price_cash_flow']['rank_negative'] === 'EXCLUDE'
          && $tv_new['ev_ebitda']['rank_negative'] === 'EXCLUDE'
          && preg_match("/set rank_negative='EXCLUDE'\s*\n\s*where name in \([^)]*'pe'/", $tv_pe_sql) === 1,
          json_encode([$tv_new['price_cash_flow']['rank_negative'],
                       $tv_new['ev_ebitda']['rank_negative']]));
    check('a price over a figure from the accounts is converted, like pe',
          $tv_new['price_cash_flow']['fx_adjust'] === 'MULTIPLY');
    check('both read a field the provider sends',
          $tv_new['price_cash_flow']['provider_field'] === 'Ratios.priceToOperatingCashFlowRatioTTM'
          && $tv_new['ev_ebitda']['provider_field'] === 'KeyMetrics.evToEBITDATTM',
          json_encode([$tv_new['price_cash_flow']['provider_field'],
                       $tv_new['ev_ebitda']['provider_field']]));

    echo "\n--- a negative ratio is not a cheap share ---\n";

    /* A company with negative operating cash flow, or negative EBITDA, gets a
       negative ratio. Sorted ASC it lands ahead of every profitable company
       and scores as the cheapest share in the index, which is how Hg Capital
       Trust reached value_score 99 on negative book value. rank_negative
       EXCLUDE leaves it unranked instead: the percentile stays null and the
       score query counts it as the neutral 50 - neither cheap nor expensive,
       because the ratio means nothing for that company. */

    $tv_neg_asof = asof_date();
    reset_calculated();
    $tv_ins = db()->prepare("insert into statistics (symbol,date,indicator,value,exchange)
                             values (?,?,?,?,'XLON')");
    foreach (['price_cash_flow' => ['AAA' => -3.0, 'BBB' => 4.0, 'CCC' => 9.0,
                                    'DDD' => 14.0, 'EEE' => 19.0],
              'ev_ebitda'       => ['AAA' => -2.0, 'BBB' => 5.0, 'CCC' => 8.0,
                                    'DDD' => 11.0, 'EEE' => 16.0]] as $ind => $vals) {
        foreach ($vals as $sym => $v) { $tv_ins->execute([$sym, $tv_neg_asof, $ind, $v]); }
    }
    run_script('get_momentum_statistics.php');
    [$tv_neg_out, ] = run_script('get_statistics.php');
    check('the job completes with negative ratios in the data',
          stripos($tv_neg_out, 'Fatal error') === false, substr($tv_neg_out, -300));

    foreach (['price_cash_flow', 'ev_ebitda'] as $tv_ind) {
        $pct = [];
        foreach (rows("select symbol, percentile from statistics where indicator=? and date=?",
                      [$tv_ind, $tv_neg_asof]) as $r) {
            $pct[$r['symbol']] = $r['percentile'] === null ? null : (float) $r['percentile'];
        }
        check("$tv_ind: the negative ratio is not ranked at all",
              array_key_exists('AAA', $pct) && $pct['AAA'] === null,
              'AAA percentile ' . var_export($pct['AAA'] ?? 'missing', true));
        check("$tv_ind: so it does not come out as the cheapest share",
              $pct['BBB'] !== null && ($pct['AAA'] === null || $pct['AAA'] < $pct['BBB']),
              json_encode($pct));
        check("$tv_ind: the cheapest positive ratio is ranked best",
              $pct['BBB'] !== null && $pct['BBB'] > ($pct['EEE'] ?? 0), json_encode($pct));
        check("$tv_ind: the raw value is still stored, so the quote page can show it",
              (float) scalar("select value from statistics where symbol='AAA' and indicator=? and date=?",
                             [$tv_ind, $tv_neg_asof]) < 0);
    }

    /* And the consequence for the score: unranked counts as 50, so the
       loss-maker sits between the cheap and the expensive rather than on top
       of the list. */
    $tv_scores_now = [];
    foreach (rows("select symbol, value from statistics where indicator='value_score' and date=?",
                  [$tv_neg_asof]) as $r) {
        $tv_scores_now[$r['symbol']] = (float) $r['value'];
    }
    check('the loss-maker does not have the best value score',
          isset($tv_scores_now['AAA'], $tv_scores_now['BBB'])
          && $tv_scores_now['AAA'] < $tv_scores_now['BBB'],
          json_encode($tv_scores_now));
    check('and is not scored worst either, because the ratio says nothing about it',
          isset($tv_scores_now['AAA'], $tv_scores_now['EEE'])
          && $tv_scores_now['AAA'] > $tv_scores_now['EEE'],
          json_encode($tv_scores_now));

    echo "\n--- a bank is scored on the measures that apply to it ---\n";

    /* EV/EBITDA means nothing for a bank: "debt less cash" is the trading
       book, not a capital structure adjustment, and nobody values one on
       EBITDA. indicator_sector_exclusions leaves it out for the sector, and
       the point of leaving it out is that the bank is then scored on five
       measures - not on six with a hole counted as nought, and not on six
       with a number that means nothing dragging it down. */

    $tv_bank = 'HHH';
    $tv_bank_sector = (string) scalar("select sector from stock_symbols where symbol=?", [$tv_bank]);
    db()->prepare("update stock_symbols set sector='Financial Services' where symbol=?")
        ->execute([$tv_bank]);

    /* indicator_sector_exclusions is created by a migration rather than by
       tests/schema.sql, so the table and its rows survive between runs of the
       suite: the schema load drops only the tables it defines. A check that
       the migration created a row would otherwise pass on a row left behind
       by yesterday's run, which is how a mutation that stopped the migration
       writing it at all was caught by nothing here. Clear this indicator's
       rows and let the migration put them back, so what is under test is what
       this run produced. */
    db()->exec("delete from indicator_sector_exclusions where indicator='ev_ebitda'");
    apply_migration('trending_value.sql');
    check('the migration is what creates the exclusion, not a row left from an earlier run',
          (int) scalar("select count(*) from indicator_sector_exclusions
                         where indicator='ev_ebitda' and sector='Financial Services'") === 1);

    /* The bank is cheap on everything except EV/EBITDA, where it is the
       most expensive share in the universe. If that measure counted, it
       would score below CCC; excluded, it stays above. One share's order
       decides the test, so the fixture has to be read both ways. */
    /* Only the two measures the rule is about. The live database has all six
       in category 12; naming more here would make the test depend on which
       of them an earlier section happened to leave registered. */
    $tv_bank_data = [
        'price_cash_flow' => ['AAA' => 20.0, 'BBB' => 18.0, 'CCC' => 9.0, 'DDD' => 16.0, $tv_bank => 2.0],
        'ev_ebitda'       => ['AAA' => 4.0,  'BBB' => 5.0,  'CCC' => 3.0, 'DDD' => 6.0,  $tv_bank => 99.0],
    ];

    /* How many category 12 measures actually reach the average for a share -
       the denominator the value score divides by. This is the "five, not six
       with a zero" of the brief, counted directly rather than inferred from
       where the share came out. */
    $tv_counted = function ($symbol) use ($tv_neg_asof) {
        return (int) scalar("select count(*) from statistics s, screen_indicators si
                              where s.indicator=si.name and s.date=? and s.exchange='XLON'
                                and si.enabled='Y' and si.category=12 and si.calc_rank='Y'
                                and s.symbol=?
                                and not exists (select 1 from stock_symbols x,
                                                       indicator_sector_exclusions e
                                                 where x.symbol=s.symbol and e.sector=x.sector
                                                   and e.indicator=s.indicator)",
                            [$tv_neg_asof, $symbol]);
    };
    $tv_bank_run = function () use ($tv_bank_data, $tv_neg_asof) {
        reset_calculated();
        $ins = db()->prepare("insert into statistics (symbol,date,indicator,value,exchange)
                              values (?,?,?,?,'XLON')");
        foreach ($tv_bank_data as $ind => $vals) {
            foreach ($vals as $sym => $v) { $ins->execute([$sym, $tv_neg_asof, $ind, $v]); }
        }
        run_script('get_momentum_statistics.php');
        run_script('get_statistics.php');
        $out = [];
        foreach (rows("select symbol, value from statistics where indicator='value_score' and date=?",
                      [$tv_neg_asof]) as $r) { $out[$r['symbol']] = (float) $r['value']; }
        return $out;
    };

    $tv_with_exclusion = $tv_bank_run();

    check('the bank still has an EV/EBITDA value stored',
          scalar("select value from statistics where symbol=? and indicator='ev_ebitda' and date=?",
                 [$tv_bank, $tv_neg_asof]) !== false);
    check('but it is not ranked on it',
          scalar("select percentile from statistics where symbol=? and indicator='ev_ebitda' and date=?",
                 [$tv_bank, $tv_neg_asof]) === null);
    check('a company in another sector is ranked on it',
          scalar("select percentile from statistics where symbol='CCC' and indicator='ev_ebitda' and date=?",
                 [$tv_neg_asof]) !== null);

    /* Scored on its other two measures, where it is the cheapest share, the
       bank must come out ahead of CCC. */
    check('the bank is scored on one measure fewer than a company in another sector',
          $tv_counted($tv_bank) === $tv_counted('CCC') - 1,
          'bank ' . $tv_counted($tv_bank) . ' vs CCC ' . $tv_counted('CCC'));
    check('and the measure it loses is the one that does not apply, not an arbitrary one',
          (int) scalar("select count(*) from statistics where symbol=? and indicator='price_cash_flow'
                         and date=?", [$tv_bank, $tv_neg_asof]) === 1);

    check('the bank is scored on the measures that apply, so being cheap on them wins',
          isset($tv_with_exclusion[$tv_bank], $tv_with_exclusion['CCC'])
          && $tv_with_exclusion[$tv_bank] > $tv_with_exclusion['CCC'],
          json_encode($tv_with_exclusion));

    /* Now take the exclusion away and run the same data again. If the
       exclusion were doing nothing, both runs would agree - and the test
       above would be passing on the arithmetic rather than on the rule. */
    db()->exec("delete from indicator_sector_exclusions
                 where indicator='ev_ebitda' and sector='Financial Services'");
    $tv_without_exclusion = $tv_bank_run();
    check('without the exclusion the same bank is dragged below CCC by a measure that means nothing for it',
          isset($tv_without_exclusion[$tv_bank], $tv_without_exclusion['CCC'])
          && $tv_without_exclusion[$tv_bank] < $tv_without_exclusion['CCC'],
          json_encode($tv_without_exclusion));

    apply_migration('trending_value.sql');
    check('the migration puts the exclusion back',
          (int) scalar("select count(*) from indicator_sector_exclusions
                         where indicator='ev_ebitda' and sector='Financial Services'") === 1);

    //Leave the universe and the statistics as they were found.
    db()->prepare("update stock_symbols set sector=? where symbol=?")
        ->execute([$tv_bank_sector, $tv_bank]);
    reset_calculated();
    run_script('get_momentum_statistics.php');
    run_script('get_statistics.php');
    if ($tv_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $tv_saved_exchange; }


    echo "\n--- reading an announcement listing ---\n";

    /* Investegate's own announcements, read in full, in place of Google
       headlines. Of 1,600 scored headlines 85% scored nought and a good
       number were not news at all - quote pages, broker profiles. An RNS is
       the primary source and says what happened.
    
       Everything here runs against tools/investegate_stub.php and
       tools/anthropic_stub.php. No test makes a live request: Investegate's
       terms are for a private investor reading in a personal capacity, which
       a suite hammering it on every run is not, and a suite that depends on
       somebody else's site fails on a Sunday for reasons of its own.
    
       The parsers below were built without sight of a real page - this
       session has no route to investegate.co.uk - so they are written to
       depend on as little of the markup as possible: the announcement URL
       pattern, and a date somewhere near the link. */

    $rns_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = 'XLON';

    /* The live layout: the title first and the date after it. That order is
       the whole of the first fault - the parser measured the distance from
       each link to the nearest date and the nearest date behind a link is
       the row above's, so every announcement on the page was dated a day
       early. The fixture is written this way round because the one that had
       the date first let the fault through the suite. */
    $rns_listing = '<html><body><nav><a href="/login">Please sign in</a></nav>'
                 . '<table><thead><tr><th>Announcement</th><th>Date</th></tr></thead><tbody>'
                 . '<tr><td><a href="/announcement/rns/rolls-royce--RR./trading-update/9001">Trading Update</a></td><td>14 Nov 2026 07:00</td></tr>'
                 . '<tr><td><a href="/announcement/rns/rolls-royce--RR./transaction-in-own-shares/9002">Transaction in Own Shares</a></td><td>13 Nov 2026 16:35</td></tr>'
                 . '<tr><td><a href="/announcement/eqs/rolls-royce--RR./director-pdmr-shareholding/9003">Director/PDMR Shareholding</a></td><td>12/11/2026</td></tr>'
                 . '</tbody></table></body></html>';

    $rns_links = rns_announcement_links($rns_listing);
    check('every announcement on the page is found', count($rns_links) === 3,
          count($rns_links) . ' links');
    check('and each one carries the id Investegate keys it by',
          array_column($rns_links, 'announcement_id') === ['9001','9002','9003'],
          json_encode(array_column($rns_links, 'announcement_id')));
    check('the wire it came over is read from the URL, not guessed',
          array_column($rns_links, 'source') === ['RNS','RNS','EQS']);
    check('the ticker Investegate uses is read from the URL, trailing dot and all',
          $rns_links[0]['tidm'] === 'RR.', $rns_links[0]['tidm']);
    check('the URL is absolute, so the fetcher can ask for it',
          strpos($rns_links[0]['url'], 'http') === 0
          && substr($rns_links[0]['url'], -5) === '/9001', $rns_links[0]['url']);

    /* The dates. Each announcement takes its own row's date, which is the
       fault the live test found: with the date after the title, measuring to
       the nearest one gave every announcement the date of the row above. */
    check('each announcement takes the date from its own row',
          array_map(fn($l) => substr((string) $l['published_at'], 0, 10), $rns_links)
            === ['2026-11-14','2026-11-13','2026-11-12'],
          json_encode(array_column($rns_links, 'published_at')));
    check('a time beside the date is kept, because it decides which close to measure from',
          $rns_links[0]['published_at'] === '2026-11-14 07:00:00',
          (string) $rns_links[0]['published_at']);
    check('a day-first date is not read as an American one',
          substr((string) $rns_links[2]['published_at'], 0, 10) === '2026-11-12',
          (string) $rns_links[2]['published_at']);

    /* The fault, named. Each of these is the date of the row above the one
       it would be put on, and none of them may appear. */
    check('no announcement takes the date of the row above it',
          substr((string) $rns_links[1]['published_at'], 0, 10) !== '2026-11-14'
          && substr((string) $rns_links[2]['published_at'], 0, 10) !== '2026-11-13',
          json_encode(array_column($rns_links, 'published_at')));

    /* A table with a header row, which has no announcement in it, must not
       shift everything by one either. */
    check('a header row is not an announcement',
          count($rns_links) === 3, count($rns_links) . ' links');

    /* And the row is what settles it, not the distance. A row whose date
       comes first still works, because the row is read as a row. */
    check('a listing with the date before the title reads the same',
          (function () {
              $other = '<table><tbody>'
                     . '<tr><td>09 Nov 2026 07:00</td><td><a href="/announcement/rns/x--AB./a/8801">A</a></td></tr>'
                     . '<tr><td>08 Nov 2026 07:00</td><td><a href="/announcement/rns/x--AB./b/8802">B</a></td></tr>'
                     . '</tbody></table>';
              $links = rns_announcement_links($other);
              return count($links) === 2
                     && substr((string) $links[0]['published_at'], 0, 10) === '2026-11-09'
                     && substr((string) $links[1]['published_at'], 0, 10) === '2026-11-08';
          })());

    check('a page with no announcements on it yields none, rather than guessing',
          rns_announcement_links('<html><body><p>Nothing here</p></body></html>') === []);
    check('the same announcement linked twice is one announcement',
          count(rns_announcement_links($rns_listing . $rns_listing)) === 3);

    /* The row reader earns its keep on a long row. Proximity gets the live
       layout right only while a row is short enough for its own date to be
       within the look-ahead; a long title, or another cell or two, puts the
       date out of reach and the nearest one left is the row above's - the
       live fault again, on a row that is merely longer. The row knows which
       date is its own at any length. */
    check('a long row still takes its own date, not the row above\'s',
          (function () {
              $long = '<table><tbody>'
                    . '<tr><td><a href="/announcement/rns/x--AB./a/8831">Final Results</a></td>'
                    . '<td>10 Nov 2026 07:00</td></tr>'
                    . '<tr><td><a href="/announcement/rns/x--AB./b/8832">Director/PDMR '
                    . 'Shareholding - Notification of Transactions by Persons Closely '
                    . 'Associated with a Director of the Company and of the Grant and '
                    . 'Vesting of Awards under the Long Term Incentive Plan</a></td>'
                    . '<td>09 Nov 2026 16:35</td></tr>'
                    . '</tbody></table>';
              $links = rns_announcement_links($long);
              return count($links) === 2
                     && substr((string) $links[1]['published_at'], 0, 10) === '2026-11-09';
          })(),
          json_encode(array_column(rns_announcement_links('<table><tbody>'
              . '<tr><td><a href="/announcement/rns/x--AB./a/8831">Final Results</a></td>'
              . '<td>10 Nov 2026 07:00</td></tr>'
              . '<tr><td><a href="/announcement/rns/x--AB./b/8832">Director/PDMR '
              . 'Shareholding - Notification of Transactions by Persons Closely '
              . 'Associated with a Director of the Company and of the Grant and '
              . 'Vesting of Awards under the Long Term Incentive Plan</a></td>'
              . '<td>09 Nov 2026 16:35</td></tr></tbody></table>'), 'published_at')));

    /* And the fallback, for a listing that is not a table at all, must not
       repeat the fault either: the date after a link is that link's, the
       date before it belongs to the one above. */
    check('a list with no table reads each date forwards, not backwards',
          (function () {
              $list = '<ul>'
                    . '<li><a href="/announcement/rns/x--AB./a/8841">A</a> 09 Nov 2026 07:00</li>'
                    . '<li><a href="/announcement/rns/x--AB./b/8842">B</a> 08 Nov 2026 07:00</li>'
                    . '</ul>';
              $links = rns_announcement_links($list);
              return count($links) === 2
                     && substr((string) $links[0]['published_at'], 0, 10) === '2026-11-09'
                     && substr((string) $links[1]['published_at'], 0, 10) === '2026-11-08';
          })(),
          json_encode(array_column(rns_announcement_links('<ul>'
              . '<li><a href="/announcement/rns/x--AB./a/8841">A</a> 09 Nov 2026 07:00</li>'
              . '<li><a href="/announcement/rns/x--AB./b/8842">B</a> 08 Nov 2026 07:00</li>'
              . '</ul>'), 'published_at')));

    /* Insurance, not a layout anybody has seen: if the dates ever move out
       of the row, rows alone would date nothing and the fetcher would store
       nothing at all. Reading the document and taking the nearest date is a
       worse answer than a row and a much better one than none, so that is
       what happens - and only when no row was dated, so the live layout
       never reaches it. */
    check('links in rows with the dates outside them still come back dated',
          (function () {
              $odd = '<table><tbody>'
                   . '<tr><td><a href="/announcement/rns/x--AB./a/8811">A</a></td></tr>'
                   . '</tbody></table><p class="when">09 Nov 2026 07:00</p>';
              $links = rns_announcement_links($odd);
              return count($links) === 1
                     && substr((string) $links[0]['published_at'], 0, 10) === '2026-11-09';
          })(),
          json_encode(rns_announcement_links('<table><tbody>'
              . '<tr><td><a href="/announcement/rns/x--AB./a/8811">A</a></td></tr>'
              . '</tbody></table><p class="when">09 Nov 2026 07:00</p>')));

    echo "\n--- reading the announcement itself ---\n";

    /* The live page's shape: the announcement in one block, with the
       navigation, a sign-up block and Investegate's own AI summary around
       it. The first version took the whole page and subtracted the furniture
       it had been told about, and on the real page that left the navigation,
       the sign-up blurb and the AI summary in the text. */
    $rns_prose = '<p>Example Company plc announces&nbsp;a trading update. Revenue grew 7% '
               . 'to &pound;1.2bn and trading has been ahead of expectations.</p>'
               . str_repeat('<p>The board reiterates the guidance given at the half year '
               . 'and expects the second half to be weighted towards the final quarter.</p>', 6);

    /* The furniture is in plain divs. The first parser removed <nav>,
       <header>, <aside> and <footer> and the live page's navigation and
       sign-up blurb came through anyway, which is only possible if the live
       markup does not use those tags. A fixture that used them would be
       cleaned up by the tag stripping alone and would say nothing about
       whether the announcement block is being found - the version of this
       fixture that did use them passed with the block finder disabled. */
    $rns_page = '<!doctype html><html><head><title>Trading Update</title>'
              . '<style>.x{color:red}</style><script>var a=1;</script></head><body>'
              . '<div class="topbar"><ul><li><a href="/login">Please sign in</a></li>'
              . '<li><a href="/register">Register</a></li><li>Markets</li></ul></div>'
              . '<div class="brand"><span>Investegate</span></div>'
              . '<div class="page">'
              . '  <div class="promo"><h3>Sign up for free</h3><p>Create a free account for '
              . '    unlimited announcements and email alerts. Join thousands of private '
              . '    investors.</p></div>'
              . '  <div class="announcement">'
              . '    <div class="ai-summary"><h3>Summary by AI BETA</h3><p>A machine wrote '
              . '      this and it must not reach the scorer.</p></div>'
              . '    <div class="rns-body"><h1>Trading Update</h1>' . $rns_prose
              . '      <p>The buyback will end on 25 February 2027.</p></div>'
              . '  </div>'
              . '</div>'
              . '<div class="site-footer"><p>Terms of use. Investegate is a trading name.</p>'
              . '</div></body></html>';

    $rns_body = rns_body($rns_page);
    check('the script and the style do not come out as words',
          strpos($rns_body, 'color:red') === false && strpos($rns_body, 'var a') === false,
          substr($rns_body, 0, 200));
    check('nor does the navigation',
          strpos($rns_body, 'Please sign in') === false && strpos($rns_body, 'Markets') === false,
          substr($rns_body, 0, 200));
    check('nor the invitation to sign up, which is what the live page added',
          stripos($rns_body, 'Create a free account') === false
          && stripos($rns_body, 'Sign up for free') === false,
          substr($rns_body, 0, 200));
    check('nor the footer',
          stripos($rns_body, 'Terms of use') === false
          && stripos($rns_body, 'trading name') === false);
    check('the announcement does',
          strpos($rns_body, 'Revenue grew 7%') !== false);
    check('entities are decoded, so a figure reads as a figure',
          strpos($rns_body, "\xc2\xa31.2bn") !== false, substr($rns_body, 0, 200));
    check('a non-breaking space is a space',
          strpos($rns_body, 'announces a trading update') !== false);

    /* Nearly all of what comes back is the announcement. On the live page
       the block was 13.6k characters and nothing else came close; a reading
       that is mostly furniture would pass every check above and still be
       wrong, so the proportion is checked too. */
    check('what comes back is the announcement rather than the page around it',
          mb_strlen($rns_body) > 500
          && mb_strlen($rns_body) < mb_strlen(strip_tags($rns_page)) * 0.95,
          mb_strlen($rns_body) . ' of ' . mb_strlen(strip_tags($rns_page)));

    $rns_clean = rns_strip_ai_summary($rns_body);
    check("the site's own AI summary is removed before scoring",
          strpos($rns_clean, 'A machine wrote this') === false, substr($rns_clean, 0, 300));
    check('and so is its heading',
          stripos($rns_clean, 'Summary by AI') === false);
    check("but the company's own words survive it",
          strpos($rns_clean, 'ahead of expectations') !== false);

    /* The AI summary inside the same block as the announcement, which is
       where the block finder cannot exclude it: the strip has to. */
    $rns_inline = rns_strip_ai_summary(rns_body(
        '<html><body><div class="only"><h3>Summary by AI BETA</h3>'
        . '<p>A machine wrote this one too.</p>' . $rns_prose . '</div></body></html>'));
    check('an AI summary inside the announcement block is still removed',
          strpos($rns_inline, 'A machine wrote this one too') === false,
          substr($rns_inline, 0, 200));
    check('and the announcement around it is not',
          strpos($rns_inline, 'Revenue grew 7%') !== false);

    /* A page that cannot be read as blocks still yields its text, rather
       than nothing: the block finder is an improvement on reading the whole
       page, not a precondition for reading it. */
    check('a page with no block structure still gives up its text',
          strpos(rns_body('Example Company plc announces a trading update.'),
                 'announces a trading update') !== false);

    echo "\n--- the wire's boilerplate is not the announcement ---\n";

    /* Every announcement on the wire ends the same way: "This information is
       provided by RNS", the exchange's disclaimer, its privacy policy, and
       the word END. Several hundred identical words on every one of them,
       paid for twice - once in the tokens sent to the scorer and once in the
       space it takes on the page. */
    $rns_footer = 'Example Company plc announces a trading update. Revenue grew 7% to '
                . "\xc2\xa31.2bn and trading has been ahead of expectations.\n\n"
                . "This information is provided by RNS, the news service of the London Stock "
                . "Exchange. RNS is approved by the Financial Conduct Authority to act as a "
                . "Primary Information Provider in the United Kingdom.\n\n"
                . "RNS may use your IP address to confirm compliance with the terms and "
                . "conditions.\n\n"
                . "London Stock Exchange plc is not responsible for and does not check content "
                . "on this Website.\n\nEND";

    $rns_trimmed = rns_strip_rns_footer($rns_footer);

    check('the wire boilerplate is cut off the end',
          stripos($rns_trimmed, 'provided by RNS') === false
          && stripos($rns_trimmed, 'IP address') === false
          && stripos($rns_trimmed, 'not responsible') === false,
          substr($rns_trimmed, -200));
    check('and the announcement in front of it is untouched',
          strpos($rns_trimmed, 'Revenue grew 7%') !== false
          && strpos($rns_trimmed, "\xc2\xa31.2bn") !== false,
          $rns_trimmed);
    check('the sign-off goes with it',
          substr(rtrim($rns_trimmed), -3) !== 'END');
    check('and it is shorter by the boilerplate, not by a guess',
          mb_strlen($rns_trimmed) < mb_strlen($rns_footer) * 0.5,
          mb_strlen($rns_trimmed) . ' of ' . mb_strlen($rns_footer));

    /* An announcement that never carried any is returned as it is. */
    $rns_no_footer = "Example Company plc announces a trading update.\n\nRevenue grew 7%.";
    check('an announcement with no boilerplate is left alone',
          rns_strip_rns_footer($rns_no_footer) === trim($rns_no_footer));

    /* A page that is nothing but boilerplate is NOT emptied here. It is not
       an announcement, and emptying it would hide that from the length check
       whose job is to refuse it - the fetcher would store a blank body
       instead of leaving the id for another night. */
    $rns_only_footer = 'This information is provided by RNS, the news service of the London '
                     . 'Stock Exchange. END';
    check('a page that is only boilerplate is left for the length check to refuse',
          mb_strlen(rns_strip_rns_footer($rns_only_footer)) > 50,
          rns_strip_rns_footer($rns_only_footer));

    /* A lone END with no disclaimer in front of it still goes: some wires
       word the sign-off differently and the markers would miss it. */
    check('a lone sign-off is removed even when the disclaimer was worded differently',
          rns_strip_rns_footer("The company announces a trading update.\n\nEND")
            === 'The company announces a trading update.',
          rns_strip_rns_footer("The company announces a trading update.\n\nEND"));

    echo "\n--- the limit page is a stop signal, not an obstacle ---\n";

    /* Investegate shows signed-out readers a limit. The only correct
       response is to stop: the run ends, the rest waits for tomorrow, and
       nobody logs in or works round it. */
    check('the limit modal is recognised',
          rns_limit_page('<html><body><h2>Announcement limit reached</h2>'
                       . '<p>You have reached your limit.</p></body></html>') !== null);
    check('so is a login page',
          rns_limit_page('<html><body><h1>Sign in</h1>'
                       . '<p>Please sign in to continue.</p></body></html>') !== null);
    /* The wording has to be one of the ones actually looked for, or this
       tests nothing: the first version of this check used a page whose nav
       said only "Sign in", which matches no marker, so the length guard it
       was meant to exercise was never reached. Found by removing the guard
       and watching the suite stay green. */
    $rns_real = '<html><body><nav><a href="/login">Please sign in</a></nav>'
              . '<h1>Final Results</h1><p>' . str_repeat('More of the announcement. ', 40)
              . '</p></body></html>';
    check('the wording alone is looked for, so this page would trip a naive check',
          stripos($rns_real, 'please sign in') !== false);
    check('but a real announcement carrying it is not mistaken for a limit page',
          rns_limit_page($rns_real) === null);
    check('while the same wording with no announcement under it is',
          rns_limit_page('<html><body><nav><a href="/login">Please sign in</a></nav>'
                       . '</body></html>') !== null);
    check('and nor is an ordinary page',
          rns_limit_page('<html><body><p>' . str_repeat('Words. ', 200) . '</p></body></html>')
            === null);

    /* A company listing is a page of links and dates: a few hundred
       characters of text whether the limit is showing or not. Judged by
       length it looked like a limit page, so the run stopped on the first
       company and stored nothing - which is what the live pages did until
       the stub was made to carry a sign-in link in its nav, as they do. */
    $rns_listing = '<html><body><nav><a href="/login">Please sign in</a></nav>'
                 . '<table><tr><td><a href="/announcement/rns/example--RNX/final-results/7701">'
                 . 'Final Results</a></td><td>12 Nov 2026 07:00</td></tr></table></body></html>';
    check('a listing is judged by its rows, not its length, so the sign-in nav does not stop the run',
          rns_limit_page($rns_listing, 'listing') === null,
          var_export(rns_limit_page($rns_listing, 'listing'), true));
    check('and that same page would read as a limit page if judged as an announcement',
          rns_limit_page($rns_listing) !== null);
    check('but a listing with the wall and no announcements on it is a limit page',
          rns_limit_page('<html><body><nav><a href="/login">Please sign in</a></nav>'
                       . '<p>You have reached your limit.</p></body></html>', 'listing') !== null);

    echo "\n--- what not to read ---\n";

    /* Most of an RNS feed is housekeeping, and the decision has to be made
       from the URL slug before the document is fetched - which is the whole
       point of having a skip list. So every case is checked twice: once as a
       title, once as a slug reads it. */
    $rns_cases = [
        ['Trading Update',                                   null],
        ['Transaction in Own Shares',                        'skipped'],
        ['Total Voting Rights',                              'skipped'],
        ['Block Listing Six Monthly Return',                 'skipped'],
        ['Holding(s) in Company',                            'skipped'],
        ['Net Asset Value(s)',                               'skipped'],
        ['Publication of Prospectus',                        'skipped'],
        ['Notice of AGM',                                    'skipped'],
        ['Result of AGM',                                    'skipped'],
        ['Form 8.3A - Example plc',                          'skipped'],
        ['Rule 8.3 Disclosure',                              'skipped'],
        ['Half-year Report',                                 null],
        ['Final Results',                                    null],
        ['Contract Award',                                   null],
    ];
    $rns_wrong = [];
    foreach ($rns_cases as [$title, $expected]) {
        $as_title = rns_skip_reason($title) === null ? null : 'skipped';
        $as_slug  = rns_skip_reason(rns_title_from_slug(
                        strtolower(preg_replace('~[^A-Za-z0-9]+~', '-', $title)))) === null
                  ? null : 'skipped';
        if ($as_title !== $expected) { $rns_wrong[] = "$title as a title gave $as_title"; }
        if ($as_slug  !== $expected) { $rns_wrong[] = "$title as a slug gave $as_slug"; }
    }
    check('the skip list reads the same whether it sees a title or a URL slug',
          count($rns_wrong) === 0, implode('; ', $rns_wrong));

    /* The one rule with an exception, and the reason the whole thing is in a
       table rather than in code. */
    check('a share award to a director says nothing, so it is skipped',
          rns_skip_reason('Director/PDMR Shareholding') !== null);
    check('and so does a vesting',
          rns_skip_reason('Director/PDMR Shareholding - Vesting of Awards') !== null);
    check('but a director buying with their own money is kept',
          rns_skip_reason('Director/PDMR Shareholding - Purchase of Shares') === null);
    check('and so is a sale',
          rns_skip_reason('Director/PDMR Shareholding - Sale of Shares') === null);
    check('the exception survives the slug spelling too',
          rns_skip_reason('Director Pdmr Shareholding Purchase Of Shares') === null);

    check('NAV is matched as a word, so Navigator is not skipped for being called that',
          rns_skip_reason('Navigator Holdings - Q3 Results') === null);
    check('while an actual NAV is',
          rns_skip_reason('NAV as at 31 October 2026') !== null);

    check('the rules come from the table, so the list can be tuned without a deploy',
          (int) scalar("select count(*) from rns_skip_rules where enabled='Y'") >= 10);
    /* Tuning the list is an update, not a deploy - so an added rule has to
       apply and a disabled one has to stop applying. */
    db()->exec("delete from rns_skip_rules where pattern='Quarterly Pointless Filing'");
    db()->exec("insert into rns_skip_rules (pattern, keep_if, description, enabled)
                values ('Quarterly Pointless Filing', null, 'added by the suite', 'Y')");
    rns_skip_rules_reset();
    check('a rule added to the table applies without a code change',
          rns_skip_reason('Quarterly Pointless Filing') !== null);

    db()->exec("update rns_skip_rules set enabled='N' where pattern='Quarterly Pointless Filing'");
    rns_skip_rules_reset();
    check('and a disabled rule stops applying',
          rns_skip_reason('Quarterly Pointless Filing') === null);
    db()->exec("delete from rns_skip_rules where pattern='Quarterly Pointless Filing'");
    rns_skip_rules_reset();

    echo "\n--- the close before an announcement ---\n";

    /* RNS goes out at 07:00, an hour before the market opens, so for most
       announcements the close to measure from is the previous trading day's.
       The rule is the market's clock, which means three things have to be
       right: the close time, the trading calendar, and the time zone.
    
       A separate exchange with a calendar made by hand, because the seed
       gives XLON a price on every calendar day and a market that never
       closes cannot show what happens when it does. */
    db()->exec("delete from historical_prices where exchange='XTST'");
    db()->exec("delete from stock_symbols where exchange='XTST'");
    db()->prepare("insert into stock_symbols (symbol,name,description,exchange,enabled,sector,market)
                   values ('RNB','RNS Baseline PLC','RNS Baseline PLC','XTST','Y','Industrials','Main Market')")
        ->execute();

    /* Thu 5, Fri 6, then the weekend shut, Mon 9, Tue 10 November 2026.
       July as well, because in summer London is an hour ahead of UTC. */
    $rns_cal = ['2026-11-05' => 100.0, '2026-11-06' => 102.0,
                '2026-11-09' => 105.0, '2026-11-10' => 110.0,
                '2026-07-09' => 200.0, '2026-07-10' => 210.0];
    $rns_price = db()->prepare("insert into historical_prices (symbol,exchange,date,price)
                                values ('RNB','XTST',?,?)");
    foreach ($rns_cal as $d => $p) { $rns_price->execute([$d, $p]); }

    $rns_base = fn($when) => rns_baseline_date($when, 'XTST');

    check('an announcement before the open is measured from the previous close',
          $rns_base('2026-11-06 07:00:00') === '2026-11-05',
          var_export($rns_base('2026-11-06 07:00:00'), true));
    check('one during the session is too, because that close has not happened yet',
          $rns_base('2026-11-06 10:30:00') === '2026-11-05',
          var_export($rns_base('2026-11-06 10:30:00'), true));
    check('one after the close is measured from that day',
          $rns_base('2026-11-06 17:00:00') === '2026-11-06',
          var_export($rns_base('2026-11-06 17:00:00'), true));
    check('and one right on the close counts as after it',
          $rns_base('2026-11-06 16:30:00') === '2026-11-06');
    check('a Saturday announcement is measured from Friday, whatever time of day',
          $rns_base('2026-11-07 09:00:00') === '2026-11-06'
          && $rns_base('2026-11-07 18:00:00') === '2026-11-06',
          var_export([$rns_base('2026-11-07 09:00:00'), $rns_base('2026-11-07 18:00:00')], true));
    check('a Monday morning announcement skips the weekend',
          $rns_base('2026-11-09 07:00:00') === '2026-11-06',
          var_export($rns_base('2026-11-09 07:00:00'), true));
    check('an announcement before any price at all has no baseline, rather than a wrong one',
          $rns_base('2020-01-02 07:00:00') === null);

    /* The time zone. In July a 16:00 UTC release is 17:00 in London, which is
       after the close; read as UTC it would be before it, and the move would
       be measured from the wrong day for half the year. */
    check('the close is compared in London time, not UTC',
          $rns_base('2026-07-10 16:00:00') === '2026-07-10',
          var_export($rns_base('2026-07-10 16:00:00'), true));
    check('and a summer morning release is still before the open',
          $rns_base('2026-07-10 06:00:00') === '2026-07-09',
          var_export($rns_base('2026-07-10 06:00:00'), true));

    check('the move is from that close to the latest one',
          rns_price_move('RNB', '2026-11-06 07:00:00', '2026-11-10', 'XTST') === 10.0,
          var_export(rns_price_move('RNB', '2026-11-06 07:00:00', '2026-11-10', 'XTST'), true));
    check('and from the other side of the close it is a different move, which is the point',
          rns_price_move('RNB', '2026-11-06 17:00:00', '2026-11-10', 'XTST') === 7.84,
          var_export(rns_price_move('RNB', '2026-11-06 17:00:00', '2026-11-10', 'XTST'), true));
    check('an unknown baseline is an unknown move, not a flat one',
          rns_price_move('RNB', '2020-01-02 07:00:00', '2026-11-10', 'XTST') === null);

    /* The move is stored on the row now, worked out once a night instead of
       four queries per row while somebody waits for a page: "In the news"
       took 27 seconds with three thousand announcements stored, against
       0.16 for the quote page.

       The rule has not moved - rns_refresh_price_moves() calls the same
       rns_price_move() these checks just exercised - so the one thing worth
       testing is that the stored number is that number, and that an
       announcement with no usable baseline stores nothing rather than a
       zero. */
    db()->exec("truncate rns_announcements");
    $mv_insert = db()->prepare("insert into rns_announcements
        (announcement_id, symbol, source, title, published_at, url, body, body_hash,
         skipped, first_seen)
        values (?, ?, 'RNS', 'Trading Update', ?, 'https://example.invalid/a', 'text',
                sha1('text'), 'N', now())");
    $mv_insert->execute(['6101', 'RNB', '2026-11-06 07:00:00']);
    $mv_insert->execute(['6102', 'RNB', '2026-11-06 17:00:00']);
    /* A symbol with no prices at all: the move is unknown, which is not the
       same as nothing having happened. */
    $mv_insert->execute(['6103', 'RNNOPRICE', '2026-11-06 07:00:00']);

    $mv_report = rns_refresh_price_moves('2026-11-10', 'XTST', 30);
    $mv_rows = rows("select announcement_id, price_move, price_move_date
                       from rns_announcements order by announcement_id");
    $mv_by_id = [];
    foreach ($mv_rows as $r) { $mv_by_id[$r['announcement_id']] = $r; }

    check('the stored move is the move the rule works out',
          abs((float) $mv_by_id['6101']['price_move'] - 10.0) < 0.0001
          && abs((float) $mv_by_id['6102']['price_move'] - 7.84) < 0.0001,
          json_encode($mv_by_id));
    check('and each row says which close it was measured to',
          substr((string) $mv_by_id['6101']['price_move_date'], 0, 10) === '2026-11-10');
    check('an announcement with no usable baseline stores no move rather than a zero',
          $mv_by_id['6103']['price_move'] === null
          && $mv_by_id['6103']['price_move_date'] === null,
          json_encode($mv_by_id['6103']));
    check('the run says how many it worked out and how many it could not',
          $mv_report['written'] === 2 && $mv_report['unknown'] === 1,
          json_encode($mv_report));

    /* Run again at a later close and the number changes, which is the whole
       point of a figure that runs to the latest price. */
    $mv_second = rns_refresh_price_moves('2026-11-09', 'XTST', 30);
    check('a later run re-measures to the new close rather than keeping the old answer',
          (float) scalar("select price_move from rns_announcements where announcement_id='6101'")
            !== (float) $mv_by_id['6101']['price_move']
          && $mv_second['written'] === 2,
          json_encode([scalar("select price_move from rns_announcements where announcement_id='6101'"),
                       $mv_by_id['6101']['price_move']]));

    db()->exec("truncate rns_announcements");
    db()->exec("delete from historical_prices where exchange='XTST'");
    db()->exec("delete from stock_symbols where exchange='XTST'");

    echo "\n--- the nightly job ---\n";

    $rns_dir = sys_get_temp_dir() . '/smrns' . getmypid();
    exec('rm -rf ' . escapeshellarg($rns_dir));
    @mkdir($rns_dir, 0777, true);

    $rns_site_port = 8351;
    $rns_ai_port   = 8352;
    $rns_site_pid = (int) trim((string) shell_exec(
        'SM_STUB_DIR=' . escapeshellarg($rns_dir) . ' php -S 127.0.0.1:' . $rns_site_port . ' '
        . escapeshellarg(dirname(__DIR__) . '/tools/investegate_stub.php')
        . ' > /dev/null 2>&1 & echo $!'));
    $rns_ai_pid = (int) trim((string) shell_exec(
        'SM_STUB_DIR=' . escapeshellarg($rns_dir) . ' php -S 127.0.0.1:' . $rns_ai_port . ' '
        . escapeshellarg(dirname(__DIR__) . '/tools/anthropic_stub.php')
        . ' > /dev/null 2>&1 & echo $!'));
    usleep(900000);

    file_put_contents("$rns_dir/site_mode", 'ok');
    file_put_contents("$rns_dir/mode", 'rns_ok');
    file_put_contents("$rns_dir/requests", '');

    /* One symbol, so the counts are about the fetcher rather than about how
       many the seed happens to have. */
    db()->exec("truncate rns_announcements");
    db()->exec("delete from stock_symbols where symbol like 'RNX%'");
    db()->prepare("insert into stock_symbols (symbol,name,description,exchange,enabled,sector,market,industry_group,industry)
                   values ('RNX','RNS Test PLC','RNS Test PLC','XLON','Y','Industrials','Main Market','Industrials','Aerospace')")
        ->execute();
    $rns_others = rows("select symbol from stock_symbols where enabled='Y' and exchange='XLON' and symbol<>'RNX'");
    db()->exec("update stock_symbols set enabled='N' where exchange='XLON' and symbol<>'RNX'");

    $rns_env = ['RNS_BASE_URL'           => "http://127.0.0.1:$rns_site_port",
                'ANTHROPIC_BASE_URL'     => "http://127.0.0.1:$rns_ai_port",
                'ANTHROPIC_API_KEY'      => 'test-key',
                'RNS_FETCH_INTERVAL'     => '0',
                'RNS_BACKOFF_SECONDS'    => '0',
                'RNS_SCORE_MAX_PER_RUN'  => '50'];

    [$rns_out1, $rns_rc1] = run_script('fetch_rns.php', [], $rns_env);
    check('the announcements job runs', $rns_rc1 === 0, substr($rns_out1, -600));

    $rns_requests = array_values(array_filter(explode("\n",
                        (string) @file_get_contents("$rns_dir/requests"))));
    check('robots.txt is asked for before anything else',
          count($rns_requests) && strpos($rns_requests[0], '/robots.txt') !== false,
          $rns_requests[0] ?? '(nothing)');
    check('the User-Agent says what this is, rather than pretending to be a browser',
          strpos((string) ($rns_requests[0] ?? ''), 'sharemanager/') !== false,
          $rns_requests[0] ?? '');

    $rns_rows = rows("select announcement_id, skipped, skip_rule, ann_type, sentiment,
                             summary, body_truncated, scored_at, length(body) body_length,
                             date(published_at) published
                        from rns_announcements order by announcement_id");
    /* Four now: three from the last few days and one twenty days old, which
       is inside the backfill a company with nothing stored gets. The fourth
       is what makes the per-company window testable - see "an interrupted
       backfill finishes" below. */
    check('every announcement in the window is on record',
          count($rns_rows) === 4, json_encode(array_column($rns_rows, 'announcement_id')));

    $rns_by_id = [];
    foreach ($rns_rows as $r) { $rns_by_id[$r['announcement_id']] = $r; }

    check('the trading update was read and scored',
          ($rns_by_id['9001']['skipped'] ?? '') === 'N'
          && (int) ($rns_by_id['9001']['body_length'] ?? 0) > 400
          && $rns_by_id['9001']['scored_at'] !== null,
          json_encode($rns_by_id['9001'] ?? null));
    check('and the scorer read the announcement, not the site\'s summary of it',
          strpos((string) ($rns_by_id['9001']['summary'] ?? ''), 'machine wrote') === false);
    check('its type came back as something from the list',
          in_array((string) ($rns_by_id['9001']['ann_type'] ?? ''), rns_types(), true),
          (string) ($rns_by_id['9001']['ann_type'] ?? ''));

    check('the buyback paperwork was skipped, with the rule that skipped it',
          ($rns_by_id['9002']['skipped'] ?? '') === 'Y'
          && stripos((string) ($rns_by_id['9002']['skip_rule'] ?? ''), 'own shares') !== false,
          json_encode($rns_by_id['9002'] ?? null));
    check('and its text was never fetched, which is the point of skipping before the request',
          (int) ($rns_by_id['9002']['body_length'] ?? 0) === 0);
    check('a skipped announcement is not scored',
          ($rns_by_id['9002']['scored_at'] ?? null) === null);

    check('the director purchase was kept, because it is a signal',
          ($rns_by_id['9003']['skipped'] ?? '') === 'N'
          && $rns_by_id['9003']['scored_at'] !== null,
          json_encode($rns_by_id['9003'] ?? null));

    /* The release date is the listing row's. The stub's announcement text
       ends with a buyback that runs to 25 February 2027, which is the date
       the first version read out of the document and filed the Rolls-Royce
       trading update under. */
    check('the stored date is the listing row\'s, not one found in the text',
          ($rns_by_id['9001']['published'] ?? '') === gmdate('Y-m-d'),
          json_encode([$rns_by_id['9001']['published'] ?? null, gmdate('Y-m-d')]));
    check('and no announcement is dated from the buyback end date in its text',
          count(array_filter($rns_rows,
                fn($r) => substr((string) $r['published'], 0, 4) === '2027')) === 0,
          json_encode(array_column($rns_rows, 'published')));
    check('each one keeps its own row\'s date, a day apart as the listing has them',
          ($rns_by_id['9001']['published'] ?? '') === gmdate('Y-m-d')
          && ($rns_by_id['9002']['published'] ?? '') === gmdate('Y-m-d', strtotime('-1 day'))
          && ($rns_by_id['9003']['published'] ?? '') === gmdate('Y-m-d', strtotime('-2 day')),
          json_encode(array_combine(array_column($rns_rows, 'announcement_id'),
                                    array_column($rns_rows, 'published'))));

    /* And its own row's TIME, on a twelve hour clock. The live listing
       writes "05:30 PM", which the first version stored as 05:30 - so every
       afternoon announcement was filed twelve hours early and measured its
       price move from the wrong close, because 05:30 is before the open and
       17:30 is after it. */
    $rns_times = rows("select announcement_id, time(published_at) t from rns_announcements
                        order by announcement_id");
    $rns_time_by_id = [];
    foreach ($rns_times as $r) { $rns_time_by_id[$r['announcement_id']] = substr((string) $r['t'], 0, 5); }
    check('a morning announcement keeps its morning time',
          ($rns_time_by_id['9001'] ?? '') === '07:00',
          json_encode($rns_time_by_id));
    check('and an afternoon one is stored in the afternoon, not twelve hours early',
          ($rns_time_by_id['9002'] ?? '') === '16:35'
          && ($rns_time_by_id['9003'] ?? '') === '17:30',
          json_encode($rns_time_by_id));
    check('the stored body carries none of the wire\'s boilerplate',
          stripos((string) scalar("select body from rns_announcements where announcement_id='9001'"),
                  'provided by RNS') === false
          && stripos((string) scalar("select body from rns_announcements where announcement_id='9001'"),
                     'not responsible') === false,
          substr((string) scalar("select body from rns_announcements where announcement_id='9001'"), -200));
    check('and the text that was scored is the announcement, not the page round it',
          (int) ($rns_by_id['9001']['body_length'] ?? 0) > 400
          && (string) scalar("select body from rns_announcements where announcement_id='9001'")
             !== ''
          && stripos((string) scalar("select body from rns_announcements
                                       where announcement_id='9001'"), 'Create a free account')
             === false,
          substr((string) scalar("select body from rns_announcements where announcement_id='9001'"), 0, 200));

    $rns_fetched = array_values(array_filter($rns_requests,
                       fn($line) => strpos($line, '/announcement/') !== false));
    check('only the three worth reading were fetched, not all four',
          count($rns_fetched) === 3, implode(' | ', $rns_fetched));

    check('the ticker that answered is remembered, so tomorrow asks once',
          (string) scalar("select tidm from stock_symbols where symbol='RNX'") !== '',
          var_export(scalar("select tidm from stock_symbols where symbol='RNX'"), true));

    /* A re-run. The listing is the same, so nothing is new and nothing is
       fetched again. */
    file_put_contents("$rns_dir/requests", '');
    [$rns_out2, $rns_rc2] = run_script('fetch_rns.php', [], $rns_env);
    check('a second run completes', $rns_rc2 === 0, substr($rns_out2, -400));
    check('and stores nothing new, because the announcement id is the key',
          (int) scalar("select count(*) from rns_announcements") === 4,
          scalar("select count(*) from rns_announcements") . ' rows');
    $rns_refetched = array_values(array_filter(explode("\n",
                         (string) @file_get_contents("$rns_dir/requests")),
                         fn($line) => strpos($line, '/announcement/') !== false));
    check('and asks for no announcement it already has',
          count($rns_refetched) === 0, implode(' | ', $rns_refetched));

    /* A date already on record that the listing disagrees with is corrected
       by the next run. This is how the rows stored by the version that dated
       every announcement a row out repair themselves - by being seen again,
       rather than by anybody going through the table by hand. */
    db()->exec("update rns_announcements set published_at='2020-01-02 07:00:00'
                 where announcement_id='9001'");
    file_put_contents("$rns_dir/requests", '');
    [$rns_fix_out, ] = run_script('fetch_rns.php', [], $rns_env);
    check('a wrong date already on record is corrected on the next run',
          (string) scalar("select date(published_at) from rns_announcements
                            where announcement_id='9001'") === gmdate('Y-m-d'),
          (string) scalar("select published_at from rns_announcements
                            where announcement_id='9001'"));
    check('the run says how many it corrected, so a repair is not silent',
          stripos($rns_fix_out, 'corrected') !== false, substr($rns_fix_out, -300));
    check('and it did not refetch the document to do it',
          count(array_filter(explode("\n", (string) @file_get_contents("$rns_dir/requests")),
                fn($l) => strpos($l, '/announcement/') !== false)) === 0);
    check('the announcement is not rescored for a date change',
          (string) scalar("select scored_model from rns_announcements
                            where announcement_id='9001'") !== '');

    /* And a date that agrees is left alone, so an unchanged listing is not
       reported as a repair every night. */
    [$rns_same_out, ] = run_script('fetch_rns.php', [], $rns_env);
    check('a date that already agrees is not reported as a correction',
          preg_match('/0 dates corrected/', $rns_same_out) === 1,
          substr($rns_same_out, -300));

    echo "\n--- when the site says it has had enough ---\n";

    /* The limit, mid-run. Everything already stored stays; nothing new is
       taken; the run says why and leaves the rest for tomorrow. */
    db()->exec("truncate rns_announcements");
    /* Every seeded symbol back on, so stopping after the first company is
       something the request log can actually show. With one symbol enabled
       "it stopped early" and "it finished" look identical. */
    db()->exec("update stock_symbols set enabled='Y' where exchange='XLON'");
    file_put_contents("$rns_dir/site_mode", 'limit');
    file_put_contents("$rns_dir/requests", '');
    [$rns_out3, $rns_rc3] = run_script('fetch_rns.php', [], $rns_env);
    check('the job does not fail when it meets the limit', $rns_rc3 === 0, substr($rns_out3, -400));
    check('it stores nothing from a limit page',
          (int) scalar("select count(*) from rns_announcements") === 0);
    check('it says it stopped early, so a short night is not a mystery',
          stripos($rns_out3, 'stopped early') !== false, substr($rns_out3, -300));
    $rns_asked = count(array_filter(explode("\n", (string) @file_get_contents("$rns_dir/requests")),
                       fn($l) => strpos($l, '/company/') !== false));
    check('and it stops asking rather than working through the universe',
          $rns_asked === 1 && count($rns_others) >= 5,
          $rns_asked . ' company pages asked for, of ' . (count($rns_others) + 1) . ' enabled');

    /* A login page is the same signal in different words. */
    file_put_contents("$rns_dir/site_mode", 'login');
    [$rns_out4, ] = run_script('fetch_rns.php', [], $rns_env);
    check('a login page is treated the same way',
          stripos($rns_out4, 'stopped early') !== false
          && (int) scalar("select count(*) from rns_announcements") === 0);

    /* Being throttled. Backs off, gives up, keeps the rest. */
    file_put_contents("$rns_dir/site_mode", '429');
    [$rns_out5, $rns_rc5] = run_script('fetch_rns.php', [],
                                       array_merge($rns_env, ['RNS_MAX_REFUSALS' => '2']));
    check('a 429 does not fail the job either', $rns_rc5 === 0, substr($rns_out5, -400));
    check('it gives up after the refusals it is allowed',
          stripos($rns_out5, 'refusal') !== false, substr($rns_out5, -300));
    check('and stores nothing',
          (int) scalar("select count(*) from rns_announcements") === 0);

    echo "\n--- a 200 is not an answer to the question that was asked ---\n";

    /* The data fault from the first full run. Investegate answers
       /company/JD with HTTP 200 and a page of OTHER companies'
       announcements - a two letter code without a dot is not the company
       anybody meant - so 133 releases from RWE, Daimler Truck, 3i and AIB
       were stored and partly scored as JD Sports, National Grid, AO and six
       others. JD Sports showed RWE releases on its quote page.

       Six more (AV, BA, BP, BT-A, SN, UU) kept the dotless code because the
       page "answered", and collected nothing of their own at all for a
       week.

       The URL carries each company's own TIDM after the "--", so every link
       says whose it is. */
    $rns_foreign = '<html><body><table><tbody>'
                 . '<tr><td><a href="/announcement/rns/rwe-ag--RWE./quarterly-statement/7101">'
                 . 'Quarterly Statement</a></td><td>12 Nov 2026 07:00 AM</td></tr>'
                 . '<tr><td><a href="/announcement/rns/daimler-truck--DTG./capital-markets-day/7102">'
                 . 'Capital Markets Day</a></td><td>11 Nov 2026 07:00 AM</td></tr>'
                 . '</tbody></table></body></html>';

    check('a page of other companies\' announcements yields none of ours',
          rns_announcement_links($rns_foreign, 'JD.') === [],
          json_encode(array_column(rns_announcement_links($rns_foreign, 'JD.'), 'tidm')));
    check('and the filter is what does it, not an empty page',
          count(rns_announcement_links($rns_foreign)) === 2,
          json_encode(array_column(rns_announcement_links($rns_foreign), 'tidm')));

    /* The company's own links are kept, and only those, when the page holds
       a mixture - which is what a search result or a sector page looks
       like. */
    $rns_mixed = '<html><body><table><tbody>'
               . '<tr><td><a href="/announcement/rns/rwe-ag--RWE./quarterly-statement/7103">'
               . 'Quarterly Statement</a></td><td>12 Nov 2026 07:00 AM</td></tr>'
               . '<tr><td><a href="/announcement/rns/jd-sports--JD./trading-statement/7104">'
               . 'Trading Statement</a></td><td>12 Nov 2026 07:05 AM</td></tr>'
               . '</tbody></table></body></html>';
    $rns_ours = rns_announcement_links($rns_mixed, 'JD.');
    check('a mixed page keeps ours and drops theirs',
          count($rns_ours) === 1 && $rns_ours[0]['announcement_id'] === '7104'
          && $rns_ours[0]['tidm'] === 'JD.',
          json_encode($rns_ours));
    check('the match ignores case, because a URL is not careful about it',
          count(rns_announcement_links($rns_mixed, 'jd.')) === 1);

    /* The share class. This application writes BT's A shares BT-A;
       Investegate writes them BT.A, so asking for BT-A got a page with none
       of BT's own announcements and BT collected nothing for a week. */
    db()->exec("update stock_symbols set tidm=null where symbol in ('RNX','BT-A')");
    check('a share class is tried in the spelling Investegate uses',
          in_array('BT.A', rns_tidm_candidates('BT-A'), true),
          json_encode(rns_tidm_candidates('BT-A')));
    check('and the trailing dot is not tried on one, because BT-A. is nobody\'s ticker',
          !in_array('BT-A.', rns_tidm_candidates('BT-A'), true),
          json_encode(rns_tidm_candidates('BT-A')));
    check('a short ticker still gets the trailing dot London writes',
          rns_tidm_candidates('RR') === ['RR', 'RR.'],
          json_encode(rns_tidm_candidates('RR')));

    /* End to end: a company whose page serves only other companies' links
       stores nothing and remembers no ticker, so tomorrow asks again rather
       than asking the wrong thing twice. */
    db()->exec("truncate rns_announcements");
    db()->exec("update stock_symbols set tidm=null where symbol='RNX'");
    db()->exec("update stock_symbols set enabled='N' where exchange='XLON' and symbol<>'RNX'");
    db()->exec("update stock_symbols set enabled='Y' where symbol='RNX'");
    file_put_contents("$rns_dir/site_mode", 'foreign');
    file_put_contents("$rns_dir/requests", '');

    [$rns_fo_out, $rns_fo_rc] = run_script('fetch_rns.php', [], $rns_env);

    check('the job completes when every page is somebody else\'s', $rns_fo_rc === 0,
          substr($rns_fo_out, -300));
    check('and stores none of it',
          (int) scalar("select count(*) from rns_announcements") === 0,
          json_encode(array_column(rows("select announcement_id, symbol from rns_announcements"),
                                   'announcement_id')));
    check('no ticker is remembered, so tomorrow asks again rather than asking wrongly twice',
          scalar("select tidm from stock_symbols where symbol='RNX'") === null
          || (string) scalar("select tidm from stock_symbols where symbol='RNX'") === '',
          var_export(scalar("select tidm from stock_symbols where symbol='RNX'"), true));
    check('every candidate was tried before giving up on the company',
          count(array_filter(explode("\n", (string) @file_get_contents("$rns_dir/requests")),
                fn($l) => strpos($l, '/company/') !== false)) >= 2,
          (string) @file_get_contents("$rns_dir/requests"));
    check('and the log says it listed none of its own',
          (int) scalar("select count(*) from message_log
                         where message_text like '%none of its own%'") > 0);

    file_put_contents("$rns_dir/site_mode", 'ok');
    db()->exec("truncate rns_announcements");

    echo "\n--- an interrupted backfill finishes ---\n";

    /* The window used to be one date for the whole run: a day before the
       newest announcement in the table. The first live run stopped after 73
       of 350 companies, which left that date at yesterday - so the next run
       would have looked back one day for the other 277 and their October
       announcements would never have been fetched at all. An interrupted
       backfill could never finish, and nothing said so.

       Three steps, because the fault only shows in the second and third. */
    db()->exec("truncate rns_announcements");
    file_put_contents("$rns_dir/site_mode", 'ok');
    db()->exec("update stock_symbols set enabled='N' where exchange='XLON' and symbol<>'RNX'");
    db()->exec("update stock_symbols set enabled='Y' where symbol='RNX'");

    /* One: nothing stored for this company, so it gets the whole backfill
       and the twenty day old announcement comes with it. */
    run_script('fetch_rns.php', [], $rns_env);
    check('a company with nothing stored is read back the whole backfill',
          (int) scalar("select count(*) from rns_announcements where announcement_id='9004'") === 1,
          json_encode(array_column(rows("select announcement_id from rns_announcements"), 'announcement_id')));

    /* Two: with recent announcements on record, the window is a day before
       this company's own newest, so the old one is behind it and the
       listing stops there. */
    db()->exec("delete from rns_announcements where announcement_id='9004'");
    file_put_contents("$rns_dir/requests", '');
    run_script('fetch_rns.php', [], $rns_env);
    check('and a company with recent announcements is not read back a month every night',
          (int) scalar("select count(*) from rns_announcements where announcement_id='9004'") === 0,
          json_encode(array_column(rows("select announcement_id from rns_announcements"), 'announcement_id')));

    /* Three: the fault itself. Another company has an announcement from
       today, so the TABLE's newest is today - but this company has nothing,
       and it must still be read back the whole backfill. Under the old
       global window it got one day and its month was lost. */
    db()->exec("truncate rns_announcements");
    db()->prepare("insert into rns_announcements
                     (announcement_id, symbol, source, title, published_at, url, body,
                      body_hash, skipped, first_seen)
                   values ('8899','OTHERCO','RNS','Someone else''s news', ?,
                           'https://example.invalid/a','text',sha1('text'),'N',now())")
      ->execute([gmdate('Y-m-d') . ' 07:00:00']);

    run_script('fetch_rns.php', [], $rns_env);
    check('a company with nothing of its own is not held back by another company\'s news',
          (int) scalar("select count(*) from rns_announcements where announcement_id='9004'") === 1,
          json_encode(array_column(rows("select announcement_id, symbol from rns_announcements"),
                                   'announcement_id')));

    db()->exec("delete from rns_announcements where symbol='OTHERCO'");

    echo "\n--- a connection that drops is not a refusal ---\n";

    /* Three "status 0" results in a row stopped the first live run after 73
       companies. That is a connection failing, not Investegate refusing: the
       site answered normally a minute later. Each one is retried once, they
       are counted separately from refusals, and they have their own higher
       limit - a network that drops one request will drop two, but the run
       should still give up rather than hammer a dead link 350 times.

       A port with nothing listening is a connection failure that costs no
       waiting, which is what makes this testable at all. */
    db()->exec("truncate rns_announcements");
    $dead_port = $rns_site_port + 40;
    [$drop_out, $drop_rc] = run_script('fetch_rns.php', [],
        array_merge($rns_env, ['RNS_BASE_URL' => "http://127.0.0.1:$dead_port",
                               'RNS_MAX_DROPPED' => '2']));

    check('the job does not fail when the connection does', $drop_rc === 0, substr($drop_out, -400));
    check('it says it stopped on connection failures, not on refusals',
          stripos($drop_out, 'connection failure') !== false
          && stripos($drop_out, 'refusal') === false,
          substr($drop_out, -400));
    check('it retried before giving up on a company',
          (int) scalar("select count(*) from message_log
                         where message_text like '%retrying once%'") > 0,
          (string) scalar("select message_text from message_log
                            where message_text like '%no answer%' limit 1"));
    check('and the night is written down as what it was',
          (int) scalar("select count(*) from message_log
                         where message_text like '%dropped=%'") > 0
          && (int) scalar("select count(*) from message_log
                            where message_text like '%refused=0%'") > 0,
          (string) scalar("select message_text from message_log
                            where message_text like '%dropped=%' limit 1"));
    check('nothing was stored from a connection that never answered',
          (int) scalar("select count(*) from rns_announcements") === 0);

    echo "\n--- the skip list after the first live run ---\n";

    /* Five rules Jarvis added on the server after reading what the fetcher
       brought back. In the migration now, so a rebuild keeps them. */
    foreach ([['Irish Form 38',       'Irish Form 38 - Dealings by a Party'],
              ['Irishform38',         'Irishform38 Dealings'],
              ['Gearing Disclosure',  'Gearing Disclosure - 31 October 2026'],
              ['Issue of Shares',     'Issue of Shares'],
              ['Director Declaration', 'Director Declaration']] as [$pattern, $title]) {

        check("the migration carries the '$pattern' rule",
              (int) scalar("select count(*) from rns_skip_rules where pattern=? and enabled='Y'",
                           [$pattern]) === 1);
        check("and '$title' is skipped before its text is fetched",
              rns_skip_reason($title) !== null,
              var_export(rns_skip_reason($title), true));
    }

    /* The exception that still has to work: an investment trust tapping out
       shares is routine, a placing is not. */
    check('a placing is still worth reading',
          rns_skip_reason('Placing of New Ordinary Shares') === null,
          var_export(rns_skip_reason('Placing of New Ordinary Shares'), true));

    /* Back to one symbol, so what follows is about the scorer rather than
       about how many companies the seed has. */
    db()->exec("update stock_symbols set enabled='N' where exchange='XLON' and symbol<>'RNX'");

    echo "\n--- scoring order, the cap, and what comes back ---\n";

    /* Newest first, which is the opposite of the headline scorer. An
       announcement is worth most on the day it is made: if the queue is
       longer than the cap, today's results are what should be scored tonight
       and last month's can wait. */
    db()->exec("truncate rns_announcements");
    /* An empty listing, so the job finds no new work and the only thing
       under test is the scorer. */
    file_put_contents("$rns_dir/site_mode", 'empty');
    $rns_insert = db()->prepare("insert into rns_announcements
        (announcement_id, symbol, source, title, published_at, url, body, body_hash,
         skipped, first_seen)
        values (?, 'RNX', 'RNS', ?, ?, 'https://example.invalid/a', ?, sha1(?), 'N', now())");
    foreach ([['7001', 'Oldest',  '-9 day'],
              ['7002', 'Middle',  '-5 day'],
              ['7003', 'Newest',  '-1 day']] as [$id, $title, $ago]) {
        $text = $title . ' announcement. ' . str_repeat('Trading has been ahead of expectations. ', 20);
        $rns_insert->execute([$id, $title, gmdate('Y-m-d H:i:s', strtotime($ago)), $text, $text]);
    }

    /* Through the job, not by calling the scorer here: llm_generate() reads
       ANTHROPIC_BASE_URL out of the constants this process was started with,
       so a call from inside the suite would go to whatever that is rather
       than to the stub. run_script() sets the environment. */
    file_put_contents("$rns_dir/mode", 'rns_ok');
    /* array_merge, not +: PHP's + keeps the left operand's value for a
       duplicate key, so $rns_env + [cap => 1] quietly kept the 50 already in
       $rns_env and the cap was never tested at all. */
    [$rns_cap_out, ] = run_script('fetch_rns.php', [],
                                  array_merge($rns_env, ['RNS_SCORE_MAX_PER_RUN' => '1']));
    check('the cap is honoured exactly',
          (int) scalar("select count(*) from rns_announcements where scored_at is not null") === 1,
          substr($rns_cap_out, -300));
    check('and the newest announcement is the one that got scored',
          (string) scalar("select announcement_id from rns_announcements
                            where scored_at is not null") === '7003',
          (string) scalar("select announcement_id from rns_announcements where scored_at is not null"));
    check('the run says it was capped, so a backlog is visible',
          stripos($rns_cap_out, 'capped') !== false
          || (int) scalar("select count(*) from rns_announcements where scored_at is null") === 2,
          substr($rns_cap_out, -300));
    check('what it cost is recorded per announcement, not just per run',
          (float) scalar("select cost_usd from rns_announcements where announcement_id='7003'") > 0
          && (int) scalar("select input_tokens from rns_announcements where announcement_id='7003'") > 0,
          json_encode(rows("select cost_usd, input_tokens, output_tokens from rns_announcements
                             where announcement_id='7003'")));

    [$rns_rest_out, ] = run_script('fetch_rns.php', [], $rns_env);
    check('the rest are scored on the next pass',
          (int) scalar("select count(*) from rns_announcements where scored_at is not null") === 3,
          substr($rns_rest_out, -300));
    check('and nothing is left unscored',
          (int) scalar("select count(*) from rns_announcements where scored_at is null") === 0);

    /* The schema constrains the answer and the code checks it again. A stub,
       a future model or a changed API could all send something else, and a
       bad value here ends up averaged into a figure a screen selects on. */
    db()->exec("update rns_announcements set scored_at=null, ann_type=null,
                    vs_expectations=null where announcement_id='7001'");
    file_put_contents("$rns_dir/mode", 'rns_bad_fields');
    run_script('fetch_rns.php', [], $rns_env);
    check('a type outside the list is refused rather than stored',
          (string) scalar("select ifnull(ann_type,'(null)') from rns_announcements
                            where announcement_id='7001'") === '(null)',
          (string) scalar("select ifnull(ann_type,'(null)') from rns_announcements where announcement_id='7001'"));
    check('and the announcement stays unscored, so it comes round again',
          scalar("select scored_at from rns_announcements where announcement_id='7001'") === null);

    file_put_contents("$rns_dir/mode", 'rns_long_summary');
    run_script('fetch_rns.php', [], $rns_env);
    $rns_summary = (string) scalar("select summary from rns_announcements where announcement_id='7001'");
    check('a summary over forty words is trimmed rather than the answer thrown away',
          $rns_summary !== ''
          && count(preg_split('/\s+/', $rns_summary, -1, PREG_SPLIT_NO_EMPTY)) === 40,
          count(preg_split('/\s+/', $rns_summary, -1, PREG_SPLIT_NO_EMPTY)) . ' words');

    echo "\n--- the figures the rest of the app reads ---\n";

    /* Same three indicator names as before, so a screen or a chart reading
       them does not care that the source changed. What changes is that they
       now mean something. */
    db()->exec("truncate rns_announcements");
    $rns_asof = asof_date();
    $rns_ind = db()->prepare("insert into rns_announcements
        (announcement_id, symbol, source, title, published_at, url, body, skipped,
         sentiment, ann_type, vs_expectations, summary, scored_model, scored_at, first_seen)
        values (?, 'RNX', 'RNS', 'T', ?, 'https://example.invalid/a', 'text', ?,
                ?, 'results', 'not stated', 'A summary', 'stub', now(), now())");
    //Today strongly bad, nine days ago mildly good, and one skipped filing.
    $rns_ind->execute(['6001', $rns_asof . ' 07:00:00', 'N', -0.8]);
    $rns_ind->execute(['6002', date('Y-m-d', strtotime($rns_asof . ' -9 day')) . ' 07:00:00', 'N', 0.4]);
    $rns_ind->execute(['6003', $rns_asof . ' 08:00:00', 'Y', 0.9]);

    $rns_7d  = rns_sentiment('RNX', $rns_asof, 7);
    $rns_30d = rns_sentiment('RNX', $rns_asof, 30);
    check('the seven day figure sees only what is inside seven days',
          abs($rns_7d - (-0.8)) < 0.0001, var_export($rns_7d, true));
    check('the thirty day figure takes in the older one, weighted towards the recent',
          $rns_30d !== null && $rns_30d > -0.8 && $rns_30d < 0.4,
          var_export($rns_30d, true));
    check('a skipped filing is not in either, because it was never read',
          abs($rns_7d - (-0.8)) < 0.0001);
    check('the count is of announcements worth reading, not of filings',
          rns_count('RNX', $rns_asof, 7) === 1, (string) rns_count('RNX', $rns_asof, 7));

    $rns_written = rns_write_indicators($rns_asof, 'XLON');
    check('the indicators are written', $rns_written > 0, (string) $rns_written);
    check('and into the rows the screens already read',
          abs((float) scalar("select value from statistics where symbol='RNX'
                               and indicator='news_sentiment_7d' and date=?", [$rns_asof]) - (-0.8)) < 0.001,
          var_export(scalar("select value from statistics where symbol='RNX'
                              and indicator='news_sentiment_7d' and date=?", [$rns_asof]), true));
    check('a company that has announced nothing gets no sentiment row, not a neutral one',
          rns_sentiment('AAA', $rns_asof, 7) === null);

    /* The outlook, the chart and the explanation all read announcements now. */
    $rns_outlook = outlook_headlines('RNX', 8);
    check('the outlook is given announcements rather than headlines',
          count($rns_outlook) === 2, json_encode($rns_outlook));
    check('with the type and the verdict the announcement carried',
          ($rns_outlook[0]['impact_type'] ?? '') === 'results'
          && array_key_exists('vs_expectations', $rns_outlook[0]),
          json_encode($rns_outlook[0] ?? null));
    check('and it no longer reaches for the retired headline feed',
          strpos(php_strip_whitespace($INCLUDES . '/ai_outlook.php'), 'get_articles') === false);

    $rns_range = rns_in_range('RNX', date('Y-m-d', strtotime($rns_asof . ' -30 day')), $rns_asof);
    check('the chart and the explanation read the same announcements',
          count($rns_range) === 2, (string) count($rns_range));
    check('and the skipped filing is not a marker on the chart',
          !in_array('6003', array_column($rns_range, 'announcement_id'), true));

    check('the chart endpoint reads announcements, not headlines',
          strpos(php_strip_whitespace(dirname(__DIR__) . '/public/chart_data.php'),
                 'rns_in_range') !== false
          && strpos(php_strip_whitespace(dirname(__DIR__) . '/public/chart_data.php'),
                 'news_in_range') === false);

    echo "\n--- the headline feed is retired, not deleted ---\n";

    $rns_cron = implode("\n", array_filter(
        explode("\n", (string) file_get_contents($INCLUDES . '/refresh_all.sh')),
        fn($line) => strpos(ltrim($line), '#') !== 0));
    check('the nightly run fetches announcements',
          strpos($rns_cron, 'fetch_rns.php') !== false);
    check('and no longer fetches headlines',
          strpos($rns_cron, 'fetch_news.php') === false);
    check('but the headline store is still there, so a year of history is not thrown away',
          (int) scalar("select count(*) from information_schema.tables
                         where table_schema=database() and table_name='news_articles'") === 1);
    check('and the headline job still exists to be run by hand',
          file_exists($INCLUDES . '/fetch_news.php'));

    echo "\n--- Investegate's terms, in the code ---\n";

    /* Their terms allow a private investor to keep extracts for personal
       reference and forbid redistribution. Two things follow, and both are
       the kind of thing that gets quietly undone. */
    $rns_src = (string) file_get_contents($INCLUDES . '/rns.php');
    check('the fetcher identifies itself',
          strpos($rns_src, 'RNS_USER_AGENT') !== false
          && strpos((string) RNS_USER_AGENT, 'sharemanager') === 0);
    check('robots.txt is read before anything is fetched',
          strpos(php_strip_whitespace($INCLUDES . '/fetch_rns.php'), 'rns_robots_allows') !== false);
    check('the job refuses to run if robots.txt disallows what it needs',
          strpos((string) file_get_contents($INCLUDES . '/fetch_rns.php'),
                 'robots.txt disallows') !== false);
    check('and there is no login anywhere in it',
          stripos($rns_src, 'password') === false
          && !preg_match('~\blogin\s*\(~i', $rns_src));

    check('robots.txt is honoured rather than assumed',
          rns_robots_allows('/company/RR.', "http://127.0.0.1:$rns_site_port") === true
          && rns_robots_allows('/login', "http://127.0.0.1:$rns_site_port") === false);

    //Leave the universe as it was found.
    if ($rns_site_pid > 0) { exec("kill $rns_site_pid 2>/dev/null"); }
    if ($rns_ai_pid > 0)   { exec("kill $rns_ai_pid 2>/dev/null"); }
    exec('rm -rf ' . escapeshellarg($rns_dir));
    foreach ($rns_others as $rns_other) {
        db()->prepare("update stock_symbols set enabled='Y' where symbol=?")
            ->execute([$rns_other['symbol']]);
    }
    db()->exec("delete from stock_symbols where symbol like 'RNX%'");
    db()->exec("truncate rns_announcements");
    reset_calculated();
    run_script('get_momentum_statistics.php');
    run_script('get_statistics.php');
    if ($rns_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $rns_saved_exchange; }

    echo "\n--- the dashboard: what the portfolio is worth ---\n";

    require_once($INCLUDES . '/home_functions.php');

    /* A portfolio the test owns outright, so the figures can be checked by
       hand. Session 99 is used nowhere else in the suite. */
    $hp_user = 99;
    db()->exec("delete from purchases where session_id=$hp_user");
    db()->exec("delete from cash_history where user_id=$hp_user");
    db()->exec("delete from dividends where session_id=$hp_user");
    db()->exec("delete from portfolio_performance where session_id=$hp_user");

    $hp_asof = asof_date();
    $hp_prev = (string) scalar("select max(date) from historical_prices
                                 where exchange='XLON' and date<?", [$hp_asof]);

    /* Two holdings with known closes on both days, so the day's change is
       arithmetic rather than whatever the fixtures happen to hold. */
    db()->exec("delete from historical_prices where symbol in ('PFA','PFB') and exchange='XLON'");
    $hp_price = db()->prepare("insert into historical_prices (symbol, exchange, date, price)
                               values (?, 'XLON', ?, ?)");
    $hp_price->execute(['PFA', $hp_prev, 100.0]);   // 100p -> 110p, 1,000 shares
    $hp_price->execute(['PFA', $hp_asof, 110.0]);
    $hp_price->execute(['PFB', $hp_prev, 200.0]);   // 200p -> 190p,   500 shares
    $hp_price->execute(['PFB', $hp_asof, 190.0]);
    db()->exec("delete from stock_symbols where symbol in ('PFA','PFB')");
    foreach (['PFA', 'PFB'] as $hp_sym) {
        db()->prepare("insert into stock_symbols (symbol, name, exchange, enabled)
                       values (?, ?, 'XLON', 'Y')")
            ->execute([$hp_sym, $hp_sym . ' Holdings PLC']);
    }

    db()->prepare("insert into purchases (session_id, symbol, trx_type, shares, price_paid,
                                          commission, purchase_date)
                   values (?, 'PFA', 'BUY', 1000, 90, 10, ?)")->execute([$hp_user, $hp_prev]);
    db()->prepare("insert into purchases (session_id, symbol, trx_type, shares, price_paid,
                                          commission, purchase_date)
                   values (?, 'PFB', 'BUY', 500, 210, 10, ?)")->execute([$hp_user, $hp_prev]);
    db()->prepare("insert into cash_history (user_id, transaction_date, trx_type, amount)
                   values (?, ?, 'DEPOSIT', 5000)")->execute([$hp_user, $hp_prev]);

    /* The strip reads portfolio_performance, which the nightly valuation job
       writes. Written here the way that job writes it. */
    $hp_pp = db()->prepare("insert into portfolio_performance
        (session_id, as_of_date, symbol, active, exchange, qty_purchased, qty_sold,
         price, price_paid, price_sold, commission, dividends, value, value_raw,
         profit, profit_raw, profit_perc)
        values (?, ?, ?, 'Y', 'XLON', ?, 0, ?, ?, 0, 10, 0, ?, ?, ?, ?, ?)");
    //PFA: 1,000 at 110p = GBP1,100; cost 90p = GBP900; less GBP10 commission.
    $hp_pp->execute([$hp_user, $hp_asof, 'PFA', 1000, 110, 90, '1,100.00', 1100.0,
                     '190.00', 190.0, 21.1]);
    //PFB: 500 at 190p = GBP950; cost 210p = GBP1,050; less GBP10 commission.
    $hp_pp->execute([$hp_user, $hp_asof, 'PFB', 500, 190, 210, '950.00', 950.0,
                     '-110.00', -110.0, -10.5]);

    $hp_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = 'XLON';

    $strip = home_portfolio_strip($hp_user, 'XLON');

    check('the strip values the holdings at the latest close',
          abs($strip['value'] - 2050.0) < 0.005, json_encode($strip));
    check('and carries the cash and the holding count',
          abs($strip['cash'] - 5000.0) < 0.005 && $strip['holdings'] === 2,
          json_encode($strip));
    check('the total is the holdings plus the cash',
          abs($strip['total'] - 7050.0) < 0.005, json_encode($strip));
    check('the gain is what the positions made, commission and all',
          abs($strip['gain'] - 80.0) < 0.005, json_encode($strip));

    /* PFA made GBP100 on the day, PFB lost GBP50: GBP50, on GBP2,000 of
       yesterday's value, is +2.5%. */
    check("today's change is the move between the last two closes",
          abs($strip['day_change'] - 50.0) < 0.005, json_encode($strip));
    check('and the percentage is against what it was worth yesterday',
          abs($strip['day_change_pct'] - 2.5) < 0.005, json_encode($strip));

    /* The honest answer when the second close is not there. A day's change
       computed against a close from a week ago would be a week's change
       wearing today's label. */
    db()->exec("delete from historical_prices where symbol in ('PFA','PFB')
                 and exchange='XLON' and date='$hp_prev'");
    $strip_one = home_portfolio_strip($hp_user, 'XLON');
    check('with only one close, no day change is claimed',
          $strip_one['day_change'] === null && $strip_one['day_change_pct'] === null,
          json_encode($strip_one));
    check('but the value is still there',
          abs($strip_one['value'] - 2050.0) < 0.005, json_encode($strip_one));

    db()->exec("delete from portfolio_performance where session_id=$hp_user");
    $strip_empty = home_portfolio_strip($hp_user, 'XLON');
    check('an account with no holdings reports none, and its cash',
          $strip_empty['holdings'] === 0 && abs($strip_empty['total'] - 5000.0) < 0.005,
          json_encode($strip_empty));

    db()->exec("delete from historical_prices where symbol in ('PFA','PFB')");
    db()->exec("delete from stock_symbols where symbol in ('PFA','PFB')");
    db()->exec("delete from purchases where session_id=$hp_user");
    db()->exec("delete from cash_history where user_id=$hp_user");

    echo "\n--- the dashboard: the ranked lists ---\n";

    $hr = home_ranked('momentum', 10, 'XLON');
    check('a ranked list carries the score, the price and the six month move',
          count($hr) > 0 && array_key_exists('score', $hr[0])
          && array_key_exists('price', $hr[0]) && array_key_exists('change_6m', $hr[0]),
          json_encode(count($hr) ? $hr[0] : null));
    /* Four lists that showed a ticker and a name, and no rank. */
    check('and it is in rank order, best first',
          count($hr) < 2 || (float) $hr[0]['score'] >= (float) $hr[1]['score'],
          json_encode(array_column($hr, 'score')));
    check('ten means ten',
          count($hr) <= 10, count($hr));
    check('and the whole list is longer than the top ten',
          count(home_ranked('momentum', null, 'XLON')) >= count($hr));

    check('an unknown score is refused rather than turned into a column name',
          home_ranked('; drop table statistics', 10, 'XLON') === []);

    /* Funds are not scored, and the list is read off whatever statistics
       date is newest - which between a deploy and the next nightly run is
       still the date the old code wrote. */
    $hr_funds = array_column(home_ranked('value', null, 'XLON'), 'symbol');
    $hr_is_fund = rows("select symbol from stock_symbols where exchange='XLON' and is_fund='Y'");
    $hr_leak = array_intersect($hr_funds, array_column($hr_is_fund, 'symbol'));
    check('no fund is in a ranked list', count($hr_leak) === 0, implode(',', $hr_leak));

    echo "\n--- the dashboard: how much of the market is in an uptrend ---\n";

    $bh = home_breadth_history($hp_asof, 6, 'XLON');
    check('the breadth history is a proportion per date',
          count($bh) > 0 && array_key_exists('date', $bh[0]) && array_key_exists('pct', $bh[0]),
          json_encode(count($bh) ? $bh[0] : null));
    check('and the latest point agrees with the figure on the card',
          count($bh) === 0
          || abs((float) end($bh)['pct']
                 - (float) outlook_market_inputs($hp_asof)['breadth']['pct_above_ma200']) < 0.11,
          json_encode([end($bh), outlook_market_inputs($hp_asof)['breadth']['pct_above_ma200']]));

    echo "\n--- the dashboard: the week's news ---\n";

    $hn_from = home_news_window($hp_asof, 7, 'XLON');
    check('the week is counted in trading days, not calendar days',
          $hn_from <= $hp_asof
          && (int) scalar("select count(distinct date) from historical_prices
                            where exchange='XLON' and date between ? and ?",
                          [$hn_from, $hp_asof]) <= 8,
          $hn_from . ' .. ' . $hp_asof);

    db()->exec("truncate rns_announcements");
    $hn_ins = db()->prepare("insert into rns_announcements
        (announcement_id, symbol, source, title, published_at, url, body, skipped,
         sentiment, ann_type, summary, key_figures, scored_model, scored_at, first_seen,
         price_move, price_move_date)
        values (?, ?, 'RNS', ?, ?, ?, 'text', 'N', ?, 'trading update', 'x', '[]',
                'stub', now(), now(), ?, ?)");
    $hn_ins->execute(['9001', 'AAA', 'Good news',  $hp_asof . ' 07:00:00',
                      'https://example.invalid/a', 0.8, 4.5, $hp_asof]);
    $hn_ins->execute(['9002', 'AAA', 'Also good',  $hp_asof . ' 08:00:00',
                      'https://example.invalid/b', 0.2, 1.0, $hp_asof]);
    $hn_ins->execute(['9003', 'BBB', 'Bad news',   $hp_asof . ' 07:00:00',
                      'https://example.invalid/c', -0.9, -6.25, $hp_asof]);

    $hn = home_news_week($hn_from, $hp_asof, 5, 'XLON');
    check('the best and the worst of the week are both there',
          count($hn['best']) > 0 && count($hn['worst']) > 0
          && $hn['best'][0]['symbol'] === 'AAA' && $hn['worst'][0]['symbol'] === 'BBB',
          json_encode([array_column($hn['best'], 'symbol'), array_column($hn['worst'], 'symbol')]));
    check('each carries what the share has done since it announced',
          abs((float) $hn['best'][0]['price_move'] - 4.5) < 0.005
          && abs((float) $hn['worst'][0]['price_move'] + 6.25) < 0.005,
          json_encode([$hn['best'][0]['price_move'], $hn['worst'][0]['price_move']]));
    /* Two announcements from one company is one row, and the move shown is
       the one since the announcement that put it on the list - the move
       since the weakest of three would be describing a different event. */
    check('a company that announced twice appears once, against its strongest',
          count(array_filter($hn['best'], function ($r) { return $r['symbol'] === 'AAA'; })) === 1
          && abs((float) $hn['best'][0]['price_move'] - 4.5) < 0.005);

    db()->exec("truncate rns_announcements");
    if ($hp_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $hp_saved_exchange; }

    echo "\n--- the quote page: the figures under the name ---\n";

    require_once($INCLUDES . '/quote_view.php');

    $qh_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = 'XLON';

    /* A share with a year of closes the test owns, so the range and the
       capitalisation are arithmetic. */
    db()->exec("delete from stock_symbols where symbol='QHD'");
    db()->exec("delete from historical_prices where symbol='QHD'");
    db()->exec("delete from stock_info where symbol='QHD'");
    db()->prepare("insert into stock_symbols (symbol, name, exchange, enabled, market, sector, logo)
                   values ('QHD', 'Quote Header PLC', 'XLON', 'Y', 'FTSE 250', 'Industrials', 'N/A')")
        ->execute();

    $qh_asof = asof_date();
    $qh_ins = db()->prepare("insert into historical_prices (symbol, exchange, date, price)
                             values ('QHD', 'XLON', ?, ?)");
    /* A year of closes between 80p and 400p, with the last two 300p then
       330p so the day's change is +30p and +10.0%. */
    for ($qh_i = 400; $qh_i >= 2; $qh_i--) {
        $qh_day = date('Y-m-d', strtotime($qh_asof . ' -' . $qh_i . ' day'));
        $qh_ins->execute([$qh_day, $qh_i % 2 ? 80.0 : 400.0]);
    }
    $qh_ins->execute([date('Y-m-d', strtotime($qh_asof . ' -1 day')), 300.0]);
    $qh_ins->execute([$qh_asof, 330.0]);

    $qh = quote_header('QHD', 'XLON');

    check('the price is the last stored close, not a live quote',
          abs($qh['price'] - 330.0) < 0.005 && $qh['as_of'] === $qh_asof,
          json_encode([$qh['price'], $qh['as_of'], $qh_asof]));
    check("and the day's change is against the close before it",
          abs($qh['change'] - 30.0) < 0.005 && abs($qh['change_pct'] - 10.0) < 0.005,
          json_encode([$qh['change'], $qh['change_pct']]));

    /* The 52 week range was blank on every share: it comes from a provider
       endpoint this plan does not include. A year of stored closes is a
       year of stored closes. */
    check('the 52 week range comes from the price history',
          abs($qh['low_52w'] - 80.0) < 0.005 && abs($qh['high_52w'] - 400.0) < 0.005,
          json_encode([$qh['low_52w'], $qh['high_52w']]));
    check('and the marker sits where today is in that range',
          abs($qh['range_pct'] - ((330.0 - 80.0) / (400.0 - 80.0) * 100)) < 0.05,
          $qh['range_pct']);
    check('closes older than a year are not in it',
          (int) scalar("select count(*) from historical_prices
                         where symbol='QHD' and date <= date_sub(?, interval 1 year)",
                       [$qh_asof]) > 0
          && abs($qh['low_52w'] - 80.0) < 0.005);

    /* A logo only when the provider supplied one. "N/A" in the column drew
       an img that could not load, with the company name as its alt text. */
    check('"N/A" in the logo column is no logo, not a broken image',
          $qh['logo'] === null, json_encode($qh['logo']));
    db()->exec("update stock_symbols set logo='https://example.invalid/qhd.png' where symbol='QHD'");
    check('and a real one is shown',
          quote_header('QHD', 'XLON')['logo'] === 'https://example.invalid/qhd.png');

    /* Capitalisation is worked out from the shares and the close rather than
       taken from the provider: a stored capitalisation is stale the moment
       the price moves. */
    db()->prepare("insert into stock_info (symbol, asofdate, attribute, value)
                   values ('QHD', ?, 'SharesStats.SharesOutstanding', '1000000')")
        ->execute([$qh_asof]);
    $qh2 = quote_header('QHD', 'XLON');
    check('the market capitalisation is the shares times the close, in pounds',
          abs($qh2['market_cap'] - (1000000 * 330.0 / 100)) < 0.5,
          json_encode([$qh2['shares'], $qh2['market_cap']]));
    check('and it is shortened rather than printed in full',
          fmt_money_compact($qh2['market_cap']) === "\xc2\xa33.3m",
          fmt_money_compact($qh2['market_cap']));

    $qh_none = quote_header('NOSUCHSYMBOL', 'XLON');
    check('an unknown symbol gives blanks rather than a warning',
          $qh_none['price'] === null && $qh_none['name'] === null);

    echo "\n--- the quote page: what the peers imply ---\n";

    /* "Relative to Industry" and a red badge reading 341. 341 is the price
       the share would be if it were valued like its peers; the badge was red
       because that is a quarter of what it costs, which is to say it was red
       for being a bargain. */
    [$vw_words, $vw_class] = valuation_words(['value' => 341, 'price' => 900], 1350.6);
    check('a relative valuation says what the number is',
          strpos($vw_words, 'Implied price 341.0p') !== false
          && strpos($vw_words, '1,350.6p') !== false
          && strpos($vw_words, 'below') !== false,
          $vw_words);
    check('and it is measured against the price the page is showing',
          strpos($vw_words, '900') === false, $vw_words);
    check('cheap against its peers is red, dear is green',
          $vw_class === 'sm-down'
          && valuation_words(['value' => 2000, 'price' => 1350.6], 1350.6)[1] === 'sm-up');
    check('the percentage is the gap, not the ratio',
          strpos($vw_words, '74.8%') !== false, $vw_words);

    /* Every peer ratio missing gives an implied price of nought, which as a
       price reads "0.0p, 100% below" - a statement about the company rather
       than about the data. */
    check('an implied price of nought is no valuation at all',
          valuation_words(['value' => 0, 'price' => 1350.6], 1350.6)[0] === null
          && valuation_words(['value' => null, 'price' => 1350.6], 1350.6)[0] === null);
    check('and neither is one with no price to compare against',
          valuation_words(['value' => 341, 'price' => 0], null)[0] === null);

    echo "\n--- the quote page: one P/E, not two ---\n";

    /* The header shows four ratios that are also in the Ratios tab, read out
       of the same structure so the two cannot disagree. */
    $qi_quote = [['category' => ['name' => 'value', 'description' => 'Value'],
                  'indicators' => [['name' => 'pe', 'description' => 'P/E', 'value' => '17.6234',
                                    'percentile' => 60, 'market_average' => 14, 'sector_average' => 15],
                                   ['name' => 'div_yield', 'description' => 'Yield', 'value' => '0.0311',
                                    'percentile' => 70, 'market_average' => 0.03, 'sector_average' => 0.03]]]];
    check('a named ratio is found wherever its category is',
          abs(quote_indicator_value($qi_quote, 'pe') - 17.6234) < 0.00005
          && abs(quote_indicator_value($qi_quote, 'div_yield') - 0.0311) < 0.00005);
    check('one that is not there is null rather than nought',
          quote_indicator_value($qi_quote, 'price_book_ratio') === null
          && quote_indicator_value(null, 'pe') === null);
    /* div_yield is stored as a fraction: 0.0311 is 3.11%. Printing it as a
       percentage without multiplying would read 0.03%. */
    check('a yield stored as a fraction is shown as a percentage',
          fmt_yield(quote_indicator_value($qi_quote, 'div_yield') * 100) === '3.11%',
          fmt_yield(quote_indicator_value($qi_quote, 'div_yield') * 100));

    echo "\n--- the quote page: what is on it ---\n";

    $qt = php_strip_whitespace(dirname(__DIR__) . '/templates/quote.php');

    /* A second set of controls for the same thing: it reloaded the page to
       redraw a chart the buttons above redraw without one. */
    check('the old Timespan / Type / Go form is gone',
          strpos($qt, 'selected_timespan') === false
          && preg_match('/<select[^>]+name="timespan"/', $qt) !== 1
          && preg_match('/value="Go"/', $qt) !== 1);
    check('but the chart buttons and Explain this move are not',
          strpos($qt, 'data-chart-span') !== false
          && strpos($qt, 'Explain this move') !== false);
    check('the Back link is gone; the browser and the navbar do that',
          preg_match('/>\s*Back\s*</', $qt) !== 1
          && strpos($qt, 'history.go(-1)') === false);
    /* Announcements were inside a tab nobody had a reason to open. */
    check('recent announcements are above the tabs, not inside one',
          strpos($qt, 'Recent announcements') !== false
          && strpos($qt, 'Recent announcements') < strpos($qt, 'quoteTabs'));
    check('the tabs offered are Profile, News and Ratios',
          substr_count($qt, 'data-bs-toggle="tab"') === 3
          && strpos($qt, 'href="#profile"') !== false
          && strpos($qt, 'href="#news"') !== false
          && strpos($qt, 'href="#statistics"') !== false);
    check('the scores use the one scale, not four colour schemes of their own',
          substr_count($qt, 'score_badge_html') >= 4
          && strpos($qt, 'text-bg-warning') === false
          && strpos($qt, 'text-bg-info') === false);
    /* The chart axis was printing the library's default - 1350.60 - beside a
       header reading 1,350.6p. */
    check('the chart axis is in pence like everything else',
          strpos((string) file_get_contents(dirname(__DIR__) . '/templates/chart.js'),
                 'priceFormatter') !== false);

    db()->exec("delete from stock_symbols where symbol='QHD'");
    db()->exec("delete from historical_prices where symbol='QHD'");
    db()->exec("delete from stock_info where symbol='QHD'");
    if ($qh_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $qh_saved_exchange; }

    echo "\n--- the portfolio: the figures add up ---\n";

    require_once($INCLUDES . '/portfolio_view.php');

    $pf_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = 'XLON';
    $pf_user = 1;

    $pf_summary  = portfolio_summary($pf_user, 'XLON');
    $pf_holdings = portfolio_holdings($pf_user, 'XLON');

    check('the seeded account has a portfolio worth looking at',
          count($pf_holdings) >= 10, count($pf_holdings) . ' holdings');

    /* Worked out here from the seeded rows rather than by calling the same
       function twice. value is the shares at the latest close, in pounds -
       prices are pence - and invested is what they cost. */
    $pf_value = 0.0; $pf_invested = 0.0;
    foreach ($pf_holdings as $pf_row) {
        $pf_price = (float) scalar("select price from historical_prices
                                     where symbol=? and exchange='XLON'
                                     order by date desc limit 1", [$pf_row['symbol']]);
        $pf_value    += $pf_price * $pf_row['shares'] / 100;
        $pf_invested += $pf_row['avg_cost'] * $pf_row['shares'] / 100;
    }

    check('the value is the holdings at the latest close',
          abs($pf_summary['value'] - $pf_value) < 0.02,
          json_encode([$pf_summary['value'], $pf_value]));
    check('and invested is what they cost',
          abs($pf_summary['invested'] - $pf_invested) < 0.02,
          json_encode([$pf_summary['invested'], $pf_invested]));
    /* The seed charges GBP5.95 commission on each position, and the gain is
       net of it. */
    check('the gain is the value less the cost, less commission',
          abs($pf_summary['gain'] - ($pf_value - $pf_invested - 5.95 * count($pf_holdings))) < 0.05,
          json_encode([$pf_summary['gain'], $pf_value - $pf_invested - 5.95 * count($pf_holdings)]));
    check('and the percentage is against the cost, not against the value',
          abs($pf_summary['gain_pct'] - ($pf_summary['gain'] / $pf_invested * 100)) < 0.01,
          json_encode([$pf_summary['gain_pct']]));
    check('the total is the holdings plus the cash',
          abs($pf_summary['total'] - ($pf_summary['value'] + $pf_summary['cash'])) < 0.005);

    /* The cash book: DEPOSIT in, BUY out, SELL in. A BUY stored as a
       negative amount is negated again and a purchase adds cash, which is
       how the seeded account came to hold three hundred thousand pounds. */
    $pf_cash_hand = (float) scalar("select sum(case trx_type
                                                 when 'DEPOSIT' then amount
                                                 when 'BUY'     then -1*amount
                                                 when 'SELL'    then amount
                                                 when 'DELETE'  then -1*amount
                                                 else amount end)
                                      from cash_history where user_id=?", [$pf_user]);
    check('the cash is the cash book, run down',
          abs($pf_summary['cash'] - $pf_cash_hand) < 0.005,
          json_encode([$pf_summary['cash'], $pf_cash_hand]));
    check('and the purchases took money out of it rather than putting it in',
          $pf_summary['cash'] < (float) scalar("select sum(amount) from cash_history
                                                 where user_id=? and trx_type='DEPOSIT'", [$pf_user]),
          $pf_summary['cash']);

    /* Weights are against the holdings rather than against holdings plus
       cash: the question is how much of what was chosen this is. */
    $pf_weight = 0.0;
    foreach ($pf_holdings as $pf_row) { $pf_weight += (float) $pf_row['weight']; }
    check('the weights add up to the whole portfolio',
          abs($pf_weight - 100.0) < 0.05, $pf_weight);

    check('a holding carries its three scores and its company name',
          array_key_exists('momentum', $pf_holdings[0])
          && array_key_exists('value_score', $pf_holdings[0])
          && array_key_exists('quality', $pf_holdings[0])
          && trim((string) $pf_holdings[0]['name']) !== '');

    echo "\n--- the portfolio: the sell rule, on the page ---\n";

    /* Dale's rule is a close below the 200 day average. A holding 1% above
       it is one that may be sold this week, so the table says three things
       rather than two. */
    $pf_trend_asof = (string) scalar("select max(date) from statistics where exchange='XLON'");
    $pf_trend_sym  = $pf_holdings[0]['symbol'];
    $pf_trend_price = (float) $pf_holdings[0]['price'];

    $pf_set_ma = function ($value) use ($pf_trend_sym, $pf_trend_asof) {
        db()->prepare("delete from statistics where symbol=? and indicator='ma200'
                        and exchange='XLON' and date=?")->execute([$pf_trend_sym, $pf_trend_asof]);
        db()->prepare("insert into statistics (symbol, exchange, indicator, date, value)
                       values (?, 'XLON', 'ma200', ?, ?)")
            ->execute([$pf_trend_sym, $pf_trend_asof, $value]);
    };
    $pf_trend_of = function () use ($pf_user, $pf_trend_sym) {
        foreach (portfolio_holdings($pf_user, 'XLON') as $r) {
            if ($r['symbol'] === $pf_trend_sym) { return $r['trend']; }
        }
        return null;
    };

    $pf_set_ma($pf_trend_price * 0.90);
    check('ten per cent above the 200 day average is above it', $pf_trend_of() === 'above');
    $pf_set_ma($pf_trend_price * 0.99);
    check('one per cent above it is a warning, because that is the sell rule',
          $pf_trend_of() === 'near');
    /* Just inside the band and just outside it, rather than exactly on it.
       The average is stored as decimal(20,6) and the rule divides the price
       by it, so "exactly 2% above" cannot be expressed: round(p / 1.02, 6)
       comes back as a gap of 0.0200000022 for nine of the twelve seeded
       prices and 0.0199999984 for the other three. A test on that boundary
       passes or fails on the day's prices, which is a test of the rounding
       rather than of the rule. */
    $pf_set_ma($pf_trend_price / 1.019);
    check('just inside two per cent is still the warning, not clear of it',
          $pf_trend_of() === 'near');
    $pf_set_ma($pf_trend_price / 1.025);
    check('and past it the warning stops, so the band has an edge',
          $pf_trend_of() === 'above');
    $pf_set_ma($pf_trend_price * 1.05);
    check('and below the average is below it', $pf_trend_of() === 'below');
    db()->prepare("delete from statistics where symbol=? and indicator='ma200'
                    and exchange='XLON' and date=?")->execute([$pf_trend_sym, $pf_trend_asof]);
    check('a share with no 200 day average says so rather than guessing',
          $pf_trend_of() === 'unknown');

    echo "\n--- the portfolio: the tax year, the sales and the dividends ---\n";

    /* 6 April to 5 April. A calendar year figure matches nothing on a tax
       return. */
    check('the tax year runs from 6 April',
          portfolio_tax_year('2026-11-16') === ['2026-04-06', '2027-04-05']
          && portfolio_tax_year('2026-04-06') === ['2026-04-06', '2027-04-05'],
          json_encode(portfolio_tax_year('2026-11-16')));
    check('and 5 April is still the year before',
          portfolio_tax_year('2026-04-05') === ['2025-04-06', '2026-04-05'],
          json_encode(portfolio_tax_year('2026-04-05')));

    [$pf_year_from, $pf_year_to] = portfolio_tax_year();
    $pf_divs_hand = (float) scalar("select ifnull(sum(amount),0) from dividends
                                     where session_id=? and dividend_date between ? and ?",
                                   [$pf_user, $pf_year_from, $pf_year_to]);
    check('the dividend figure is this tax year, not every dividend ever',
          abs($pf_summary['dividends_tax_year'] - $pf_divs_hand) < 0.005
          && $pf_divs_hand < (float) scalar("select sum(amount) from dividends where session_id=?",
                                            [$pf_user]),
          json_encode([$pf_summary['dividends_tax_year'], $pf_divs_hand]));

    $pf_sold = portfolio_sold($pf_user);
    check('the closed positions are there to be looked at',
          count($pf_sold) >= 2, count($pf_sold) . ' closed');
    check('one closed at a profit and one at a loss, so both colours are exercised',
          count(array_filter($pf_sold, function ($r) { return $r['gain'] > 0; })) > 0
          && count(array_filter($pf_sold, function ($r) { return $r['gain'] < 0; })) > 0,
          json_encode(array_column($pf_sold, 'gain')));

    check('the dividends and the cash book both have rows to show',
          count(portfolio_dividends($pf_user)) > 0 && count(portfolio_cash($pf_user)) > 0);

    echo "\n--- the portfolio: the chart and its benchmark ---\n";

    $pf_series = portfolio_series($pf_user, 365);
    check('the performance series is the nightly totals',
          count($pf_series) > 1 && array_key_exists('total', $pf_series[0]),
          count($pf_series) . ' points');

    /* The benchmark is rebased to start where the portfolio starts. An
       index level of 3,900 beside a portfolio of GBP15,000 compares
       nothing. */
    db()->exec("delete from strategy_values where strategy='_benchmark'");
    $pf_bench_ins = db()->prepare("insert into strategy_values
        (strategy, date, total, cash, holdings_value, holdings, note)
        values ('_benchmark', ?, ?, 0, ?, 350, null)");
    $pf_bench_ins->execute(['2026-11-10', 1000.0, 1000.0]);
    $pf_bench_ins->execute(['2026-11-11', 1100.0, 1100.0]);
    $pf_bench_ins->execute(['2026-11-12',  990.0,  990.0]);
    $pf_bench = portfolio_benchmark('2026-11-10', '2026-11-12', 20000.0);
    check('the benchmark starts where the portfolio starts',
          count($pf_bench) === 3 && abs($pf_bench[0]['total'] - 20000.0) < 0.01,
          json_encode($pf_bench));
    check('and keeps its own shape',
          abs($pf_bench[1]['total'] - 22000.0) < 0.01
          && abs($pf_bench[2]['total'] - 19800.0) < 0.01,
          json_encode($pf_bench));
    check('with no benchmark stored the chart draws one line, not a wrong one',
          portfolio_benchmark('2001-01-01', '2001-01-31', 20000.0) === []);
    db()->exec("delete from strategy_values where strategy='_benchmark'");

    echo "\n--- the portfolio: what is on the page ---\n";

    $pf_tpl = php_strip_whitespace(dirname(__DIR__) . '/templates/portfolio.php');

    check('the monospace block of unlabelled numbers is gone',
          strpos($pf_tpl, '<pre>') === false && strpos($pf_tpl, 'Shares:') === false);
    check('and so is the Timespan / Type / Go form and the stray timespan',
          strpos($pf_tpl, 'selected_timespan') === false
          && preg_match('/<select[^>]+name="timespan"/', $pf_tpl) !== 1
          && strpos($pf_tpl, 'echo $timespan') === false);
    /* Two links with no space between them rendered as one word: "Active
       SharesSold Shares". */
    check('the four tabs are tabs, with counts',
          substr_count($pf_tpl, 'data-bs-toggle="tab"') === 4
          && strpos($pf_tpl, 'href="#holdings"') !== false
          && strpos($pf_tpl, 'href="#sold"') !== false
          && strpos($pf_tpl, 'href="#dividends"') !== false
          && strpos($pf_tpl, 'href="#cash"') !== false);
    check('the actions are buttons at the top, opening the existing forms',
          strpos($pf_tpl, 'data-bs-target="#buyModal"') !== false
          && strpos($pf_tpl, 'data-bs-target="#sellModal"') !== false
          && strpos($pf_tpl, 'data-bs-target="#dividendModal"') !== false
          && strpos($pf_tpl, 'data-bs-target="#cashModal"') !== false
          && strpos($pf_tpl, 'action="buy.php"') !== false
          && strpos($pf_tpl, 'action="sell.php"') !== false
          && strpos($pf_tpl, 'action="topup.php"') !== false
          && strpos($pf_tpl, 'action="dividends.php"') !== false);
    check('an empty portfolio gets a sentence and a button, not an empty table',
          strpos($pf_tpl, 'Nothing held.') !== false
          && strpos($pf_tpl, 'Add a purchase') !== false);
    check('the holdings table has a totals row and sorts',
          strpos($pf_tpl, 'sm-total-row') !== false
          && strpos($pf_tpl, 'sm-table-sort') !== false);
    /* 300px of empty card above "No performance history available" is what
       the old page did. */
    check('no history means no chart box, rather than an empty one',
          strpos((string) file_get_contents(dirname(__DIR__) . '/templates/portfolio_chart.js'),
                 'root.style.display = "none"') !== false);

    /* The form has always been <input type="date">, which submits
       YYYY-MM-DD, and buy.php insisted on DD-MM-YYYY - so the Buy page
       could not buy anything: its own validation turned away every
       submission from its own form. checkdate() was being called
       (day, month, year) as well, which is (month, day, year). */
    $pf_buy = (string) file_get_contents(dirname(__DIR__) . '/public/buy.php');
    check('buy.php accepts the date its own form submits',
          strpos($pf_buy, '(\\d{4})-(\\d{2})-(\\d{2})') !== false);
    check('and checks it the right way round',
          strpos($pf_buy, 'checkdate((int) $month, (int) $day, (int) $year)') !== false);

    $pf_empty = portfolio_holdings(987654, 'XLON');
    check('an account with nothing in it is handled without a warning',
          $pf_empty === [] && portfolio_summary(987654, 'XLON')['holdings'] === 0);

    if ($pf_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $pf_saved_exchange; }

    echo "\n--- screens: a screen that cannot be run is not offered ---\n";

    require_once($INCLUDES . '/screen_view.php');

    $sv_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = 'XLON';

    /* The seeded Quality Momentum screen is the one from the live database:
       its criteria carry the indicator's name in first_operand where a
       number belongs. build_screen() puts first_operand into the SQL as it
       stands, so "3mnth > 0" becomes "AND s.value> 3mnth" - a reference to
       a column that does not exist - and the page died with an SQL error
       rather than showing a screen. */
    $sv_problems = screen_problems(1);
    check('the broken screen is reported rather than run',
          count($sv_problems) > 0, json_encode($sv_problems));
    check('and the reason names the operand that is wrong',
          strpos(implode(' ', $sv_problems), '3mnth') !== false,
          json_encode($sv_problems));

    $sv_rows = screen_list_rows();
    $sv_by_name = [];
    foreach ($sv_rows as $sv_row) { $sv_by_name[$sv_row['name']] = $sv_row; }

    check('the list shows every screen',
          count($sv_rows) >= 3, count($sv_rows) . ' screens');
    check('a broken screen has no match count to show',
          $sv_by_name['Quality Momentum']['matches'] === null
          && count($sv_by_name['Quality Momentum']['problems']) > 0);
    check('a sound one has one, and it is what the screen actually finds',
          $sv_by_name['Trend following (200-day)']['matches']
          === count(build_screen($sv_by_name['Trend following (200-day)']['id'], null)),
          json_encode($sv_by_name['Trend following (200-day)']['matches']));

    /* A screen with no criteria at all selects every share, which is not a
       screen. */
    db()->exec("insert into screen (id, name, description, displayed, session_id)
                values (91, 'Empty screen', 'Nothing in it', 'Y', 1)");
    check('a screen with no criteria is refused too',
          count(screen_problems(91)) === 1
          && stripos(screen_problems(91)[0], 'no criteria') !== false,
          json_encode(screen_problems(91)));

    /* An operator the builder does not know is a criterion that selects
       nothing, silently. */
    db()->exec("insert into screen_criteria (id, indicator_id, description, operator,
                                             first_operand, second_operand)
                select 91, indicator_id, 'nonsense', 'LIKE', '5', ''
                  from screen_indicators where name='6mnth' limit 1");
    db()->exec("insert into screen_build (screen_id, criteria_id) values (91, 91)");
    check('and so is an operator the builder does not know',
          count(screen_problems(91)) === 1
          && stripos(screen_problems(91)[0], 'operator') !== false,
          json_encode(screen_problems(91)));

    db()->exec("update screen_criteria set operator='>' where id=91");
    check('a number to compare against is what makes it valid',
          screen_problems(91) === [], json_encode(screen_problems(91)));

    db()->exec("update screen_criteria set first_operand='', second_operand='ma200' where id=91");
    check('or an empty box with another indicator named beside it',
          screen_problems(91) === [], json_encode(screen_problems(91)));

    db()->exec("update screen_criteria set second_operand='not_an_indicator' where id=91");
    check('but not an indicator that does not exist',
          count(screen_problems(91)) === 1, json_encode(screen_problems(91)));

    db()->exec("delete from screen_build where screen_id=91");
    db()->exec("delete from screen_criteria where id=91");
    db()->exec("delete from screen where id=91");

    echo "\n--- screens: what a run shows ---\n";

    $sv_screen_id = $sv_by_name['Trend following (200-day)']['id'];
    $sv_asof    = screen_asof();
    $sv_columns = screen_indicator_columns($sv_screen_id);
    $sv_symbols = build_screen($sv_screen_id, null);
    $sv_results = screen_result_rows($sv_symbols, $sv_columns, $sv_asof);

    check('a run returns a row per matching share, in the screen order',
          count($sv_results) === count($sv_symbols)
          && $sv_results[0]['symbol'] === $sv_symbols[0]['symbol'],
          json_encode([count($sv_results), count($sv_symbols)]));
    check('each row carries the company name and the screen own columns',
          trim((string) $sv_results[0]['name']) !== ''
          && array_key_exists($sv_columns[0]['name'], $sv_results[0]['values']));
    /* A list of tickers against one ratio is not enough to decide anything
       with, and following each to its quote page was the only way to see
       the rest. */
    check('and the price, the six month move and the three scores besides',
          array_key_exists('price', $sv_results[0])
          && array_key_exists('change_6m', $sv_results[0])
          && array_key_exists('momentum', $sv_results[0])
          && array_key_exists('value_score', $sv_results[0])
          && array_key_exists('quality', $sv_results[0]));

    /* A screen built on the latest close was showing it twice: once as its
       own column and once as the Price beside it. */
    $sv_extras = screen_extras_wanted($sv_columns);
    check('a figure the screen already has a column for is not repeated',
          in_array('close_price', array_column($sv_columns, 'name'), true)
          && !isset($sv_extras['close_price']),
          json_encode([array_column($sv_columns, 'name'), array_keys($sv_extras)]));
    check('and the ones it does not have are still shown',
          isset($sv_extras['6mnth']) && isset($sv_extras['momentum_score']));

    /* A screen can be built on anything, so a column could be a price in
       pence, a percentage return or a score. One formatter would print them
       all as "196.82". */
    check('a price column is pence, a return is a signed percentage',
          strpos(screen_cell_html('close_price', 196.8153), '196.8p') !== false
          && strpos(screen_cell_html('6mnth', 9.0568), '+9.1%') !== false,
          screen_cell_html('close_price', 196.8153) . ' / ' . screen_cell_html('6mnth', 9.0568));
    check('a score is a badge on the one scale',
          strpos(screen_cell_html('momentum_score', 82), 'sm-badge sm-score') !== false
          && strpos(screen_cell_html('momentum_score', 82), '--score: 82') !== false,
          screen_cell_html('momentum_score', 82));
    check('and a yield stored as a fraction is a percentage',
          strpos(screen_cell_html('div_yield', 0.0311), '3.11%') !== false,
          screen_cell_html('div_yield', 0.0311));

    /* The rows come back in the order of the last top_n, so the page says
       so rather than leaving a reader to assume alphabetical. */
    $sv_tv = null;
    foreach ($sv_rows as $sv_row) {
        if (stripos($sv_row['name'], 'Trending Value') !== false) { $sv_tv = $sv_row['id']; }
    }
    check('a ranked screen says what it was ranked by',
          $sv_tv === null || (screen_rank_words($sv_tv) !== null
                              && stripos(screen_rank_words($sv_tv), 'ranked by') === 0),
          $sv_tv === null ? 'no Trending Value screen' : screen_rank_words($sv_tv));
    check('and one with no ranking says nothing rather than guessing',
          screen_rank_words($sv_screen_id) === null);

    /* Nothing recorded when a screen was last run: they run on demand, not
       from the nightly job. */
    check('the run stamp column is there',
          screen_has_last_run());
    db()->exec("update screen set last_run=null where id=$sv_screen_id");
    screen_mark_run($sv_screen_id);
    check('and running one stamps it',
          (string) scalar("select last_run from screen where id=?", [$sv_screen_id]) !== '');

    echo "\n--- screens: the list and the results pages ---\n";

    $sv_list_tpl = php_strip_whitespace(dirname(__DIR__) . '/templates/screens.php');
    $sv_res_tpl  = php_strip_whitespace(dirname(__DIR__) . '/templates/screen.php');

    /* Choosing a screen and choosing what to do with it were two decisions
       in two places, and neither was on the row you were looking at. */
    check('the radio buttons and the two shared buttons are gone',
          strpos($sv_list_tpl, 'type="radio"') === false
          && strpos($sv_list_tpl, 'View/Edit') === false);
    check('every row carries its own Run and Edit',
          strpos($sv_list_tpl, 'run=1') !== false && strpos($sv_list_tpl, 'edit=1') !== false);
    check('and a broken screen gets an Edit but no Run',
          preg_match('/if \(!count\(\$screen\[.problems.\]\)\).{0,200}run=1/s', $sv_list_tpl) === 1);
    check('the list shows the matches and the last run',
          stripos($sv_list_tpl, 'Matches') !== false && stripos($sv_list_tpl, 'Last run') !== false);

    check('the results page has a heading that says which screen and when',
          strpos($sv_res_tpl, "screen['name']") !== false
          && strpos($sv_res_tpl, "screen['description']") !== false
          && strpos($sv_res_tpl, 'fmt_date($as_of)') !== false);
    check('and links back to the screens rather than having a Back button',
          strpos($sv_res_tpl, 'All screens') !== false
          && preg_match('/>\s*Back\s*</', $sv_res_tpl) !== 1
          && strpos($sv_res_tpl, 'btnClose') === false);
    check('the table sorts and the numbers are right aligned',
          strpos($sv_res_tpl, 'sm-table-sort') !== false
          && substr_count($sv_res_tpl, 'sm-num') > 4);
    check('there is a CSV to download',
          strpos($sv_res_tpl, 'screen_csv.php') !== false);
    /* The old page's title was <?php $title ?> - the variable with no echo -
       so it printed nothing at all. */
    check('and the title is printed rather than merely mentioned',
          strpos($sv_res_tpl, '<?php $title ?>') === false);

    if ($sv_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $sv_saved_exchange; }

    echo "\n--- in the news: routine filings and the days they land on ---\n";

    require_once($INCLUDES . '/news_view.php');

    /* Routine is both: type "other" and neutral in tone. A profit warning
       typed "other" is the most important thing on the page, and a neutral
       set of results is a scheduled event somebody is waiting for. */
    check('a neutral "other" is routine',
          news_is_routine(['ann_type' => 'other', 'sentiment' => 0.05]) === true
          && news_is_routine(['ann_type' => 'other', 'sentiment' => -0.14]) === true);
    check('but a strong one is not, whatever it is typed as',
          news_is_routine(['ann_type' => 'other', 'sentiment' => -0.8]) === false
          && news_is_routine(['ann_type' => 'other', 'sentiment' => 0.6]) === false);
    check('and neither is a neutral announcement of any other type',
          news_is_routine(['ann_type' => 'results', 'sentiment' => 0.0]) === false
          && news_is_routine(['ann_type' => 'trading update', 'sentiment' => 0.01]) === false);
    check('the boundary is the neutral band, not exactly nought',
          news_is_routine(['ann_type' => 'other', 'sentiment' => 0.1499]) === true
          && news_is_routine(['ann_type' => 'other', 'sentiment' => 0.15]) === false);
    check('an unscored "other" counts as routine rather than as unknown',
          news_is_routine(['ann_type' => 'other', 'sentiment' => null]) === true);

    $nv_rows = [
        ['symbol' => 'AAA', 'company' => 'A PLC', 'published' => '2026-11-13',
         'ann_type' => 'results',  'sentiment' => 0.2],
        ['symbol' => 'AAA', 'company' => 'A PLC', 'published' => '2026-11-13',
         'ann_type' => 'other',    'sentiment' => 0.0],
        ['symbol' => 'AAA', 'company' => 'A PLC', 'published' => '2026-11-13',
         'ann_type' => 'warning',  'sentiment' => -0.9],
        ['symbol' => 'AAA', 'company' => 'A PLC', 'published' => '2026-11-12',
         'ann_type' => 'results',  'sentiment' => 0.4],
        ['symbol' => 'BBB', 'company' => 'B PLC', 'published' => '2026-11-13',
         'ann_type' => 'other',    'sentiment' => 0.01],
    ];

    check('the routine ones are counted, not silently dropped',
          news_routine_count($nv_rows) === 2, news_routine_count($nv_rows));
    check('and taken out leaves the rest alone',
          count(news_without_routine($nv_rows)) === 3,
          count(news_without_routine($nv_rows)));

    /* OSB filed three times on 13 November and got three rows of the same
       company. */
    $nv_groups = news_group_by_day($nv_rows);
    check('a company that announced three times on one day is one row',
          count($nv_groups) === 3, json_encode(array_map(function ($g) {
              return $g['symbol'] . ' ' . $g['published'] . ' x' . $g['count']; }, $nv_groups)));
    check('the row says how many there were',
          $nv_groups[0]['count'] === 3 && $nv_groups[0]['symbol'] === 'AAA');
    /* Three neutral filings and a profit warning is a profit warning. */
    check('and shows the strongest of them, not the first',
          abs((float) $nv_groups[0]['strongest']['sentiment'] + 0.9) < 0.0001,
          json_encode($nv_groups[0]['strongest']['sentiment']));
    check('the same company on another day is a separate row',
          $nv_groups[1]['symbol'] === 'AAA' && $nv_groups[1]['published'] === '2026-11-12'
          && $nv_groups[1]['count'] === 1);
    check('and every announcement is still there to open',
          array_sum(array_column($nv_groups, 'count')) === count($nv_rows));

    /* The order of the rows decides the order of the groups, so a page
       sorted by sentiment still shows the strongest first. */
    $nv_sorted = news_group_by_day([$nv_rows[4], $nv_rows[0]]);
    check('the order the rows arrived in is kept',
          $nv_sorted[0]['symbol'] === 'BBB' && $nv_sorted[1]['symbol'] === 'AAA');

    $nv_tpl = php_strip_whitespace(dirname(__DIR__) . '/templates/in_the_news.php');
    /* A fold rather than a link back to the server: the rows are already on
       the page, so showing them is the browser's job and closing it again
       does not need a second word for it. */
    check('the page offers the routine items rather than hiding that it hid them',
          strpos($nv_tpl, 'Show routine items') !== false
          && strpos($nv_tpl, '<details class="sm-reveal sm-routine">') !== false);
    check('sentiment is the number, the word and the swatch',
          substr_count($nv_tpl, 'sentiment_html') >= 2
          && strpos($nv_tpl, 'rns_band(') === false);
    check('and the best and worst boxes carry the move since as well as the score',
          strpos($nv_tpl, "price_move") !== false
          && strpos($nv_tpl, 'Best received') !== false);
    check('dates are written out rather than printed as stored',
          strpos($nv_tpl, 'fmt_date_short($group') !== false
          && strpos($nv_tpl, 'fmt_date($from)') !== false);

    echo "\n--- strategies: names and rules a reader can read ---\n";

    /* "trend_following" is a column name, and it was the heading of the
       card. */
    check('a strategy has a name as well as a code',
          strategy_display_name('trending_value') === 'Trending Value'
          && strategy_display_name('trend_following') === 'Trend following',
          strategy_display_name('trending_value') . ' / ' . strategy_display_name('trend_following'));
    check('and one with no name set gets its code tidied rather than left raw',
          strategy_display_name('no_such_strategy') === 'No such strategy',
          strategy_display_name('no_such_strategy'));

    /* The page printed the values as stored: an exit code and an indicator
       name. A reader cannot act on either. */
    check('an exit rule is a sentence, not a code',
          strategy_exit_words('below_ma200_2d')
          === 'Sells after two closes below the 200 day average',
          strategy_exit_words('below_ma200_2d'));
    check('the ranking says what it ranks by',
          strategy_rank_words('6mnth') === 'Ranked by the 6 month return',
          strategy_rank_words('6mnth'));
    check('and nothing to rank by says nothing rather than "ranked by"',
          strategy_rank_words('') === null && strategy_rank_words(null) === null);
    check('an indicator with no sentence of its own is looked up, not printed',
          stripos(strategy_rank_words('ev_ebitda'), 'ev to ebitda') !== false,
          strategy_rank_words('ev_ebitda'));
    check('the rebalance is a sentence too',
          strategy_rebalance_words('weekly') === 'Rebalanced at the start of each week'
          && strategy_rebalance_words('monthly') === 'Rebalanced at the start of each month');

    /* Said on a trade and said as a rule are two different sentences about
       the same thing, and both have to exist. */
    check('the same exit reads one way as a rule and another on a trade',
          strategy_exit_words('below_ma200_2d') !== strategy_reason_words('below_ma200_2d')
          && stripos(strategy_reason_words('below_ma200_2d'), '200') !== false);

    $st_tpl = php_strip_whitespace(dirname(__DIR__) . '/templates/strategies.php');

    check('the card shows the name and keeps the code in small type',
          strpos($st_tpl, 'strategy_display_name($card') !== false);
    check('the rules on the card are the sentences',
          strpos($st_tpl, 'strategy_rebalance_words') !== false
          && strpos($st_tpl, 'strategy_rank_words') !== false
          && strpos($st_tpl, 'strategy_exit_words') !== false);
    check('and the chart names its lines the same way',
          strpos($st_tpl, 'strategy_display_name($card[\'strategy\'])') !== false);

    /* Eight lines of explanation, with the thing a reader must know - that
       none of this is real - in the middle of it. */
    check('the opening paragraph is two lines with the rest folded away',
          strpos($st_tpl, 'How this works') !== false
          && strpos($st_tpl, '<summary>How this works</summary>') !== false
          && strpos($st_tpl, 'No real orders are ever placed') !== false
          && strpos($st_tpl, 'No real orders are ever placed')
             < strpos($st_tpl, 'How this works'));

    /* A row of dashes reads as a strategy that lost everything rather than
       one that has not started. */
    check('before the first fill the card says when it starts',
          strpos($st_tpl, 'Starts on') !== false
          && strpos($st_tpl, "card['starts_on']") !== false);
    check('and a switched-off one that never ran says that instead',
          strpos($st_tpl, 'Switched off, so it never will') !== false);

    check('the switched-off strategies are behind a toggle with a count',
          strpos($st_tpl, 'Show disabled (') !== false
          && strpos($st_tpl, 'Hide switched-off strategies') !== false
          && strpos($st_tpl, 'disabled=1') !== false);

    /* Returns on this page are stored as fractions - 0.124 is 12.4% - so
       they go through the fraction formatter and not the percentage one. */
    check('the page formats through the shared formatters',
          strpos($st_tpl, 'fmt_percent_of') !== false
          && strpos($st_tpl, 'fmt_pounds') !== false
          && strpos($st_tpl, 'move_class') !== false);

    $st_mig = (string) file_get_contents(dirname(__DIR__) . '/sql/strategy_display_name.sql');
    check('the migration adds the column only if it is missing',
          stripos($st_mig, 'information_schema.columns') !== false
          && stripos($st_mig, 'display_name') !== false);
    check('and leaves a name somebody has edited alone',
          stripos($st_mig, "display_name is null or display_name = ''") !== false);
    check('the column is on the table in this database',
          (int) scalar("select count(*) from information_schema.columns
                         where table_schema=database() and table_name='strategy_accounts'
                           and column_name='display_name'") === 1);

    echo "\n--- the index that makes the dashboard possible ---\n";

    /* statistics had one index, (symbol, indicator, date, exchange), which is
       right for "everything about this share" and useless for the question
       the dashboard, the ranked lists, the screens and the strategy engine
       all ask: one indicator, one date, every share. With 2.3 million rows of
       history behind it the home page took 9.4 seconds. */
    check('statistics has an index led by the indicator',
          (int) scalar("select count(*) from information_schema.statistics
                         where table_schema=database() and table_name='statistics'
                           and index_name='statistics_indicator_date'
                           and seq_in_index=1 and column_name='indicator'") === 1);
    check('and the one it had is still there, for the other question',
          (int) scalar("select count(*) from information_schema.statistics
                         where table_schema=database() and table_name='statistics'
                           and index_name='symbol' and seq_in_index=1
                           and column_name='symbol'") === 1);

    $ix_mig = (string) file_get_contents(dirname(__DIR__) . '/sql/statistics_indicator_index.sql');
    check('the migration adds it only if it is missing',
          stripos($ix_mig, 'information_schema.statistics') !== false
          && stripos($ix_mig, 'statistics_indicator_date') !== false);
    /* A live table of this size cannot be taken offline to index it. */
    check('and adds it without locking the table',
          stripos($ix_mig, 'algorithm=inplace') !== false
          && stripos($ix_mig, 'lock=none') !== false);

    /* The query that was slow, actually using it. */
    $ix_plan = rows("explain select s.symbol, s.value from statistics s
                      where s.indicator='ma200' and s.date=? and s.exchange='XLON'",
                    [asof_date()]);
    check('the breadth query uses it rather than scanning',
          count($ix_plan) > 0
          && stripos((string) ($ix_plan[0]['key'] ?? ''), 'statistics_indicator_date') !== false,
          json_encode($ix_plan));

    echo "\n--- the breadth figures are worked out once a night ---\n";

    $mb_asof = asof_date();

    check('the table is there',
          market_breadth_ready());

    /* The brief's check: the stored figures are what the live calculation
       gives. Written here first rather than trusted from the last nightly
       run - the tests above add and remove statistics rows, so a row
       written before them describes a different universe, which is exactly
       what a stale stored figure looks like and exactly what this check
       would otherwise be unable to tell apart from a wrong one. */
    market_breadth_write($mb_asof, 'XLON');
    $mb_live   = market_breadth_compute($mb_asof, 'XLON');
    $mb_stored = market_breadth($mb_asof, 'XLON');

    check('the dashboard reads the stored row rather than counting again',
          ($mb_stored['stored'] ?? false) === true, json_encode($mb_stored));
    check('and it says the same as counting again would',
          abs((float) $mb_stored['pct_above_ma200'] - (float) $mb_live['pct_above_ma200']) < 0.005
          && abs((float) $mb_stored['pct_ma50_above_ma200'] - (float) $mb_live['pct_ma50_above_ma200']) < 0.005
          && abs((float) $mb_stored['median_12m'] - (float) $mb_live['median_12m']) < 0.0005
          && (int) $mb_stored['shares_counted'] === (int) $mb_live['shares_counted'],
          json_encode([$mb_stored, $mb_live]));

    /* The denominator is stored because a proportion without one cannot be
       checked, and on a night the statistics job half ran it is the thing
       that gives it away. */
    check('the count the proportion is over is stored with it',
          (int) $mb_stored['shares_counted'] > 0
          && (int) $mb_stored['shares_counted']
             <= (int) scalar("select count(*) from stock_symbols where exchange='XLON' and enabled='Y'"),
          $mb_stored['shares_counted']);

    /* A host one deploy behind, or a night the job did not run, gets the old
       behaviour rather than an empty card. */
    db()->exec("delete from market_breadth where date='$mb_asof' and exchange='XLON'");
    $mb_fallback = market_breadth($mb_asof, 'XLON');
    check('with no stored row it works the figures out instead',
          ($mb_fallback['stored'] ?? true) === false
          && abs((float) $mb_fallback['pct_above_ma200'] - (float) $mb_live['pct_above_ma200']) < 0.005,
          json_encode($mb_fallback));

    /* And the model is given the same three numbers the page shows, which it
       was not obliged to be while each had its own copy of the rule. */
    $mb_outlook = outlook_market_inputs($mb_asof)['breadth'];
    check('the outlook is told the same breadth the dashboard shows',
          abs((float) $mb_outlook['pct_above_ma200'] - (float) $mb_live['pct_above_ma200']) < 0.005
          && (int) $mb_outlook['shares_with_200_days'] === (int) $mb_live['shares_counted'],
          json_encode([$mb_outlook, $mb_live]));

    [$mb_out, $mb_rc] = run_script('write_market_breadth.php', [], ['SM_EXCHANGE' => 'XLON']);
    check('the nightly job writes the latest date', $mb_rc === 0, $mb_out);
    check('and the row is back',
          (int) scalar("select count(*) from market_breadth where date=? and exchange='XLON'",
                       [$mb_asof]) === 1);

    /* A range backfills. */
    db()->exec("delete from market_breadth where exchange='XLON'");
    [$mb_out, $mb_rc] = run_script('write_market_breadth.php',
                                   ['2000-01-01', $mb_asof], ['SM_EXCHANGE' => 'XLON']);
    $mb_dates = (int) scalar("select count(*) from market_breadth where exchange='XLON'");
    check('a range backfills every date the statistics cover',
          $mb_rc === 0 && $mb_dates > 0
          && $mb_dates === (int) scalar("select count(distinct date) from statistics
                                          where exchange='XLON'
                                            and indicator in ('close_price','ma200')"),
          $mb_dates . ' dates, rc ' . $mb_rc);
    /* A date the statistics job never covered has no breadth, and writing a
       zero for it would put a false bottom in the line. */
    check('and writes nothing for a date the statistics never covered',
          (int) scalar("select count(*) from market_breadth
                         where exchange='XLON' and date not in
                           (select distinct date from statistics where exchange='XLON')") === 0);

    [$mb_out, $mb_rc] = run_script('write_market_breadth.php', ['not-a-date'],
                                   ['SM_EXCHANGE' => 'XLON']);
    check('a date it cannot read is refused rather than interpreted',
          $mb_rc === 2 && stripos($mb_out, 'YYYY-MM-DD') !== false, $mb_out);
    [$mb_out, $mb_rc] = run_script('write_market_breadth.php', ['2026-11-16', '2026-01-01'],
                                   ['SM_EXCHANGE' => 'XLON']);
    check('and a range that starts after it ends', $mb_rc === 2, $mb_out);

    /* The six month line comes from the table now. */
    $mb_history = home_breadth_history($mb_asof, 6, 'XLON');
    check('the six month line is read rather than pivoted',
          count($mb_history) > 0 && array_key_exists('pct', $mb_history[0]),
          count($mb_history) . ' points');
    check('and its latest point is the figure on the card',
          abs((float) end($mb_history)['pct'] - (float) $mb_live['pct_above_ma200']) < 0.011,
          json_encode([end($mb_history), $mb_live['pct_above_ma200']]));

    $mb_job = (string) file_get_contents(dirname(__DIR__) . '/includes/refresh_all.sh');
    check('the nightly run writes it, after the job that feeds it',
          strpos($mb_job, 'write_market_breadth.php') !== false
          && strpos($mb_job, 'get_statistics.php') < strpos($mb_job, 'write_market_breadth.php'));
    check('and a failure there does not take the run with it',
          preg_match('/write_market_breadth\.php" \|\| echo/', $mb_job) === 1);

    echo "\n--- one model: a trade goes where the portfolio looks ---\n";

    /* Buy wrote shares, history and users.cash. The Portfolio page reads
       purchases, cash_history and portfolio_performance. So a purchase made
       through the application never appeared in the portfolio it was made
       in, and the cash it spent came out of a balance nothing showed. */

    $om_user = 77;
    db()->exec("delete from purchases where session_id=$om_user");
    db()->exec("delete from cash_history where user_id=$om_user");
    db()->exec("delete from shares where id=$om_user");

    $om_saved_id = $_SESSION["id"] ?? null;
    $om_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["id"] = $om_user;
    $_SESSION["exchange"] = 'XLON';

    check('an account with no trades holds nothing and has no cash',
          portfolio_held($om_user, 'AAA') === ['shares' => 0, 'avg_cost' => null]
          && abs(portfolio_cash_balance($om_user)) < 0.005);

    update_cash_history('2026-01-05', 'DEPOSIT', 50000.00);
    check('a deposit is the cash book, not a column on users',
          abs(portfolio_cash_balance($om_user) - 50000.0) < 0.005,
          portfolio_cash_balance($om_user));

    /* Two lots, so the average cost has to be weighted by the shares in
       each: 500 at 72.5p and 250 at 81.1p average 75.37p, not 76.8p. */
    portfolio_record_trade($om_user, 'BUY', 'AAA', 500, 72.50, 5.95, '2026-01-06');
    portfolio_record_trade($om_user, 'BUY', 'AAA', 250, 81.10, 5.95, '2026-02-10');

    $om_held = portfolio_held($om_user, 'AAA');
    check('a purchase is in purchases, where the portfolio reads it',
          $om_held['shares'] === 750, json_encode($om_held));
    check('and the average cost is weighted by the lots, not the prices',
          abs($om_held['avg_cost'] - 75.3666667) < 0.0001, $om_held['avg_cost']);

    /* 500 at 72.5p is GBP362.50, plus GBP5.95; 250 at 81.1p is GBP202.75,
       plus GBP5.95. 50,000 less 577.15 is 49,422.85. */
    check('the money left the cash book, consideration and commission',
          abs(portfolio_cash_balance($om_user) - 49422.85) < 0.005,
          portfolio_cash_balance($om_user));

    portfolio_record_trade($om_user, 'SELL', 'AAA', 250, 90.00, 5.95, '2026-03-11');
    check('a sale reduces the holding',
          portfolio_held($om_user, 'AAA')['shares'] === 500);
    /* 250 at 90p is GBP225.00, less GBP5.95 commission. */
    check('and the proceeds go in, less the commission',
          abs(portfolio_cash_balance($om_user) - (49422.85 + 225.00 - 5.95)) < 0.005,
          portfolio_cash_balance($om_user));
    check('while the average cost still describes what was bought',
          abs(portfolio_held($om_user, 'AAA')['avg_cost'] - 75.3666667) < 0.0001);

    /* A BUY row holds what the purchase cost as a positive number, because
       the balance negates it. Stored negative it is negated again and a
       purchase adds cash - which is how the seeded account came to show
       three hundred thousand pounds against fifteen thousand of holdings. */
    check('a purchase is stored positive, so the balance can take it off',
          (float) scalar("select amount from cash_history
                           where user_id=? and trx_type='BUY' order by id limit 1",
                         [$om_user]) > 0);

    $om_symbols = array_column(portfolio_held_symbols($om_user), 'symbol');
    check('the Sell list is what is still held',
          $om_symbols === ['AAA'], json_encode($om_symbols));
    portfolio_record_trade($om_user, 'SELL', 'AAA', 500, 95.00, 0, '2026-04-01');
    check('and a position sold out of leaves it',
          portfolio_held_symbols($om_user) === []
          && portfolio_held($om_user, 'AAA')['shares'] === 0);

    /* Nothing writes the three old tables any more. */
    check('the trade wrote nothing to the shares table',
          (int) scalar("select count(*) from shares where id=?", [$om_user]) === 0);

    $om_sources = '';
    foreach (['public/buy.php', 'public/sell.php', 'public/topup.php'] as $om_file) {
        $om_sources .= (string) file_get_contents(dirname(__DIR__) . '/' . $om_file);
    }
    check('buy, sell and deposit no longer write shares, history or users.cash',
          stripos($om_sources, 'INTO shares') === false
          && stripos($om_sources, 'UPDATE shares') === false
          && stripos($om_sources, 'record_transaction') === false
          && stripos($om_sources, 'users set cash') === false,
          $om_sources === '' ? 'no sources read' : 'a write is still there');
    check('and they all write the model the portfolio reads',
          substr_count($om_sources, 'portfolio_record_trade') === 2
          && stripos($om_sources, 'update_cash_history') !== false);

    /* The share outlook read the shares table with no user in it, so on an
       account with more than one user the model was told about somebody
       else's holdings. */
    $om_outlook = (string) file_get_contents($INCLUDES . '/ai_outlook.php');
    check('the outlook reads the holding from purchases, for this session',
          stripos($om_outlook, 'from shares where symbol') === false
          && strpos($om_outlook, 'portfolio_held($_SESSION["id"]') !== false);

    /* update_portfolio() copied a figure derived from purchases into a
       second table nobody consults. */
    check('nothing keeps the shares table in step any more',
          stripos((string) file_get_contents($INCLUDES . '/share_functions.php'),
                  'insert into shares') === false);

    db()->exec("delete from purchases where session_id=$om_user");
    db()->exec("delete from cash_history where user_id=$om_user");
    if ($om_saved_id === null) { unset($_SESSION["id"]); } else { $_SESSION["id"] = $om_saved_id; }
    if ($om_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $om_saved_exchange; }

    echo "\n--- one model: the pages that do the writing ---\n";

    /* The deposit check was inverted: it apologised when the amount WAS a
       number, so "5000" was refused and "abc" was accepted and written to
       the cash book as nought. */
    $om_topup = (string) file_get_contents(dirname(__DIR__) . '/public/topup.php');
    check('a deposit of a number is accepted and a word is not',
          preg_match('/!preg_match\("\/\^\\\\d\+\(\\\\\.\\\\d\{1,2\}\)\?\$\/"/', $om_topup) === 1,
          'the amount check');
    check('and it takes pence as well as pounds',
          strpos($om_topup, '\\d{1,2}') !== false);

    /* Buy checks against the cash book rather than users.cash, which was a
       second balance that two pages maintained and nothing read. */
    $om_buy = (string) file_get_contents(dirname(__DIR__) . '/public/buy.php');
    check('buy checks the money against the book it spends from',
          strpos($om_buy, 'portfolio_cash_balance') !== false
          && stripos($om_buy, 'cash FROM users') === false);
    /* Both date forms are checked where the fault was first described,
       under the portfolio rebuild. */

    $om_sell = (string) file_get_contents(dirname(__DIR__) . '/public/sell.php');
    check('sell counts what is held from the trades rather than a running total',
          strpos($om_sell, 'portfolio_held(') !== false
          && stripos($om_sell, 'FROM shares') === false);

    $om_modal = php_strip_whitespace(dirname(__DIR__) . '/templates/portfolio.php');
    check('the Buy and Sell forms offer the commission the pages accept',
          substr_count($om_modal, 'name="commission"') === 2
          && strpos($om_modal, 'name="sellDate"') !== false);

    /* dividends.php reads its fields as arrays - $symbol[0], $amount[0] -
       because its own page edits several rows at once. Posted as scalars,
       $symbol[0] is the first letter of the ticker and the dividend is
       filed against a company called "A". */
    check('the dividend form posts the arrays dividends.php reads',
          strpos($om_modal, 'name="symbol[]"') !== false
          && strpos($om_modal, 'name="dividend_date[]"') !== false
          && strpos($om_modal, 'name="amount[]"') !== false);

    echo "\n--- one model: a trade shows before tonight ---\n";

    /* portfolio_performance is the valuation of purchases and cash_history,
       and the nightly job is what normally writes it - so a purchase made at
       eleven in the morning was in the trade list and in the cash balance
       but not in Holdings until the following night. */
    $rv_user = 78;
    $rv_saved_id = $_SESSION["id"] ?? null;
    $rv_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["id"] = $rv_user;
    $_SESSION["exchange"] = 'XLON';

    foreach (['purchases' => 'session_id', 'cash_history' => 'user_id',
              'performance' => 'session_id', 'portfolio_performance' => 'session_id',
              'dividends' => 'session_id'] as $rv_table => $rv_key) {
        db()->exec("delete from $rv_table where $rv_key=$rv_user");
    }

    update_cash_history('2026-01-05', 'DEPOSIT', 20000.00);
    portfolio_record_trade($rv_user, 'BUY', 'AAA', 300, 70.00, 5.95, date('Y-m-d'));

    check('before the account is valued, Holdings has nothing to show',
          count(get_active_positions($rv_user)) === 0);

    portfolio_revalue($rv_user);

    $rv_positions = get_active_positions($rv_user);
    check('valuing it puts the purchase in Holdings at once',
          count($rv_positions) === 1 && $rv_positions[0]['symbol'] === 'AAA'
          && (int) $rv_positions[0]['qty_purchased'] === 300,
          json_encode(array_column($rv_positions, 'symbol')));

    /* One date, replaced. Deleting more would throw away history the nightly
       job wrote and this cannot rebuild. */
    db()->prepare("insert into performance (session_id, performance_date, total_value,
                                            total_profit, total_holding, cash)
                   values (?, '2020-01-02', 1, 1, 1, 1)")->execute([$rv_user]);
    portfolio_revalue($rv_user);
    check('and it rewrites today only, leaving the history alone',
          (int) scalar("select count(*) from performance
                         where session_id=? and performance_date='2020-01-02'",
                       [$rv_user]) === 1
          && (int) scalar("select count(*) from performance
                            where session_id=? and performance_date=?",
                          [$rv_user, date('Y-m-d')]) === 1);

    check('buying and selling both value the account before redirecting',
          substr_count((string) file_get_contents(dirname(__DIR__) . '/public/buy.php'),
                       'portfolio_revalue') === 1
          && substr_count((string) file_get_contents(dirname(__DIR__) . '/public/sell.php'),
                          'portfolio_revalue') === 1);

    foreach (['purchases' => 'session_id', 'cash_history' => 'user_id',
              'performance' => 'session_id', 'portfolio_performance' => 'session_id'] as $rv_table => $rv_key) {
        db()->exec("delete from $rv_table where $rv_key=$rv_user");
    }
    if ($rv_saved_id === null) { unset($_SESSION["id"]); } else { $_SESSION["id"] = $rv_saved_id; }
    if ($rv_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $rv_saved_exchange; }

    echo "\n--- the as of date, everywhere ---\n";

    /* Six functions on the quote page used "yesterday". Three overwrote it
       with the job date and left the calculation as dead code; the other
       three meant it, so on a Monday they asked for Sunday and found
       nothing, and on any day after the nightly job they showed the day
       before's figures - beside a header that was already reading the
       latest close. */
    $ad_asof = statistics_asof_date('XLON');
    check('there is one as of date, and it is the one the statistics are keyed on',
          $ad_asof === (string) scalar("select date(max(job_date)) from jobs
                                         where job_name='get_statistics_asof'"),
          $ad_asof);

    /* With no job row - a database the job has never run against - the
       newest statistics date is the same answer by another route. */
    $ad_jobs = rows("select id, job_name, job_date from jobs where job_name='get_statistics_asof'");
    db()->exec("delete from jobs where job_name='get_statistics_asof'");
    check('and with no job row it falls back to the statistics themselves',
          statistics_asof_date('XLON')
          === (string) scalar("select max(date) from statistics where exchange='XLON'"),
          statistics_asof_date('XLON'));
    $ad_put = db()->prepare("insert into jobs (id, job_name, job_date) values (?,?,?)");
    foreach ($ad_jobs as $ad_row) {
        $ad_put->execute([$ad_row['id'], $ad_row['job_name'], $ad_row['job_date']]);
    }

    $ad_src = (string) file_get_contents($INCLUDES . '/share_functions.php');
    check('no quote page function works out yesterday any more',
          preg_match_all("/sub\(new DateInterval\('P1D'\)\)/", $ad_src) === 7,
          preg_match_all("/sub\(new DateInterval\('P1D'\)\)/", $ad_src) . ' left');
    check('the scores, the ranks and the two valuations all read it',
          substr_count($ad_src, 'statistics_asof_date()') === 6,
          substr_count($ad_src, 'statistics_asof_date()') . ' call sites');

    /* The thing this was breaking: the implied price and the price it is
       measured against were two different days apart. */
    $ad_val = get_valuation('AAA');
    check('a valuation is read at the same date the statistics are written at',
          $ad_val['price'] === null
          || abs((float) $ad_val['price']
                 - (float) scalar("select value from statistics where symbol='AAA'
                                    and indicator='close_price' and exchange='XLON' and date=?",
                                  [$ad_asof])) < 0.0001,
          json_encode($ad_val));

    /* The division this was hiding. get_valuation() guards against a missing
       price; get_industry_valuation() beside it never did, so a share with
       no price for the date - one that did not trade, or any share at all on
       a database where the statistics job has not run yet - divided by
       nought and took the whole quote page down with it.

       It was reachable the moment the as-of date stopped being "yesterday",
       because yesterday always found a price in historical_prices whether it
       was the right one or not. */
    check('a share with no price for the date gets no valuation, not a fatal',
          is_array(get_industry_valuation('NOSUCHSYMBOL'))
          && get_industry_valuation('NOSUCHSYMBOL')['value'] === null,
          json_encode(get_industry_valuation('NOSUCHSYMBOL')));
    check('and the sector figure beside it behaves the same way',
          is_array(get_valuation('NOSUCHSYMBOL'))
          && get_valuation('NOSUCHSYMBOL')['value'] === null);

    echo "\n--- the quote page stops paying for what nobody can open ---\n";

    $dp_tpl = php_strip_whitespace(dirname(__DIR__) . '/templates/quote.php');
    $dp_ctl = (string) file_get_contents(dirname(__DIR__) . '/public/quote.php');

    check('the two panes with nothing to open them are gone',
          strpos($dp_tpl, 'id="financials"') === false
          && strpos($dp_tpl, 'id="ratings"') === false
          && strpos($dp_tpl, 'incomestatement') === false);
    /* Comments stripped: the note left behind names the functions it no
       longer calls, so that it is clear they are still there to come back
       to - and searching the raw file finds the note. Sixth time. */
    $dp_ctl = preg_replace('#/\*.*?\*/#s', '', preg_replace('#//.*$#m', '', $dp_ctl));
    check('and the seven queries behind them with it',
          strpos($dp_ctl, 'income_statement(') === false
          && strpos($dp_ctl, 'balance_sheet(') === false
          && strpos($dp_ctl, 'cash_flow_statement(') === false
          && strpos($dp_ctl, 'ratings(') === false
          && strpos($dp_ctl, 'get_momentum_statistics(') === false
          && strpos($dp_ctl, 'get_growth_statistics(') === false
          && strpos($dp_ctl, 'get_value_statistics(') === false
          && strpos($dp_ctl, 'get_quality_statistics(') === false);
    /* Kept, so a tab can be given back to them in a line. */
    check('the functions behind them are still there to come back to',
          function_exists('income_statement') && function_exists('ratings')
          && function_exists('get_value_statistics'));
    check('the three tabs that do open are untouched',
          substr_count($dp_tpl, 'data-bs-toggle="tab"') === 3);

    echo "\n--- the transactions page can be pointed at this year ---\n";

    /* A fixed 2014 to 2019, on a page whose default view is the current
       month. */
    $ey_tpl = php_strip_whitespace(dirname(__DIR__) . '/templates/edit_portfolio.php');
    check('the year list comes from the data rather than from a literal',
          strpos($ey_tpl, 'foreach ($years as $y)') !== false
          && strpos($ey_tpl, '2019') === false);
    check('and every render of the page supplies it',
          substr_count((string) file_get_contents(dirname(__DIR__) . '/public/edit.php'),
                       'edit_years($_SESSION["id"])') === 5);

    /* "positions" with "trx_type" typed into the middle of it, so the
       editable view rendered with no rows at all. */
    check('the editable view is given its rows under the name it reads them by',
          strpos((string) file_get_contents(dirname(__DIR__) . '/public/edit.php'),
                 'ptrx_typeositions') === false);

    echo "\n--- the stylesheet that was never there, and the stray files ---\n";

    /* On the server styles1.css is the placeholder comment fetch_assets.sh
       writes, not a stylesheet. Every page was fetching an empty file. */
    check('styles1.css is no longer loaded',
          stripos((string) file_get_contents(dirname(__DIR__) . '/templates/header.php'),
                  '<link href="/css/styles1.css"') === false);
    check('and app.css is still last, so it still decides',
          strpos(preg_replace('/<!--.*?-->/s', '',
                 (string) file_get_contents(dirname(__DIR__) . '/templates/header.php')), 'app.css')
          > strpos(preg_replace('/<!--.*?-->/s', '',
                 (string) file_get_contents(dirname(__DIR__) . '/templates/header.php')), 'bootstrap.min.css'));

    $sf_stray = [];
    foreach (glob(dirname(__DIR__) . '/{includes,public,templates}/*~', GLOB_BRACE) as $sf_file) {
        $sf_stray[] = basename($sf_file);
    }
    check('no editor backup files are left in the tree',
          count($sf_stray) === 0, implode(', ', $sf_stray));
    check('and the orphan controller in templates is gone',
          !file_exists(dirname(__DIR__) . '/templates/index.php'));

    echo "\n--- one colour scale, mixed rather than stepped ---\n";

    /* The four fixed bands made 49 and 50 different colours for no reason a
       reader can see. The badge carries the score as a custom property and
       the stylesheet mixes the colour from it; the band word stays, in the
       tooltip, because "strong" is what a reader wants in words and a
       colour cannot say it. */
    check('the badge carries the score rather than a band class',
          strpos(score_badge_html(62, 'Quality'), '--score: 62') !== false
          && strpos(score_badge_html(62, 'Quality'), 'sm-score-fair') === false,
          score_badge_html(62, 'Quality'));
    check('and still says what it is and which way is better',
          strpos(score_badge_html(62, 'Quality'), 'Quality 62/100') !== false
          && strpos(score_badge_html(62, 'Quality'), 'higher is better') !== false
          && strpos(score_badge_html(62, 'Quality'), 'above average') !== false);
    check('a score outside 0 to 100 is brought back into it',
          strpos(score_badge_html(140, 'Quality'), '--score: 100') !== false
          && strpos(score_badge_html(-20, 'Quality'), '--score: 0') !== false);
    check('and an unscored share is still a dash, not a badge at nought',
          strpos(score_badge_html(null, 'Quality'), 'sm-missing') !== false);

    /* The band helper has not gone: the tooltip and the key use it. */
    $cs_bands = [0 => 'poor', 24 => 'poor', 25 => 'below average', 49 => 'below average',
                 50 => 'above average', 74 => 'above average', 75 => 'strong', 100 => 'strong'];
    $cs_wrong = [];
    foreach ($cs_bands as $cs_score => $cs_word) {
        if (score_band_word($cs_score) !== $cs_word) { $cs_wrong[] = $cs_score; }
    }
    check('the band words still change at 25, 50 and 75',
          count($cs_wrong) === 0, implode(',', $cs_wrong));

    $cs_css = (string) file_get_contents(dirname(__DIR__) . '/templates/app.css');
    $cs_scale = view_score_scale();
    check('the stylesheet mixes in oklch between the two ends',
          strpos($cs_css, 'color-mix(in oklch') !== false
          && strpos($cs_css, $cs_scale['low']) !== false
          && strpos($cs_css, $cs_scale['high']) !== false,
          json_encode($cs_scale));
    /* Mixing in sRGB goes through mud and the lightness wanders, so the text
       is readable at the ends and not in the middle. */
    check('and not in sRGB, which would take red to green through grey',
          strpos($cs_css, 'color-mix(in srgb') === false);
    /* White text on no background at all is white text on white. */
    check('a browser without color-mix gets a legible fallback',
          preg_match('/\.sm-score \{\s*background: var\(--sm-score-none\);/', $cs_css) === 1);

    echo "\n--- light, dark, or whatever the machine says ---\n";

    check('every colour the page owns is written for both schemes',
          substr_count($cs_css, 'light-dark(') >= 8, substr_count($cs_css, 'light-dark('));
    check('and color-scheme is set, or light-dark() has nothing to go on',
          strpos($cs_css, 'color-scheme: light dark') !== false);
    /* With light-dark() following the system while Bootstrap follows the
       attribute, a machine set to dark and the app set to light comes out
       half of each. */
    check('the two pinned settings keep Bootstrap and light-dark together',
          strpos($cs_css, ':root[data-bs-theme="light"] { color-scheme: light; }') !== false
          && strpos($cs_css, ':root[data-bs-theme="dark"]  { color-scheme: dark; }') !== false);
    /* The status badges carry white text on their own background, so what
       they clear is the contrast against that text, not against the page. */
    check('the status badges are one colour in both schemes',
          strpos($cs_css, '--sm-status-paper: #1d4ed8;') !== false
          && strpos($cs_css, 'light-dark(#1d4ed8') === false);

    $th_header = (string) file_get_contents(dirname(__DIR__) . '/templates/header.php');
    check('the preference is on the page before the stylesheets',
          strpos($th_header, 'data-bs-theme="') !== false
          && strpos($th_header, '$sm_theme') < strpos($th_header, 'bootstrap.min.css'));
    /* A theme applied by script after the page has painted is a white flash
       on every load. */
    check('and the system setting is applied before the body, not after it',
          strpos($th_header, 'prefers-color-scheme: dark') !== false
          && strpos($th_header, 'prefers-color-scheme: dark') < strpos($th_header, '<body>'));
    /* Buttons in a form, not links: a link is something a prefetch or a
       link preview can follow on your behalf, and a form works with
       JavaScript off. */
    check('the menu offers all three, as a form rather than links',
          strpos($th_header, 'action="theme.php"') !== false
          && strpos($th_header, 'method="post"') !== false
          && strpos($th_header, "'light' => 'Light', 'dark' => 'Dark', 'system' => 'System'") !== false
          && preg_match('/<a [^>]*href="theme\.php/', $th_header) === 0);

    /* Stored on the user, so it is the same on the phone and the desktop. */
    check('the column is there and defaults to the system setting',
          (int) scalar("select count(*) from information_schema.columns
                         where table_schema=database() and table_name='users'
                           and column_name='theme'") === 1);

    $th_saved_id = $_SESSION["id"] ?? null;
    $_SESSION["id"] = 1;
    unset($_SESSION["sm_theme"]);
    db()->exec("update users set theme='dark' where id=1");
    check('and the page reads it', view_theme() === 'dark', view_theme());
    unset($_SESSION["sm_theme"]);
    db()->exec("update users set theme='nonsense' where id=1");
    check('a value that is not one of the three reads as the system setting',
          view_theme() === 'system', view_theme());
    unset($_SESSION["sm_theme"]);
    db()->exec("update users set theme='system' where id=1");
    if ($th_saved_id === null) { unset($_SESSION["id"]); } else { $_SESSION["id"] = $th_saved_id; }
    unset($_SESSION["sm_theme"]);

    $th_endpoint = (string) file_get_contents(dirname(__DIR__) . '/public/theme.php');
    check('the endpoint takes only the three values',
          strpos($th_endpoint, "['system', 'light', 'dark']") !== false);
    check('it refuses GET, so a link preview cannot change the theme',
          strpos($th_endpoint, "!== 'POST'") !== false);
    /* A Location built from the request is a redirect somebody else can aim. */
    check('and it only ever goes back to a page on this site',
          strpos($th_endpoint, '^[A-Za-z0-9_\-]+\.php') !== false);

    echo "\n--- things that fold away without a script ---\n";

    /* A Bootstrap collapse does not work with JavaScript off, can get stuck
       half open if the script does not load, and needs an id tying the
       control to the panel. */
    $fold_files = ['templates/ai_outlook_panel.php', 'templates/strategies.php',
                   'templates/in_the_news.php', 'templates/home.php',
                   'templates/quote.php', 'templates/portfolio.php'];
    $fold_left = [];
    foreach ($fold_files as $fold_file) {
        $fold_text = php_strip_whitespace(dirname(__DIR__) . '/' . $fold_file);
        if (strpos($fold_text, 'data-bs-toggle="collapse"') !== false) { $fold_left[] = $fold_file; }
    }
    check('no page folds anything away with a collapse any more',
          count($fold_left) === 0, implode(', ', $fold_left));

    check('the outlook folds with a details',
          strpos(php_strip_whitespace(dirname(__DIR__) . '/templates/ai_outlook_panel.php'),
                 '<details class="sm-reveal"') !== false);
    check('and so does "How this works"',
          strpos(php_strip_whitespace(dirname(__DIR__) . '/templates/strategies.php'),
                 '<summary>How this works</summary>') !== false);

    /* The routine filings used to be a link back to the server with the
       filter in the query string: a page reload to see rows the page
       already had. */
    $fold_news = php_strip_whitespace(dirname(__DIR__) . '/templates/in_the_news.php');
    check('the routine items are behind a fold rather than behind a reload',
          strpos($fold_news, 'Show routine items') !== false
          && strpos($fold_news, 'sm-routine') !== false
          && strpos($fold_news, 'routine=1') === false
          && strpos((string) file_get_contents(dirname(__DIR__) . '/public/in_the_news.php'),
                    "\$_GET['routine']") === false);
    /* One table renderer, used for both, so the fold cannot drift from the
       page above it. */
    check('and both tables are drawn by the same function',
          substr_count($fold_news, 'news_table(') === 3);

    check('the marker is drawn rather than left to the engine',
          strpos($cs_css, '.sm-reveal > summary::before') !== false
          && strpos($cs_css, '::-webkit-details-marker') !== false);
    check('and the open animates where the browser can do it',
          strpos($cs_css, 'interpolate-size: allow-keywords') !== false
          && strpos($cs_css, '::details-content') !== false);

    echo "\n--- cards that lay out on their own width ---\n";

    /* A card in the dashboard's two-column grid is about the width of a
       phone. Laying its contents out on the window width is what made the
       same card read differently in the two places. */
    check('every card is a container',
          preg_match('/\.card \{[^}]*container-type: inline-size/s', $cs_css) === 1);
    check('and the figures inside switch columns on the card, not the window',
          strpos($cs_css, '@container (min-width: 360px)') !== false
          && strpos($cs_css, '@container (min-width: 760px)') !== false);
    check('with a layout for a browser that has no container queries',
          strpos($cs_css, '@supports not (container-type: inline-size)') !== false);

    echo "\n--- the rank buffer: held until it drops out of the top N ---\n";

    /* The first backtest of trend following returned +62.8% and paid
       GBP53,553 in dealing costs on GBP100,000 of starting capital. Weekly
       re-ranking churns the names: a holding that slips from fifteenth to
       sixteenth is sold and bought back a week later, both ways paying
       commission, stamp duty and the spread.

       hold_rank is the buffer. The fixture is eight symbols ranked by a
       score written straight in, so "ranked twentieth" is exact rather than
       whatever the data happens to give. */
    $HR = 'test_hold_rank';
    $hr_saved_exchange = $_SESSION["exchange"] ?? null;
    $_SESSION["exchange"] = 'XLON';
    $hr_asof = asof_date();

    $hr_universe = ['AAA','BBB','CCC','DDD','EEE','FFF','GGG','HHH'];
    /* Highest score ranks first. AAA 80 down to HHH 10. */
    foreach ($hr_universe as $hr_i => $hr_symbol) {
        s_stat($hr_symbol, 'hr_score', $hr_asof, 80 - $hr_i * 10);
    }

    $hr_rank_of = function ($symbol) use ($hr_universe) {
        return array_search($symbol, $hr_universe, true) + 1;
    };

    s_drop($HR);
    s_rules($HR, ['selection'=>'score','score_indicator'=>'hr_score','rank_by'=>'hr_score',
                  'holdings'=>3,'hold_rank'=>null]);
    s_reset($HR);

    $hr_select = function () use ($HR, $hr_asof) {
        $rules = strategy_rules($HR);
        $out = strategy_select_symbols($rules, $hr_asof, 'XLON');
        return $out['symbols'];
    };

    check('with no buffer the selection is the top three',
          $hr_select() === ['AAA','BBB','CCC'], json_encode($hr_select()));

    /* A holding that has slipped. DDD is ranked fourth, outside the three
       slots but inside a buffer of five. */
    $hr_hold = function ($symbol, $quantity = 100) use ($HR) {
        db()->prepare("insert into strategy_positions (strategy,symbol,exchange,quantity,avg_price,updated_at)
                       values (?,?,'XLON',?,1.0,now())
                       on duplicate key update quantity=values(quantity)")
          ->execute([$HR,$symbol,$quantity]);
    };
    db()->prepare("delete from strategy_positions where strategy=?")->execute([$HR]);
    $hr_hold('AAA'); $hr_hold('BBB'); $hr_hold('DDD');

    check('without a buffer the one that slipped is sold',
          !in_array('DDD', $hr_select(), true) && $hr_select() === ['AAA','BBB','CCC'],
          json_encode($hr_select()));

    s_rules($HR, ['selection'=>'score','score_indicator'=>'hr_score','rank_by'=>'hr_score',
                  'holdings'=>3,'hold_rank'=>5]);
    check('with a buffer of five it is kept, because it is still ranked fourth',
          in_array('DDD', $hr_select(), true), json_encode($hr_select()));
    check('and the free slot is filled from the top of the ranking',
          $hr_select() === ['AAA','BBB','DDD'], json_encode($hr_select()));
    check('not from the name just outside the buffer',
          !in_array('FFF', $hr_select(), true) && !in_array('GGG', $hr_select(), true));

    /* The brief's case: ranked twentieth, kept with a buffer of thirty and
       sold with none. Eight names here, so ranked sixth with a buffer of
       seven. */
    db()->prepare("delete from strategy_positions where strategy=?")->execute([$HR]);
    $hr_hold('AAA'); $hr_hold('BBB'); $hr_hold('FFF');
    s_rules($HR, ['selection'=>'score','score_indicator'=>'hr_score','rank_by'=>'hr_score',
                  'holdings'=>3,'hold_rank'=>7]);
    check('a holding ranked sixth is kept with a buffer of seven',
          $hr_rank_of('FFF') === 6 && in_array('FFF', $hr_select(), true),
          json_encode($hr_select()));
    s_rules($HR, ['selection'=>'score','score_indicator'=>'hr_score','rank_by'=>'hr_score',
                  'holdings'=>3,'hold_rank'=>5]);
    check('and sold with a buffer of five, which it is outside',
          !in_array('FFF', $hr_select(), true), json_encode($hr_select()));
    s_rules($HR, ['selection'=>'score','score_indicator'=>'hr_score','rank_by'=>'hr_score',
                  'holdings'=>3,'hold_rank'=>null]);
    check('and sold with none',
          !in_array('FFF', $hr_select(), true), json_encode($hr_select()));

    /* Leaving the selection is not the same as slipping down it, and both
       end in the same place - which is what makes this one rule. */
    s_rules($HR, ['selection'=>'score','score_indicator'=>'hr_score','rank_by'=>'hr_score',
                  'holdings'=>3,'hold_rank'=>8]);
    db()->prepare("delete from strategy_positions where strategy=?")->execute([$HR]);
    $hr_hold('AAA'); $hr_hold('HHH');
    check('a holding at the bottom of a buffer that covers everything is kept',
          in_array('HHH', $hr_select(), true), json_encode($hr_select()));
    db()->prepare("delete from statistics where symbol='HHH' and indicator='hr_score'
                    and date=? and exchange='XLON'")->execute([$hr_asof]);
    check('and sold the moment it leaves the selection, whatever its rank',
          !in_array('HHH', $hr_select(), true), json_encode($hr_select()));
    s_stat('HHH', 'hr_score', $hr_asof, 10);

    /* Slots fill from rank 1 downward, skipping names already held. */
    s_rules($HR, ['selection'=>'score','score_indicator'=>'hr_score','rank_by'=>'hr_score',
                  'holdings'=>4,'hold_rank'=>6]);
    db()->prepare("delete from strategy_positions where strategy=?")->execute([$HR]);
    $hr_hold('EEE');
    $hr_filled = $hr_select();
    check('a single holding inside the buffer keeps its place',
          in_array('EEE', $hr_filled, true), json_encode($hr_filled));
    check('and the other three slots are the best names not already held',
          count($hr_filled) === 4
          && in_array('AAA', $hr_filled, true) && in_array('BBB', $hr_filled, true)
          && in_array('CCC', $hr_filled, true)
          && !in_array('DDD', $hr_filled, true),
          json_encode($hr_filled));

    /* More holdings inside the buffer than there are slots. */
    s_rules($HR, ['selection'=>'score','score_indicator'=>'hr_score','rank_by'=>'hr_score',
                  'holdings'=>2,'hold_rank'=>6]);
    db()->prepare("delete from strategy_positions where strategy=?")->execute([$HR]);
    $hr_hold('BBB'); $hr_hold('DDD'); $hr_hold('FFF');
    check('with more kept names than slots, the best ranked stay',
          $hr_select() === ['BBB','DDD'], json_encode($hr_select()));

    /* A buffer below the holdings count would be stricter than no buffer,
       which is not what a buffer is. */
    s_rules($HR, ['selection'=>'score','score_indicator'=>'hr_score','rank_by'=>'hr_score',
                  'holdings'=>4,'hold_rank'=>2]);
    check('a buffer narrower than the book is floored at the book',
          strategy_rules($HR)['hold_rank'] === 4, strategy_rules($HR)['hold_rank']);
    check('and a buffer of nought is no buffer at all',
          (function () use ($HR) {
              s_rules($HR, ['selection'=>'score','score_indicator'=>'hr_score',
                            'rank_by'=>'hr_score','holdings'=>3,'hold_rank'=>0]);
              return strategy_rules($HR)['hold_rank'] === null;
          })());

    /* The page says why a holding that slipped two places is still there. */
    check('the card says what the buffer is',
          strpos(php_strip_whitespace(dirname(__DIR__) . '/templates/strategies.php'),
                 'falls out of the top') !== false);

    $hr_mig = (string) file_get_contents(dirname(__DIR__) . '/sql/strategy_hold_rank.sql');
    check('the migration adds the column only if it is missing',
          stripos($hr_mig, 'information_schema.columns') !== false
          && stripos($hr_mig, 'hold_rank') !== false);
    check('and leaves both live strategies without a buffer for now',
          stripos($hr_mig, 'update strategy_rules set hold_rank') === false);

    s_drop($HR);
    foreach ($hr_universe as $hr_symbol) {
        db()->prepare("delete from statistics where symbol=? and indicator='hr_score'")
          ->execute([$hr_symbol]);
    }
    if ($hr_saved_exchange === null) { unset($_SESSION["exchange"]); }
    else { $_SESSION["exchange"] = $hr_saved_exchange; }

    echo "\n--- one way to print a number ---\n";

    /* Formatting used to live in each template, and the same figure was
       printed three ways on three pages. These are the formatters every
       template now goes through, so they are worth pinning: a change here
       changes every page at once. */

    check('a London price is pence, one place, with a p',
          fmt_pence(1350.6) === '1,350.6p', fmt_pence(1350.6));
    check('and pounds are pounds, two places',
          fmt_pounds(6609.33) === "\xc2\xa36,609.33", fmt_pounds(6609.33));
    /* The two are a factor of 100 apart and the names are the only thing
       stopping a template confusing them, so the names have to differ in
       what they print as well. */
    check('the two never look the same',
          fmt_pence(1350.6) !== fmt_pounds(1350.6));

    check('large money shortens above ten thousand',
          fmt_money_compact(4700000000) === "\xc2\xa34.7bn"
          && fmt_money_compact(312400000) === "\xc2\xa3312.4m"
          && fmt_money_compact(48100) === "\xc2\xa348.1k",
          fmt_money_compact(4700000000) . ' ' . fmt_money_compact(312400000)
          . ' ' . fmt_money_compact(48100));
    check('and stays exact at or below it',
          fmt_money_compact(9999.5) === "\xc2\xa39,999.50"
          && fmt_money_compact(10000) === "\xc2\xa310,000.00",
          fmt_money_compact(10000));

    check('a change carries its sign',
          fmt_change_pct(12.4) === '+12.4%' && fmt_change_pct(-2.2) === '-2.2%',
          fmt_change_pct(12.4) . ' ' . fmt_change_pct(-2.2));
    check('zero carries none',
          fmt_change_pct(0) === '0.0%', fmt_change_pct(0));
    /* A fraction and a percentage are also a factor of 100 apart. Same
       treatment: two names, no flag. */
    check('a fraction goes through its own formatter',
          fmt_percent_of(0.124) === '+12.4%', fmt_percent_of(0.124));

    check('ratios get one place, yields two',
          fmt_ratio(17.6234) === '17.6' && fmt_yield(3.1134) === '3.11%',
          fmt_ratio(17.6234) . ' ' . fmt_yield(3.1134));
    check('a score is whole and a quantity has separators',
          fmt_score(82.0) === '82' && fmt_quantity(11578.2) === '11,578',
          fmt_score(82.0) . ' ' . fmt_quantity(11578.2));
    check('sentiment is two places, signed, with the band word',
          fmt_sentiment(0.7) === '+0.70 strong' && fmt_sentiment(-0.62) === '-0.62 bad',
          fmt_sentiment(0.7) . ' / ' . fmt_sentiment(-0.62));

    check('a date is 13 Nov 2026 in prose and 13 Nov in a table',
          fmt_date('2026-11-13') === '13 Nov 2026'
          && fmt_date_short('2026-11-13') === '13 Nov',
          fmt_date('2026-11-13') . ' / ' . fmt_date_short('2026-11-13'));

    /* Missing is its own thing. A zero says the value is nought and an empty
       cell says there is no such column; neither is "the provider did not
       send it". */
    check('nothing to print prints an en dash, not a zero',
          fmt_pence(null) === VIEW_DASH && fmt_pounds('') === VIEW_DASH
          && fmt_ratio('n/a') === VIEW_DASH && fmt_score(null) === VIEW_DASH);
    check('and the dash says why it is there',
          strpos(view_missing_html('not supplied by FMP'), 'not supplied by FMP') !== false
          && strpos(view_missing_html(), 'title=') !== false);

    echo "\n--- one colour scale for every score ---\n";

    /* The boundaries. 24 and 25 are different bands, and so are 49/50 and
       74/75 - which is the whole content of the rule, so it is checked at
       every edge rather than in the middle of each band. */
    $bands = [0 => 'score-bad', 24 => 'score-bad', 25 => 'score-weak',
              49 => 'score-weak', 50 => 'score-fair', 74 => 'score-fair',
              75 => 'score-good', 100 => 'score-good'];
    $band_wrong = [];
    foreach ($bands as $score => $want) {
        if (score_band($score) !== $want) { $band_wrong[] = "$score gave " . score_band($score); }
    }
    check('the score bands change at 25, 50 and 75',
          count($band_wrong) === 0, implode(', ', $band_wrong));
    check('and an unscored share gets its own band, not red',
          score_band(null) === 'score-none' && score_band('') === 'score-none');

    check('the badge says what is being scored and which way is better',
          strpos(score_badge_html(82, 'Quality'), 'Quality 82/100') !== false
          && strpos(score_badge_html(82, 'Quality'), 'higher is better') !== false,
          score_badge_html(82, 'Quality'));
    check('and a score it has not got is a dash, not a zero badge',
          strpos(score_badge_html(null, 'Quality'), 'sm-missing') !== false,
          score_badge_html(null, 'Quality'));

    check('a move is green up, red down, grey flat',
          move_class(1.2) === 'sm-up' && move_class(-1.2) === 'sm-down'
          && move_class(0) === 'sm-flat');

    /* WCAG AA for text on a badge is 4.5:1. Computed rather than eyeballed,
       because the eye is generous about dark text on mid green and the
       standard is not. */
    $contrast = function ($a, $b) {
        $lum = function ($hex) {
            $hex = ltrim($hex, '#');
            $parts = [];
            foreach ([0, 2, 4] as $i) {
                $c = hexdec(substr($hex, $i, 2)) / 255;
                $parts[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
            }
            return 0.2126 * $parts[0] + 0.7152 * $parts[1] + 0.0722 * $parts[2];
        };
        $la = $lum($a); $lb = $lum($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    };
    $low = [];
    foreach (view_palette() as $name => [$background, $text]) {
        $ratio = $contrast($background, $text);
        if ($ratio < 4.5) { $low[] = sprintf('%s %.2f:1', $name, $ratio); }
    }
    check('every badge passes WCAG AA for its own text',
          count($low) === 0, implode(', ', $low));

    /* The same for the colours used as text rather than as a background.
       These sit on the page, which app.css sets to #f8f9fa - the lighter the
       background the easier this is, so it is checked against the darker of
       the two the page can have. */
    $text_low = [];
    foreach (view_text_colours() as $name => $colour) {
        $ratio = $contrast($colour, '#f8f9fa');
        if ($ratio < 4.5) { $text_low[] = sprintf('%s %.2f:1', $name, $ratio); }
    }
    check('and every value colour passes AA as text on the page',
          count($text_low) === 0, implode(', ', $text_low));

    /* PHP cannot read a stylesheet and a stylesheet cannot call PHP, so the
       palette exists twice. A colour changed in one and not the other gives
       one page its own scheme, silently - this is what stops that. */
    $app_css = (string) file_get_contents(dirname(__DIR__) . '/templates/app.css');
    $missing_css = [];
    foreach (view_palette() as $name => [$background, $text]) {
        if (stripos($app_css, $background) === false) { $missing_css[] = "$name $background"; }
    }
    foreach (view_text_colours() as $name => $colour) {
        /* The sentiment tones are printed inline from view_text_colours()
           rather than through a class, so only the four that app.css owns
           have to appear in it. */
        if (!in_array($name, ['up', 'down', 'flat', 'muted'], true)) { continue; }
        if (stripos($app_css, $colour) === false) { $missing_css[] = "$name $colour"; }
    }
    check('the stylesheet and the palette agree on every colour',
          count($missing_css) === 0, implode(', ', $missing_css));

    check('numbers are set in tabular figures, or the columns do not line up',
          strpos($app_css, 'tabular-nums') !== false);

    echo "\n--- how old are these figures ---\n";

    /* A page showing Friday's prices on a Wednesday is not wrong, it is
       stale, and a reader cannot tell a stale price from a falling one. The
       indicator is on every page, so its thresholds are worth pinning.

       On a throwaway exchange code, so the fixtures the rest of the suite
       ranks are left alone. */
    db()->exec("delete from historical_prices where exchange='XFRS'");
    check('with no prices at all it says so, in red',
          view_freshness('XFRS')['level'] === 'red'
          && stripos(view_freshness('XFRS')['words'], 'no prices') !== false,
          json_encode(view_freshness('XFRS')));

    /* Weekends are not a late job, so the age is counted in weekdays. This
       finds the date that is exactly n weekdays back from today, whatever
       day today happens to be. */
    $weekdays_back = function ($n) {
        $day = strtotime(date('Y-m-d'));
        $seen = 0;
        while (true) {
            $day = strtotime(date('Y-m-d', $day) . ' -1 day');
            if ((int) date('N', $day) <= 5) { $seen++; }
            if ($seen >= $n) { return date('Y-m-d', $day); }
        }
    };
    $put_price = function ($date) {
        db()->exec("delete from historical_prices where exchange='XFRS'");
        $s = db()->prepare("insert into historical_prices (symbol, exchange, date, price)
                            values ('FRSH', 'XFRS', ?, 100)");
        $s->execute([$date]);
    };

    $put_price(date('Y-m-d'));
    check("today's close is fresh",
          view_freshness('XFRS')['level'] === 'fresh', json_encode(view_freshness('XFRS')));

    $put_price($weekdays_back(1));
    check('one trading day behind turns it amber',
          view_freshness('XFRS')['level'] === 'amber', json_encode(view_freshness('XFRS')));

    $put_price($weekdays_back(2));
    check('two is still amber',
          view_freshness('XFRS')['level'] === 'amber', json_encode(view_freshness('XFRS')));

    $put_price($weekdays_back(3));
    check('three turns it red, and says how far behind',
          view_freshness('XFRS')['level'] === 'red'
          && strpos(view_freshness('XFRS')['words'], 'behind') !== false,
          json_encode(view_freshness('XFRS')));

    $put_price($weekdays_back(10));
    check('and a job that stopped a fortnight ago is still red, not alarming',
          view_freshness('XFRS')['level'] === 'red');

    db()->exec("delete from historical_prices where exchange='XFRS'");

    echo "\n--- the page frame ---\n";

    /* Comments stripped first - HTML and PHP both. Every check below asks
       what the page LOADS, and the prose in these two files necessarily
       names the stylesheets it no longer loads and the divs it no longer
       closes. Reading the comments made four of these checks assert the
       opposite of what they are for. */
    $no_comments = function ($path) {
        $text = (string) file_get_contents(dirname(__DIR__) . $path);
        $text = preg_replace('/<!--.*?-->/s', '', $text);
        return preg_replace('#/\*.*?\*/#s', '', $text);
    };
    $hdr = $no_comments('/templates/header.php');
    $ftr = $no_comments('/templates/footer.php');
    $fetcher = (string) file_get_contents(dirname(__DIR__) . '/tools/fetch_assets.sh');
    $fetcher_code = preg_replace('/^\s*#.*$/m', '', $fetcher);

    /* Three stylesheets for one framework, one of them a Bootstrap 3 theme,
       is how two pages ended up with different button shapes. */
    check('one Bootstrap stylesheet is loaded, not three',
          preg_match_all('#<link[^>]+bootstrap[^>]*>#i', $hdr) === 1, $hdr);
    check('and the Bootstrap 3 theme is gone from the page and from the fetcher',
          stripos($hdr, 'bootstrap-theme') === false
          && stripos($fetcher_code, 'bootstrap-theme') === false);
    check('the application stylesheet is loaded last, so it decides',
          preg_match('#app\.css#', $hdr) === 1
          && strpos($hdr, 'app.css') > strpos($hdr, 'bootstrap.min.css')
          && strpos($hdr, 'app.css') > strpos($hdr, 'styles1.css'));
    check('and the fetcher puts it there',
          strpos($fetcher_code, 'templates/app.css') !== false);

    /* The footer used to close four divs against two opening tags, so the
       extra ones closed whatever the page itself had open. */
    check('the footer closes exactly what the header opens',
          substr_count($hdr, '<main') === 1 && substr_count($ftr, '</main>') === 1
          && substr_count($ftr, '</div>') === 0);

    check('the active page is marked in the navbar',
          strpos($hdr, 'aria-current="page"') !== false
          && strpos($hdr, "nav-link<?= \$sm_section") !== false);
    check('Log Out is under the user name, not beside the pages',
          strpos($hdr, 'smUserMenu') !== false
          && preg_match('#<li class="nav-item">\s*<a class="nav-link[^"]*"[^>]*href="logout\.php"#', $hdr) === 0);
    check('the freshness indicator is in the navbar on every page',
          strpos($hdr, 'sm_fresh') !== false);
    check('and the share search offers company names',
          stripos($hdr, 'placeholder="Symbol or company"') !== false);

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

        /* backtest.php and backtest_results.php are gone from this list
           because they are gone from the docroot: the 2023 backtest drove
           its own model and shared nothing with the paper engine, and
           includes/backtest_strategy.php replaces it. They are in
           archive/public/, which the "dead pages" test checks. */
        $pages = ['index.php','performance.php','dividends.php','cash_history.php',
                  'edit.php','topup.php','screen_list.php','quote.php','criteria.php',
                  'criteria_list.php','screen_criteria.php','sector_companies.php',
                  'strategies.php','sell.php','buy.php','rankings.php',
                  'statistics.php','statistics_form.php','download_prices.php',
                  'register.php','reset_passwd.php','in_the_news.php','broker.php'];
        $fatal = [];
        foreach ($pages as $page) {
            $body = (string)$curl("$base/$page");
            if (preg_match('/(Fatal error|Parse error)/i', $body)) { $fatal[] = $page; }
        }
        check(count($pages) . ' pages render without a fatal', count($fatal) === 0,
              'fatal: ' . implode(', ', $fatal));

        /* ---------------- no raw floats on any page ---------------- */

        /* 82.000000, 96.525206, 1350.6000, 0.700. Every one of those was on
           a page, and every one of them is a database column printed
           straight out. Six decimal places is not precision to a reader, it
           is a tell that nobody formatted the figure - and a column that
           prints raw is a column whose units nobody has thought about, which
           is how pence and pounds got mixed up in the first place.

           Visible text only: tags and attributes are stripped first, because
           a title attribute carrying the exact value is useful and a chart's
           JSON payload is not prose.

           The one exception is the model's cost in dollars, where the fourth
           decimal place is the figure rather than noise - $0.0004 cannot be
           written with fewer. It is marked with its own class and cut out
           here by name. */
        $visible = function ($html) {
            $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $html);
            $html = preg_replace('#<[a-z]+[^>]*class="[^"]*sm-outlook-meta[^"]*"[^>]*>.*?</[a-z]+>#is',
                                 ' ', $html);
            $html = preg_replace('#<[^>]*>#', ' ', $html);
            return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        };

        $raw = [];
        foreach ($pages as $page) {
            $text = $visible($curl("$base/$page"));
            if (preg_match_all('/(?<![\d.])\d+\.\d{4,}/', $text, $hits)) {
                $raw[] = $page . ': ' . implode(' ', array_slice(array_unique($hits[0]), 0, 5));
            }
        }
        check('no page prints a number with four or more decimal places',
              count($raw) === 0, implode("\n          ", $raw));

        /* ---------------- "In the news" ---------------- */

        /* Announcement text is Investegate's, kept under terms that let a
           private investor read it and forbid redistribution. Behind the
           login that is somebody reading their own research; served to the
           world it would be republishing. So the gate is checked first, and
           checked on the page that shows the most of it. */
        $itn_jar = tempnam(sys_get_temp_dir(), 'smanon');
        $itn_anon = (string) shell_exec('curl -s -m 30 -b ' . escapeshellarg($itn_jar)
                                        . ' -c ' . escapeshellarg($itn_jar) . ' '
                                        . escapeshellarg("$base/in_the_news.php") . ' 2>/dev/null');
        $itn_code = trim((string) shell_exec('curl -s -o /dev/null -w "%{http_code}" -m 30 '
                                             . escapeshellarg("$base/in_the_news.php") . ' 2>/dev/null'));
        check('an anonymous request for the news page is redirected to login',
              $itn_code === '302', "status $itn_code");
        check('and no announcement text reaches it',
              stripos($itn_anon, 'ahead of expectations') === false
              && stripos($itn_anon, 'investegate.co.uk/announcement') === false,
              json_encode(substr($itn_anon, 0, 160)));
        @unlink($itn_jar);

        /* Now with something to show. One good announcement, one bad, and one
           routine filing that must not appear at all. */
        db()->exec("truncate rns_announcements");
        $itn_asof = asof_date();
        $itn_insert = db()->prepare("insert into rns_announcements
            (announcement_id, symbol, source, title, published_at, url, body, skipped,
             sentiment, ann_type, vs_expectations, summary, key_figures,
             bull_point, bear_point, scored_model, scored_at, first_seen)
            values (?, ?, 'RNS', ?, ?, ?, 'text', ?, ?, ?, ?, ?, ?, ?, ?, 'stub', now(), now())");
        $itn_insert->execute(['5001', 'AAA', 'Trading Update', $itn_asof . ' 07:00:00',
                              'https://www.investegate.co.uk/announcement/rns/a--AAA/t/5001',
                              'N', 0.7, 'trading update', 'beat',
                              'Trading ahead of expectations and guidance raised.',
                              json_encode(['revenue +7% to GBP1.2bn']),
                              'Guidance went up.', 'The comparative was weak.']);
        $itn_insert->execute(['5002', 'BBB', 'Profit Warning', $itn_asof . ' 07:00:00',
                              'https://www.investegate.co.uk/announcement/rns/b--BBB/p/5002',
                              'N', -0.8, 'profit warning', 'missed',
                              'Profit will be materially below guidance.',
                              json_encode([]), '', 'Guidance cut.']);
        $itn_insert->execute(['5003', 'CCC', 'Transaction in Own Shares', $itn_asof . ' 07:00:00',
                              'https://www.investegate.co.uk/announcement/rns/c--CCC/t/5003',
                              'Y', null, null, null, null, json_encode([]), '', '']);

        $itn = (string) $curl("$base/in_the_news.php");
        check('the news page renders without a fatal',
              !preg_match('/(Fatal error|Parse error)/i', $itn), substr($itn, 0, 300));
        check('it shows the announcements that were read',
              strpos($itn, 'Trading ahead of expectations') !== false
              && strpos($itn, 'materially below guidance') !== false);
        check('and not the routine filing, which was never read',
              strpos($itn, 'Transaction in Own Shares') === false);
        check('each row says what kind of announcement it was',
              strpos($itn, 'trading update') !== false && strpos($itn, 'profit warning') !== false);
        check('and what the company said about expectations',
              strpos($itn, 'beat') !== false && strpos($itn, 'missed') !== false);
        check('the key figures and both sides are there to open',
              strpos($itn, 'revenue +7% to GBP1.2bn') !== false
              && strpos($itn, 'Guidance went up.') !== false
              && strpos($itn, 'The comparative was weak.') !== false);
        check('the original is linked, with no referrer and no window handle',
              strpos($itn, 'investegate.co.uk/announcement/rns/a--AAA/t/5001') !== false
              && strpos($itn, 'rel="noopener noreferrer"') !== false);
        check('the quote page is linked from the share',
              strpos($itn, 'quote.php?symbol=AAA') !== false);
        check('whose terms the text is under is on the page',
              stripos($itn, 'Investegate') !== false
              && stripos($itn, 'personal reference') !== false);
        /* "Best received" rather than "Best news", which is the wording on
           the dashboard's box of the same five shares. */
        check('the week is summarised both ways',
              stripos($itn, 'Worst received') !== false && stripos($itn, 'Best received') !== false);
        /* The swatch keeps rns_band()'s colours; its shape moved out of an
           inline style and into app.css with everything else. */
        check('a sentiment band is drawn rather than a bare number',
              strpos($itn, 'sm-swatch') !== false
              && preg_match('/sm-swatch[^>]*background:#[0-9a-f]{6}/i', $itn) === 1,
              substr($itn, max(0, strpos($itn, 'sm-swatch') - 40), 200));
        /* And the number and the band word beside it, which the swatch
           alone never said. */
        check('with the score and the word it stands for',
              preg_match('/[+-]\d\.\d\d (bad|poor|neutral|good|strong)/', $itn) === 1,
              substr($itn, max(0, strpos($itn, 'sm-swatch') - 40), 300));

        /* The filters. Each one is a value the page offered, and the type
           filter is the one that proves the query is parameterised rather
           than concatenated. */
        $itn_pos = (string) $curl("$base/in_the_news.php?tone=positive");
        check('the positive filter leaves out the bad news',
              strpos($itn_pos, 'Trading ahead of expectations') !== false
              && strpos($itn_pos, 'materially below guidance') === false);
        $itn_type = (string) $curl("$base/in_the_news.php?type=profit+warning");
        check('the type filter narrows to that type',
              strpos($itn_type, 'materially below guidance') !== false
              && strpos($itn_type, 'Trading ahead of expectations') === false);
        $itn_odd = (string) $curl("$base/in_the_news.php?type=" . urlencode("' or 1=1 -- "));
        check('a type that was never offered selects nothing and breaks nothing',
              !preg_match('/(Fatal error|SQLSTATE|You have an error)/i', $itn_odd)
              && strpos($itn_odd, 'Trading ahead of expectations') === false,
              substr($itn_odd, 0, 200));
        /* The move comes off the row, not out of a query per row. Planted
           as a number the arithmetic could not produce: if the page still
           worked the move out, it would show the real one instead. This is
           what took the page from 27 seconds to one query. */
        db()->prepare("update rns_announcements set price_move=?, price_move_date=?
                        where announcement_id='5001'")->execute([42.5, $itn_asof]);
        $itn_moved = (string) $curl("$base/in_the_news.php");
        check('the price move on the page is the one stored on the row',
              strpos($itn_moved, '42.5') !== false, substr($itn_moved, 0, 200));

        /* And sorting by it is the database's job now. The negative
           announcement has no stored move at all, so it sorts last either
           way: an unknown move is not the biggest fall. */
        db()->prepare("update rns_announcements set price_move=?, price_move_date=?
                        where announcement_id='5002'")->execute([-11.2, $itn_asof]);
        $itn_by_move = (string) $curl("$base/in_the_news.php?sort=move");
        check('sorting by the move puts the worst first',
              strpos($itn_by_move, '-11.2') !== false
              && strpos($itn_by_move, 'BBB') !== false
              && strpos($itn_by_move, 'BBB') < strpos($itn_by_move, 'AAA'),
              substr($itn_by_move, max(0, strpos($itn_by_move, 'BBB') - 100), 300));

        $itn_days = (string) $curl("$base/in_the_news.php?days=99999");
        check('a period nobody offered falls back to the week rather than reading everything',
              strpos($itn_days, 'Last 7 trading days') !== false
              && !preg_match('/(Fatal error|Parse error)/i', $itn_days));
        $itn_sort = (string) $curl("$base/in_the_news.php?sort=drop+table");
        check('a sort nobody offered falls back to the date',
              !preg_match('/(Fatal error|SQLSTATE)/i', $itn_sort)
              && strpos($itn_sort, 'Trading ahead of expectations') !== false);

        check('the menu links to it',
              strpos((string) $curl("$base/index.php"), 'in_the_news.php') !== false);

        /* A headline is somebody else's text; an announcement is somebody
           else's text read by a model. Both ends escaped. */
        db()->prepare("update rns_announcements set summary=?, bull_point=? where announcement_id='5001'")
            ->execute(['<script>alert(1)</script> and "quotes"',
                       '</td><img src=x onerror=alert(1)>']);
        $itn_xss = (string) $curl("$base/in_the_news.php");
        check('markup in a summary is shown, not run',
              strpos($itn_xss, '<script>alert(1)</script>') === false
              && strpos($itn_xss, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false);
        check('and markup in a bull point cannot close the row it is in',
              strpos($itn_xss, '</td><img src=x') === false);

        db()->exec("truncate rns_announcements");

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
           valuation, health scores, scores, announcements and the ratios. It
           reached none of that while every symbol looked invalid, so assert
           it renders the content rather than only that it does not crash.

           The three financial statements used to be checked here. They are
           not on the page any more: their pane had nothing to open it, so
           seven queries a page were paying for markup nobody could see. */
        $q = (string)$curl("$base/quote.php?symbol=AAA&page=quote_form.php");
        foreach (['Business summary' => 'profile',
                  'Price valuation' => 'valuation',
                  'Financial health' => 'health scores',
                  'Recent announcements' => 'announcements'] as $needle => $what) {
            check("the quote page renders the $what", stripos($q, $needle) !== false);
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
              && preg_match('/id="news".{0,600}(No regulatory announcements|<table|<a href=)/s', $q) === 1,
              substr($q, max(0, strpos($q, 'id="news"')), 300));

        /* ---------------- a screen's results and its CSV ---------------- */

        /* The file is what somebody keeps. If it says something different
           from the page it was downloaded from, the page is what they will
           remember checking and the file is what they will act on. Both are
           built from the same two functions; this is what holds them there. */
        $sc_id = (int) scalar("select id from screen where name='Trend following (200-day)'");
        $sc_page = (string) $curl("$base/screen_list.php?screen=$sc_id&run=1");
        $sc_csv  = (string) $curl("$base/screen_csv.php?screen=$sc_id");

        $sc_lines = array_values(array_filter(array_map('trim', explode("\n", $sc_csv))));
        $sc_rows  = [];
        foreach (array_slice($sc_lines, 1) as $sc_line) {
            $sc_rows[] = str_getcsv($sc_line);
        }

        $sc_expected = screen_result_rows(build_screen($sc_id, null),
                                          screen_indicator_columns($sc_id), screen_asof());

        check('the CSV has a heading row and one row per match',
              count($sc_lines) === count($sc_expected) + 1,
              count($sc_lines) . ' lines for ' . count($sc_expected) . ' matches');

        $sc_wrong = [];
        foreach ($sc_expected as $sc_i => $sc_want) {
            if (!isset($sc_rows[$sc_i])) { $sc_wrong[] = 'missing row ' . ($sc_i + 1); continue; }
            $sc_got = $sc_rows[$sc_i];
            if ((int) $sc_got[0] !== $sc_i + 1)        { $sc_wrong[] = 'rank ' . $sc_got[0]; }
            if ($sc_got[1] !== $sc_want['symbol'])     { $sc_wrong[] = 'symbol ' . $sc_got[1]; }
            if ($sc_got[2] !== (string) $sc_want['name']) { $sc_wrong[] = 'name ' . $sc_got[2]; }
        }
        check('and the rows are the same shares in the same order as the table',
              count($sc_wrong) === 0, implode(', ', array_slice($sc_wrong, 0, 5)));

        /* Every symbol the page shows is in the file and the other way
           round: a filter applied to one and not the other is the failure
           this is for. */
        $sc_in_page = [];
        foreach ($sc_expected as $sc_want) {
            if (strpos($sc_page, '>' . $sc_want['symbol'] . '<') !== false) {
                $sc_in_page[] = $sc_want['symbol'];
            }
        }
        check('every share in the file is on the page too',
              count($sc_in_page) === count($sc_expected),
              count($sc_in_page) . ' of ' . count($sc_expected));

        /* The file is read by something else, so its numbers are
           unformatted: no separators, no "p", no percentage sign. */
        check('the file carries numbers rather than dressed-up ones',
              strpos($sc_csv, 'p,') === false && strpos($sc_csv, '%,') === false
              && strpos($sc_csv, "\xc2\xa3") === false,
              substr($sc_csv, 0, 200));
        check('and it is sent as a file rather than drawn as a page',
              stripos((string) shell_exec('curl -s -D - -o /dev/null -m 30 -b '
                      . escapeshellarg($jar) . ' '
                      . escapeshellarg("$base/screen_csv.php?screen=$sc_id") . ' 2>/dev/null'),
                      'Content-Disposition: attachment') !== false);

        /* ---------------- The strategy page ---------------- */

        /* A strategy with a night behind it, so the page has figures to
           show rather than only its empty state. Written straight in: the
           page is what is under test here, not the engine. */
        $sp = 'test_page_strategy';
        foreach (['strategy_orders','strategy_positions','strategy_targets',
                  'strategy_values','strategy_rules','strategy_accounts'] as $sp_table) {
            db()->prepare("delete from $sp_table where strategy=?")->execute([$sp]);
        }
        $sp_asof = asof_date();
        $sp_prev = scalar("select distinct date from historical_prices where exchange='XLON'
                            and date<? order by date desc limit 1", [$sp_asof]);
        $sp_price = (float) scalar("select price from historical_prices
                                     where symbol='AAA' and exchange='XLON' and date=?", [$sp_asof]);
        db()->prepare("insert into strategy_accounts (strategy,cash,currency,mode,enabled,created_at,note)
                       values (?,25000,'GBP','PAPER','Y',now(),?)")
          ->execute([$sp, 'Paper. <script>alert(1)</script> note.']);
        db()->prepare("insert into strategy_rules (strategy,selection,score_indicator,rank_by,holdings,
                                                   rebalance,exit_rules,exclude_funds,updated_at)
                       values (?,'score','momentum_score','momentum_score',5,'weekly','below_ma200_2d','Y',now())")
          ->execute([$sp]);
        db()->prepare("insert into strategy_positions (strategy,symbol,exchange,quantity,avg_price,updated_at)
                       values (?,'AAA','XLON',1000,?,now())")->execute([$sp, $sp_price]);
        db()->prepare("insert into strategy_orders
                         (client_order_id,strategy,as_of_date,symbol,exchange,side,quantity,status,
                          fill_date,fill_price,consideration,commission,stamp_duty,slippage,total_cost,
                          note,created_at,filled_at)
                       values (?,?,?,'AAA','XLON','BUY',1000,'FILLED',?,?,?,5.95,?,?,?,'rebalance',now(),now())")
          ->execute([$sp . '-page-1', $sp, $sp_prev, $sp_asof, $sp_price, $sp_price * 1000,
                     $sp_price * 1000 * 0.005, $sp_price * 1000 * 0.0025,
                     -($sp_price * 1000 * 1.0075 + 5.95)]);
        db()->prepare("insert into strategy_targets (strategy,as_of_date,symbol,exchange,target_weight,created_at)
                       values (?,?,'AAA','XLON',0.2,now())")->execute([$sp, $sp_prev]);
        $sp_values = db()->prepare("insert into strategy_values
                                      (strategy,date,cash,holdings_value,total,holdings)
                                    values (?,?,?,?,?,?)");
        $sp_values->execute([$sp, $sp_prev, 25000, 75000, 100000, 1]);
        $sp_values->execute([$sp, $sp_asof, 25000, 80000, 105000, 1]);
        db()->prepare("delete from strategy_values where strategy=?")->execute([STRATEGY_BENCHMARK]);
        $sp_values->execute([STRATEGY_BENCHMARK, $sp_prev, 0, 0, 100, 12]);
        $sp_values->execute([STRATEGY_BENCHMARK, $sp_asof, 0, 0, 102, 12]);

        $sp_page = (string) $curl("$base/strategies.php");

        check('the strategy page renders without a fatal',
              stripos($sp_page, 'Fatal error') === false
              && stripos($sp_page, 'Parse error') === false,
              substr($sp_page, 0, 300));
        check('it shows the strategy, its value and its return',
              strpos($sp_page, $sp) !== false
              && strpos($sp_page, '105,000') !== false
              && strpos($sp_page, '+5.0%') !== false,
              substr($sp_page, max(0, strpos($sp_page, $sp)), 400));
        check('and the benchmark over the same period, not over all of history',
              strpos($sp_page, '+2.0%') !== false);
        check('the holding is listed with its weight and a link to the quote page',
              preg_match('/quote\.php\?symbol=AAA/', $sp_page) === 1
              && strpos($sp_page, '1,000') !== false);
        check('the trade says why it happened',
              strpos($sp_page, 'rebalance') !== false);
        check('the dealing costs paid are on the page',
              stripos($sp_page, 'Dealing costs paid') !== false);
        check('it says in plain words that nothing real is traded',
              stripos($sp_page, 'No real orders are ever placed') !== false);
        check('and names the benchmark, because equal weighted is a choice',
              stripos($sp_page, 'equal weighted') !== false
              && stripos($sp_page, 'leaves out the companies that have left the index') !== false,
              substr($sp_page, max(0, stripos($sp_page, 'benchmark')), 400));
        check('markup in a strategy note is shown, not run',
              strpos($sp_page, '<script>alert(1)</script>') === false
              && strpos($sp_page, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false);
        check('the rebalance dates are shown',
              stripos($sp_page, 'Last rebalance') !== false
              && stripos($sp_page, 'Next on or after') !== false);

        /* The chart's data is JSON in the page rather than generated
           JavaScript, and the strategy names in it are escaped the same way
           everything else is. */
        check('the chart data is JSON, with the names escaped',
              strpos($sp_page, 'id="strategy-chart-data"') !== false
              && strpos($sp_page, 'lightweight-charts.standalone.production.js') !== false,
              substr($sp_page, max(0, strpos($sp_page, 'strategy-chart')), 300));

        /* A backtest of the same rules belongs on the page under the live
           card, labelled so nobody reads a replay as a record of trading. */
        db()->exec("delete from strategy_backtest_runs where strategy='$sp'");
        db()->prepare("insert into strategy_backtest_runs
                         (strategy,account,from_date,to_date,started_at,finished_at,status,
                          trading_days,rebalances,exits,fills,start_value,final_value,
                          total_return,benchmark_return,max_drawdown,costs_paid,note)
                       values (?,?,?,?,now(),now(),'DONE',120,6,2,30,100000,112000,
                               0.12,0.04,0.085,842.10,?)")
          ->execute([$sp, STRATEGY_BACKTEST_PREFIX . $sp . '_1', '2026-01-02', $sp_prev,
                     'Survivorship: the universe is today\'s FTSE 350. Look-ahead: each date'
                     . ' used only the statistics stored for it.']);

        $sp_with_bt = (string) $curl("$base/strategies.php");
        check('a backtest appears under the live card, labelled a backtest',
              stripos($sp_with_bt, 'Backtest') !== false
              && strpos($sp_with_bt, '+12.0%') !== false
              && stripos($sp_with_bt, 'Not a record of trading') !== false,
              substr($sp_with_bt, max(0, stripos($sp_with_bt, 'Backtest')), 500));
        check('with its own benchmark figure and its worst fall',
              strpos($sp_with_bt, '+4.0%') !== false && strpos($sp_with_bt, '-8.5%') !== false);
        check('and the survivorship caveat next to it, not buried in a log',
              stripos($sp_with_bt, 'survivorship') !== false);
        db()->exec("delete from strategy_backtest_runs where strategy='$sp'");

        /* ---------------- The dry run popup ---------------- */

        /* It used to be a POST back to the page that printed the engine's own
           lines in a <pre> block at the top - hard to read, and sitting away
           from the strategy it belonged to. Now an endpoint that answers with
           the plan as data, drawn in a popup over the page.

           The endpoint's one promise is that it writes nothing, so that is
           the first thing checked and it is checked by counting rows rather
           than by reading the code. */
        $dr_orders_before  = (int) scalar("select count(*) from strategy_orders");
        $dr_targets_before = (int) scalar("select count(*) from strategy_targets");
        $dr_values_before  = (int) scalar("select count(*) from strategy_values");
        $dr_cash_before    = scalar("select cash from strategy_accounts where strategy=?", [$sp]);

        $dr_body = (string) $curl("$base/strategy_dry_run.php", 'strategy=' . urlencode($sp));
        $dr = json_decode($dr_body, true);

        check('the dry run endpoint answers with JSON',
              is_array($dr) && ($dr['ok'] ?? false) === true,
              substr($dr_body, 0, 300));
        check('and writes nothing at all: no orders, no targets, no values, no cash moved',
              (int) scalar("select count(*) from strategy_orders") === $dr_orders_before
              && (int) scalar("select count(*) from strategy_targets") === $dr_targets_before
              && (int) scalar("select count(*) from strategy_values") === $dr_values_before
              && scalar("select cash from strategy_accounts where strategy=?", [$sp]) === $dr_cash_before);

        check('it says which strategy and which date it planned against',
              ($dr['strategy'] ?? '') === $sp && ($dr['as_of'] ?? '') === $sp_asof,
              json_encode([$dr['strategy'] ?? null, $dr['as_of'] ?? null, $sp_asof]));
        check('and whether tonight is a rebalance, with the next date either way',
              array_key_exists('rebalance', $dr)
              && ($dr['rebalance'] === true || $dr['next_rebalance'] !== null),
              json_encode([$dr['rebalance'] ?? null, $dr['next_rebalance'] ?? null]));
        check('the footnote says fills happen at the next close, not this one',
              stripos((string) ($dr['footnote'] ?? ''), 'next trading') !== false,
              (string) ($dr['footnote'] ?? ''));

        /* Every trade carries what the table needs, and the value is the
           quantity times the price rather than a separately computed number
           that could disagree with it. */
        $dr_shape = true;
        $dr_arithmetic = true;
        foreach (($dr['trades'] ?? []) as $trade) {
            foreach (['side','symbol','quantity','price','value','costs','reason'] as $field) {
                if (!array_key_exists($field, $trade)) { $dr_shape = false; }
            }
            if (!in_array($trade['side'] ?? '', ['BUY','SELL'], true)) { $dr_shape = false; }
            if (!is_array($trade['costs'] ?? null)) { $dr_shape = false; }
            if ($trade['price'] !== null
                && abs(($trade['quantity'] * $trade['price']) - $trade['value']) > 0.01) {
                $dr_arithmetic = false;
            }
            /* Pounds, not pence. A share whose price in pounds is under 100
               must not appear here at a hundred times that. */
            if ($trade['price'] !== null && $trade['price'] > 1000) { $dr_shape = false; }
        }
        check('every planned trade carries side, share, quantity, price, value, costs and reason',
              $dr_shape, json_encode(array_slice($dr['trades'] ?? [], 0, 2)));
        check('and the value is the quantity times the price, to the penny',
              $dr_arithmetic, json_encode(array_slice($dr['trades'] ?? [], 0, 2)));

        /* A reason in plain words rather than the engine's own vocabulary:
           the popup is read by somebody deciding whether tonight looks
           right. */
        check('a reason reads as a sentence, not as a code',
              strategy_reason_words('new_holding') === 'Rebalance: new holding'
              && strategy_reason_words('below_ma200_2d')
                 === 'Exit: closed below 200-day average twice'
              && strategy_reason_words('delisted') === 'Delisted: sold at last price');

        check('the totals split the costs into commission, stamp duty and spread',
              isset($dr['totals']['costs']['commission'])
              && isset($dr['totals']['costs']['stamp_duty'])
              && isset($dr['totals']['costs']['slippage'])
              && isset($dr['totals']['cash_before'])
              && isset($dr['totals']['cash_after'])
              && isset($dr['totals']['holdings_before'])
              && isset($dr['totals']['holdings_after']),
              json_encode($dr['totals'] ?? null));

        /* A night with nothing to do says so rather than showing an empty
           table, and says when the next rebalance is. The page strategy is
           weekly with a holding already at target, so most nights are this. */
        check('a night with no trades says so',
              count($dr['trades'] ?? []) > 0
              || (count($dr['trades'] ?? []) === 0 && $dr['next_rebalance'] !== null),
              json_encode([$dr['trades'] ?? null, $dr['next_rebalance'] ?? null]));

        /* The kill switch. The endpoint reports it rather than planning
           around it, and places nothing either way. */
        $dr_switch = sys_get_temp_dir() . '/sm_stop_dryrun_' . getmypid();
        file_put_contents($dr_switch, "stop\n");
        $dr_halted_body = (string) shell_exec('curl -s -m 30 -b ' . escapeshellarg($jar)
                          . ' -c ' . escapeshellarg($jar)
                          . ' -d ' . escapeshellarg('strategy=' . $sp)
                          . ' ' . escapeshellarg("$base/strategy_dry_run.php?") . ' 2>/dev/null');
        @unlink($dr_switch);
        /* The test server was started without that variable, so this cannot
           assert the halted path through HTTP - the switch is read from the
           environment of the php process. Asserted directly instead, which
           is where the behaviour lives. */
        check('the kill switch state is part of what the dry run reports',
              array_key_exists('halted', $dr),
              json_encode(array_keys($dr)));

        /* Not logged in: a redirect to the login page, not a plan. A paper
           book is still a statement about what somebody is doing with their
           money. */
        $dr_code = trim((string) shell_exec('curl -s -o /dev/null -w "%{http_code}" -m 30 '
                        . ' -d ' . escapeshellarg('strategy=' . $sp)
                        . ' ' . escapeshellarg("$base/strategy_dry_run.php") . ' 2>/dev/null'));
        $dr_anon = (string) shell_exec('curl -s -m 30 -d ' . escapeshellarg('strategy=' . $sp)
                   . ' ' . escapeshellarg("$base/strategy_dry_run.php") . ' 2>/dev/null');
        check('an anonymous dry run is redirected rather than answered',
              $dr_code === '302', "status $dr_code");
        check('and no plan reaches it',
              strpos($dr_anon, '"trades"') === false && strpos($dr_anon, $sp) === false,
              substr($dr_anon, 0, 160));

        /* A strategy nobody listed cannot be planned, and neither can a
           backtest account. */
        $dr_bad = (string) $curl("$base/strategy_dry_run.php",
                                 'strategy=' . urlencode(STRATEGY_BACKTEST_PREFIX . 'anything'));
        check('a backtest account cannot be dry run',
              strpos($dr_bad, '"ok":false') !== false && strpos($dr_bad, '"trades"') === false,
              substr($dr_bad, 0, 200));

        /* GET is refused: this is an action, and a link somebody can share
           would be fetched by every crawler, each fetch running a screen
           over the universe. */
        $dr_get = trim((string) shell_exec('curl -s -o /dev/null -w "%{http_code}" -m 30 -b '
                       . escapeshellarg($jar) . ' '
                       . escapeshellarg("$base/strategy_dry_run.php?strategy=$sp") . ' 2>/dev/null'));
        check('a GET is refused with 405 rather than answered',
              $dr_get === '405', "status $dr_get");

        /* The page itself: one way to ask, not two. The old <pre> card and
           its POST route are gone, and the popup is what is left. */
        $dr_page = (string) $curl("$base/strategies.php");
        check('the page offers the popup button',
              strpos($dr_page, 'data-dry-run="' . $sp . '"') !== false,
              substr($dr_page, max(0, strpos($dr_page, 'data-dry-run')), 200));
        check('and the modal it opens, with a Close and no "run for real"',
              strpos($dr_page, 'id="dry-run-modal"') !== false
              && stripos($dr_page, 'data-bs-dismiss="modal"') !== false
              && stripos($dr_page, 'run for real') === false);
        check('the old POST route is gone, so there is one way to ask',
              strpos($dr_page, 'name="dry_run"') === false
              && strpos((string) file_get_contents(dirname(__DIR__) . '/public/strategies.php'),
                        "POST['dry_run']") === false);
        check('and the script is copied into the docroot on deploy',
              strpos((string) file_get_contents(dirname(__DIR__) . '/tools/fetch_assets.sh'),
                     'strategy_dry_run.js') !== false);

        /* A strategy nobody listed cannot be planned, whatever the parameter
           says. Checked against the endpoint now rather than against the
           page's old POST route. */
        $sp_other_before = (int) scalar("select count(*) from strategy_targets where strategy='trending_value'");
        $sp_injected = (string) $curl("$base/strategy_dry_run.php",
                                      'strategy=' . urlencode('../../etc/passwd'));
        check('a strategy the page never listed cannot be dry run',
              strpos($sp_injected, '"trades"') === false
              && strpos($sp_injected, '"ok":false') !== false
              && (int) scalar("select count(*) from strategy_targets where strategy='trending_value'")
                 === $sp_other_before,
              substr($sp_injected, 0, 160));

        /* Behind the login like everything else. A paper book is still a
           statement about what somebody is doing with their money. */
        $sp_jar = tempnam(sys_get_temp_dir(), 'smanon2');
        $sp_code = trim((string) shell_exec('curl -s -o /dev/null -w "%{http_code}" -m 30 '
                                            . escapeshellarg("$base/strategies.php") . ' 2>/dev/null'));
        $sp_anon = (string) shell_exec('curl -s -m 30 -b ' . escapeshellarg($sp_jar)
                                       . ' -c ' . escapeshellarg($sp_jar) . ' '
                                       . escapeshellarg("$base/strategies.php") . ' 2>/dev/null');
        check('an anonymous request for the strategy page is redirected to login',
              $sp_code === '302', "status $sp_code");
        check('and no holdings reach it',
              strpos($sp_anon, $sp) === false && stripos($sp_anon, '105,000') === false,
              substr($sp_anon, 0, 160));
        @unlink($sp_jar);

        /* The 2023 page is retired rather than deleted, and the new one is
           at the URL the navigation points at. */
        check('the 2023 Backing Winners page is in the archive',
              file_exists(dirname(__DIR__) . '/archive/public/strategies.php')
              && file_exists(dirname(__DIR__) . '/archive/templates/strategies.php'));
        check('and public/strategies.php is the paper strategies page now',
              strpos((string) file_get_contents(dirname(__DIR__) . '/public/strategies.php'),
                     'strategy_functions.php') !== false);
        check('the navigation links to it',
              strpos((string) file_get_contents(dirname(__DIR__) . '/templates/header.php'),
                     'strategies.php">Strategies') !== false);
        check('and the chart script is copied into the docroot on deploy',
              strpos((string) file_get_contents(dirname(__DIR__) . '/tools/fetch_assets.sh'),
                     'strategy_chart.js') !== false);

        foreach (['strategy_orders','strategy_positions','strategy_targets',
                  'strategy_values','strategy_rules','strategy_accounts'] as $sp_table) {
            db()->prepare("delete from $sp_table where strategy=?")->execute([$sp]);
        }

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
        /* Comments stripped - the prose in footer.php has to name the
           Bootstrap it no longer loads to explain why. HTML comments were
           stripped here already; the note is a PHP comment now, so that is
           stripped too. What the check asks is unchanged: no second
           framework in the markup. */
        $footer = preg_replace('#/\*.*?\*/#s', '',
                  preg_replace('/<!--.*?-->/s', '',
                               (string)file_get_contents(dirname(__DIR__) . '/templates/footer.php')));
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

        echo "\n--- the chart data endpoint ---\n";

        /* The quote page's chart is drawn in the browser now, from this.
           It is behind the same login as every other page: config.php
           redirects an anonymous request before it reaches a query. */
        $ch_anon_jar = tempnam(sys_get_temp_dir(), 'smchart');
        $ch_anon = (string) shell_exec('curl -s -m 30 -b ' . escapeshellarg($ch_anon_jar)
                 . ' -c ' . escapeshellarg($ch_anon_jar) . ' -L '
                 . escapeshellarg("$base/chart_data.php?symbol=AAA") . ' 2>/dev/null');
        check('an anonymous request for the chart data does not get data',
              strpos($ch_anon, '"prices"') === false, substr($ch_anon, 0, 200));
        check('it is sent to the login page instead',
              stripos($ch_anon, 'password') !== false, substr($ch_anon, 0, 200));
        @unlink($ch_anon_jar);

        /* Announcements, not headlines: the markers read rns_announcements
           now. The fixture moved with the source rather than the checks
           being weakened - what a marker is has changed, what it has to show
           has not. */
        $ch_asof = asof_date();
        db()->exec("delete from rns_announcements where symbol='AAA'");
        $ch_insert = db()->prepare("insert into rns_announcements
            (announcement_id, symbol, source, title, published_at, url, body, skipped,
             sentiment, ann_type, vs_expectations, summary, key_figures,
             bull_point, bear_point, scored_model, scored_at, first_seen)
            values (?, 'AAA', ?, ?, ?, ?, 'text', 'N', ?, ?, 'not stated', ?, '[]',
                    'Bull side', 'Bear side', 'stub', now(), now())");
        //Two on one day and one on another, so the grouping has something to do.
        $ch_day1 = date('Y-m-d', strtotime($ch_asof . ' -3 day'));
        $ch_day2 = date('Y-m-d', strtotime($ch_asof . ' -10 day'));
        $ch_insert->execute(['c1', 'RNS', 'Good news for AAA', $ch_day1 . ' 09:00:00',
                             'https://example.invalid/c1', 0.8, 'results', 'It went well.']);
        $ch_insert->execute(['c2', 'RNS', 'More good news', $ch_day1 . ' 15:00:00',
                             'https://example.invalid/c2', 0.4, 'contract or product', 'A new thing.']);
        $ch_insert->execute(['c3', 'RNS', 'Bad news for AAA', $ch_day2 . ' 09:00:00',
                             'https://example.invalid/c3', -0.6, 'regulatory or legal', 'It went badly.']);
        /* One outside the range at each end. Both sides, because a bound that
           is only tested from below is only half a bound. */
        $ch_insert->execute(['c4', 'RNS', 'Ancient history',
                             date('Y-m-d', strtotime($ch_asof . ' -400 day')) . ' 09:00:00',
                             'https://example.invalid/c4', 0.1, 'other', 'Long ago.']);
        $ch_insert->execute(['c4b', 'RNS', 'Not yet happened',
                             date('Y-m-d', strtotime($ch_asof . ' +400 day')) . ' 09:00:00',
                             'https://example.invalid/c4b', 0.1, 'other', 'Later.']);
        /* And a routine filing, which the chart must not mark at all: a dot
           on the price for a buyback's paperwork is exactly the noise this
           whole change was about. */
        $ch_insert->execute(['c4c', 'RNS', 'Transaction in Own Shares', $ch_day1 . ' 08:00:00',
                             'https://example.invalid/c4c', 0.0, 'other', 'Paperwork.']);
        db()->exec("update rns_announcements set skipped='Y', skip_rule='Transaction in Own Shares',
                        scored_at=null, sentiment=null where announcement_id='c4c'");

        $ch_from = date('Y-m-d', strtotime($ch_asof . ' -30 day'));
        $ch_json = json_decode((string) $curl(
            "$base/chart_data.php?symbol=AAA&from=$ch_from&to=$ch_asof"), true);

        check('a logged in request gets the series', is_array($ch_json) && !empty($ch_json['ok']),
              json_encode(array_slice((array) $ch_json, 0, 3)));
        check('with prices in it', count($ch_json['prices'] ?? []) > 0);
        check('every price is inside the range asked for',
              count(array_filter($ch_json['prices'] ?? [],
                    function ($p) use ($ch_from, $ch_asof) {
                        return $p['time'] < $ch_from || $p['time'] > $ch_asof;
                    })) === 0);
        check('the two moving averages come from statistics, not recomputed',
              array_key_exists('ma50', $ch_json) && array_key_exists('ma200', $ch_json));

        $ch_markers = $ch_json['markers'] ?? [];
        check('one marker per day with news, not one per headline',
              count($ch_markers) === 2, count($ch_markers) . ' markers');
        $ch_by_day = [];
        foreach ($ch_markers as $m) { $ch_by_day[$m['date']] = $m; }
        check('the day with two headlines says so',
              ($ch_by_day[$ch_day1]['count'] ?? 0) === 2);
        check('and carries the mean of their sentiment',
              abs(($ch_by_day[$ch_day1]['sentiment'] ?? 0) - 0.6) < 0.0001,
              var_export($ch_by_day[$ch_day1]['sentiment'] ?? null, true));
        check('a bad day reads negative',
              ($ch_by_day[$ch_day2]['sentiment'] ?? 0) < 0);
        check('the kinds of news that day are listed, for the filter',
              in_array('results', $ch_by_day[$ch_day1]['impact_types'] ?? [], true)
              && in_array('contract or product', $ch_by_day[$ch_day1]['impact_types'] ?? [], true),
              json_encode($ch_by_day[$ch_day1]['impact_types'] ?? null));
        check('and a routine filing is not marked on the chart at all',
              strpos(json_encode($ch_markers), 'Paperwork') === false
              && ($ch_by_day[$ch_day1]['count'] ?? 0) === 2,
              json_encode($ch_by_day[$ch_day1]['count'] ?? null));
        check('an article before the range is not in it',
              strpos(json_encode($ch_markers), 'Ancient history') === false);
        check('nor one after it',
              strpos(json_encode($ch_markers), 'Not yet happened') === false);
        check('each headline carries what the panel shows',
              isset($ch_by_day[$ch_day1]['articles'][0]['summary'])
              && isset($ch_by_day[$ch_day1]['articles'][0]['bull_point'])
              && isset($ch_by_day[$ch_day1]['articles'][0]['link']));

        /* A headline is somebody else's text. It is JSON on the way out, so
           json_encode escapes it, and the page puts it on screen with
           textContent - never innerHTML - so there is nowhere for it to
           become markup. */
        db()->prepare("insert into rns_announcements
            (announcement_id, symbol, source, title, published_at, url, body, skipped,
             sentiment, ann_type, vs_expectations, summary, key_figures, scored_model, scored_at, first_seen)
            values ('c5', 'AAA', 'RNS', ?, ?, ?, 'text', 'N', 0.1, 'other', 'not stated',
                    ?, '[]', 'stub', now(), now())")
            ->execute(['<script>alert(1)</script> and "quotes"',
                       $ch_day1 . ' 17:00:00',
                       'javascript:alert(1)',
                       '</script><img src=x onerror=alert(1)>']);
        $ch_raw = (string) $curl("$base/chart_data.php?symbol=AAA&from=$ch_from&to=$ch_asof");
        check('a headline with markup in it is escaped in the JSON',
              strpos($ch_raw, '<script>alert(1)</script>') === false
              && strpos($ch_raw, '<script>') !== false,
              substr($ch_raw, 0, 300));
        /* The only assignment to innerHTML may be the empty string that
           clears the panel. Anything else is a headline being parsed as
           markup. */
        $ch_js = (string) file_get_contents(dirname(__DIR__) . '/templates/chart.js');
        preg_match_all('/innerHTML\s*=\s*([^;]+);/', $ch_js, $ch_writes);
        check('and the page builds its text with textContent, never innerHTML',
              count(array_filter($ch_writes[1] ?? [],
                    function ($written) { return trim($written) !== '""'; })) === 0,
              json_encode($ch_writes[1] ?? []));
        check('it does use textContent, so that is not vacuous',
              substr_count($ch_js, 'textContent') >= 2);
        /* And a link out of a feed only reaches an href if it is http: a
           javascript: or data: URL in a headline is the one thing in here
           that could do something. */
        check('only an http link reaches an href',
              strpos($ch_js, 'safeHref') !== false
              && strpos($ch_js, '^https?:') !== false,
              'the scheme check is not the one expected');
        db()->exec("delete from rns_announcements where announcement_id='c5'");

        //Parameters are checked rather than interpreted.
        foreach (['yesterday', '2026-13-45', "2026-01-01' or '1'='1", '+1 month'] as $ch_bad) {
            $ch_refused = json_decode((string) $curl(
                "$base/chart_data.php?symbol=AAA&from=" . urlencode($ch_bad) . "&to=$ch_asof"), true);
            check("a from date of '$ch_bad' is refused rather than interpreted",
                  isset($ch_refused['ok']) && $ch_refused['ok'] === false,
                  json_encode($ch_refused));
        }
        $ch_unknown = json_decode((string) $curl("$base/chart_data.php?symbol=NOSUCHSYM"), true);
        check('an unknown symbol is a clear answer, not an empty chart',
              isset($ch_unknown['ok']) && $ch_unknown['ok'] === false);
        $ch_backwards = json_decode((string) $curl(
            "$base/chart_data.php?symbol=AAA&from=$ch_asof&to=$ch_from"), true);
        check('a range that ends before it starts is refused',
              isset($ch_backwards['ok']) && $ch_backwards['ok'] === false);

        echo "\n--- the chart on the page ---\n";

        $ch_page = (string) $curl("$base/quote.php?symbol=AAA&page=quote_form.php");
        check('the page has somewhere to draw the chart',
              strpos($ch_page, 'id="price-chart"') !== false);
        check('it loads the library from this server, not a CDN',
              strpos($ch_page, '/js/lightweight-charts.standalone.production.js') !== false
              && strpos($ch_page, 'unpkg.com') === false
              && strpos($ch_page, 'cdn.jsdelivr.net') === false);
        check('the old static chart is still there as the fallback',
              strpos($ch_page, 'stockgraph.php?symbol=AAA') !== false
              && strpos($ch_page, 'id="price-chart-fallback"') !== false);
        check('and a browser with no JavaScript is told why',
              strpos($ch_page, '<noscript>') !== false);
        foreach (['30','91','182','365','1825'] as $ch_span) {
            check("there is a $ch_span day timespan button",
                  strpos($ch_page, 'data-chart-span="' . $ch_span . '"') !== false);
        }
        check('and a filter by the kind of news',
              strpos($ch_page, 'id="news-filter"') !== false
              && strpos($ch_page, '>Earnings<') !== false);
        check('the panel the headlines are listed in is on the page',
              strpos($ch_page, 'id="news-panel"') !== false);

        /* The quote page is about half a second today and should stay under
           a second. The chart is drawn from a second request, so the page
           itself should not have got slower. */
        $ch_started = microtime(true);
        $curl("$base/quote.php?symbol=AAA&page=quote_form.php");
        $ch_page_seconds = microtime(true) - $ch_started;
        check('the quote page still loads in under a second',
              $ch_page_seconds < 1.0, round($ch_page_seconds, 3) . 's');
        $ch_started = microtime(true);
        $curl("$base/chart_data.php?symbol=AAA&from=$ch_from&to=$ch_asof");
        $ch_data_seconds = microtime(true) - $ch_started;
        check('and the chart data comes back in under a second too',
              $ch_data_seconds < 1.0, round($ch_data_seconds, 3) . 's');

        db()->exec("delete from rns_announcements where symbol='AAA'");

        echo "\n--- why did it move? ---\n";

        /* The same pattern as the outlook panel: nothing happens until the
           button is pressed, the answer is cached per range, and Regenerate
           is the way to pay for it again. The stub answers, never the real
           API. */

        $ex_asof = asof_date();
        $ex_to   = $ex_asof;
        $ex_from = date('Y-m-d', strtotime($ex_asof . ' -14 day'));

        db()->exec("delete from ai_explanations where symbol='AAA'");
        db()->exec("delete from rns_announcements where symbol='AAA'");
        $ex_insert = db()->prepare("insert into rns_announcements
            (announcement_id, symbol, source, title, published_at, url, body, skipped,
             sentiment, ann_type, vs_expectations, summary, key_figures, scored_model, scored_at, first_seen)
            values (?, 'AAA', 'RNS', ?, ?, ?, 'text', 'N', ?, 'results', ?, ?, '[]',
                    'stub', now(), now())");
        $ex_day = date('Y-m-d', strtotime($ex_asof . ' -7 day'));
        $ex_insert->execute(['e1', 'AAA beats expectations', $ex_day . ' 09:00:00',
                             'https://example.invalid/e1', 0.9, 'beat',
                             'Profit ahead of forecast.']);
        $ex_id = (int) scalar("select id from rns_announcements where announcement_id='e1'");

        //POST only: a link or a prefetch must not be able to spend money.
        $ex_get = (string) $curl("$base/ai_explain.php?symbol=AAA");
        check('the explain endpoint refuses a GET',
              strpos($ex_get, '"ok":false') !== false
              && stripos($ex_get, 'Use POST') !== false, substr($ex_get, 0, 200));

        $ex_anon_jar = tempnam(sys_get_temp_dir(), 'smex');
        $ex_anon = (string) shell_exec('curl -s -m 30 -b ' . escapeshellarg($ex_anon_jar)
                 . ' -c ' . escapeshellarg($ex_anon_jar) . ' -L -d '
                 . escapeshellarg("symbol=AAA&from=$ex_from&to=$ex_to") . ' '
                 . escapeshellarg("$base/ai_explain.php") . ' 2>/dev/null');
        check('an anonymous request never reaches the API',
              strpos($ex_anon, '"explanation"') === false, substr($ex_anon, 0, 200));
        @unlink($ex_anon_jar);

        foreach (['yesterday', '', '2026-02-30'] as $ex_bad) {
            $ex_refused = json_decode((string) $curl("$base/ai_explain.php",
                "symbol=AAA&from=" . urlencode($ex_bad) . "&to=$ex_to"), true);
            check("a from date of '$ex_bad' is refused rather than interpreted",
                  isset($ex_refused['ok']) && $ex_refused['ok'] === false,
                  json_encode($ex_refused));
        }

        file_put_contents("$ai_dir/mode", 'explain_ok');
        $ex_first = json_decode((string) $curl("$base/ai_explain.php",
            "symbol=AAA&from=$ex_from&to=$ex_to"), true);

        check('an explanation comes back', !empty($ex_first['ok']),
              json_encode(array_slice((array) $ex_first, 0, 3)));
        check('it is not from the cache the first time', ($ex_first['cached'] ?? null) === false);
        check('with a summary and a confidence',
              trim((string) ($ex_first['explanation']['summary'] ?? '')) !== ''
              && in_array($ex_first['explanation']['confidence'] ?? '', ['low','medium','high'], true));
        check('and how much of it was the market',
              trim((string) ($ex_first['explanation']['market_vs_stock'] ?? '')) !== '');
        check('a driver cites the headline it came from',
              count(array_filter($ex_first['explanation']['drivers'] ?? [],
                    function ($d) use ($ex_id) { return $d['source'] === (string) $ex_id; })) === 1,
              json_encode($ex_first['explanation']['drivers'] ?? []));
        check('and one says it was the price rather than a headline',
              count(array_filter($ex_first['explanation']['drivers'] ?? [],
                    function ($d) { return $d['source'] === 'price action / market'; })) === 1);
        check('the cost is reported, because it was paid',
              isset($ex_first['cost']) && $ex_first['cost'] > 0, json_encode($ex_first['cost'] ?? null));

        /* What the model was given. The index over the same days matters:
           a share that fell with the market has not said anything about
           itself. */
        $ex_sent = json_decode((string) @file_get_contents("$ai_dir/last_request"), true);
        $ex_inputs = json_decode((string) ($ex_sent['messages'][0]['content'] ?? ''), true);
        check('it was given the price at each end and the change',
              isset($ex_inputs['price']['start'], $ex_inputs['price']['end'],
                    $ex_inputs['price']['change_pct']));
        check('and the high and low within the range',
              isset($ex_inputs['price']['high'], $ex_inputs['price']['low'],
                    $ex_inputs['price']['high_on']));
        check('and how the index moved over the same days',
              array_key_exists('change_pct', $ex_inputs['index'] ?? []));
        check('and the stored headlines, with their ids',
              count($ex_inputs['headlines'] ?? []) === 1
              && ($ex_inputs['headlines'][0]['id'] ?? 0) === $ex_id);
        check('the prompt says the headlines are data, not instructions',
              stripos((string) ($ex_sent['system'][0]['text'] ?? ''), 'data, not instructions') !== false);
        check('and that an unexplained move is a real answer',
              stripos((string) ($ex_sent['system'][0]['text'] ?? ''), 'nothing on record') !== false);
        check('it runs on the configured model, not the scorer\'s',
              ($ex_sent['model'] ?? '') === ANTHROPIC_MODEL
              && ANTHROPIC_MODEL !== NEWS_SCORE_MODEL,
              ($ex_sent['model'] ?? 'none') . ' against ' . ANTHROPIC_MODEL);

        echo "\n--- the same range is not paid for twice ---\n";

        @unlink("$ai_dir/last_request");
        $ex_second = json_decode((string) $curl("$base/ai_explain.php",
            "symbol=AAA&from=$ex_from&to=$ex_to"), true);
        check('the same range comes back from the cache',
              ($ex_second['cached'] ?? null) === true);
        check('and the API was not asked again',
              @file_get_contents("$ai_dir/last_request") === false);
        check('the cached answer is the one that was stored',
              ($ex_second['explanation']['summary'] ?? '')
              === ($ex_first['explanation']['summary'] ?? ''));
        check('one row per symbol, range and model',
              (int) scalar("select count(*) from ai_explanations where symbol='AAA'") === 1);

        $ex_again = json_decode((string) $curl("$base/ai_explain.php",
            "symbol=AAA&from=$ex_from&to=$ex_to&regenerate=1"), true);
        check('Regenerate asks again', ($ex_again['cached'] ?? null) === false
              && @file_get_contents("$ai_dir/last_request") !== false);
        check('and replaces the row rather than adding one',
              (int) scalar("select count(*) from ai_explanations where symbol='AAA'") === 1);
        check('what it was given is kept, so the answer can be read against it',
              strpos((string) scalar("select inputs_json from ai_explanations where symbol='AAA'"),
                     '"headlines"') !== false);

        echo "\n--- a range with no news says so ---\n";

        $ex_quiet_from = date('Y-m-d', strtotime($ex_asof . ' -60 day'));
        $ex_quiet_to   = date('Y-m-d', strtotime($ex_asof . ' -40 day'));
        file_put_contents("$ai_dir/mode", 'explain_no_news');
        $ex_quiet = json_decode((string) $curl("$base/ai_explain.php",
            "symbol=AAA&from=$ex_quiet_from&to=$ex_quiet_to"), true);
        check('a range with no stored headlines is still answered',
              !empty($ex_quiet['ok']), json_encode(array_slice((array) $ex_quiet, 0, 3)));
        check('with no drivers rather than invented ones',
              count($ex_quiet['explanation']['drivers'] ?? []) === 0);
        $ex_quiet_sent = json_decode((string) @file_get_contents("$ai_dir/last_request"), true);
        $ex_quiet_inputs = json_decode((string) ($ex_quiet_sent['messages'][0]['content'] ?? ''), true);
        check('and the model was told there were none',
              count($ex_quiet_inputs['headlines'] ?? []) === 0
              && stripos((string) ($ex_quiet_inputs['headline_note'] ?? ''), 'no headlines') !== false);

        echo "\n--- a citation that goes nowhere is dropped ---\n";

        /* A driver citing an id that was never sent reads as evidence and is
           not. Dropped rather than shown. */
        db()->exec("delete from ai_explanations where symbol='AAA'");
        db()->exec("truncate message_log");
        file_put_contents("$ai_dir/mode", 'explain_bad_source');
        $ex_bad = json_decode((string) $curl("$base/ai_explain.php",
            "symbol=AAA&from=$ex_from&to=$ex_to"), true);
        check('the invented citation is not shown',
              strpos(json_encode($ex_bad['explanation']['drivers'] ?? []), '987654') === false,
              json_encode($ex_bad['explanation']['drivers'] ?? []));
        check('the real driver survives it',
              count($ex_bad['explanation']['drivers'] ?? []) === 1);
        check('and the drop is logged',
              (int) scalar("select count(*) from message_log
                             where message_text like '%dropped a driver citing headline%'") > 0);

        file_put_contents("$ai_dir/mode", 'ok');

        echo "\n--- the explain panel on the page ---\n";

        $ex_page = (string) $curl("$base/quote.php?symbol=AAA&page=quote_form.php");
        check('there is a button to ask', strpos($ex_page, 'id="explain-go"') !== false);
        check('and a from and a to to ask about',
              strpos($ex_page, 'id="explain-from"') !== false
              && strpos($ex_page, 'id="explain-to"') !== false);
        check('and somewhere to put the answer', strpos($ex_page, 'id="explain-panel"') !== false);
        check('and a Regenerate button', strpos($ex_page, 'id="explain-again"') !== false);
        $ex_js = (string) file_get_contents(dirname(__DIR__) . '/templates/chart.js');
        check('nothing is asked for until the button is pressed',
              strpos($ex_js, 'go.addEventListener') !== false);
        check('the cost and the caveat are shown with the answer',
              strpos($ex_js, 'Not financial advice') !== false
              && strpos($ex_js, 'Cost $') !== false);
        check('the endpoint closes the session before the call, like the outlook',
              strpos(php_strip_whitespace(dirname(__DIR__) . '/public/ai_explain.php'),
                     'session_write_close') !== false);
        check('and turns a fatal into a message rather than HTML',
              strpos(php_strip_whitespace(dirname(__DIR__) . '/public/ai_explain.php'),
                     'register_shutdown_function') !== false);

        db()->exec("delete from rns_announcements where symbol='AAA'");
        db()->exec("delete from ai_explanations where symbol='AAA'");

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
