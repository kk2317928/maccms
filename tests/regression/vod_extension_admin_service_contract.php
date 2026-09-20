<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/application/common/util/VodExtensionAdminService.php';

use app\common\util\VodExtensionAdminService;

$normalized = VodExtensionAdminService::normalize([
    'title_tw' => '繁體片名',
    'title_cn' => '简体片名',
    'title_en' => 'English Title',
    'original_title' => '원제',
    'old_titles' => "舊名一\r\n舊名二\n舊名一",
    'type2' => 'kr-drama',
    'tmdb_id' => '123',
    'tmdb_type' => 'tv',
]);
if ($normalized['title_tw'] !== '繁體片名' || $normalized['tmdb_id'] !== 123) {
    fwrite(STDERR, "FAIL: multilingual or TMDB values were not normalized.\n");
    exit(1);
}
if ($normalized['old_titles_json'] !== '["舊名一","舊名二"]') {
    fwrite(STDERR, "FAIL: old titles were not normalized deterministically.\n");
    exit(1);
}

$failed = false;
try {
    VodExtensionAdminService::normalize(['type2' => '../invalid']);
} catch (InvalidArgumentException $e) {
    $failed = true;
}
if (!$failed) {
    fwrite(STDERR, "FAIL: invalid type2 was accepted.\n");
    exit(1);
}

fwrite(STDOUT, "OK: native video extension input is normalized safely.\n");
