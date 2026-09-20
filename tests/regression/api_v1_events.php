<?php
declare(strict_types=1);

function ok($condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$root = dirname(__DIR__, 2);
require_once $root . '/application/common/util/ApiV1EventContract.php';
require_once $root . '/application/common/util/ApiV1EventDeduplicator.php';

use app\common\util\ApiV1EventContract;
use app\common\util\ApiV1EventDeduplicator;

$types = ['play_start','valid_watch','progress','completion','favorite'];
ok(ApiV1EventContract::types() === $types, 'event type allowlist must be exact');
ok(ApiV1EventContract::dedupeWindow('progress') === 30, 'progress window');
ok(ApiV1EventContract::dedupeWindow('play_start') === 300, 'play-start window');
ok(ApiV1EventContract::dedupeWindow('valid_watch') === 1800, 'valid-watch window');
ok(ApiV1EventContract::dedupeWindow('completion') === 86400, 'completion window');
ok(ApiV1EventContract::dedupeWindow('favorite') === 86400, 'favorite window');

$event = ApiV1EventContract::normalize([
    'event_type'=>'progress', 'public_id'=>'ABC234', 'episode'=>'  ep-2 ',
    'position_seconds'=>'91', 'duration_seconds'=>1200, 'occurred_at'=>1700000007,
    'event_id'=>'01890f3e-7b6d-7cc2-a31d-aabbccddeeff'
]);
ok($event['episode'] === 'ep-2' && $event['position_seconds'] === 91, 'normalizes event');
ok($event['occurred_at'] === 1700000007, 'preserves valid client occurrence time');
foreach ([
    ['event_type'=>'bogus','public_id'=>'ABC234','occurred_at'=>1700000000],
    ['event_type'=>'progress','public_id'=>'bad!','occurred_at'=>1700000000],
    ['event_type'=>'progress','public_id'=>'ABC234','position_seconds'=>5,'duration_seconds'=>4,'occurred_at'=>1700000000],
] as $bad) {
    try { ApiV1EventContract::normalize($bad); ok(false, 'invalid event must fail'); }
    catch (InvalidArgumentException $e) {}
}

$member = ApiV1EventContract::actorKey(42, 'session-1', 'device-token');
$session = ApiV1EventContract::actorKey(0, 'session-1', 'device-token');
$device = ApiV1EventContract::actorKey(0, '', 'device-token');
ok($member === 'member:42', 'member identity wins');
ok($session === 'session:' . hash('sha256','session-1'), 'session identity wins');
ok($device === 'device:' . hash('sha256','device-token'), 'device identity is hashed');
try { ApiV1EventContract::actorKey(0, '', ''); ok(false, 'actor required'); }
catch (InvalidArgumentException $e) {}

$d = new ApiV1EventDeduplicator();
$bucketStart = intdiv($event['occurred_at'], 30) * 30;
$a = $d->fingerprint(array_merge($event, ['occurred_at'=>$bucketStart + 1]), $member);
$b = $d->fingerprint(array_merge($event, ['occurred_at'=>$bucketStart + 29]), $member);
$c = $d->fingerprint(array_merge($event, ['occurred_at'=>$bucketStart + 30]), $member);
ok(hash_equals($a, $b), 'same actor/content/episode/type/window deduplicates replay');
ok(!hash_equals($a, $c), 'next window is independent');
ok(!hash_equals($a, $d->fingerprint($event, $device)), 'actor boundary is isolated');
ok($d->fingerprint(array_merge($event,['occurred_at'=>$bucketStart + 2]),$member) ===
   $d->fingerprint(array_merge($event,['occurred_at'=>$bucketStart + 28]),$member),
   'reordered events in one window remain idempotent');

$migration = file_get_contents($root . '/application/data/migrations/20260920000100_api_video_events.sql');
ok($migration !== false, 'event migration exists');
foreach (['api_video_event','dedupe_hash','event_type','actor_key','occurred_at','received_at','UNIQUE KEY','idx_api_video_event_vod_time'] as $needle) {
    ok(stripos($migration, $needle) !== false, "migration contains {$needle}");
}
ok(stripos($migration, 'FOREIGN KEY') === false, 'migration remains prefix/install compatible');

fwrite(STDOUT, "API v1 event contract passed.\n");
