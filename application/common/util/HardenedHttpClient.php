<?php

namespace app\common\util;

use InvalidArgumentException;
use RuntimeException;

class HardenedHttpClient
{
    private $policy;
    private $transport;

    public function __construct(ExternalHttpPolicy $policy, callable $transport = null)
    {
        $this->policy = $policy;
        $this->transport = $transport ?: [$this, 'curlTransport'];
    }

    public function get(string $url, array $options = []): array
    {
        return $this->request('GET', $url, $options);
    }

    public function post(string $url, string $body, array $options = []): array
    {
        $options['body'] = $body;
        return $this->request('POST', $url, $options);
    }

    private function request(string $method, string $url, array $options): array
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new InvalidArgumentException('External HTTP method is not permitted.');
        }
        $allowedHosts = $options['allowed_hosts'] ?? [];
        $timeout = max(1, min(120, (int) ($options['timeout'] ?? 15)));
        $maxBytes = max(1, min(10485760, (int) ($options['max_bytes'] ?? 1048576)));
        $maxRedirects = max(0, min(5, (int) ($options['max_redirects'] ?? 0)));
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $body = (string) ($options['body'] ?? '');

        for ($hop = 0; ; $hop++) {
            $target = $this->policy->validate($url, $allowedHosts);
            $response = call_user_func($this->transport, $url, [
                'method' => $method, 'body' => $body,
                'timeout' => $timeout, 'max_bytes' => $maxBytes,
                'headers' => $headers, 'target' => $target,
            ]);
            if (!is_array($response) || !isset($response['status'], $response['headers'], $response['body'])) {
                throw new RuntimeException('External HTTP transport returned an invalid response.');
            }
            if (strlen((string) $response['body']) > $maxBytes) {
                throw new InvalidArgumentException('External HTTP response exceeded the size limit.');
            }
            $status = (int) $response['status'];
            $responseHeaders = array_change_key_case((array) $response['headers'], CASE_LOWER);
            if ($status < 300 || $status >= 400) {
                return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $response['body']];
            }
            if ($hop >= $maxRedirects || empty($responseHeaders['location'])) {
                throw new InvalidArgumentException('External HTTP redirect was not permitted.');
            }
            if ($method !== 'GET' || $headers !== []) {
                throw new InvalidArgumentException('Credentialed or non-GET redirects are not permitted.');
            }
            $url = (string) $responseHeaders['location'];
        }
    }

    protected function curlTransport(string $url, array $options): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is required for external HTTP requests.');
        }
        $body = '';
        $headers = [];
        $exceeded = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $options['timeout']),
            CURLOPT_TIMEOUT => $options['timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $this->headerLines($options['headers']),
            CURLOPT_CUSTOMREQUEST => $options['method'],
            CURLOPT_RESOLVE => [$options['target']['host'] . ':' . $options['target']['port'] . ':' . $options['target']['ips'][0]],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $length = strlen($line);
                $pair = explode(':', trim($line), 2);
                if (count($pair) === 2) { $headers[strtolower(trim($pair[0]))] = trim($pair[1]); }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$exceeded, $options): int {
                if (strlen($body) + strlen($chunk) > $options['max_bytes']) { $exceeded = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($options['method'] === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($exceeded) { throw new InvalidArgumentException('External HTTP response exceeded the size limit.'); }
        if ($ok === false) { throw new RuntimeException('External HTTP request failed: ' . $this->policy->redact($error)); }
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    private function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                $pair = explode(':', (string) $value, 2);
                if (count($pair) !== 2) {
                    throw new InvalidArgumentException('Invalid external HTTP header.');
                }
                $name = trim($pair[0]);
                $value = trim($pair[1]);
            }
            if (!preg_match('/^[A-Za-z0-9-]+$/', (string) $name) || preg_match('/[\r\n]/', (string) $value)) {
                throw new InvalidArgumentException('Invalid external HTTP header.');
            }
            $lines[] = $name . ': ' . $value;
        }
        return $lines;
    }
}
