<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$policyPath = $root . '/application/common/util/ExternalHttpPolicy.php';
$clientPath = $root . '/application/common/util/HardenedHttpClient.php';
if (!is_file($policyPath) || !is_file($clientPath)) {
    fwrite(STDERR, "FAIL: hardened HTTP boundary files are missing.\n");
    exit(1);
}
require_once $policyPath;
require_once $clientPath;

use app\common\util\ExternalHttpPolicy;
use app\common\util\HardenedHttpClient;

function httpAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function httpReject(callable $callback, string $message): void
{
    try { $callback(); } catch (InvalidArgumentException $exception) { return; }
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$dns = static function (string $host): array {
    $map = [
        'api.example.com' => ['93.184.216.34'],
        'cdn.example.com' => ['93.184.216.35'],
        'mixed.example.com' => ['93.184.216.34', '127.0.0.1'],
        'internal.example.com' => ['10.0.0.2'],
    ];
    return $map[$host] ?? [];
};
$policy = new ExternalHttpPolicy($dns);
$validated = $policy->validate('https://api.example.com/v1/items', ['api.example.com']);
httpAssert($validated['host'] === 'api.example.com' && $validated['ips'] === ['93.184.216.34'], 'public allowlisted HTTPS URL must validate.');
httpReject(fn () => $policy->validate('http://api.example.com/', ['api.example.com']), 'plain HTTP must be rejected by default.');
httpReject(fn () => $policy->validate('https://user:pass@api.example.com/', ['api.example.com']), 'URL credentials must be rejected.');
httpReject(fn () => $policy->validate('https://internal.example.com/', ['internal.example.com']), 'private DNS answers must be rejected.');
httpReject(fn () => $policy->validate('https://mixed.example.com/', ['mixed.example.com']), 'mixed public/private DNS answers must be rejected.');
httpReject(fn () => $policy->validate('https://cdn.example.com/', ['api.example.com']), 'non-allowlisted hosts must be rejected.');

$redacted = $policy->redact("Authorization: Bearer topsecret\nhttps://api.example.com/?api_key=abc&x=1");
httpAssert(strpos($redacted, 'topsecret') === false && strpos($redacted, 'abc') === false && strpos($redacted, '[REDACTED]') !== false, 'headers and sensitive query values must be redacted.');

$responses = [
    ['status' => 302, 'headers' => ['location' => 'https://cdn.example.com/final'], 'body' => ''],
    ['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => '{"ok":true}'],
];
$transport = static function (string $url, array $options) use (&$responses): array {
    return array_shift($responses);
};
$client = new HardenedHttpClient($policy, $transport);
$response = $client->get('https://api.example.com/start', [
    'allowed_hosts' => ['api.example.com', 'cdn.example.com'],
    'timeout' => 5, 'max_bytes' => 64, 'max_redirects' => 2,
]);
httpAssert($response['status'] === 200 && $response['body'] === '{"ok":true}', 'validated redirect chain must return the final response.');

$oversized = new HardenedHttpClient($policy, static fn (): array => ['status' => 200, 'headers' => [], 'body' => str_repeat('x', 9)]);
httpReject(fn () => $oversized->get('https://api.example.com/', ['allowed_hosts' => ['api.example.com'], 'max_bytes' => 8]), 'oversized responses must be rejected.');

$badRedirect = new HardenedHttpClient($policy, static fn (): array => ['status' => 302, 'headers' => ['location' => 'https://internal.example.com/'], 'body' => '']);
httpReject(fn () => $badRedirect->get('https://api.example.com/', ['allowed_hosts' => ['api.example.com', 'internal.example.com']]), 'redirect targets must be revalidated.');

fwrite(STDOUT, "OK: hardened external HTTP boundary contract passed.\n");
