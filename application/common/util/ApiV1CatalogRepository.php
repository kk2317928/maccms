<?php
namespace app\common\util;

final class ApiV1CatalogRepository
{
    const PUBLICATION_SQL = "v.vod_status = 1 AND e.workflow_status = 'published' AND e.merged_into_vod_id = 0 AND e.published_at > 0";
    const VIDEO_FIELDS = 'v.vod_name,v.vod_pic,v.vod_year,v.vod_remarks,v.vod_score,v.vod_content,v.vod_actor,v.vod_director,v.vod_area,v.vod_lang,v.vod_serial,v.vod_total,v.vod_isend,v.vod_hits,e.public_id,e.title_tw,e.title_cn,e.title_en,e.original_title,e.poster_s3,e.type2,e.trailer_url,e.preview_url,e.published_at';

    public function listVideos(ApiV1Pagination $pagination, array $filters, $search = null)
    {
        $query = $this->published()->field(self::VIDEO_FIELDS);
        $this->applyFilters($query, $filters, $search);
        $countQuery = clone $query;
        $sort = isset($filters['sort']) ? $filters['sort'] : 'latest';
        $orders = array('latest'=>'e.published_at desc,v.vod_id desc','popular'=>'v.vod_hits desc,v.vod_id desc','rating'=>'v.vod_score desc,v.vod_id desc');
        return array(
            'items' => $query->order($orders[$sort])->limit($pagination->offset(), $pagination->meta(0)['per_page'])->select(),
            'total' => (int) $countQuery->count(),
        );
    }

    public function findVideo($publicId)
    {
        $row = $this->published()->field(self::VIDEO_FIELDS)->where('e.public_id', $publicId)->find();
        return $row ?: null;
    }

    public function findEpisodeData($publicId)
    {
        $row = $this->published()->field('e.public_id,v.vod_play_from,v.vod_play_url,v.vod_play_server,v.vod_play_note')->where('e.public_id', $publicId)->find();
        return $row ?: null;
    }

    public function taxonomies($vodId = null)
    {
        $query = db('meta_term')->alias('t')->field('t.kind,t.slug,t.name_tw,t.name_cn,t.name_en,t.sort')->where('t.status', 1);
        if ($vodId !== null) { $query->join('__VOD_META_TERM__ vm', 'vm.term_id=t.term_id')->where('vm.vod_id', (int) $vodId); }
        return $query->order('t.kind asc,t.sort desc,t.slug asc')->select();
    }

    public function home($limit = 12)
    {
        $pagination = ApiV1Pagination::fromQuery(array('per_page'=>(string) $limit));
        return $this->listVideos($pagination, array('sort'=>'latest'));
    }

    private function published()
    {
        return db('vod')->alias('v')->join('__VOD_EXT__ e', 'e.vod_id=v.vod_id')->where(self::PUBLICATION_SQL);
    }

    private function applyFilters($query, array $filters, $search)
    {
        if (isset($filters['type2'])) { $query->where('e.type2', $filters['type2']); }
        if (isset($filters['year'])) { $query->where('v.vod_year', (string) $filters['year']); }
        if ($search !== null) {
            $escaped = addcslashes($search, '%_\\');
            $query->where('(v.vod_name LIKE :catalog_q OR e.title_tw LIKE :catalog_q OR e.title_cn LIKE :catalog_q OR e.title_en LIKE :catalog_q OR e.original_title LIKE :catalog_q)', array('catalog_q'=>'%'.$escaped.'%'));
        }
        if (isset($filters['taxonomy_kind'])) {
            $query->join('__VOD_META_TERM__ vm', 'vm.vod_id=v.vod_id')->join('__META_TERM__ t', 't.term_id=vm.term_id')
                ->where('t.status', 1)->where('t.kind', $filters['taxonomy_kind'])->where('t.slug', $filters['taxonomy_slug']);
        }
    }
}
