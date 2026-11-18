<!doctype html>
<?php $sm_theme = function_exists("view_theme") ? view_theme() : 'system'; ?>
<html lang="en"<?= $sm_theme === 'system' ? '' : ' data-bs-theme="' . htmlspecialchars($sm_theme) . '"' ?>>
<?php
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.

/* Which navigation entry to light up. A page is one of five things as far as
   the navbar is concerned, and "Portfolio" covers the transaction and cash
   pages because that is where the reader went looking for them. */
$sm_here = basename($_SERVER["SCRIPT_NAME"] ?? "");
$sm_section = "";
if ($sm_here === "index.php") { $sm_section = "home"; }
else if (in_array($sm_here, ["performance.php", "edit.php", "dividends.php",
                             "topup.php", "cash_history.php", "buy.php",
                             "sell.php"], true)) { $sm_section = "portfolio"; }
else if (in_array($sm_here, ["screen_list.php", "criteria.php", "criteria_list.php",
                             "screen_criteria.php", "statistics.php",
                             "statistics_form.php", "sector_companies.php",
                             "download_prices.php"], true)) { $sm_section = "screens"; }
else if ($sm_here === "in_the_news.php")  { $sm_section = "news"; }
else if ($sm_here === "strategies.php")   { $sm_section = "strategies"; }
else if ($sm_here === "broker.php")       { $sm_section = "broker"; }

/* How old the figures on this page are. Printed on every page, because a
   reader cannot tell a stale price from a falling one. */
$sm_fresh = function_exists("view_freshness") ? view_freshness() : null;
$sm_fresh_class = ["fresh" => "sm-fresh", "amber" => "sm-fresh-amber",
                   "red" => "sm-fresh-red"][$sm_fresh["level"] ?? "fresh"] ?? "sm-fresh";
$sm_user = function_exists("view_username") ? view_username() : "";
?>

    <head>

        <!-- Required meta tags -->
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

        <?php if ($sm_theme === 'system'): ?>
        <!-- Following the machine. app.css does this on its own through
             color-scheme and light-dark(); Bootstrap 5.3 needs the attribute,
             so it is set here - before the stylesheets, so the page is never
             painted in the wrong one and corrected afterwards. With
             JavaScript off this does nothing and Bootstrap stays light while
             the application's own colours follow the system, which is the
             right way round for it to degrade. -->
        <script>
          (function () {
            var dark = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)");
            function apply(on) {
              document.documentElement.setAttribute("data-bs-theme", on ? "dark" : "light");
            }
            if (!dark) { return; }
            apply(dark.matches);
            //The system setting can change while the page is open.
            if (dark.addEventListener) { dark.addEventListener("change", function (e) { apply(e.matches); }); }
          }());
        </script>
        <?php endif ?>

        <!-- One Bootstrap stylesheet: version 5, matching the bundle below.
             bootstrap.css and bootstrap-theme.min.css used to be loaded here
             too. The theme is Bootstrap 3 and does not exist for 5, and the
             unminified copy was the same framework a second time. -->
        <link rel="stylesheet" href="<?= asset_url('/css/bootstrap.min.css') ?>">
        <!-- styles1.css was loaded here. On the server it is the placeholder
             comment tools/fetch_assets.sh writes, not a stylesheet: there is
             no real one anywhere, so every page was fetching an empty file. -->
        <link rel="stylesheet" href="<?= asset_url('/css/fontawesome.min.css') ?>">
        <!-- typeahead.js ships no stylesheet, so the navbar suggestion list has
             none unless this is loaded. See templates/typeahead.css. -->
        <link rel="stylesheet" href="<?= asset_url('/css/typeahead.css') ?>">
        <!-- This application's own stylesheet, last so it decides. Built from
             templates/app.css by tools/fetch_assets.sh. -->
        <link rel="stylesheet" href="<?= asset_url('/css/app.css') ?>">

        <script src="<?= asset_url('/js/jquery-3.3.1.min.js') ?>"></script>
        <script src="<?= asset_url('/js/bootstrap.bundle.min.js') ?>"></script>
        <script src="<?= asset_url('/js/bloodhound.min.js') ?>"></script>
        <script src="<?= asset_url('/js/typeahead.jquery.js') ?>"></script>
        <script src="<?= asset_url('/js/scripts.js') ?>"></script>

        <?php if (isset($title)): ?>
            <title>Share Portfolio Manager <?= htmlspecialchars($title) ?></title>
        <?php else: ?>
            <title>Share Portfolio Manager</title>
        <?php endif ?>

</head>

    <body>

<nav class="navbar navbar-expand-lg fixed-top navbar-dark bg-primary">
  <div class="container-fluid">
  <a class="navbar-brand" href="index.php">Share Manager</a>
  <!-- Without a toggler the collapse above never opens, so on a phone the whole
       navigation was simply invisible. -->
  <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
          data-bs-target="#navbarNavDropdown" aria-controls="navbarNavDropdown"
          aria-expanded="false" aria-label="Toggle navigation">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="navbarNavDropdown">
    <ul class="navbar-nav me-auto">
      <li class="nav-item">
        <a class="nav-link<?= $sm_section === 'home' ? ' active' : '' ?>"
           <?= $sm_section === 'home' ? 'aria-current="page"' : '' ?>
           href="index.php">Home</a>
      </li>
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle<?= $sm_section === 'portfolio' ? ' active' : '' ?>"
           <?= $sm_section === 'portfolio' ? 'aria-current="page"' : '' ?>
           href="#" id="navbarDropdownMenuLink" role="button" data-bs-toggle="dropdown"
           aria-haspopup="true" aria-expanded="false">
          Portfolio
        </a>
        <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
          <a class="dropdown-item" href="performance.php">Overview</a>
          <a class="dropdown-item" href="edit.php">Transactions</a>
          <a class="dropdown-item" href="dividends.php">Dividends</a>
          <a class="dropdown-item" href="topup.php">Deposit Cash</a>
          <a class="dropdown-item" href="cash_history.php">Cash History</a>
        </div>
      </li>
      <li class="nav-item">
        <a class="nav-link<?= $sm_section === 'screens' ? ' active' : '' ?>"
           <?= $sm_section === 'screens' ? 'aria-current="page"' : '' ?>
           href="screen_list.php">Screens</a>
      </li>
      <li class="nav-item">
        <a class="nav-link<?= $sm_section === 'news' ? ' active' : '' ?>"
           <?= $sm_section === 'news' ? 'aria-current="page"' : '' ?>
           href="in_the_news.php">In the news</a>
      </li>
      <li class="nav-item">
        <a class="nav-link<?= $sm_section === 'strategies' ? ' active' : '' ?>"
           <?= $sm_section === 'strategies' ? 'aria-current="page"' : '' ?>
           href="strategies.php">Strategies</a>
      </li>
      <!-- The broker connection, read only. Its own entry rather than one
           under Portfolio: the Portfolio pages are the transactions entered
           by hand, and the point of this one is that it is somebody else's
           record of the same thing. -->
      <li class="nav-item">
        <a class="nav-link<?= $sm_section === 'broker' ? ' active' : '' ?>"
           <?= $sm_section === 'broker' ? 'aria-current="page"' : '' ?>
           href="broker.php">Broker</a>
      </li>
    </ul>

  <!-- How old the prices are, on every page. Amber once the latest close is a
       trading day behind, red at three. -->
  <?php if ($sm_fresh !== null): ?>
  <span class="<?= $sm_fresh_class ?> me-3 my-1" data-bs-toggle="tooltip"
        title="<?= htmlspecialchars($sm_fresh['title']) ?>">
    <?= htmlspecialchars($sm_fresh['words']) ?>
  </span>
  <?php endif ?>

  <!-- Searchable by company name as well as by ticker: search_symbols()
       matches both, and the suggestion list shows the name. -->
  <form id="search" class="d-flex me-3 my-1" action="quote.php" method="post">
		<input class="form-control me-2 typeahead" type="search" name="symbol"
		       placeholder="Symbol or company" aria-label="Find a share">
		<button type="submit" class="btn btn-outline-light">Lookup</button>
  </form>

  <!-- Log Out was a sixth navigation entry, beside the pages. It is an action
       on the account rather than a place to go, so it sits under the user's
       name on the right. -->
  <ul class="navbar-nav">
    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle" href="#" id="smUserMenu" role="button"
         data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <i class="fa-regular fa-user me-1" aria-hidden="true"></i><?= htmlspecialchars($sm_user !== '' ? $sm_user : 'Account') ?>
      </a>
      <div class="dropdown-menu dropdown-menu-end" aria-labelledby="smUserMenu">
        <?php /* A form rather than links, so it works with JavaScript off and
                 so changing the theme is a POST rather than something a link
                 preview can do on your behalf. */ ?>
        <h6 class="dropdown-header">Appearance</h6>
        <form action="theme.php" method="post" class="px-2 pb-2">
          <input type="hidden" name="from" value="<?= htmlspecialchars($sm_here) ?>">
          <div class="btn-group btn-group-sm w-100" role="group" aria-label="Colour scheme">
          <?php foreach (['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $sm_key => $sm_label): ?>
            <button type="submit" name="theme" value="<?= $sm_key ?>"
                    class="btn btn-outline-secondary<?= $sm_theme === $sm_key ? ' active' : '' ?>"
                    <?= $sm_theme === $sm_key ? 'aria-current="true"' : '' ?>><?= $sm_label ?></button>
          <?php endforeach ?>
          </div>
        </form>
        <div class="dropdown-divider"></div>
        <a class="dropdown-item" href="logout.php">Log Out</a>
      </div>
    </li>
  </ul>
  </div>
  </div>
</nav>

<main class="sm-page sm-page-top">
