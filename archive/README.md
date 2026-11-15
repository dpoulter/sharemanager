# archive

Files moved out of the live tree but kept in the repository. Nothing here is
served: `public/` is the document root, and these are one level above it.

Moved with `git mv`, so the history is intact — `git log --follow
archive/public/<file>` still works, and restoring one is a `git mv` back.

## archive/public

21 scratch and debug pages that sat in the document root. None was referenced by
any link, form action, redirect, include, or script in the application; the only
cross-references were between themselves (`testheader.php` prefetched
`symbols10.php`).

- `symbols.php`, `symbols2.php` … `symbols11.php` — twelve near-identical
  variants of a symbol lookup, each around 25 lines, all with the live query
  commented out.
- `test.php` (2 lines), `test2.php`, `test3.php`, `test_articles.php`,
  `mytest.php`, `my_test_symbols.php`, `firstprog.php` — scratch pages.
- `testheader.php`, `testheader2.php` — earlier drafts of `templates/header.php`.
- `phpinfo.php` — a bare `phpinfo()` call. This one mattered: it was reachable
  in the document root and published the full PHP configuration, including
  extension versions, paths and environment, to anyone who found the URL.

## archive/public, second pass

Twelve more pages, all unreferenced by any link, form action, redirect, include
or script.

Five JSON endpoints — `portfolio_json.php`, `quote_json.php`,
`performance_json.php`, `company_search_json.php`, `login_json.php`. These look
like an API for a client that was never built, or one that no longer exists.
Two of them carried a real problem: `portfolio_json.php` and `quote_json.php`
resolve the account with `SELECT * FROM users WHERE username = 'dale'` rather
than from the session, so any logged-in caller got that account's portfolio.
Archiving them removes the leak; if the client turns out to exist, fix that
before restoring either.

`login_json.php` verified passwords with `crypt($password, $row["hash"])`, while
`login.php` uses `crypt($password, 'sharemanager')`. Two different schemes
against the same `users.hash` column, so an account working on one path would
not necessarily work on the other. Worth resolving before this one comes back.

Seven orphaned pages — `chart.php`, `edit_screen.php`, `history.php`,
`navbar.php`, `new_screen.php`, `reset.php`, `search.php`.

- `search.php` held the one live SQL injection in the application. It is fixed
  in the archived copy, but nothing ever called it: the navbar typeahead it
  appeared to serve uses a hardcoded list, `['AAA','BBB','CCC']`, in
  `templates/scripts.js`.
- `reset.php` was a logged-in change-password form. Account recovery is
  unaffected — `reset_passwd.php` handles the emailed link and is still served,
  and is one of the four pages `includes/config.php` exempts from the login
  check — but there is now no in-application way to change a password while
  logged in.
- `navbar.php` is superseded by the navigation inside `templates/header.php`.
- `chart.php` needs `phpChart_Lite/`, which is not in the repository.

## archive/public, third pass

`portfolio.php` was a template fragment sitting in the document root. It opens
with `<h3>Portfolio</h3>` and expects `$cash`, `$total_value` and
`$inactive_positions` to be supplied by whatever renders it. `render()` resolves
`"portfolio.php"` to `templates/portfolio.php`, which is the copy
`performance.php` actually uses, so the one in `public/` was reachable only as a
direct URL and warned seven times when fetched. The two copies had diverged by
83 lines; the templates one is the live copy.

## archive/public-backups

Editor backup files (`*.php~`, `*.bak`) that were committed to `public/` and
`templates/`. These are a real exposure in a document root: a web server that
does not recognise the `.php~` extension serves the file as plain text, which
publishes the source, and these copies still contain the old hard-coded API
keys.

## archive/includes

`get_statistics_test.php` - an earlier copy of the sector median loop out of
`get_statistics.php`, 38 lines, referenced by nothing: no include, no cron
entry, no link, and no caller anywhere in the tree. It had drifted a long way
from the live copy - no exchange scoping on any of its queries or on the row it
wrote, no fund exclusion, and the old upper-middle median - and it ended by
inserting a `get_statistics_asof` row into `jobs`. That marker is what the
dashboard, the screens and the quote page resolve their as of date from, so
running this by hand would have moved the whole application's idea of "today"
onto whatever date it had just half-calculated.

## archive/includes, second pass

- `get_api_stats.php` - a PHP twin of `python/get_api_stats.py`, which is what
  `refresh_all.sh` actually runs. `README.md` already called it deprecated. It
  had fallen a long way behind: no currency correction, so it would write the
  understated P/E, price/sales and price/book that FMP sends for every
  non-sterling reporter, and running it by hand would have undone a night's
  work silently.
- `test_calc_valuation.php`, `test_earnings_growth.php`, `test_rank.php` -
  scratch scripts, referenced by nothing. `test_rank.php` is the one that
  mattered: it deletes and rewrites `value_score` and `momentum_score` rows
  for yesterday's date, on its own old ranking SQL, with no exchange scoping
  and no fund exclusion. Running it would have quietly replaced the
  dashboard's scores with a different calculation's answers.
- `share_functions.php.080418`, `share_functions.php.230623` - editor backups
  of the library, 121 KB and 127 KB, committed in `includes/`. Both still
  contain hard-coded API keys.

## archive/templates

- `share_functions.php` - a 55 KB duplicate of `includes/share_functions.php`
  sitting in the templates directory. Nothing renders it and nothing includes
  it: `include("share_functions.php")` resolves through the include path to
  `includes/`, and `render()` is only ever called with page templates. The two
  copies had diverged - this one still reads `div_yield` and
  `previous_close`, indicators nothing has written for years.
- `quote.php.111118` - an editor backup of the quote page, 40 KB.
- `testheader.php` - an earlier draft of `templates/header.php`. Its typeahead
  was the hardcoded `['AAA','BBB','CCC']` list that `templates/scripts.js`
  describes; nothing renders it.

## Restoring

```sh
git mv archive/public/<file> public/<file>
```
