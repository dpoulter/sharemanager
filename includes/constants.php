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
