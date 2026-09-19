<?php
namespace app\common\util;

final class ApiV1EpisodeDto
{
    public static function collection($publicId, array $decoded)
    {
        $sources = array();
        foreach (array_values($decoded) as $sourceIndex => $source) {
            $sourcePosition = $sourceIndex + 1;
            $episodes = array();
            foreach (array_values(isset($source['episodes']) && is_array($source['episodes']) ? $source['episodes'] : array()) as $episodeIndex => $episode) {
                $episodePosition = $episodeIndex + 1;
                $episodes[] = array(
                    'episode_id' => 's'.$sourcePosition.'e'.$episodePosition,
                    'name' => isset($episode['name']) && $episode['name'] !== '' ? (string) $episode['name'] : (string) $episodePosition,
                    'position' => $episodePosition,
                );
            }
            $sources[] = array(
                'source_id' => 's'.$sourcePosition,
                'name' => isset($source['source']) ? (string) $source['source'] : '',
                'position' => $sourcePosition,
                'episodes' => $episodes,
            );
        }
        return array('public_id'=>(string) $publicId, 'sources'=>$sources);
    }
}
