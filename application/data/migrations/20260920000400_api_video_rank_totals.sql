CREATE TABLE IF NOT EXISTS `__PREFIX__api_video_rank_total` (
  `vod_id` int unsigned NOT NULL,
  `all_time_score` bigint unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`vod_id`),
  KEY `idx_api_video_rank_total_score` (`all_time_score`,`vod_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
