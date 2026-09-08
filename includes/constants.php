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

    //Debug Flag. Set to "Y" to have debug_log() write per row tracing to
    //message_log. Leave off for normal running: the batch jobs generate tens of
    //thousands of rows per run with it enabled. Anything worth keeping should
    //use write_log(), which is never gated.
    define ("DEBUG_LOG","N");

	

?>

