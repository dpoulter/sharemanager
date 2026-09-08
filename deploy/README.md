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
sudo chown -R www-data:www-data /var/www/sharemanager
```

Only `public/` is served. `includes/` (which holds `constants.php` and the
database password), `tools/`, `tests/`, `sql/` and `.git` sit above the docroot
and are never reachable over HTTP.

## 3. Database

```sh
sudo mariadb -e "create database sharemanager;
                 create user 'shares'@'localhost' identified by '<a long random password>';
                 grant all on sharemanager.* to 'shares'@'localhost';"
```

Load the schema, then the reset-token table:

```sh
sudo mariadb sharemanager < tests/schema.sql
sudo mariadb sharemanager < sql/paper_trading.sql
sudo mariadb sharemanager < sql/password_resets.sql
```

`tests/schema.sql` is a reconstruction, not a dump of your production database.
If you have a real `mysqldump --no-data` from the live site, use that instead
and load only `sql/password_resets.sql` on top.

That leaves an empty database with no accounts, which is what a real deployment
wants. For a demo with data in it, also run:

```sh
php -d include_path=/var/www/sharemanager/tests/fixtures:/var/www/sharemanager/includes \
    tests/seed.php
sudo mariadb sharemanager < tools/sandbox_data.sql
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

```sh
sudo cp deploy/Caddyfile /etc/caddy/Caddyfile
sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
sudo systemctl reload caddy
```

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
