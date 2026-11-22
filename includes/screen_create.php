<?php

	/**
	 * screen_create (contracts/modules.yaml): add an empty screen.
	 *
	 * The one place a row is put into `screen`. It takes a name and a
	 * description and gives back the new id, or a refusal with the reason.
	 * What it makes is a displayed screen with no criteria: no rows in
	 * screen_build, last_run null. Criteria are added afterwards on the
	 * screen's Edit page, with the picker that is already there.
	 *
	 * A web POST by a logged in user and nothing else: never a job, never the
	 * command line, never a GET. Every field is checked here, on the server,
	 * whatever the browser did. On a refusal nothing is written at all.
	 *
	 * It never creates or changes a strategy, and never touches another
	 * screen or its criteria. The name and the description reach the
	 * database as parameters only.
	 *
	 * Two submits of the same name make one screen: the check that no
	 * displayed screen has the name, and the insert, are one transaction
	 * taken under a named database lock (SCREEN_CREATE_LOCK), so a second
	 * submit waits for the first and then finds the name used. A unique key
	 * was the other way and was not taken: the key would have to be on the
	 * name of displayed screens only, which needs a generated column, and the
	 * migration would fail on a production table that already holds two
	 * displayed screens of one name - the schema in tests/ is a
	 * reconstruction, so that cannot be ruled out from here. The lock needs
	 * no migration and does not depend on the table's storage engine.
	 *
	 * screen_view.php stamps last_run when a screen is run. That is the one
	 * other writer of `screen`, and it only ever updates that column.
	 */

	require_once("constants.php");
	require_once("functions.php");

	if (!defined('SCREEN_NAME_MAX'))        { define('SCREEN_NAME_MAX', 100); }
	if (!defined('SCREEN_DESCRIPTION_MAX')) { define('SCREEN_DESCRIPTION_MAX', 255); }
	if (!defined('SCREEN_CREATE_LOCK'))     { define('SCREEN_CREATE_LOCK', 'sharemanager_screen_create'); }

	/**
	 * $options: user_id, name, description.
	 *
	 * Returns ['ok'=>true, 'id'=>int] or ['ok'=>false, 'why'=>string].
	 */
	function screen_create($options = []) {

		$refuse = function ($why) { return ['ok' => false, 'why' => $why]; };

		if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
			return $refuse("a screen is created by a person on the screens page, in their own"
			             . " logged in session. The command line cannot create one.");
		}
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
			return $refuse("creating a screen is a form, so it is a POST and nothing else.");
		}

		$user_id = isset($options['user_id']) ? (int) $options['user_id'] : 0;
		$users = $user_id > 0 ? query("select id from users where id=?", $user_id) : [];
		if (!is_array($users) || !count($users)) { return $refuse("creating a screen needs somebody logged in."); }

		$name = isset($options['name']) && is_string($options['name']) ? trim($options['name']) : '';
		$description = isset($options['description']) && is_string($options['description'])
		             ? trim($options['description']) : '';

		if (!mb_check_encoding($name, 'UTF-8') || !mb_check_encoding($description, 'UTF-8')) {
			return $refuse("the name and the description have to be plain text.");
		}
		if ($name === '') { return $refuse("the screen needs a name."); }
		if (mb_strlen($name, 'UTF-8') > SCREEN_NAME_MAX) {
			return $refuse("the name can be at most " . SCREEN_NAME_MAX . " characters.");
		}
		if (mb_strlen($description, 'UTF-8') > SCREEN_DESCRIPTION_MAX) {
			return $refuse("the description can be at most " . SCREEN_DESCRIPTION_MAX . " characters.");
		}

		/* One at a time from here: the check and the insert must not race. */
		$lock = query("select get_lock(?, 10) got", SCREEN_CREATE_LOCK);
		if (!is_array($lock) || !count($lock) || (int) $lock[0]['got'] !== 1) {
			return $refuse("another screen is being created just now. Nothing was created; try again.");
		}

		$id = null;
		$why = null;
		query("start transaction");

		$taken = query("select id from screen where displayed='Y' and name=?", $name);
		if (!is_array($taken)) {
			$why = "the screens could not be read. Nothing was created.";
		}
		else if (count($taken)) {
			$why = "there is already a screen called \"" . $name . "\".";
		}
		else if (query("insert into screen (name, description, displayed) values (?, ?, 'Y')",
		               $name, $description) === false) {
			$why = "the screen could not be saved. Nothing was created.";
		}
		else {
			$made = query("select last_insert_id() id");
			$id = (is_array($made) && count($made)) ? (int) $made[0]['id'] : 0;
			if ($id < 1) { $why = "the screen could not be saved. Nothing was created."; }
		}

		query($why === null ? "commit" : "rollback");
		query("select release_lock(?)", SCREEN_CREATE_LOCK);

		if ($why !== null) { return $refuse($why); }

		write_log("screen_create", "screen " . $id . " created by user " . $user_id . ": " . $name);
		return ['ok' => true, 'id' => $id];
	}

/* No closing tag: see the note at the end of constants.php. */
