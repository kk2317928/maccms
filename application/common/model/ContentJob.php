<?php

namespace app\common\model;

class ContentJob extends Base
{
    protected $name = 'content_job';
    protected $primaryId = 'job_id';
    protected $pk = 'job_id';
    protected $autoWriteTimestamp = false;
}
