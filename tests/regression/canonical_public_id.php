<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
defined('ROOT_PATH') or define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);
defined('APP_PATH') or define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp/base.php';
require ROOT_PATH . 'thinkphp/helper.php';

function canonical_public_id_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$rowsByPublicId = [
    'ALIAS2' => ['vod_id' => 10, 'public_id' => 'ALIAS2', 'merged_into_vod_id' => 20],
];
$rowsByVodId = [
    10 => $rowsByPublicId['ALIAS2'],
    20 => ['vod_id' => 20, 'public_id' => 'MIDDLE', 'merged_into_vod_id' => 30],
    30 => ['vod_id' => 30, 'public_id' => 'MASTER', 'merged_into_vod_id' => 0],
];

$resolved = \app\common\model\VodExt::resolvePublicIdWithLookups(
    ' alias2 ',
    function ($publicId) use ($rowsByPublicId) {
        return $rowsByPublicId[$publicId] ?? null;
    },
    function ($vodId) use ($rowsByVodId) {
        return $rowsByVodId[$vodId] ?? null;
    }
);

canonical_public_id_assert($resolved === [
    'requested_public_id' => 'ALIAS2',
    'canonical_public_id' => 'MASTER',
    'is_alias' => true,
], 'a secondary public ID must resolve to the terminal canonical public ID.');
canonical_public_id_assert(!array_key_exists('vod_id', $resolved), 'public resolution must not expose numeric video IDs.');

$canonical = \app\common\model\VodExt::resolvePublicIdWithLookups(
    'MASTER',
    function ($publicId) use ($rowsByVodId) {
        return $publicId === 'MASTER' ? $rowsByVodId[30] : null;
    },
    function ($vodId) use ($rowsByVodId) {
        return $rowsByVodId[$vodId] ?? null;
    }
);
canonical_public_id_assert($canonical['is_alias'] === false, 'a canonical public ID must not be reported as an alias.');
canonical_public_id_assert($canonical['canonical_public_id'] === 'MASTER', 'canonical lookup must preserve its public ID.');

$missing = \app\common\model\VodExt::resolvePublicIdWithLookups(
    'ZZZZZZ',
    function () { return null; },
    function () { return null; }
);
canonical_public_id_assert($missing === null, 'an unknown public ID must return null.');

$invalid = \app\common\model\VodExt::resolvePublicIdWithLookups(
    'bad-id',
    function () { throw new RuntimeException('invalid IDs must not reach storage'); },
    function () { throw new RuntimeException('invalid IDs must not reach storage'); }
);
canonical_public_id_assert($invalid === null, 'an invalid public ID must return null without querying storage.');

fwrite(STDOUT, "OK: canonical public-ID resolution contract passed.\n");
