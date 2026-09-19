<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$required = ['FinalPublicationWorkspace.php', 'FinalPublicationService.php'];

$storageMigration = $root . '/application/data/migrations/20260919000400_vod_transactional_publication.sql';
publicationAssert(is_file($storageMigration), 'publication must provide a versioned native vod InnoDB conversion migration.');
if (is_file($storageMigration)) {
    $storageSql = (string) file_get_contents($storageMigration);
    publicationAssert(strpos($storageSql, 'ALTER TABLE `__PREFIX__vod` ENGINE=InnoDB') !== false, 'native vod storage migration must use the configurable prefix and InnoDB.');
}

foreach ($required as $file) {
    $path = $root . '/application/common/util/' . $file;
    if (!is_file($path)) { fwrite(STDERR, "FAIL: {$file} is missing.\n"); exit(1); }
    require_once $path;
}
require_once $root . '/application/common/util/VodPlaybackCodec.php';
require_once $root . '/application/common/util/VodWorkflow.php';
require_once $root . '/application/common/util/ContentAdminPolicy.php';
require_once $root . '/application/common/util/ContentAdminAudit.php';

use app\common\util\ContentAdminAudit;
use app\common\util\FinalPublicationService;
use app\common\util\FinalPublicationWorkspace;

function publicationAssert($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

function publicationFixture(array $changes = []): array
{
    return array_merge([
        'vod_id' => 42, 'vod_name' => '主要片名', 'vod_sub' => 'Original Title', 'vod_en' => 'Main Title',
        'vod_blurb' => '摘要', 'vod_content' => '完整簡介', 'vod_area' => '日本', 'vod_class' => '劇情',
        'vod_tag' => '成長', 'vod_pic' => 'https://img.test/poster.jpg', 'vod_pic_slide' => 'https://img.test/backdrop.jpg',
        'vod_play_from' => 'hls', 'vod_play_url' => '第1集$https://video.test/1.m3u8', 'vod_play_server' => '',
        'vod_play_note' => '', 'vod_status' => 0, 'vod_publish_time' => 123,
        'public_id' => 'ABC234', 'workflow_status' => 'manual_review', 'merged_into_vod_id' => 0,
        'trailer_url' => 'https://video.test/trailer.mp4', 'poster_s3' => 'https://cdn.test/poster.webp',
        'published_at' => 0,
    ], $changes);
}

$rows = [42 => publicationFixture()];
$locales = [42 => [
    'zh-TW' => ['vod_name' => '主要片名', 'vod_blurb' => '繁中摘要'],
    'zh-CN' => ['vod_name' => '主要片名（简）', 'vod_blurb' => '简中摘要'],
    'en' => ['vod_name' => 'Main Title', 'vod_blurb' => 'English summary'],
]];
$terms = [42 => [
    ['kind' => 'region', 'term_id' => 1, 'name_tw' => '', 'name_cn' => '日本', 'name_en' => 'Japan'],
    ['kind' => 'genre', 'term_id' => 2, 'name_tw' => '劇情', 'name_cn' => '剧情', 'name_en' => 'Drama'],
]];
$workspace = new FinalPublicationWorkspace(
    static fn (int $offset, int $limit): array => array_slice(array_values($rows), $offset, $limit),
    static fn (): int => count($rows),
    static fn (int $vodId): ?array => $rows[$vodId] ?? null,
    static fn (int $vodId): array => $locales[$vodId] ?? [],
    static fn (int $vodId): array => $terms[$vodId] ?? [],
    ['video.test']
);

$queue = $workspace->queue(1, 20);
publicationAssert($queue['total'] === 1 && $queue['rows'][0]['workflow_status'] === 'manual_review', 'queue must expose only manual-review publication candidates with paging.');
$preview = $workspace->preview(42);
publicationAssert($preview['publishable'] === true && $preview['blockers'] === [], 'complete canonical content must be publishable.');
publicationAssert($preview['locales']['zh-TW']['title'] === '主要片名' && $preview['locales']['en']['summary'] === 'English summary', 'preview must expose multilingual titles and summaries.');
publicationAssert(count($preview['taxonomy']['region']) === 1 && count($preview['taxonomy']['genre']) === 1, 'preview must expose active region and genre mappings.');
publicationAssert($preview['media']['poster'] !== '' && $preview['media']['backdrop'] !== '' && $preview['media']['trailer'] !== '', 'preview must expose image and trailer media.');
publicationAssert($preview['playback'][0]['episodes'][0]['url'] === 'https://video.test/1.m3u8', 'preview must decode native playback into sources and episodes.');
publicationAssert(preg_match('/^[a-f0-9]{64}$/', $preview['revision']) === 1, 'preview must bind confirmation to a deterministic content revision.');

$warningRows = [43 => publicationFixture(['vod_id' => 43, 'vod_pic_slide' => '', 'trailer_url' => '', 'poster_s3' => ''])];
$warningWorkspace = new FinalPublicationWorkspace(
    static fn (): array => array_values($warningRows), static fn (): int => 1,
    static fn (int $vodId): ?array => $warningRows[$vodId] ?? null,
    static fn (): array => ['zh-TW' => ['vod_name' => '主要片名']],
    static fn (): array => $terms[42], ['video.test']
);
$warningPreview = $warningWorkspace->preview(43);
publicationAssert($warningPreview['publishable'] === true && count($warningPreview['warnings']) >= 4, 'missing optional locales, backdrop, trailer and S3 poster must be warnings, not blockers.');
publicationAssert($warningPreview['media']['poster'] === 'https://img.test/poster.jpg', 'empty S3 poster must fall back to the native poster.');

$blockedCases = [
    'invalid public identity' => publicationFixture(['public_id' => 'bad']),
    'merged content' => publicationFixture(['merged_into_vod_id' => 99]),
    'wrong workflow' => publicationFixture(['workflow_status' => 'published']),
    'missing title' => publicationFixture(['vod_name' => '']),
    'missing taxonomy' => publicationFixture(['vod_area' => '', 'vod_class' => '']),
    'missing playback' => publicationFixture(['vod_play_url' => '']),
    'missing playback source' => publicationFixture(['vod_play_from' => '']),
    'unsafe playback' => publicationFixture(['vod_play_url' => '第1集$javascript:alert(1)']),
];
foreach ($blockedCases as $label => $row) {
    $caseWorkspace = new FinalPublicationWorkspace(
        static fn (): array => [], static fn (): int => 0, static fn (): array => $row,
        static fn (): array => $locales[42],
        static function () use ($label, $terms): array { return $label === 'missing taxonomy' ? [] : $terms[42]; },
        ['video.test']
    );
    $casePreview = $caseWorkspace->preview(42);
    publicationAssert($casePreview['publishable'] === false && $casePreview['blockers'] !== [], "{$label} must block publication.");
}

foreach (['第1集$vbscript:alert(1)', '第1集$http://127.0.0.1/video', '第1集$https://evil.test/video'] as $unsafeUrl) {
    $unsafeRow = publicationFixture(['vod_play_url' => $unsafeUrl]);
    $unsafeWorkspace = new FinalPublicationWorkspace(
        static fn (): array => [], static fn (): int => 0, static fn (): array => $unsafeRow,
        static fn (): array => $locales[42], static fn (): array => $terms[42], ['video.test']
    );
    publicationAssert($unsafeWorkspace->preview(42)['publishable'] === false, 'private, custom-scheme and non-allowlisted playback URLs must block publication.');
}

foreach ([['第1集$http://127.1/video', ['127.1']], ['第1集$http://[::1]/video', ['[::1]']]] as $privateCase) {
    $privateRow = publicationFixture(['vod_play_url' => $privateCase[0]]);
    $privateWorkspace = new FinalPublicationWorkspace(
        static fn (): array => [], static fn (): int => 0, static fn (): array => $privateRow,
        static fn (): array => $locales[42], static fn (): array => $terms[42], $privateCase[1]
    );
    publicationAssert($privateWorkspace->preview(42)['publishable'] === false, 'alternative private-address literals must remain blocked even when allowlisted.');
}

$events = [];
$audit = new ContentAdminAudit(static function (array $row) use (&$events): int { $events[] = $row; return count($events); }, static fn (): int => 1726800000);
$locked = publicationFixture();
$state = $locked;
$cacheInvalidations = [];
$searchSynchronizations = [];
$transaction = static function (callable $callback) use (&$state) {
    $before = $state;
    try { return $callback(); } catch (Throwable $exception) { $state = $before; throw $exception; }
};
$service = new FinalPublicationService(
    $workspace, $audit, $transaction,
    static function (int $vodId) use (&$locked): array { return $locked; },
    static function (int $vodId, array $update) use (&$state): bool { $state = array_merge($state, $update); return true; },
    static function (int $vodId, array $update) use (&$state): bool { $state = array_merge($state, $update); return true; },
    static fn (): int => 1726800000,
    static function (int $vodId, string $publicId) use (&$cacheInvalidations): void { $cacheInvalidations[] = [$vodId, $publicId]; },
    static function (int $vodId) use (&$searchSynchronizations): void { $searchSynchronizations[] = $vodId; }
);
$published = $service->publish(42, 9, 'editor', ['content_workspace/publish'], true, $preview['revision']);
publicationAssert($published['workflow_status'] === 'published' && $state['workflow_status'] === 'published', 'publication must transition the separate workflow state.');
publicationAssert($state['vod_status'] === 1 && $state['vod_publish_time'] === 0 && $state['published_at'] === 1726800000, 'publication must activate native visibility and store publication time.');
publicationAssert($state['vod_area'] === '日本' && $state['vod_class'] === '劇情' && $state['vod_tag'] === '成長', 'publication must synchronize reviewed taxonomy into native MACCMS fields.');
publicationAssert(count($events) === 1 && $events[0]['event_code'] === 'content.publish' && $events[0]['subject_public_id'] === 'ABC234', 'publication must append one immutable public-ID audit event.');
publicationAssert($cacheInvalidations === [[42, 'ABC234']], 'successful publication must precisely invalidate public content caches.');
publicationAssert($searchSynchronizations === [42], 'successful publication must synchronize the published video into the configured search index.');

foreach ([[[], true], [['content_workspace/publish'], false]] as $denied) {
    try { $service->publish(42, 9, 'editor', $denied[0], $denied[1], $preview['revision']); publicationAssert(false, 'publication bypassed exact permission or confirmation.'); }
    catch (RuntimeException $exception) {}
}
$locked = publicationFixture(['workflow_status' => 'merged']);
try { $service->publish(42, 9, 'editor', ['content_workspace/publish'], true, $preview['revision']); publicationAssert(false, 'stale workflow state was published after locking.'); }
catch (Throwable $exception) {}
$locked = publicationFixture();

$changedPreview = $workspace->previewRow(publicationFixture(['vod_name' => '另一個仍有效片名']));
try { $service->publish(42, 9, 'editor', ['content_workspace/publish'], true, $changedPreview['revision']); publicationAssert(false, 'publication accepted a confirmation for a different preview revision.'); }
catch (RuntimeException $exception) {}

$rollbackState = publicationFixture();
$failingAudit = new ContentAdminAudit(static fn (): int => 0);
$rollbackService = new FinalPublicationService(
    $workspace, $failingAudit,
    static function (callable $callback) use (&$rollbackState) { $before = $rollbackState; try { return $callback(); } catch (Throwable $e) { $rollbackState = $before; throw $e; } },
    static fn (): array => publicationFixture(),
    static function (int $vodId, array $update) use (&$rollbackState): bool { $rollbackState = array_merge($rollbackState, $update); return true; },
    static function (int $vodId, array $update) use (&$rollbackState): bool { $rollbackState = array_merge($rollbackState, $update); return true; },
    static fn (): int => 1726800000
);
try { $rollbackService->publish(42, 9, 'editor', ['content_workspace/publish'], true, $preview['revision']); publicationAssert(false, 'audit failure did not abort publication.'); }
catch (RuntimeException $exception) {}
publicationAssert($rollbackState['vod_status'] === 0 && $rollbackState['workflow_status'] === 'manual_review' && $rollbackState['published_at'] === 0, 'audit failure must roll back native and workflow publication state.');

$controller = @file_get_contents($root . '/application/admin/controller/ContentWorkspace.php') ?: '';
$template = @file_get_contents($root . '/application/admin/view_new/content_workspace/publish.html') ?: '';
$dashboard = @file_get_contents($root . '/application/admin/view_new/content_workspace/index.html') ?: '';
foreach (['function publish', "assertAllowed('publish'", 'mac_admin_csrf_token', 'confirmed', 'revision'] as $needle) {
    publicationAssert(strpos($controller, $needle) !== false, 'publication controller contract missing ' . $needle . '.');
}
foreach (['|htmlentities', '發布阻擋', '發布提醒', '多語標題', '分類與地區', '播放來源', 'name="confirmed"', 'data-confirm', 'preview.ext.revision'] as $needle) {
    publicationAssert(strpos($template, $needle) !== false, 'publication view contract missing ' . $needle . '.');
}
publicationAssert(strpos($dashboard, 'content_workspace/publish') !== false, 'workspace dashboard must link to final publication.');
$base = @file_get_contents($root . '/application/admin/controller/Base.php') ?: '';
publicationAssert(strpos($base, "(string)\$this->_admin['admin_id'] === '1'") !== false, 'super admin must retain publication route access before exact delegated-admin checks.');
foreach (['content_lang', 'vod_meta_term', 'lock(true)', 'information_schema.TABLES', "_vod_detail_' . \$vodId"] as $needle) {
    publicationAssert(strpos(@file_get_contents($root . '/application/common/util/FinalPublicationService.php') ?: '', $needle) !== false, 'publication transaction must lock the complete reviewed snapshot: ' . $needle . '.');
}

fwrite(STDOUT, "OK: final validation and atomic publication UI contract passed.\n");
