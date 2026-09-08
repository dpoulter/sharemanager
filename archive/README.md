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

## Restoring

```sh
git mv archive/public/<file> public/<file>
```
