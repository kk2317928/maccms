SET @ai_review_baseline_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = '__PREFIX__content_ai_field_review'
     AND COLUMN_NAME = 'baseline_hash') = 0,
  'ALTER TABLE `__PREFIX__content_ai_field_review` ADD COLUMN `baseline_value_json` mediumtext NULL AFTER `candidate_value_json`, ADD COLUMN `baseline_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `baseline_value_json`',
  'SELECT 1'
);
PREPARE ai_review_baseline_stmt FROM @ai_review_baseline_sql;
EXECUTE ai_review_baseline_stmt;
DEALLOCATE PREPARE ai_review_baseline_stmt;

ALTER TABLE `__PREFIX__content_ai_field_review`
  MODIFY COLUMN `decision` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  MODIFY COLUMN `actor_id` int(10) unsigned NOT NULL DEFAULT '0';
