<?php
namespace app\common\util;
use think\Db;
class PublicIdRepairService {
 public function inspect():array{$missing=(int)Db::name('vod')->alias('v')->leftJoin((string)config('database.prefix').'vod_ext e','e.vod_id=v.vod_id')->whereNull('e.vod_id')->count();$invalid=0;foreach((array)Db::name('vod_ext')->field('vod_id,public_id')->select() as $r){if(!preg_match('/^[A-Za-z0-9]{6}$/D',(string)$r['public_id']))$invalid++;}return ['missing'=>$missing,'invalid'=>$invalid,'would_change'=>$missing+$invalid];}
 public function repair(bool $confirmed):array{$report=$this->inspect();if(!$confirmed){$report['applied']=false;return $report;}Db::transaction(function(){foreach((array)Db::name('vod')->alias('v')->leftJoin((string)config('database.prefix').'vod_ext e','e.vod_id=v.vod_id')->whereNull('e.vod_id')->field('v.vod_id')->select() as $r){\app\common\model\VodExt::ensureForVod((int)$r['vod_id']);}foreach((array)Db::name('vod_ext')->field('vod_id,public_id')->select() as $r){if(preg_match('/^[A-Za-z0-9]{6}$/D',(string)$r['public_id']))continue;$id=PublicIdGenerator::generate(fn($x)=>(int)Db::name('vod_ext')->where('public_id',$x)->count()>0);Db::name('vod_ext')->where('vod_id',(int)$r['vod_id'])->update(['public_id'=>$id,'updated_at'=>time()]);}});$after=$this->inspect();$after['applied']=true;return $after;}
}
