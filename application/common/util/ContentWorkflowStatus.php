<?php

namespace app\common\util;

use InvalidArgumentException;
use RuntimeException;
use think\Db;

class ContentWorkflowStatus
{
    public function forVideo(int $vodId): array
    {
        if ($vodId <= 0) {
            throw new InvalidArgumentException('Video ID must be positive.');
        }
        $extension = $this->loadExtension($vodId);
        if (!$extension) {
            throw new RuntimeException('Video extension row was not found.');
        }
        $job = $this->loadLatestJob($vodId);
        $state = (string) ($extension['workflow_status'] ?? '');
        $failure = (string) ($job['last_error_class'] ?? $job['error_class'] ?? '');
        return [
            'public_id' => (string) ($extension['public_id'] ?? ''),
            'workflow_status' => $state,
            'ai_completed_at' => (int) ($extension['ai_completed_at'] ?? 0),
            'duplicate_checked_at' => (int) ($extension['duplicate_checked_at'] ?? 0),
            'tmdb_completed_at' => (int) ($extension['tmdb_completed_at'] ?? 0),
            'updated_at' => (int) ($extension['updated_at'] ?? 0),
            'latest_job' => $job,
            'safe_failure_class' => $failure,
            'can_rerun_ai' => in_array($state, ['imported', 'ai_processing', 'failed'], true),
            'can_rerun_tmdb' => in_array($state, ['tmdb_matching', 'manual_review', 'failed'], true),
        ];
    }

    public function rerunAi(int $vodId, int $actorId): array
    {
        if ($vodId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('AI rerun identity is invalid.');
        }
        $vod = Db::name('vod')->where('vod_id', $vodId)
            ->field(implode(',', VodExtensionService::AI_INPUT_FIELDS))->find();
        if (!$vod) {
            throw new RuntimeException('Video was not found.');
        }
        $fingerprint = VodExtensionService::contentFingerprint($vod);
        return (new ContentWorkflowCoordinator())->beginAi($vodId, $fingerprint);
    }

    public function rerunTmdb(int $vodId, int $actorId): array
    {
        if ($vodId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('TMDB rerun identity is invalid.');
        }
        if (Db::name('vod_ext')->where('vod_id', $vodId)->count() !== 1) {
            throw new RuntimeException('Video extension row was not found.');
        }
        return (new TmdbReviewWorkspace())->enqueueRematch($vodId, $actorId);
    }

    protected function loadExtension(int $vodId): array
    {
        $row = Db::name('vod_ext')->where('vod_id', $vodId)
            ->field('public_id,workflow_status,ai_completed_at,duplicate_checked_at,tmdb_completed_at,updated_at')->find();
        return $row ?: [];
    }

    protected function loadLatestJob(int $vodId): array
    {
        $rows = Db::name('content_job')
            ->where('job_type', 'in', ['ai_normalize', 'ai.normalize', 'tmdb_review', 'tmdb_match', 'tmdb_manual_match'])
            ->order('job_id desc')->limit(100)->select();
        foreach ((array) $rows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (is_array($payload) && (int) ($payload['vod_id'] ?? 0) === $vodId) {
                $row['last_error_class'] = (string) ($row['error_class'] ?? '');
                unset($row['payload_json'], $row['error_summary'], $row['lock_owner'], $row['idempotency_key']);
                return $row;
            }
        }
        return [];
    }
}
