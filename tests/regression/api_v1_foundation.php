<?php
declare(strict_types=1);

function failTest($message)
{
    fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
    exit(1);
}

function assertTrueValue($condition, $message)
{
    if (!$condition) {
        failTest($message);
    }
}

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        failTest($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assertContainsValue($needle, $haystack, $message)
{
    assertTrueValue(strpos($haystack, $needle) !== false, $message);
}

function assertInvalidPagination(array $query, $field)
{
    try {
        \app\common\util\ApiV1Pagination::fromQuery($query);
    } catch (InvalidArgumentException $exception) {
        assertSameValue($field, $exception->getMessage(), 'Pagination error must expose only the safe field name.');
        return;
    }
    failTest('Expected invalid pagination for ' . $field . ': ' . var_export($query, true));
}

$root = dirname(__DIR__, 2);
$required = array(
    'application/common/util/ApiV1Dto.php',
    'application/common/util/ApiV1AllowlistDto.php',
    'application/common/util/ApiV1RequestId.php',
    'application/common/util/ApiV1Pagination.php',
    'application/common/util/ApiV1Response.php',
    'application/api/controller/v1/Base.php',
    'application/api/controller/v1/Index.php',
);
foreach ($required as $relative) {
    assertTrueValue(is_file($root . '/' . $relative), 'Missing API v1 foundation file: ' . $relative);
}

require_once $root . '/application/common/util/ApiV1Dto.php';
require_once $root . '/application/common/util/ApiV1AllowlistDto.php';
require_once $root . '/application/common/util/ApiV1RequestId.php';
require_once $root . '/application/common/util/ApiV1Pagination.php';
require_once $root . '/application/common/util/ApiV1Response.php';

use app\common\util\ApiV1AllowlistDto;
use app\common\util\ApiV1Pagination;
use app\common\util\ApiV1RequestId;
use app\common\util\ApiV1Response;

$dto = new ApiV1AllowlistDto(
    array('public_id' => 'ABC234', 'title' => 'Example', 'vod_id' => 99),
    array('public_id', 'title')
);
assertSameValue(array('public_id' => 'ABC234', 'title' => 'Example'), $dto->toArray(), 'DTO must expose allowlisted fields only.');

$default = ApiV1Pagination::fromQuery(array());
assertSameValue(0, $default->offset(), 'Default offset must be zero.');
assertSameValue(array(
    'current_page' => 1,
    'per_page' => 20,
    'total_items' => 0,
    'total_pages' => 0,
), $default->meta(0), 'Empty pagination metadata mismatch.');
assertSameValue(5, $default->meta(100)['total_pages'], 'Exact page total mismatch.');
assertSameValue(6, $default->meta(101)['total_pages'], 'Remainder page total mismatch.');
assertSameValue(99, ApiV1Pagination::fromQuery(array('page' => '2', 'per_page' => '99'))->offset(), 'Explicit pagination offset mismatch.');

foreach (array(
    array(array('page' => '0'), 'page'),
    array(array('page' => ' 1'), 'page'),
    array(array('page' => '+1'), 'page'),
    array(array('page' => '1.0'), 'page'),
    array(array('page' => '1e2'), 'page'),
    array(array('page' => array('1')), 'page'),
    array(array('per_page' => '101'), 'per_page'),
    array(array('per_page' => true), 'per_page'),
) as $invalid) {
    assertInvalidPagination($invalid[0], $invalid[1]);
}
assertInvalidPagination(array('page' => (string) PHP_INT_MAX, 'per_page' => '100'), 'page');

assertSameValue('client-123', ApiV1RequestId::resolve('client-123'), 'Valid request ID must be preserved.');
$generated = ApiV1RequestId::resolve("bad\nvalue");
assertTrueValue((bool) preg_match('/\A[A-Za-z0-9._:-]{16,64}\z/D', $generated), 'Generated request ID must be safe and bounded.');
assertTrueValue($generated !== "bad\nvalue", 'Unsafe inbound request ID must not be reflected.');

$success = ApiV1Response::success(array('version' => 'v1'), 'req-1');
assertSameValue(array('data' => array('version' => 'v1'), 'meta' => array('request_id' => 'req-1')), $success, 'Success envelope mismatch.');
assertTrueValue(!array_key_exists('error', $success), 'Success envelope cannot contain error.');

$collection = ApiV1Response::collection(array($dto), $default, 1, 'req-2');
assertSameValue(array('public_id' => 'ABC234', 'title' => 'Example'), $collection['data'][0], 'Collection must normalize DTOs.');
assertSameValue(1, $collection['meta']['pagination']['total_items'], 'Collection pagination metadata mismatch.');

$error = ApiV1Response::error('VALIDATION_ERROR', 'Invalid request.', 'req-3', array('page' => 'invalid'));
assertTrueValue(!array_key_exists('data', $error), 'Error envelope cannot contain data.');
assertSameValue('VALIDATION_ERROR', $error['error']['code'], 'Stable error code mismatch.');

$route = file_get_contents($root . '/application/route.php');
assertContainsValue("'api/v1$'", $route, 'Exact API v1 route is missing.');
assertContainsValue("'api/v1.index/index'", $route, 'API v1 probe target is missing.');
assertContainsValue("'api/v1/<path>'", $route, 'API v1 fallback route is missing.');

$base = file_get_contents($root . '/application/api/controller/v1/Base.php');
assertContainsValue('class Base extends \\app\\api\\controller\\Base', $base, 'v1 base must preserve API initialization.');
assertContainsValue('ApiV1Response::error', $base, 'v1 base must use the stable error factory.');

$index = file_get_contents($root . '/application/api/controller/v1/Index.php');
assertContainsValue("'version' => 'v1'", $index, 'v1 probe version payload is missing.');
assertContainsValue('NOT_FOUND', $index, 'v1 fallback must use the stable not-found code.');

$workflow = file_get_contents($root . '/.github/workflows/php-regression.yml');
foreach (array(
    'php -l tests/regression/api_v1_foundation.php',
    'php -l tests/regression/run_api_v1.php',
    'php tests/regression/run_api_v1.php',
) as $command) {
    assertContainsValue($command, $workflow, 'PHP workflow is missing: ' . $command);
}

fwrite(STDOUT, "API v1 foundation contract passed." . PHP_EOL);
