<?php

require __DIR__ . '/Ops006TestSupport.php';

function ops006RobustRedact($value)
{
    $className = 'app\\services\\ApiKeyRedactor';
    ops006RequireClass($className);
    return call_user_func([$className, 'redact'], $value);
}

ops006Test('quoted escaped JSON values are fully redacted and remain valid JSON', function () {
    $input = '{"x-api-key":"prefix\"SYNTHETIC_ESCAPED_JSON_123456"}';
    $actual = ops006RobustRedact($input);
    $decoded = json_decode($actual, true);
    ops006AssertSame(JSON_ERROR_NONE, json_last_error(), 'Redacted JSON with an escaped quote should remain valid.');
    ops006Assert(is_array($decoded), 'The escaped-value JSON should still decode.');
    ops006Assert(strpos($actual, 'SYNTHETIC_ESCAPED_JSON_123456') === false, 'The escaped quoted value should be fully redacted.');
});

ops006Test('multiline PHP-export values are fully redacted', function () {
    $input = "'x-api-key' => 'first line\nSYNTHETIC_MULTILINE_EXPORT_123456\nlast line'";
    $actual = ops006RobustRedact($input);
    ops006Assert(strpos($actual, 'SYNTHETIC_MULTILINE_EXPORT_123456') === false, 'The multiline exported value should not leak after its first line.');
    ops006Assert(strpos($actual, '[REDACTED]') !== false, 'The multiline exported value should have a redaction marker.');
});

ops006Test('serialized object fields redact array values too', function () {
    $serialized = serialize((object)['x-api-key' => ['SYNTHETIC_SERIALIZED_ARRAY_123456']]);
    $input = "unserialize('" . addcslashes($serialized, "\\'") . "')";
    $actual = ops006RobustRedact($input);
    ops006Assert(strpos($actual, 'SYNTHETIC_SERIALIZED_ARRAY_123456') === false, 'The serialized array value should not remain in output.');
    ops006AssertSame('[REDACTED]', $actual, 'The serialized export should be replaced as a whole.');
});

ops006Test('header arrays scan nested brackets and delimiters inside quoted values', function () {
    $input = "'x-api-key' => ['outer' => ['left]SYNTHETIC_NESTED_ARRAY_123456', 'right']];";
    $actual = ops006RobustRedact($input);
    ops006Assert(strpos($actual, 'SYNTHETIC_NESTED_ARRAY_123456') === false, 'A closing bracket inside a quoted header value must not end scanning.');
    ops006Assert(strpos($actual, '[REDACTED]') !== false, 'The nested header value should receive a marker.');
});

ops006Test('raw Digest Authorization values are redacted as one header value', function () {
    $input = "Authorization: Digest username=\"synthetic-user\", response=\"SYNTHETIC_DIGEST_RESPONSE_123456\"\ntail=kept";
    $actual = ops006RobustRedact($input);
    ops006Assert(strpos($actual, 'synthetic-user') === false, 'Digest username text should not survive the header replacement.');
    ops006Assert(strpos($actual, 'SYNTHETIC_DIGEST_RESPONSE_123456') === false, 'The Digest response should not survive the header replacement.');
    ops006Assert(strpos($actual, 'tail=kept') !== false, 'Text after the raw header should remain intact.');
});

ops006Test('raw headers inside JSON preserve the closing quote and valid JSON', function () {
    $input = '{"error":"x-api-key: SYNTHETIC_JSON_RAW_HEADER_123456"}';
    $actual = ops006RobustRedact($input);
    $decoded = json_decode($actual, true);
    ops006AssertSame(JSON_ERROR_NONE, json_last_error(), 'Redacting a header embedded in JSON should preserve valid JSON.');
    ops006Assert(is_array($decoded), 'The JSON header fixture should still decode.');
    ops006Assert(strpos($actual, 'SYNTHETIC_JSON_RAW_HEADER_123456') === false, 'The embedded raw header value should be removed.');
});

ops006Test('raw secret assignments inside quoted JSON preserve valid JSON', function () {
    $input = '{"error":"OPENAI_API_KEY: SYNTHETIC_QUOTED_JSON_ASSIGNMENT_123456"}';
    $actual = ops006RobustRedact($input);
    $decoded = json_decode($actual, true);
    ops006AssertSame(JSON_ERROR_NONE, json_last_error(), 'A quoted environment assignment should leave valid JSON.');
    ops006Assert(is_array($decoded), 'The quoted assignment JSON should still decode.');
    ops006Assert(strpos($actual, 'SYNTHETIC_QUOTED_JSON_ASSIGNMENT_123456') === false, 'The quoted environment assignment should be redacted.');
});

ops006Test('PHP-exported JSON headers are redacted without breaking the exported string', function () {
    $input = "'response' => '{\"headers\":{\"x-api-key\":\"SYNTHETIC_EXPORTED_JSON_HEADER_123456\"}}'";
    $actual = ops006RobustRedact($input);
    ops006Assert(preg_match("/'response' => '(.*)'$/s", $actual, $matches) === 1, 'The PHP-exported response string should remain delimited.');
    $decoded = json_decode($matches[1], true);
    ops006AssertSame(JSON_ERROR_NONE, json_last_error(), 'The JSON inside the PHP-exported string should remain valid.');
    ops006Assert(is_array($decoded), 'The exported JSON should still decode.');
    ops006Assert(strpos($matches[1], 'SYNTHETIC_EXPORTED_JSON_HEADER_123456') === false, 'The exported JSON header should be redacted.');
});

ops006Test('encoded query separators are recognized and encoded values stop at encoded delimiters', function () {
    $input = 'https%3A%2F%2Fexample.invalid%3Fkey%3DSYNTHETIC_ENCODED_QUERY_KEY_123456%26api_key%3DSYNTHETIC_ENCODED_API_KEY_123456 key%3DSYNTHETIC_ENCODED_END_KEY_123456%26next%3Dkeep';
    $actual = ops006RobustRedact($input);
    foreach (['SYNTHETIC_ENCODED_QUERY_KEY_123456', 'SYNTHETIC_ENCODED_API_KEY_123456', 'SYNTHETIC_ENCODED_END_KEY_123456'] as $secret) {
        ops006Assert(strpos($actual, $secret) === false, 'An encoded query value remains in output.');
    }
    ops006Assert(strpos($actual, '%26next%3Dkeep') !== false, 'The encoded adjacent field should remain after the redacted value.');
});

ops006Test('encoded delimiters remain part of ordinary URL query values', function () {
    $input = 'https://example.invalid/?key=prefix%26SYNTHETIC_ORDINARY_PERCENT26_QUERY_123456&next=keep&api_key=prefix%3FSYNTHETIC_ORDINARY_PERCENT3F_QUERY_123456&after=kept';
    $actual = ops006RobustRedact($input);
    ops006Assert(strpos($actual, 'SYNTHETIC_ORDINARY_PERCENT26_QUERY_123456') === false, 'The percent-26 portion of an ordinary query secret should be redacted.');
    ops006Assert(strpos($actual, 'SYNTHETIC_ORDINARY_PERCENT3F_QUERY_123456') === false, 'The percent-3f portion of an ordinary query secret should be redacted.');
    ops006Assert(strpos($actual, '&next=keep') !== false, 'The ordinary adjacent query field should remain.');
    ops006Assert(strpos($actual, '&after=kept') !== false, 'The query field after an encoded question mark should remain.');
});

ops006Test('one megabyte adversarial variable-name text stays near linear baseline', function () {
    $hostileName = 'a_pass' . str_repeat('_pass', 200000) . '!';
    $input = $hostileName . "\nOPENAI_API_KEY: SYNTHETIC_ADVERSARIAL_API_KEY_123456";
    $input = str_pad($input, 1024 * 1024, 'x');
    ops006AssertSame(1024 * 1024, strlen($input), 'The adversarial message should be exactly one mebibyte.');
    $ordinaryInput = str_repeat('x', 1024 * 1024);
    $started = microtime(true);
    ops006RobustRedact($ordinaryInput);
    $ordinaryElapsed = microtime(true) - $started;
    $started = microtime(true);
    $actual = ops006RobustRedact($input);
    $elapsed = microtime(true) - $started;
    ops006Assert(is_string($actual), 'Redaction should always return a string for adversarial input.');
    ops006Assert($elapsed <= 3 * $ordinaryElapsed + 0.25, 'Adversarial redaction should remain close to ordinary same-size input.');
    ops006Assert($elapsed < 10.0, 'One megabyte of adversarial text should redact in under ten seconds.');
    ops006Assert(strpos($actual, 'SYNTHETIC_ADVERSARIAL_API_KEY_123456') === false, 'The trailing API key should be redacted after adversarial text.');
});

exit(ops006Finish());
