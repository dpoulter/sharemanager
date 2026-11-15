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
    $resolved   = scalar("select date(max(job_date)) from jobs where job_name='get_statistics_asof'");
    $score_date = scalar("select max(date) from statistics where indicator='momentum_score'");
    check('the as of marker matches the date the scores were written at',
          $resolved !== false && $resolved === $score_date, "resolved=$resolved scores=$score_date");
    $wrong = scalar("select date(max(job_date)) from jobs where job_name='get_statistics'");
    check('resolving the run row instead would miss the scores (regression guard)', $wrong !== $score_date);

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

    $asof = date('Y-m-d', strtotime('-1 day'));
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
    $worst_positive = min(array_filter([$pct['CCC'], $pct['DDD'], $pct['EEE']],
                                       fn($p) => $p !== null));
    $negatives_above = array_filter(['AAA' => $pct['AAA'], 'BBB' => $pct['BBB']],
                                    fn($p) => $p !== null && $p > $worst_positive);
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

    $asof2 = date('Y-m-d', strtotime('-1 day'));
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
    $trend_asof = date('Y-m-d', strtotime('-1 day'));   // the date the job uses

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
    check('it refuses to discard uncommitted work on the server',
          strpos($deploy_src, 'git status --porcelain') !== false);
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
