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

foreach (['--report', '--enforce'] as $mode) {
    [$exitCode, $output] = run_inventory($inventory, $mode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "{$mode} must succeed after prohibited updater removal.\n{$output}\n");
        exit(1);
    }
    if (strpos($output, 'official update endpoint is absent') === false
        || strpos($output, 'enforcement is active') === false) {
        fwrite(STDERR, "{$mode} did not confirm active prohibited-endpoint enforcement.\n{$output}\n");
        exit(1);
    }
}

fwrite(STDOUT, "Outbound inventory contract passed.\n");
