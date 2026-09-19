<?php

namespace app\common\util;

use RuntimeException;
use think\Db;
use Throwable;

final class FinalPublicationService
{
    private $workspace;
    private $audit;
    private $transaction;
    private $locker;
    private $nativeUpdater;
    private $extensionUpdater;
    private $clock;

    public function __construct(FinalPublicationWorkspace $workspace = null, ContentAdminAudit $audit = null, callable $transaction = null, callable $locker = null, callable $nativeUpdater = null, callable $extensionUpdater = null, callable $clock = null)
    {
        $this->workspace = $workspace ?: new FinalPublicationWorkspace();
        $this->audit = $audit ?: new ContentAdminAudit();
        $this->transaction = $transaction ?: static function (callable $callback) {
            Db::startTrans();
            try { $result = $callback(); Db::commit(); return $result; }
            catch (Throwable $exception) { Db::rollback(); throw $exception; }
        };
        $this->locker = $locker ?: static function (int $vodId): array {
            $video = Db::name('vod')->where('vod_id', $vodId)->lock(true)->find();
            $ext = Db::name('vod_ext')->where('vod_id', $vodId)->lock(true)->find();
            if (!$video || !$ext) { throw new RuntimeException('Publication candidate was not found.'); }
            return array_merge($video, $ext);
        };
        $this->nativeUpdater = $nativeUpdater ?: static function (int $vodId, array $update): bool {
            return Db::name('vod')->where('vod_id', $vodId)->update($update) !== false;
        };
        $this->extensionUpdater = $extensionUpdater ?: static function (int $vodId, array $update): bool {
            return Db::name('vod_ext')->where('vod_id', $vodId)->where('workflow_status', VodWorkflow::MANUAL_REVIEW)->update($update) === 1;
        };
        $this->clock = $clock ?: 'time';
    }

    public function publish(int $vodId, int $actorId, string $actorName, array $grants, bool $confirmed): array
    {
        (new ContentAdminPolicy())->assertAllowed('publish', $grants, $confirmed);
        return call_user_func($this->transaction, function () use ($vodId, $actorId, $actorName): array {
            $locked = call_user_func($this->locker, $vodId);
            $preview = $this->workspace->previewRow($locked);
            if (!$preview['publishable']) { throw new RuntimeException('Publication validation failed.'); }
            VodWorkflow::assertTransition((string) $locked['workflow_status'], VodWorkflow::PUBLISHED);
            $now = (int) call_user_func($this->clock);
            if (!call_user_func($this->nativeUpdater, $vodId, ['vod_status' => 1, 'vod_publish_time' => 0])) {
                throw new RuntimeException('Native publication update failed.');
            }
            if (!call_user_func($this->extensionUpdater, $vodId, ['workflow_status' => VodWorkflow::PUBLISHED, 'published_at' => $now, 'updated_at' => $now])) {
                throw new RuntimeException('Workflow publication update failed.');
            }
            $before = ['workflow_status' => VodWorkflow::MANUAL_REVIEW, 'vod_status' => (int) ($locked['vod_status'] ?? 0), 'published_at' => (int) ($locked['published_at'] ?? 0)];
            $after = ['workflow_status' => VodWorkflow::PUBLISHED, 'vod_status' => 1, 'published_at' => $now];
            $this->audit->append($actorId, $actorName, 'content.publish', 'vod', (string) $locked['public_id'], $before, $after, [
                'vod_id' => $vodId, 'warning_count' => count($preview['warnings']), 'playback_source_count' => count($preview['playback']),
            ]);
            return ['vod_id' => $vodId, 'public_id' => (string) $locked['public_id'], 'workflow_status' => VodWorkflow::PUBLISHED, 'vod_status' => 1, 'published_at' => $now];
        });
    }
}
