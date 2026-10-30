<?php

require __DIR__ . '/Ops006TestSupport.php';

function ops006Redact($value)
{
    $className = 'app\\services\\ApiKeyRedactor';
    ops006RequireClass($className);
    return call_user_func([$className, 'redact'], $value);
}

function ops006AssertRedacted($input, $secret, $context)
{
    $actual = ops006Redact($input);
    ops006Assert(strpos($actual, $secret) === false, "{$context}: the synthetic secret remains in the output.");
    ops006Assert(strpos($actual, '[REDACTED]') !== false, "{$context}: no redaction marker was emitted.");
    return $actual;
}

ops006Test('redacts raw Authorization values with non-Bearer schemes', function () {
    ops006AssertRedacted('Authorization: CustomScheme SYNTHETIC_AUTH_VALUE_314159', 'SYNTHETIC_AUTH_VALUE_314159', 'raw Authorization');
});

ops006Test('redacts raw x-goog-api-key values', function () {
    ops006AssertRedacted('x-goog-api-key: SYNTHETIC_GOOGLE_VALUE_314159', 'SYNTHETIC_GOOGLE_VALUE_314159', 'raw x-goog-api-key');
});

ops006Test('redacts raw api-key values', function () {
    ops006AssertRedacted('api-key: SYNTHETIC_API_KEY_VALUE_314159', 'SYNTHETIC_API_KEY_VALUE_314159', 'raw api-key');
});

ops006Test('redacts raw x-api-key values', function () {
    ops006AssertRedacted('x-api-key: SYNTHETIC_X_API_KEY_VALUE_314159', 'SYNTHETIC_X_API_KEY_VALUE_314159', 'raw x-api-key');
});

ops006Test('redacts JSON API header values and leaves valid JSON', function () {
    $input = '{"Authorization":"Token SYNTHETIC_JSON_AUTH_271828","x-goog-api-key":"SYNTHETIC_JSON_GOOGLE_271828","api-key":"SYNTHETIC_JSON_API_271828","x-api-key":"SYNTHETIC_JSON_X_API_271828","keep":"ordinary text"}';
    $actual = ops006Redact($input);
    foreach (['SYNTHETIC_JSON_AUTH_271828', 'SYNTHETIC_JSON_GOOGLE_271828', 'SYNTHETIC_JSON_API_271828', 'SYNTHETIC_JSON_X_API_271828'] as $secret) {
        ops006Assert(strpos($actual, $secret) === false, 'A JSON header value remains in the output.');
    }
    ops006AssertSame(JSON_ERROR_NONE, json_last_error(), 'The input fixture should be valid JSON.');
    $decoded = json_decode($actual, true);
    ops006Assert(is_array($decoded), 'Redacted JSON should still decode.');
    ops006AssertSame('ordinary text', $decoded['keep'], 'Non-secret JSON values should remain unchanged.');
    ops006AssertSame('[REDACTED]', $decoded['Authorization'], 'The JSON Authorization value should be redacted.');
});

ops006Test('redacts PHP-export scalar header values', function () {
    $input = "array (\n  'Authorization' => 'Custom SYNTHETIC_EXPORT_AUTH_161803',\n  'x-goog-api-key' => 'SYNTHETIC_EXPORT_GOOGLE_161803',\n  'api-key' => 'SYNTHETIC_EXPORT_API_161803',\n  'x-api-key' => 'SYNTHETIC_EXPORT_X_API_161803',\n)";
    $actual = ops006Redact($input);
    foreach (['SYNTHETIC_EXPORT_AUTH_161803', 'SYNTHETIC_EXPORT_GOOGLE_161803', 'SYNTHETIC_EXPORT_API_161803', 'SYNTHETIC_EXPORT_X_API_161803'] as $secret) {
        ops006Assert(strpos($actual, $secret) === false, 'A PHP-export header value remains in the output.');
    }
    ops006Assert(substr_count($actual, '[REDACTED]') >= 4, 'Every PHP-export header should be redacted.');
});

ops006Test('redacts standalone Bearer tokens', function () {
    ops006AssertRedacted('request credential Bearer SYNTHETIC_BEARER_VALUE_141421 was rejected', 'SYNTHETIC_BEARER_VALUE_141421', 'Bearer token');
});

ops006Test('redacts sk-style keys', function () {
    $secret = 'sk-SYNTHETIC_FAKE_KEY_ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    ops006AssertRedacted('provider returned ' . $secret . ' during setup', $secret, 'sk-style key');
});

ops006Test('redacts key and api_key query values including percent-encoded values', function () {
    $input = 'https://example.invalid/generate?key=SYNTHETIC_QUERY_KEY_123456&api_key=SYNTHETIC_QUERY_API_KEY_123456&next=keep&other=SYNTHETIC%2DENCODED%2DQUERY%2DKEY';
    $actual = ops006Redact($input);
    foreach (['SYNTHETIC_QUERY_KEY_123456', 'SYNTHETIC_QUERY_API_KEY_123456', 'SYNTHETIC%2DENCODED%2DQUERY%2DKEY'] as $secret) {
        ops006Assert(strpos($actual, $secret) === false, 'A query parameter value remains in the output.');
    }
    ops006Assert(strpos($actual, 'next=keep') !== false, 'Adjacent non-secret query parameters should remain unchanged.');
});

ops006Test('redacts percent-encoded key parameter names', function () {
    $input = 'request key%3DSYNTHETIC_ENCODED_KEY_223606 and api_key%3DSYNTHETIC_ENCODED_API_KEY_223606';
    $actual = ops006Redact($input);
    ops006Assert(strpos($actual, 'SYNTHETIC_ENCODED_KEY_223606') === false, 'The percent-encoded key value remains in the output.');
    ops006Assert(strpos($actual, 'SYNTHETIC_ENCODED_API_KEY_223606') === false, 'The percent-encoded api_key value remains in the output.');
    ops006Assert(strpos($actual, '[REDACTED]') !== false, 'Encoded parameter values should receive a redaction marker.');
});

ops006Test('redacts array-valued Yii PHP-export headers', function () {
    $input = "array (\n  'x-api-key' => \n  array (\n    0 => 'SYNTHETIC_ARRAY_HEADER_KEY_173205',\n  ),\n)";
    ops006AssertRedacted($input, 'SYNTHETIC_ARRAY_HEADER_KEY_173205', 'array-valued PHP-export header');
});

ops006Test('keeps Yii serialized object export valid or redacts the whole value', function () {
    $secret = 'SYNTHETIC_SERIALIZED_OBJECT_KEY_244949';
    $serialized = serialize((object)['x-api-key' => $secret]);
    $input = "unserialize('" . addcslashes($serialized, "\\'") . "')";
    $actual = ops006Redact($input);
    ops006Assert(strpos($actual, $secret) === false, 'The serialized object secret remains in the output.');
    if ($actual === '[REDACTED]') {
        return;
    }
    ops006Assert(preg_match("/^unserialize\\('(.*)'\\)$/s", $actual, $matches) === 1, 'The serialized export should be valid or replaced as a whole.');
    $redactedValue = @unserialize(stripcslashes($matches[1]));
    ops006Assert(is_object($redactedValue), 'The returned serialized object export should still be valid.');
    ops006Assert(strpos(serialize($redactedValue), $secret) === false, 'The decoded serialized object should not contain its secret.');
});

ops006Test('redacts sensitive environment variable names in raw, JSON, and PHP-export forms', function () {
    $input = "OPENAI_API_KEY: SYNTHETIC_ENV_API_KEY_316227\n{\"SERVICE_SECRET\":\"SYNTHETIC_ENV_SECRET_316227\",\"DB_PASS\":\"SYNTHETIC_ENV_PASS_316227\",\"AUTH_TOKEN\":\"SYNTHETIC_ENV_TOKEN_316227\"}\n'INTEGRATION_API_KEY' => 'SYNTHETIC_EXPORT_ENV_KEY_316227'";
    $actual = ops006Redact($input);
    foreach (['SYNTHETIC_ENV_API_KEY_316227', 'SYNTHETIC_ENV_SECRET_316227', 'SYNTHETIC_ENV_PASS_316227', 'SYNTHETIC_ENV_TOKEN_316227', 'SYNTHETIC_EXPORT_ENV_KEY_316227'] as $secret) {
        ops006Assert(strpos($actual, $secret) === false, 'A sensitive environment value remains in the output.');
    }
    $jsonLine = explode("\n", $actual)[1];
    $decoded = json_decode($jsonLine, true);
    ops006Assert(is_array($decoded), 'Redacting environment values should leave the embedded JSON valid.');
});

ops006Test('leaves ordinary text and adjacent fields unchanged', function () {
    $input = 'ordinary report text; mode=summary; owner=synthetic; Authorization: Bearer SYNTHETIC_ADJACENT_AUTH_173205; tail=kept';
    $actual = ops006Redact($input);
    ops006Assert(strpos($actual, 'ordinary report text; mode=summary; owner=synthetic;') === 0, 'Non-secret text before the secret should remain intact.');
    ops006Assert(strpos($actual, '; tail=kept') !== false, 'Non-secret text after the secret should remain intact.');
    ops006Assert(strpos($actual, 'SYNTHETIC_ADJACENT_AUTH_173205') === false, 'The adjacent Authorization value should be redacted.');
});

ops006Test('redacts an HTTP body token crossing Yii client’s 2,000-byte logging boundary', function () {
    $secret = 'SYNTHETIC_HTTP_BODY_BOUNDARY_KEY_0123456789';
    $body = str_repeat('x', 1978) . '{"x-api-key":"' . $secret . '"}';
    $client = new \yii\httpclient\Client(['contentLoggingMaxSize' => 2000]);
    $requestLog = $client->createRequestLogToken('POST', 'https://example.invalid/generate', [], $body);
    ops006Assert(strpos($requestLog, substr($secret, 0, 8)) !== false, 'The fixture should place a partial token at the 2,000-byte truncation boundary.');
    $actual = ops006Redact($requestLog);
    ops006Assert(strpos($actual, substr($secret, 0, 8)) === false, 'A partial API key at the truncation boundary remains in the log token.');
});

exit(ops006Finish());
