CREATE TABLE IF NOT EXISTS `__PREFIX__content_duplicate_candidate` (
  `duplicate_candidate_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `vod_id_low` int(10) unsigned NOT NULL,
  `vod_id_high` int(10) unsigned NOT NULL,
  `evidence_json` text NOT NULL,
  `score` smallint(5) unsigned NOT NULL,
  `decision` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `reviewed_by` int(10) unsigned NOT NULL DEFAULT '0',
  `reviewed_at` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` int(10) unsigned NOT NULL,
  `updated_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`duplicate_candidate_id`),
  UNIQUE KEY `uk_candidate_pair` (`vod_id_low`,`vod_id_high`),
  KEY `idx_decision_score` (`decision`,`score`),
  KEY `idx_vod_high` (`vod_id_high`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
