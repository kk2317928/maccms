<?php

namespace app\admin\controller;

use app\common\util\TaxonomyDictionaryService;

class TaxonomyDictionary extends Base
{
    public function index()
    {
        $filters = [
            'kind' => (string) input('kind', ''),
            'status' => input('status', ''),
            'q' => (string) input('q', ''),
        ];
        try {
            $rows = (new TaxonomyDictionaryService())->list($filters);
        } catch (\Throwable $exception) {
            $rows = [];
        }
        $this->assign('list', $rows);
        $this->assign('filters', $filters);
        $this->assign('title', '內容分類詞典');
        return $this->fetch('admin@taxonomy_dictionary/index');
    }

    public function info()
    {
        $service = new TaxonomyDictionaryService();
        if (Request()->isPost()) {
            $param = input('post.');
            $validate = \think\Loader::validate('Token');
            if (!$validate->check($param)) {
                return $this->ajaxErrorWithFreshToken($validate->getError());
            }
            unset($param['__token__']);
            try {
                $saved = $service->save($param, (int) $this->_admin['admin_id']);
                return $this->success('詞典項目已儲存', url('taxonomy_dictionary/info', ['id' => (int) $saved['term_id']]));
            } catch (\Throwable $exception) {
                return $this->ajaxErrorWithFreshToken($exception->getMessage());
            }
        }

        $id = (int) input('id/d', 0);
        $info = [
            'term_id' => 0, 'kind' => 'genre', 'slug' => '', 'name_tw' => '',
            'name_cn' => '', 'name_en' => '', 'synonyms_json' => '[]',
            'status' => 1, 'sort' => 0,
        ];
        if ($id > 0) {
            foreach ($service->list([]) as $row) {
                if ((int) $row['term_id'] === $id) { $info = $row; break; }
            }
        }
        $synonyms = json_decode((string) ($info['synonyms_json'] ?? '[]'), true);
        $info['synonyms'] = is_array($synonyms) ? implode("\n", $synonyms) : '';
        $this->assign('info', $info);
        $this->assign('title', '內容分類詞典');
        return $this->fetch('admin@taxonomy_dictionary/info');
    }

    public function remove()
    {
        if (!Request()->isPost()) {
            return $this->error('請使用 POST 操作');
        }
        $param = input('post.');
        $validate = \think\Loader::validate('Token');
        if (!$validate->check($param)) {
            return $this->ajaxErrorWithFreshToken($validate->getError());
        }
        try {
            $service = new TaxonomyDictionaryService();
            $termId = (int) ($param['term_id'] ?? 0);
            $result = !empty($param['deactivate'])
                ? ['action' => 'deactivated', 'term' => $service->deactivate($termId, (int) $this->_admin['admin_id'])]
                : $service->delete($termId, (int) $this->_admin['admin_id']);
            return $this->success($result['action'] === 'deleted' ? '詞典項目已刪除' : '詞典項目已停用');
        } catch (\Throwable $exception) {
            return $this->ajaxErrorWithFreshToken($exception->getMessage());
        }
    }
}
