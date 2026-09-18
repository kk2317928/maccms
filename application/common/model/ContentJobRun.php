<?php

namespace app\common\model;

class ContentJobRun extends Base
{
    protected $name = 'content_job_run';
    protected $primaryId = 'run_id';
    protected $pk = 'run_id';
    protected $autoWriteTimestamp = false;
}
