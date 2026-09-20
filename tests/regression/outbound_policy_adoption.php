<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$client = (string) file_get_contents($root . '/application/common/util/HardenedHttpClient.php');

$errors = [];
if (strpos($client, 'public function post(') === false) {
    $errors[] = 'HardenedHttpClient must provide a bounded POST boundary.';
}
if (strpos($client, "'method' => 'POST'") === false) {
    $errors[] = 'HardenedHttpClient POST must pass an explicit method to the transport.';
}

$providers = [
    'application/common/util/AiProvider.php',
    'application/common/util/TmdbExternalSourceProvider.php',
    'application/common/util/ImdbExternalSourceProvider.php',
    'application/common/util/DoubanExternalSourceProvider.php',
];
foreach ($providers as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    if (strpos($source, 'HardenedHttpClient') === false || strpos($source, 'ExternalHttpPolicy') === false) {
        $errors[] = $relative . ' does not use the centralized outbound boundary.';
    }
    if (strpos($source, 'HttpClient::curlPostWithTimeout') !== false
        || preg_match('/curl_(?:init|exec)\s*\(/', $source)) {
        $errors[] = $relative . ' retains a direct HTTP transport bypass.';
    }
    if (strpos($source, 'CURLOPT_SSL_VERIFYPEER') !== false) {
        $errors[] = $relative . ' retains caller-controlled TLS verification.';
    }
}

if ($errors) {
    foreach ($errors as $error) {
        fwrite(STDERR, "FAIL: {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "OK: retained content providers use the centralized outbound policy.\n");
