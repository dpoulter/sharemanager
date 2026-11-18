<?php

    /**
     * constants.php
     * Global constants.
     */

    //Every setting below reads the environment first and falls back to the
    //value in this file. This file is tracked in git, so a deployed host should
    //set SM_DB_PASS and the rest in the environment the web server and cron run
    //under (php-fpm: env[] in the pool config) and leave the file alone. That
    //way a real password is never in a tracked file and never in a diff, and
    //git pull does not conflict with local edits.

    // your database's name
    define("DATABASE", getenv("SM_DB_NAME") ?: "sharemanager");

    // your database's password
    define("PASSWORD", getenv("SM_DB_PASS") ?: "mypassword");

    // your database's server
    define("SERVER", getenv("SM_DB_HOST") ?: "localhost");

    // your database's username
    define("USERNAME", getenv("SM_DB_USER") ?: "myuser");
	
	//smtp host name
	define("SMTP_HOST", getenv("SM_SMTP_HOST") ?: "");
	
	//smtp user name
	define("SMTP_USERNAME", getenv("SM_SMTP_USER") ?: "");
	
	//smtp password
	define("SMTP_PASSWORD", getenv("SM_SMTP_PASS") ?: "");
	
	//smtp port
	define("SMTP_PORT", getenv("SM_SMTP_PORT") ?: "587");
	
	//site_url
	define ("SITE_URL", getenv("SM_SITE_URL") ?: "https://shares.duckdns.org");

    //Which market data provider to use: "eodhd" or "fmp". Both are supported;
    //this picks one. Nothing else branches on it beyond a handful of functions
    //in functions.php and the provider_field column in screen_indicators.
    define("DATA_PROVIDER", getenv("SM_DATA_PROVIDER") ?: "eodhd");

    //Financial Modeling Prep. Read from the environment, as above, so the key
    //is never committed.
    define("FMP_API_KEY", getenv("FMP_API_KEY") ?: "");

    //FMP's stable API. The older /api/v3 paths answer 403 "Legacy Endpoint" on
    //accounts opened after the change, so they are not a fallback.
    define("FMP_BASE_URL", getenv("FMP_BASE_URL") ?: "https://financialmodelingprep.com/stable");

    //FMP addresses London tickers as CODE.L, where EODHD says CODE.LSE and
    //stock_symbols.exchange holds the MIC (XLON). None are interchangeable.
    define("FMP_EXCHANGE", "L");

    //EODHD API key. Read from the environment so the key is never committed:
    //set EODHD_API_KEY in the environment the web server and cron run under.
    //The Python jobs read the same variable directly.
    define("EODHD_API_KEY", getenv("EODHD_API_KEY") ?: "");

    //EODHD API base. Overridable so a sandbox can point at a local stub and
    //exercise the request, the JSON parsing and the adapter rather than
    //skipping them.
    define("EODHD_BASE_URL", getenv("EODHD_BASE_URL") ?: "https://eodhd.com/api");

    //EODHD exchange suffix for London. EODHD addresses LSE tickers as CODE.LSE,
    //while stock_symbols.exchange holds the MIC (XLON) the rest of the
    //application keys on, so the two are not interchangeable.
    define("EODHD_EXCHANGE", "LSE");

    //The exchange a new account starts on. This is the MIC that stock_symbols,
    //historical_prices and statistics are keyed on, not the EODHD ticker suffix
    //above, and the two are not interchangeable.
    define("DEFAULT_EXCHANGE", "XLON");

    //Google News RSS, for the News tab and the outlook prompt. Overridable
    //so the tests can point at a local stub and exercise the real request -
    //including which Connection header it asks for, which is the difference
    //between a quote page that renders in a second and one that takes
    //thirteen. Never set this on a real host.
    define("SM_NEWS_BASE_URL", getenv("SM_NEWS_BASE_URL") ?: "https://news.google.com");

    //-----------------------------------------------------------------------
    // The AI outlook panel. Same pattern as the market data providers above:
    // the key is read from the environment and never committed.
    //
    // php-fpm pool (/etc/php/8.3/fpm/pool.d/sharemanager.conf):
    //     env[ANTHROPIC_API_KEY] = sk-ant-...
    //     env[ANTHROPIC_MODEL]   = claude-sonnet-5-5
    //     env[SM_VAULT_DIR]      = /var/lib/sharemanager/vault
    //
    // Cron (/etc/sharemanager.env, mode 600) needs none of these: nothing
    // generates an outlook on a schedule. Only the Generate button calls the
    // API. See includes/llm.php.
    //-----------------------------------------------------------------------

    //Anthropic API key. Empty means the panel says so and generates nothing;
    //it is never a fatal and never reaches the browser.
    define("ANTHROPIC_API_KEY", getenv("ANTHROPIC_API_KEY") ?: "");

    //Matches the model the server is configured for. A missing environment
    //variable should not quietly move the panel to a different model with
    //different behaviour, different prices and, as it turned out, a
    //different view on forced tool use.
    define("ANTHROPIC_MODEL", getenv("ANTHROPIC_MODEL") ?: "claude-fable-5-2");

    //Overridable so the tests can point at a local stub and exercise the
    //request, the response parsing and the error paths rather than skipping
    //them. Never set this on a real host.
    define("ANTHROPIC_BASE_URL", getenv("ANTHROPIC_BASE_URL") ?: "https://api.anthropic.com");

    //Dollars per million tokens, so the panel can price each generation.
    //
    //All four are claude-fable-5-2's published rates, matching the default
    //model above: $8 input, $40 output, $0.20 cache read, $10 five-minute
    //cache write. They must move together - a set describing two different
    //models prices nothing correctly, and the cache figures are the ones
    //that get forgotten, which under-reports exactly the calls the caching
    //was added to make cheap.
    //
    //These are a default, not a source of truth. Override them when the
    //rates change or ANTHROPIC_MODEL points elsewhere, and check them
    //against a real invoice rather than trusting the panel.
    define("ANTHROPIC_PRICE_INPUT",      getenv("ANTHROPIC_PRICE_INPUT")      ?: "8.00");
    define("ANTHROPIC_PRICE_OUTPUT",     getenv("ANTHROPIC_PRICE_OUTPUT")     ?: "40.00");
    define("ANTHROPIC_PRICE_CACHE_READ", getenv("ANTHROPIC_PRICE_CACHE_READ") ?: "0.20");
    //Writing to the cache costs more than ordinary input and reading back
    //costs far less; that gap is the reason the vault is sent cached at all,
    //so the two are priced apart rather than lumped together. This is the
    //five-minute write rate, which is what cache_control: ephemeral buys.
    define("ANTHROPIC_PRICE_CACHE_WRITE", getenv("ANTHROPIC_PRICE_CACHE_WRITE") ?: "10.00");

    //The news scorer. A thousand headlines a night is the wrong job for the
    //model behind the outlook panel: it is a sentence of classification per
    //headline, twenty at a time, and Haiku does it at a fiftieth of the
    //price. Its own model and its own rates, so the cost line in the log is
    //about the model that actually ran rather than about the one in
    //ANTHROPIC_MODEL.
    //
    //Haiku 5.5: $0.10 in / $0.50 out per million tokens.
    define("NEWS_SCORE_MODEL",        getenv("NEWS_SCORE_MODEL")        ?: "claude-haiku-5-5");
    define("NEWS_PRICE_INPUT",        getenv("NEWS_PRICE_INPUT")        ?: "0.10");
    define("NEWS_PRICE_OUTPUT",       getenv("NEWS_PRICE_OUTPUT")       ?: "0.50");
    define("NEWS_PRICE_CACHE_READ",   getenv("NEWS_PRICE_CACHE_READ")   ?: "0.01");
    define("NEWS_PRICE_CACHE_WRITE",  getenv("NEWS_PRICE_CACHE_WRITE")  ?: "0.125");

    //How many headlines one run may score. The nightly job scores whatever
    //is unscored, and a feed that suddenly returns a thousand articles a
    //symbol would otherwise be a bill rather than a surprise. Anything left
    //over waits for tomorrow.
    define("NEWS_SCORE_MAX_PER_RUN",  (int) (getenv("NEWS_SCORE_MAX_PER_RUN") ?: 1000));

    //Headlines per request. Twenty short titles and a schema fit comfortably
    //inside the token budget, and the per-request overhead - the system
    //prompt, the schema - is then paid once for twenty rather than once each.
    define("NEWS_SCORE_BATCH",        (int) (getenv("NEWS_SCORE_BATCH") ?: 20));

    //Seconds between Google News requests. One a second is gentle; the feed
    //has no published limit and answers 429 when it has had enough.
    define("NEWS_FETCH_INTERVAL",     (float) (getenv("NEWS_FETCH_INTERVAL") ?: 1.0));

    //----------------------------------------------------------------------
    //Regulatory announcements (RNS), from Investegate.
    //----------------------------------------------------------------------

    //Where the announcements come from. Overridable so the suite can point
    //the fetcher at tools/investegate_stub.php rather than the real site: no
    //test makes a live request.
    define("RNS_BASE_URL",  getenv("RNS_BASE_URL") ?: "https://www.investegate.co.uk");

    //Seconds between requests. Two, not one: Investegate is a small site
    //serving full documents rather than a feed, 350 company pages at this
    //rate is twelve minutes, and there is nothing to be gained by going
    //faster than a person reading.
    define("RNS_FETCH_INTERVAL", (float) (getenv("RNS_FETCH_INTERVAL") ?: 2.0));

    //A User-Agent that says what this is and whose it is. A site owner who
    //wants it to stop should be able to tell who to ask; a generic browser
    //string would be hiding.
    define("RNS_USER_AGENT", getenv("RNS_USER_AGENT")
           ?: "sharemanager/1.0 (private portfolio tool; one request per 2s)");

    //How far back the first run goes. After that the nightly run only needs
    //yesterday, but the table starts empty and a month of history is what
    //makes the first "In the news" page worth looking at.
    define("RNS_BACKFILL_DAYS", (int) (getenv("RNS_BACKFILL_DAYS") ?: 30));

    //How much of a document is sent to the scorer. A set of annual results
    //runs to tens of thousands of words of accounting policies; the
    //highlights, the outlook and the chief executive's statement are at the
    //front. Truncation is recorded on the row so a summary built from half a
    //document is not mistaken for one built from all of it.
    define("RNS_BODY_MAX_CHARS", (int) (getenv("RNS_BODY_MAX_CHARS") ?: 12000));

    //How much text a page must hold before it counts as an announcement
    //rather than as the site's limit modal. The modal is a few lines; the
    //shortest real RNS - a one-line board change - still runs to a few
    //hundred characters of boilerplate.
    define("RNS_MIN_BODY_CHARS", (int) (getenv("RNS_MIN_BODY_CHARS") ?: 400));

    //How many announcements one run may score. Lower than the headline cap
    //because each one is a document rather than a title: a request here is
    //thousands of input tokens, not twenty.
    define("RNS_SCORE_MAX_PER_RUN", (int) (getenv("RNS_SCORE_MAX_PER_RUN") ?: 300));

    //How many consecutive fetches may fail, or come back as a limit page,
    //before the run gives up for the night and leaves the rest for tomorrow.
    define("RNS_MAX_REFUSALS", (int) (getenv("RNS_MAX_REFUSALS") ?: 3));
    /* Connection failures are not refusals: the first live run stopped after
       three timeouts in a row and the site answered normally a minute later.
       Each one is retried once; this is how many may fail before the run
       gives up on the night. Higher than the refusal limit, because a
       network that drops one request will drop two, and lower than 350,
       because a run should not hammer a dead link all night. */
    define("RNS_MAX_DROPPED", (int) (getenv("RNS_MAX_DROPPED") ?: 10));
    /* How far back the nightly run refreshes the stored price move. The page
       offers 7 and 30 days, so 31 covers both with a day to spare; older
       announcements keep whatever move they last had, which is what the page
       shows if somebody asks for an older window. */
    define("RNS_MOVE_WINDOW_DAYS", (int) (getenv("RNS_MOVE_WINDOW_DAYS") ?: 31));

    //The first back-off after a refusal, in seconds, multiplied by how many
    //refusals have happened. A site answering 429 wants less traffic, not the
    //same traffic a second later. Zero in the suite, which has no real site
    //to be polite to.
    define("RNS_BACKOFF_SECONDS", (int) (getenv("RNS_BACKOFF_SECONDS") ?: 5));

    //When the London market closes, in London time. An announcement at or
    //after this is measured from the same day's close; one before it - which
    //is most of them, since RNS goes out around 07:00 - is measured from the
    //previous trading day's close.
    define("RNS_MARKET_CLOSE", getenv("RNS_MARKET_CLOSE") ?: "16:30");

    //The zone the market keeps. published_at is stored UTC, and in summer
    //London is an hour ahead of it: a 07:00 BST release is 06:00 UTC, and
    //comparing that against a clock time without converting would put some
    //announcements on the wrong side of the close.
    define("RNS_MARKET_TZ", getenv("RNS_MARKET_TZ") ?: "Europe/London");

    //Output tokens per generation, thinking included. Raise it for a model
    //that thinks at length; it cannot go below 4000, because a budget too
    //small to finish in does not save money, it buys truncated answers.
    define("ANTHROPIC_MAX_TOKENS", (int)(getenv("ANTHROPIC_MAX_TOKENS") ?: 4000));

    //How long to wait for a generation before giving up and saying so.
    define("ANTHROPIC_TIMEOUT", (int)(getenv("ANTHROPIC_TIMEOUT") ?: 90));

    //The investing vault: markdown notes with YAML frontmatter, synced from
    //elsewhere. Missing or empty is normal and the panel still works.
    define("SM_VAULT_DIR", getenv("SM_VAULT_DIR") ?: "/var/lib/sharemanager/vault");

    //Words of notes to send at most. Over this, sector notes are dropped and
    //then strategy notes; policy notes are never dropped.
    define("SM_VAULT_WORD_BUDGET", (int)(getenv("SM_VAULT_WORD_BUDGET") ?: 6000));

    //----------------------------------------------------------------------
    // The broker connection: Trading 212.
    //
    // STAGE 1 IS READ ONLY. No code in this application posts an order to
    // Trading 212, in either environment. The live key Dale created has no
    // Orders - Execute permission, so a live order would be refused at the
    // other end as well; that is a second lock, not the first one.
    //
    // Same pattern as the market data providers above: the key is read from
    // the environment and never committed. Cron reads /etc/sharemanager.env,
    // the website reads the php-fpm pool:
    //
    //     env[SM_T212_ENV]            = live-read
    //     env[SM_T212_API_KEY]        = ...
    //     env[SM_T212_API_SECRET]     = ...
    //
    // With SM_T212_ENV unset or "off" - which is the default, and what a host
    // that has not been configured has - every broker function is a no-op
    // that says so, no request is made, and nothing else in the application
    // changes behaviour.
    //----------------------------------------------------------------------

    //The only broker this application knows about. A value rather than an
    //assumption: the broker_* tables key on it, and a second broker would be
    //a row beside it rather than a migration.
    define("BROKER_T212", "t212");

    //--- the three switches -------------------------------------------------
    //
    // SM_T212_ENV named ONE environment, so the application talked to one
    // account at a time and the practice twin stopped the moment the live
    // account was read. These are independent, so both run at once:
    //
    //     SM_T212_DEMO=on         read and trade the practice account
    //     SM_T212_LIVE_READ=on    read the live account (read-only key)
    //     SM_T212_LIVE_ORDERS=on  send orders to the LIVE account
    //
    // SM_T212_LIVE_ORDERS is off unless it is exactly "on", and off means
    // the live order function refuses before it looks at anything else. It
    // takes a key of its own, which is the only key in this application with
    // Orders - Execute on the real account: the read-only live key cannot
    // place an order even if something tried.
    //
    // A real order also needs the account armed and the batch approved - see
    // includes/README-broker.md. The switch is the first lock, not the only
    // one, and none of the others live in the environment.
    define("T212_ENV", strtolower(trim((string) (getenv("SM_T212_ENV") ?: ""))));

    //"on" and nothing else. A typo must not switch something on.
    //
    //Guarded, because this file is required plainly rather than once in one
    //place and a second include must not turn a warning about a redefined
    //constant into a fatal about a redeclared function.
    if (!function_exists('t212_switch')) {
        function t212_switch($name, $default = false) {
            $value = getenv($name);
            if ($value === false || trim((string) $value) === '') { return $default; }
            return strtolower(trim((string) $value)) === 'on';
        }
    }

    /* SM_T212_ENV is still read, for one release, and mapped onto the
       switches: a host that has not been reconfigured keeps working and the
       log says what was assumed. It can never switch live orders on - it
       never could, and a variable that used to mean "read the live account"
       must not come to mean "trade it". */
    define("T212_ENV_LEGACY", in_array(T212_ENV, ['demo', 'live-read'], true));

    define("T212_DEMO_ON",
           t212_switch("SM_T212_DEMO", T212_ENV === 'demo'));
    define("T212_LIVE_READ_ON",
           t212_switch("SM_T212_LIVE_READ", T212_ENV === 'live-read'));
    define("T212_LIVE_ORDERS_ON", t212_switch("SM_T212_LIVE_ORDERS", false));

    //The live, read-only key. Basic auth: base64(key:secret).
    define("T212_API_KEY",    getenv("SM_T212_API_KEY") ?: "");
    define("T212_API_SECRET", getenv("SM_T212_API_SECRET") ?: "");

    //The demo key, for stage 2. Separate credentials, separate host: a demo
    //key is not accepted by the live API and the other way round.
    define("T212_DEMO_API_KEY",    getenv("SM_T212_DEMO_API_KEY") ?: "");
    define("T212_DEMO_API_SECRET", getenv("SM_T212_DEMO_API_SECRET") ?: "");

    //The live key with Orders - Execute. A THIRD credential, not the
    //read-only one with a permission added: the read-only key stays
    //read-only, so the sync cannot place an order however it is called, and
    //this one is only ever handed to the one function that sends.
    define("T212_LIVE_ORDERS_API_KEY",    getenv("SM_T212_LIVE_ORDERS_API_KEY") ?: "");
    define("T212_LIVE_ORDERS_API_SECRET", getenv("SM_T212_LIVE_ORDERS_API_SECRET") ?: "");

    //The two hosts, which are constants rather than configuration so that no
    //value of any environment variable can point a request at the live host
    //while the application thinks it is talking to the demo one.
    define("T212_LIVE_BASE_URL", "https://live.trading212.com/api/v0");
    define("T212_DEMO_BASE_URL", "https://demo.trading212.com/api/v0");

    //Where a DEMO order is sent, when the suite needs it to go to
    //tools/t212_stub.php instead. This is the only way the order host can be
    //anything but T212_DEMO_BASE_URL, and the order function refuses a value
    //that names the live host whatever is set here. Never set it on a real
    //host.
    define("T212_DEMO_BASE_URL_OVERRIDE", getenv("SM_T212_DEMO_BASE_URL") ?: "");

    //Overridable so the suite can point the client at tools/t212_stub.php and
    //exercise the request, the paging, the rate limit handling and the error
    //paths rather than skipping them. Never set this on a real host: it is
    //the one setting that could send a request somewhere unintended, so the
    //client logs the base URL it used on every run.
    define("T212_BASE_URL", getenv("SM_T212_BASE_URL") ?: "");

    //Seconds to wait for a response. The API is quick; a sync that hangs for
    //a minute per call holds the nightly run up behind it.
    define("T212_TIMEOUT", (int) (getenv("SM_T212_TIMEOUT") ?: 20));

    //Rows per page on the cursor-paged history calls. Trading 212's maximum
    //is 50.
    define("T212_PAGE_LIMIT", (int) (getenv("SM_T212_PAGE_LIMIT") ?: 50));

    //How many pages one call may follow. A cursor that never ends - a bug at
    //either end - would otherwise be an infinite loop inside the nightly job.
    define("T212_MAX_PAGES", (int) (getenv("SM_T212_MAX_PAGES") ?: 40));

    //How long to wait for a 429's reset, at most. The header says when the
    //limit resets; this is the ceiling on believing it. One wait, then the
    //run gives up and tomorrow tries again.
    define("T212_MAX_WAIT", (int) (getenv("SM_T212_MAX_WAIT") ?: 60));

    //How far back the first sync reads the order and dividend history. After
    //that it only needs what is newer than the newest row it holds.
    define("T212_HISTORY_DAYS", (int) (getenv("SM_T212_HISTORY_DAYS") ?: 365));

    //How often the instrument list is refreshed. It is a large response on a
    //one request per 50 seconds limit and it changes when a company lists or
    //leaves, which is not nightly news.
    define("T212_INSTRUMENT_DAYS", (int) (getenv("SM_T212_INSTRUMENT_DAYS") ?: 7));

    //Whether the broker quotes prices in pounds or pence, for London shares.
    //
    //PENCE, settled by the first nine fills on the practice account:
    //currentPrice, averagePricePaid and a fill's price are all pence, and
    //everything under walletImpact - netValue, totalCost, currentValue - is
    //pounds. Getting this wrong is a factor of 100 on every figure.
    //
    //"auto" is still accepted and still works it out by comparing the
    //broker's price for a share against our own close for the same share.
    //With pounds or pence set, that comparison becomes a tripwire instead:
    //a held share whose own close disagrees with the setting is reported as
    //a problem, because one of the two is then wrong.
    define("T212_PRICE_UNIT", strtolower(trim((string) (getenv("SM_T212_PRICE_UNIT") ?: "pence"))));

    //Multiplier on the per-endpoint minimum gap between requests. One means
    //the published limits, which is what a real host wants. Zero in the
    //suite, which is talking to a stub on localhost and has no quota to
    //protect - at a real gap the broker tests alone would take two minutes.
    define("T212_THROTTLE", (float) (getenv("SM_T212_THROTTLE") === false
                                     ? 1.0 : (float) getenv("SM_T212_THROTTLE")));

    // Trading 212's own minimum is GBP 1. Ten is Dale's: an order smaller
    // than that moves a GBP 250 account by four per cent of one holding,
    // and the cash it leaves idle is worth less than the noise it adds.
    define("T212_MIN_ORDER_VALUE", (float) (getenv("SM_T212_MIN_ORDER") ?: 10.00));

    // How many decimal places a quantity may have. NOT YET CONFIRMED against
    // the demo account - the documentation gives no rule and their sell
    // example is -10.5, so two places is a guess that errs small. Confirm it
    // on demo before relying on it, and raise it here if they allow more:
    // rounding DOWN to fewer places than allowed costs a little cash sitting
    // idle, rounding to more than allowed gets the order refused.
    define("T212_QUANTITY_DECIMALS", (int) (getenv("SM_T212_QUANTITY_DECIMALS") ?: 2));

    // The most one run may send, in pounds of consideration. A cap on the
    // damage a bug can do in one go: the account holds GBP 250, so a run
    // that wants to send four figures is wrong about something.
    define("T212_MAX_RUN_VALUE", (float) (getenv("SM_T212_MAX_RUN_VALUE") ?: 1000.00));

    // When the London market is open, in London time. Orders are sent
    // during the session and never outside it: a market order queued
    // overnight fills at whatever the open does, which is not the price
    // anything was decided at.
    define("T212_MARKET_OPEN",  getenv("SM_T212_MARKET_OPEN")  ?: "08:00");
    define("T212_MARKET_SHUT",  getenv("SM_T212_MARKET_SHUT")  ?: "16:30");
    define("T212_MARKET_TZ",    getenv("SM_T212_MARKET_TZ")    ?: "Europe/London");

    // How long an order may sit SENT before the next run goes looking for it
    // at the broker, and how long an UNKNOWN one is given to turn up before
    // it is written off as never having arrived.
    // How long an order may be out and unaccounted for before the sender
    // stops adding to it, and before it gives up and halts the strategy.
    //
    // A market order fills in ten to thirty seconds, so three minutes is
    // already long. The first stage 2 run sent three more orders on each of
    // three passes while the earlier ones still showed as working, which was
    // harmless because every order was distinct and it was a first build -
    // and is exactly how a position gets doubled on a day when it is not.
    define("T212_SETTLE_SECONDS", (int) (getenv("SM_T212_SETTLE_AFTER") ?: 180));
    define("T212_STUCK_SECONDS",  (int) (getenv("SM_T212_STUCK_AFTER")  ?: 1800));

    define("T212_POLL_AFTER_SECONDS",   (int) (getenv("SM_T212_POLL_AFTER") ?: 30));
    define("T212_UNKNOWN_AFTER_SECONDS",(int) (getenv("SM_T212_UNKNOWN_AFTER") ?: 900));

    // Where the live order path posts, when something other than Trading 212
    // has to answer it. For the suite's stub and for nothing else: the live
    // sender refuses the practice host whatever this says, and with this
    // unset the host must be live.trading212.com exactly. Set it and the
    // sender says in its output that it is not talking to Trading 212.
    define("T212_LIVE_ORDERS_BASE_URL", getenv("SM_T212_LIVE_ORDERS_BASE_URL") ?: "");

    // ---------------------------------------------------------------------
    // The live account's caps.
    //
    // These are literals, with no getenv() in front of them, and that is
    // the point: a cap an environment variable can widen is a cap that a
    // typo in a cron wrapper can remove. Everything else about Trading 212
    // here is a setting; the most real money this application may ever put
    // at risk is not.
    //
    // Nothing is sent unless the account has been armed from its page
    // (includes/broker_live.php), and arming states its own capital cap -
    // GBP 250 for the account Dale opened this for. The figure below is the
    // ceiling on what arming may state, not the amount.
    // ---------------------------------------------------------------------

    // The most a live strategy may ever have invested plus reserved,
    // whatever cash the Trading 212 account holds. A deposit does not raise
    // it: the strategy's cash is its own ledger.
    define("T212_LIVE_CAPITAL_CAP_MAX", 500.00);

    // The most one order may be, as a fraction of the capital cap. Fifteen
    // names of a GBP 250 account are about 6.7% each, so 15% is twice the
    // size of anything the engine should be planning: an order above it is
    // a sizing bug rather than a big position.
    define("T212_LIVE_PER_ORDER_FRACTION", 0.15);

    // The most one sending run may send, in pounds of consideration. The
    // practice account's figure is T212_MAX_RUN_VALUE (GBP 1,000); a live
    // run that wants more than this is wrong about something, and the right
    // answer is to stop with the orders still PENDING.
    define("T212_LIVE_MAX_RUN_VALUE", 150.00);

    // How many live orders may be sent in one day, counting every strategy's
    // and whatever became of them. A rebalance of fifteen names is thirty
    // orders at its very worst (fifteen sells and fifteen buys).
    define("T212_LIVE_ORDERS_PER_DAY", 30);

    // How long arming lasts unless a shorter time is asked for, and the
    // longest it may ever last. A forgotten account disarms itself: the
    // safe direction is off.
    define("T212_LIVE_ARM_DAYS", 35);
    define("T212_LIVE_ARM_DAYS_MAX", 90);

    //Where the probe writes the raw responses it was run to collect. Outside
    //the web root: the file holds the account's positions.
    define("T212_PROBE_DIR", getenv("SM_T212_PROBE_DIR") ?: "/var/lib/sharemanager/probe");

    //Debug Flag. Set to "Y" to have debug_log() write per row tracing to
    //message_log. Leave off for normal running: the batch jobs generate tens of
    //thousands of rows per run with it enabled. Anything worth keeping should
    //use write_log(), which is never gated.
    define ("DEBUG_LOG","N");

	

/* No closing tag. PHP swallows exactly one newline after "?>" and emits
   every byte after that, so a blank line here put a newline at the front of
   every response - including the graph endpoints, where a leading byte
   before the PNG header made the browser show a broken image. Leaving the
   tag off means there is nothing after it to get wrong. */
