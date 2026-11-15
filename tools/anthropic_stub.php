<?php
/**
 * A stand-in for the Anthropic API, for the test suite.
 *
 *   SM_STUB_DIR=/tmp/x php -S 127.0.0.1:PORT tools/anthropic_stub.php
 *
 * The real API is never called by the tests: it costs money, needs a key and
 * would make the suite depend on a third party being up and on what a model
 * happens to say today. This answers the same shapes.
 *
 * SM_STUB_DIR holds the control files:
 *   mode            what to answer with (see below); "ok" when absent
 *   last_request    the body of the most recent request, for assertions
 *   last_headers    its headers, likewise
 *
 * Modes: ok, prose, bad_stance, empty_summary, unauthorized, rate_limited,
 *        server_error, not_json, slow, cached, thinking, no_text, truncated,
 *        refusal, injection, stance_case,
 *        news_ok, news_bad_shape, news_bad_fields, explain_ok
 */

$dir = getenv('SM_STUB_DIR') ?: sys_get_temp_dir();
$mode = @file_get_contents($dir . '/mode');
$mode = $mode === false ? 'ok' : trim($mode);

$body = file_get_contents('php://input');
@file_put_contents($dir . '/last_request', $body);

//Decoded once, here, because three of the modes answer from what was asked
//rather than from a fixed script.
$payload = json_decode($body, true);
if (!is_array($payload)) { $payload = []; }

//Header names arrive upper cased with an HTTP_ prefix; normalise so a test
//can ask for "x-api-key" the way the client spells it.
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strncmp($key, 'HTTP_', 5) === 0) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
    if ($key === 'CONTENT_TYPE') { $headers['content-type'] = $value; }
}
@file_put_contents($dir . '/last_headers', json_encode($headers));

/**
 * Reject a schema the real API would reject.
 *
 * A stub that accepts anything is worse than no stub: it reports the request
 * as fine and the first time anyone finds out otherwise is in production.
 * Structured outputs support a subset of JSON Schema, and this is the
 * documented list of what is not in it:
 *
 *   recursive schemas; numerical constraints (minimum, maximum, multipleOf);
 *   string constraints (minLength, maxLength); complex array constraints;
 *   additionalProperties set to anything other than false.
 *
 * Checked recursively, because a nested object is as rejectable as the top
 * one. Returns an error string, or null when the schema is acceptable.
 */
function stub_schema_problem($node, $path = 'schema') {

    if (!is_array($node)) { return null; }

    $unsupported = [
        'minimum' => 'numerical', 'maximum' => 'numerical',
        'exclusiveMinimum' => 'numerical', 'exclusiveMaximum' => 'numerical',
        'multipleOf' => 'numerical',
        'minLength' => 'string', 'maxLength' => 'string', 'pattern' => 'string',
        'minItems' => 'array', 'maxItems' => 'array', 'uniqueItems' => 'array',
        'minContains' => 'array', 'maxContains' => 'array', 'contains' => 'array',
        'minProperties' => 'object', 'maxProperties' => 'object',
        'patternProperties' => 'object', 'dependentSchemas' => 'object',
        'if' => 'conditional', 'then' => 'conditional', 'else' => 'conditional',
        'not' => 'conditional', 'oneOf' => 'combinator',
    ];

    foreach ($unsupported as $keyword => $kind) {
        if (array_key_exists($keyword, $node)) {
            return "output_config.format.schema: $kind constraint \"$keyword\" at $path "
                 . "is not supported by structured outputs.";
        }
    }

    if (($node['type'] ?? null) === 'object'
        && array_key_exists('additionalProperties', $node)
        && $node['additionalProperties'] !== false) {
        return "output_config.format.schema: additionalProperties must be false at $path.";
    }
    if (($node['type'] ?? null) === 'object' && !array_key_exists('additionalProperties', $node)) {
        return "output_config.format.schema: additionalProperties: false is required at $path.";
    }

    foreach ($node as $key => $child) {
        if (!is_array($child)) { continue; }
        $problem = stub_schema_problem($child, $path . '.' . $key);
        if ($problem !== null) { return $problem; }
    }

    return null;
}

/* The request is checked before the mode is honoured, so a schema the API
   would refuse fails here too rather than being answered happily. */
$request = json_decode($body, true);
if (is_array($request)) {

    //Forced tool use is a 400 on the model this panel runs on.
    if (isset($request['tool_choice']['type'])
        && in_array($request['tool_choice']['type'], ['tool', 'any'], true)) {
        header('Content-Type: application/json');
        http_response_code(400);
        print(json_encode(["type" => "error", "error" => ["type" => "invalid_request_error",
            "message" => 'tool_choice: type "tool" and "any" are not supported for this model.']]));
        exit;
    }

    $schema_problem = isset($request['output_config']['format']['schema'])
        ? stub_schema_problem($request['output_config']['format']['schema'])
        : null;
    if ($schema_problem !== null) {
        header('Content-Type: application/json');
        http_response_code(400);
        print(json_encode(["type" => "error",
            "error" => ["type" => "invalid_request_error", "message" => $schema_problem]]));
        exit;
    }
}

header('Content-Type: application/json');

function stub_usage($cached, $output = 210, $thinking = 60) {
    //thinking_tokens is a breakdown of output_tokens, not an addition to
    //it: thinking is billed as output and counted there.
    $usage = $cached
        ? ["input_tokens" => 120, "cache_read_input_tokens" => 4000,
           "cache_creation_input_tokens" => 0, "output_tokens" => $output]
        : ["input_tokens" => 900, "cache_read_input_tokens" => 0,
           "cache_creation_input_tokens" => 4000, "output_tokens" => $output];
    $usage["output_tokens_details"] = ["thinking_tokens" => $thinking];
    return $usage;
}

/**
 * A structured-output answer: the JSON arrives as the text of a text block,
 * not as a tool call. output_config.format is what constrains it.
 */
function stub_message($output, $cached = false, $model = "claude-sonnet-5-5") {
    return [
        "id" => "msg_stub", "type" => "message", "role" => "assistant",
        "model" => $model,
        "stop_reason" => "end_turn",
        "content" => [["type" => "text", "text" => json_encode($output)]],
        "usage" => stub_usage($cached),
    ];
}

$outlook = [
    "stance"    => "positive",
    "summary"   => "Trading above both averages with improving momentum, and the policy note on trend following supports holding while that lasts.",
    "positives" => ["Above the 200 day average", "Momentum improving across 3 and 12 months", "Quality score in the top quartile"],
    "risks"     => ["Valuation above the sector", "Earnings concentration", "A close below the 200 day average would end the case"],
    "action"    => "Hold while it stays above the 200 day average; review below it.",
    "watch"     => "The 50 day average crossing the 200 day.",
];

/**
 * The news scorer's answer: one entry per id the request carried.
 *
 * Built from the request rather than fixed, because the whole point of the
 * id is that the answer is matched back to the headline it is about - a
 * canned reply would pass a test that an off-by-one would also pass.
 *
 * Sentiment is derived from the headline so a test can predict it: a title
 * containing "rise" or "raise" scores well, one containing "fall" or "probe"
 * badly, anything else neutral.
 */
function stub_news_scores($payload, $shift_ids = false) {
    $text = '';
    foreach (($payload['messages'] ?? []) as $message) {
        $text .= is_string($message['content'] ?? '') ? $message['content'] : '';
    }

    $scores = [];
    foreach (preg_split('/\r?\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] !== '{') { continue; }
        $item = json_decode($line, true);
        if (!is_array($item) || !isset($item['id'])) { continue; }

        $headline = strtolower((string) ($item['headline'] ?? ''));
        if (preg_match('/\b(rise|raise|beat|wins|upgrade)/', $headline))      { $sentiment = 0.8; }
        elseif (preg_match('/\b(fall|cut|probe|warn|loss)/', $headline))      { $sentiment = -0.7; }
        else                                                                   { $sentiment = 0.0; }

        $impact = preg_match('/result|profit|earning/', $headline) ? 'earnings' : 'other';

        $scores[] = [
            "id"          => ((int) $item['id']) + ($shift_ids ? 1000 : 0),
            "sentiment"   => $sentiment,
            "impact_type" => $impact,
            "summary"     => "Stub summary of headline " . ((int) $item['id']) . ".",
            "bull_point"  => "A holder would read it well.",
            "bear_point"  => "A seller would read it badly.",
        ];
    }

    return ["scores" => $scores];
}

switch ($mode) {

    case 'news_ok':
        //One score per id, matched back to the headline it is about.
        print(json_encode(stub_message(stub_news_scores($payload), false,
                                       $payload['model'] ?? 'claude-haiku-5-5')));
        break;

    case 'news_bad_shape':
        //Valid JSON, wrong shape: no scores array at all. The batch must
        //stay unscored and come round again rather than being written as
        //nulls.
        print(json_encode(stub_message(["answers" => []], false,
                                       $payload['model'] ?? 'claude-haiku-5-5')));
        break;

    case 'news_bad_fields':
        //The right shape with values the schema would not have allowed: an
        //impact type outside the list, a sentiment that is not a number,
        //and an id that was never asked about.
        $bad = stub_news_scores($payload);
        if (isset($bad['scores'][0])) { $bad['scores'][0]['impact_type'] = 'vibes'; }
        if (isset($bad['scores'][1])) { $bad['scores'][1]['sentiment'] = 'quite good'; }
        $bad['scores'][] = ["id" => 999999, "sentiment" => 1, "impact_type" => "other",
                            "summary" => "About a headline nobody asked about.",
                            "bull_point" => "", "bear_point" => ""];
        print(json_encode(stub_message($bad, false, $payload['model'] ?? 'claude-haiku-5-5')));
        break;

    case 'unauthorized':
        http_response_code(401);
        print(json_encode(["type" => "error",
                           "error" => ["type" => "authentication_error",
                                       "message" => "invalid x-api-key"]]));
        break;

    case 'rate_limited':
        http_response_code(429);
        print(json_encode(["type" => "error",
                           "error" => ["type" => "rate_limit_error",
                                       "message" => "too many requests"]]));
        break;

    case 'server_error':
        http_response_code(503);
        print(json_encode(["type" => "error",
                           "error" => ["type" => "overloaded_error",
                                       "message" => "overloaded"]]));
        break;

    case 'not_json':
        print("<html><body>a gateway said something</body></html>");
        break;

    case 'prose':
        //Answered with text that is not JSON at all.
        print(json_encode([
            "id" => "msg_stub", "type" => "message", "role" => "assistant",
            "model" => "claude-sonnet-5-5", "stop_reason" => "end_turn",
            "content" => [["type" => "text", "text" => "I would rather write an essay."]],
            "usage" => stub_usage(false),
        ]));
        break;

    case 'bad_stance':
        print(json_encode(stub_message(array_merge($outlook, ["stance" => "wildly bullish"]))));
        break;

    case 'stance_case':
        //A schema enum the model echoed in a different case. The comparison
        //is case-insensitive, so this is a good answer, not a bad one.
        print(json_encode(stub_message(array_merge($outlook, ["stance" => "POSITIVE"]))));
        break;

    case 'empty_summary':
        print(json_encode(stub_message(array_merge($outlook, ["summary" => "   "]))));
        break;

    case 'injection':
        //Model output carrying markup, to prove the panel escapes it.
        print(json_encode(stub_message(array_merge($outlook, [
            "summary" => '<script>alert("xss")</script> and <b>bold</b>',
            "action"  => '"><img src=x onerror=alert(1)>',
        ]))));
        break;

    case 'slow':
        //Longer than the test's configured timeout, to exercise that path.
        sleep((int) (getenv('SM_STUB_SLEEP') ?: 5));
        print(json_encode(stub_message($outlook)));
        break;

    case 'cached':
        print(json_encode(stub_message($outlook, true)));
        break;

    case 'thinking':
        /* What claude-fable-5-2 returns: the first content block is a
           thinking block with no text field at all, and the answer is behind
           it. Anything reading content[0] finds the wrong block, and
           content[0]["text"] finds nothing. */
        print(json_encode([
            "id" => "msg_stub", "type" => "message", "role" => "assistant",
            "model" => "claude-fable-5-2", "stop_reason" => "end_turn",
            "content" => [
                ["type" => "thinking",
                 "thinking" => "Let me look at the trend figures before deciding.",
                 "signature" => "stub"],
                ["type" => "text", "text" => json_encode($outlook)],
            ],
            "usage" => stub_usage(false),
        ]));
        break;

    case 'no_text':
        //Thought, and then said nothing.
        print(json_encode([
            "id" => "msg_stub", "type" => "message", "role" => "assistant",
            "model" => "claude-fable-5-2", "stop_reason" => "end_turn",
            "content" => [["type" => "thinking", "thinking" => "Hmm.", "signature" => "stub"]],
            "usage" => stub_usage(false),
        ]));
        break;

    case 'truncated':
        /* Out of output budget: thinking used it all and the JSON is cut off
           part way, so there is nothing trustworthy to parse. */
        print(json_encode([
            "id" => "msg_stub", "type" => "message", "role" => "assistant",
            "model" => "claude-fable-5-2", "stop_reason" => "max_tokens",
            "content" => [
                ["type" => "thinking", "thinking" => str_repeat("thinking ", 50), "signature" => "stub"],
                ["type" => "text", "text" => '{"stance":"positive","summary":"Trading above'],
            ],
            //A real truncation spends the whole budget, nearly all of it on
            //thinking - which is why output_tokens alone says so little.
            "usage" => stub_usage(false, 4000, 3900),
        ]));
        break;

    case 'refusal':
        print(json_encode([
            "id" => "msg_stub", "type" => "message", "role" => "assistant",
            "model" => "claude-fable-5-2", "stop_reason" => "refusal",
            "content" => [],
            "usage" => stub_usage(false),
        ]));
        break;

    case 'ok':
    default:
        print(json_encode(stub_message($outlook)));
        break;
}
