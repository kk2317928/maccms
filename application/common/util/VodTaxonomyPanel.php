<?php

namespace app\common\util;

use InvalidArgumentException;
use think\Db;

class VodTaxonomyPanel
{
    public function forVideo(int $vodId): array
    {
        if ($vodId <= 0) {
            throw new InvalidArgumentException('Video ID must be positive.');
        }

        $accepted = ['region' => [], 'genre' => [], 'tag' => []];
        foreach ($this->acceptedRows($vodId) as $row) {
            $kind = (string) ($row['kind'] ?? '');
            if (isset($accepted[$kind])) {
                $accepted[$kind][] = $row;
            }
        }

        $locks = ['region' => false, 'genre' => false, 'tag' => false];
        foreach ($this->lockRows($vodId) as $row) {
            $field = (string) ($row['field_name'] ?? '');
            foreach (array_keys($locks) as $kind) {
                if (in_array($field, ['taxonomy.' . $kind, VodExtensionService::NATIVE_FIELDS[$kind]], true)
                    && (int) ($row['is_locked'] ?? 0) === 1) {
                    $locks[$kind] = true;
                }
            }
        }

        return [
            'accepted' => $accepted,
            'pending' => $this->pendingRows($vodId),
            'locks' => $locks,
            'workflow' => $this->workflowRow($vodId),
        ];
    }

    protected function acceptedRows(int $vodId): array
    {
        $rows = Db::name('vod_meta_term')->alias('v')
            ->join('__META_TERM__ m', 'm.term_id=v.term_id')
            ->where('v.vod_id', $vodId)
            ->field('m.term_id,m.kind,m.slug,m.name_tw,m.name_cn,m.name_en,m.status,m.sort')
            ->order('m.kind asc,m.sort asc,m.term_id asc')->select();
        return is_array($rows) ? $rows : [];
    }

    protected function pendingRows(int $vodId): array
    {
        $rows = Db::name('content_taxonomy_suggestion')->alias('s')
            ->join('__META_TERM__ m', 'm.term_id=s.matched_term_id', 'left')
            ->where(['s.vod_id' => $vodId, 's.decision' => 'pending'])
            ->field('s.*,m.slug AS matched_slug,m.name_tw AS matched_name_tw,m.name_en AS matched_name_en')
            ->order('s.kind asc,s.suggestion_id asc')->select();
        return is_array($rows) ? $rows : [];
    }

    protected function lockRows(int $vodId): array
    {
        $rows = Db::name('vod_field_state')->where('vod_id', $vodId)
            ->where('is_locked', 1)->field('field_name,is_locked')->select();
        return is_array($rows) ? $rows : [];
    }

    protected function workflowRow(int $vodId): array
    {
        $row = Db::name('vod_ext')->where('vod_id', $vodId)
            ->field('public_id,workflow_status,ai_completed_at,duplicate_checked_at,tmdb_completed_at,updated_at')->find();
        return $row ?: [];
    }
}
