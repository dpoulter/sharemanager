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
	function llm_cost($usage) {
		$per_million = function ($tokens, $price) {
			return ((float) $tokens) * ((float) $price) / 1000000.0;
		};
		return $per_million($usage['input_tokens']  ?? 0, ANTHROPIC_PRICE_INPUT)
		     + $per_million($usage['cache_write_tokens'] ?? 0, ANTHROPIC_PRICE_CACHE_WRITE)
		     + $per_million($usage['cached_tokens'] ?? 0, ANTHROPIC_PRICE_CACHE_READ)
		     + $per_million($usage['output_tokens'] ?? 0, ANTHROPIC_PRICE_OUTPUT);
	}

	/**
	 * The shape every caller gets back, success or failure.
	 */
	function llm_failure($message) {
		return ["ok" => false, "error" => $message, "tool_input" => null,
		        "usage" => ["input_tokens" => 0, "cached_tokens" => 0,
		                    "cache_write_tokens" => 0, "output_tokens" => 0],
		        "cost" => 0.0, "model" => llm_model()];
	}

	/**
	 * Ask the model to fill in one tool call.
	 *
	 * $cached_system and $system are both system text; the first is sent with
	 * cache_control so the long, stable part (the instructions and the vault)
	 * is cached and a repeat click pays the read rate for it. The per-request
	 * data goes in the user message, which is never cached.
	 *
	 * $tool is ["name" => ..., "description" => ..., "input_schema" => [...]].
	 * tool_choice forces it, so the model cannot reply with prose instead.
	 */
	function llm_generate($cached_system, $system, $user_text, $tool) {

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
			"model"       => llm_model(),
			"max_tokens"  => 2000,
			"system"      => $system_blocks,
			"messages"    => [["role" => "user", "content" => (string) $user_text]],
			"tools"       => [$tool],
			"tool_choice" => ["type" => "tool", "name" => $tool["name"]],
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
		$response = @file_get_contents(llm_base_url() . "/v1/messages", false, $context);

		if ($response === false) {
			//A timeout and an unreachable host look the same here. Both mean
			//the same thing to the person reading the panel.
			return llm_failure("Could not reach the API: it did not respond within "
			                 . (defined('ANTHROPIC_TIMEOUT') ? ANTHROPIC_TIMEOUT : 90)
			                 . " seconds, or the host is unreachable.");
		}

		$status = llm_status_from_headers($http_response_header);
		$decoded = json_decode($response, true);

		if ($status >= 400) {
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
		];

		//tool_choice forces the tool, but a refusal, a max_tokens stop or a
		//model that ignores it all end up here, so the content is searched
		//rather than assumed.
		$tool_input = null;
		foreach ((array) ($decoded["content"] ?? []) as $block) {
			if (($block["type"] ?? '') === "tool_use" && ($block["name"] ?? '') === $tool["name"]) {
				$tool_input = $block["input"] ?? null;
				break;
			}
		}

		if (!is_array($tool_input)) {
			return array_merge(
				llm_failure("The model did not return an outlook in the expected form."),
				["usage" => $usage, "cost" => llm_cost($usage),
				 "model" => (string) ($decoded["model"] ?? llm_model())]);
		}

		return ["ok" => true, "error" => "", "tool_input" => $tool_input,
		        "usage" => $usage, "cost" => llm_cost($usage),
		        "model" => (string) ($decoded["model"] ?? llm_model())];
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
