<?php

	/**
	 * The Trading 212 client.
	 *
	 * Everything that knows how to talk to Trading 212 lives here, and in
	 * stage 1 everything it knows how to do is read.
	 *
	 * GET ONLY. There is no method on this class that posts, puts, patches or
	 * deletes, and the one request builder hard-codes "GET". An order cannot
	 * be placed through this file by passing it different arguments, which is
	 * a stronger statement than a flag that says not to.
	 *
	 * The API is version 0 and described by its own documentation as beta, so
	 * the endpoints, their limits and their response shapes are all in one
	 * place: when one of them changes, this is the file that changes.
	 *
	 * Rules this file keeps:
	 *
	 *   the key and the secret never reach a log line, an exception message,
	 *   a page or the probe's output. t212_mask() is applied to everything
	 *   that leaves here as text, and the Authorization header is built at
	 *   the moment of the request and never stored;
	 *
	 *   no failure is a fatal. Every path returns the same array shape with
	 *   ok=false and a sentence a person can act on, so one broker call
	 *   cannot take the nightly run or a page down with it;
	 *
	 *   nothing is requested unless the environment says to. With
	 *   SM_T212_ENV=off, or a missing key, no socket is opened at all;
	 *
	 *   the published rate limits are respected before the fact, by waiting
	 *   between calls, as well as after it. A 429 waits for the reset the
	 *   headers give, once, and then the run gives up rather than retrying
	 *   into a limit that is telling it to stop.
	 */

	require_once("constants.php");

	/**
	 * Whatever this is, with the credentials taken out of it.
	 *
	 * Applied to every message, log line and probe file this client produces.
	 * The empty-string guard matters: with no key configured, replacing ""
	 * would put the marker between every character of the text.
	 */
	function t212_mask($text) {

		$text = (string) $text;

		foreach ([T212_API_KEY, T212_API_SECRET,
		          T212_DEMO_API_KEY, T212_DEMO_API_SECRET] as $secret) {
			$secret = (string) $secret;
			if (strlen($secret) >= 4) { $text = str_replace($secret, '[redacted]', $text); }
		}

		/* And the header itself, whatever it holds: a base64 blob after
		   "Basic" is a credential even if it is not one of ours - a key from
		   a previous deploy, say, read out of a file. */
		$text = preg_replace('~(Basic|Bearer)\s+[A-Za-z0-9+/=._\-]+~i', '$1 [redacted]', $text);
		$text = preg_replace('~("?(?:api[_-]?key|secret|password|authorization)"?\s*[:=]\s*"?)'
		                     .'[^"\s,}]+~i', '$1[redacted]', $text);

		return $text;
	}

	class T212Client {

		/**
		 * The calls, their paths and the minimum seconds between two of them.
		 *
		 * The gaps are the published limits: one per 5 seconds for the
		 * summary, one per second for positions, one per 50 for the
		 * instrument list, and 20 per minute - so one per 3 seconds - for the
		 * three history calls. Where the documentation states no limit the
		 * gap is one second, because "not stated" is not "none".
		 */
		const ENDPOINTS = [
			'summary'       => ['/equity/account/summary',        5.0],
			'positions'     => ['/equity/positions',              1.0],
			'instruments'   => ['/equity/metadata/instruments',   50.0],
			'exchanges'     => ['/equity/metadata/exchanges',     30.0],
			'orders'        => ['/equity/orders',                  1.0],
			'order_history' => ['/equity/history/orders',          3.0],
			'dividends'     => ['/equity/history/dividends',       3.0],
			'transactions'  => ['/equity/history/transactions',    3.0],
		];

		private $env;
		private $key;
		private $secret;
		private $base;
		private $timeout;
		private $throttle;
		private $sleeper;

		private $last_call = [];
		private $requests  = 0;
		private $waits     = 0;
		private $halted    = false;
		private $problems  = [];
		private $notes     = [];
		private $rate      = ['remaining' => null, 'reset' => null];

		/**
		 * $options is for the suite and the probe, not for configuration:
		 *
		 *   env        override the environment, e.g. 'live-read'
		 *   sleep      a callable taking seconds, so a test need not wait
		 *   throttle   multiplier on the gaps above
		 */
		public function __construct($options = []) {

			$this->env = isset($options['env'])
			           ? strtolower(trim((string) $options['env']))
			           : (defined('T212_ENV') ? T212_ENV : 'off');

			if ($this->env === 'demo') {
				$this->key    = (string) T212_DEMO_API_KEY;
				$this->secret = (string) T212_DEMO_API_SECRET;
				$this->base   = T212_DEMO_BASE_URL;
			}
			else {
				$this->key    = (string) T212_API_KEY;
				$this->secret = (string) T212_API_SECRET;
				$this->base   = T212_LIVE_BASE_URL;
			}

			/* The override, for the stub. Deliberately the last word on the
			   base URL and deliberately loud about it: a sync that is talking
			   to something other than Trading 212 should say so on the page
			   and in the log rather than look like a sync. */
			if (defined('T212_BASE_URL') && trim((string) T212_BASE_URL) !== '') {
				$this->base = rtrim(trim((string) T212_BASE_URL), '/');
			}
			$this->base = rtrim($this->base, '/');

			$this->timeout  = max(1, (int) (isset($options['timeout']) ? $options['timeout'] : T212_TIMEOUT));
			$this->throttle = isset($options['throttle'])
			                ? max(0.0, (float) $options['throttle'])
			                : (defined('T212_THROTTLE') ? max(0.0, (float) T212_THROTTLE) : 1.0);
			$this->sleeper  = isset($options['sleep']) && is_callable($options['sleep'])
			                ? $options['sleep']
			                : function ($seconds) { if ($seconds > 0) { usleep((int) ($seconds * 1000000)); } };
		}

		public function environment() { return $this->env; }
		public function base_url()    { return $this->base; }
		public function requests()    { return $this->requests; }
		public function waits()       { return $this->waits; }
		public function halted()      { return $this->halted; }
		public function problems()    { return $this->problems; }
		/**
		 * Things that happened and were dealt with.
		 *
		 * Separate from problems() because the page's list is "what the sync
		 * could not do", and a rate limit that was waited out and then
		 * answered is not on that list - it is a line in the log.
		 */
		public function notes()       { return $this->notes; }
		public function rate_limit()  { return $this->rate; }

		/** Whether this environment is one that reads the live account. */
		public function is_live() { return $this->env === 'live-read'; }

		/** Whether anything can be asked for at all. */
		public function configured() {
			return in_array($this->env, ['live-read', 'demo'], true)
			    && trim($this->key) !== '' && trim($this->secret) !== '';
		}

		/**
		 * Why not, in words for the page.
		 *
		 * Off is not an error and does not read like one: a host that has not
		 * been given a key is the normal state of this feature, not a fault.
		 */
		public function why_not() {

			if ($this->env === 'off')  { return "The broker connection is off (SM_T212_ENV=off)."; }
			if (!in_array($this->env, ['live-read', 'demo'], true)) {
				return "SM_T212_ENV is '" . $this->env . "', which is not one of off,"
				     . " live-read or demo, so nothing is connected.";
			}
			if (trim($this->key) === '' || trim($this->secret) === '') {
				return "No " . ($this->env === 'demo' ? "demo " : "")
				     . "key is configured, so there is nothing to ask with."
				     . " Set SM_T212_" . ($this->env === 'demo' ? "DEMO_" : "")
				     . "API_KEY and _API_SECRET in the server environment.";
			}
			return "";
		}

		/**
		 * One call.
		 *
		 * Returns ['ok'=>true,'status'=>200,'data'=>decoded] or
		 * ['ok'=>false,'kind'=>..., 'status'=>..., 'message'=>..., 'data'=>null].
		 *
		 * kind is for code to branch on - 'forbidden' is the one the page
		 * turns into "the key is missing a permission" - and message is for a
		 * person to read.
		 */
		public function get($name, $query = []) {

			if (!isset(self::ENDPOINTS[$name])) {
				return $this->failure('usage', 0, "No such call '" . $name . "'");
			}
			if (!$this->configured()) {
				return $this->failure('not_configured', 0, $this->why_not());
			}
			if ($this->halted) {
				return $this->failure('rate_limited', 429,
				       "Trading 212 asked for less traffic earlier in this run, so"
				     . " nothing more was requested tonight.");
			}

			[$path, $gap] = self::ENDPOINTS[$name];
			$this->wait_for($name, $gap);

			$url = $this->base . $path;
			if (count($query)) { $url .= '?' . http_build_query($query); }

			return $this->fetch($name, $url);
		}

		/**
		 * One call, following the cursor to the end.
		 *
		 * The history calls answer {items: [...], nextPagePath: "..."} and
		 * nextPagePath is null on the last page. Everything else about them is
		 * the same, so the paging is here rather than in each caller.
		 *
		 * Returns ['ok'=>..., 'items'=>[...], 'pages'=>n] and on a failure
		 * part way through returns what it has WITH ok=false: half the
		 * dividends plus a sentence beats nothing plus a sentence, and the
		 * caller writes what it got either way because every row is keyed on
		 * the broker's own id.
		 */
		public function get_all($name, $query = []) {

			if (!isset($query['limit']) && defined('T212_PAGE_LIMIT')) {
				$query['limit'] = max(1, min(50, (int) T212_PAGE_LIMIT));
			}

			$first = $this->get($name, $query);
			if (!$first['ok']) { return $first + ['items' => [], 'pages' => 0]; }

			$items = $this->items_of($first['data']);
			$pages = 1;
			$seen  = [];
			$next  = $this->next_page_of($first['data']);

			while ($next !== null) {

				if ($pages >= max(1, (int) T212_MAX_PAGES)) {
					$this->note("the " . $name . " list did not end after "
					          . $pages . " pages, so the rest was left");
					break;
				}

				$url = $this->resolve($next);
				if ($url === null) {
					$this->note("the " . $name . " list pointed at '" . t212_mask($next)
					          . "', which is not on " . parse_url($this->base, PHP_URL_HOST)
					          . ", so it was not followed");
					break;
				}
				if (isset($seen[$url])) {
					$this->note("the " . $name . " list pointed at a page it had already"
					          . " served, so the paging was stopped");
					break;
				}
				$seen[$url] = true;

				[, $gap] = self::ENDPOINTS[$name];
				$this->wait_for($name, $gap);

				$page = $this->fetch($name, $url);
				$pages++;

				if (!$page['ok']) {
					return $page + ['items' => $items, 'pages' => $pages];
				}

				$items = array_merge($items, $this->items_of($page['data']));
				$next  = $this->next_page_of($page['data']);
			}

			return ['ok' => true, 'status' => 200, 'data' => null,
			        'items' => $items, 'pages' => $pages];
		}

		/* ----------------------------------------------------------------- */

		/** The rows of a response, whether it is a list or a page of one. */
		private function items_of($data) {
			if (is_array($data) && isset($data['items']) && is_array($data['items'])) {
				return array_values($data['items']);
			}
			return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
		}

		private function next_page_of($data) {
			if (!is_array($data)) { return null; }
			foreach (['nextPagePath', 'next_page_path', 'nextPage'] as $key) {
				if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
					return trim($data[$key]);
				}
			}
			return null;
		}

		/**
		 * A next-page path turned into a URL, or null if it points elsewhere.
		 *
		 * A cursor that names another host is not followed. The response comes
		 * from the broker, but "follow whatever URL the response gives you"
		 * is how a credential ends up being sent to the wrong place.
		 */
		private function resolve($next) {

			$host = parse_url($this->base, PHP_URL_HOST);

			if (preg_match('~^https?://~i', $next)) {
				return parse_url($next, PHP_URL_HOST) === $host ? $next : null;
			}

			$origin = parse_url($this->base, PHP_URL_SCHEME) . '://' . $host;
			$port   = parse_url($this->base, PHP_URL_PORT);
			if ($port) { $origin .= ':' . $port; }

			if (substr($next, 0, 1) === '/') { return $origin . $next; }
			return $this->base . '/' . $next;
		}

		/** The published gap between two calls of the same kind, kept. */
		private function wait_for($name, $gap) {

			$gap = $gap * $this->throttle;
			if ($gap <= 0 || !isset($this->last_call[$name])) { return; }

			$since = microtime(true) - $this->last_call[$name];
			if ($since < $gap) { call_user_func($this->sleeper, $gap - $since); }
		}

		/**
		 * The request itself. The only place this class opens a socket, and
		 * the only place the method is named: GET, written once.
		 */
		private function fetch($name, $url) {

			$context = stream_context_create(["http" => [
				"method"  => "GET",
				"header"  => "Authorization: Basic "
				           . base64_encode($this->key . ':' . $this->secret) . "\r\n"
				           . "Accept: application/json\r\n"
				           . "User-Agent: sharemanager/1.0 (private portfolio tool)\r\n"
				           . "Connection: close\r\n",
				"timeout" => $this->timeout,
				/* Without this a 401 or a 429 comes back as false with the
				   body thrown away, and the body is where the API says what
				   it did not like. */
				"ignore_errors" => true,
			]]);

			$http_response_header = [];
			$this->requests++;
			$this->last_call[$name] = microtime(true);

			$body = @file_get_contents($url, false, $context);

			$headers = (array) ($http_response_header ?? []);
			$status  = 0;
			foreach ($headers as $line) {
				if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) { $status = (int) $m[1]; }
			}
			$this->read_rate_limit($headers);

			if ($body === false) {
				return $this->failure('unreachable', $status,
				       "Trading 212 did not answer within " . $this->timeout
				     . " seconds, or the host could not be reached.");
			}

			if ($status === 401) {
				return $this->failure('unauthorised', 401,
				       "Trading 212 did not accept the key (401). Check the key and"
				     . " secret, and that they are for the "
				     . ($this->env === 'demo' ? "demo" : "live") . " environment.");
			}

			if ($status === 403) {
				return $this->failure('forbidden', 403,
				       "Trading 212 refused the " . $name . " call (403). The key does"
				     . " not have the permission that call needs.");
			}

			if ($status === 429) {
				return $this->rate_limited($name, $url);
			}

			if ($status >= 500) {
				return $this->failure('server', $status,
				       "Trading 212 answered " . $status . ", which is its end rather"
				     . " than ours. Nothing more was asked for tonight.", true);
			}

			if ($status >= 400 || $status === 0) {
				return $this->failure('http', $status,
				       "Trading 212 answered " . ($status ?: 'nothing') . " for the "
				     . $name . " call.");
			}

			$decoded = json_decode((string) $body, true);
			if ($decoded === null && trim((string) $body) !== 'null') {
				return $this->failure('bad_json', $status,
				       "Trading 212's answer to the " . $name . " call was not JSON.");
			}

			return ['ok' => true, 'status' => $status, 'data' => $decoded];
		}

		/**
		 * 429, handled once.
		 *
		 * The headers say when the limit resets, so the one thing worth doing
		 * is waiting that long and asking again. If the second answer is a
		 * 429 as well, the run is over: a limit that is still complaining
		 * after its own reset time wants less traffic than this, and a loop of
		 * retries is how an account gets its key withdrawn.
		 */
		private function rate_limited($name, $url) {

			if ($this->waits > 0) {
				return $this->failure('rate_limited', 429,
				       "Trading 212 asked for less traffic again after waiting for its"
				     . " own reset, so the run stopped.", true);
			}

			$wait = $this->rate['reset'];
			if ($wait === null || $wait < 0) { $wait = 5; }
			$wait = min($wait, max(0, (int) T212_MAX_WAIT));

			$this->waits++;
			$this->notes[] = "waited " . $wait . "s for the rate limit on " . $name
			               . " to reset, then asked once more";
			call_user_func($this->sleeper, $wait);

			$again = $this->fetch($name, $url);
			if (!$again['ok'] && ($again['kind'] ?? '') === 'rate_limited') {
				$this->halted = true;
			}
			return $again;
		}

		/**
		 * x-ratelimit-remaining and x-ratelimit-reset, read on every response.
		 *
		 * reset is stored as seconds to wait. Trading 212 sends it as seconds
		 * remaining, but an epoch is the other common spelling, so a value
		 * that is obviously a timestamp is turned into a wait rather than
		 * being waited for.
		 */
		private function read_rate_limit($headers) {

			foreach ($headers as $line) {

				if (!preg_match('~^([A-Za-z0-9\-]+)\s*:\s*(.*)$~', (string) $line, $m)) { continue; }
				$key   = strtolower($m[1]);
				$value = trim($m[2]);

				if ($key === 'x-ratelimit-remaining' && $value !== '') {
					$this->rate['remaining'] = (int) $value;
				}
				if (($key === 'x-ratelimit-reset' || $key === 'retry-after') && $value !== '') {
					$seconds = (int) $value;
					if ($seconds > 1000000000) { $seconds = max(0, $seconds - time()); }
					$this->rate['reset'] = $seconds;
				}
			}
		}

		private function failure($kind, $status, $message, $halt = false) {
			$message = t212_mask($message);
			$this->note($message);
			if ($halt) { $this->halted = true; }
			return ['ok' => false, 'kind' => $kind, 'status' => (int) $status,
			        'message' => $message, 'data' => null];
		}

		private function note($text) {
			$text = t212_mask($text);
			if (!in_array($text, $this->problems, true)) { $this->problems[] = $text; }
		}
	}

/* No closing tag: see the note at the end of constants.php. */
