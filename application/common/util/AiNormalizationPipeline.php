<?php

namespace app\common\util;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

class AiNormalizationPipeline
{
    private const FIELD_MAP = [
        'normalized_title' => 'vod_name', 'original_title' => 'original_title',
        'title_tw' => 'title_tw', 'title_cn' => 'title_cn', 'title_en' => 'title_en',
        'year' => 'vod_year', 'media_type' => 'type2',
    ];
    private $provider;
    private $validator;
    private $runs;
    private $fields;
    private $config;
    private $clock;

    public function __construct(callable $provider, $validator, $runs, $fields, array $config, callable $clock = null)
    {
        foreach (['provider', 'model', 'prompt_version', 'daily_budget_micros'] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new InvalidArgumentException('Missing AI pipeline configuration: ' . $key);
            }
        }
        if ((int) $config['daily_budget_micros'] < 0) {
            throw new InvalidArgumentException('AI daily budget cannot be negative.');
        }
        $this->provider = $provider;
        $this->validator = $validator;
        $this->runs = $runs;
        $this->fields = $fields;
        $this->config = $config;
        $this->clock = $clock ?: 'time';
    }

    public function handle(array $payload, array $job): array
    {
        $vodId = (int) ($payload['vod_id'] ?? 0);
        $jobId = (int) ($job['job_id'] ?? 0);
        if ($vodId < 1 || $jobId < 1) {
            throw new InvalidArgumentException('AI normalization requires positive video and job IDs.');
        }
        $now = (int) call_user_func($this->clock);
        $budget = (int) $this->config['daily_budget_micros'];
        if ($budget > 0 && !$this->runs->withinDailyBudget($budget, $now)) {
            throw new RuntimeException('AI daily budget is exhausted.');
        }
        try {
            $requestBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('AI job payload is not JSON encodable.', 0, $exception);
        }
        $response = call_user_func($this->provider, [
            'model' => (string) $this->config['model'],
            'prompt_version' => (string) $this->config['prompt_version'],
            'payload' => $payload,
        ]);
        $this->assertResponse($response);
        $run = [
            'vod_id' => $vodId, 'job_id' => $jobId,
            'provider' => (string) $this->config['provider'], 'model' => (string) $this->config['model'],
            'prompt_version' => (string) $this->config['prompt_version'], 'request_body' => $requestBody,
            'raw_response' => $response['raw_response'], 'validation_status' => 'valid', 'decision_status' => 'pending',
            'input_tokens' => $response['input_tokens'], 'output_tokens' => $response['output_tokens'],
            'estimated_cost_micros' => $response['estimated_cost_micros'],
        ];
        try {
            $normalized = $this->validator->validate($response['raw_response']);
        } catch (Throwable $exception) {
            $run['validation_status'] = 'invalid';
            $run['decision_status'] = 'rejected';
            $this->runs->record($run);
            throw $exception;
        }

        $runId = $this->runs->record($run);
        $staged = [];
        foreach (self::FIELD_MAP as $candidateKey => $field) {
            $current = $this->fields->inspect($vodId, $field);
            $baseline = $current['value'] ?? null;
            $staged[] = [
                'field_name' => $field,
                'candidate' => $normalized[$candidateKey],
                'baseline' => $baseline,
                'baseline_hash' => $this->valueHash($baseline),
            ];
        }
        $this->runs->stageReviews($runId, $vodId, $staged, $now);
        return ['ai_run_id' => $runId, 'fields_proposed' => 7, 'total_tokens' => $response['input_tokens'] + $response['output_tokens']];
    }

    private function valueHash($value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function assertResponse($response): void
    {
        if (!is_array($response)) {
            throw new RuntimeException('AI provider response is invalid.');
        }
        foreach (['raw_response', 'input_tokens', 'output_tokens', 'estimated_cost_micros'] as $key) {
            if (!array_key_exists($key, $response)) {
                throw new RuntimeException('AI provider response is incomplete.');
            }
        }
        if (!is_string($response['raw_response'])) {
            throw new RuntimeException('AI provider body must be a string.');
        }
        foreach (['input_tokens', 'output_tokens', 'estimated_cost_micros'] as $key) {
            if (!is_int($response[$key]) || $response[$key] < 0) {
                throw new RuntimeException('AI provider usage is invalid.');
            }
        }
    }
}
