<?php
namespace app\common\util;

use InvalidArgumentException;
use think\Db;
use Throwable;

final class ContentRepairService
{
    private $load;
    private $repair;
    private $stage;
    private $enqueue;
    private $transaction;
    private $guard;

    public function __construct(callable $load=null, callable $repair=null, callable $stage=null, callable $enqueue=null, callable $transaction=null, callable $guard=null)
    {
        $this->load=$load?:static function($limit){
            $prefix=config('database.prefix');
            $fields="v.*,IF(e.vod_id IS NULL,0,1) has_extension,e.workflow_status,e.merged_into_vod_id,"
                ."(SELECT COUNT(*) FROM {$prefix}vod_field_state f WHERE f.vod_id=v.vod_id AND f.is_locked=1) manual_locks,"
                ."(SELECT COUNT(*) FROM {$prefix}content_job j WHERE j.idempotency_key LIKE CONCAT('video:',v.vod_id,':ai:%')) has_ai_job,"
                ."(SELECT COUNT(*) FROM {$prefix}content_taxonomy_suggestion s WHERE s.vod_id=v.vod_id AND s.source='import' AND s.source_ref=CONCAT('repair:vod:',v.vod_id)) has_repair_suggestion";
            $eligible="(e.vod_id IS NULL OR NOT EXISTS (SELECT 1 FROM {$prefix}content_job j2 WHERE j2.idempotency_key LIKE CONCAT('video:',v.vod_id,':ai:%'))"
                ." OR ((v.vod_area<>'' OR v.vod_class<>'' OR v.vod_tag<>'') AND NOT EXISTS (SELECT 1 FROM {$prefix}content_taxonomy_suggestion s2 WHERE s2.vod_id=v.vod_id AND s2.source='import' AND s2.source_ref=CONCAT('repair:vod:',v.vod_id))))";
            return Db::name('vod')->alias('v')
                ->join($prefix.'vod_ext e','e.vod_id=v.vod_id','LEFT')
                ->field($fields)->where('v.vod_recycle_time',0)
                ->whereRaw("(e.merged_into_vod_id IS NULL OR e.merged_into_vod_id=0) AND (e.workflow_status IS NULL OR e.workflow_status NOT IN ('published','merged','manual_review'))")
                ->whereRaw("NOT EXISTS (SELECT 1 FROM {$prefix}vod_field_state lf WHERE lf.vod_id=v.vod_id AND lf.is_locked=1)")
                ->whereRaw($eligible)->order('v.vod_id asc')->limit((int)$limit)->select()?:[];
        };
        $this->repair=$repair?:static function($id,$state){
            VodExtensionService::ensure((int)$id);
            if($state==='imported') Db::name('vod_ext')->where('vod_id',(int)$id)->where('workflow_status','in',['','failed'])->update(['workflow_status'=>'imported','updated_at'=>time()]);
        };
        $this->stage=$stage?:static function($id,$taxonomy){return (new TaxonomySuggestionService())->stage((int)$id,'import','repair:vod:'.(int)$id,$taxonomy);};
        $this->enqueue=$enqueue?:static function($id){
            $fields=Db::name('vod')->where('vod_id',(int)$id)->field(implode(',',VodExtensionService::AI_INPUT_FIELDS))->find()?:[];
            return (new ContentWorkflowCoordinator())->beginAi((int)$id,VodExtensionService::contentFingerprint($fields));
        };
        $this->transaction=$transaction?:static function($callback){Db::startTrans();try{$r=$callback();Db::commit();return $r;}catch(Throwable $e){Db::rollback();throw $e;}};
        $this->guard=$guard?:static function($id){
            $prefix=config('database.prefix');
            $row=Db::name('vod')->alias('v')->join($prefix.'vod_ext e','e.vod_id=v.vod_id','LEFT')
                ->field("v.*,IF(e.vod_id IS NULL,0,1) has_extension,e.workflow_status,e.merged_into_vod_id")
                ->where('v.vod_id',(int)$id)->lock(true)->find();
            if(!$row) return null;
            $locks=Db::name('vod_field_state')->where('vod_id',(int)$id)->lock(true)->select()?:[];
            $row['manual_locks']=count(array_filter($locks,static function($lock){return (int)($lock['is_locked']??0)===1;}));
            $row['has_ai_job']=Db::name('content_job')->where('idempotency_key','like','video:'.(int)$id.':ai:%')->lock(true)->count();
            $row['has_repair_suggestion']=Db::name('content_taxonomy_suggestion')->where(['vod_id'=>(int)$id,'source'=>'import','source_ref'=>'repair:vod:'.(int)$id])->lock(true)->count();
            return $row;
        };
    }

    public function run($apply,$confirmed,$limit=100)
    {
        $limit=(int)$limit;
        if($limit<1||$limit>500) throw new InvalidArgumentException('Repair batch limit must be between 1 and 500.');
        if($apply&&!$confirmed) throw new InvalidArgumentException('Apply requires explicit confirmation.');
        $rows=(array)call_user_func($this->load,$limit);
        $result=['scanned'=>count($rows),'extensions'=>0,'taxonomy'=>0,'ai_jobs'=>0,'repaired'=>0,'skipped'=>0];
        foreach($rows as $snapshot){
            if(!$this->safe($snapshot)){ $result['skipped']++; continue; }
            $preview=$this->needs($snapshot);
            if($preview['extension']) $result['extensions']++;
            if($preview['taxonomy']) $result['taxonomy']++;
            if($preview['ai']) $result['ai_jobs']++;
            if(!$apply) continue;
            $changed=call_user_func($this->transaction,function()use($snapshot){
                $row=call_user_func($this->guard,(int)$snapshot['vod_id'],$snapshot);
                if(!$row||!$this->safe($row)) return false;
                $needs=$this->needs($row);
                if(!$needs['any']) return false;
                $id=(int)$row['vod_id'];
                if($needs['extension']||$needs['workflow']) call_user_func($this->repair,$id,'imported');
                if($needs['taxonomy']) call_user_func($this->stage,$id,$needs['taxonomy_payload']);
                if($needs['ai']) call_user_func($this->enqueue,$id);
                return true;
            });
            if($changed) $result['repaired']++; else $result['skipped']++;
        }
        return $result;
    }

    private function safe($row)
    {
        return empty($row['merged_into_vod_id'])
            &&(int)($row['manual_locks']??0)===0
            &&!in_array((string)($row['workflow_status']??''),['published','merged','manual_review'],true);
    }

    private function needs($row)
    {
        $taxonomy=$this->taxonomy($row);
        $needsTaxonomy=!empty($taxonomy)&&empty($row['has_repair_suggestion']);
        $needsWorkflow=in_array((string)($row['workflow_status']??''),['','failed'],true);
        $needsExtension=empty($row['has_extension']);
        $needsAi=empty($row['has_ai_job']);
        return ['extension'=>$needsExtension,'workflow'=>$needsWorkflow,'taxonomy'=>$needsTaxonomy,'taxonomy_payload'=>$taxonomy,'ai'=>$needsAi,'any'=>$needsExtension||$needsWorkflow||$needsTaxonomy||$needsAi];
    }

    private function taxonomy($row)
    {
        $map=['vod_area'=>'regions','vod_class'=>'genres','vod_tag'=>'tags'];$out=[];
        foreach($map as $field=>$key){$values=array_values(array_filter(array_map('trim',preg_split('/[,，]/u',(string)($row[$field]??''))),'strlen'));if($values)$out[$key]=$values;}
        return $out;
    }
}
