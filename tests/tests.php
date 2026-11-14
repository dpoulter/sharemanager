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

    echo "\n--- pages render without a fatal ---\n";

    /* Drives the site over HTTP as a logged in user. Everything above tests the
       jobs and the queries; this is the only thing that catches a page which
       parses fine and still dies on the way to the browser - a missing include,
       a column nothing writes, an undefined function. */
    $docroot = dirname(__DIR__) . '/public';
    $port = 8099;
    $include_path = __DIR__ . '/fixtures' . PATH_SEPARATOR . dirname(__DIR__) . '/includes';
    $server = 'EODHD_BASE_URL=http://127.0.0.1:' . ($port + 10)
            . ' EODHD_API_KEY=test-stub-token'
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

    printf("\n%s  %d passed, %d failed\n\n",
           $failed === 0 ? "\033[32mALL PASSED\033[0m" : "\033[31mFAILURES\033[0m", $passed, $failed);
    if ($failed > 0) { echo "  failed: " . implode("\n          ", $failures) . "\n\n"; }
    exit($failed === 0 ? 0 : 1);

?>
