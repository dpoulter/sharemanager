<?php

    /**
     * Buy shares.
     *
     * Writes one row to purchases and one to cash_history, which is what the
     * Portfolio page, the dashboard and the nightly valuation read. It used
     * to write shares, history and users.cash instead - a different model
     * entirely - so a purchase made here never appeared in the portfolio it
     * was made in.
     *
     * Prices are pence throughout, as purchases stores them. The commission
     * is pounds, because it is cash.
     */

    require("../includes/config.php");
    require_once("../includes/portfolio_functions.php");

    if ($_SERVER["REQUEST_METHOD"] != "POST")
    {
        render("buy_form.php", ["title" => "Buy Shares"]);
        exit;
    }

    if (empty($_POST["symbol"]) || !is_string($_POST["symbol"]))
    {
        apologize("You haven't selected a stock to buy.");
    }

    //A whole number of shares, greater than nought.
    if (!preg_match("/^\d+$/", (string) ($_POST["qty"] ?? '')) || (int) $_POST["qty"] < 1)
    {
        apologize("Quantity must be a whole number greater than 0.");
    }

    /* Either form of date.
     *
     * The form has always been <input type="date">, and that control submits
     * YYYY-MM-DD - which this refused, because it insisted on DD-MM-YYYY. So
     * the Buy page could not buy anything: every submission from its own form
     * was turned away by its own validation. Both are accepted now, and both
     * are checked - with the arguments to checkdate() the right way round,
     * which they were not either.
     */
    $raw_date = (string) ($_POST["buyDate"] ?? '');

    if (preg_match("~^(\d{4})-(\d{2})-(\d{2})$~", $raw_date, $parts)) {
        [, $year, $month, $day] = $parts;
    }
    else if (preg_match("~^(\d{2})-(\d{2})-(\d{4})$~", $raw_date, $parts)) {
        [, $day, $month, $year] = $parts;
    }
    else {
        apologize("Date must be DD-MM-YYYY or YYYY-MM-DD.");
    }

    if (!checkdate((int) $month, (int) $day, (int) $year)) {
        apologize("Please enter a valid date.");
    }

    $purchase_date = sprintf('%04d-%02d-%02d', $year, $month, $day);

    /* Dealing costs. Optional, because the form did not have the field
       before and a bookmarked post will not carry it; nought is an honest
       default and the figure is visible on the Transactions page. */
    $commission = (isset($_POST["commission"]) && is_numeric($_POST["commission"]))
                ? round((float) $_POST["commission"], 4) : 0.0;
    if ($commission < 0) { apologize("Commission cannot be negative."); }

    $quote = lookup($_POST["symbol"]);
    if ($quote === false) { apologize("Invalid Symbol: " . htmlspecialchars($_POST["symbol"])); }

    $shares = (int) $_POST["qty"];
    $price  = (float) $quote["price"];

    //Pence times shares is pence; what leaves the account is pounds.
    $cost = ($price * $shares / 100) + $commission;

    /* Against the cash book, which is what every page means by cash.
       users.cash was a second balance that only this page and sell.php
       maintained, and it disagreed with the book from the first deposit. */
    $cash = portfolio_cash_balance($_SESSION["id"]);

    if ($cash + 0.005 < $cost) {
        apologize("You do not have enough cash available: this costs "
                  . fmt_pounds($cost) . " and you have " . fmt_pounds($cash) . ".");
    }

    portfolio_record_trade($_SESSION["id"], 'BUY', $quote["symbol"], $shares,
                           $price, $commission, $purchase_date);

    write_log("buy", "bought " . $shares . " " . $quote["symbol"] . " at " . $price
                     . "p on " . $purchase_date);

    /* Value the account now, so the purchase is in Holdings when the page
       loads rather than after tonight's valuation job. */
    portfolio_revalue($_SESSION["id"]);

    redirect("performance.php");

?>
