<?php

namespace app\admin\controller;

use app\common\util\ContentWorkspaceDashboard;

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
}
