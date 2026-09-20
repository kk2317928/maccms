<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/使用說明.md';
if (!is_file($path) || filesize($path) < 5000) {
    fwrite(STDERR, "FAIL: 使用說明.md is missing or incomplete.\n");
    exit(1);
}
$content = (string) file_get_contents($path);
$required = [
    '# MACCMS Headless AI 使用說明',
    'php think maccms:migrate',
    'php think maccms:jobs',
    'php think maccms:analytics',
    'content_workspace/view',
    'title_tw',
    'title_cn',
    'title_en',
    'MACCMS_API_V1_JWT_SECRET',
    '/api/v1/openapi.json',
    'docs/testing/native-smoke-checklist.md',
    'v1.0.3-rc1',
    '只能用於測試',
];
foreach ($required as $needle) {
    if (strpos($content, $needle) === false) {
        fwrite(STDERR, "FAIL: usage guide is missing: {$needle}\n");
        exit(1);
    }
}
fwrite(STDOUT, "OK: Traditional Chinese usage guide covers installation, operations and acceptance.\n");
