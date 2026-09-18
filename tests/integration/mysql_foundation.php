<?php

declare(strict_types=1);

$database = (string) getenv('MACCMS_TEST_DATABASE');
if (!preg_match('/^maccms_ci_[a-z0-9_]+$/', $database)) {
    fwrite(STDERR, "FAIL: MACCMS_TEST_DATABASE must name a disposable maccms_ci_ database.\n");
    exit(1);
}

$host = getenv('MACCMS_TEST_HOST') ?: '127.0.0.1';
$port = getenv('MACCMS_TEST_PORT') ?: '3306';
$user = getenv('MACCMS_TEST_USER') ?: 'root';
$password = getenv('MACCMS_TEST_PASSWORD') ?: '';
$expectedFamily = (string) getenv('MACCMS_TEST_MYSQL_FAMILY');
if (!in_array($expectedFamily, ['5.7', '8.0'], true)) {
    fwrite(STDERR, "FAIL: MACCMS_TEST_MYSQL_FAMILY must be 5.7 or 8.0.\n");
    exit(1);
}
$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
if (strpos($version, $expectedFamily . '.') !== 0) {
    fwrite(STDERR, "FAIL: expected MySQL {$expectedFamily}, got {$version}.\n");
    exit(1);
}

$ledger = $pdo->query('SELECT version, checksum FROM mac_schema_migration ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
if (count($ledger) !== 1
    || $ledger[0]['version'] !== '20260918000100'
    || !preg_match('/^[a-f0-9]{64}$/', $ledger[0]['checksum'])) {
    fwrite(STDERR, "FAIL: migration ledger does not contain the single expected checksummed version.\n");
    exit(1);
}

$requiredIndexes = [
    'mac_vod_ext' => ['PRIMARY', 'uk_public_id', 'idx_workflow', 'idx_tmdb', 'idx_merged_into'],
    'mac_meta_term' => ['PRIMARY', 'uk_kind_slug', 'idx_kind_status_sort'],
    'mac_vod_meta_term' => ['uk_vod_term', 'idx_term_id'],
    'mac_vod_field_state' => ['uk_vod_field', 'idx_source', 'idx_locked'],
];
$tableStatement = $pdo->prepare(
    'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
);
$indexStatement = $pdo->prepare(
    'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
);
foreach ($requiredIndexes as $table => $expectedIndexes) {
    $tableStatement->execute([$database, $table]);
    $tableInfo = $tableStatement->fetch(PDO::FETCH_ASSOC);
    if (!$tableInfo || $tableInfo['ENGINE'] !== 'InnoDB' || strpos($tableInfo['TABLE_COLLATION'], 'utf8mb4_') !== 0) {
        fwrite(STDERR, "FAIL: {$table} must exist as InnoDB/utf8mb4.\n");
        exit(1);
    }
    $indexStatement->execute([$database, $table]);
    $actualIndexes = $indexStatement->fetchAll(PDO::FETCH_COLUMN);
    foreach ($expectedIndexes as $index) {
        if (!in_array($index, $actualIndexes, true)) {
            fwrite(STDERR, "FAIL: {$table} is missing index {$index}.\n");
            exit(1);
        }
    }
}

fwrite(STDOUT, "OK: MySQL {$version} foundation migration invariants passed for {$database}.\n");
