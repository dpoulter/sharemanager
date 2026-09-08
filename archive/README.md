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
