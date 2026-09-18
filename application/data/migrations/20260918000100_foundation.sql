CREATE TABLE IF NOT EXISTS `__PREFIX__vod_ext` (
  `vod_id` int(10) unsigned NOT NULL,
  `public_id` char(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `title_tw` varchar(255) NOT NULL DEFAULT '',
  `title_cn` varchar(255) NOT NULL DEFAULT '',
  `title_en` varchar(255) NOT NULL DEFAULT '',
  `original_title` varchar(255) NOT NULL DEFAULT '',
  `old_titles_json` text,
  `type2` varchar(32) NOT NULL DEFAULT '',
  `tmdb_id` int(10) unsigned NOT NULL DEFAULT '0',
  `tmdb_type` varchar(8) NOT NULL DEFAULT '',
  `trailer_url` varchar(1024) NOT NULL DEFAULT '',
  `preview_url` varchar(1024) NOT NULL DEFAULT '',
  `old_poster` varchar(1024) NOT NULL DEFAULT '',
  `old_poster_s3` varchar(1024) NOT NULL DEFAULT '',
  `poster_s3` varchar(1024) NOT NULL DEFAULT '',
  `workflow_status` varchar(32) NOT NULL DEFAULT 'imported',
  `ai_completed_at` int(10) unsigned NOT NULL DEFAULT '0',
  `tmdb_completed_at` int(10) unsigned NOT NULL DEFAULT '0',
  `duplicate_checked_at` int(10) unsigned NOT NULL DEFAULT '0',
  `merged_into_vod_id` int(10) unsigned NOT NULL DEFAULT '0',
  `published_at` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` int(10) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`vod_id`),
  UNIQUE KEY `uk_public_id` (`public_id`),
  KEY `idx_workflow` (`workflow_status`,`updated_at`),
  KEY `idx_tmdb` (`tmdb_type`,`tmdb_id`),
  KEY `idx_merged_into` (`merged_into_vod_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `__PREFIX__meta_term` (
  `term_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(16) NOT NULL,
  `slug` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name_tw` varchar(128) NOT NULL DEFAULT '',
  `name_cn` varchar(128) NOT NULL DEFAULT '',
  `name_en` varchar(128) NOT NULL DEFAULT '',
  `synonyms_json` text,
  `status` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `sort` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` int(10) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`term_id`),
  UNIQUE KEY `uk_kind_slug` (`kind`,`slug`),
  KEY `idx_kind_status_sort` (`kind`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `__PREFIX__vod_meta_term` (
  `vod_id` int(10) unsigned NOT NULL,
  `term_id` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL DEFAULT '0',
  UNIQUE KEY `uk_vod_term` (`vod_id`,`term_id`),
  KEY `idx_term_id` (`term_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `__PREFIX__vod_field_state` (
  `vod_id` int(10) unsigned NOT NULL,
  `field_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source` varchar(16) NOT NULL DEFAULT 'import',
  `is_locked` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `source_ref` varchar(191) NOT NULL DEFAULT '',
  `updated_at` int(10) unsigned NOT NULL DEFAULT '0',
  UNIQUE KEY `uk_vod_field` (`vod_id`,`field_name`),
  KEY `idx_source` (`source`),
  KEY `idx_locked` (`is_locked`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
