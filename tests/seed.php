<?php

    /**
     * Seeds the test database with a deterministic dataset.
     *
     * 12 symbols on XLON (the exchange the loaders actually write) plus one on
     * LON, so the suite can prove the exchange filtering excludes it. Prices are
     * generated from a fixed formula rather than rand(), so every run produces
     * byte identical data and the assertions can compare exact row counts.
     *
     * Prices run to today because get_statistics.php calculates for "yesterday",
     * and the dashboard only shows data at that as of date.
     */

    require_once("constants.php");

    $pdo = new PDO("mysql:dbname=" . DATABASE . ";host=" . SERVER, USERNAME, PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Start far enough back that momentum has 12 months of history to look at
    // before the first as of date.
    $START_DATE = '2023-01-02';
    $XLON_SYMBOLS = ['AAA','BBB','CCC','DDD','EEE','FFF','GGG','HHH','III','JJJ','KKK','LLL'];
    $LON_SYMBOL   = 'ZZZ';

    $insert_price  = $pdo->prepare("insert into historical_prices (symbol,exchange,date,price) values (?,?,?,?)");
    $insert_symbol = $pdo->prepare("insert into stock_symbols (symbol,name,description,exchange,enabled,sector,market,industry_group,industry,logo) values (?,?,?,?,?,?,?,?,?,?)");

    $universe = [];
    foreach ($XLON_SYMBOLS as $i => $symbol) {
        $universe[] = [$symbol, 'XLON', 50.0 + $i * 17, $i];
    }
    // Same shape, different exchange. Nothing in the suite should ever touch it.
    $universe[] = [$LON_SYMBOL, 'LON', 75.0, 99];

    $pdo->beginTransaction();
    foreach ($universe as [$symbol, $exchange, $base_price, $i]) {

        $insert_symbol->execute([$symbol, $symbol . ' Test PLC', $symbol . ' Test PLC ordinary shares',
                                 $exchange, 'Y', 'Sector ' . ($i % 3),
                                 $exchange === 'XLON' ? 'Main Market' : 'AIM',
                                 'Industry Group ' . ($i % 2), 'Industry ' . ($i % 4), null]);

        $date = new DateTime($START_DATE);
        $end  = new DateTime('today');
        $k = 0;
        while ($date <= $end) {
            // weekdays only, the way a real price feed behaves
            if ((int)$date->format('N') < 6) {
                // Each symbol gets its own phase and drift so the top ten
                // ordering is stable and meaningful rather than arbitrary.
                $price = $base_price * (1 + 0.25 * sin(($k + $i * 30) / 45.0)
                                          + 0.0004 * $k * (1 + $i / 6.0));
                $insert_price->execute([$symbol, $exchange, $date->format('Y-m-d'), round($price, 4)]);
                $k++;
            }
            $date->modify('+1 day');
        }
    }

    // The three momentum indicators get_momentum_statistics.php calculates.
    // screen_function is what indicator_stats() dispatches through.
    $pdo->exec("insert into screen_indicators (name,description,enabled,order_number,screen_function,calc_rank,rank_zero,rank_order) values
        ('3mnth','3mnth','Y',1,'calc_momentum_3mnth','Y','N','value DESC'),
        ('6mnth','6mnth','Y',1,'calc_momentum_6mnth','Y','N','value DESC'),
        ('12mnth','12mnth','Y',1,'calc_momentum_12mnth','Y','N','value DESC')");

    $pdo->exec("insert into indicator_category (category_id,name,description,`order`) values (10,'Quality','Quality',1)");

    // login.php compares crypt($password,'sharemanager') against users.hash.
    $pdo->prepare("insert into users (username,hash,email,cash,default_exchange) values (?,?,?,?,?)")
        ->execute(['tester', crypt('testpass', 'sharemanager'), 'tester@example.com', 10000, 'XLON']);

    // ---------------------------------------------------------------------
    // A portfolio for the logged-in user, so the pages reachable from the nav
    // have something real to render: open and closed positions, dividends,
    // cash movements, a valuation history and a saved screen.
    // ---------------------------------------------------------------------

    $USER_ID = 1;   // the 'tester' row inserted below

    /* A portfolio somebody could actually have: ten holdings, two positions
       closed in full, one sold down in part, and dividends from several of
       them. Three holdings and one sale was enough to prove the queries ran;
       it was not enough to see whether the page reads properly, which is
       what a table of ten rows with mixed gains, mixed trends and a totals
       line is for.

       The costs are set against the seeded closing prices so that some
       holdings show a gain and some a loss - a portfolio where every row is
       green tells you nothing about how the red ones look. */
    $trades = [
        ['AAA', 'BUY',  500, 72.50,  '2025-03-14'],
        ['BBB', 'BUY',  200, 240.00, '2025-04-02'],
        ['CCC', 'BUY',  800, 41.25,  '2025-06-11'],
        ['AAA', 'BUY',  250, 81.10,  '2025-09-05'],   // second lot, averages cost
        ['DDD', 'BUY',  300, 155.00, '2025-02-20'],
        ['DDD', 'SELL', 300, 178.40, '2026-01-16'],   // closed at a profit

        ['EEE', 'BUY',  400, 171.20, '2025-04-18'],
        ['FFF', 'BUY', 1500, 212.40, '2025-05-09'],
        ['FFF', 'SELL', 300, 268.10, '2026-03-05'],   // sold down, still held
        ['GGG', 'BUY',  150, 340.00, '2025-06-27'],
        ['HHH', 'BUY',  600, 298.75, '2025-08-14'],
        ['III', 'BUY',  220, 401.00, '2025-02-07'],
        ['III', 'SELL', 220, 362.80, '2026-05-21'],   // closed at a loss
        ['JJJ', 'BUY',  300, 418.60, '2025-10-02'],   // under water
        ['KKK', 'BUY',  900, 395.10, '2025-11-21'],
        ['LLL', 'BUY',  500, 466.30, '2026-02-13'],   // under water
    ];
    $buy_trade  = $pdo->prepare("insert into purchases (session_id,symbol,trx_type,shares,price_paid,commission,purchase_date) values (?,?,?,?,?,?,?)");
    $log_trade  = $pdo->prepare("insert into history (user_id,trx_type,symbol,quantity,price,timestamp) values (?,?,?,?,?,?)");
    foreach ($trades as [$symbol, $side, $qty, $price, $when]) {
        $buy_trade->execute([$USER_ID, $symbol, $side, $qty, $price, 5.95, $when]);
        $log_trade->execute([$USER_ID, $side, $symbol, $qty, $price, $when . ' 09:15:00']);
    }

    /* Net holdings, the way buy.php maintains them. The average cost of a
       symbol bought twice is weighted by the shares in each lot: AAA is 500
       at 72.50 and 250 at 81.10, which averages 75.37. */
    $holdings = ['AAA' => [750, 75.37],  'BBB' => [200, 240.00], 'CCC' => [800, 41.25],
                 'EEE' => [400, 171.20], 'FFF' => [1200, 212.40], 'GGG' => [150, 340.00],
                 'HHH' => [600, 298.75], 'JJJ' => [300, 418.60], 'KKK' => [900, 395.10],
                 'LLL' => [500, 466.30]];
    $hold = $pdo->prepare("insert into shares (id,symbol,shares,avg_cost,commission,price_paid) values (?,?,?,?,?,?)");
    foreach ($holdings as $symbol => [$qty, $cost]) { $hold->execute([$USER_ID, $symbol, $qty, $cost, 5.95, $cost]); }

    $dividend = $pdo->prepare("insert into dividends (session_id,symbol,dividend_date,amount) values (?,?,?,?)");
    /* Dividends across two tax years, so the strip's "since 6 April" figure
       is a different number from the lot. The UK tax year runs 6 April to 5
       April; a calendar year figure matches nothing on a tax return. */
    foreach ([['AAA','2025-07-18',112.50], ['AAA','2026-01-20',131.25],
              ['BBB','2025-09-30',84.00],  ['CCC','2025-11-14',66.00],
              ['EEE','2026-05-14',96.00],  ['FFF','2026-06-30',210.00],
              ['HHH','2026-07-22',148.50], ['KKK','2026-09-18',172.00],
              ['LLL','2026-10-09',119.25], ['CCC','2026-08-07',72.50]] as $d) {
        $dividend->execute([$USER_ID, $d[0], $d[1], $d[2]]);
    }

    $cash = $pdo->prepare("insert into cash_history (user_id,transaction_date,trx_type,amount) values (?,?,?,?)");
    /* The running cash balance is
         DEPOSIT +amount, BUY -amount, SELL +amount, DELETE -amount, else +amount
       which is the sum every page in the application computes. A BUY row
       therefore holds what the purchase cost as a positive number; stored
       negative it is negated again and a purchase adds cash. The first BUY
       row below was stored negative, so the seeded account's cash was
       GBP72,000 higher than the trades it is made of. */
    foreach ([['2025-03-01','DEPOSIT',100000.00], ['2025-03-14','BUY',36255.95],
              ['2025-07-18','DIVIDEND',112.50],   ['2026-01-16','SELL',53514.05],
              ['2026-02-01','WITHDRAWAL',-10000.00],
              ['2025-04-18','BUY',6848.00],       ['2025-05-09','BUY',31860.00],
              ['2025-06-27','BUY',5100.00],       ['2025-08-14','BUY',17925.00],
              ['2025-10-02','BUY',12558.00],      ['2025-11-21','BUY',35559.00],
              ['2026-02-13','BUY',23315.00],      ['2026-03-05','SELL',8043.00],
              ['2026-05-21','SELL',7981.60],      ['2026-06-01','DEPOSIT',25000.00]] as $c) {
        $cash->execute([$USER_ID, $c[0], $c[1], $c[2]]);
    }

    // A month of daily valuations, so performance.php has a curve rather than a
    // single point. Values are derived from the seeded prices, not invented.
    $snapshot = $pdo->prepare("insert into portfolio_performance
        (session_id,as_of_date,symbol,active,exchange,qty_purchased,qty_sold,price,price_paid,
         price_sold,commission,dividends,value,value_raw,profit,profit_raw,profit_perc)
        values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $price_on = $pdo->prepare("select price from historical_prices where symbol=? and exchange='XLON' and date<=? order by date desc limit 1");
    $day = new DateTime('today'); $day->modify('-30 days');
    while ($day <= new DateTime('today')) {
        $as_of = $day->format('Y-m-d');
        foreach ($holdings as $symbol => [$qty, $cost]) {
            $price_on->execute([$symbol, $as_of]);
            $price = $price_on->fetchColumn();
            if ($price !== false) {
                $value  = ($price * $qty) / 100;
                $profit = ((($price - $cost) * $qty) / 100) - 5.95;
                $snapshot->execute([$USER_ID, $as_of, $symbol, 'Y', 'XLON', $qty, 0, $price, $cost,
                                    null, 5.95, 0, number_format($value, 2), $value,
                                    number_format($profit, 2), $profit,
                                    $cost > 0 ? round($profit / (($cost * $qty) / 100) * 100, 4) : 0]);
            }
        }
        $day->modify('+1 day');
    }

    /* The positions that are closed. get_inactive_positions() reads these
       rows with active='N', so without them the Sold tab is empty however
       many sales are in purchases. One snapshot each, at the latest date,
       because a closed position does not move any more. */
    $closed = ['DDD' => [300, 155.00, 178.40, '2026-01-16'],
               'III' => [220, 401.00, 362.80, '2026-05-21']];
    $latest = $pdo->query("select max(as_of_date) from portfolio_performance")->fetchColumn();
    foreach ($closed as $symbol => [$qty, $cost, $sold_at, $when]) {
        $dividends_on = 0;
        //Realised gain: what it sold for less what it cost, less commission.
        $profit = ((($sold_at - $cost) * $qty) / 100) - 5.95;
        $snapshot->execute([$USER_ID, $latest, $symbol, 'N', 'XLON', $qty, $qty, $sold_at, $cost,
                            $sold_at, 5.95, $dividends_on, number_format(0, 2), 0.0,
                            number_format($profit, 2), $profit,
                            $cost > 0 ? round($profit / (($cost * $qty) / 100) * 100, 4) : 0]);
    }

    // Whole-portfolio daily totals, derived from the per symbol snapshots above so
    // the two agree rather than telling different stories.
    $pdo->exec("insert into performance (session_id,performance_date,total_value,total_profit,total_holding,cash)
                select session_id, as_of_date, sum(value_raw), sum(profit_raw), sum(value_raw), 25000.00
                  from portfolio_performance group by session_id, as_of_date");

    // A saved screen with criteria, for screen_list.php and criteria_list.php.
    $pdo->exec("insert into screen (id,name,description,displayed,session_id)
                values (1,'Quality Momentum','High quality names with positive momentum','Y',$USER_ID)");
    $pdo->exec("insert into screen_criteria (id,indicator_id,description,operator,first_operand,second_operand) values
        (1,1,'3 month momentum above zero','>','3mnth','0'),
        (2,3,'12 month momentum above 10%','>','12mnth','10')");
    $pdo->exec("insert into screen_build (screen_id,criteria_id) values (1,1),(1,2)");

    $pdo->exec("insert into strategy (strategy_id,name,description)
                values (1,'Backing Winners','Ride winners, cut losers on a trailing stop')");
    $pdo->exec("insert into strategy_shares (symbol,status,session_id,strategy_id) values
        ('AAA','ACTIVE',$USER_ID,1), ('BBB','ACTIVE',$USER_ID,1), ('DDD','CLOSED',$USER_ID,1)");

    $pdo->exec("insert into backtest_results (strategy_id,start_date,end_date,sharpe_ratio,avg_daily_return,standard_deviation)
                values (1,'2025-01-01','2026-01-01',0.842000,0.000412,0.011300)");

    // Legacy momentum table, still read by screening.php.
    // Moving averages in a rising order (10 day above 30 day above 100 day), which
    // is the condition get_trend_screen() looks for, so the screen returns names
    // rather than silently matching nothing.
    $pdo->exec("insert into price_momentum (symbol,`3mnth`,`6mnth`,`12mnth`,tendayavg,thirtydayavg,hndrddayavg,earnings_growth)
                select symbol, 4.5, 9.1, 18.7, 112.0, 108.0, 101.0, 12.4
                  from stock_symbols where exchange='XLON'");

    // Financial statements for the quote page.
    $pdo->exec("insert into financial_statement_items (name,description,type,order_number) values
        ('revenue','Revenue','income_statement',1),
        ('operating_income','Operating Income','income_statement',2),
        ('net_income','Net Income','income_statement',3),
        ('total_assets','Total Assets','balance_sheet',1),
        ('total_liabilities','Total Liabilities','balance_sheet',2),
        ('operating_cash_flow','Operating Cash Flow','cash_flow_statement',1),
        ('capital_expenditure','Capital Expenditure','cash_flow_statement',2),
        ('free_cash_flow','Free Cash Flow','cash_flow_statement',3)");
    $pdo->exec("insert into financial_statement_periods (period_id,end_date,period_name) values
        (1,'2023-12-31','FY2023'), (2,'2024-12-31','FY2024'), (3,'2025-12-31','FY2025')");
    $statement = $pdo->prepare("insert into financial_statement_values (symbol,period_id,item_name,value) values (?,?,?,?)");
    $base = ['revenue'=>4200000, 'operating_income'=>780000, 'net_income'=>560000,
             'total_assets'=>9100000, 'total_liabilities'=>3800000,
             'operating_cash_flow'=>690000, 'capital_expenditure'=>-210000,
             'free_cash_flow'=>480000];
    foreach ($XLON_SYMBOLS as $i => $symbol) {
        foreach ([1,2,3] as $period) {
            foreach ($base as $item => $value) {
                $statement->execute([$symbol, $period, $item, $value * (1 + $i * 0.08) * (1 + $period * 0.06)]);
            }
        }
    }

    $pdo->commit();

    $counts = $pdo->query("select exchange, count(distinct symbol) symbols, count(*) prices,
                                  min(date) min_date, max(date) max_date
                           from historical_prices group by exchange")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($counts as $row) {
        printf("  seeded %-5s %2d symbols, %5d prices, %s .. %s\n",
               $row['exchange'], $row['symbols'], $row['prices'], $row['min_date'], $row['max_date']);
    }

?>
