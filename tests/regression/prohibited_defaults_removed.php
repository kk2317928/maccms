<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$errors = [];
$files = [
    'application/install/view/index/foot.html',
    'application/install/view/index/step2.html',
    'application/install/view/index/step3.html',
    'application/extra/maccms.php',
    'application/admin/controller/Addon.php',
    'application/common/util/AddonCloudService.php',
    'application/common/util/TemplateCloudService.php',
    'addons/adminloginbg/Adminloginbg.php',
    'addons/adminloginbg/config.php',
];
$prohibited = [
    'www.maccms.la',
    'union.maccms.la',
    'api.maccms.com',
    'api.maccms.ai',
    'cdn.maccms.ai',
    'img.infinitynewtab.com',
    'tongji.html',
];

foreach ($files as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        $errors[] = 'Missing inspected file: ' . $relative;
        continue;
    }
    $source = (string) file_get_contents($path);
    foreach ($prohibited as $needle) {
        if (stripos($source, $needle) !== false) {
            $errors[] = $relative . ' retains prohibited default communication: ' . $needle;
        }
    }
}

if ($errors) {
    foreach ($errors as $error) {
        fwrite(STDERR, "FAIL: {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "OK: announcements, affiliate defaults and telemetry are absent.\n");
