<?php
namespace app\common\util;

final class ApiV1CanonicalResource
{
    public static function resolve($publicId, callable $resolver, callable $isPublished)
    {
        $resolved=$resolver($publicId);
        if (!is_array($resolved) || empty($resolved['canonical_public_id'])) return array('status'=>'not_found');
        $canonical=(string)$resolved['canonical_public_id'];
        if (!$isPublished($canonical)) return array('status'=>'not_found');
        return array(
            'status'=>!empty($resolved['is_alias'])?'redirect':'canonical',
            'canonical_public_id'=>$canonical,
        );
    }
}
