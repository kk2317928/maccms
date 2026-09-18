<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$servicePath = $root . '/application/common/util/SchemaMigrationService.php';
if (!is_file($servicePath)) {
    fwrite(STDERR, "FAIL: SchemaMigrationService is missing.\n");
    exit(1);
}
require $servicePath;

use app\common\util\SchemaMigrationService;

class DiscoveryMigrationService extends SchemaMigrationService
{
    private $appliedRows;

    public function __construct(string $directory, string $prefix, array $appliedRows)
    {
        parent::__construct($directory, $prefix);
        $this->appliedRows = $appliedRows;
    }

    protected function appliedMigrations(): array
    {
        return $this->appliedRows;
    }
}

function migration_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$directory = sys_get_temp_dir() . '/maccms-migrations-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    fwrite(STDERR, "FAIL: unable to create migration fixture directory.\n");
    exit(1);
}

$fixtures = [
    '20260918000200_second.sql' => "SELECT 'second;value';\n",
    '20260918000100_first.sql' => "SELECT 1;\nSELECT \"a;b\";\n",
    '20260918000300_BAD.sql' => "SELECT 3;\n",
    'notes.sql' => "SELECT 4;\n",
];

try {
    foreach ($fixtures as $name => $sql) {
        file_put_contents($directory . '/' . $name, $sql);
    }

    $service = new DiscoveryMigrationService($directory, 'mac_', []);
    $pending = $service->pending();

    migration_assert(count($pending) === 2, 'only valid migration filenames should be discovered.');
    migration_assert($pending[0]['version'] === '20260918000100', 'migrations must sort by version ascending.');
    migration_assert($pending[1]['version'] === '20260918000200', 'second migration order is incorrect.');
    migration_assert(
        $pending[0]['checksum'] === hash('sha256', $fixtures['20260918000100_first.sql']),
        'checksum must use the original SQL bytes.'
    );

    $applied = [
        '20260918000100' => $pending[0]['checksum'],
    ];
    $remaining = (new DiscoveryMigrationService($directory, 'mac_', $applied))->pending();
    migration_assert(count($remaining) === 1 && $remaining[0]['version'] === '20260918000200', 'matching applied migration must be skipped.');

    $allApplied = [
        '20260918000100' => $pending[0]['checksum'],
        '20260918000200' => $pending[1]['checksum'],
    ];
    $secondRun = (new DiscoveryMigrationService($directory, 'mac_', $allApplied))->pending();
    migration_assert($secondRun === [], 'a second run with matching ledger rows must be a no-op.');

    $checksumRejected = false;
    try {
        (new DiscoveryMigrationService($directory, 'mac_', ['20260918000100' => str_repeat('0', 64)]))->pending();
    } catch (RuntimeException $exception) {
        $checksumRejected = strpos($exception->getMessage(), 'checksum') !== false;
    }
    migration_assert($checksumRejected, 'changed checksum for an applied version must be rejected.');

    $prefixRejected = false;
    try {
        new DiscoveryMigrationService($directory, 'mac-unsafe;', []);
    } catch (InvalidArgumentException $exception) {
        $prefixRejected = true;
    }
    migration_assert($prefixRejected, 'unsafe table prefix must be rejected.');

    fwrite(STDOUT, "OK: migration discovery and checksum contract passed.\n");
} finally {
    foreach (array_keys($fixtures) as $name) {
        @unlink($directory . '/' . $name);
    }
    @rmdir($directory);
}
