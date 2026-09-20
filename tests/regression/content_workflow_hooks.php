<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/application/common/util/VodExtensionService.php';

use app\common\util\VodExtensionService;

$calls = [];
$factory = static function () use (&$calls) {
    return new class($calls) {
        private $calls;
        public function __construct(array &$calls) { $this->calls =& $calls; }
        public function beginAi(int $vodId, string $fingerprint): array
        {
            $this->calls[] = [$vodId, $fingerprint];
            return ['job_id' => 1, 'idempotency_key' => 'video:' . $vodId . ':ai:' . $fingerprint];
        }
    };
};

$created = VodExtensionService::enqueueAiAfterWrite(31, [], [
    'vod_name' => '  Example Movie ', 'vod_year' => '2024', 'type_id' => 2,
], $factory);
assertHook($created !== null && count($calls) === 1, 'new video enqueues AI once');

$firstFingerprint = $calls[0][1];
$same = VodExtensionService::enqueueAiAfterWrite(31, [
    'vod_name' => 'Example Movie', 'vod_year' => '2024', 'type_id' => 2,
], [
    'vod_name' => '  Example Movie ', 'vod_year' => 2024, 'type_id' => '2',
], $factory);
assertHook($same === null && count($calls) === 1, 'equivalent identity data does not enqueue');

$playback = VodExtensionService::enqueueAiAfterWrite(31, [
    'vod_name' => 'Example Movie', 'vod_year' => '2024',
], [
    'vod_play_from' => 'line', 'vod_play_url' => 'Episode$https://media.example/video.m3u8',
], $factory);
assertHook($playback === null && count($calls) === 1, 'playback-only update does not enqueue');

$changed = VodExtensionService::enqueueAiAfterWrite(31, [
    'vod_name' => 'Example Movie', 'vod_year' => '2024',
], [
    'vod_name' => 'Example Movie Extended',
], $factory);
assertHook($changed !== null && count($calls) === 2, 'identity change enqueues AI');
assertHook($calls[1][1] !== $firstFingerprint, 'identity change produces a new fingerprint');

$again = VodExtensionService::contentFingerprint([
    'type_id' => 2, 'vod_year' => 2024, 'vod_name' => 'Example Movie',
]);
$reordered = VodExtensionService::contentFingerprint([
    'vod_name' => ' Example Movie ', 'vod_year' => '2024', 'type_id' => '2',
]);
assertHook($again === $reordered, 'fingerprint is deterministic and order independent');

$root = dirname(__DIR__, 2);
$vodSource = (string) file_get_contents($root . '/application/common/model/Vod.php');
$collectSource = (string) file_get_contents($root . '/application/common/model/Collect.php');
assertHook(substr_count($vodSource, 'enqueueAiAfterWrite(') >= 1, 'native admin save calls shared enqueue boundary');
assertHook(substr_count($collectSource, 'enqueueAiAfterWrite(') >= 2, 'collection insert and update call shared enqueue boundary');

fwrite(STDOUT, "PASS: native content workflow hooks\n");

function assertHook(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
