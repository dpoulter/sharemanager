# Running the sandbox on your server

Puts a browsable copy of the site on your own machine, with generated data,
completely separate from the live site at `/var/www/shares`.

Nothing here touches production: different directory, different database,
different database user, and no cron entries. The one thing you must get right
is keeping it that way, so the ordering below matters.

---

## 1. Check what you have

```sh
php -v                                  # 8.0 or newer
php -m | grep -E 'pdo_mysql|mysqli'     # pdo_mysql must be listed
mysql --version                         # or mariadb --version
git --version
```

If `php -v` reports 7.x, everything still works, but note that
`includes/PHPMailerAutoload.php` was only fixed for PHP 8 on this branch — it
was a parse error there, which is what broke password reset.

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

```sh
cd /var/www/shares-sandbox
export SM_SANDBOX_DB=sharemanager_sandbox
export SM_SANDBOX_HOST=127.0.0.1
export SM_SANDBOX_USER=sandbox
export SM_SANDBOX_PASS='pick-something-random'

tools/sandbox.sh --no-serve --rebuild
```

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

- **Charts.** `chart.php`, `stockgraph.php`, `performance_graph.php` and
  `jpgraph.php` need `phpChart_Lite/`, which is not in the repository.
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
