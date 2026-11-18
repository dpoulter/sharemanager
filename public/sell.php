<?php

    /**
     * Sell shares.
     *
     * Writes one row to purchases and one to cash_history, like buy.php.
     * It used to decrement a shares table and add to users.cash, neither of
     * which the Portfolio page reads, so selling changed nothing visible.
     *
     * How many are held comes from purchases - buys less sells - rather than
     * from a running total somebody has to keep in step.
     */

    require("../includes/config.php");
    require_once("../includes/portfolio_functions.php");

    if ($_SERVER["REQUEST_METHOD"] != "POST")
    {
        $portfolio = [];
        foreach (portfolio_held_symbols($_SESSION["id"]) as $row) {
            $stock = lookup($row["symbol"]);
            $portfolio[] = [
                "name"   => $stock === false ? $row["symbol"] : $stock["name"],
                "price"  => $stock === false ? null : $stock["price"],
                "shares" => (int) $row["shares"],
                "symbol" => $row["symbol"],
            ];
        }

        if (!count($portfolio)) { apologize("You dont have any stocks yet."); }

        render("sell_form.php", ["title" => "Sell Shares", "portfolio" => $portfolio]);
        exit;
    }

    if (empty($_POST["symbol"]) || !is_string($_POST["symbol"]))
    {
        apologize("You haven't selected a stock to sell.");
    }

    if (!preg_match("/^\d+$/", (string) ($_POST["qty"] ?? '')) || (int) $_POST["qty"] < 1)
    {
        apologize("Quantity must be a whole number greater than 0.");
    }

    /* The date, if one was given. A sale with no date is today's: the Sell
       form has never had a date field, and refusing it would stop the page
       working rather than make it more correct. */
    $sale_date = date('Y-m-d');
    if (isset($_POST["sellDate"]) && is_string($_POST["sellDate"])
        && preg_match("~^(\d{4})-(\d{2})-(\d{2})$~", $_POST["sellDate"], $parts)) {
        if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            apologize("Please enter a valid date.");
        }
        $sale_date = $_POST["sellDate"];
    }

    $commission = (isset($_POST["commission"]) && is_numeric($_POST["commission"]))
                ? round((float) $_POST["commission"], 4) : 0.0;
    if ($commission < 0) { apologize("Commission cannot be negative."); }

    $shares = (int) $_POST["qty"];
    $held   = portfolio_held($_SESSION["id"], $_POST["symbol"]);

    if ($held['shares'] < 1)
    {
        apologize("You do not have any shares of " . htmlspecialchars($_POST["symbol"]) . ".");
    }

    if ($shares > $held['shares'])
    {
        apologize("You cannot sell more than " . fmt_quantity($held['shares']) . " shares.");
    }

    $quote = lookup($_POST["symbol"]);
    if ($quote === false) { apologize("Invalid Symbol: " . htmlspecialchars($_POST["symbol"])); }

    portfolio_record_trade($_SESSION["id"], 'SELL', $quote["symbol"], $shares,
                           (float) $quote["price"], $commission, $sale_date);

    write_log("sell", "sold " . $shares . " " . $quote["symbol"] . " at "
                      . $quote["price"] . "p on " . $sale_date);

    /* Value the account now, so the purchase is in Holdings when the page
       loads rather than after tonight's valuation job. */
    portfolio_revalue($_SESSION["id"]);

    redirect("performance.php");

?>
