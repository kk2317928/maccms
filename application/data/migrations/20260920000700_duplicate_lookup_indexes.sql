ALTER TABLE `__PREFIX__vod`
  ADD KEY `idx_dup_name_year` (`vod_name`(100),`vod_year`),
  ADD KEY `idx_dup_en_year` (`vod_en`(100),`vod_year`);

ALTER TABLE `__PREFIX__vod_ext`
  ADD KEY `idx_dup_tmdb_id` (`tmdb_id`),
  ADD KEY `idx_dup_title_tw` (`title_tw`(100)),
  ADD KEY `idx_dup_original_title` (`original_title`(100));
