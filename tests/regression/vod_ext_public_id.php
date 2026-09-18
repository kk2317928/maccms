<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$modelPath = $root . '/application/common/model/VodExt.php';
if (!is_file($modelPath)) {
    fwrite(STDERR, "FAIL: VodExt model is missing.\n");
    exit(1);
}

defined('ROOT_PATH') or define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);
defined('APP_PATH') or define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp/base.php';
require ROOT_PATH . 'thinkphp/helper.php';

function vod_ext_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$chain = [10 => 20, 20 => 30, 30 => 0];
$resolved = \app\common\model\VodExt::resolveCanonical(10, function ($vodId) use ($chain) {
    return array_key_exists($vodId, $chain) ? $chain[$vodId] : null;
});
vod_ext_assert($resolved === 30, 'canonical chain must resolve to its terminal video.');

$missing = \app\common\model\VodExt::resolveCanonical(99, function () {
    return null;
});
vod_ext_assert($missing === 99, 'a video without an extension row must resolve to itself.');

$cycleRejected = false;
try {
    \app\common\model\VodExt::resolveCanonical(1, function ($vodId) {
        return $vodId === 1 ? 2 : 1;
    });
} catch (RuntimeException $exception) {
    $cycleRejected = strpos($exception->getMessage(), 'cycle') !== false;
}
vod_ext_assert($cycleRejected, 'canonical cycles must be rejected.');

$depthRejected = false;
try {
    \app\common\model\VodExt::resolveCanonical(1, function ($vodId) {
        return $vodId + 1;
    });
} catch (RuntimeException $exception) {
    $depthRejected = strpos($exception->getMessage(), 'depth') !== false;
}
vod_ext_assert($depthRejected, 'canonical chains beyond ten hops must be rejected.');

$source = file_get_contents($modelPath);
foreach (['ensureForVod', 'findByPublicId', 'canonicalVodId'] as $needle) {
    vod_ext_assert(strpos($source, $needle) !== false, "VodExt source is missing {$needle}.");
}
vod_ext_assert(
    strpos($source, "protected \$name = 'vod_ext'") !== false,
    'VodExt must bind to vod_ext.'
);

fwrite(STDOUT, "OK: VodExt public ID and canonical traversal contract passed.\n");
