<?php

	/**
	 * Backtest settings on the sharemanager side, each with one owner.
	 *
	 * Every backtest runs on the Mac mini research engine. Sharemanager only
	 * queues a run (backtest_runs.php, the backtest_queue module) and hands it
	 * out through public/backtest_api.php (the backtest_api module).
	 *
	 * The holdout start is the sharemanager copy of the engine's own setting
	 * (research/config.yaml periods.holdout_start, read by periods.py). It is
	 * written here and nowhere else in sharemanager: the page and the API ask
	 * backtest_runs.php, which reads this constant.
	 */

	/* The 18-month holdout starts here. A run whose end date is on or after it
	   is refused: the final exam is run by hand on the Mac mini, never from here. */
	if (!defined('BACKTEST_HOLDOUT_START')) { define('BACKTEST_HOLDOUT_START', '2025-05-18'); }

	/* The engine's monthly universe starts here (market caps from 2007). An
	   earlier start would be refused by the engine anyway; refusing it at the
	   form says so before it waits in the queue. */
	if (!defined('BACKTEST_EARLIEST')) { define('BACKTEST_EARLIEST', '2007-01-01'); }

	/* A claimed run still RUNNING this long after its claim is marked failed
	   with that reason, and is never handed out again. */
	if (!defined('BACKTEST_TIME_LIMIT_MINUTES')) {
		define('BACKTEST_TIME_LIMIT_MINUTES', (int) (getenv('SM_BACKTEST_TIME_LIMIT_MINUTES') ?: 120));
	}

	/* Where reports posted by the Mac mini are kept, one file per run, named
	   by the server. Outside the web root. */
	if (!defined('BACKTEST_REPORTS_DIR')) {
		define('BACKTEST_REPORTS_DIR', getenv('SM_BACKTEST_REPORTS_DIR') ?: '/var/lib/sharemanager/backtest_reports');
	}

	/* The largest report accepted, in bytes. */
	if (!defined('BACKTEST_REPORT_MAX_BYTES')) { define('BACKTEST_REPORT_MAX_BYTES', 8 * 1024 * 1024); }

	/* The environment file that holds the API token, and the key it is under. */
	if (!defined('BACKTEST_ENV_FILE')) { define('BACKTEST_ENV_FILE', getenv('SM_ENV_FILE') ?: '/etc/sharemanager.env'); }
	if (!defined('BACKTEST_TOKEN_KEY')) { define('BACKTEST_TOKEN_KEY', 'SM_BACKTEST_API_TOKEN'); }

/* No closing tag: see the note at the end of constants.php. */
