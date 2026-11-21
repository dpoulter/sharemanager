<?php

	/**
	 * The nightly job's wait for the Mac mini (contract nightly_job, 21 Nov 2026).
	 *
	 *   php data_publish_wait.php [--today=YYYY-MM-DD] [--until=HH:MM] [--poll=SECONDS] [--once]
	 *
	 * --once checks once and does not wait (by hand, and the tests).
	 *
	 * Called by refresh_all.sh, in place of the price fetch, before anything
	 * keys on the as of date. Polls data_publishes for a closed publish whose
	 * latest date is the latest trading day - the last weekday before today
	 * that is not an England and Wales bank holiday - until 03:30 (--until).
	 *
	 *   exit 0   the publish is in: plan the strategies.
	 *   exit 3   none by the deadline: refresh_all.sh plans no strategy tonight.
	 *            The skip is logged (message_log, module nightly_job) with the
	 *            reason, and Dale is emailed through the existing mail path
	 *            (PHPMailer and the SM_SMTP_* settings, as the password reset
	 *            uses) at SM_ALERT_EMAIL, or the first user's address when
	 *            that is not set. A mail that cannot be sent is logged as such.
	 *
	 * Never fetches anything itself: no publish means no planning, never a
	 * fallback to the N100's own fetch (Dale, 21 Nov 2026).
	 */

	require_once("constants.php");
	require_once("functions.php");
	require_once(__DIR__ . "/data_import.php");

	/* England and Wales bank holidays, when the London market is shut. Add the
	   next year's before it starts. */
	if (!defined('DATA_MARKET_HOLIDAYS')) {
		define('DATA_MARKET_HOLIDAYS', [
			'2025-01-01', '2025-04-18', '2025-04-21', '2025-05-05', '2025-05-26', '2025-08-25', '2025-12-25', '2025-12-26',
			'2026-01-01', '2026-04-03', '2026-04-06', '2026-05-04', '2026-05-25', '2026-08-31', '2026-12-25', '2026-12-28',
			'2027-01-01', '2027-03-26', '2027-03-29', '2027-05-03', '2027-05-31', '2027-08-30', '2027-12-27', '2027-12-28',
		]);
	}

	/** The latest trading day before $today (Y-m-d). */
	function data_wait_expected_day($today) {
		$d = strtotime($today . ' 12:00:00');
		do {
			$d -= 86400;
			$ymd = date('Y-m-d', $d);
		} while ((int) date('N', $d) >= 6 || in_array($ymd, DATA_MARKET_HOLIDAYS, true));
		return $ymd;
	}

	/** The closed publish that covers $day, or null. */
	function data_wait_publish_for($day) {
		if (!data_import_ready()) { return null; }
		$rows = query("select * from data_publishes where latest_date >= ? order by id desc limit 1", $day);
		return (is_array($rows) && count($rows)) ? $rows[0] : null;
	}

	/** Tell Dale. Returns a sentence saying how it went (logged by the caller). */
	function data_wait_tell_dale($subject, $text) {

		$to = trim((string) getenv('SM_ALERT_EMAIL'));
		if ($to === '') {
			$rows = query("select email from users where email is not null and email<>'' order by id limit 1");
			$to = (is_array($rows) && count($rows)) ? (string) $rows[0]['email'] : '';
		}
		if ($to === '') { return "no email sent: no SM_ALERT_EMAIL and no user with an address"; }
		if (SMTP_HOST === '') { return "no email sent: SM_SMTP_HOST is not set"; }

		require_once(__DIR__ . "/PHPMailerAutoload.php");
		$mail = new PHPMailer;
		$mail->isSMTP();
		$mail->Host = SMTP_HOST;
		$mail->SMTPAuth = true;
		$mail->Username = SMTP_USERNAME;
		$mail->Password = SMTP_PASSWORD;
		$mail->SMTPSecure = 'tls';
		$mail->Port = SMTP_PORT;
		$mail->setFrom('dale.poulter@yandex.com', 'Share Portfolio Manager');
		$mail->addAddress($to);
		$mail->isHTML(false);
		$mail->Subject = $subject;
		$mail->Body = $text;
		return $mail->send() ? "emailed" : "the email failed: " . $mail->ErrorInfo;
	}

	/* ---- run */

	if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {

		$opts = ['today' => date('Y-m-d'), 'until' => getenv('SM_DATA_WAIT_UNTIL') ?: '03:30', 'poll' => 60, 'once' => false];
		foreach (array_slice($argv, 1) as $arg) {
			if ($arg === '--once') { $opts['once'] = true; continue; }
			if (preg_match('~^--(today|until|poll)=(.+)$~', $arg, $m)) { $opts[$m[1]] = $m[2]; continue; }
			fwrite(STDERR, "data_publish_wait: unknown argument $arg\n");
			exit(2);
		}
		if (!data_import_valid_date($opts['today']) || !preg_match('~^\d{2}:\d{2}$~', $opts['until'])) {
			fwrite(STDERR, "data_publish_wait: --today is Y-m-d and --until is HH:MM\n");
			exit(2);
		}

		$day = data_wait_expected_day($opts['today']);
		$deadline = strtotime($opts['today'] . ' ' . $opts['until'] . ':00');
		$poll = max(1, (int) $opts['poll']);

		while (true) {
			$pub = data_wait_publish_for($day);
			if ($pub !== null) {
				$msg = "publish " . $pub['publish_id'] . " (data version " . $pub['data_version'] . ", latest date "
				     . $pub['latest_date'] . ", closed " . $pub['closed_at'] . ") covers $day; the strategies are planned";
				write_log("nightly_job", $msg);
				echo "data_publish_wait: $msg\n";
				exit(0);
			}
			if ($opts['once'] || time() + $poll > $deadline) { break; }
			sleep($poll);
		}

		$last = data_import_ready() ? data_import_last_publish() : null;
		$why = "no strategy planned tonight: no complete publish from the Mac mini for $day by "
		     . $opts['until'] . " (" . ($last === null ? "no publish has ever closed"
		        : "the newest closed publish is " . $last['publish_id'] . ", latest date " . $last['latest_date']
		          . ", closed " . $last['closed_at']) . "). Nothing was fetched in its place.";
		$told = data_wait_tell_dale("Sharemanager: no strategies planned tonight", $why);
		write_log("nightly_job", $why . " Dale: " . $told . ".");
		fwrite(STDERR, "data_publish_wait: $why ($told)\n");
		exit(3);
	}

/* No closing tag: see the note at the end of constants.php. */
