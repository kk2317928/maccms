<?php

namespace app\admin\controller;

use app\common\util\ContentWorkspaceDashboard;
use app\common\util\AiFieldReviewService;
use app\common\util\ContentAdminAudit;
use app\common\util\FieldGovernance;
use app\common\util\ContentAdminPolicy;
use app\common\util\DuplicateCandidateDecisionService;
use app\common\util\DuplicateMergeService;
use app\common\util\DuplicateRestoreService;
use app\common\util\DuplicateReviewWorkspace;
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
        $service = new AiFieldReviewService(new FieldGovernance(), new ContentAdminAudit());
        if (request()->isPost()) {
            $param = input('post.');
            $token = (string) ($param['__token__'] ?? '');
            $stableToken = function_exists('mac_admin_csrf_token') ? (string) mac_admin_csrf_token() : (string) Session::get('admin_csrf');
            $legacyToken = Session::has('__token__') ? (string) Session::get('__token__') : '';
            if ($token === '' || !(($stableToken !== '' && hash_equals($stableToken, $token)) || ($legacyToken !== '' && hash_equals($legacyToken, $token)))) {
                return json(['code' => 0, 'msg' => lang('token_err')]);
            }
            try {
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

    public function mergeRestore()
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
                return json(['code' => 0, 'msg' => '重複內容操作未完成，請重新整理並檢查衝突。']);
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
}
