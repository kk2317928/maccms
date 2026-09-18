<?php

declare(strict_types=1);

$runner = __DIR__ . '/run_baseline.php';
if (!is_file($runner)) {
    fwrite(STDERR, "Baseline runner is missing: {$runner}\n");
    exit(1);
}

$fixtureDir = sys_get_temp_dir() . '/maccms-baseline-runner-' . bin2hex(random_bytes(8));
if (!mkdir($fixtureDir, 0700, true) && !is_dir($fixtureDir)) {
    fwrite(STDERR, "Unable to create fixture directory.\n");
    exit(1);
}

$logFile = $fixtureDir . '/execution.log';
$fixtures = [
    '10-pass.php' => "<?php file_put_contents(" . var_export($logFile, true) . ", \"pass\\n\", FILE_APPEND); exit(0);\n",
    '20-fail.php' => "<?php file_put_contents(" . var_export($logFile, true) . ", \"fail\\n\", FILE_APPEND); fwrite(STDERR, \"intentional fixture failure\\n\"); exit(23);\n",
    '30-never.php' => "<?php file_put_contents(" . var_export($logFile, true) . ", \"never\\n\", FILE_APPEND); exit(0);\n",
];

try {
    foreach ($fixtures as $name => $source) {
        if (file_put_contents($fixtureDir . '/' . $name, $source) === false) {
            throw new RuntimeException("Unable to write fixture {$name}.");
        }
    }

    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($runner)
        . ' --suite-dir ' . escapeshellarg($fixtureDir)
        . ' 2>&1';
    $outputLines = [];
    exec($command, $outputLines, $exitCode);
    $output = implode("\n", $outputLines);
    $executionLog = is_file($logFile) ? file_get_contents($logFile) : false;

    $failures = [];
    if ($exitCode !== 23) {
        $failures[] = "Expected exit code 23, received {$exitCode}.";
    }
    if ($executionLog !== "pass\nfail\n") {
        $failures[] = 'Expected deterministic stop-on-first-failure execution order.';
    }
    if (strpos($output, '20-fail.php') === false) {
        $failures[] = 'Runner output did not identify the failing script.';
    }

    if ($failures !== []) {
        fwrite(STDERR, implode("\n", $failures) . "\nRunner output:\n{$output}\n");
        exit(1);
    }

    fwrite(STDOUT, "Baseline runner contract passed.\n");
} finally {
    foreach (array_keys($fixtures) as $name) {
        @unlink($fixtureDir . '/' . $name);
    }
    @unlink($logFile);
    @rmdir($fixtureDir);
}
