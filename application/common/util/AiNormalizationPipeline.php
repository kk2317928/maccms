<?php

namespace app\common\util;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

class AiNormalizationPipeline
{
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
        if ((int) $config['daily_budget_micros'] < 1) {
            throw new InvalidArgumentException('AI daily budget must be positive.');
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
        if (!$this->runs->withinDailyBudget($budget, $now)) {
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

        $mapping = [
            'normalized_title' => 'vod_name', 'original_title' => 'original_title',
            'title_tw' => 'title_tw', 'title_cn' => 'title_cn', 'title_en' => 'title_en',
            'year' => 'vod_year', 'media_type' => 'type2',
        ];
        $applied = 0;
        foreach ($mapping as $sourceField => $targetField) {
            if ($this->fields->apply($vodId, $targetField, $normalized[$sourceField], 'ai', 'job:' . $jobId)) {
                $applied++;
            }
        }
        $run['decision_status'] = $applied ? 'applied' : 'blocked';
        $runId = $this->runs->record($run);
        return ['ai_run_id' => $runId, 'fields_applied' => $applied, 'total_tokens' => $response['input_tokens'] + $response['output_tokens']];
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
