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
    require_once("../includes/news_view.php");
    require_once("../includes/home_functions.php");

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

    $sort   = in_array($_GET['sort'] ?? '', ['date','sentiment','symbol','move'], true)
            ? $_GET['sort'] : 'date';

    /* Routine filings - type "other" and neutral in tone - are the long tail
       of this page and they were mixed in with the results and the
       warnings. They are folded away rather than left out, in a <details>
       the browser opens: no round trip, and the count on the summary says
       what is behind it. */
    $tone   = in_array($_GET['tone'] ?? '', ['positive','negative'], true)
            ? $_GET['tone'] : '';
    $type   = isset($_GET['type'])   && is_string($_GET['type'])   ? trim($_GET['type'])   : '';
    $market = isset($_GET['market']) && is_string($_GET['market']) ? trim($_GET['market']) : '';

    $rows = rns_news_page($from, $as_of,
                          ["type" => $type, "market" => $market, "tone" => $tone],
                          $sort);

    /* The price move comes off the row. It used to be worked out here, once
       per row: the close before an announcement depends on what time of day
       it was made, which is a rule in PHP rather than something a join can
       express (see rns_baseline_date()), so it was four queries a row. With
       three thousand announcements stored that made this page take 27
       seconds.

       The rule has not moved - rns_refresh_price_moves() still applies it,
       in PHP, once a night for the window this page shows. What changed is
       that the page reads the answer instead of working it out while
       somebody waits. Sorting by the move is the database's job now too.

       A row whose move has not been worked out yet shows no move rather
       than a zero: the figure arrives on the next nightly run. */
    foreach ($rows as $i => $row) {
        $rows[$i]['price_move'] = $row['price_move'] === null
                                ? null : (float) $row['price_move'];
        $rows[$i]['figures']    = rns_key_figures($row['key_figures']);
    }

    $routine_rows = [];
    $main_rows    = [];
    foreach ($rows as $row) {
        if (news_is_routine($row)) { $routine_rows[] = $row; }
        else                       { $main_rows[] = $row; }
    }

    /* One entry per company per day, on each side. A company that files
       three times in a morning was three rows of the same company. */
    $groups         = news_group_by_day($main_rows);
    $routine_groups = news_group_by_day($routine_rows);

    /* The best and worst boxes carry what the share has done since, as well
       as the score. The same function the dashboard uses, so the two pages
       cannot rank the week differently. */
    $movers = home_news_week($from, $as_of, 5);

    render("in_the_news.php", [
        "title"       => "In the news",
        "rows"           => $rows,
        "groups"         => $groups,
        "routine_groups" => $routine_groups,
        "routine_count"  => count($routine_rows),
        "movers"      => $movers,
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
