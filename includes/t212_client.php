<?php

	/**
	 * The Trading 212 client.
	 *
	 * Everything that knows how to talk to Trading 212 lives here, and in
	 * stage 1 everything it knows how to do is read.
	 *
	 * READS ARE GET ONLY. One method writes - place_market_order() - and it
	 * is the only place in this application that sends anything but a GET.
	 * It cannot be pointed at the live account:
	 *
	 *   it refuses unless the environment is 'demo';
	 *   it builds its URL from T212_DEMO_BASE_URL, a constant, rather than
	 *   from the base URL the reads use;
	 *   it refuses any host whose name contains the live one, whatever has
	 *   been set in the environment;
	 *   and the live key has no Orders - Execute permission anyway, so the
	 *   other end would refuse it too.
	 *
	 * That is four locks on a door that only opens onto a practice account.
	 * Real money stays off until Dale decides, after at least three months
	 * of paper results.
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
	 *   nothing is requested unless the environment is switched on. With
	 *   its switch off, or a missing key, no socket is opened at all;
	 *
	 *   the published rate limits are respected before the fact, by waiting
	 *   between calls, as well as after it. A 429 waits for the reset the
	 *   headers give, once, and then the run gives up rather than retrying
	 *   into a limit that is telling it to stop.
	 */

	require_once("constants.php");

	/* ---------------------------------------------------------------------
	   The environments, and which of them are switched on.

	   There are three: the practice account (demo), the live account read
	   with a read-only key (live-read), and the live account with the key
	   that can trade (live-orders). They are independent, because the
	   practice twin has to keep running beside the live account rather than
	   stop the day it is switched on.
	   --------------------------------------------------------------------- */

	/** The environments this application knows, in the order it reads them. */
	function t212_environments() { return ['demo', 'live-read', 'live-orders']; }

	/** Whether one of them is switched on. */
	function t212_environment_on($env) {

		switch (strtolower(trim((string) $env))) {
			case 'demo':        return (bool) T212_DEMO_ON;
			case 'live-read':   return (bool) T212_LIVE_READ_ON;
			case 'live-orders': return (bool) T212_LIVE_ORDERS_ON;
		}
		return false;
	}

	/**
	 * The environments to READ, in order. Never live-orders: that key is for
	 * sending and nothing else, and a sync that used it would be a sync
	 * holding a credential it has no business holding.
	 */
	function t212_environments_to_read() {

		$out = [];
		if (T212_DEMO_ON)      { $out[] = 'demo'; }
		if (T212_LIVE_READ_ON) { $out[] = 'live-read'; }
		return $out;
	}

	/**
	 * Which ACCOUNT an environment is: demo or live.
	 *
	 * Every broker row carries this, because two accounts are read now and
	 * a position in one is not a position in the other.
	 */
	function t212_env_label($env) {
		return strtolower(trim((string) $env)) === 'demo' ? 'demo' : 'live';
	}

	/** The account label in words, for a page. */
	function t212_label_words($label) {
		return ((string) $label === 'demo') ? 'Practice account' : 'Live account';
	}

	/**
	 * The environment an unnamed client gets: the first one switched on.
	 *
	 * Only for callers that predate there being two. Everything that stores
	 * a row names its environment, because a row has to say which account
	 * it came from.
	 */
	function t212_default_environment() {

		$reading = t212_environments_to_read();
		return count($reading) ? $reading[0] : 'off';
	}

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
		          T212_DEMO_API_KEY, T212_DEMO_API_SECRET,
		          /* The one that can move real money is masked first in
		             spirit and listed here in fact: a key that reaches a log
		             is a key that has to be replaced. */
		          T212_LIVE_ORDERS_API_KEY, T212_LIVE_ORDERS_API_SECRET] as $secret) {
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

	/**
	 * Why Trading 212 refused an order, in one sentence, with the
	 * credentials taken out.
	 *
	 * The ONLY parser of a refusal body in this application: the practice
	 * sender (T212Client::place_market_order) and the live one
	 * (broker_live_place_order) both call it, and neither carries a copy.
	 * Two copies is how HAS was refused on 19 Nov 2026 and recorded with no
	 * reason at all while the body had something in it.
	 *
	 * $status is the HTTP status, $body the raw answer. The broker's own
	 * words are used where it gave any: its code, message, title and detail,
	 * whichever are present, in that order and joined with ": " when more
	 * than one is. A code alone ("BadRequest") can hide the message that says
	 * what was wrong, and the sender decides what to do next from this text -
	 * a share that is whole-shares-only is found by the word "quantity" or
	 * "fraction" in it - so the message is kept beside the code, not
	 * discarded in its favour. With none of them, the first 200 characters
	 * of the body; with no body, the status alone. It never claims there was
	 * nothing to say when the body had content.
	 *
	 * Masked as a whole BEFORE it is cut, so a key that straddles the
	 * 200-character mark is not left half showing.
	 */
	function refusal_reason($status, $body) {

		$status = (int) $status;
		$body   = trim((string) $body);

		if ($body === '') { return "HTTP " . $status . ", empty body"; }

		$decoded = json_decode($body, true);
		if (is_array($decoded)) {

			$said = [];
			foreach (['code', 'message', 'title', 'detail'] as $field) {
				if (!isset($decoded[$field]) || !is_scalar($decoded[$field])) { continue; }
				$text = trim((string) $decoded[$field]);
				if ($text !== '' && !in_array($text, $said, true)) { $said[] = $text; }
			}
			if (count($said)) { return t212_mask(implode(": ", $said)); }
		}

		/* Whitespace folded so a pretty-printed body is still one sentence. */
		$excerpt = trim((string) preg_replace('~\s+~', ' ', t212_mask($body)));
		return function_exists('mb_substr') ? mb_substr($excerpt, 0, 200) : substr($excerpt, 0, 200);
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

			/* Which account this client is for. There is no default any
			   more: a client is built for one environment, and the caller
			   says which, because two of them now run beside each other.
			   An unnamed one takes the first that is switched on, so the
			   old single-environment callers keep working. */
			$this->env = isset($options['env'])
			           ? strtolower(trim((string) $options['env']))
			           : t212_default_environment();

			if ($this->env === 'demo') {
				$this->key    = (string) T212_DEMO_API_KEY;
				$this->secret = (string) T212_DEMO_API_SECRET;
				$this->base   = T212_DEMO_BASE_URL;
			}
			else if ($this->env === 'live-orders') {
				/* The one credential in this application that can move real
				   money, and it is handed out here only because the caller
				   asked for this environment by name. Asking for it is not
				   enough to use it: T212Client has no write path for it at
				   all - see includes/broker_live.php, which is the only
				   file that sends to the live account and checks six
				   conditions before it opens a socket. */
				$this->key    = (string) T212_LIVE_ORDERS_API_KEY;
				$this->secret = (string) T212_LIVE_ORDERS_API_SECRET;
				$this->base   = T212_LIVE_BASE_URL;
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
		public function is_live() {
			return in_array($this->env, ['live-read', 'live-orders'], true);
		}

		/**
		 * Which ACCOUNT this environment is, for the tables and the page.
		 *
		 * Two environments read the live account - the read-only key and
		 * the one that can trade - and a row stored by either came from the
		 * same account, so both are labelled "live".
		 */
		public function label() { return t212_env_label($this->env); }

		/** Whether anything can be asked for at all. */
		public function configured() {
			return t212_environment_on($this->env)
			    && trim($this->key) !== '' && trim($this->secret) !== '';
		}

		/**
		 * Why not, in words for the page.
		 *
		 * Off is not an error and does not read like one: a host that has not
		 * been given a key is the normal state of this feature, not a fault.
		 */
		public function why_not() {

			$switches = ['demo'        => 'SM_T212_DEMO',
			             'live-read'   => 'SM_T212_LIVE_READ',
			             'live-orders' => 'SM_T212_LIVE_ORDERS'];

			if (!isset($switches[$this->env])) {
				return "'" . $this->env . "' is not an environment this knows:"
				     . " they are demo, live-read and live-orders.";
			}
			if (!t212_environment_on($this->env)) {
				return "The " . $this->env . " connection is off ("
				     . $switches[$this->env] . " is not on).";
			}
			if (trim($this->key) === '' || trim($this->secret) === '') {
				$prefix = ['demo' => 'SM_T212_DEMO_', 'live-read' => 'SM_T212_',
				           'live-orders' => 'SM_T212_LIVE_ORDERS_'];
				return "No " . $this->env . " key is configured, so there is nothing to"
				     . " ask with. Set " . $prefix[$this->env] . "API_KEY and "
				     . $prefix[$this->env] . "API_SECRET in the server environment.";
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

		/**
		 * Place a market order. DEMO ONLY.
		 *
		 * $quantity is signed: negative is a sell, which is how Trading 212
		 * says which way round a trade goes.
		 *
		 * Returns the same shape as get(), with the broker's order id in
		 * data on success. The caller must have recorded the order BEFORE
		 * calling this: the order calls are not idempotent, so a request
		 * that times out must never be sent again blind.
		 *
		 * 'unreachable' and a 5xx are the dangerous answers - the order may
		 * or may not have arrived - and they come back with kind 'unknown'
		 * so a caller cannot mistake them for a refusal.
		 */
		public function place_market_order($ticker, $quantity) {

			/* Lock one: the environment. This method sends to the practice
			   account and to nothing else, whatever else is switched on.
			   The live account has its own function, in its own file, with
			   its own key and six conditions in front of it - see
			   includes/broker_live.php. Two paths, so neither can be made
			   into the other by a parameter. */
			if ($this->env !== 'demo') {
				return $this->failure('refused', 0,
				       "an order can only be placed against the demo account through"
				     . " this client, and this one is '" . $this->env . "'.");
			}
			if (!$this->configured()) {
				return $this->failure('not_configured', 0, $this->why_not());
			}

			/* Lock two: the host is the demo constant, or an override that
			   is checked below. The reads' base URL is not consulted. */
			$base = defined('T212_DEMO_BASE_URL_OVERRIDE')
			     && trim((string) T212_DEMO_BASE_URL_OVERRIDE) !== ''
			      ? rtrim(trim((string) T212_DEMO_BASE_URL_OVERRIDE), '/')
			      : T212_DEMO_BASE_URL;

			/* Lock three: not the live host, whatever anyone has set. */
			$host = (string) parse_url($base, PHP_URL_HOST);
			if ($host === '' || stripos($host, 'live.trading212.com') !== false
			    || stripos($base, 'live.trading212.com') !== false) {
				return $this->failure('refused', 0,
				       "refusing to send an order to '" . $host . "': orders go to the"
				     . " demo account and nowhere else.");
			}

			$quantity = (float) $quantity;
			if ($quantity === 0.0) {
				return $this->failure('refused', 0, "an order for nothing is not an order.");
			}

			$body = json_encode(['ticker' => (string) $ticker, 'quantity' => $quantity]);
			if ($body === false) {
				return $this->failure('refused', 0, "could not build the order.");
			}

			$this->wait_for('place_order', 1.0);

			$context = stream_context_create(["http" => [
				"method"  => "POST",
				"header"  => "Authorization: Basic "
				           . base64_encode($this->key . ':' . $this->secret) . "\r\n"
				           . "Content-Type: application/json\r\n"
				           . "Accept: application/json\r\n"
				           . "User-Agent: sharemanager/1.0 (private portfolio tool)\r\n"
				           . "Connection: close\r\n",
				"content" => $body,
				"timeout" => $this->timeout,
				"ignore_errors" => true,
			]]);

			$http_response_header = [];
			$this->requests++;
			$this->last_call['place_order'] = microtime(true);

			$answer = @file_get_contents($base . '/equity/orders/market', false, $context);

			$headers = (array) ($http_response_header ?? []);
			$status  = 0;
			foreach ($headers as $line) {
				if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) { $status = (int) $m[1]; }
			}
			$this->read_rate_limit($headers);

			/* The two answers that mean "ask the broker what happened"
			   rather than "it did not happen". */
			if ($answer === false) {
				return $this->failure('unknown', $status,
				       "the order was sent and Trading 212 did not answer within "
				     . $this->timeout . " seconds. Whether it arrived is not known from here.");
			}
			if ($status >= 500) {
				return $this->failure('unknown', $status,
				       "the order was sent and Trading 212 answered " . $status
				     . ". Whether it arrived is not known from here.");
			}

			if ($status === 401 || $status === 403) {
				return $this->failure($status === 401 ? 'unauthorised' : 'forbidden', $status,
				       "Trading 212 refused the order (" . $status . "). The demo key does"
				     . " not have the permission to place one.");
			}
			if ($status === 429) {
				return $this->failure('rate_limited', 429,
				       "Trading 212 asked for less traffic, so the order was not placed."
				     . " It is not retried: the order calls are not idempotent.", true);
			}

			$decoded = json_decode((string) $answer, true);

			if ($status >= 400) {
				return $this->failure('rejected', $status,
				       "Trading 212 refused the order: " . refusal_reason($status, $answer));
			}

			if (!is_array($decoded)) {
				return $this->failure('unknown', $status,
				       "Trading 212 accepted the order and answered something that is not"
				     . " JSON, so its id is not known from here.");
			}

			return ['ok' => true, 'status' => $status, 'data' => $decoded];
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
