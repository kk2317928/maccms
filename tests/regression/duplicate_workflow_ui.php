<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);
$path=$root.'/application/common/util/DuplicateWorkflowStatus.php';
if(!is_file($path)){fwrite(STDERR,"FAIL: DuplicateWorkflowStatus is missing.\n");exit(1);}
require_once $path;

$source=(string)file_get_contents($path);
foreach(['not_run','queued','running','no_candidates','pending','merged','restored','failed','forVideo'] as $needle){
 if(strpos($source,$needle)===false){fwrite(STDERR,"FAIL: duplicate workflow status missing {$needle}.\n");exit(1);}
}
$template=(string)file_get_contents($root.'/application/admin/view_new/content_workspace/merge_restore.html');
foreach(['重複狀態','no_candidates','restored','failed','conflict'] as $needle){
 if(strpos($template,$needle)===false){fwrite(STDERR,"FAIL: duplicate UI missing {$needle}.\n");exit(1);}
}
$vod=(string)file_get_contents($root.'/application/admin/view_new/vod/info.html');
if(strpos($vod,'重複內容狀態')===false){fwrite(STDERR,"FAIL: video editor lacks duplicate status.\n");exit(1);}

fwrite(STDOUT,"PASS: duplicate workflow observability contract.\n");
