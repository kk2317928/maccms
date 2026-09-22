<?php

namespace app\common\util;

use InvalidArgumentException;
use RuntimeException;

class ExternalImageIngestionService
{
    private $http;
    private $uploader;
    private $root;

    public function __construct(HardenedHttpClient $http = null, callable $uploader = null, string $root = '')
    {
        $this->http = $http ?: new HardenedHttpClient(new ExternalHttpPolicy());
        $this->uploader = $uploader;
        $this->root = $root !== '' ? rtrim($root, '/\\') : rtrim(ROOT_PATH, '/\\');
    }

    public function ingest(string $url, string $sourceRef): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) { throw new InvalidArgumentException('Image URL must use HTTP(S).'); }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') { throw new InvalidArgumentException('Image host is invalid.'); }
        $response = $this->http->get($url, ['allowed_hosts'=>[$host], 'max_bytes'=>8388608, 'max_redirects'=>3, 'timeout'=>20]);
        if ((int) ($response['status'] ?? 0) < 200 || (int) ($response['status'] ?? 0) >= 300) { throw new RuntimeException('Image download failed.'); }
        $bytes = (string) ($response['body'] ?? '');
        $info = @getimagesizefromstring($bytes);
        if (!$info || empty($info['mime']) || !in_array(strtolower((string) $info['mime']), ['image/jpeg','image/png','image/webp'], true)) {
            throw new InvalidArgumentException('Downloaded content is not an allowed image.');
        }
        $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][strtolower((string) $info['mime'])];
        $relative = 'upload/tmdb/' . date('Y/m/d') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $absolute = $this->root . '/' . $relative;
        if (!is_dir(dirname($absolute)) && !@mkdir(dirname($absolute), 0755, true) && !is_dir(dirname($absolute))) { throw new RuntimeException('Image staging directory could not be created.'); }
        if (@file_put_contents($absolute, $bytes, LOCK_EX) !== strlen($bytes)) { throw new RuntimeException('Image staging write failed.'); }
        try {
            $stored = $this->upload($relative);
            if ($stored === '' || $stored === $relative || $stored === '/' . $relative) { throw new RuntimeException('Remote image upload did not return a stored URL.'); }
            return ['stored_url'=>$stored, 'local_path'=>$relative, 'mime'=>strtolower((string) $info['mime']), 'sha256'=>hash('sha256',$bytes), 'source_ref'=>substr($sourceRef,0,255)];
        } catch (\Throwable $e) {
            @unlink($absolute);
            throw new RuntimeException('External image ingestion failed: ' . get_class($e));
        }
    }

    protected function upload(string $relative): string
    {
        if ($this->uploader) { return (string) call_user_func($this->uploader, $relative); }
        $cfg = config('maccms.upload');
        $cfg = is_array($cfg) ? $cfg : [];
        $api = strtolower((string) ($cfg['api']['type'] ?? $cfg['api']['name'] ?? 's3'));
        if ($api !== 's3') { throw new RuntimeException('Configured remote image uploader is not available.'); }
        return (string) (new \app\common\extend\upload\S3(['keep_local'=>true]))->submit($relative);
    }
}
