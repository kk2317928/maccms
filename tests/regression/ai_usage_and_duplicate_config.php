<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
foreach (['AiProvider.php', 'DuplicateCandidateScorer.php', 'DuplicateCandidateRepository.php', 'DuplicateCandidateDetector.php'] as $file) {
    require_once $root . '/application/common/util/' . $file;
}

use app\common\util\AiProvider;
use app\common\util\DuplicateCandidateDetector;
use app\common\util\DuplicateCandidateRepository;
use app\common\util\DuplicateCandidateScorer;

function task106_assert($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$usageCases = [
    [['provider' => 'openai'], '{"usage":{"prompt_tokens":120,"completion_tokens":30}}', ['input_tokens' => 120, 'output_tokens' => 30]],
    [['provider' => 'claude'], '{"usage":{"input_tokens":80,"output_tokens":20}}', ['input_tokens' => 80, 'output_tokens' => 20]],
    [['provider' => 'gemini'], '{"usageMetadata":{"promptTokenCount":70,"candidatesTokenCount":15}}', ['input_tokens' => 70, 'output_tokens' => 15]],
];
foreach ($usageCases as [$config, $body, $expected]) {
    task106_assert(AiProvider::extractUsage($config, $body) === $expected, 'provider token usage was not parsed exactly.');
}
task106_assert(AiProvider::extractUsage(['provider' => 'openai'], '{}') === null, 'missing provider usage must trigger fallback estimation.');
task106_assert(AiProvider::estimateCostMicros(1000, 500, ['input_price_micros_per_million' => 2000000, 'output_price_micros_per_million' => 4000000]) === 4000, 'configured token prices produced the wrong micro-cost.');

$config = AiProvider::normalizeContentConfig([
    'prompt_version' => " normalize-v9\n", 'retry_count' => 99, 'retry_delay_ms' => -1,
    'duplicate_threshold' => 1200, 'duplicate_candidate_limit' => 0, 'duplicate_fallback_limit' => 9000,
    'input_price_micros_per_million' => -2, 'output_price_micros_per_million' => 987654,
]);
task106_assert($config['prompt_version'] === 'normalize-v9', 'prompt version was not normalized.');
task106_assert($config['retry_count'] === 5 && $config['retry_delay_ms'] === 0, 'retry bounds were not enforced.');
task106_assert($config['duplicate_threshold'] === 1000 && $config['duplicate_candidate_limit'] === 1 && $config['duplicate_fallback_limit'] === 500, 'duplicate-search bounds were not enforced.');
task106_assert($config['input_price_micros_per_million'] === 0 && $config['output_price_micros_per_million'] === 987654, 'token price bounds were not enforced.');

final class Task106Repository extends DuplicateCandidateRepository
{
    public $rows = [];
    protected function persist(array $row): int { $this->rows[] = $row; return count($this->rows); }
}
final class Task106Detector extends DuplicateCandidateDetector
{
    private $fixtures;
    public function __construct(array $fixtures, $repository) { $this->fixtures = $fixtures; parent::__construct(new DuplicateCandidateScorer(), $repository, 650, static function (): int { return 100; }, 2, 1); }
    protected function loadCandidates(int $vodId): array { return $this->fixtures; }
    protected function markChecked(int $vodId, int $now): void {}
}
$fixtures = [];
foreach ([2, 3, 4] as $id) { $fixtures[] = ['vod_id' => $id, 'tmdb_id' => 77]; }
$repository = new Task106Repository(static function (): int { return 100; });
$detector = new Task106Detector($fixtures, $repository);
$metrics = $detector->detect(1, ['tmdb_id' => 77]);
task106_assert($metrics['candidates_recorded'] === 2 && count($repository->rows) === 2, 'configured candidate limit was not enforced independently of repository size.');

fwrite(STDOUT, "OK: configurable AI usage and duplicate lookup contract passed.\n");
