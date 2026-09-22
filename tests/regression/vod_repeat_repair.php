<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
$service=$root.'/application/common/util/VodRepeatRepairService.php';
if(!is_file($service)){fwrite(STDERR,"FAIL: VodRepeatRepairService is missing.\n");exit(1);}
$src=(string)file_get_contents($service);
foreach(['refreshName','inspect','rebuild','vod_repeat','name1'] as $needle){if(strpos($src,$needle)===false){fwrite(STDERR,"FAIL: repeat repair missing {$needle}.\n");exit(1);}}
$vod=(string)file_get_contents($root.'/application/common/model/Vod.php');
if(strpos($vod,'VodRepeatRepairService')===false){fwrite(STDERR,"FAIL: native repeat maintenance does not delegate to repair service.\n");exit(1);}
$command=(string)file_get_contents($root.'/application/command.php');
if(strpos($command,'MaccmsRepairVodRepeat')===false){fwrite(STDERR,"FAIL: repeat repair CLI is not registered.\n");exit(1);}
$migration=(string)file_get_contents($root.'/application/data/migrations/20260921000100_vod_repeat_unique.sql');
if(stripos($migration,'unique')===false||strpos($migration,'name1')===false){fwrite(STDERR,"FAIL: repeat cache uniqueness migration missing.\n");exit(1);}
fwrite(STDOUT,"PASS: native duplicate-name repair contract.\n");
