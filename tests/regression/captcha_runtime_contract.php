<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
define('APP_PATH', $root . '/application/');
define('ENTRANCE', 'command');
require $root . '/thinkphp/base.php';
require_once $root . '/extend/think/captcha/helper.php';

if (!class_exists('think\\captcha\\Captcha')) {
    fwrite(STDERR, "FAIL: think\\captcha\\Captcha is unavailable in a clean release checkout.\n");
    exit(1);
}
if (!function_exists('captcha_check')) {
    fwrite(STDERR, "FAIL: captcha_check helper is unavailable in a clean release checkout.\n");
    exit(1);
}

$captcha = new \think\captcha\Captcha(['length' => 4, 'codeSet' => '1234567890']);
if (!method_exists($captcha, 'entry') || !method_exists($captcha, 'check')) {
    fwrite(STDERR, "FAIL: captcha runtime does not provide entry/check operations.\n");
    exit(1);
}

fwrite(STDOUT, "OK: captcha runtime is self-contained in the release checkout.\n");
