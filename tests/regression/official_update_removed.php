<?php
declare(strict_types=1);
function removed_ok($c,string $m):void{if(!$c){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$root=dirname(__DIR__,2);
removed_ok(!is_file($root.'/application/admin/controller/Update.php'),'official Update controller must be removed');
removed_ok(!is_file($root.'/static_new/js/update.js'),'official update payload must be removed');
foreach([
 'static_new/js/admin_common.js',
 'application/admin/view_new/index/index.html',
 'application/admin/controller/Base.php',
 'application/extra/version.php',
] as $file){
 $s=file_get_contents($root.'/'.$file);
 removed_ok($s!==false,"{$file} readable");
 foreach(['update.maccms.la','admin/update/step1','update_hash','showUpdateDialog','update-notification'] as $needle){
  removed_ok(stripos($s,$needle)===false,"{$file} must not contain {$needle}");
 }
}
fwrite(STDOUT,"Official update communication and routes removed.\n");
