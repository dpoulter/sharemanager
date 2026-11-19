<?php

	/**
	 * Step a strategy down a stage: stop practice, stop live, or archive it.
	 * Nothing is deleted, sold, cancelled or sent.
	 *
	 *   stop_practice  switches the practice account off. Its positions stay
	 *                  at the broker: nothing is sold.
	 *   stop_live      is the existing disarm, broker_live_disarm(). This adds
	 *                  no second way to disarm.
	 *   archive        marks the strategy archived. Its rules, runs, orders
	 *                  and accounts all stay; the night run skips it. Refused
	 *                  while any account of the strategy is switched on or
	 *                  armed.
	 *
	 * A web POST by a logged in user, and the step has to be open in
	 * strategy_stage() - asked on the server, so a crafted POST for a closed
	 * step fails here. No password: every one of these is the safe direction.
	 * It never clears a halt.
	 *
	 * Writes strategy_accounts (stop practice), strategy_rules (archive) and,
	 * through the existing disarm, the live arming and its log.
	 */

	require_once("constants.php");
	require_once(__DIR__ . "/strategy_functions.php");
	require_once(__DIR__ . "/strategy_stage.php");

	/** The steps, and the stage move each one is. */
	function stage_stepdown_steps() {
		return ['stop_practice' => 'stop_practice', 'stop_live' => 'stop_live', 'archive' => 'archive'];
	}

	/**
	 * $options: user_id, strategy, step.
	 * Returns ['ok'=>bool, 'why'=>string, 'words'=>what the page says when ok].
	 */
	function stage_stepdown($options = []) {

		$refuse = function ($why) {
			write_log("stage_stepdown", "refused: " . $why);
			return ['ok' => false, 'why' => $why, 'words' => ''];
		};

		if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
			return $refuse("stepping down is done on the strategy's page, in a logged in session.");
		}
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
			return $refuse("stepping down is a form, so it is a POST and nothing else.");
		}

		$user_id = isset($options['user_id']) ? (int) $options['user_id'] : 0;
		$users = $user_id > 0 ? query("select username from users where id=?", $user_id) : [];
		if (!count($users)) { return $refuse("stepping down needs somebody logged in."); }
		$who = (string) $users[0]['username'];

		$strategy = (string) ($options['strategy'] ?? '');
		$step = (string) ($options['step'] ?? '');
		if (!isset(stage_stepdown_steps()[$step])) { return $refuse("that is not a step down."); }

		$stage = strategy_stage($strategy);
		if ($stage === null) { return $refuse("there is no strategy called that."); }

		$move = $stage['moves'][stage_stepdown_steps()[$step]];
		if (!$move['open']) {
			return $refuse("that step is closed: " . $move['reason'] . ".");
		}

		if ($step === 'stop_practice') {
			query("update strategy_accounts set enabled='N' where strategy=? and mode=?",
			      $strategy, STRATEGY_MODE_T212_DEMO);
			write_log("stage_stepdown", $strategy . " practice stopped by " . $who);
			return ['ok' => true, 'why' => '',
			        'words' => "Practice stopped: the account is switched off. Its positions stay at the"
			                 . " broker; nothing was sold."];
		}

		if ($step === 'stop_live') {
			broker_live_disarm($strategy, $who, "stepped down from the strategy's page");
			return ['ok' => true, 'why' => '',
			        'words' => "Live stopped: the account is disarmed. Orders are planned and not sent until"
			                 . " it is armed again."];
		}

		query("update strategy_rules set archived_at=now() where strategy=?", $strategy);
		write_log("stage_stepdown", $strategy . " archived by " . $who);
		return ['ok' => true, 'why' => '',
		        'words' => "Archived. Its rules, runs, orders and accounts are all kept; the night run skips it."];
	}

/* No closing tag: see the note at the end of constants.php. */
