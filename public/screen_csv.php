<?php

    /**
     * A screen's results as a CSV file.
     *
     *   screen_csv.php?screen=1
     *
     * The same rows, in the same order, with the same figures as the table
     * on screen_list.php - built from the same two functions, so the file
     * and the page cannot drift apart. A screen's output is something
     * somebody wants to keep, and copying it out of a web page by hand is
     * not keeping it.
     *
     * config.php applies the same authentication gate as every other page
     * here: this script is not on its allow list, so an anonymous request is
     * redirected to login.php.
     *
     * Numbers are written unformatted - no thousands separators, no "p", no
     * percentage sign - because this is a file something else will read. The
     * page is where a number is dressed for a person.
     */

    require("../includes/config.php");
    require("../includes/screen_view.php");

    $screen_id = (isset($_GET["screen"]) && is_numeric($_GET["screen"]))
               ? (int) $_GET["screen"] : 0;

    $screen = $screen_id > 0
            ? query("select name, description from screen where id=? and displayed='Y'", $screen_id)
            : [];

    if (!count($screen)) { apologize("That is not a screen on this account."); }

    if (count(screen_problems($screen_id))) {
        apologize("That screen has no criteria the screen builder can use, so it has no results.");
    }

    $as_of   = screen_asof();
    $columns = screen_indicator_columns($screen_id);
    $rows    = screen_result_rows(build_screen($screen_id, null), $columns, $as_of);

    /* A filename built from the screen's name, with everything that is not a
       letter or a digit removed. A name arrives from the database and a
       Content-Disposition header is a header: a newline in it would be a
       second header. */
    $slug = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $screen[0]['name']);
    $slug = trim($slug, '-');
    if ($slug === '') { $slug = 'screen'; }

    header("Content-Type: text/csv; charset=utf-8");
    header("X-Content-Type-Options: nosniff");
    header("Content-Disposition: attachment; filename=\"" . $slug . "-" . $as_of . ".csv\"");

    $out = fopen('php://output', 'w');

    /* The same columns the table has, including the same rule about not
       repeating a figure the screen already has a column for. */
    $extras = screen_extras_wanted($columns);
    /* A heading for every extra the page shows, so adding one to
       screen_extra_columns() cannot leave the file a column short of its
       own data - which is what happened the day ShareRank was added. */
    $titles = ['close_price' => 'Price (pence)', '6mnth' => '6 month change (%)',
               'momentum_score' => 'Momentum', 'value_score' => 'Value',
               'quality_score' => 'Quality', 'overall_score' => 'ShareRank'];

    $heading = ['Rank', 'Symbol', 'Company'];
    foreach ($columns as $column) { $heading[] = $column['description']; }
    foreach ($extras as $indicator => $key) {
        $heading[] = isset($titles[$indicator]) ? $titles[$indicator] : $indicator;
    }
    fputcsv($out, $heading);

    foreach ($rows as $i => $row) {
        $line = [$i + 1, $row['symbol'], $row['name']];
        foreach ($columns as $column) { $line[] = $row['values'][$column['name']]; }
        foreach ($extras as $indicator => $key) { $line[] = $row[$key]; }
        fputcsv($out, $line);
    }

    fclose($out);

?>
