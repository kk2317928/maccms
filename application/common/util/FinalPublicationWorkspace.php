<?php

namespace app\common\util;

use InvalidArgumentException;
use RuntimeException;
use think\Db;

class FinalPublicationWorkspace
{
    private $queueReader;
    private $queueCounter;
    private $videoReader;
    private $localeReader;
    private $termReader;

    public function __construct(callable $queueReader = null, callable $queueCounter = null, callable $videoReader = null, callable $localeReader = null, callable $termReader = null)
    {
        $this->queueReader = $queueReader ?: static function (int $offset, int $limit): array {
            return Db::name('vod_ext')->alias('ve')->join('__VOD__ v', 'v.vod_id=ve.vod_id')
                ->field('v.vod_id,v.vod_name,v.vod_status,ve.public_id,ve.workflow_status,ve.updated_at')
                ->where('ve.workflow_status', VodWorkflow::MANUAL_REVIEW)->where('ve.merged_into_vod_id', 0)
                ->order('ve.updated_at asc,v.vod_id asc')->limit($offset, $limit)->select();
        };
        $this->queueCounter = $queueCounter ?: static function (): int {
            return (int) Db::name('vod_ext')->where('workflow_status', VodWorkflow::MANUAL_REVIEW)->where('merged_into_vod_id', 0)->count();
        };
        $this->videoReader = $videoReader ?: static function (int $vodId): ?array {
            $row = Db::name('vod')->alias('v')->join('__VOD_EXT__ ve', 've.vod_id=v.vod_id')
                ->field('v.*,ve.*')->where('v.vod_id', $vodId)->find();
            return $row ?: null;
        };
        $this->localeReader = $localeReader ?: static function (int $vodId): array {
            $rows = Db::name('content_lang')->where(['content_type' => 'vod', 'content_id' => $vodId])->select();
            $result = [];
            foreach ($rows as $row) {
                $data = json_decode((string) ($row['data'] ?? ''), true);
                if (is_array($data)) { $result[(string) $row['lang_code']] = $data; }
            }
            return $result;
        };
        $this->termReader = $termReader ?: static function (int $vodId): array {
            return Db::name('vod_meta_term')->alias('vmt')->join('__META_TERM__ mt', 'mt.term_id=vmt.term_id')
                ->field('mt.term_id,mt.kind,mt.slug,mt.name_tw,mt.name_cn,mt.name_en')
                ->where('vmt.vod_id', $vodId)->where('mt.status', 1)->order('mt.sort asc,mt.term_id asc')->select();
        };
    }

    public function queue(int $page = 1, int $pageSize = 20): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));
        $rows = (array) call_user_func($this->queueReader, ($page - 1) * $pageSize, $pageSize);
        $rows = array_values(array_filter($rows, static function (array $row): bool {
            return ($row['workflow_status'] ?? '') === VodWorkflow::MANUAL_REVIEW && (int) ($row['merged_into_vod_id'] ?? 0) === 0;
        }));
        return ['rows' => $rows, 'total' => (int) call_user_func($this->queueCounter), 'page' => $page, 'page_size' => $pageSize];
    }

    public function preview(int $vodId): array
    {
        if ($vodId <= 0) { throw new InvalidArgumentException('Video ID must be positive.'); }
        $row = call_user_func($this->videoReader, $vodId);
        if (!is_array($row)) { throw new RuntimeException('Publication candidate was not found.'); }
        return $this->previewRow($row);
    }

    public function previewRow(array $row): array
    {
        $vodId = (int) ($row['vod_id'] ?? 0);
        if ($vodId <= 0) { throw new InvalidArgumentException('Publication candidate has no video identity.'); }
        $rawLocales = (array) call_user_func($this->localeReader, $vodId);
        $terms = (array) call_user_func($this->termReader, $vodId);
        $taxonomy = ['region' => [], 'genre' => [], 'tag' => []];
        foreach ($terms as $term) {
            $kind = (string) ($term['kind'] ?? '');
            if (isset($taxonomy[$kind])) { $taxonomy[$kind][] = $term; }
        }
        $locales = [];
        foreach (['zh-TW', 'zh-CN', 'en'] as $locale) {
            $data = isset($rawLocales[$locale]) && is_array($rawLocales[$locale]) ? $rawLocales[$locale] : [];
            $locales[$locale] = [
                'title' => trim((string) ($data['vod_name'] ?? $data['title'] ?? '')),
                'summary' => trim((string) ($data['vod_blurb'] ?? $data['vod_content'] ?? $data['summary'] ?? '')),
            ];
        }
        if ($locales['zh-TW']['title'] === '') { $locales['zh-TW']['title'] = trim((string) ($row['title_tw'] ?? $row['vod_name'] ?? '')); }
        if ($locales['zh-TW']['summary'] === '') { $locales['zh-TW']['summary'] = trim((string) ($row['vod_blurb'] ?? $row['vod_content'] ?? '')); }
        if ($locales['zh-CN']['title'] === '') { $locales['zh-CN']['title'] = trim((string) ($row['title_cn'] ?? '')); }
        if ($locales['en']['title'] === '') { $locales['en']['title'] = trim((string) ($row['title_en'] ?? $row['vod_en'] ?? '')); }

        $blockers = [];
        $warnings = [];
        $publicId = trim((string) ($row['public_id'] ?? ''));
        if (!preg_match('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/', $publicId)) { $blockers[] = '缺少有效的六位公開識別碼。'; }
        if ((int) ($row['merged_into_vod_id'] ?? 0) > 0) { $blockers[] = '已合併內容不可獨立發布。'; }
        if ((string) ($row['workflow_status'] ?? '') !== VodWorkflow::MANUAL_REVIEW) { $blockers[] = '內容不在人工審核狀態。'; }
        if (trim((string) ($row['vod_name'] ?? '')) === '') { $blockers[] = '缺少主要片名。'; }
        if (!$taxonomy['region']) { $blockers[] = '缺少有效地區映射。'; }
        if (!$taxonomy['genre']) { $blockers[] = '缺少有效分類映射。'; }

        $playback = [];
        try {
            $playback = VodPlaybackCodec::decode((string) ($row['vod_play_from'] ?? ''), (string) ($row['vod_play_url'] ?? ''), (string) ($row['vod_play_server'] ?? ''), (string) ($row['vod_play_note'] ?? ''));
            $playable = false;
            foreach ($playback as $source) {
                foreach ($source['episodes'] as $episode) {
                    if (trim((string) $episode['url']) !== '') { $playable = true; break 2; }
                }
            }
            if (!$playable) { $blockers[] = '缺少可播放網址。'; }
        } catch (InvalidArgumentException $exception) {
            $blockers[] = '播放資料包含不安全或無效網址。';
        }

        foreach (['zh-CN', 'en'] as $locale) {
            if ($locales[$locale]['title'] === '' || $locales[$locale]['summary'] === '') { $warnings[] = $locale . ' 語系資料不完整。'; }
        }
        $media = [
            'poster' => trim((string) ($row['poster_s3'] ?? $row['vod_pic'] ?? '')),
            'native_poster' => trim((string) ($row['vod_pic'] ?? '')),
            'backdrop' => trim((string) ($row['vod_pic_slide'] ?? '')),
            'trailer' => trim((string) ($row['trailer_url'] ?? '')),
        ];
        if (trim((string) ($row['poster_s3'] ?? '')) === '') { $warnings[] = '尚未提供 S3 海報。'; }
        if ($media['poster'] === '') { $warnings[] = '尚未提供海報。'; }
        if ($media['backdrop'] === '') { $warnings[] = '尚未提供背景圖片。'; }
        if ($media['trailer'] === '') { $warnings[] = '尚未提供預告片。'; }
        if (trim((string) ($row['vod_blurb'] ?? '')) === '') { $warnings[] = '尚未提供短摘要。'; }

        return [
            'video' => $row,
            'ext' => ['public_id' => $publicId, 'workflow_status' => (string) ($row['workflow_status'] ?? ''), 'merged_into_vod_id' => (int) ($row['merged_into_vod_id'] ?? 0), 'published_at' => (int) ($row['published_at'] ?? 0)],
            'locales' => $locales, 'taxonomy' => $taxonomy, 'media' => $media, 'playback' => $playback,
            'blockers' => $blockers, 'warnings' => $warnings, 'publishable' => $blockers === [],
        ];
    }
}
