<?php

namespace app\common\util;

use DomainException;
use InvalidArgumentException;
use RuntimeException;
use think\Db;
use Throwable;

final class ContentWorkflowCoordinator
{
    private $loadForUpdate;
    private $save;
    private $transaction;
    private $enqueue;
    private $clock;

    public function __construct(
        callable $loadForUpdate = null,
        callable $save = null,
        callable $transaction = null,
        callable $enqueue = null,
        callable $clock = null
    ) {
        $this->loadForUpdate = $loadForUpdate ?: static function (int $vodId): array {
            $row = Db::name('vod_ext')->where('vod_id', $vodId)->lock(true)->find();
            if (!$row) {
                throw new RuntimeException('Video extension row does not exist.');
            }
            return $row;
        };
        $this->save = $save ?: static function (int $vodId, array $changes): void {
            $changes['updated_at'] = time();
            if (Db::name('vod_ext')->where('vod_id', $vodId)->update($changes) < 0) {
                throw new RuntimeException('Video workflow state could not be saved.');
            }
        };
        $this->transaction = $transaction ?: static function (callable $callback) {
            Db::startTrans();
            try {
                $result = $callback();
                Db::commit();
                return $result;
            } catch (Throwable $exception) {
                Db::rollback();
                throw $exception;
            }
        };
        $this->enqueue = $enqueue ?: static function (string $type, array $payload, string $key): array {
            return (new ContentJobRepository())->enqueue($type, $payload, $key);
        };
        $this->clock = $clock ?: 'time';
    }

    public function beginAi(int $vodId, string $fingerprint): array
    {
        $this->assertVodId($vodId);
        $fingerprint = trim($fingerprint);
        if ($fingerprint === '' || strlen($fingerprint) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/', $fingerprint)) {
            throw new InvalidArgumentException('Invalid content fingerprint.');
        }

        call_user_func($this->transaction, function () use ($vodId): void {
            $row = call_user_func($this->loadForUpdate, $vodId);
            $state = (string) ($row['workflow_status'] ?? '');
            if ($state === VodWorkflow::AI_PROCESSING) {
                return;
            }
            if ($state === VodWorkflow::FAILED) {
                VodWorkflow::assertTransition($state, VodWorkflow::IMPORTED);
                $state = VodWorkflow::IMPORTED;
            }
            VodWorkflow::assertTransition($state, VodWorkflow::AI_PROCESSING);
            call_user_func($this->save, $vodId, ['workflow_status' => VodWorkflow::AI_PROCESSING]);
        });

        $key = 'video:' . $vodId . ':ai:' . $fingerprint;
        return call_user_func($this->enqueue, 'ai_normalize', ['vod_id' => $vodId, 'content_fingerprint' => $fingerprint], $key);
    }

    public function completeAi(int $vodId, int $runId, bool $hasDuplicates): array
    {
        $this->assertVodId($vodId);
        if ($runId <= 0) {
            throw new InvalidArgumentException('AI run ID must be positive.');
        }
        $now = (int) call_user_func($this->clock);
        $target = $hasDuplicates ? VodWorkflow::DUPLICATE_REVIEW : VodWorkflow::TMDB_MATCHING;

        call_user_func($this->transaction, function () use ($vodId, $target, $now): void {
            $row = call_user_func($this->loadForUpdate, $vodId);
            $state = (string) ($row['workflow_status'] ?? '');
            if ($state === $target) {
                return;
            }
            VodWorkflow::assertTransition($state, VodWorkflow::DUPLICATE_REVIEW);
            if ($target === VodWorkflow::TMDB_MATCHING) {
                VodWorkflow::assertTransition(VodWorkflow::DUPLICATE_REVIEW, $target);
            }
            $changes = ['workflow_status' => $target, 'ai_completed_at' => $now];
            if ($target === VodWorkflow::TMDB_MATCHING) {
                $changes['duplicate_checked_at'] = $now;
            }
            call_user_func($this->save, $vodId, $changes);
        });

        if ($hasDuplicates) {
            return ['workflow_status' => $target, 'next_job' => null];
        }
        $job = $this->enqueueTmdb($vodId);
        return ['workflow_status' => $target, 'next_job' => $job];
    }

    public function completeDuplicateReview(int $vodId): array
    {
        $this->assertVodId($vodId);
        $now = (int) call_user_func($this->clock);
        call_user_func($this->transaction, function () use ($vodId, $now): void {
            $row = call_user_func($this->loadForUpdate, $vodId);
            $state = (string) ($row['workflow_status'] ?? '');
            if ($state === VodWorkflow::TMDB_MATCHING) {
                return;
            }
            VodWorkflow::assertTransition($state, VodWorkflow::TMDB_MATCHING);
            call_user_func($this->save, $vodId, [
                'workflow_status' => VodWorkflow::TMDB_MATCHING,
                'duplicate_checked_at' => $now,
            ]);
        });
        return $this->enqueueTmdb($vodId);
    }

    public function completeTmdbReview(int $vodId, int $reviewId): array
    {
        $this->assertVodId($vodId);
        if ($reviewId <= 0) {
            throw new InvalidArgumentException('TMDB review ID must be positive.');
        }
        $now = (int) call_user_func($this->clock);
        call_user_func($this->transaction, function () use ($vodId, $now): void {
            $row = call_user_func($this->loadForUpdate, $vodId);
            $state = (string) ($row['workflow_status'] ?? '');
            if ($state === VodWorkflow::MANUAL_REVIEW) {
                return;
            }
            VodWorkflow::assertTransition($state, VodWorkflow::MANUAL_REVIEW);
            call_user_func($this->save, $vodId, [
                'workflow_status' => VodWorkflow::MANUAL_REVIEW,
                'tmdb_completed_at' => $now,
            ]);
        });
        return ['workflow_status' => VodWorkflow::MANUAL_REVIEW, 'next_job' => null];
    }

    public function failStage(int $vodId, string $stage, string $safeClass): void
    {
        $this->assertVodId($vodId);
        if (!in_array($stage, [VodWorkflow::AI_PROCESSING, VodWorkflow::TMDB_MATCHING], true)) {
            throw new InvalidArgumentException('Only AI and TMDB stages may fail.');
        }
        if (!preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/', $safeClass)) {
            throw new InvalidArgumentException('Invalid safe failure class.');
        }
        call_user_func($this->transaction, function () use ($vodId, $stage): void {
            $row = call_user_func($this->loadForUpdate, $vodId);
            $state = (string) ($row['workflow_status'] ?? '');
            if ($state === VodWorkflow::FAILED) {
                return;
            }
            if ($state !== $stage) {
                throw new DomainException('Failure stage does not match current workflow state.');
            }
            VodWorkflow::assertTransition($state, VodWorkflow::FAILED);
            call_user_func($this->save, $vodId, ['workflow_status' => VodWorkflow::FAILED]);
        });
    }

    private function enqueueTmdb(int $vodId): array
    {
        return call_user_func($this->enqueue, 'tmdb_review', ['vod_id' => $vodId], 'video:' . $vodId . ':tmdb');
    }

    private function assertVodId(int $vodId): void
    {
        if ($vodId <= 0) {
            throw new InvalidArgumentException('Video ID must be positive.');
        }
    }
}
