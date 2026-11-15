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
 *        refusal
 */

$dir = getenv('SM_STUB_DIR') ?: sys_get_temp_dir();
$mode = @file_get_contents($dir . '/mode');
$mode = $mode === false ? 'ok' : trim($mode);

$body = file_get_contents('php://input');
@file_put_contents($dir . '/last_request', $body);

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

header('Content-Type: application/json');

function stub_usage($cached) {
    return $cached
        ? ["input_tokens" => 120, "cache_read_input_tokens" => 4000,
           "cache_creation_input_tokens" => 0, "output_tokens" => 210]
        : ["input_tokens" => 900, "cache_read_input_tokens" => 0,
           "cache_creation_input_tokens" => 4000, "output_tokens" => 210];
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

switch ($mode) {

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
            "usage" => stub_usage(false),
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
