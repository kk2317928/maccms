<?php

declare(strict_types=1);

$mode = isset($argv[1]) ? $argv[1] : '--report';
if (!in_array($mode, ['--report', '--enforce'], true)) {
    fwrite(STDERR, "Usage: php outbound_inventory.php [--report|--enforce]\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$reportPath = $root . '/docs/security/outbound-inventory.md';
$scanRoots = ['application', 'addons', 'static_new'];
$extensions = ['php' => true, 'js' => true, 'html' => true, 'json' => true, 'ini' => true, 'yml' => true, 'yaml' => true];
$skipParts = ['/vendor/', '/thinkphp/', '/static_new/swagger/', '/static_new/editor/', '/static_new/player/'];

$filesScanned = 0;
$urlReferences = 0;
$networkPrimitives = 0;
$dynamicScripts = 0;
$officialUpdateEvidence = [];

foreach ($scanRoots as $relativeRoot) {
    $absoluteRoot = $root . '/' . $relativeRoot;
    if (!is_dir($absoluteRoot)) {
        fwrite(STDERR, "Missing scan root: {$relativeRoot}\n");
        exit(1);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        $relativePath = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
        $skip = false;
        foreach ($skipParts as $part) {
            if (strpos('/' . $relativePath, $part) !== false) {
                $skip = true;
                break;
            }
        }
        if ($skip || !isset($extensions[strtolower(pathinfo($path, PATHINFO_EXTENSION))])) {
            continue;
        }

        $source = file_get_contents($path);
        if ($source === false) {
            fwrite(STDERR, "Unable to read: {$relativePath}\n");
            exit(1);
        }
        $filesScanned++;

        $urlReferences += preg_match_all('~(?:https?:)?//[a-z0-9][a-z0-9._-]+(?:\.[a-z]{2,})(?::[0-9]+)?~i', $source, $unused);
        $networkPrimitives += preg_match_all('~\b(?:curl_init|curl_exec|fsockopen|pfsockopen|stream_socket_client|file_get_contents|fopen|readfile)\s*\(~i', $source, $unused);
        $dynamicScripts += preg_match_all('~(?:createElement\s*\(\s*[\'\"]script|<script[^>]+src=|append\s*\([^\n]{0,160}scr)~i', $source, $unused);

        $hasLiteralUpdate = stripos($source, 'update.maccms.la') !== false;
        $hasPackedUpdate = strpos($relativePath, 'static_new/js/admin_common.js') !== false
            && strpos($source, "'update|maccms|la|") !== false
            && strpos($source, 'eval(function') !== false;
        $hasUpdateDownloader = strpos($relativePath, 'static_new/js/update.js') !== false
            && stripos($source, "domain = 'update.maccms.la/'") !== false;
        if ($hasLiteralUpdate || $hasPackedUpdate || $hasUpdateDownloader) {
            $officialUpdateEvidence[] = $relativePath;
        }
    }
}

$officialUpdateEvidence = array_values(array_unique($officialUpdateEvidence));
sort($officialUpdateEvidence, SORT_STRING);

if (!is_file($reportPath)) {
    fwrite(STDERR, "Outbound inventory document is missing: docs/security/outbound-inventory.md\n");
    exit(1);
}
$report = file_get_contents($reportPath);
if ($report === false) {
    fwrite(STDERR, "Unable to read outbound inventory document.\n");
    exit(1);
}

$requiredReportTerms = [
    'required/configured',
    'optional disabled-by-default',
    'prohibited',
    'test/documentation-only',
    'SSRF-sensitive',
    'update.maccms.la',
    'TMDB',
    'AI providers',
    'S3',
    'SMTP',
    'SMS',
    'payment',
    'push/webhook',
    'collection/resource',
];
foreach ($requiredReportTerms as $term) {
    if (stripos($report, $term) === false) {
        fwrite(STDERR, "Outbound inventory document is missing classification term: {$term}\n");
        exit(1);
    }
}

if ($officialUpdateEvidence !== []) {
    fwrite(STDERR, "PROHIBITED: update.maccms.la remains in " . implode(', ', $officialUpdateEvidence) . "\n");
    exit(1);
}

fwrite(STDOUT, "Outbound source inventory\n");
fwrite(STDOUT, "Files scanned: {$filesScanned}\n");
fwrite(STDOUT, "URL references: {$urlReferences}\n");
fwrite(STDOUT, "Network primitives: {$networkPrimitives}\n");
fwrite(STDOUT, "Dynamic script indicators: {$dynamicScripts}\n");

fwrite(STDOUT, "PASS: prohibited official update endpoint is absent.\n");
fwrite(STDOUT, "PASS: outbound endpoint families are inventoried and enforcement is active.\n");
exit(0);
