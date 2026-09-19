<?php
namespace app\common\util;

final class ApiV1ActivityDto implements ApiV1Dto
{
    private $data;
    private function __construct(array $data) { $this->data = $data; }
    public function toArray() { return $this->data; }

    public static function favorite(array $row)
    {
        return new self(array(
            'public_id'=>(string)self::value($row,'public_id',''),
            'title'=>(string)self::value($row,'title',''),
            'poster'=>(string)self::value($row,'poster',''),
            'favorited_at'=>(int)self::value($row,'favorited_at',0),
        ));
    }

    public static function history(array $row)
    {
        return new self(array(
            'public_id'=>(string)self::value($row,'public_id',''),
            'title'=>(string)self::value($row,'title',''),
            'poster'=>(string)self::value($row,'poster',''),
            'source_id'=>(string)self::value($row,'source_id',''),
            'episode_id'=>(string)self::value($row,'episode_id',''),
            'position_seconds'=>(int)self::value($row,'position_seconds',0),
            'duration_seconds'=>(int)self::value($row,'duration_seconds',0),
            'updated_at'=>(int)self::value($row,'updated_at',0),
        ));
    }

    public static function progress(array $row)
    {
        return new self(array(
            'public_id'=>(string)self::value($row,'public_id',''),
            'source_id'=>(string)self::value($row,'source_id',''),
            'episode_id'=>(string)self::value($row,'episode_id',''),
            'position_seconds'=>(int)self::value($row,'position_seconds',0),
            'duration_seconds'=>(int)self::value($row,'duration_seconds',0),
            'updated_at'=>(int)self::value($row,'updated_at',0),
        ));
    }

    private static function value(array $row, $key, $default)
    {
        return array_key_exists($key,$row) && $row[$key] !== null ? $row[$key] : $default;
    }
}
