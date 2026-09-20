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
if (count($ledger) !== 17
    || $ledger[0]['version'] !== '20260918000100'
    || $ledger[1]['version'] !== '20260918000200'
    || $ledger[2]['version'] !== '20260918000300'
    || $ledger[3]['version'] !== '20260918000400'
    || $ledger[4]['version'] !== '20260918000500'
    || $ledger[5]['version'] !== '20260918000600'
    || $ledger[6]['version'] !== '20260918000700'
    || $ledger[7]['version'] !== '20260918000800'
    || $ledger[8]['version'] !== '20260919000100'
    || $ledger[9]['version'] !== '20260919000200'
    || $ledger[10]['version'] !== '20260919000300'
    || $ledger[11]['version'] !== '20260919000400'
    || !preg_match('/^[a-f0-9]{64}$/', $ledger[0]['checksum'])
    || !preg_match('/^[a-f0-9]{64}$/', $ledger[1]['checksum'])
    || !preg_match('/^[a-f0-9]{64}$/', $ledger[2]['checksum'])
    || !preg_match('/^[a-f0-9]{64}$/', $ledger[3]['checksum'])
    || !preg_match('/^[a-f0-9]{64}$/', $ledger[4]['checksum'])
    || !preg_match('/^[a-f0-9]{64}$/', $ledger[5]['checksum'])
    || !preg_match('/^[a-f0-9]{64}$/', $ledger[6]['checksum'])
    || !preg_match('/^[a-f0-9]{64}$/', $ledger[7]['checksum'])
    || !preg_match('/^[a-f0-9]{64}$/', $ledger[8]['checksum'])
    || !preg_match('/^[a-f0-9]{64}$/', $ledger[9]['checksum'])
    || !preg_match('/^[a-f0-9]{64}$/', $ledger[10]['checksum'])
    || $ledger[12]['version'] !== '20260919000500'
    || !preg_match('/^[a-f0-9]{64}$/', $ledger[11]['checksum'])
    || !preg_match('/^[a-f0-9]{64}$/', $ledger[12]['checksum'])) {
    fwrite(STDERR, "FAIL: migration ledger does not contain all expected checksummed versions.\n");
    exit(1);
}

$requiredIndexes = [
    'mac_vod_ext' => ['PRIMARY', 'uk_public_id', 'idx_workflow', 'idx_tmdb', 'idx_merged_into'],
    'mac_meta_term' => ['PRIMARY', 'uk_kind_slug', 'idx_kind_status_sort'],
    'mac_vod_meta_term' => ['uk_vod_term', 'idx_term_id'],
    'mac_vod_field_state' => ['uk_vod_field', 'idx_source', 'idx_locked'],
    'mac_content_job' => ['PRIMARY', 'uk_type_idempotency', 'idx_claim', 'idx_lock'],
    'mac_content_job_run' => ['PRIMARY', 'uk_job_attempt', 'idx_job_status'],
    'mac_content_worker_heartbeat' => ['PRIMARY', 'idx_seen'],
    'mac_content_ai_run' => ['PRIMARY', 'idx_vod_created', 'idx_job_id', 'idx_daily_usage'],
    'mac_content_duplicate_candidate' => ['PRIMARY', 'uk_candidate_pair', 'idx_decision_score', 'idx_vod_high', 'idx_invalidation'],
    'mac_content_merge_snapshot' => ['PRIMARY', 'uk_duplicate_candidate', 'idx_primary_status', 'idx_secondary_status'],
    'mac_content_admin_audit_event' => ['PRIMARY', 'idx_actor_created', 'idx_event_created', 'idx_subject'],
    'mac_content_ai_field_review' => ['PRIMARY', 'uk_run_field', 'idx_vod_decision', 'idx_decision_updated'],
    'mac_content_tmdb_review' => ['PRIMARY', 'uk_vod_revision', 'idx_status_updated', 'idx_selected'],
];
$tableStatement = $pdo->prepare(
    'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
);
$indexStatement = $pdo->prepare(
    'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
);
$tableStatement->execute([$database, 'mac_vod']);
$vodTableInfo = $tableStatement->fetch(PDO::FETCH_ASSOC);
if ($vodTableInfo && strtoupper((string) $vodTableInfo['ENGINE']) !== 'INNODB') {
    fwrite(STDERR, "FAIL: mac_vod must use InnoDB for atomic publication.\n");
    exit(1);
}
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
$reviewColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mac_content_ai_field_review'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['baseline_value_json', 'baseline_hash'] as $column) {
    if (!in_array($column, $reviewColumns, true)) { fwrite(STDERR, "FAIL: AI field review is missing {$column}.\n"); exit(1); }
}

$sessionColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mac_api_refresh_session'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['session_id', 'family_id', 'user_id', 'token_hash', 'consumed_at', 'revoked_at', 'expires_at'] as $column) {
    if (!in_array($column, $sessionColumns, true)) { fwrite(STDERR, "FAIL: API refresh session is missing {$column}.\n"); exit(1); }
}
$sessionIndexes = $pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mac_api_refresh_session'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['uk_api_refresh_token_hash', 'idx_api_refresh_family', 'idx_api_refresh_user_session'] as $index) {
    if (!in_array($index, $sessionIndexes, true)) { fwrite(STDERR, "FAIL: API refresh session is missing {$index}.\n"); exit(1); }
}

fwrite(STDOUT, "OK: MySQL {$version} foundation migration invariants passed for {$database}.\n");
