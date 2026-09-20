<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$errors = [];
$scanRoots = ['application', 'addons', 'static_new'];
$extensions = ['php' => true, 'js' => true, 'html' => true, 'ini' => true, 'json' => true];
$prohibited = [
    'www.maccms.la',
    'union.maccms.la',
    'api.maccms.com',
    'api.maccms.ai',
    'cdn.maccms.ai',
    'img.infinitynewtab.com',
    'maccmsbox.com',
    'tongji.html',
];

foreach ($scanRoots as $scanRoot) {
    $directory = $root . '/' . $scanRoot;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $extension = strtolower(pathinfo($file->getPathname(), PATHINFO_EXTENSION));
        if (!isset($extensions[$extension])) {
            continue;
        }
        $relative = str_replace('\\\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $source = (string) file_get_contents($file->getPathname());
        foreach ($prohibited as $needle) {
            if (stripos($source, $needle) !== false) {
                $errors[] = $relative . ' retains prohibited default communication: ' . $needle;
            }
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
