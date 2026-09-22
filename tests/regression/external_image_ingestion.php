<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$servicePath = $root . '/application/common/util/ExternalImageIngestionService.php';
$handlerPath = $root . '/application/common/util/TmdbPosterJobHandler.php';
if (!is_file($servicePath)) { fwrite(STDERR, "FAIL: ExternalImageIngestionService is missing.\n"); exit(1); }
if (!is_file($handlerPath)) { fwrite(STDERR, "FAIL: TmdbPosterJobHandler is missing.\n"); exit(1); }

$service = (string) file_get_contents($servicePath);
$handler = (string) file_get_contents($handlerPath);
$s3 = (string) file_get_contents($root . '/application/common/extend/upload/S3.php');
$jobs = (string) file_get_contents($root . '/application/command/MaccmsJobs.php');

foreach (['HardenedHttpClient', 'getimagesizefromstring', 'sha256', 'random_bytes', 'max_redirects', 'stored_url', 'local_path', 'mime'] as $needle) {
    if (strpos($service, $needle) === false) { fwrite(STDERR, "FAIL: image ingestion missing {$needle}.\n"); exit(1); }
}
foreach (['vod_pic', 'old_poster_s3', 'poster_s3', 'FieldGovernance', 'tmdb_poster_ingest'] as $needle) {
    if (strpos($handler, $needle) === false && strpos($jobs, $needle) === false) { fwrite(STDERR, "FAIL: poster workflow missing {$needle}.\n"); exit(1); }
}
if (strpos($s3, 'return $file_path;') !== false && strpos($s3, 'RuntimeException') === false) {
    fwrite(STDERR, "FAIL: S3 driver still silently treats local path as upload success.\n"); exit(1);
}


require_once $root . '/application/common/util/ExternalHttpPolicy.php';
require_once $root . '/application/common/util/HardenedHttpClient.php';
require_once $servicePath;

$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
$tmp=sys_get_temp_dir().'/maccms-poster-'.bin2hex(random_bytes(4)); @mkdir($tmp,0755,true);
$http=new class($png) extends \app\common\util\HardenedHttpClient {
    private $bytes; public function __construct($bytes){$this->bytes=$bytes;}
    public function get(string $url,array $options=[]):array{return ['status'=>200,'headers'=>['content-type'=>'image/png'],'body'=>$this->bytes];}
};
$uploaded=[];
$svc=new \app\common\util\ExternalImageIngestionService($http,function(string $path)use(&$uploaded):string{$uploaded[]=$path;return 'https://cdn.example.test/'.$path;},$tmp);
$result=$svc->ingest('https://image.tmdb.org/t/p/original/a.png','tmdb:movie:1');
if($result['mime']!=='image/png'||strlen($result['sha256'])!==64||count($uploaded)!==1){fwrite(STDERR,"FAIL: valid PNG ingestion failed.\n");exit(1);}
if(preg_match('/[a-f0-9]{32}\\.png$/',$uploaded[0])!==1){fwrite(STDERR,"FAIL: staged image name is not random.\n");exit(1);}
$bad=new class extends \app\common\util\HardenedHttpClient {public function __construct(){} public function get(string $url,array $options=[]):array{return ['status'=>200,'headers'=>[],'body'=>'not-an-image'];}};
try{(new \app\common\util\ExternalImageIngestionService($bad,fn($p)=>'https://cdn/'.$p,$tmp))->ingest('https://image.tmdb.org/x.jpg','tmdb:x');fwrite(STDERR,"FAIL: corrupt image accepted.\n");exit(1);}catch(\InvalidArgumentException $e){}
$fail=new \app\common\util\ExternalImageIngestionService($http,function(string $p):string{throw new \RuntimeException('secret signed url');},$tmp);
try{$fail->ingest('https://image.tmdb.org/x.png','tmdb:x');fwrite(STDERR,"FAIL: upload failure accepted.\n");exit(1);}catch(\RuntimeException $e){if(strpos($e->getMessage(),'secret signed url')!==false){fwrite(STDERR,"FAIL: upload secret leaked.\n");exit(1);}}
$httpSource=(string)file_get_contents($root.'/application/common/util/HardenedHttpClient.php');
if(strpos($httpSource,'for ($hop = 0;')===false||strpos($httpSource,'$this->policy->validate($url, $allowedHosts)')===false){fwrite(STDERR,"FAIL: redirect target revalidation missing.\n");exit(1);}
fwrite(STDOUT, "PASS: hardened TMDB poster ingestion source and behavior contract.\n");
