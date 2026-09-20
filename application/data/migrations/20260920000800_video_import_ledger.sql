CREATE TABLE IF NOT EXISTS `__PREFIX__video_import_nonce` (
  `nonce` varchar(128) NOT NULL,
  `request_timestamp` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`nonce`), KEY `idx_import_nonce_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `__PREFIX__video_import_request` (
  `import_request_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `idempotency_key` varchar(191) NOT NULL,
  `request_fingerprint` char(64) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'processing',
  `response_json` mediumtext NULL,
  `created_at` int unsigned NOT NULL,
  `completed_at` int unsigned NULL,
  PRIMARY KEY (`import_request_id`), UNIQUE KEY `uniq_import_idempotency` (`idempotency_key`), KEY `idx_import_status_created` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
