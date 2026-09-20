<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$marker = $root . '/upload/.gitkeep';
if (!is_file($marker)) {
    fwrite(STDERR, "FAIL: release checkout does not contain the required ./upload directory marker.\n");
    exit(1);
}

$ignore = (string) file_get_contents($root . '/.gitignore');
if (preg_match('/^\/upload\s*$/m', $ignore)) {
    fwrite(STDERR, "FAIL: .gitignore excludes the complete ./upload directory.\n");
    exit(1);
}
foreach (['/upload/*', '!/upload/.gitkeep'] as $rule) {
    if (strpos($ignore, $rule) === false) {
        fwrite(STDERR, "FAIL: .gitignore missing upload preservation rule: {$rule}\n");
        exit(1);
    }
}

$installer = (string) file_get_contents($root . '/application/install/controller/Index.php');
if (strpos($installer, "['dir', './upload'") === false) {
    fwrite(STDERR, "FAIL: installer no longer verifies ./upload availability.\n");
    exit(1);
}

fwrite(STDOUT, "OK: install distribution preserves the required writable upload directory.\n");
