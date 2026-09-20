<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$merge=(string)file_get_contents($root.'/application/common/util/DuplicateMergeService.php');$restore=(string)file_get_contents($root.'/application/common/util/DuplicateRestoreService.php');
foreach([[$merge,"'version' => 2"],[$merge,'mergeRelatedData'],[$merge,'old_titles_json'],[$merge,'content_lang'],[$merge,'vod_meta_term'],[$merge,'ext_source_map'],[$restore,'restoreRelations'],[$restore,'[1, 2]']] as $r){if(strpos($r[0],$r[1])===false){fwrite(STDERR,"FAIL: missing {$r[1]}\n");exit(1);}}fwrite(STDOUT,"PASS: relational duplicate merge/restore source contract\n");
