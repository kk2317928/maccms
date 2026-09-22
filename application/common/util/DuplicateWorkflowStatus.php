<?php

namespace app\common\util;

use InvalidArgumentException;
use think\Db;

class DuplicateWorkflowStatus
{
    public function forVideo(int $vodId): array
    {
        if($vodId<=0){throw new InvalidArgumentException('Video ID must be positive.');}
        $ext=Db::name('vod_ext')->where('vod_id',$vodId)->field('workflow_status,merged_into_vod_id,duplicate_checked_at')->find()?:[];
        $candidate=Db::name('content_duplicate_candidate')->where(function($q)use($vodId){$q->where('vod_id_low',$vodId)->whereOr('vod_id_high',$vodId);})->order('duplicate_candidate_id desc')->find();
        $snapshot=Db::name('content_merge_snapshot')->where(function($q)use($vodId){$q->where('primary_vod_id',$vodId)->whereOr('secondary_vod_id',$vodId);})->order('merge_snapshot_id desc')->find();
        $job=$this->latestAiJob($vodId);
        $state='not_run';
        if((int)($ext['merged_into_vod_id']??0)>0||($candidate['decision']??'')==='merged'){$state='merged';}
        elseif(($snapshot['status']??'')==='restored'){$state='restored';}
        elseif(($ext['workflow_status']??'')==='failed'||($job['status']??'')==='failed'){$state='failed';}
        elseif($candidate&&($candidate['decision']??'')==='pending'){$state='pending';}
        elseif(($job['status']??'')==='running'){$state='running';}
        elseif(($job['status']??'')==='queued'){$state='queued';}
        elseif((int)($ext['duplicate_checked_at']??0)>0){$state='no_candidates';}
        return ['state'=>$state,'candidate'=>$candidate?:[],'snapshot'=>$snapshot?:[],'latest_job'=>$job,'duplicate_checked_at'=>(int)($ext['duplicate_checked_at']??0)];
    }
    protected function latestAiJob(int $vodId): array
    {
        $rows=Db::name('content_job')->where('job_type','in',['ai_normalize','ai.normalize'])->order('job_id desc')->limit(100)->select();
        foreach((array)$rows as $row){$p=json_decode((string)($row['payload_json']??''),true);if(is_array($p)&&(int)($p['vod_id']??0)===$vodId){unset($row['payload_json'],$row['error_summary'],$row['idempotency_key'],$row['lock_owner']);return $row;}}
        return [];
    }
}
