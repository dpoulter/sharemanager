# Running the sandbox on your server

Puts a browsable copy of the site on your own machine, with generated data,
completely separate from the live site at `/var/www/shares`.

Nothing here touches production: different directory, different database,
different database user, and no cron entries. The one thing you must get right
is keeping it that way, so the ordering below matters.

---

## 1. Check what you have, and install what is missing

```sh
php -v                                  # 8.0 or newer
php -m | grep -E 'pdo_mysql'            # pdo_mysql must be listed
mysql --version                         # or mariadb --version
git --version
```

If any of those say `command not found`, the box is bare and you need to install
them. First work out where you are:

```sh
# Is this the machine already running the live site?
ls -d /var/www/shares 2>/dev/null && echo "LIVE SITE IS HERE" || echo "not the live site"

cat /etc/os-release | head -2
```

**Debian or Ubuntu:**

```sh
sudo apt update
sudo apt install -y git curl mariadb-server php-cli php-mysql
sudo systemctl enable --now mariadb
```

**RHEL, Rocky, Alma or Fedora:**

```sh
sudo dnf install -y git curl mariadb-server php-cli php-mysqlnd
sudo systemctl enable --now mariadb
```

That is everything the sandbox needs. It does **not** need Python, Apache or
nginx: the seed is PHP, and section 5 option A serves the site with PHP's own
built-in server. Only add a web server if you choose option B.

Then re-run the four checks above. All four must succeed before continuing;
`php -m | grep pdo_mysql` printing nothing is the one people miss, and it fails
later with a confusing PDO error rather than an obvious missing-extension one.

If MariaDB is a fresh install, set a root password while you are here:

```sh
sudo mariadb-secure-installation
```

### If this is a different machine from the live site

That is the better arrangement, not a problem. A separate box means the sandbox
cannot reach production at all, so the isolation this document spends effort on
comes for free. You can still follow every step below; the checks in section 3
will simply pass trivially because the live database is not on this machine.

If it turns out this **is** the live machine and PHP is merely missing from
root's `PATH`, stop and check with `ls /usr/bin/php*` before installing
anything, so you do not end up with a second PHP version alongside the one the
live site runs on.

## 2. Clone to its own directory

**Not** into `/var/www/shares`. A separate path is the first thing keeping the
sandbox away from your live site.

```sh
sudo mkdir -p /var/www/shares-sandbox
sudo chown "$USER" /var/www/shares-sandbox
git clone https://github.com/dpoulter/sharemanager.git /var/www/shares-sandbox
cd /var/www/shares-sandbox
git checkout claude/code-review-z7ysrs
```

## 3. Create the sandbox database and a user that can only see it

The grant is the second thing keeping the sandbox away from production. Give
this user rights on the sandbox database **only** — then even a mistake in the
sandbox cannot reach real data.

```sql
CREATE DATABASE sharemanager_sandbox;
-- 'localhost' here must match SM_SANDBOX_HOST in tools/sandbox.env, which
-- defaults to localhost. An account created as 'sandbox'@'localhost' is not
-- necessarily matched by a TCP connection to 127.0.0.1.
CREATE USER 'sandbox'@'localhost' IDENTIFIED BY 'pick-something-random';
GRANT ALL PRIVILEGES ON sharemanager_sandbox.* TO 'sandbox'@'localhost';
FLUSH PRIVILEGES;
```

Confirm it genuinely cannot see the live database:

```sh
mysql -u sandbox -p -e "use sharemanager;"     # must fail with "Access denied"
```

If that succeeds, stop and fix the grant before going further.

## 4. Build the data

Write the settings to a file rather than exporting them, or you will have to
re-export in every new shell and the script will fall back to its defaults and
fail to connect:

```sh
cd /var/www/shares-sandbox
cp tools/sandbox.env.example tools/sandbox.env
$EDITOR tools/sandbox.env          # set SM_SANDBOX_USER and SM_SANDBOX_PASS

tools/sandbox.sh --no-serve --rebuild
```

`tools/sandbox.env` is gitignored, because it holds the password.

You should see roughly:

```
building sandbox data in sharemanager_sandbox
  seeded LON    1 symbols,   962 prices, 2023-01-02 .. today
  seeded XLON  12 symbols, 11544 prices, 2023-01-02 .. today
  done
```

Takes a minute or two, mostly inserting price rows.

**Do not add any cron entries.** The sandbox runs on generated data;
`refresh_all.sh` belongs to the live site only.

---

## 4b. Front-end assets

`css/`, `js/`, `img/` and `fonts/` are in `.gitignore`, so a fresh clone has no
stylesheets or scripts and every page renders as unstyled HTML: working links
and forms, no Bootstrap.

```sh
tools/fetch_assets.sh
```

That downloads Bootstrap 4.1.3, jQuery 3.3.1 and typeahead.js 0.11.1 into
`public/css` and `public/js`, and copies `templates/scripts.js` to where
`header.php` looks for it. It checks every download and reports failures rather
than leaving empty files behind.

`css/styles1.css` is the application's own stylesheet. It is not on any CDN and
exists only on the server running the live site, so the script writes a
placeholder and the sandbox renders with plain Bootstrap. To get the real thing,
along with the images, copy from a machine that has them:

```sh
tools/fetch_assets.sh --from you@your-server:/var/www/shares
```

Use `--from` whenever you can — it is the only way to match production exactly.

If the server has no outbound internet access, `--from` is the only option; the
CDN downloads will all report `http 000`.

## 5. Serve it — pick one

### Option A: private, over SSH (simplest, and the safest)

Bind to localhost and reach it through an SSH tunnel. Nothing is exposed to the
internet, so the weak sandbox login does not matter.

Create `/etc/systemd/system/sharemanager-sandbox.service`:

```ini
[Unit]
Description=sharemanager sandbox
After=network.target mariadb.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/shares-sandbox
Environment=SM_TEST_DB=sharemanager_sandbox
Environment=SM_TEST_HOST=127.0.0.1
Environment=SM_TEST_USER=sandbox
Environment=SM_TEST_PASS=pick-something-random
ExecStart=/usr/bin/php -S 127.0.0.1:8081 -t /var/www/shares-sandbox/public \
  -d include_path=/var/www/shares-sandbox/tests/fixtures:/var/www/shares-sandbox/includes \
  -d opcache.enable=0 -d display_errors=1
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now sharemanager-sandbox
sudo systemctl status sharemanager-sandbox
```

From your laptop:

```sh
ssh -L 8081:127.0.0.1:8081 you@your-server
```

Then open `http://127.0.0.1:8081/`.

`php -S` is PHP's built-in development server. It is single-threaded and was
never meant to face the internet, which is exactly why this option binds it to
localhost.

### Option B: a proper vhost (if it needs to be reachable in a browser directly)

Use your real web server and read **section 6 first** — an unprotected sandbox
with a published password is a liability.

The one non-obvious part is `include_path`. It must put `tests/fixtures` ahead
of `includes`, because that is what makes the app read the sandbox's
`constants.php` instead of your production one. Get this wrong and the sandbox
either fails to connect or, worse, connects to the live database.

**Apache** (`/etc/apache2/sites-available/sandbox.conf`):

```apache
<VirtualHost *:80>
    ServerName sandbox.shares.duckdns.org
    DocumentRoot /var/www/shares-sandbox/public

    php_value include_path "/var/www/shares-sandbox/tests/fixtures:/var/www/shares-sandbox/includes"
    SetEnv SM_TEST_DB   sharemanager_sandbox
    SetEnv SM_TEST_HOST 127.0.0.1
    SetEnv SM_TEST_USER sandbox
    SetEnv SM_TEST_PASS pick-something-random

    <Directory /var/www/shares-sandbox/public>
        Require all granted
        AllowOverride All
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/sandbox-error.log
    CustomLog ${APACHE_LOG_DIR}/sandbox-access.log combined
</VirtualHost>
```

```sh
sudo a2ensite sandbox && sudo systemctl reload apache2
```

With php-fpm rather than mod_php, `php_value` does nothing — put the same
settings in the pool config instead (`php_admin_value[include_path]` and
`env[SM_TEST_*]`), or use a dedicated pool for the sandbox.

**nginx + php-fpm** (`/etc/nginx/sites-available/sandbox`):

```nginx
server {
    listen 80;
    server_name sandbox.shares.duckdns.org;
    root /var/www/shares-sandbox/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php$is_args$args; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param PHP_VALUE "include_path=/var/www/shares-sandbox/tests/fixtures:/var/www/shares-sandbox/includes";
        fastcgi_param SM_TEST_DB   sharemanager_sandbox;
        fastcgi_param SM_TEST_HOST 127.0.0.1;
        fastcgi_param SM_TEST_USER sandbox;
        fastcgi_param SM_TEST_PASS pick-something-random;
    }

    # editor backups and dotfiles are served as plain text by default
    location ~ ~$      { deny all; }
    location ~ /\.     { deny all; }
}
```

```sh
sudo ln -s /etc/nginx/sites-available/sandbox /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

---

## 6. If it is reachable from the internet, protect it

The sandbox login is **tester / testpass**, published in this repository. Beyond
that, the application hashes passwords with `crypt($password, 'sharemanager')` —
a fixed salt, so identical passwords produce identical hashes and the scheme is
weak regardless of what you set. `register.php` also lets anyone create an
account.

None of that matters behind an SSH tunnel. All of it matters on a public URL.
Put HTTP basic auth in front of the whole vhost:

```sh
sudo htpasswd -c /etc/apache2/.htpasswd-sandbox youruser
```

```apache
<Directory /var/www/shares-sandbox/public>
    AuthType Basic
    AuthName "sandbox"
    AuthUserFile /etc/apache2/.htpasswd-sandbox
    Require valid-user
</Directory>
```

nginx equivalent:

```nginx
auth_basic "sandbox";
auth_basic_user_file /etc/nginx/.htpasswd-sandbox;
```

Or restrict by address (`Require ip 203.0.113.0/24` / `allow 203.0.113.0/24; deny all;`).

And serve it over HTTPS if it is public — basic auth over plain HTTP sends the
password in clear text. `certbot --apache -d sandbox.shares.duckdns.org` or the
nginx equivalent.

---

## 7. Check it worked

```sh
curl -sI http://127.0.0.1:8081/login.php | head -1        # HTTP/1.1 200 OK

curl -s -c /tmp/j -o /dev/null http://127.0.0.1:8081/login.php
curl -s -b /tmp/j -c /tmp/j -d "username=tester&password=testpass" \
     -o /dev/null http://127.0.0.1:8081/login.php
curl -s -b /tmp/j http://127.0.0.1:8081/index.php | grep -c "Top Ten"   # 4
```

In a browser you should see the four top-ten panels populated, and
Transactions, Dividends, Cash History and Portfolio Overview with rows in them.

Confirm the isolation held:

```sh
mysql -u sandbox -p -e "show databases;"    # sharemanager_sandbox, not sharemanager
```

---

## 8. Day to day

Refresh the data (destroys and rebuilds the sandbox database only):

```sh
cd /var/www/shares-sandbox && tools/sandbox.sh --no-serve --rebuild
sudo systemctl restart sharemanager-sandbox
```

Pull code changes:

```sh
cd /var/www/shares-sandbox && git pull && sudo systemctl restart sharemanager-sandbox
```

Remove it entirely:

```sh
sudo systemctl disable --now sharemanager-sandbox
sudo rm /etc/systemd/system/sharemanager-sandbox.service
sudo rm -rf /var/www/shares-sandbox
mysql -e "DROP DATABASE sharemanager_sandbox; DROP USER 'sandbox'@'localhost';"
```

---

## What will not work, and that is expected

- **Charts.** `chart.php` and `jpgraph.php` need `phpChart_Lite/`, which is not
  in the repository. `stockgraph.php` and `performance_graph.php` do render:
  they use jpgraph when it is installed and a small SVG line chart otherwise.
  The fallback deliberately avoids GD, which ships as a separate `php-gd`
  package, so no extension needs installing.
- **News.** The News tab fetches Google News RSS, so it needs outbound network,
  the `php-xml` package for simplexml, and `allow_url_fopen` on. Missing any of
  them means the tab reports no articles; it never hangs or fails the page.
- **Live quotes and price fetches.** No `EODHD_API_KEY` is set, so these return
  empty rather than erroring. Everything on screen comes from generated data.
- **Email.** No SMTP is configured, so password reset cannot send.
- **The prices are fake** — a sine wave with drift. Fine for exercising the UI,
  useless for judging whether the screen picks good stocks.

## Common problems

| symptom | cause |
| --- | --- |
| "Access denied for user" | `SM_TEST_*` not reaching PHP. With php-fpm, `SetEnv` in an Apache vhost does not reach it; use the pool config. |
| Blank page, no error | `display_errors` off. Check the web server error log. |
| Changes to a file appear to do nothing | `php -S` runs under the `cli-server` SAPI, where `opcache.enable` applies rather than `opcache.enable_cli`. Set `opcache.enable=0`, as the unit above does. |
| Dashboard panels empty | Data not built, or built before this branch. Re-run with `--rebuild`. |
| "cannot connect as 'smtest'" | Settings not reaching the script. Put them in `tools/sandbox.env`; exports do not survive a new shell. |
| "cannot connect as 'sandbox'" | Wrong password, or a host mismatch. `select user, host from mysql.user where user='sandbox'` — if it says `localhost`, `SM_SANDBOX_HOST` must be `localhost`, not `127.0.0.1`. |
| Missing tables after a `git pull` | The schema changed. `tools/sandbox.sh --rebuild`. The script refuses to serve stale data rather than letting it look like an application bug. |
| "refusing to run: must end in _sandbox" | Working as intended — the database name is the guard against pointing this at production. |

---

## This is not how to deploy the live site

The steps above deliberately use the test fixtures and generated data. Putting
this branch on the **real** site is a different job:

1. Set `EODHD_API_KEY` in the environment for both cron and the web server.
2. Apply `sql/eodhd_migration.sql` to the production database.
3. Verify the EODHD field names against a live response — step 4 of
   `python/README-eodhd.md`. The mappings in that migration are from EODHD's
   documentation and were never confirmed against real data.
4. Revoke the old marketstack key. It is out of the code but still in the git
   history.
5. Check whether `shares` really has `commission` and `price_paid`. Nothing in
   the application writes them but `new_screen.php` selects them, so if the
   real table lacks them that page is broken in production.
