CREATE TABLE IF NOT EXISTS `__PREFIX__api_video_rank` (
  `rank_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `stat_date` date NOT NULL,
  `vod_id` int unsigned NOT NULL,
  `today_score` int unsigned NOT NULL DEFAULT 0,
  `days_7_score` int unsigned NOT NULL DEFAULT 0,
  `days_30_score` int unsigned NOT NULL DEFAULT 0,
  `all_time_score` bigint unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`rank_id`),
  UNIQUE KEY `uk_api_video_rank_date_vod` (`stat_date`,`vod_id`),
  KEY `idx_api_video_rank_today` (`stat_date`,`today_score`,`vod_id`),
  KEY `idx_api_video_rank_7` (`stat_date`,`days_7_score`,`vod_id`),
  KEY `idx_api_video_rank_30` (`stat_date`,`days_30_score`,`vod_id`),
  KEY `idx_api_video_rank_all` (`stat_date`,`all_time_score`,`vod_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
