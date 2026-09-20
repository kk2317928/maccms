<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function installedWorkflowAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$command = @file_get_contents($root . '/application/command/MaccmsJobs.php') ?: '';
$pipeline = @file_get_contents($root . '/application/common/util/AiNormalizationPipeline.php') ?: '';
$provider = @file_get_contents($root . '/application/common/util/AiNormalizationProvider.php') ?: '';
$detector = @file_get_contents($root . '/application/common/util/DuplicateCandidateDetector.php') ?: '';

installedWorkflowAssert(strpos($command, 'new AiNormalizationJobHandler') !== false, 'installed worker must register a default ai.normalize handler.');
installedWorkflowAssert(strpos($command, 'new AiNormalizationProvider') !== false, 'installed worker must use the configured AI provider.');
installedWorkflowAssert(strpos($command, 'new DuplicateCandidateDetector') !== false, 'installed worker must wire automatic duplicate detection.');
foreach (["'taxonomy'", "'vod_area'", "'vod_class'", "'vod_tag'", 'auto_adopt_empty'] as $needle) {
    installedWorkflowAssert(strpos($pipeline, $needle) !== false, 'AI pipeline must retain and classify taxonomy via ' . $needle . '.');
}
installedWorkflowAssert(strpos($pipeline, 'detect(') !== false, 'AI completion must generate duplicate candidates.');
installedWorkflowAssert(strpos($provider, 'AiProvider::chat') !== false, 'default normalization provider must call the configured provider.');
installedWorkflowAssert(strpos($detector, 'DuplicateCandidateScorer') !== false && strpos($detector, 'duplicate_checked_at') !== false, 'duplicate detector must score candidates and record scan completion.');

fwrite(STDOUT, "OK: installed AI classification and duplicate-candidate workflow is wired end to end.\n");
