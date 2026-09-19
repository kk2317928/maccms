<?php
declare(strict_types=1);

function localeFail($message) { fwrite(STDERR, "FAIL: ".$message.PHP_EOL); exit(1); }
function localeSame($expected,$actual,$message) { if ($expected !== $actual) localeFail($message."\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)); }
function localeTrue($value,$message) { if (!$value) localeFail($message); }

$root=dirname(__DIR__,2);
$required=array(
 'application/common/util/ApiV1Locale.php',
 'application/common/util/ApiV1CanonicalResource.php',
);
foreach($required as $relative) localeTrue(is_file($root.'/'.$relative),'Missing T-072 file: '.$relative);

require_once $root.'/application/common/util/ApiV1Dto.php';
require_once $root.'/application/common/util/ApiV1Locale.php';
require_once $root.'/application/common/util/ApiV1CanonicalResource.php';
require_once $root.'/application/common/util/ApiV1VideoDto.php';

use app\common\util\ApiV1CanonicalResource;
use app\common\util\ApiV1Locale;
use app\common\util\ApiV1VideoDto;

localeSame('zh-TW',ApiV1Locale::resolve(array(),null)->code(),'Default locale mismatch.');
localeSame('zh-CN',ApiV1Locale::resolve(array('locale'=>'zh_cn'),'en')->code(),'Query locale must win and normalize.');
localeSame('en',ApiV1Locale::resolve(array(),'fr-FR;q=1, en-US;q=0.8, zh-TW;q=0.7')->code(),'Accept-Language selection mismatch.');
localeSame('zh-TW',ApiV1Locale::resolve(array(),'fr-FR')->code(),'Unsupported header must fall back to default.');
foreach(array('', 'fr', array('en')) as $invalid) {
 try { ApiV1Locale::resolve(array('locale'=>$invalid),null); localeFail('Expected invalid explicit locale.'); }
 catch(InvalidArgumentException $exception) { localeSame('locale',$exception->getMessage(),'Locale validation must expose safe field only.'); }
}

$titles=array('tw'=>'繁體','cn'=>'简体','en'=>'English','original'=>'Original','native'=>'Native');
localeSame('繁體',ApiV1Locale::fromCode('zh-TW')->selectTitle($titles),'zh-TW title mismatch.');
localeSame('简体',ApiV1Locale::fromCode('zh-CN')->selectTitle($titles),'zh-CN title mismatch.');
localeSame('English',ApiV1Locale::fromCode('en')->selectTitle($titles),'English title mismatch.');
$titles['en']='';
localeSame('Original',ApiV1Locale::fromCode('en')->selectTitle($titles),'English fallback mismatch.');

$row=array('public_id'=>'ABC234','vod_name'=>'Native','title_tw'=>'繁體','title_cn'=>'简体','title_en'=>'English','original_title'=>'Original');
localeSame('English',ApiV1VideoDto::summary($row,ApiV1Locale::fromCode('en'))->toArray()['title'],'DTO must use resolved locale.');
$term=ApiV1VideoDto::taxonomy(array('kind'=>'genre','slug'=>'action','name_tw'=>'動作','name_cn'=>'动作','name_en'=>''),ApiV1Locale::fromCode('en'));
localeSame('動作',$term['name'],'Taxonomy fallback mismatch.');
localeSame(array('tw'=>'動作','cn'=>'动作','en'=>''),$term['names'],'Taxonomy multilingual map must remain stable.');

$canonical=function($id) {
 if ($id==='ALIAS2') return array('requested_public_id'=>'ALIAS2','canonical_public_id'=>'REAL23','is_alias'=>true);
 if ($id==='REAL23') return array('requested_public_id'=>'REAL23','canonical_public_id'=>'REAL23','is_alias'=>false);
 return null;
};
$available=function($id) { return $id==='REAL23'; };
localeSame(array('status'=>'redirect','canonical_public_id'=>'REAL23'),ApiV1CanonicalResource::resolve('ALIAS2',$canonical,$available),'Published alias must redirect.');
localeSame(array('status'=>'canonical','canonical_public_id'=>'REAL23'),ApiV1CanonicalResource::resolve('REAL23',$canonical,$available),'Canonical resource mismatch.');
localeSame(array('status'=>'not_found'),ApiV1CanonicalResource::resolve('MISS23',$canonical,$available),'Missing ID must fail closed.');
localeSame(array('status'=>'not_found'),ApiV1CanonicalResource::resolve('ALIAS2',$canonical,function(){return false;}),'Unavailable canonical target must fail closed.');

$controller=file_get_contents($root.'/application/api/controller/v1/Catalog.php');
$baseController=file_get_contents($root.'/application/api/controller/v1/Base.php');
localeTrue(strpos($controller,'canonicalRedirectResponse')!==false && strpos($baseController,'308')!==false && strpos($baseController,'Location')!==false,'Catalog controller must emit explicit canonical 308 redirects.');
localeTrue(strpos($controller,"'canonical_public_id'")!==false,'Redirect payload must use public ID only.');
$repository=file_get_contents($root.'/application/common/util/ApiV1CatalogRepository.php');
localeTrue(strpos($repository,'self::VIDEO_FIELDS')===false,'Detail query must not reference the removed VIDEO_FIELDS constant.');
localeTrue(strpos($repository,'self::DETAIL_FIELDS')!==false,'Detail query must use DETAIL_FIELDS.');

fwrite(STDOUT,"API v1 locale and canonical contract passed.".PHP_EOL);
