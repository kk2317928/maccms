<?php

$root = dirname(__DIR__, 2);
$errors = [];

function inventory_assert($condition, $message)
{
    global $errors;
    if (!$condition) {
        $errors[] = $message;
    }
}

function inventory_php_count($directory)
{
    $files = glob($directory . '/*.php');
    return is_array($files) ? count($files) : 0;
}

function inventory_line_count($path)
{
    $content = @file_get_contents($path);
    return is_string($content) ? substr_count($content, "\n") : -1;
}

$requiredFiles = [
    'index.php',
    'api.php',
    'admin.php',
    'install.php',
    'think',
    'application/command.php',
    'application/common/model/Vod.php',
    'application/common/model/Collect.php',
    'application/common/util/AiProvider.php',
    'application/common/util/TmdbExternalSourceProvider.php',
    'application/common/util/JwtService.php',
    'application/common/util/OpenApiSpec.php',
    'tests/regression/user_register_validate.php',
];

foreach ($requiredFiles as $relativePath) {
    inventory_assert(is_file($root . '/' . $relativePath), 'Missing required file: ' . $relativePath);
}

inventory_assert(is_dir($root . '/application/admin/view_new'), 'Active admin template directory is missing.');
inventory_assert(!is_dir($root . '/application/admin/view'), 'Unexpected legacy admin view directory exists.');

$counts = [
    'admin_controllers' => inventory_php_count($root . '/application/admin/controller'),
    'api_controllers' => inventory_php_count($root . '/application/api/controller'),
    'index_controllers' => inventory_php_count($root . '/application/index/controller'),
    'common_models' => inventory_php_count($root . '/application/common/model'),
    'common_utilities' => inventory_php_count($root . '/application/common/util'),
];

$expectedCounts = [
    'admin_controllers' => 71,
    'api_controllers' => 39,
    'index_controllers' => 24,
    'common_models' => 68,
    'common_utilities' => 112,
];

foreach ($expectedCounts as $name => $expected) {
    inventory_assert($counts[$name] === $expected, sprintf('%s changed: expected %d, got %d.', $name, $expected, $counts[$name]));
}

$lineCounts = [
    'application/common/model/Collect.php' => 3192,
    'application/common/model/Vod.php' => 1025,
    'application/common/util/OpenApiSpec.php' => 1158,
    'application/data/update/database.php' => 1520,
];

foreach ($lineCounts as $relativePath => $expected) {
    $actual = inventory_line_count($root . '/' . $relativePath);
    inventory_assert($actual === $expected, sprintf('%s line count changed: expected %d, got %d.', $relativePath, $expected, $actual));
}

$commandConfig = @file_get_contents($root . '/application/command.php');
inventory_assert(is_string($commandConfig) && strpos($commandConfig, "app\\\\command\\\\SeoAiGenerate") !== false, 'SeoAiGenerate is not registered.');
inventory_assert(is_string($commandConfig) && strpos($commandConfig, "app\\\\command\\\\MaccmsJobs") !== false, 'MaccmsJobs is not registered.');

$inventoryDocument = $root . '/docs/development/source-inventory.md';
inventory_assert(is_file($inventoryDocument), 'Source inventory document is missing.');
if (is_file($inventoryDocument)) {
    $document = (string) file_get_contents($inventoryDocument);
    foreach ($expectedCounts as $name => $expected) {
        inventory_assert(strpos($document, '`' . $name . '` | ' . $expected) !== false, 'Inventory document is stale for ' . $name . '.');
    }
    inventory_assert(strpos($document, '`SeoAiGenerate`') !== false, 'Inventory document does not record the CLI command.');
    inventory_assert(strpos($document, '`user_register_validate.php`') !== false, 'Inventory document does not record the pre-existing regression test.');
}

if ($errors) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }
    exit(1);
}

echo 'OK: source inventory baseline matches.' . PHP_EOL;
