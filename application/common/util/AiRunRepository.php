<?php

namespace app\common\util;

use InvalidArgumentException;
use think\Db;

class AiRunRepository
{
    private $clock;

    public function __construct(callable $clock = null)
    {
        $this->clock = $clock ?: 'time';
    }

    public function record(array $data): int
    {
        foreach (['vod_id', 'job_id', 'provider', 'model', 'prompt_version', 'request_body', 'raw_response', 'validation_status', 'decision_status', 'input_tokens', 'output_tokens', 'estimated_cost_micros'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new InvalidArgumentException('Missing AI run field: ' . $key);
            }
        }
        foreach (['vod_id', 'job_id'] as $key) {
            if (!is_int($data[$key]) || $data[$key] <= 0) {
                throw new InvalidArgumentException($key . ' must be a positive integer.');
            }
        }
        foreach (['input_tokens', 'output_tokens', 'estimated_cost_micros'] as $key) {
            if (!is_int($data[$key]) || $data[$key] < 0) {
                throw new InvalidArgumentException($key . ' must be a non-negative integer.');
            }
        }
        foreach (['provider' => 64, 'model' => 128, 'prompt_version' => 64, 'validation_status' => 16, 'decision_status' => 16] as $key => $limit) {
            if (!is_string($data[$key]) || trim($data[$key]) === '' || strlen($data[$key]) > $limit || preg_match('/[\x00-\x1F\x7F]/', $data[$key])) {
                throw new InvalidArgumentException('Invalid AI run field: ' . $key);
            }
        }
        if (!is_string($data['request_body']) || !is_string($data['raw_response'])) {
            throw new InvalidArgumentException('AI request and response bodies must be strings.');
        }

        $row = [
            'vod_id' => $data['vod_id'],
            'job_id' => $data['job_id'],
            'provider' => trim($data['provider']),
            'model' => trim($data['model']),
            'prompt_version' => trim($data['prompt_version']),
            'request_fingerprint' => hash('sha256', $data['request_body']),
            'response_fingerprint' => hash('sha256', $data['raw_response']),
            'validation_status' => trim($data['validation_status']),
            'decision_status' => trim($data['decision_status']),
            'input_tokens' => $data['input_tokens'],
            'output_tokens' => $data['output_tokens'],
            'total_tokens' => $data['input_tokens'] + $data['output_tokens'],
            'estimated_cost_micros' => $data['estimated_cost_micros'],
            'raw_response_json' => $data['raw_response'],
            'created_at' => (int) call_user_func($this->clock),
        ];
        return $this->insertRun($row);
    }

    public function dailyUsage(int $timestamp): array
    {
        if ($timestamp < 0) {
            throw new InvalidArgumentException('Timestamp cannot be negative.');
        }
        $from = intdiv($timestamp, 86400) * 86400;
        $usage = $this->sumUsage($from, $from + 86400);
        return [
            'cost_micros' => (int) ($usage['cost_micros'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
        ];
    }

    public function withinDailyBudget(int $budgetMicros, int $timestamp): bool
    {
        if ($budgetMicros < 0) {
            throw new InvalidArgumentException('Daily budget cannot be negative.');
        }
        if ($budgetMicros === 0) {
            return true;
        }
        return $this->dailyUsage($timestamp)['cost_micros'] < $budgetMicros;
    }

    public function recordWithReviews(array $run, int $vodId, array $fields, int $now): int
    {
        return (int) Db::transaction(function () use ($run, $vodId, $fields, $now) {
            $runId = $this->record($run);
            $this->stageReviews($runId, $vodId, $fields, $now);
            return $runId;
        });
    }

    protected function stageReviews(int $runId, int $vodId, array $fields, int $now): void
    {
        foreach ($fields as $field) {
            Db::name('content_ai_field_review')->insert([
                'ai_run_id' => $runId, 'vod_id' => $vodId,
                'field_name' => (string) $field['field_name'],
                'candidate_value_json' => $this->encodeValue($field['candidate']),
                'baseline_value_json' => $this->encodeValue($field['baseline']),
                'baseline_hash' => (string) $field['baseline_hash'],
                'reviewed_value_json' => null, 'decision' => 'pending',
                'actor_id' => 0, 'actor_name' => '', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function encodeValue($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) { throw new InvalidArgumentException('AI review value is not JSON encodable.'); }
        return $json;
    }

    protected function insertRun(array $row): int
    {
        return (int) Db::name('content_ai_run')->insertGetId($row);
    }

    protected function sumUsage(int $from, int $to): array
    {
        $row = Db::name('content_ai_run')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->field('COALESCE(SUM(estimated_cost_micros),0) AS cost_micros,COALESCE(SUM(total_tokens),0) AS total_tokens')
            ->find();
        return $row ?: ['cost_micros' => 0, 'total_tokens' => 0];
    }
}
