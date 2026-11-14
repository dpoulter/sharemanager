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

    // Per row tracing. The suite runs with this off by default and flips it on
    // via SM_TEST_DEBUG_LOG to assert that the gate works in both directions.
    define("DEBUG_LOG", getenv('SM_TEST_DEBUG_LOG') === 'Y' ? 'Y' : 'N');

?>
