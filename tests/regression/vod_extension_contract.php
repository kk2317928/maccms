<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'application/common/model/MetaTerm.php',
    'application/common/model/VodMetaTerm.php',
    'application/common/model/VodFieldState.php',
    'application/common/util/VodExtensionService.php',
];
foreach ($files as $file) {
    if (!is_file($root . '/' . $file)) {
        fwrite(STDERR, "FAIL: missing extension source {$file}.\n");
        exit(1);
    }
}

defined('ROOT_PATH') or define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);
defined('APP_PATH') or define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp/base.php';
require ROOT_PATH . 'thinkphp/helper.php';

function extension_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$bindings = [
    'app\\common\\model\\MetaTerm' => 'meta_term',
    'app\\common\\model\\VodMetaTerm' => 'vod_meta_term',
    'app\\common\\model\\VodFieldState' => 'vod_field_state',
];
foreach ($bindings as $class => $table) {
    $reflection = new ReflectionClass($class);
    $defaults = $reflection->getDefaultProperties();
    extension_assert(($defaults['name'] ?? null) === $table, "{$class} must bind to {$table}.");
}

extension_assert(
    \app\common\model\MetaTerm::KINDS === ['region', 'genre', 'tag'],
    'taxonomy kinds must be exactly region, genre, and tag.'
);
extension_assert(
    \app\common\model\VodFieldState::SOURCES === ['import', 'ai', 'tmdb', 'manual'],
    'field sources must be exactly import, ai, tmdb, and manual.'
);

foreach (['region', 'genre', 'tag'] as $kind) {
    \app\common\model\MetaTerm::assertKind($kind);
}
foreach (['import', 'ai', 'tmdb', 'manual'] as $source) {
    \app\common\model\VodFieldState::assertSource($source);
}
foreach ([
    [\app\common\model\MetaTerm::class, 'assertKind', 'unknown'],
    [\app\common\model\VodFieldState::class, 'assertSource', 'unknown'],
] as $case) {
    $rejected = false;
    try {
        call_user_func([$case[0], $case[1]], $case[2]);
    } catch (InvalidArgumentException $exception) {
        $rejected = true;
    }
    extension_assert($rejected, "{$case[1]} must reject unsupported values.");
}

$serviceClass = new ReflectionClass('app\\common\\util\\VodExtensionService');
foreach (['ensure', 'replaceTerms', 'lockField', 'syncNativeTaxonomy'] as $methodName) {
    extension_assert($serviceClass->hasMethod($methodName), "VodExtensionService is missing {$methodName}.");
    $method = $serviceClass->getMethod($methodName);
    extension_assert($method->isPublic() && $method->isStatic(), "{$methodName} must be public and static.");
}
extension_assert(
    \app\common\util\VodExtensionService::NATIVE_FIELDS === [
        'region' => 'vod_area',
        'genre' => 'vod_class',
        'tag' => 'vod_tag',
    ],
    'native taxonomy mappings must be exact.'
);

$serviceSource = file_get_contents($root . '/application/common/util/VodExtensionService.php');
extension_assert(
    (bool) preg_match("/order\\(['\"]term_sort asc,term_id asc['\"]\\)/i", $serviceSource),
    'native taxonomy terms must sort by term_sort then term_id ascending.'
);
extension_assert(
    (bool) preg_match("/implode\\(\\s*['\"],['\"]\\s*,/", $serviceSource),
    'native compatibility names must be comma joined.'
);

fwrite(STDOUT, "OK: video extension service source contract passed.\n");
