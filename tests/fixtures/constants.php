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
    define("ANTHROPIC_PRICE_INPUT",       getenv('ANTHROPIC_PRICE_INPUT')       ?: "2.00");
    define("ANTHROPIC_PRICE_OUTPUT",      getenv('ANTHROPIC_PRICE_OUTPUT')      ?: "10.00");
    define("ANTHROPIC_PRICE_CACHE_READ",  getenv('ANTHROPIC_PRICE_CACHE_READ')  ?: "0.10");
    define("ANTHROPIC_PRICE_CACHE_WRITE", getenv('ANTHROPIC_PRICE_CACHE_WRITE') ?: "2.50");
    define("ANTHROPIC_TIMEOUT", (int)(getenv('ANTHROPIC_TIMEOUT') ?: 90));
    define("ANTHROPIC_MAX_TOKENS", (int)(getenv('ANTHROPIC_MAX_TOKENS') ?: 4000));

    define("SM_VAULT_DIR", getenv('SM_VAULT_DIR') ?: "");
    define("SM_VAULT_WORD_BUDGET", (int)(getenv('SM_VAULT_WORD_BUDGET') ?: 6000));

    // Per row tracing. The suite runs with this off by default and flips it on
    // via SM_TEST_DEBUG_LOG to assert that the gate works in both directions.
    define("DEBUG_LOG", getenv('SM_TEST_DEBUG_LOG') === 'Y' ? 'Y' : 'N');

?>
