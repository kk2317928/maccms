<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require_once $root.'/application/common/util/PublicIdGenerator.php';
use app\common\util\PublicIdGenerator;
$src=(string)file_get_contents($root.'/application/common/util/PublicIdGenerator.php');
if(strpos($src,"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789")===false){fwrite(STDERR,"FAIL: exact mixed-case alphabet missing.\n");exit(1);}
for($i=0;$i<200;$i++){ $id=PublicIdGenerator::generate(fn($v)=>false); if(!preg_match('/^[A-Za-z0-9]{6}$/D',$id)){fwrite(STDERR,"FAIL: generated invalid public ID {$id}.\n");exit(1);} }
$validators=['application/common/model/VodExt.php','application/common/util/DuplicateReviewWorkspace.php'];
foreach($validators as $file){$s=(string)file_get_contents($root.'/'.$file);if(strpos($s,'[A-Za-z0-9]{6}')===false){fwrite(STDERR,"FAIL: {$file} lacks exact mixed-case validation.\n");exit(1);}if($file==='application/common/model/VodExt.php' && preg_match('/strtoupper\\s*\\(\\s*trim\\s*\\(\\s*\\$publicId/', $s)){fwrite(STDERR,"FAIL: VodExt still uppercases requested public IDs.\n");exit(1);}}
fwrite(STDOUT,"PASS: mixed-case public ID generator and validators.\n");
