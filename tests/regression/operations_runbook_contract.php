<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/docs/deployment/operations-runbook.md';
if (!is_file($path)) {
    fwrite(STDERR, "FAIL: operations runbook is missing.\n");
    exit(1);
}
$doc = (string) file_get_contents($path);
$required = [
    '# Deployment and recovery runbook',
    'PHP 8.1',
    'MySQL 5.7',
    'MySQL 8.0',
    'php think maccms:migrate',
    'php think maccms:jobs',
    'php think maccms:analytics',
    'mysqldump --single-transaction',
    'application/extra',
    'application/data',
    'upload/',
    'maintenance mode',
    'health check',
    'rollback',
    'disaster recovery',
    'restore',
    'RPO',
    'RTO',
    'Do not',
    'release-regression.yml',
    'outbound_inventory.php --enforce',
];
foreach ($required as $needle) {
    if (stripos($doc, $needle) === false) {
        fwrite(STDERR, "FAIL: operations runbook missing: {$needle}\n");
        exit(1);
    }
}
fwrite(STDOUT, "OK: deployment and recovery runbook contract passed.\n");
