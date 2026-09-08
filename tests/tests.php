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

    printf("\n%s  %d passed, %d failed\n\n",
           $failed === 0 ? "\033[32mALL PASSED\033[0m" : "\033[31mFAILURES\033[0m", $passed, $failed);
    if ($failed > 0) { echo "  failed: " . implode("\n          ", $failures) . "\n\n"; }
    exit($failed === 0 ? 0 : 1);

?>
