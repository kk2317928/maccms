CREATE TABLE IF NOT EXISTS `__PREFIX__content_tmdb_review` (
  `tmdb_review_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `vod_id` int(10) unsigned NOT NULL,
  `revision` int(10) unsigned NOT NULL DEFAULT '1',
  `status` varchar(24) NOT NULL DEFAULT 'candidate_review',
  `candidates_json` mediumtext NOT NULL,
  `candidates_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `preselected_tmdb_id` int(10) unsigned NOT NULL DEFAULT '0',
  `selected_tmdb_id` int(10) unsigned NOT NULL DEFAULT '0',
  `selected_type` varchar(8) NOT NULL DEFAULT '',
  `reviewed_by` int(10) unsigned NOT NULL DEFAULT '0',
  `reviewed_at` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` int(10) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`tmdb_review_id`),
  UNIQUE KEY `uk_vod_revision` (`vod_id`,`revision`),
  KEY `idx_status_updated` (`status`,`updated_at`),
  KEY `idx_selected` (`selected_type`,`selected_tmdb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
