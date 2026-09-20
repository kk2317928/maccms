<?php
namespace app\common\util;
use InvalidArgumentException;use think\Db;use Throwable;
final class ContentRepairService
{
 private $load;
 private $repair;
 private $stage;
 private $enqueue;
 private $transaction;
 public function __construct(callable $load=null,callable $repair=null,callable $stage=null,callable $enqueue=null,callable $transaction=null){
  $this->load=$load?:static function($limit){return Db::name('vod')->alias('v')->join(config('database.prefix').'vod_ext e','e.vod_id=v.vod_id','LEFT')->field('v.*,IF(e.vod_id IS NULL,0,1) has_extension,e.workflow_status,e.merged_into_vod_id,(SELECT COUNT(*) FROM '.config('database.prefix').'vod_field_state f WHERE f.vod_id=v.vod_id AND f.is_locked=1) manual_locks,(SELECT COUNT(*) FROM '.config('database.prefix')."content_job j WHERE j.idempotency_key LIKE CONCAT('video:',v.vod_id,':ai:%')) has_ai_job")->where('v.vod_recycle_time',0)->order('v.vod_id asc')->limit((int)$limit)->select()?:[];};
  $this->repair=$repair?:static function($id,$state){VodExtensionService::ensure((int)$id);if($state==='imported')Db::name('vod_ext')->where('vod_id',(int)$id)->where('workflow_status','in',['','failed'])->update(['workflow_status'=>'imported','updated_at'=>time()]);};
  $this->stage=$stage?:static function($id,$taxonomy){return (new TaxonomySuggestionService())->stage((int)$id,'import','repair:vod:'.(int)$id,$taxonomy);};
  $this->enqueue=$enqueue?:static function($id){$fields=Db::name('vod')->where('vod_id',(int)$id)->field(implode(',',VodExtensionService::AI_INPUT_FIELDS))->find()?:[];return (new ContentWorkflowCoordinator())->beginAi((int)$id,VodExtensionService::contentFingerprint($fields));};
  $this->transaction=$transaction?:static function($callback){Db::startTrans();try{$r=$callback();Db::commit();return $r;}catch(Throwable $e){Db::rollback();throw $e;}};
 }
 public function run($apply,$confirmed,$limit=100){$limit=(int)$limit;if($limit<1||$limit>500)throw new InvalidArgumentException('Repair batch limit must be between 1 and 500.');if($apply&&!$confirmed)throw new InvalidArgumentException('Apply requires explicit confirmation.');$rows=(array)call_user_func($this->load,$limit);$result=['scanned'=>count($rows),'extensions'=>0,'taxonomy'=>0,'ai_jobs'=>0,'repaired'=>0,'skipped'=>0];foreach($rows as $row){$safe=empty($row['merged_into_vod_id'])&&(int)($row['manual_locks']??0)===0&&!in_array((string)($row['workflow_status']??''),['published','merged','manual_review'],true);if(!$safe){$result['skipped']++;continue;}$needsExt=empty($row['has_extension']);$needsAi=empty($row['has_ai_job']);if($needsExt)$result['extensions']++;if($needsAi)$result['ai_jobs']++;$taxonomy=$this->taxonomy($row);if($taxonomy)$result['taxonomy']++;if(!$apply)continue;call_user_func($this->transaction,function()use($row,$needsExt,$needsAi,$taxonomy){$id=(int)$row['vod_id'];if($needsExt||(string)($row['workflow_status']??'')===''||(string)($row['workflow_status']??'')==='failed')call_user_func($this->repair,$id,'imported');if($taxonomy)call_user_func($this->stage,$id,$taxonomy);if($needsAi)call_user_func($this->enqueue,$id);});$result['repaired']++;}return $result;}
 private function taxonomy($row){$map=['vod_area'=>'regions','vod_class'=>'genres','vod_tag'=>'tags'];$out=[];foreach($map as $field=>$key){$values=array_values(array_filter(array_map('trim',preg_split('/[,，]/u',(string)($row[$field]??''))),'strlen'));if($values)$out[$key]=$values;}return $out;}
}
