<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/application/data/migrations/20260918000100_foundation.sql';
$sql = is_file($path) ? file_get_contents($path) : '';

$required = [
    'CREATE TABLE IF NOT EXISTS `__PREFIX__vod_ext`',
    'CREATE TABLE IF NOT EXISTS `__PREFIX__meta_term`',
    'CREATE TABLE IF NOT EXISTS `__PREFIX__vod_meta_term`',
    'CREATE TABLE IF NOT EXISTS `__PREFIX__vod_field_state`',
    '`public_id` char(6)',
    '`title_tw` varchar(255)',
    '`title_cn` varchar(255)',
    '`title_en` varchar(255)',
    '`original_title` varchar(255)',
    '`old_titles_json` text',
    '`type2` varchar(32)',
    '`tmdb_id` int(10) unsigned',
    '`poster_s3` varchar(1024)',
    '`old_poster_s3` varchar(1024)',
    '`workflow_status` varchar(32)',
    '`merged_into_vod_id` int(10) unsigned',
    'UNIQUE KEY `uk_public_id` (`public_id`)',
    'UNIQUE KEY `uk_kind_slug` (`kind`,`slug`)',
    'UNIQUE KEY `uk_vod_term` (`vod_id`,`term_id`)',
    'UNIQUE KEY `uk_vod_field` (`vod_id`,`field_name`)',
    'KEY `idx_workflow` (`workflow_status`,`updated_at`)',
    'KEY `idx_tmdb` (`tmdb_type`,`tmdb_id`)',
    'KEY `idx_kind_status_sort` (`kind`,`status`,`sort`)',
    'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
];

foreach ($required as $needle) {
    if (strpos($sql, $needle) === false) {
        fwrite(STDERR, "FAIL: foundation migration missing {$needle}\n");
        exit(1);
    }
}

if (substr_count($sql, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4') !== 4) {
    fwrite(STDERR, "FAIL: every foundation extension table must declare InnoDB/utf8mb4.\n");
    exit(1);
}
if (stripos($sql, 'FOREIGN KEY') !== false) {
    fwrite(STDERR, "FAIL: foundation migration must not add foreign keys to the MyISAM compatibility core.\n");
    exit(1);
}
if (stripos($sql, 'content_lang') !== false) {
    fwrite(STDERR, "FAIL: foundation migration must reuse ContentLang rather than create a competing multilingual store.\n");
    exit(1);
}
if (preg_match('/\bmac_(?:vod_ext|meta_term|vod_meta_term|vod_field_state)\b/i', $sql)) {
    fwrite(STDERR, "FAIL: migration table names must use the literal __PREFIX__ token.\n");
    exit(1);
}

fwrite(STDOUT, "OK: foundation extension schema contract passed.\n");
