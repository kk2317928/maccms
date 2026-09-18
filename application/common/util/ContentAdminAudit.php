<?php

namespace app\common\util;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use think\Db;

class ContentAdminAudit
{
    private $writer;
    private $clock;

    public function __construct(callable $writer = null, callable $clock = null)
    {
        $this->writer = $writer ?: static function (array $row): int {
            return (int) Db::name('content_admin_audit_event')->insertGetId($row);
        };
        $this->clock = $clock ?: 'time';
    }

    public function append(int $actorId, string $actorName, string $eventCode, string $subjectType, string $subjectPublicId, array $before, array $after, array $context): int
    {
        if ($actorId <= 0 || !preg_match('/^[a-z][a-z0-9_.-]{2,63}$/', $eventCode)
            || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $subjectType)
            || strlen($subjectPublicId) > 64) {
            throw new InvalidArgumentException('Content-admin audit identity is invalid.');
        }
        $beforeJson = $this->encodeCanonical($before);
        $afterJson = $this->encodeCanonical($after);
        $contextJson = $this->encodeCanonical($this->redact($context));
        $row = [
            'actor_id' => $actorId,
            'actor_name' => substr(trim($actorName), 0, 60),
            'event_code' => $eventCode,
            'subject_type' => $subjectType,
            'subject_public_id' => $subjectPublicId,
            'before_hash' => hash('sha256', $beforeJson),
            'after_hash' => hash('sha256', $afterJson),
            'context_json' => $contextJson,
            'created_at' => (int) call_user_func($this->clock),
        ];
        $eventId = (int) call_user_func($this->writer, $row);
        if ($eventId <= 0) {
            throw new RuntimeException('Content-admin audit persistence failed.');
        }
        return $eventId;
    }

    private function encodeCanonical(array $value): string
    {
        $value = $this->canonicalize($value);
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Audit data is not JSON encodable.', 0, $exception);
        }
    }

    private function canonicalize($value)
    {
        if (!is_array($value)) { return $value; }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) { ksort($value); }
        foreach ($value as $key => $item) { $value[$key] = $this->canonicalize($item); }
        return $value;
    }

    private function redact(array $value): array
    {
        foreach ($value as $key => $item) {
            $name = strtolower((string) $key);
            if (preg_match('/(password|secret|token|api[_-]?key|private[_-]?key)/', $name)) {
                $value[$key] = '[redacted]';
            } elseif (is_array($item)) {
                $value[$key] = $this->redact($item);
            }
        }
        return $value;
    }
}
