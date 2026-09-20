<?php
declare(strict_types=1);

$tests = array(
    __DIR__ . '/api_v1_foundation.php',
    __DIR__ . '/api_v1_public_catalog.php',
    __DIR__ . '/api_v1_locale_canonical.php',
    __DIR__ . '/api_v1_sessions.php',
    __DIR__ . '/api_v1_member_activity.php',
    __DIR__ . '/api_v1_playback.php',
    __DIR__ . '/api_v1_delivery_policy.php',
    __DIR__ . '/api_v1_openapi.php',
    __DIR__ . '/api_v1_events.php',
    __DIR__ . '/api_v1_rankings.php',
    __DIR__ . '/api_v1_recommendations.php',
    __DIR__ . '/api_v1_event_policy.php',
    __DIR__ . '/api_v1_discovery.php',
    __DIR__ . '/api_v1_import.php',
    __DIR__ . '/api_v1_people_site_config.php',
);

foreach ($tests as $test) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test);
    fwrite(STDOUT, '$ ' . $command . PHP_EOL);
    passthru($command, $status);
    if ($status !== 0) {
        fwrite(STDERR, basename($test) . ' failed with status ' . $status . PHP_EOL);
        exit($status);
    }
}

fwrite(STDOUT, "API v1 regression suite passed." . PHP_EOL);
