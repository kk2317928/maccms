CREATE TABLE IF NOT EXISTS `__PREFIX__content_ai_field_review` (
  `review_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ai_run_id` bigint(20) unsigned NOT NULL,
  `vod_id` int(10) unsigned NOT NULL,
  `field_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `candidate_value_json` mediumtext NOT NULL,
  `baseline_value_json` mediumtext NOT NULL,
  `baseline_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reviewed_value_json` mediumtext,
  `decision` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `actor_id` int(10) unsigned NOT NULL DEFAULT '0',
  `actor_name` varchar(100) NOT NULL DEFAULT '',
  `created_at` int(10) unsigned NOT NULL,
  `updated_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`review_id`),
  UNIQUE KEY `uk_run_field` (`ai_run_id`,`field_name`),
  KEY `idx_vod_decision` (`vod_id`,`decision`),
  KEY `idx_decision_updated` (`decision`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
