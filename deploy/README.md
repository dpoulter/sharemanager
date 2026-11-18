# Deploying to a public host with Caddy

For `sharemanager.advancepost.net` on the test server. Read
[the security notes](#what-changed-because-it-is-public) before opening it up:
several things that were harmless behind an SSH tunnel are not harmless on a
public URL. They are all present in the sandbox too, so this is worth reading
even if you are not going public yet.

## 1. Packages

Caddy is not in the Ubuntu archive; add its repository first.

```sh
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
  | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
  | sudo tee /etc/apt/sources.list.d/caddy-stable.list

sudo apt update
sudo apt install -y caddy php8.3-fpm php8.3-mysql php8.3-xml php8.3-mbstring mariadb-server git
```

`php8.3-xml` is for the News tab (simplexml). There is deliberately no
`php8.3-gd`: the charts render SVG so they do not need it.

## 2. Code

```sh
sudo mkdir -p /var/www
sudo git clone https://github.com/dpoulter/sharemanager /var/www/sharemanager
cd /var/www/sharemanager
sudo git checkout claude/code-review-z7ysrs

# The web server only ever reads the code, so give it group read and keep
# ownership with root. Handing the tree to www-data would let a bug in the
# application rewrite the application, and would make every later `sudo git
# pull` fail with "detected dubious ownership".
sudo chown -R root:www-data /var/www/sharemanager
sudo chmod -R g+rX,o-rwx /var/www/sharemanager
```

Later updates are then just:

```sh
cd /var/www/sharemanager && sudo git pull
```

### Front-end assets

`css/`, `js/`, `img/` and `fonts/` are in `.gitignore`, so a fresh clone has no
stylesheets and every page renders as unstyled HTML. Fetch the third-party
libraries `templates/header.php` loads:

```sh
sudo tools/fetch_assets.sh

# Files created by root do not inherit the group the web server reads through,
# so put the ownership back afterwards.
sudo chown -R root:www-data public/css public/js public/img public/fonts
sudo chmod -R g+rX public/css public/js public/img public/fonts
```

That gets Bootstrap, jQuery and typeahead from a CDN. It cannot get
`css/styles1.css`, which is this application's own stylesheet, is gitignored,
and exists only on whatever server runs the live site - the script writes a
placeholder so the page does not 404, but the result is plain Bootstrap rather
than the real look. If you have that server, copy the real files instead:

```sh
sudo tools/fetch_assets.sh --from you@your-live-server:/var/www/shares
```

Only `public/` is served. `includes/` (which holds `constants.php` and the
database password), `tools/`, `tests/`, `sql/` and `.git` sit above the docroot
and are never reachable over HTTP.

## 3. Database

MariaDB matches accounts on user *and* host, and the two loopback spellings are
not interchangeable: `localhost` connects over the unix socket, `127.0.0.1` over
TCP, and with name resolution on, a TCP connection from 127.0.0.1 is reported
back as `localhost` anyway. Create an account for one and point `SM_DB_HOST` at
the other and every page is a 500 reading "Access denied for user
'shares'@'localhost'". Create both spellings and the question does not arise.

```sh
sudo mariadb <<'SQL'
create database if not exists sharemanager;
create user if not exists 'shares'@'localhost' identified by '<a long random password>';
create user if not exists 'shares'@'127.0.0.1' identified by '<the same password>';
grant all on sharemanager.* to 'shares'@'localhost';
grant all on sharemanager.* to 'shares'@'127.0.0.1';
flush privileges;
SQL
```

Prove the credential before going any further. This must print 1:

```sh
mariadb -h 127.0.0.1 -u shares -p sharemanager -e "select 1"
```

The password has to match `env[SM_DB_PASS]` in the php-fpm pool exactly, and
`env[SM_DB_HOST]` there is `127.0.0.1`.

## The AI outlook panel

The quote page and the dashboard each have an outlook panel. Nothing generates
one on a schedule: the panel draws the last saved outlook, and only the
Generate button calls the API. An idle site costs nothing, and no cron job
needs an API key.

Settings go in the php-fpm pool, beside the database ones, not in
`/etc/sharemanager.env` - that file is sourced by cron jobs, none of which has
any use for an API key:

```
env[ANTHROPIC_API_KEY] = sk-ant-...
env[ANTHROPIC_MODEL]   = claude-sonnet-5-5
env[SM_VAULT_DIR]      = /var/lib/sharemanager/vault
```

With no key the panel says so and generates nothing; that is a state, not an
error, so the page still works.

The per-million prices the panel costs each generation with default to Sonnet
5.5's published rates and can be overridden when they change:
`ANTHROPIC_PRICE_INPUT`, `ANTHROPIC_PRICE_OUTPUT`, `ANTHROPIC_PRICE_CACHE_READ`,
`ANTHROPIC_PRICE_CACHE_WRITE`.

### The vault

`SM_VAULT_DIR` is a directory of markdown notes with YAML frontmatter - your
own investing notes, synced from wherever you write them:

```
---
title: Position sizing
tags: [policy, risk]
---
Never more than 5% of the portfolio in one name.
```

Notes tagged `policy` and `indicators` go into every prompt. A share outlook
also gets notes tagged with its sector (`stock_symbols.industry_group`, e.g.
`industrials`) and with any screen it is currently in (`trend-following`); the
market outlook gets notes tagged `market` instead. Over
`SM_VAULT_WORD_BUDGET` words (6,000 by default) sector notes are dropped
first, then strategy notes. Policy notes are never dropped.

The directory is allowed to be missing or empty. The panel says which notes it
used, and "no vault notes were available" is a normal answer.

Load the schema, then the reset-token table:

```sh
sudo mariadb sharemanager < tests/schema.sql
sudo mariadb sharemanager < sql/paper_trading.sql
sudo mariadb sharemanager < sql/password_resets.sql
sudo mariadb sharemanager < sql/trend_screens.sql
sudo mariadb sharemanager < sql/ai_outlook.sql
sudo mariadb sharemanager < sql/fund_sectors.sql
sudo mariadb sharemanager < sql/report_currency.sql
sudo mariadb sharemanager < sql/shareholder_yield.sql
sudo mariadb sharemanager < sql/fmp_scores.sql
sudo mariadb sharemanager < sql/news.sql
sudo mariadb sharemanager < sql/trending_value.sql
sudo mariadb sharemanager < sql/rns.sql
```

`sql/trending_value.sql` changes the Value score on the home page and the
quote page, because it adds the two value measures the score was always meant
to have. Run the pipeline by hand afterwards so the new score exists for the
latest trading day, and read the last table the migration prints: a count of
zero for `price_cash_flow` or `ev_ebitda` means the FMP field name is wrong.
`Ratios.priceToOperatingCashFlowRatioTTM` is the one mapping in this
application that has never been seen in a live response - there is no FMP key
in the repository session, so `python/fmp_probe.py` could not confirm it - and
`ev_ebitda` is set to `fx_adjust='NONE'` pending the same check on a
non-sterling reporter. See the comments at the top of the file.

`tools/fetch_assets.sh` now also downloads TradingView Lightweight Charts
(Apache-2.0) into `public/js/` and copies `templates/chart.js` beside it. Run
it after this deploy, or the quote page keeps the old `stockgraph.php` image
- which is what the chart falls back to when the library is not there.

Every `sql/*.sql` above is re-runnable, so a later `git pull` can just run
them all again rather than working out which are new. Two currently need it
on a database that already has the others: `sql/fund_sectors.sql` adds
`stock_symbols.is_fund`, and `sql/fmp_scores.sql` is new.

## Regulatory announcements, and Investegate's terms

The nightly news job reads companies' own regulatory announcements from
Investegate instead of Google News headlines. `includes/fetch_rns.php`
replaced `includes/fetch_news.php` in `refresh_all.sh`; the headline job and
the `news_articles` table are both still there and can still be run and read
by hand.

**The terms matter and they shape the design.** Investegate's terms of use are
for private investors using the site in a personal capacity: extracts may be
kept for personal reference, and redistribution, republishing and commercial
use are forbidden. This application fits that - it is one person's portfolio
tool, behind Authelia, reachable over Tailscale only - and it has to stay
fitting it:

- announcement text is stored to be scored and to be shown inside the app, and
  nowhere else;
- nothing that shows announcement text may be served outside the login. The
  suite checks that `public/in_the_news.php` redirects an anonymous request
  and that no announcement text reaches it;
- if this is ever opened up beyond Tailscale, the announcement text has to
  stop being served first.

Investegate shows signed-out readers an announcement limit. The fetcher looks
for it on every page and **stops the run for the night** when it finds one,
leaving the rest for tomorrow - so the first month's backfill may take several
nights. That is the intended behaviour. Do not add a login and do not work
round the limit.

Rate: one request every two seconds (`RNS_FETCH_INTERVAL`), a User-Agent that
names the application (`RNS_USER_AGENT`), robots.txt fetched and honoured
before anything else, and a refusal count (`RNS_MAX_REFUSALS`) that ends the
run on repeated 429s or 5xx. About a quarter of an hour for 350 companies on
a normal night.

### What has not been checked against a real page

This was written in a session with no network route to investegate.co.uk, so
the parsing was built to depend on as little of the markup as possible and
tested against fixtures of the documented shape. Two things to check on the
first run, in this order:

1. **`rns_body()` in `includes/rns.php`** - how the announcement text is
   pulled out of the page. It uses no class names or ids, so the likely
   failure is not "no text" but "the text plus some navigation". Read one
   saved announcement page through it before trusting a month of scores. A
   page that yields less than `RNS_MIN_BODY_CHARS` of text is not stored, so
   a total failure shows up as nothing being collected rather than as rubbish
   being scored.
2. **The TIDM.** `stock_symbols.tidm` starts empty. The fetcher tries the
   symbol, then the symbol with a trailing dot (Rolls-Royce is `RR` here and
   `RR.` on Investegate), and writes back whichever answered. Check the column
   fills up over the first run or two; fill in by hand anything stubborn,
   `BT.A` being the obvious candidate.

Also unverified: whether Investegate has a full-day announcements index that
would replace 350 company-page requests with one. The brief's own note said
`/today-announcements/` served five links without JavaScript. The fetcher
takes the company-page route, which is known to work; everything downstream of
`rns_announcement_links()` is indifferent to where the links came from, so
switching is a change to one loop.

### Tuning what gets skipped

Most of an RNS feed is housekeeping - buyback paperwork, voting rights,
holdings notices. `rns_skip_rules` holds the list, so tuning it is an update
rather than a deploy:

```sql
-- stop skipping something
update rns_skip_rules set enabled='N' where pattern='Notice of AGM';
-- skip something new
insert into rns_skip_rules (pattern, keep_if, description, enabled)
values ('Quarterly Filing', null, 'Routine.', 'Y');
```

`keep_if` is the exception: `Director/PDMR Shareholding` is skipped unless the
title says purchase, sale, bought or sold, because a director spending their
own money is one of the few real signals in the feed.

After the first night, read a sample by eye and check the cost: the per-call
tokens and cost are on each row (`input_tokens`, `output_tokens`, `cost_usd`),
so `select sum(cost_usd), count(*) from rns_announcements where scored_at is
not null` is the real figure to set `RNS_SCORE_MAX_PER_RUN` from.

## News features, and what was left out

The headline store, the chart markers and the "why did it move?" panel are
modelled on PokieTicker (github.com/owengetinfo-design/PokieTicker, MIT).

Two of its features are deliberately not here:

- **The XGBoost direction prediction.** It needs a labelled history to train
  on and this application has none: Google News returns only recent items and
  FMP has no news for `.L` tickers, so there is no backfill and the store
  starts empty the day `includes/fetch_news.php` first runs. A model trained
  on a few weeks of headlines would produce a number with a decimal point on
  it and nothing behind it, which is worse than no number at all. Revisit
  when there is a year of history and a way to score the predictions
  honestly.
- **"Similar past periods".** Same reason, and the same trap in sharper form:
  with months rather than years of data every period looks similar to every
  other, and the feature would be most confident when it had least to go on.

The sentiment indicators are registered with `calc_rank='N'` for the same
reason. They are shown, screenable and sent to the AI outlook, and they are
not part of the value, quality or overall scores until there is history to
justify it.

`sql/report_currency.sql` is the one that matters most to get in before the
next nightly run. Until it is applied, `stock_symbols.report_ccy` does not
exist, the fundamentals loader cannot record what each company reports in, and
every non-sterling reporter keeps the understated P/E, price/sales and
price/book that FMP sends. The first run after it lands spends one extra call
per symbol reading reporting currencies, and about one call per currency
reading exchange rates; after that it refreshes a currency only when it is
over a month old.

`deploy/deploy.sh` does not run migrations, so after pulling a change that
brings a new one, run it by hand. Until `sql/fund_sectors.sql` has been
applied the nightly job carries on ranking investment funds alongside
companies, the way it did before, and says so in `message_log`.

`tests/schema.sql` is a reconstruction, not a dump of your production database.
If you have a real `mysqldump --no-data` from the live site, use that instead
and load only `sql/password_resets.sql` on top.

That leaves an empty database with no accounts, which is what a real deployment
wants. For a demo with data in it, also run:

`tests/seed.php` reads its connection from `tests/fixtures/constants.php`, which
takes the `SM_TEST_*` variables and otherwise defaults to the throwaway test
database. Point it at this one explicitly or it will not write here at all:

```sh
cd /var/www/sharemanager
sudo SM_TEST_DB=sharemanager SM_TEST_HOST=127.0.0.1 \
     SM_TEST_USER=shares SM_TEST_PASS='<the database password>' \
     php -d include_path=/var/www/sharemanager/tests/fixtures:/var/www/sharemanager/includes \
     tests/seed.php
sudo mariadb sharemanager < tools/sandbox_data.sql

sudo mariadb sharemanager -e "select id, username from users;"
```

That creates **tester / testpass**, whose password is published in this
repository. Only do it on a host you are willing to have strangers log into,
and delete the account before the site is anything but a demo.

## 4. Settings, and where the password goes

Do not edit `includes/constants.php`. It is tracked in git, so an edit there
conflicts on every `git pull` and puts you one `git commit -a` away from
publishing the database password. Every setting in it reads the environment
first, so put the real values in the php-fpm pool instead.

php-fpm refuses to start if it cannot open its error log or its session
directory, and `systemctl` reports only "control process exited with error
code", so create both before copying the pool in.

```sh
sudo install -d -o www-data -g www-data /var/log/php
sudo install -d -o www-data -g www-data -m 700 /var/lib/php/sharemanager-sessions

sudo cp deploy/php-fpm-pool.conf /etc/php/8.3/fpm/pool.d/sharemanager.conf
sudo chmod 640 /etc/php/8.3/fpm/pool.d/sharemanager.conf
sudo editor /etc/php/8.3/fpm/pool.d/sharemanager.conf   # set SM_DB_PASS

sudo php-fpm8.3 -t          # validates the config and names the offending line
sudo systemctl restart php8.3-fpm
```

`php-fpm8.3 -t` is worth running every time: it prints the exact file and line,
which the systemd failure message does not.

The pool also sets `display_errors = off`, an `open_basedir` confined to the
application, and disables the shell-exec family. `SM_DISPLAY_ERRORS` is
deliberately absent: setting it to 1 puts filesystem paths and query fragments
on the page for anyone who can reach the site.

Confirm the pool is listening before moving on:

```sh
ls -l /run/php/sharemanager.sock
```

## 5. Caddy

Point the DNS A record at the server first; Caddy needs port 80 reachable for
the ACME challenge, and gets the certificate itself.

Caddy needs ports 80 and 443 to itself. Check nothing else holds them - a
previous Apache or nginx will keep it from starting:

```sh
sudo ss -ltnp | grep -E ':80 |:443 '
# if apache2 or nginx answers:
sudo systemctl disable --now apache2 nginx 2>/dev/null
```

The Caddyfile writes an access log, and Caddy runs as the `caddy` user, so
that directory has to belong to it or the service exits at startup:

```sh
sudo install -d -o caddy -g caddy -m 750 /var/log/caddy
```

**Do not copy this over `/etc/caddy/Caddyfile`.** That file may already serve
other sites on this host, and replacing it takes every one of them down with
`ERR_SSL_PROTOCOL_ERROR`: Caddy stops holding certificates for names that are no
longer in its config. Install the vhost as its own file and import it.

```sh
sudo mkdir -p /etc/caddy/conf.d
sudo cp deploy/Caddyfile /etc/caddy/conf.d/sharemanager.caddy
```

Then make sure `/etc/caddy/Caddyfile` contains this line once, at the top level,
outside any site block:

```
import /etc/caddy/conf.d/*.caddy
```

```sh
sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile

# validate runs as root and opens the log writer, which leaves
# sharemanager.log owned by root in a directory owned by caddy. The service
# runs as caddy and then cannot write to its own log file, which reads as
# "permission denied" on a directory that looks correctly owned. Hand the
# whole directory back after validating.
sudo chown -R caddy:caddy /var/log/caddy

sudo systemctl reload caddy
sudo systemctl status caddy --no-pager
```

Confirm Caddy still knows every site this host serves, not just this one:

```sh
sudo caddy adapt --config /etc/caddy/Caddyfile --adapter caddyfile 2>/dev/null \
  | grep -oE '"[a-z0-9.-]+\.[a-z]{2,}"' | sort -u
```

`restart`, not `reload`: reload fails if the service is not already running,
which it is not on a first install. `caddy validate` only checks the file, so a
valid config can still fail to start - a port already taken, or `/var/log/caddy`
missing. `journalctl -xeu caddy` names which.

## 6. Check it

```sh
curl -sI https://sharemanager.advancepost.net/login.php | head -1

# The auth gate. Both must redirect to login, not serve the page.
curl -s  https://sharemanager.advancepost.net/index.php | grep -c "Top Ten"            # 0
curl -s  https://sharemanager.advancepost.net/index.php/login.php | grep -c "Top Ten"  # 0

# Nothing above the docroot is reachable.
curl -sI https://sharemanager.advancepost.net/../includes/constants.php | head -1      # 40x
curl -sI https://sharemanager.advancepost.net/.git/config | head -1                    # 404
```

---

## What changed because it is public

Four things were fine behind an SSH tunnel and are not fine on a public URL.
All four are fixed on this branch; they are written down because they affect
any existing deployment too.

**The login gate could be walked past.** `config.php` decided whether a page
needed a session by matching the tail of `$_SERVER["PHP_SELF"]`. PHP_SELF is
`SCRIPT_NAME` with `PATH_INFO` appended, so a request for
`/index.php/login.php` runs `index.php` with PHP_SELF ending in `login.php` and
the gate stood aside — `curl http://host/index.php/login.php` returned the
logged-in dashboard to an anonymous caller. This is not specific to Caddy: it
reproduces on the built-in server the sandbox uses, so any existing deployment
has it too. The gate now reads `SCRIPT_NAME`, which cannot carry path info, and
any request that arrives with path info is refused outright.

**Passwords were barely hashed.** `crypt($password, 'sharemanager')` uses a
fixed two-character DES salt. Two consequences: identical passwords produce
identical hashes on every account, so one lookup table breaks all of them; and
DES crypt discards everything past the eighth character, so a twenty-character
password was only ever as strong as its first eight. Storage is now
`password_hash()`. Existing hashes still verify, and each one is replaced the
next time that user logs in, so nobody is locked out and the weak hashes drain
away by themselves. (`update_password()` already wrote a modern hash, which
`login.php` could not verify — anyone who used the reset flow was locked out.
That is fixed by the same change.)

**Anyone could take over any account.** The password-reset link carried
`md5(90*13+id)` — that is `md5(1170 + user_id)`, computable for any account by
anyone, and `reset_passwd.php` is deliberately exempt from the login gate. The
token for user 1 was always `a113c1ecd3cace2237256f4c712f61b5`. Tokens are now
32 random bytes, single use, expiring after an hour, and only their SHA-256 is
stored, so a database backup does not hand over live reset links. This needs
`sql/password_resets.sql` loaded.

**Errors were printed to the browser.** `config.php` hardcoded
`ini_set("display_errors", true)`, so every warning published a filesystem path
and often a fragment of the query that failed. It defaults to off now and logs
instead; set `SM_DISPLAY_ERRORS=1` in the environment for a sandbox.

Session cookies are `httponly` + `SameSite=Lax`, `secure` whenever the request
arrived over TLS, and the session id is regenerated at login and registration
so a session fixed in advance is not the one that ends up logged in.

## Still open, and your call

- **`register.php` lets anyone create an account.** That is by design in the
  original application. On a public URL it means strangers can sign up. If you
  do not want that, put basic auth in front of the whole vhost, or restrict by
  IP, or delete `register.php` and create accounts by hand.
- **`tester` / `testpass` is public**, published in this repository. Do not load
  `tests/seed.php` into the production database, and if you already have, delete
  that account.
- **Email is not configured.** `SMTP_*` in `constants.php` are empty, so the
  reset email cannot send. Until you set them up the reset flow is unusable —
  which is not a security problem, just a missing feature.
- **The scrapers need php-curl.** `includes/scrape_*.php` use ext-curl and will
  fail without `php8.3-curl`. Nothing served needs it.
