<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$pipeline=(string)file_get_contents($root.'/application/common/util/AiNormalizationPipeline.php');
$decision=(string)file_get_contents($root.'/application/common/util/DuplicateCandidateDecisionService.php');
$merge=(string)file_get_contents($root.'/application/common/util/DuplicateMergeService.php');
$controller=(string)file_get_contents($root.'/application/admin/controller/ContentWorkspace.php');
handoffAssert(strpos($pipeline,'workflowCoordinator->completeAi')!==false,'AI completion is not handed to coordinator');
handoffAssert(strpos($decision,'workflowCoordinator->completeDuplicateReview')>strpos($decision,'commitTransaction();'),'different decision advances only after commit');
handoffAssert(strpos($merge,'workflowCoordinator->completeDuplicateReview')>strpos($merge,'commitTransaction();'),'merge advances primary only after commit');
handoffAssert(strpos($controller,'new ContentWorkflowCoordinator())->completeTmdbReview')>strpos($controller,'Db::commit();'),'TMDB decision advances only after controller commit');
handoffAssert(strpos($merge,'completeDuplicateReview($secondaryVodId)')===false,'merged secondary must not receive downstream work');
fwrite(STDOUT,"PASS: workflow stage handoff contract\n");
function handoffAssert(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
