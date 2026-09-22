-- T-116: ensure the native repeat cache exists, deduplicate it, then enforce one row per name.
CREATE TABLE IF NOT EXISTS __PREFIX__vod_repeat (
  id1 int unsigned DEFAULT NULL,
  name1 varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  KEY name1 (name1(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DELETE r1 FROM __PREFIX__vod_repeat r1
JOIN __PREFIX__vod_repeat r2 ON r1.name1=r2.name1 AND r1.id1>r2.id1;

ALTER TABLE __PREFIX__vod_repeat DROP INDEX name1;
ALTER TABLE __PREFIX__vod_repeat ADD UNIQUE KEY uk_vod_repeat_name1 (name1(100));
