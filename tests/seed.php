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
    $insert_symbol = $pdo->prepare("insert into stock_symbols (symbol,name,exchange,enabled,sector,industry) values (?,?,?,?,?,?)");

    $universe = [];
    foreach ($XLON_SYMBOLS as $i => $symbol) {
        $universe[] = [$symbol, 'XLON', 50.0 + $i * 17, $i];
    }
    // Same shape, different exchange. Nothing in the suite should ever touch it.
    $universe[] = [$LON_SYMBOL, 'LON', 75.0, 99];

    $pdo->beginTransaction();
    foreach ($universe as [$symbol, $exchange, $base_price, $i]) {

        $insert_symbol->execute([$symbol, $symbol . ' Test PLC', $exchange, 'Y',
                                 'Sector ' . ($i % 3), 'Industry ' . ($i % 4)]);

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

    $pdo->commit();

    $counts = $pdo->query("select exchange, count(distinct symbol) symbols, count(*) prices,
                                  min(date) min_date, max(date) max_date
                           from historical_prices group by exchange")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($counts as $row) {
        printf("  seeded %-5s %2d symbols, %5d prices, %s .. %s\n",
               $row['exchange'], $row['symbols'], $row['prices'], $row['min_date'], $row['max_date']);
    }

?>
