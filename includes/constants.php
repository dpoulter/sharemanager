<?php

    /**
     * constants.php
     * Global constants.
     */

    // your database's name
    define("DATABASE", "sharemanager");

    // your database's password
    define("PASSWORD", "mypassword");

    // your database's server
    define("SERVER", "localhost");

    // your database's username
    define("USERNAME", "myuser");
	
	//smtp host name
	define("SMTP_HOST","");
	
	//smtp user name
	define("SMTP_USERNAME","");
	
	//smtp password
	define("SMTP_PASSWORD","");
	
	//smtp port
	define("SMTP_PORT","587");
	
	//site_url
	define ("SITE_URL","https://shares.duckdns.org");

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

