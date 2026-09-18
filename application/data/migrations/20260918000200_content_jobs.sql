CREATE TABLE IF NOT EXISTS `__PREFIX__content_job` (
  `job_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_json` mediumtext NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'queued',
  `priority` int(11) NOT NULL DEFAULT '0',
  `attempt` int(10) unsigned NOT NULL DEFAULT '0',
  `max_attempts` int(10) unsigned NOT NULL DEFAULT '3',
  `next_run_at` int(10) unsigned NOT NULL DEFAULT '0',
  `lock_owner` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `lock_expires_at` int(10) unsigned NOT NULL DEFAULT '0',
  `idempotency_key` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `error_class` varchar(128) NOT NULL DEFAULT '',
  `error_summary` varchar(1000) NOT NULL DEFAULT '',
  `completed_at` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` int(10) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`job_id`),
  UNIQUE KEY `uk_type_idempotency` (`job_type`,`idempotency_key`),
  KEY `idx_claim` (`status`,`next_run_at`,`priority`,`job_id`),
  KEY `idx_lock` (`status`,`lock_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `__PREFIX__content_job_run` (
  `run_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint(20) unsigned NOT NULL,
  `attempt` int(10) unsigned NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `worker_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `started_at` int(10) unsigned NOT NULL DEFAULT '0',
  `finished_at` int(10) unsigned NOT NULL DEFAULT '0',
  `error_class` varchar(128) NOT NULL DEFAULT '',
  `error_summary` varchar(1000) NOT NULL DEFAULT '',
  `metrics_json` text,
  PRIMARY KEY (`run_id`),
  UNIQUE KEY `uk_job_attempt` (`job_id`,`attempt`),
  KEY `idx_job_status` (`job_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
