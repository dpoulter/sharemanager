<?php

	/**
	 * The language model client.
	 *
	 * Everything that knows how to talk to Anthropic lives here. The callers
	 * (includes/ai_outlook.php) hand over a system prompt, some notes, a block
	 * of data and a tool schema, and get back either a validated tool input or
	 * a message explaining why not. Adding a second provider later means
	 * another implementation of llm_generate() and a setting to pick between
	 * them; nothing above this file would change.
	 *
	 * Rules this file keeps:
	 *   - the API key never reaches the browser, a log line or an exception
	 *     message;
	 *   - no failure is a fatal. Every path returns the same array shape with
	 *     ok=false and a sentence a person can read;
	 *   - no network call happens unless llm_generate() is called, which only
	 *     happens from the Generate button.
	 */

	require_once("constants.php");

	define("LLM_API_VERSION", "2023-06-01");

	function llm_model() {
		return defined('ANTHROPIC_MODEL') && ANTHROPIC_MODEL !== '' ? ANTHROPIC_MODEL : 'claude-sonnet-5-5';
	}

	function llm_api_key() {
		return defined('ANTHROPIC_API_KEY') ? trim((string) ANTHROPIC_API_KEY) : '';
	}

	function llm_configured() {
		return llm_api_key() !== '';
	}

	/**
	 * The output budget, thinking included.
	 *
	 * Floored rather than simply defaulted: too small a value does not save
	 * anything, it truncates the answer, and a truncated answer is billed and
	 * discarded.
	 */
	function llm_max_tokens($configured = null) {
		//The value is a parameter so the floor can be exercised directly; a
		//constant cannot be redefined mid-process, and production passes
		//nothing and gets ANTHROPIC_MAX_TOKENS.
		if ($configured === null) {
			$configured = defined('ANTHROPIC_MAX_TOKENS') ? (int) ANTHROPIC_MAX_TOKENS : 0;
		}
		return max(4000, (int) $configured);
	}

	function llm_base_url() {
		$url = defined('ANTHROPIC_BASE_URL') ? rtrim((string) ANTHROPIC_BASE_URL, '/') : '';
		return $url !== '' ? $url : 'https://api.anthropic.com';
	}

	/**
	 * What a generation cost, in dollars.
	 *
	 * Priced from the per-million rates in constants.php rather than hard
	 * coded, because they change and because ANTHROPIC_MODEL can point at a
	 * different model with different rates.
	 *
	 * Cache writes are priced separately from ordinary input: Anthropic
	 * charges a premium to put something in the cache and a large discount to
	 * read it back, which is the whole point of sending the vault that way.
	 */
	function llm_cost($usage, $prices = null) {
		/* $prices overrides the configured rates for a call that went to a
		   different model. The news scorer runs on Haiku at a fiftieth of
		   the price, and billing a thousand headlines at the panel's rates
		   would report a figure forty times too high. */
		$rates = [
			"input"       => $prices['input']       ?? ANTHROPIC_PRICE_INPUT,
			"output"      => $prices['output']      ?? ANTHROPIC_PRICE_OUTPUT,
			"cache_read"  => $prices['cache_read']  ?? ANTHROPIC_PRICE_CACHE_READ,
			"cache_write" => $prices['cache_write'] ?? ANTHROPIC_PRICE_CACHE_WRITE,
		];
		$per_million = function ($tokens, $price) {
			return ((float) $tokens) * ((float) $price) / 1000000.0;
		};
		return $per_million($usage['input_tokens']  ?? 0, $rates['input'])
		     + $per_million($usage['cache_write_tokens'] ?? 0, $rates['cache_write'])
		     + $per_million($usage['cached_tokens'] ?? 0, $rates['cache_read'])
		     + $per_million($usage['output_tokens'] ?? 0, $rates['output']);
	}

	/**
	 * One log line about a generation.
	 *
	 * message_log has no level column and write_log() takes no level, and
	 * that table is shared with every other module - so the level goes in
	 * the line rather than into a migration that changes logging for the
	 * whole application. It is the first field, so a grep for "level=WARN"
	 * finds the ones that need attention and the rest read as a census:
	 *
	 *   select message_text from message_log
	 *    where module='llm' and message_text like 'level=WARN%';
	 *
	 * Never carries prompt text, note text, a symbol or the key. These lines
	 * exist to be counted, and anything else in them is a liability.
	 *
	 * write_log() is never gated by DEBUG_LOG. The guard is for callers that
	 * use this file without functions.php loaded.
	 */
	function llm_log($level, $text) {
		if (function_exists('write_log')) {
			write_log("llm", "level=" . $level . " " . $text);
		}
	}

	/**
	 * A token count for a log line.
	 *
	 * null means the response did not report the figure, which is not the
	 * same as zero: zero would read as "no thinking happened".
	 */
	function llm_token_figure($value) {
		return $value === null ? "unreported" : (string) (int) $value;
	}

	/**
	 * The shape every caller gets back, success or failure.
	 */
	function llm_failure($message) {
		return ["ok" => false, "error" => $message, "output" => null,
		        "usage" => ["input_tokens" => 0, "cached_tokens" => 0,
		                    "cache_write_tokens" => 0, "output_tokens" => 0,
		                    "thinking_tokens" => null],
		        "cost" => 0.0, "model" => llm_model()];
	}

	/**
	 * Ask for one JSON answer matching a schema.
	 *
	 * $cached_system and $system are both system text; the first is sent with
	 * cache_control so the long, stable part (the instructions and the vault)
	 * is cached and a repeat click pays the read rate for it. The per-request
	 * data goes in the user message, which is never cached.
	 *
	 * $schema is a JSON schema. It is sent as output_config.format, which is
	 * what constrains the answer.
	 *
	 * This used to define a tool and force it with tool_choice. The live API
	 * rejects that on claude-fable-5-2 with HTTP 400, "tool_choice: type
	 * "tool" and "any" are not supported for this model", so forcing a
	 * tool is not a portable way to get structured output. Structured outputs
	 * are, and they need no beta header.
	 */
	function llm_generate($cached_system, $system, $user_text, $schema, $options = []) {

		/* $options lets one caller use a different model without every
		   caller having to. The news scorer runs a thousand headlines a
		   night through Haiku; the panels run one request through the
		   configured model. Everything else - the schema, the cache block,
		   the error handling, the logging - is the same work and is done
		   once, here.

		     model        model id, default ANTHROPIC_MODEL
		     prices       per-million rates for that model, default the
		                  configured ones, so the cost line is about the
		                  model that actually ran
		     max_tokens   default ANTHROPIC_MAX_TOKENS, floored at 4000
		     label        what to call this caller in the log */
		$model_wanted = isset($options['model']) && trim((string) $options['model']) !== ''
		              ? trim((string) $options['model']) : llm_model();
		$prices       = $options['prices'] ?? null;
		$max_tokens   = llm_max_tokens($options['max_tokens'] ?? null);
		$label        = isset($options['label']) ? (string) $options['label'] : 'panel';

		if (!llm_configured()) {
			return llm_failure("No API key is configured, so there is nothing to ask. "
			                 . "Set ANTHROPIC_API_KEY in the web server environment.");
		}

		//The cached block comes first and must stay byte identical between
		//calls, or the cache misses and the saving goes with it.
		$system_blocks = [];
		if (trim((string) $cached_system) !== '') {
			$system_blocks[] = ["type" => "text", "text" => (string) $cached_system,
			                    "cache_control" => ["type" => "ephemeral"]];
		}
		if (trim((string) $system) !== '') {
			$system_blocks[] = ["type" => "text", "text" => (string) $system];
		}

		$payload = [
			"model"      => $model_wanted,
			//Thinking tokens come out of this budget, so a model that thinks
			//before answering can spend the whole allowance and have the JSON
			//cut off part way. 2000 was enough for the answer and not for the
			//thinking in front of it. The floor is not negotiable by
			//configuration: a smaller number does not save money, it buys
			//truncated answers that are thrown away and paid for anyway.
			"max_tokens" => $max_tokens,
			"system"     => $system_blocks,
			"messages"   => [["role" => "user", "content" => (string) $user_text]],
			//No tools and no tool_choice. See llm_generate() above.
			"output_config" => ["format" => ["type" => "json_schema", "schema" => $schema]],
		];

		$body = json_encode($payload);
		if ($body === false) {
			return llm_failure("Could not build the request.");
		}

		$context = stream_context_create(["http" => [
			"method"  => "POST",
			"header"  => "content-type: application/json\r\n"
			           . "anthropic-version: " . LLM_API_VERSION . "\r\n"
			           . "x-api-key: " . llm_api_key() . "\r\n",
			"content" => $body,
			"timeout" => defined('ANTHROPIC_TIMEOUT') ? ANTHROPIC_TIMEOUT : 90,
			//Without this a 4xx makes file_get_contents return false and throw
			//the body away, which is where the API says what was wrong.
			"ignore_errors" => true,
		]]);

		//@ because a refused connection or a DNS failure is a warning, and a
		//warning printed into a JSON response is a broken panel. The return
		//value and $http_response_header carry everything needed.
		$http_response_header = [];
		$started = microtime(true);
		$response = @file_get_contents(llm_base_url() . "/v1/messages", false, $context);
		$duration_ms = (int) round((microtime(true) - $started) * 1000);

		if ($response === false) {
			//A timeout and an unreachable host look the same here. Both mean
			//the same thing to the person reading the panel.
			llm_log("WARN", "outcome=unreachable caller=" . $label . " model=" . $model_wanted
			              . " duration_ms=" . $duration_ms
			              . " timeout_s=" . (defined('ANTHROPIC_TIMEOUT') ? ANTHROPIC_TIMEOUT : 90));
			return llm_failure("Could not reach the API: it did not respond within "
			                 . (defined('ANTHROPIC_TIMEOUT') ? ANTHROPIC_TIMEOUT : 90)
			                 . " seconds, or the host is unreachable.");
		}

		$status = llm_status_from_headers($http_response_header);
		$decoded = json_decode($response, true);

		if ($status >= 400) {
			//No tokens and no stop_reason on a refused request, but it still
			//happened and is still worth counting - a run of 429s is exactly
			//the thing these lines exist to make visible.
			llm_log("WARN", "outcome=http_error status=" . $status
			              . " caller=" . $label . " model=" . $model_wanted
			              . " duration_ms=" . $duration_ms);
			return llm_failure(llm_error_message($status, $decoded));
		}

		if (!is_array($decoded)) {
			return llm_failure("The API returned something that is not JSON.");
		}

		$usage = [
			"input_tokens"       => (int) ($decoded["usage"]["input_tokens"] ?? 0),
			"cached_tokens"      => (int) ($decoded["usage"]["cache_read_input_tokens"] ?? 0),
			"cache_write_tokens" => (int) ($decoded["usage"]["cache_creation_input_tokens"] ?? 0),
			"output_tokens"      => (int) ($decoded["usage"]["output_tokens"] ?? 0),
			/* Thinking is part of output_tokens, not additional to it: it is
			   billed as output and counted there. This field breaks out how
			   much of that was thinking, where the response carries it, so
			   it is reported and never added to the cost - adding it would
			   bill the same tokens twice. */
			"thinking_tokens"    => isset($decoded["usage"]["output_tokens_details"]["thinking_tokens"])
			                        ? (int) $decoded["usage"]["output_tokens_details"]["thinking_tokens"]
			                        : null,
		];

		$model = (string) ($decoded["model"] ?? $model_wanted);

		/* One line per generation, whatever the outcome. The truncation
		   warning below is the alert; this is the record, and without it a
		   successful run leaves no trace at all - so there is nothing to
		   compare the truncations against and no way to tell whether 4000 is
		   enough. Emitted here, before the outcome is branched on, so every
		   path that got a response is counted exactly once. */
		llm_log("INFO", "outcome=generated"
		              . " stop_reason=" . ((string) ($decoded["stop_reason"] ?? 'none'))
		              . " caller=" . $label
		              . " model=" . $model
		              . " duration_ms=" . $duration_ms
		              . " max_tokens=" . $max_tokens
		              . " input_tokens=" . $usage["input_tokens"]
		              . " cached_tokens=" . $usage["cached_tokens"]
		              . " cache_write_tokens=" . $usage["cache_write_tokens"]
		              . " output_tokens=" . $usage["output_tokens"]
		              . " thinking_tokens=" . llm_token_figure($usage["thinking_tokens"]));

		$fail  = function ($message) use ($usage, $model) {
			return array_merge(llm_failure($message),
				["usage" => $usage, "cost" => llm_cost($usage, $prices), "model" => $model]);
		};

		/* Why the answer stopped, before reading it.
		
		   max_tokens means the JSON is cut off part way, and a half-written
		   outlook is not a cheaper outlook - it is a wrong one. refusal means
		   the model declined; there is nothing to parse and saying "the shape
		   was wrong" would be a lie about what happened. */
		$stop = (string) ($decoded["stop_reason"] ?? '');
		if ($stop === 'max_tokens') {
			/* Logged, not just reported, so how often this happens can be
			   counted before deciding whether 4000 is enough.
			
			   output_tokens includes the thinking tokens - thinking is
			   billed as output and counted there - so on a truncation it
			   sits at or just under max_tokens and says little on its own.
			   thinking_tokens is the figure that answers the question: how
			   much of the budget went to thinking rather than to the answer.
			   It is absent on responses that do not report it, and logged as
			   "unreported" rather than as zero, which would read as "none".
			
			   write_log() is never gated by DEBUG_LOG; the guard is for
			   callers that use this file without functions.php. No prompt
			   text and no key: the numbers are the point. */
			llm_log("WARN", "outcome=truncated stop_reason=max_tokens model=" . $model
			              . " max_tokens=" . llm_max_tokens()
			              . " output_tokens=" . $usage["output_tokens"]
			              . " thinking_tokens=" . llm_token_figure($usage["thinking_tokens"])
			              . " input_tokens=" . $usage["input_tokens"]
			              . " cached_tokens=" . $usage["cached_tokens"]);
			return $fail("The model ran out of room before finishing (stop_reason max_tokens). "
			           . "Raise ANTHROPIC_MAX_TOKENS.");
		}
		if ($stop === 'refusal') {
			return $fail("The model declined to answer (stop_reason refusal).");
		}

		/* The first text block, not content[0].
		
		   On a model that thinks before answering - claude-fable-5-2 does -
		   the first block is a thinking block with no text field at all.
		   Reading content[0] gets that block, and content[0]["text"] gets
		   nothing, so the answer looks missing when it is simply second. */
		$text = null;
		foreach ((array) ($decoded["content"] ?? []) as $block) {
			if (($block["type"] ?? '') === "text" && isset($block["text"])) {
				$text = (string) $block["text"];
				break;
			}
		}

		if ($text === null || trim($text) === '') {
			return $fail("The model returned no answer.");
		}

		$output = json_decode($text, true);
		if (!is_array($output)) {
			return $fail("The model did not return an outlook in the expected form.");
		}

		return ["ok" => true, "error" => "", "output" => $output,
		        "usage" => $usage, "cost" => llm_cost($usage, $prices), "model" => $model];
	}

	/**
	 * The status code out of $http_response_header.
	 *
	 * The last HTTP/ line wins: a redirect leaves more than one, and the final
	 * one is the response actually being read.
	 */
	function llm_status_from_headers($headers) {
		$status = 0;
		foreach ((array) $headers as $line) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int) $m[1]; }
		}
		return $status;
	}

	/**
	 * A sentence for the panel.
	 *
	 * The API's own message is included when there is one, because "invalid
	 * model" and "credit balance too low" are both 400 and the difference
	 * matters. It is text from an external service, so whatever renders this
	 * escapes it.
	 */
	function llm_error_message($status, $decoded) {
		$detail = '';
		if (is_array($decoded) && isset($decoded["error"]["message"])) {
			$detail = ' (' . (string) $decoded["error"]["message"] . ')';
		}

		if ($status === 401 || $status === 403) {
			return "The API rejected the key" . $detail . ". Check ANTHROPIC_API_KEY.";
		}
		if ($status === 429) {
			return "Rate limited by the API" . $detail . ". Try again in a minute.";
		}
		if ($status >= 500) {
			return "The API had a problem (HTTP " . $status . ")" . $detail . ". Try again shortly.";
		}
		return "The API refused the request (HTTP " . $status . ")" . $detail . ".";
	}
