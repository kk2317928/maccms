<?php

declare(strict_types=1);

$inventory = __DIR__ . '/outbound_inventory.php';
if (!is_file($inventory)) {
    fwrite(STDERR, "Outbound inventory script is missing: {$inventory}\n");
    exit(1);
}

function run_inventory($inventory, $mode)
{
    $lines = [];
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($inventory) . ' ' . $mode . ' 2>&1';
    exec($command, $lines, $exitCode);

    return [$exitCode, implode("\n", $lines)];
}

[$reportCode, $reportOutput] = run_inventory($inventory, '--report');
if ($reportCode !== 0) {
    fwrite(STDERR, "Report mode must succeed.\n{$reportOutput}\n");
    exit(1);
}
if (strpos($reportOutput, 'update.maccms.la') === false || strpos($reportOutput, 'QUARANTINED') === false) {
    fwrite(STDERR, "Report mode must expose the quarantined official update endpoint.\n{$reportOutput}\n");
    exit(1);
}

[$enforceCode, $enforceOutput] = run_inventory($inventory, '--enforce');
if ($enforceCode === 0) {
    fwrite(STDERR, "Enforcement mode must fail while the prohibited endpoint remains.\n{$enforceOutput}\n");
    exit(1);
}
if (strpos($enforceOutput, 'update.maccms.la') === false || strpos($enforceOutput, 'PROHIBITED') === false) {
    fwrite(STDERR, "Enforcement mode must identify the prohibited endpoint.\n{$enforceOutput}\n");
    exit(1);
}

fwrite(STDOUT, "Outbound inventory contract passed.\n");
