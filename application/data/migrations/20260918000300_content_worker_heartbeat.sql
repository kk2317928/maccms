CREATE TABLE IF NOT EXISTS `__PREFIX__content_worker_heartbeat` (
  `worker_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'idle',
  `processed` int(10) unsigned NOT NULL DEFAULT '0',
  `last_seen_at` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`worker_id`),
  KEY `idx_seen` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
