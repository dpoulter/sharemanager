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

    // Per row tracing. The suite runs with this off by default and flips it on
    // via SM_TEST_DEBUG_LOG to assert that the gate works in both directions.
    define("DEBUG_LOG", getenv('SM_TEST_DEBUG_LOG') === 'Y' ? 'Y' : 'N');

?>
