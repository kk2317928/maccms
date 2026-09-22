<?php
declare(strict_types=1);
$database=(string)getenv('MACCMS_TEST_DATABASE');if(!preg_match('/^maccms_ci_[a-z0-9_]+$/',$database)){fwrite(STDERR,"FAIL: disposable database required\n");exit(1);}
$root=dirname(__DIR__,2);define('APP_PATH',$root.'/application/');define('ENTRANCE','command');$_SERVER['HTTP_HOST']='127.0.0.1';$_SERVER['SCRIPT_NAME']='/repeat-repair';require $root.'/thinkphp/base.php';\think\App::initCommon();
function rr($ok,$m){if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$prefix=(string)config('database.prefix');\think\Db::execute("CREATE TABLE IF NOT EXISTS `".$prefix."vod_repeat` (id1 int unsigned DEFAULT NULL,name1 varchar(255) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");\think\Db::name('vod_repeat')->delete();
$base=['type_id'=>0,'vod_content'=>'','vod_down_url'=>'','vod_plot_name'=>'','vod_plot_detail'=>''];
$a=(int)\think\Db::name('vod')->insertGetId($base+['vod_name'=>'Repeat CI','vod_recycle_time'=>0]);$b=(int)\think\Db::name('vod')->insertGetId($base+['vod_name'=>'Repeat CI','vod_recycle_time'=>0]);\think\Db::name('vod')->insertGetId($base+['vod_name'=>'Recycled Only','vod_recycle_time'=>time()]);\think\Db::name('vod')->insertGetId($base+['vod_name'=>'Recycled Only','vod_recycle_time'=>time()]);
$s=new \app\common\util\VodRepeatRepairService();$s->refreshName('Repeat CI');$s->refreshName('Repeat CI');rr((int)\think\Db::name('vod_repeat')->where('name1','Repeat CI')->count()===1,'refresh duplicated cache row');
$before=(int)\think\Db::name('vod_repeat')->count();$dry=$s->rebuild(false);rr((int)\think\Db::name('vod_repeat')->count()===$before&&!$dry['applied'],'dry run mutated cache');
$s->rebuild(true);rr((int)\think\Db::name('vod_repeat')->where('name1','Repeat CI')->count()===1,'rebuild missing duplicate');rr((int)\think\Db::name('vod_repeat')->where('name1','Recycled Only')->count()===0,'recycled rows entered duplicate cache');
fwrite(STDOUT,"PASS: vod repeat repair on {$database}.\n");
