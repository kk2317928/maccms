<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function requireContains(string $path, array $needles, string $label): void
{
    $content = (string) file_get_contents($path);
    foreach ($needles as $needle) {
        if (strpos($content, $needle) === false) {
            fwrite(STDERR, "FAIL: {$label} is missing required contract: {$needle}\n");
            exit(1);
        }
    }
}

requireContains($root . '/application/install/controller/Index.php', [
    'SchemaMigrationService',
    "APP_PATH . 'data/migrations'",
    '->migrate()',
], 'web installer migration integration');

requireContains($root . '/application/admin/common/auth.php', [
    "'controller' => 'ContentWorkspace'",
    "'action' => 'view'",
    '智能內容工作區',
], 'admin navigation');

requireContains($root . '/application/admin/controller/Vod.php', [
    'array_key_exists(\'vod_ext\', $param)',
    'unset($param[\'vod_ext\'])',
    'if ($vodId > 0 && $hasVodExt)',
    'VodExtensionAdminService::normalize($vodExt)',
    'VodExtensionAdminService::save($vodId, $vodExt)',
], 'native video extension persistence');

requireContains($root . '/application/admin/view_new/vod/info.html', [
    'name="vod_ext[title_tw]"',
    'name="vod_ext[title_cn]"',
    'name="vod_ext[title_en]"',
    'name="vod_ext[original_title]"',
    'name="vod_ext[type2]"',
    'name="vod_ext[tmdb_id]"',
    'name="vod_ext[tmdb_type]"',
    'name="vod_ext[trailer_url]"',
    'name="vod_ext[preview_url]"',
    'name="vod_ext[poster_s3]"',
], 'native video extension form');

fwrite(STDOUT, "OK: install and native admin expose secondary-development features.\n");
