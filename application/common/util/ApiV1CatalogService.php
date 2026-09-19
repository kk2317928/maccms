<?php
namespace app\common\util;

use app\common\model\VodExt;

final class ApiV1CatalogService
{
    private $repository;
    public function __construct(ApiV1CatalogRepository $repository = null) { $this->repository = $repository ?: new ApiV1CatalogRepository(); }

    public function canonicalResource($publicId)
    {
        $repository=$this->repository;
        return ApiV1CanonicalResource::resolve(
            $publicId,
            function($candidate) { return VodExt::resolvePublicId((string)$candidate); },
            function($candidate) use ($repository) { return $repository->isPublished($candidate); }
        );
    }

    public function home(ApiV1Locale $locale)
    {
        $result=$this->repository->home(12);
        return array('latest'=>$this->summaryArrays($result['items'],$locale));
    }

    public function videos(ApiV1Pagination $pagination, ApiV1CatalogQuery $query, ApiV1Locale $locale)
    {
        $result=$this->repository->listVideos($pagination,$query->toArray());
        return array('items'=>$this->summaries($result['items'],$locale),'total'=>$result['total']);
    }

    public function search(ApiV1Pagination $pagination, ApiV1CatalogQuery $query, $term, ApiV1Locale $locale)
    {
        $result=$this->repository->listVideos($pagination,$query->toArray(),$term);
        return array('items'=>$this->summaries($result['items'],$locale),'total'=>$result['total']);
    }

    public function detail($publicId, ApiV1Locale $locale)
    {
        $row=$this->repository->findVideo($publicId);
        if ($row===null) return null;
        return ApiV1VideoDto::detail($row,$this->taxonomyDtos($this->repository->taxonomiesByPublicId($publicId),$locale),$locale);
    }

    public function episodes($publicId)
    {
        $row=$this->repository->findEpisodeData($publicId);
        if ($row===null) return null;
        $decoded=VodPlaybackCodec::decode((string)$row['vod_play_from'],(string)$row['vod_play_url'],(string)$row['vod_play_server'],(string)$row['vod_play_note']);
        return ApiV1EpisodeDto::collection($publicId,$decoded);
    }

    public function taxonomies(ApiV1Locale $locale)
    {
        return $this->taxonomyDtos($this->repository->taxonomies(),$locale);
    }

    private function taxonomyDtos(array $rows,ApiV1Locale $locale)
    {
        $items=array();
        foreach($rows as $row) $items[]=ApiV1VideoDto::taxonomy($row,$locale);
        return $items;
    }

    private function summaryArrays(array $rows,ApiV1Locale $locale)
    {
        $items=array();
        foreach($this->summaries($rows,$locale) as $dto) $items[]=$dto->toArray();
        return $items;
    }

    private function summaries(array $rows,ApiV1Locale $locale)
    {
        $items=array();
        foreach($rows as $row) $items[]=ApiV1VideoDto::summary($row,$locale);
        return $items;
    }
}
