<?php
declare(strict_types=1);
$db=(string)getenv('MACCMS_TEST_DATABASE');if(!preg_match('/^maccms_ci_[a-z0-9_]+$/',$db)){fwrite(STDERR,"FAIL: disposable database required\n");exit(1);}
$root=dirname(__DIR__,2);define('APP_PATH',$root.'/application/');define('ENTRANCE','command');$_SERVER['HTTP_HOST']='127.0.0.1';$_SERVER['SCRIPT_NAME']='/public-id-case';require $root.'/thinkphp/base.php';\think\App::initCommon();
function pidAssert($x,$m){if(!$x){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$base=['type_id'=>0,'vod_content'=>'','vod_down_url'=>'','vod_plot_name'=>'','vod_plot_detail'=>''];$a=(int)\think\Db::name('vod')->insertGetId($base+['vod_name'=>'Case A']);$b=(int)\think\Db::name('vod')->insertGetId($base+['vod_name'=>'Case B']);
\think\Db::name('vod_ext')->insert(['vod_id'=>$a,'public_id'=>'Ab12Cd','old_titles_json'=>'[]','workflow_status'=>'new','created_at'=>time(),'updated_at'=>time()]);
\think\Db::name('vod_ext')->insert(['vod_id'=>$b,'public_id'=>'ab12cd','old_titles_json'=>'[]','workflow_status'=>'new','created_at'=>time(),'updated_at'=>time()]);
pidAssert((int)\think\Db::name('vod_ext')->where('public_id','Ab12Cd')->value('vod_id')===$a,'exact-case lookup A failed');
pidAssert((int)\think\Db::name('vod_ext')->where('public_id','ab12cd')->value('vod_id')===$b,'exact-case lookup B failed');
fwrite(STDOUT,"PASS: case-sensitive public IDs coexist on {$db}.\n");
