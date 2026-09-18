CREATE TABLE IF NOT EXISTS `__PREFIX__content_merge_snapshot` (
  `merge_snapshot_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `duplicate_candidate_id` bigint(20) unsigned NOT NULL,
  `primary_vod_id` int(10) unsigned NOT NULL,
  `secondary_vod_id` int(10) unsigned NOT NULL,
  `snapshot_json` mediumtext NOT NULL,
  `snapshot_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `merged_by` int(10) unsigned NOT NULL,
  `merged_at` int(10) unsigned NOT NULL,
  `restored_by` int(10) unsigned NOT NULL DEFAULT '0',
  `restored_at` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`merge_snapshot_id`),
  UNIQUE KEY `uk_duplicate_candidate` (`duplicate_candidate_id`),
  KEY `idx_primary_status` (`primary_vod_id`,`status`),
  KEY `idx_secondary_status` (`secondary_vod_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
