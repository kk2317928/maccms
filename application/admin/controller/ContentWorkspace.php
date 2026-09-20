<?php

namespace app\admin\controller;

use app\common\util\ContentWorkspaceDashboard;
use app\common\util\ContentJobAdminService;
use app\common\util\AiFieldReviewService;
use app\common\util\ContentAdminAudit;
use app\common\util\FieldGovernance;
use app\common\util\ContentAdminPolicy;
use app\common\util\DuplicateCandidateDecisionService;
use app\common\util\DuplicateMergeService;
use app\common\util\DuplicateRestoreService;
use app\common\util\DuplicateReviewWorkspace;
use app\common\util\TmdbImportService;
use app\common\util\TmdbReviewWorkspace;
use app\common\util\TaxonomySuggestionService;
use app\common\util\FinalPublicationWorkspace;
use app\common\util\FinalPublicationService;
use think\Db;
use think\Session;
use Throwable;

class ContentWorkspace extends Base
{
    public function __construct()
    {
        parent::__construct();
        $this->view->config('view_path', APP_PATH . 'admin/view_new/');
    }

    public function view()
    {
        $config = config('maccms');
        $config = is_array($config) ? $config : [];
        $snapshot = (new ContentWorkspaceDashboard())->snapshot(ContentWorkspaceDashboard::optionsFromConfig($config));

        $this->assign('dashboard', $snapshot);
        $this->assign('workflow_labels', [
            'imported' => '已匯入', 'ai_processing' => 'AI 處理中', 'duplicate_review' => '重複審核',
            'tmdb_matching' => 'TMDB 配對', 'manual_review' => '人工審核', 'failed' => '失敗',
            'published' => '已發布', 'rejected' => '已拒絕', 'merged' => '已合併',
        ]);
        $this->assign('title', '智能內容工作區');
        return $this->fetch('content_workspace/index');
    }

    public function review()
    {
        $taxonomy = new TaxonomySuggestionService();
        $service = new AiFieldReviewService(new FieldGovernance(), new ContentAdminAudit(), null, $taxonomy);
        if (request()->isPost()) {
            $param = input('post.');
            $token = (string) ($param['__token__'] ?? '');
            $stableToken = function_exists('mac_admin_csrf_token') ? (string) mac_admin_csrf_token() : (string) Session::get('admin_csrf');
            $legacyToken = Session::has('__token__') ? (string) Session::get('__token__') : '';
            if ($token === '' || !(($stableToken !== '' && hash_equals($stableToken, $token)) || ($legacyToken !== '' && hash_equals($legacyToken, $token)))) {
                return json(['code' => 0, 'msg' => lang('token_err')]);
            }
            try {
                if (in_array((string) ($param['review_action'] ?? ''), ['accept_taxonomy', 'reject_taxonomy'], true)) {
                    $result = $taxonomy->review(
                        (int) ($param['suggestion_id'] ?? 0),
                        (string) $param['review_action'] === 'accept_taxonomy' ? 'accept' : 'reject',
                        (int) $this->_admin['admin_id'],
                        (string) ($this->_admin['admin_name'] ?? ('admin-' . $this->_admin['admin_id']))
                    );
                    return json(['code' => 1, 'msg' => 'ok', 'data' => $result]);
                }
                $result = $service->review(
                    (int) ($param['run_id'] ?? 0), (string) ($param['field'] ?? ''),
                    (string) ($param['review_action'] ?? ''), $param['edited_value'] ?? null,
                    (int) $this->_admin['admin_id'], (string) ($this->_admin['admin_name'] ?? ('admin-' . $this->_admin['admin_id']))
                );
                return json(['code' => 1, 'msg' => 'ok', 'data' => $result]);
            } catch (Throwable $exception) {
                return json(['code' => 0, 'msg' => '欄位審核未完成，請重新整理後再試。']);
            }
        }
        $runId = (int) input('param.run_id/d', 0);
        try { $preview = $runId > 0 ? $service->preview($runId) : null; }
        catch (Throwable $exception) { $preview = null; }
        $this->assign('preview', $preview);
        $this->assign('queue', $service->pendingRuns(max(1, (int) input('param.page/d', 1)), 20));
        $this->assign('title', 'AI 欄位審核');
        return $this->fetch('content_workspace/review');
    }

    public function merge_restore()
    {
        $workspace = new DuplicateReviewWorkspace();
        if (request()->isPost()) {
            $param = input('post.');
            $token = (string) ($param['__token__'] ?? '');
            $stable = function_exists('mac_admin_csrf_token') ? (string) mac_admin_csrf_token() : (string) Session::get('admin_csrf');
            $legacy = Session::has('__token__') ? (string) Session::get('__token__') : '';
            if ($token === '' || !(($stable !== '' && hash_equals($stable, $token)) || ($legacy !== '' && hash_equals($legacy, $token)))) {
                return json(['code' => 0, 'msg' => lang('token_err')]);
            }
            try {
                $confirmed = (int) ($param['confirmed'] ?? 0) === 1;
                $actorId = (int) $this->_admin['admin_id'];
                $grants = array_filter(array_map('trim', explode(',', strtolower((string) ($this->_admin['admin_auth'] ?? '')))));
                if ($actorId === 1) { $grants[] = 'content_workspace/merge_restore'; }
                (new ContentAdminPolicy())->assertAllowed('merge_restore', $grants, $confirmed);
                $actorName = (string) ($this->_admin['admin_name'] ?? ('admin-' . $actorId));
                $action = (string) ($param['review_action'] ?? '');
                $audit = new ContentAdminAudit();
                if ($action === 'different') {
                    $candidateId = (int) ($param['candidate_id'] ?? 0);
                    $comparison = $workspace->compare($candidateId);
                    (new DuplicateCandidateDecisionService())->markDifferent($candidateId, $actorId, function () use ($audit, $actorId, $actorName, $comparison, $candidateId) {
                        $audit->append($actorId, $actorName, 'content.duplicate.different', 'vod', (string) $comparison['left']['ext']['public_id'],
                            ['decision' => (string) $comparison['candidate']['decision']], ['decision' => 'different'],
                            ['duplicate_candidate_id' => $candidateId, 'other_public_id' => (string) $comparison['right']['ext']['public_id']]);
                    });
                    $result = ['decision' => 'different'];
                } elseif ($action === 'merge') {
                    $candidateId = (int) ($param['candidate_id'] ?? 0);
                    $comparison = $workspace->compare($candidateId);
                    $leftId = (int) $comparison['candidate']['vod_id_low'];
                    $rightId = (int) $comparison['candidate']['vod_id_high'];
                    $primaryId = (int) ($param['primary_vod_id'] ?? 0);
                    if (!in_array($primaryId, [$leftId, $rightId], true)) { throw new \InvalidArgumentException('Primary video is not part of this candidate.'); }
                    $secondaryId = $primaryId === $leftId ? $rightId : $leftId;
                    $primary = $primaryId === $leftId ? $comparison['left'] : $comparison['right'];
                    $secondary = $primaryId === $leftId ? $comparison['right'] : $comparison['left'];
                    $snapshotId = (new DuplicateMergeService())->merge($candidateId, $primaryId, $secondaryId, $actorId, function () use ($audit, $actorId, $actorName, $primary, $secondary, $candidateId) {
                        $audit->append($actorId, $actorName, 'content.duplicate.merge', 'vod', (string) $primary['ext']['public_id'],
                            ['decision' => 'pending'], ['decision' => 'merged'],
                            ['duplicate_candidate_id' => $candidateId, 'secondary_public_id' => (string) $secondary['ext']['public_id']]);
                    });
                    $result = ['decision' => 'merged', 'snapshot_id' => $snapshotId];
                } elseif ($action === 'restore') {
                    $snapshotId = (int) ($param['snapshot_id'] ?? 0);
                    $preview = $workspace->snapshotPreview($snapshotId);
                    $primaryPublicId = (string) ($preview['payload']['primary']['ext']['public_id'] ?? '');
                    $secondaryPublicId = (string) ($preview['payload']['secondary']['ext']['public_id'] ?? '');
                    (new DuplicateRestoreService())->restore($snapshotId, $actorId, true, static fn (): bool => true, function () use ($audit, $actorId, $actorName, $snapshotId, $primaryPublicId, $secondaryPublicId) {
                        $audit->append($actorId, $actorName, 'content.duplicate.restore', 'vod', $primaryPublicId,
                            ['decision' => 'merged'], ['decision' => 'pending'],
                            ['merge_snapshot_id' => $snapshotId, 'secondary_public_id' => $secondaryPublicId]);
                    });
                    $result = ['decision' => 'restored'];
                } else {
                    throw new \InvalidArgumentException('Unsupported duplicate review action.');
                }
                return json(['code' => 1, 'msg' => 'ok', 'data' => $result]);
            } catch (Throwable $exception) {
                $message = strpos($exception->getMessage(), 'Restoration conflict') !== false
                    ? '偵測到合併後資料變更（Restoration conflict），禁止還原。'
                    : '重複內容操作未完成，請重新整理並檢查衝突。';
                return json(['code' => 0, 'msg' => $message]);
            }
        }
        $candidateId = (int) input('param.candidate_id/d', 0);
        $snapshotId = (int) input('param.snapshot_id/d', 0);
        try { $comparison = $candidateId > 0 ? $workspace->compare($candidateId) : null; } catch (Throwable $exception) { $comparison = null; }
        try { $snapshot = $snapshotId > 0 ? $workspace->snapshotPreview($snapshotId) : null; } catch (Throwable $exception) { $snapshot = null; }
        $this->assign('queue', $workspace->queue(max(1, (int) input('param.page/d', 1)), 20));
        $this->assign('comparison', $comparison);
        $this->assign('snapshot_preview', $snapshot);
        $this->assign('title', '重複內容比較與還原');
        return $this->fetch('content_workspace/merge_restore');
    }

    public function tmdb_review()
    {
        $workspace = new TmdbReviewWorkspace();
        $fields = new FieldGovernance();
        $importer = new TmdbImportService();
        if (request()->isPost()) {
            $param = input('post.');
            $token = (string) ($param['__token__'] ?? '');
            $stable = function_exists('mac_admin_csrf_token') ? (string) mac_admin_csrf_token() : (string) Session::get('admin_csrf');
            $legacy = Session::has('__token__') ? (string) Session::get('__token__') : '';
            if ($token === '' || !(($stable !== '' && hash_equals($stable, $token)) || ($legacy !== '' && hash_equals($legacy, $token)))) {
                return json(['code' => 0, 'msg' => lang('token_err')]);
            }
            try {
                $action = (string) ($param['review_action'] ?? '');
                $actorId = (int) $this->_admin['admin_id'];
                $actorName = (string) ($this->_admin['admin_name'] ?? ('admin-' . $actorId));
                $grants = array_filter(array_map('trim', explode(',', strtolower((string) ($this->_admin['admin_auth'] ?? '')))));
                if ($actorId === 1) { $grants = array_merge($grants, ['content_workspace/review', 'content_workspace/run_tmdb']); }
                $policy = new ContentAdminPolicy();
                $audit = new ContentAdminAudit();
                Db::startTrans();
                if ($action === 'select') {
                    $policy->assertAllowed('review', $grants);
                    $reviewId = (int) ($param['review_id'] ?? 0);
                    $type = strtolower((string) ($param['tmdb_type'] ?? ''));
                    $tmdbId = (int) ($param['tmdb_id'] ?? 0);
                    $workspace->lockForUpdate($reviewId);
                    $preview = $workspace->preview($reviewId, static fn (int $vodId, array $candidate): array => $importer->preview($vodId, $candidate, $fields), $type, $tmdbId);
                    $candidate = null;
                    foreach ($preview['candidates'] as $item) {
                        if ((int) $item['id'] === $tmdbId && strtolower((string) $item['media_type']) === $type) { $candidate = $item; break; }
                    }
                    if (!$candidate) { throw new \InvalidArgumentException('Stored TMDB candidate was not found.'); }
                    $approved = array_values(array_filter((array) ($param['approved_fields'] ?? []), 'is_string'));
                    $applied = $importer->apply((int) $preview['review']['vod_id'], $candidate, $approved, $fields, $actorId, false);
                    $result = $workspace->select($reviewId, $type, $tmdbId, $actorId);
                    $audit->append($actorId, $actorName, 'content.tmdb.select', 'vod', (string) $preview['review']['public_id'],
                        ['status' => 'candidate_review'], $result, ['approved_fields' => $approved, 'applied' => $applied]);
                    $result['fields'] = $applied;
                } elseif ($action === 'no_match') {
                    $policy->assertAllowed('review', $grants);
                    $reviewId = (int) ($param['review_id'] ?? 0);
                    $workspace->lockForUpdate($reviewId);
                    $preview = $workspace->preview($reviewId, static fn (): array => []);
                    $result = $workspace->noMatch($reviewId, $actorId);
                    $audit->append($actorId, $actorName, 'content.tmdb.no_match', 'vod', (string) $preview['review']['public_id'],
                        ['status' => 'candidate_review'], $result, []);
                } elseif ($action === 'manual') {
                    $policy->assertAllowed('run_tmdb', $grants);
                    $vodId = (int) ($param['vod_id'] ?? 0);
                    $result = $workspace->enqueueManual($vodId, (string) ($param['tmdb_type'] ?? ''), (int) ($param['tmdb_id'] ?? 0), $actorId);
                    $audit->append($actorId, $actorName, 'content.tmdb.manual_enqueue', 'vod', '', [], ['job_id' => (int) $result['job_id']], ['vod_id' => $vodId]);
                } elseif ($action === 'rematch') {
                    $policy->assertAllowed('run_tmdb', $grants);
                    $vodId = (int) ($param['vod_id'] ?? 0);
                    $result = $workspace->enqueueRematch($vodId, $actorId);
                    $audit->append($actorId, $actorName, 'content.tmdb.rematch_enqueue', 'vod', '', [], ['job_id' => (int) $result['job_id']], ['vod_id' => $vodId]);
                } else {
                    throw new \InvalidArgumentException('Unsupported TMDB review action.');
                }
                Db::commit();
                return json(['code' => 1, 'msg' => 'ok', 'data' => $result]);
            } catch (Throwable $exception) {
                Db::rollback();
                return json(['code' => 0, 'msg' => 'TMDB 操作未完成，請重新整理並檢查候選狀態。']);
            }
        }
        $reviewId = (int) input('param.review_id/d', 0);
        $candidateType = (string) input('param.candidate_type/s', '');
        $candidateId = (int) input('param.candidate_id/d', 0);
        try { $preview = $reviewId > 0 ? $workspace->preview($reviewId, static fn (int $vodId, array $candidate): array => $importer->preview($vodId, $candidate, $fields), $candidateType, $candidateId) : null; }
        catch (Throwable $exception) { $preview = null; }
        $this->assign('queue', $workspace->queue(max(1, (int) input('param.page/d', 1)), 20));
        $this->assign('preview', $preview);
        $this->assign('title', 'TMDB 候選與手動配對');
        return $this->fetch('content_workspace/tmdb_review');
    }

    public function publish()
    {
        $workspace = new FinalPublicationWorkspace();
        if (request()->isPost()) {
            $param = input('post.');
            $token = (string) ($param['__token__'] ?? '');
            $stable = function_exists('mac_admin_csrf_token') ? (string) mac_admin_csrf_token() : (string) Session::get('admin_csrf');
            $legacy = Session::has('__token__') ? (string) Session::get('__token__') : '';
            if ($token === '' || !(($stable !== '' && hash_equals($stable, $token)) || ($legacy !== '' && hash_equals($legacy, $token)))) {
                return json(['code' => 0, 'msg' => lang('token_err')]);
            }
            try {
                $actorId = (int) $this->_admin['admin_id'];
                $actorName = (string) ($this->_admin['admin_name'] ?? ('admin-' . $actorId));
                $grants = array_filter(array_map('trim', explode(',', strtolower((string) ($this->_admin['admin_auth'] ?? '')))));
                if ($actorId === 1) { $grants[] = 'content_workspace/publish'; }
                $confirmed = (int) ($param['confirmed'] ?? 0) === 1;
                (new ContentAdminPolicy())->assertAllowed('publish', $grants, $confirmed);
                $result = (new FinalPublicationService($workspace, new ContentAdminAudit()))->publish((int) ($param['vod_id'] ?? 0), $actorId, $actorName, $grants, $confirmed, (string) ($param['revision'] ?? ''));
                return json(['code' => 1, 'msg' => '發布完成。', 'data' => $result]);
            } catch (Throwable $exception) {
                return json(['code' => 0, 'msg' => '發布未完成，請重新整理並檢查阻擋項目。']);
            }
        }
        $vodId = (int) input('param.vod_id/d', 0);
        try { $preview = $vodId > 0 ? $workspace->preview($vodId) : null; }
        catch (Throwable $exception) { $preview = null; }
        $this->assign('queue', $workspace->queue(max(1, (int) input('param.page/d', 1)), 20));
        $this->assign('preview', $preview);
        $this->assign('title', '最終驗證與發布');
        return $this->fetch('content_workspace/publish');
    }
    public function jobs()
    {
        $service = new ContentJobAdminService();
        if (request()->isPost()) {
            $param = input('post.');
            $token = (string) ($param['__token__'] ?? '');
            $stable = function_exists('mac_admin_csrf_token') ? (string) mac_admin_csrf_token() : (string) Session::get('admin_csrf');
            $legacy = Session::has('__token__') ? (string) Session::get('__token__') : '';
            if ($token === '' || !(($stable !== '' && hash_equals($stable, $token)) || ($legacy !== '' && hash_equals($legacy, $token)))) {
                return json(['code' => 0, 'msg' => lang('token_err')]);
            }
            try {
                $actorId = (int) $this->_admin['admin_id'];
                $actorName = (string) ($this->_admin['admin_name'] ?? ('admin-' . $actorId));
                $grants = array_filter(array_map('trim', explode(',', strtolower((string) ($this->_admin['admin_auth'] ?? '')))));
                if ($actorId === 1) { $grants = array_merge($grants, ['content_workspace/run_ai', 'content_workspace/run_tmdb']); }
                $confirmed = (int) ($param['confirmed'] ?? 0) === 1;
                if ((string) ($param['job_action'] ?? '') === 'retry') {
                    $result = $service->retry((int) ($param['job_id'] ?? 0), $actorId, $actorName, $grants, $confirmed);
                    return json(['code' => 1, 'msg' => '失敗工作已重新排入佇列。', 'data' => $result]);
                }
                $rawIds = preg_split('/[\s,，]+/u', trim((string) ($param['vod_ids'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $result = $service->enqueueBatch((string) ($param['job_type'] ?? ''), $rawIds, $actorId, $actorName, $grants, $confirmed);
                return json(['code' => 1, 'msg' => '批量工作已排入佇列。', 'data' => $result]);
            } catch (Throwable $exception) {
                return json(['code' => 0, 'msg' => '工作操作未完成，請檢查權限、確認狀態與輸入。']);
            }
        }
        $type = (string) input('param.job_type/s', '');
        $status = (string) input('param.status/s', '');
        $page = max(1, (int) input('param.page/d', 1));
        $jobId = (int) input('param.job_id/d', 0);
        try { $jobs = $service->page($type, $status, $page, 20); }
        catch (Throwable $exception) { $jobs = $service->page('', '', 1, 20); }
        $this->assign('jobs', $jobs);
        $this->assign('runs', $service->runs($jobId, 10));
        $this->assign('selected_job_id', $jobId);
        $this->assign('title', '批量工作與失敗重試');
        return $this->fetch('content_workspace/jobs');
    }

}
