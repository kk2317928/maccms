<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$commandPath = $root . '/application/command/MaccmsMigrate.php';
$configPath = $root . '/application/command.php';
$entrypointPath = $root . '/think';

if (!is_file($commandPath)) {
    fwrite(STDERR, "FAIL: MaccmsMigrate command is missing.\n");
    exit(1);
}

$command = file_get_contents($commandPath);
$config = file_get_contents($configPath);
$entrypoint = file_get_contents($entrypointPath);
$checks = [
    [strpos($command, "setName('maccms:migrate')") !== false, 'command name must be maccms:migrate.'],
    [strpos($command, 'SchemaMigrationService') !== false, 'command must use SchemaMigrationService.'],
    [strpos($command, "APP_PATH . 'data/migrations'") !== false, 'command must use the application migration directory.'],
    [strpos($command, "config('database.prefix')") !== false, 'command must use the configured table prefix.'],
    [strpos($config, "'app\\\\command\\\\SeoAiGenerate'") !== false, 'SeoAiGenerate registration must remain.'],
    [strpos($config, "'app\\\\command\\\\MaccmsMigrate'") !== false, 'MaccmsMigrate must be registered.'],
    [strpos($entrypoint, "define('ENTRANCE', 'command')") !== false, 'CLI entrypoint must define the neutral command entrance before application bootstrap.'],
];

foreach ($checks as $check) {
    if (!$check[0]) {
        fwrite(STDERR, 'FAIL: ' . $check[1] . "\n");
        exit(1);
    }
}

fwrite(STDOUT, "OK: migration CLI contract passed.\n");
