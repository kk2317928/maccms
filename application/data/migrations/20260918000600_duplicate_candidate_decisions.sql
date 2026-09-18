ALTER TABLE `__PREFIX__content_duplicate_candidate`
  ADD COLUMN `fingerprint_low` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0000000000000000000000000000000000000000000000000000000000000000' AFTER `vod_id_high`,
  ADD COLUMN `fingerprint_high` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0000000000000000000000000000000000000000000000000000000000000000' AFTER `fingerprint_low`,
  ADD COLUMN `invalidated_at` int(10) unsigned NOT NULL DEFAULT '0' AFTER `reviewed_at`,
  ADD KEY `idx_invalidation` (`decision`,`invalidated_at`);
