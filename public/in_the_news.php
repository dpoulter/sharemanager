<?php

    /**
     * "In the news": every scored regulatory announcement across the
     * universe for the last week or month, with what the company said and
     * what the share did since.
     *
     *   in_the_news.php?days=7&type=results&tone=negative&market=FTSE+250&sort=sentiment
     *
     * config.php applies the same authentication gate as every other page
     * here, which matters more on this one than on most: announcement text
     * is Investegate's, kept under terms that allow a private investor to
     * read it and forbid redistribution. Behind the login it is somebody
     * reading their own research; served to the world it would be
     * republishing. There is no second copy of the gate here to drift out of
     * step with the first.
     *
     * Every input is read into one of a fixed set of values, and the two
     * that are not - the type and the market - are bound parameters matched
     * against what is actually in the database. The sort is one of three
     * named orders rather than a column name off the query string.
     */

    require("../includes/config.php");
    require_once("../includes/rns.php");

    /* Seven trading days, or thirty. Not a free number: a range somebody can
       type is a range somebody can type 100000 into, and this page reads
       every announcement in it. */
    $days = (isset($_GET['days']) && (int) $_GET['days'] === 30) ? 30 : 7;

    $as_of = last_trading_date(session_exchange());
    if ($as_of === null) { $as_of = date('Y-m-d'); }

    /* Trading days, not calendar days: "the last 7 trading days" over a bank
       holiday weekend is ten days of announcements, and a company that
       reported on the Friday before should still be on the page. */
    $dates = query("select distinct date from historical_prices
                     where exchange=? and date<=? order by date desc limit " . ($days + 1),
                   session_exchange(), $as_of);

    $from = count($dates) ? (string) end($dates)['date']
                          : date('Y-m-d', strtotime($as_of . ' -' . $days . ' day'));

    $sort   = in_array($_GET['sort'] ?? '', ['date','sentiment','symbol'], true)
            ? $_GET['sort'] : 'date';
    $tone   = in_array($_GET['tone'] ?? '', ['positive','negative'], true)
            ? $_GET['tone'] : '';
    $type   = isset($_GET['type'])   && is_string($_GET['type'])   ? trim($_GET['type'])   : '';
    $market = isset($_GET['market']) && is_string($_GET['market']) ? trim($_GET['market']) : '';

    $rows = rns_news_page($from, $as_of,
                          ["type" => $type, "market" => $market, "tone" => $tone],
                          $sort);

    /* The price move is one query per row rather than part of the listing
       query: the close before an announcement depends on what time of day it
       was made, which is a rule in PHP (see rns_baseline_date()) and not
       something a join can express. A week of announcements is tens of rows,
       not thousands.

       Sorting by the move therefore happens here, once the moves are known. */
    foreach ($rows as $i => $row) {
        $rows[$i]['price_move'] = rns_price_move($row['symbol'], $row['published_at'],
                                                 $as_of, session_exchange());
        $rows[$i]['figures']    = rns_key_figures($row['key_figures']);
    }

    if (($_GET['sort'] ?? '') === 'move') {
        $sort = 'move';
        usort($rows, function ($a, $b) {
            /* A share with no usable baseline has an unknown move, not a
               flat one, so it sorts to the end either way rather than
               claiming the middle. */
            if ($a['price_move'] === null && $b['price_move'] === null) { return 0; }
            if ($a['price_move'] === null) { return 1; }
            if ($b['price_move'] === null) { return -1; }
            return $a['price_move'] <=> $b['price_move'];
        });
    }

    render("in_the_news.php", [
        "title"       => "In the news",
        "rows"        => $rows,
        "movers"      => rns_movers($from, $as_of),
        "types"       => rns_types_present($from, $as_of),
        "markets"     => rns_markets(),
        "days"        => $days,
        "from"        => $from,
        "as_of"       => $as_of,
        "sort"        => $sort,
        "tone"        => $tone,
        "type"        => $type,
        "market"      => $market,
    ]);
