CREATE TABLE IF NOT EXISTS `__PREFIX__content_admin_audit_event` (
  `event_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` int(10) unsigned NOT NULL,
  `actor_name` varchar(60) NOT NULL DEFAULT '',
  `event_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_public_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `before_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `after_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `context_json` text NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`event_id`),
  KEY `idx_actor_created` (`actor_id`,`created_at`),
  KEY `idx_event_created` (`event_code`,`created_at`),
  KEY `idx_subject` (`subject_type`,`subject_public_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
