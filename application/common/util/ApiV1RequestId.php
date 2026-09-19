<?php
namespace app\common\util;

use Throwable;

class ApiV1RequestId
{
    public static function resolve($candidate = null)
    {
        if (is_string($candidate)
            && strlen($candidate) >= 1
            && strlen($candidate) <= 64
            && preg_match('/\A[A-Za-z0-9._:-]+\z/D', $candidate) === 1
        ) {
            return $candidate;
        }

        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable $exception) {
            return hash('sha256', uniqid((string) getmypid(), true) . microtime(true));
        }
    }
}
