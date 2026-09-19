<?php
namespace app\common\util;

final class ApiV1CatalogService
{
    private $repository;
    public function __construct(ApiV1CatalogRepository $repository = null) { $this->repository = $repository ?: new ApiV1CatalogRepository(); }

    public function home()
    {
        $result = $this->repository->home(12);
        return array('latest'=>$this->summaries($result['items']));
    }

    public function videos(ApiV1Pagination $pagination, ApiV1CatalogQuery $query)
    {
        $result = $this->repository->listVideos($pagination, $query->toArray());
        return array('items'=>$this->summaries($result['items']), 'total'=>$result['total']);
    }

    public function search(ApiV1Pagination $pagination, ApiV1CatalogQuery $query, $term)
    {
        $result = $this->repository->listVideos($pagination, $query->toArray(), $term);
        return array('items'=>$this->summaries($result['items']), 'total'=>$result['total']);
    }

    public function detail($publicId)
    {
        $row = $this->repository->findVideo($publicId);
        if ($row === null) { return null; }
        return ApiV1VideoDto::detail($row, array_map(array(ApiV1VideoDto::class, 'taxonomy'), $this->repository->taxonomiesByPublicId($publicId)));
    }

    public function episodes($publicId)
    {
        $row = $this->repository->findEpisodeData($publicId);
        if ($row === null) { return null; }
        $decoded = VodPlaybackCodec::decode((string) $row['vod_play_from'], (string) $row['vod_play_url'], (string) $row['vod_play_server'], (string) $row['vod_play_note']);
        return ApiV1EpisodeDto::collection($publicId, $decoded);
    }

    public function taxonomies()
    {
        return array_map(array(ApiV1VideoDto::class, 'taxonomy'), $this->repository->taxonomies());
    }

    private function summaries(array $rows)
    {
        $items = array();
        foreach ($rows as $row) { $items[] = ApiV1VideoDto::summary($row); }
        return $items;
    }
}
