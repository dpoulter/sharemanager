<?php

	/**
	 * The backtest_api module (contracts/modules.yaml): the only way the Mac
	 * mini talks to sharemanager. Three things it can ask, each a POST with a
	 * JSON body:
	 *
	 *   {"action": "claim"}                                    the next queued run, or none
	 *   {"action": "result",  "run_id", "fingerprint", "result", "report_base64"}
	 *   {"action": "failure", "run_id", "fingerprint", "why"}
	 *
	 * The caller has to come from the Tailscale range and carry the token
	 * (Authorization: Bearer ...). The token is SM_BACKTEST_API_TOKEN, read
	 * from the environment or from /etc/sharemanager.env. A refusal is logged
	 * with the caller's address and the reason, never the token.
	 *
	 * Every request goes through backtest_queue (backtest_runs.php). This file
	 * writes no table itself and does not know the runs table's name.
	 *
	 * Never: hands out an account, a position or a broker detail; accepts a
	 * run that reaches into the holdout (backtest_queue refuses it).
	 */

	require_once("constants.php");
	require_once("functions.php");
	require_once(__DIR__ . "/backtest_config.php");
	require_once(__DIR__ . "/backtest_runs.php");

	/** The token, or '' when none is set. Never logged. */
	function backtest_api_token() {

		$env = getenv(BACKTEST_TOKEN_KEY);
		if (is_string($env) && trim($env) !== '') { return trim($env); }

		$file = BACKTEST_ENV_FILE;
		if (!is_readable($file)) { return ''; }
		foreach (preg_split('~\R~', (string) @file_get_contents($file)) as $line) {
			if (preg_match('~^\s*(?:export\s+)?' . preg_quote(BACKTEST_TOKEN_KEY, '~') . '\s*=\s*(.*?)\s*$~', $line, $m)) {
				$v = $m[1];
				if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) { $v = substr($v, 1, -1); }
				return trim($v);
			}
		}
		return '';
	}

	/** Whether an address is inside Tailscale's ranges (100.64.0.0/10, fd7a:115c:a1e0::/48). */
	function backtest_api_tailscale($ip) {

		$bin = @inet_pton((string) $ip);
		if ($bin === false) { return false; }
		if (strlen($bin) === 4) {
			$n = unpack('N', $bin)[1];
			return ($n & 0xFFC00000) === (0x64400000 & 0xFFC00000);
		}
		/* An IPv4 address carried in IPv6 (::ffff:100.x.y.z). */
		if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
			return backtest_api_tailscale(inet_ntop(substr($bin, 12)));
		}
		return substr($bin, 0, 6) === "\xfd\x7a\x11\x5c\xa1\xe0";
	}

	/**
	 * Answer one request. $request: method, remote_addr, authorization (the
	 * header), body (raw). Returns [http status, array to send as JSON].
	 */
	function backtest_api_handle(array $request) {

		$ip = (string) ($request['remote_addr'] ?? '');
		$refuse = function ($status, $why) use ($ip) {
			write_log("backtest_api", "refused " . $ip . ": " . $why);
			return [$status, ['ok' => false, 'why' => $why]];
		};

		if (!backtest_api_tailscale($ip)) { return $refuse(403, "the caller is not on the Tailscale network."); }

		$token = backtest_api_token();
		if ($token === '') { return $refuse(503, "no API token is set on the server (" . BACKTEST_TOKEN_KEY . ")."); }
		$auth = (string) ($request['authorization'] ?? '');
		if (!preg_match('~^Bearer\s+(\S+)$~', $auth, $m)) { return $refuse(401, "no token was given."); }
		if (!hash_equals($token, $m[1])) { return $refuse(401, "the token is wrong."); }

		if (strtoupper((string) ($request['method'] ?? '')) !== 'POST') { return $refuse(405, "only POST is answered."); }
		if (!backtest_runs_ready()) { return $refuse(503, "the backtest tables are not migrated."); }

		$body = json_decode((string) ($request['body'] ?? ''), true);
		if (!is_array($body)) { return $refuse(400, "the body is not a JSON object."); }

		switch ($body['action'] ?? null) {

			case 'claim':
				$run = backtest_claim_next();
				return [200, ['ok' => true, 'run' => $run]];

			case 'result':
				$report = base64_decode((string) ($body['report_base64'] ?? ''), true);
				$out = backtest_post_result((int) ($body['run_id'] ?? 0), $body['fingerprint'] ?? null,
				                            $body['result'] ?? null, $report === false ? '' : $report);
				return $out['ok'] ? [200, $out] : $refuse(409, $out['why']);

			case 'failure':
				$out = backtest_post_failure((int) ($body['run_id'] ?? 0), $body['fingerprint'] ?? null,
				                             $body['why'] ?? '');
				return $out['ok'] ? [200, $out] : $refuse(409, $out['why']);
		}
		return $refuse(400, "the action is claim, result or failure.");
	}

/* No closing tag: see the note at the end of constants.php. */
