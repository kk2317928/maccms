<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/application/common/util/ExternalHttpPolicy.php';
require_once $root . '/application/common/util/HardenedHttpClient.php';

use app\common\util\ExternalHttpPolicy;
use app\common\util\HardenedHttpClient;

function release_security_assert($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

function release_security_reject(callable $callback, string $message): void
{
    try { $callback(); } catch (InvalidArgumentException $exception) { return; }
    fwrite(STDERR, "FAIL: {$message}\n"); exit(1);
}

$answers = [
    'public.example' => ['93.184.216.34'],
    'metadata.example' => ['169.254.169.254'],
    'loopback.example' => ['::1'],
    'private-v6.example' => ['fc00::10'],
];
$policy = new ExternalHttpPolicy(static function (string $host) use ($answers): array {
    return $answers[$host] ?? [];
});

foreach (['metadata.example', 'loopback.example', 'private-v6.example'] as $host) {
    release_security_reject(
        static fn () => $policy->validate('https://' . $host . '/', [$host]),
        $host . ' must be rejected as non-public.'
    );
}

$redacted = $policy->redact('https://public.example/?client_secret=one&signature=two&password=three');
release_security_assert(
    strpos($redacted, 'one') === false && strpos($redacted, 'two') === false && strpos($redacted, 'three') === false,
    'client secrets, signatures and passwords must be redacted.'
);

$transportCalls = 0;
$transport = static function (string $url, array $options) use (&$transportCalls): array {
    $transportCalls++;
    return ['status' => 200, 'headers' => [], 'body' => '{}'];
};
$client = new HardenedHttpClient($policy, $transport);

release_security_reject(
    static fn () => $client->get('https://public.example/', [
        'allowed_hosts' => ['public.example'],
        'headers' => ['Host' => 'metadata.example'],
    ]),
    'caller-supplied Host headers must be rejected.'
);
release_security_assert($transportCalls === 0, 'invalid Host header must fail before transport.');

release_security_reject(
    static fn () => $client->post('https://public.example/', str_repeat('x', 9), [
        'allowed_hosts' => ['public.example'],
        'max_request_bytes' => 8,
    ]),
    'oversized request bodies must be rejected.'
);

$dnsCalls = 0;
$rebindingPolicy = new ExternalHttpPolicy(static function (string $host) use (&$dnsCalls): array {
    $dnsCalls++;
    return $dnsCalls === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
});
$responses = [
    ['status' => 302, 'headers' => ['location' => 'https://public.example/final'], 'body' => ''],
];
$rebindingClient = new HardenedHttpClient($rebindingPolicy, static function () use (&$responses): array {
    return array_shift($responses);
});
release_security_reject(
    static fn () => $rebindingClient->get('https://public.example/start', [
        'allowed_hosts' => ['public.example'],
        'max_redirects' => 1,
    ]),
    'redirect DNS must be resolved and validated again.'
);
release_security_assert($dnsCalls === 2, 'redirect validation must perform a fresh DNS resolution.');

fwrite(STDOUT, "OK: release security edge-case contract passed.\n");
