<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$requiredFiles = [
    'extend/login/ThinkOauth.php',
    'extend/login/sdk/QqSDK.php',
    'extend/login/sdk/WeixinSDK.php',
    'extend/phpmailer/src/PHPMailer.php',
    'extend/phpmailer/src/SMTP.php',
    'extend/qiniu/autoload.php',
    'extend/qiniu/src/Qiniu/Auth.php',
    'extend/upyun/src/Upyun/Upyun.php',
    'extend/upyun/vendor/autoload.php',
    'vendor/autoload.php',
    'vendor/composer/autoload_real.php',
    'vendor/karsonzhang/fastadmin-addons/src/Addons.php',
    'vendor/topthink/think-captcha/src/Captcha.php',
    'vendor/topthink/think-helper/src/Arr.php',
    'vendor/topthink/think-image/src/Image.php',
    'vendor/topthink/think-installer/src/Plugin.php',
    'vendor/topthink/think-queue/src/Queue.php',
    'runtime/index.html',
    'upload/index.htm',
    'upload/vod/index.htm',
];

foreach ($requiredFiles as $relativePath) {
    if (!is_file($root . '/' . $relativePath)) {
        fwrite(STDERR, "FAIL: bundled distribution dependency missing: {$relativePath}\n");
        exit(1);
    }
}

$forbiddenFiles = [
    'application/admin/controller/Update.php',
    'application/admin/view_new/index/update.html',
    'static_new/css/update.css',
    'static_new/js/update.js',
];

foreach ($forbiddenFiles as $relativePath) {
    if (is_file($root . '/' . $relativePath)) {
        fwrite(STDERR, "FAIL: official update component was restored: {$relativePath}\n");
        exit(1);
    }
}

fwrite(STDOUT, "OK: bundled distribution dependencies are present and official updater remains removed.\n");
