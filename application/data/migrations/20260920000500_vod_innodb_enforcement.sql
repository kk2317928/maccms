-- Ensure existing native video rows participate in atomic publication transactions.
-- This is intentionally a new version because the earlier migration may already be ledgered.
SET @maccms_vod_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '__PREFIX__vod'
);
SET @maccms_vod_engine_sql = IF(
    @maccms_vod_exists = 1,
    'ALTER TABLE `__PREFIX__vod` ENGINE=InnoDB',
    'SELECT 1'
);
PREPARE maccms_vod_engine_statement_v2 FROM @maccms_vod_engine_sql;
EXECUTE maccms_vod_engine_statement_v2;
DEALLOCATE PREPARE maccms_vod_engine_statement_v2;
