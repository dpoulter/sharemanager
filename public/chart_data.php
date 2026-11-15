<?php

    /**
     * The price series, its two moving averages and the news markers, as
     * JSON, for the chart on the quote page.
     *
     *   chart_data.php?symbol=RR&from=2026-05-01&to=2026-11-13
     *
     * config.php applies the same authentication gate as every other page
     * here: this script is not on its allow list, so an anonymous request is
     * redirected to login.php and never reaches the query. The share universe
     * and what has been written about it are behind the login like everything
     * else, and there is no second copy of the gate here to drift out of step
     * with it.
     *
     * Everything that reaches the database is a bound parameter. Nothing is
     * escaped here on the way out either: this is JSON, json_encode() does
     * the escaping, and the page that draws it uses textContent rather than
     * innerHTML - a headline is somebody else's text and is treated as such
     * at both ends.
     */

    require("../includes/config.php");
    require_once("../includes/news.php");

    header("Content-Type: application/json");
    header("X-Content-Type-Options: nosniff");

    function chart_fail($message, $status = 400) {
        http_response_code($status);
        print(json_encode(["ok" => false, "error" => $message]));
        exit;
    }

    $symbol = (isset($_GET["symbol"]) && is_string($_GET["symbol"]))
            ? strtoupper(trim($_GET["symbol"])) : "";

    if ($symbol === "" || !preg_match('/^[A-Z0-9.\-]{1,50}$/', $symbol)) {
        chart_fail("No usable symbol was asked for.");
    }

    /* A date or nothing. Anything else is refused rather than interpreted:
       strtotime() would read "yesterday" and "+1 month", and a chart whose
       range depends on when it was loaded is not a chart of anything. */
    $a_date = function ($name, $fallback) {
        $raw = (isset($_GET[$name]) && is_string($_GET[$name])) ? trim($_GET[$name]) : "";
        if ($raw === "") { return $fallback; }
        $parsed = DateTime::createFromFormat('Y-m-d', $raw);
        if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
            chart_fail("'" . $name . "' is not a date in YYYY-MM-DD form.");
        }
        return $raw;
    };

    $to   = $a_date("to", date('Y-m-d'));
    $from = $a_date("from", date('Y-m-d', strtotime($to . ' -1 year')));

    if ($from > $to) { chart_fail("The range starts after it ends."); }

    $rows = query("select symbol, name from stock_symbols where symbol=? and exchange=?",
                  $symbol, session_exchange());
    if (!count($rows)) { chart_fail("There is no such share on this exchange.", 404); }
    $name = (string) $rows[0]['name'];

    /* The price series. Closes only: historical_prices stores one price a
       day, so a candle would be four copies of the same number dressed up as
       information. */
    $prices = query("select date, price from historical_prices
                      where symbol=? and exchange=? and date between ? and ?
                      order by date asc", $symbol, session_exchange(), $from, $to);

    $series = [];
    foreach ($prices as $row) {
        $series[] = ["time" => (string) $row['date'], "value" => (float) $row['price']];
    }

    /* The averages as this application calculates them, from statistics,
       rather than recomputed here: the chart should show the same number the
       screens select on. One row per day, so a day with no statistic simply
       has no point and the line breaks, which is honest. */
    $line = function ($indicator) use ($symbol, $from, $to) {
        $out = [];
        foreach (query("select date, value from statistics
                         where symbol=? and exchange=? and indicator=?
                           and date between ? and ? and value is not null
                         order by date asc",
                       $symbol, session_exchange(), $indicator, $from, $to) as $row) {
            $out[] = ["time" => (string) $row['date'], "value" => (float) $row['value']];
        }
        return $out;
    };

    /* The markers: one per day with news, whatever the day held. Grouped
       here rather than in the browser so the page gets what it draws. */
    $days = [];
    foreach (news_in_range($symbol, $from, $to) as $article) {

        $day = (string) $article['published'];

        if (!isset($days[$day])) {
            $days[$day] = ["date" => $day, "count" => 0, "sentiment" => null,
                           "impact_types" => [], "articles" => []];
        }

        $days[$day]["count"]++;

        if ($article['sentiment'] !== null) {
            $days[$day]["articles_scored"] = ($days[$day]["articles_scored"] ?? 0) + 1;
            $days[$day]["sentiment_total"] = ($days[$day]["sentiment_total"] ?? 0)
                                           + (float) $article['sentiment'];
        }

        $impact = (string) $article['impact_type'];
        if ($impact !== '' && !in_array($impact, $days[$day]["impact_types"], true)) {
            $days[$day]["impact_types"][] = $impact;
        }

        $days[$day]["articles"][] = [
            "id"          => (int) $article['id'],
            "title"       => (string) $article['title'],
            "link"        => (string) $article['link'],
            "publisher"   => (string) $article['publisher'],
            "sentiment"   => $article['sentiment'] === null ? null : (float) $article['sentiment'],
            "impact_type" => $impact,
            "summary"     => (string) $article['summary'],
            "bull_point"  => (string) $article['bull_point'],
            "bear_point"  => (string) $article['bear_point'],
            "scored"      => $article['scored_at'] !== null,
        ];
    }

    $markers = [];
    foreach ($days as $day) {
        $scored = $day["articles_scored"] ?? 0;
        $day["sentiment"] = $scored > 0
                          ? round(($day["sentiment_total"] ?? 0) / $scored, 4) : null;
        unset($day["articles_scored"], $day["sentiment_total"]);
        $markers[] = $day;
    }

    print(json_encode([
        "ok"      => true,
        "symbol"  => $symbol,
        "name"    => $name,
        "from"    => $from,
        "to"      => $to,
        "prices"  => $series,
        "ma50"    => $line('ma50'),
        "ma200"   => $line('ma200'),
        "markers" => $markers,
    ]));

    /* No closing tag: a newline after one is written to the front of the
       response, which would make this invalid JSON. See includes/constants.php. */
