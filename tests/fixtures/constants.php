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

    // Per row tracing. The suite runs with this off by default and flips it on
    // via SM_TEST_DEBUG_LOG to assert that the gate works in both directions.
    define("DEBUG_LOG", getenv('SM_TEST_DEBUG_LOG') === 'Y' ? 'Y' : 'N');

?>
