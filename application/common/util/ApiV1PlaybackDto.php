<?php
namespace app\common\util;

final class ApiV1PlaybackDto implements ApiV1Dto
{
    private $data;
    private function __construct(array $data){$this->data=$data;}
    public function toArray(){return $this->data;}
    public static function make(array $row)
    {
        return new self(array(
            'public_id'=>(string)($row['public_id']??''),
            'source_id'=>(string)($row['source_id']??''),
            'episode_id'=>(string)($row['episode_id']??''),
            'url'=>(string)($row['url']??''),
            'expires_at'=>(int)($row['expires_at']??0),
        ));
    }
}
