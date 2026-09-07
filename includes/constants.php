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

    //Debug Flag. Set to "Y" to have debug_log() write per row tracing to
    //message_log. Leave off for normal running: the batch jobs generate tens of
    //thousands of rows per run with it enabled. Anything worth keeping should
    //use write_log(), which is never gated.
    define ("DEBUG_LOG","N");

	

?>

