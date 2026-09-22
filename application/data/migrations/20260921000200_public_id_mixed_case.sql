-- T-117: public video IDs are exact-case six-character ASCII identities.
ALTER TABLE __PREFIX__vod_ext
  MODIFY public_id CHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
