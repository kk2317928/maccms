<?php

namespace app\common\util;

use InvalidArgumentException;
use RuntimeException;
use think\Db;

class TmdbPosterJobHandler
{
    private $ingestion;
    private $fields;

    public function __construct(ExternalImageIngestionService $ingestion = null, FieldGovernance $fields = null)
    {
        $this->ingestion = $ingestion ?: new ExternalImageIngestionService();
        $this->fields = $fields ?: new FieldGovernance();
    }

    public function handle(array $payload): array
    {
        $vodId=(int)($payload['vod_id']??0); $url=trim((string)($payload['poster_url']??'')); $ref=trim((string)($payload['source_ref']??''));
        if($vodId<=0||$url===''||$ref===''){ throw new InvalidArgumentException('TMDB poster job payload is invalid.'); }
        $before=Db::name('vod')->where('vod_id',$vodId)->field('vod_pic')->find();
        $ext=Db::name('vod_ext')->where('vod_id',$vodId)->field('old_poster_s3,poster_s3')->find();
        if(!$before||!$ext){ throw new RuntimeException('TMDB poster target was not found.'); }
        $image=$this->ingestion->ingest($url,$ref);
        $stored=(string)$image['stored_url'];
        Db::transaction(function() use($vodId,$stored,$ref,$before,$ext){
            if(!$this->fields->apply($vodId,'vod_pic',$stored,'tmdb',$ref,true,false)){ throw new RuntimeException('Poster field is locked.'); }
            Db::name('vod_ext')->where('vod_id',$vodId)->update([
                'old_poster_s3'=>(string)($ext['poster_s3']?:$before['vod_pic']),
                'poster_s3'=>$stored,'updated_at'=>time(),
            ]);
        });
        return $image;
    }
}
