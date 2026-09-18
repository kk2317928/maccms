<?php
namespace app\common\model;

class VodMetaTerm extends Base
{
    protected $name = 'vod_meta_term';
    protected $primaryId = 'vod_id';
    protected $pk = 'vod_id';
    protected $autoWriteTimestamp = false;
}
