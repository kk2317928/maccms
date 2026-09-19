<?php

namespace app\admin\controller;

use app\common\util\ContentWorkspaceDashboard;
use app\common\util\AiFieldReviewService;
use app\common\util\ContentAdminAudit;
use app\common\util\FieldGovernance;
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
}
