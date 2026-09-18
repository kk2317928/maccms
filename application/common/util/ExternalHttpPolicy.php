<?php

namespace app\common\util;

use InvalidArgumentException;

class ExternalHttpPolicy
{
    private $resolver;

    public function __construct(callable $resolver = null)
    {
        $this->resolver = $resolver ?: [$this, 'resolve'];
    }

    public function validate(string $url, array $allowedHosts): array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException('External URL must use HTTPS and include a host.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('URL credentials are not allowed.');
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $allow = array_map(static function ($value): string { return strtolower(rtrim(trim((string) $value), '.')); }, $allowedHosts);
        if (!$allow || !in_array($host, $allow, true)) {
            throw new InvalidArgumentException('External host is not allowlisted.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : call_user_func($this->resolver, $host);
        $ips = array_values(array_unique(array_map('strval', is_array($ips) ? $ips : [])));
        if (!$ips) {
            throw new InvalidArgumentException('External host did not resolve.');
        }
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new InvalidArgumentException('External host resolved to a non-public address.');
            }
        }
        return ['url' => $url, 'host' => $host, 'ips' => $ips, 'port' => (int) ($parts['port'] ?? 443)];
    }

    public function redact(string $value): string
    {
        $value = (string) preg_replace('/(?im)^(authorization|proxy-authorization|x-api-key)\s*:\s*.*$/', '$1: [REDACTED]', $value);
        return (string) preg_replace('/([?&](?:api[_-]?key|access[_-]?token|token|key)=)[^&\s]*/i', '$1[REDACTED]', $value);
    }

    private function resolve(string $host): array
    {
        $ips = [];
        if (function_exists('dns_get_record')) {
            foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
                $records = @dns_get_record($host, $type);
                foreach (is_array($records) ? $records : [] as $record) {
                    if (!empty($record[$field])) { $ips[] = (string) $record[$field]; }
                }
            }
        }
        if (!$ips) {
            $fallback = @gethostbynamel($host);
            $ips = is_array($fallback) ? $fallback : [];
        }
        return $ips;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
