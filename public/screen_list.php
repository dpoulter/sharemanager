<?php

    /**
     * The screens, and the result of running one.
     *
     *   screen_list.php                 the list
     *   screen_list.php?screen=1&run=1  run that one
     *
     * The list was a radio button per screen with View/Edit and Run buttons
     * underneath, so picking a screen and saying what to do with it were two
     * decisions in two places and neither was on the row you were looking
     * at. Each row carries its own Run and Edit now.
     *
     * A screen whose criteria the builder cannot use is not offered a Run
     * button at all: it does not return no shares, it returns every share or
     * none depending on which rule is missing, and either way the reader
     * would be looking at an answer to a question nobody asked.
     *
     * The screen id is cast to an integer and looked up; nothing from the
     * request reaches a query as text.
     */

    require("../includes/config.php");
    require("../includes/screen_view.php");

    /* POST is still accepted so the old buttons keep working for anything
       that has them bookmarked, but the page's own links are GETs: running a
       screen reads and writes nothing but the run stamp, and a result worth
       looking at is a result worth linking to. */
    $screen_id = 0;
    foreach ([$_POST, $_GET] as $source) {
        if (isset($source["screen"]) && is_numeric($source["screen"])) {
            $screen_id = (int) $source["screen"];
        }
    }

    $wants_edit = isset($_POST["btnEdit"]) || isset($_GET["edit"]);
    $wants_run  = isset($_POST["btnRun"])  || isset($_GET["run"]);

    if ($screen_id > 0 && ($wants_edit || $wants_run)) {

        $screen = query("select name, description from screen where id=? and displayed='Y'",
                        $screen_id);
        if (!count($screen)) { apologize("That is not a screen on this account."); }

        if ($wants_edit) {
            $criteria = query("select sc.id, sc.description
                                 from screen_build sb, screen_criteria sc
                                where sb.criteria_id=sc.id and sb.screen_id=?", $screen_id);
            render("screen_criteria.php",
                   ["screen_id" => $screen_id, "screen" => $screen[0]["name"],
                    "description" => $screen[0]["description"], "criterias" => $criteria]);
            exit;
        }

        /* Refuse to run one that cannot be run, rather than running it and
           showing whatever falls out. */
        $problems = screen_problems($screen_id);
        if (count($problems)) {
            render("screen.php", [
                "title"      => $screen[0]["name"],
                "screen_id"  => $screen_id,
                "screen"     => $screen[0],
                "as_of"      => screen_asof(),
                "rank_words" => null,
                "problems"   => $problems,
                "columns"    => [],
                "extras"     => [],
                "rows"       => [],
            ]);
            exit;
        }

        $as_of   = screen_asof();
        $symbols = build_screen($screen_id, null);
        $columns = screen_indicator_columns($screen_id);

        screen_mark_run($screen_id);

        render("screen.php", [
            "title"      => $screen[0]["name"],
            "screen_id"  => $screen_id,
            "screen"     => $screen[0],
            "as_of"      => $as_of,
            "rank_words" => screen_rank_words($screen_id),
            "problems"   => [],
            "columns"    => $columns,
            "extras"     => screen_extras_wanted($columns),
            "rows"       => screen_result_rows($symbols, $columns, $as_of),
        ]);
        exit;
    }

    render("screens.php", ["title" => "Screens", "screens" => screen_list_rows()]);

?>
