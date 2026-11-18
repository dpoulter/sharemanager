<?php

    /**
     * Test constants.php.
     *
     * Placed first on the include path by run_tests.sh so that every relative
     * require("constants.php") in the application resolves here instead of
     * includes/constants.php. Values come from the environment so no test
     * credentials are committed.
     */

    define("DATABASE", getenv('SM_TEST_DB')   ?: 'sharemanager_test');
    define("SERVER",   getenv('SM_TEST_HOST') ?: '127.0.0.1');
    define("USERNAME", getenv('SM_TEST_USER') ?: 'smtest');
    define("PASSWORD", getenv('SM_TEST_PASS') ?: 'smtest');

    define("SMTP_HOST", "");
    define("SMTP_USERNAME", "");
    define("SMTP_PASSWORD", "");
    define("SMTP_PORT", "587");
    define("SITE_URL", "");

    // Provider selection. The suite exercises both mappings directly, so the
    // default here only decides which one the served pages would use.
    define("DATA_PROVIDER",  getenv('SM_DATA_PROVIDER') ?: 'eodhd');
    define("FMP_API_KEY",    getenv('FMP_API_KEY') ?: "");
    define("FMP_BASE_URL",   getenv('FMP_BASE_URL') ?: "https://financialmodelingprep.com/stable");
    define("FMP_EXCHANGE",   "L");

    // EODHD. No key in tests: nothing here calls the live API, and an empty
    // key is the documented "feature unavailable" state rather than an error.
    define("EODHD_API_KEY", getenv('EODHD_API_KEY') ?: "");
    define("EODHD_EXCHANGE", "LSE");
    define("EODHD_BASE_URL", getenv('EODHD_BASE_URL') ?: "https://eodhd.com/api");

    // Google News RSS. The suite points this at tools/news_stub.php, which
    // behaves like a server that honours Keep-Alive, so the timeout the old
    // header caused is reproduced rather than described.
    define("SM_NEWS_BASE_URL", getenv('SM_NEWS_BASE_URL') ?: "https://news.google.com");

    // The AI outlook. There is no real key here and never should be: the
    // suite points ANTHROPIC_BASE_URL at tools/anthropic_stub.php and asserts
    // the request shape, the usage accounting and every error path against
    // that. An empty key is itself one of the cases under test.
    define("ANTHROPIC_API_KEY", getenv('ANTHROPIC_API_KEY') ?: "");
    define("ANTHROPIC_MODEL",   getenv('ANTHROPIC_MODEL') ?: "claude-sonnet-5-5");
    define("ANTHROPIC_BASE_URL", getenv('ANTHROPIC_BASE_URL') ?: "https://api.anthropic.com");
    // The same four rates the application ships, so the cost arithmetic is
    // tested against the model it actually defaults to.
    define("ANTHROPIC_PRICE_INPUT",       getenv('ANTHROPIC_PRICE_INPUT')       ?: "8.00");
    define("ANTHROPIC_PRICE_OUTPUT",      getenv('ANTHROPIC_PRICE_OUTPUT')      ?: "40.00");
    define("ANTHROPIC_PRICE_CACHE_READ",  getenv('ANTHROPIC_PRICE_CACHE_READ')  ?: "0.20");
    define("ANTHROPIC_PRICE_CACHE_WRITE", getenv('ANTHROPIC_PRICE_CACHE_WRITE') ?: "10.00");

    //Mirrors includes/constants.php; see the note there.
    define("NEWS_SCORE_MODEL",        getenv("NEWS_SCORE_MODEL")        ?: "claude-haiku-5-5");
    define("NEWS_PRICE_INPUT",        getenv("NEWS_PRICE_INPUT")        ?: "0.10");
    define("NEWS_PRICE_OUTPUT",       getenv("NEWS_PRICE_OUTPUT")       ?: "0.50");
    define("NEWS_PRICE_CACHE_READ",   getenv("NEWS_PRICE_CACHE_READ")   ?: "0.01");
    define("NEWS_PRICE_CACHE_WRITE",  getenv("NEWS_PRICE_CACHE_WRITE")  ?: "0.125");
    define("NEWS_SCORE_MAX_PER_RUN",  (int) (getenv("NEWS_SCORE_MAX_PER_RUN") ?: 1000));
    define("NEWS_SCORE_BATCH",        (int) (getenv("NEWS_SCORE_BATCH") ?: 20));
    define("NEWS_FETCH_INTERVAL",     (float) (getenv("NEWS_FETCH_INTERVAL") ?: 1.0));
    // Regulatory announcements. RNS_BASE_URL points at the stub in the suite.
    define("RNS_BASE_URL",  getenv("RNS_BASE_URL") ?: "https://www.investegate.co.uk");
    define("RNS_FETCH_INTERVAL", (float) (getenv("RNS_FETCH_INTERVAL") ?: 2.0));
    define("RNS_USER_AGENT", getenv("RNS_USER_AGENT")
           ?: "sharemanager/1.0 (private portfolio tool; one request per 2s)");
    define("RNS_BACKFILL_DAYS", (int) (getenv("RNS_BACKFILL_DAYS") ?: 30));
    define("RNS_BODY_MAX_CHARS", (int) (getenv("RNS_BODY_MAX_CHARS") ?: 12000));
    //How much text a page must hold before it counts as an announcement
    //rather than as the site's limit modal. The modal is a few lines; the
    //shortest real RNS - a one-line board change - still runs to a few
    //hundred characters of boilerplate.
    define("RNS_MIN_BODY_CHARS", (int) (getenv("RNS_MIN_BODY_CHARS") ?: 400));

    define("RNS_SCORE_MAX_PER_RUN", (int) (getenv("RNS_SCORE_MAX_PER_RUN") ?: 300));
    define("RNS_MAX_REFUSALS", (int) (getenv("RNS_MAX_REFUSALS") ?: 3));
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

    define("RNS_MARKET_CLOSE", getenv("RNS_MARKET_CLOSE") ?: "16:30");
    define("RNS_MARKET_TZ", getenv("RNS_MARKET_TZ") ?: "Europe/London");

    define("ANTHROPIC_TIMEOUT", (int)(getenv('ANTHROPIC_TIMEOUT') ?: 90));
    define("ANTHROPIC_MAX_TOKENS", (int)(getenv('ANTHROPIC_MAX_TOKENS') ?: 4000));

    define("SM_VAULT_DIR", getenv('SM_VAULT_DIR') ?: "");
    define("SM_VAULT_WORD_BUDGET", (int)(getenv('SM_VAULT_WORD_BUDGET') ?: 6000));

    // The broker connection. Off by default, which is the state of a host
    // nobody has given a key to - and the state the suite asserts makes no
    // network call at all. SM_T212_BASE_URL points at tools/t212_stub.php
    // where a test wants the request, the paging and the error paths
    // exercised rather than skipped; no test talks to Trading 212.
    define("BROKER_T212", "t212");
    define("T212_ENV", strtolower(trim((string) (getenv("SM_T212_ENV") ?: ""))));
    if (!function_exists('t212_switch')) {
        function t212_switch($name, $default = false) {
            $value = getenv($name);
            if ($value === false || trim((string) $value) === '') { return $default; }
            return strtolower(trim((string) $value)) === 'on';
        }
    }
    define("T212_ENV_LEGACY", in_array(T212_ENV, ['demo', 'live-read'], true));
    define("T212_DEMO_ON",      t212_switch("SM_T212_DEMO",      T212_ENV === 'demo'));
    define("T212_LIVE_READ_ON", t212_switch("SM_T212_LIVE_READ", T212_ENV === 'live-read'));
    /* Off unless the test says otherwise, and the suite asserts that off
       means no POST reaches the live host whatever else is set. */
    define("T212_LIVE_ORDERS_ON", t212_switch("SM_T212_LIVE_ORDERS", false));
    define("T212_API_KEY",    getenv("SM_T212_API_KEY") ?: "");
    define("T212_API_SECRET", getenv("SM_T212_API_SECRET") ?: "");
    define("T212_DEMO_API_KEY",    getenv("SM_T212_DEMO_API_KEY") ?: "");
    define("T212_DEMO_API_SECRET", getenv("SM_T212_DEMO_API_SECRET") ?: "");
    define("T212_LIVE_ORDERS_API_KEY",    getenv("SM_T212_LIVE_ORDERS_API_KEY") ?: "");
    define("T212_LIVE_ORDERS_API_SECRET", getenv("SM_T212_LIVE_ORDERS_API_SECRET") ?: "");
    define("T212_LIVE_BASE_URL", "https://live.trading212.com/api/v0");
    define("T212_DEMO_BASE_URL", "https://demo.trading212.com/api/v0");
    define("T212_BASE_URL", getenv("SM_T212_BASE_URL") ?: "");
    define("T212_DEMO_BASE_URL_OVERRIDE", getenv("SM_T212_DEMO_BASE_URL") ?: "");
    define("T212_TIMEOUT", (int) (getenv("SM_T212_TIMEOUT") ?: 20));
    define("T212_PAGE_LIMIT", (int) (getenv("SM_T212_PAGE_LIMIT") ?: 50));
    define("T212_MAX_PAGES", (int) (getenv("SM_T212_MAX_PAGES") ?: 40));
    define("T212_MAX_WAIT", (int) (getenv("SM_T212_MAX_WAIT") ?: 60));
    define("T212_HISTORY_DAYS", (int) (getenv("SM_T212_HISTORY_DAYS") ?: 365));
    define("T212_INSTRUMENT_DAYS", (int) (getenv("SM_T212_INSTRUMENT_DAYS") ?: 7));
    define("T212_PRICE_UNIT", strtolower(trim((string) (getenv("SM_T212_PRICE_UNIT") ?: "pence"))));
    define("T212_PROBE_DIR", getenv("SM_T212_PROBE_DIR") ?: sys_get_temp_dir());
    // Stage 2: what a Trading 212 account trades by.
    define("T212_MIN_ORDER_VALUE", (float) (getenv("SM_T212_MIN_ORDER") ?: 10.00));
    define("T212_QUANTITY_DECIMALS", (int) (getenv("SM_T212_QUANTITY_DECIMALS") ?: 2));
    define("T212_MAX_RUN_VALUE", (float) (getenv("SM_T212_MAX_RUN_VALUE") ?: 1000.00));
    // Where the live order path posts, for the suite's stub. The sender
    // refuses the practice host whatever this says, and with it unset the
    // host must be live.trading212.com exactly.
    define("T212_LIVE_ORDERS_BASE_URL", getenv("SM_T212_LIVE_ORDERS_BASE_URL") ?: "");
    // The live account's caps: literals in the application too, because a
    // cap an environment variable can widen is not a cap. The suite uses
    // the same figures the server does.
    define("T212_LIVE_CAPITAL_CAP_MAX", 500.00);
    define("T212_LIVE_PER_ORDER_FRACTION", 0.15);
    define("T212_LIVE_MAX_RUN_VALUE", 150.00);
    define("T212_LIVE_ORDERS_PER_DAY", 30);
    define("T212_LIVE_ARM_DAYS", 35);
    define("T212_LIVE_ARM_DAYS_MAX", 90);
    define("T212_MARKET_OPEN",  getenv("SM_T212_MARKET_OPEN")  ?: "08:00");
    define("T212_MARKET_SHUT",  getenv("SM_T212_MARKET_SHUT")  ?: "16:30");
    define("T212_MARKET_TZ",    getenv("SM_T212_MARKET_TZ")    ?: "Europe/London");
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

    define("T212_POLL_AFTER_SECONDS",    (int) (getenv("SM_T212_POLL_AFTER") ?: 30));
    define("T212_UNKNOWN_AFTER_SECONDS", (int) (getenv("SM_T212_UNKNOWN_AFTER") ?: 900));
    // Zero, so the suite does not wait out the published gaps between calls
    // against a stub on localhost. One on a real host.
    define("T212_THROTTLE", (float) (getenv("SM_T212_THROTTLE") === false
                                     ? 1.0 : (float) getenv("SM_T212_THROTTLE")));

    // Per row tracing. The suite runs with this off by default and flips it on
    // via SM_TEST_DEBUG_LOG to assert that the gate works in both directions.
    define("DEBUG_LOG", getenv('SM_TEST_DEBUG_LOG') === 'Y' ? 'Y' : 'N');

?>
