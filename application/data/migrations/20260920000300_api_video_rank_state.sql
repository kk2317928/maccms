CREATE TABLE IF NOT EXISTS `__PREFIX__api_video_rank_state` (
  `state_id` tinyint unsigned NOT NULL,
  `last_event_id` bigint unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`state_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
