-- T-116: deduplicate cache then enforce one row per duplicate name.
DELETE r1 FROM __PREFIX__vod_repeat r1
JOIN __PREFIX__vod_repeat r2 ON r1.name1=r2.name1
AND (r1.id1 > r2.id1 OR (r1.id1 = r2.id1 AND r1.name1 = r2.name1));

ALTER TABLE __PREFIX__vod_repeat DROP INDEX name1;
ALTER TABLE __PREFIX__vod_repeat ADD UNIQUE KEY uk_vod_repeat_name1 (name1(100));
