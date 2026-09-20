<?php
namespace app\common\util;

/**
 * 统一 LLM 调用层。六家 provider 的请求体/响应格式差异全部收在这里。
 *
 * 为什么新建而不是复用 SeoAi：SeoAi 硬限制只能 openai
 * （application/common/util/SeoAi.php:94：provider !== 'openai' 直接走 fallback），
 * 且它的语义是「SEO 元数据」，不是「内容标注」。
 *
 * 为什么不动现有 5 套 AI 配置（ai_seo / ai_search / ai_cover / theme_ai /
 * admin_assistant）：收敛配置孤岛是独立重构，会改到多条稳定路径，不该夹带进本 PR。
 * 这里只保证「新功能不再造第 7 个孤岛」，并支持继承 ai_search 的金钥。
 */
class AiProvider
{
    public static function resolveConfig()
    {
        $cfg = config('maccms');
        $ai = isset($cfg['ai_content']) && is_array($cfg['ai_content']) ? $cfg['ai_content'] : [];

        $out = [
            'enabled' => (string)(isset($ai['enabled']) ? $ai['enabled'] : '0') === '1',
            'provider' => strtolower(trim((string)(isset($ai['provider']) ? $ai['provider'] : 'openai'))),
            'model' => trim((string)(isset($ai['model']) ? $ai['model'] : 'gpt-4o-mini')),
            'api_base' => rtrim(trim((string)(isset($ai['api_base']) ? $ai['api_base'] : '')), '/'),
            'api_key' => trim((string)(isset($ai['api_key']) ? $ai['api_key'] : '')),
            'timeout' => max(5, intval(isset($ai['timeout']) ? $ai['timeout'] : 30)),
            'max_tokens' => max(256, intval(isset($ai['max_tokens']) ? $ai['max_tokens'] : 800)),
            'batch_size' => max(1, min(100, intval(isset($ai['batch_size']) ? $ai['batch_size'] : 20))),
            'daily_budget_micros' => self::dailyBudgetMicros($cfg),
            'auto_adopt_empty' => (string)(isset($ai['auto_adopt_empty']) ? $ai['auto_adopt_empty'] : '0') === '1',
            'prompt_version' => self::cleanPromptVersion(isset($ai['prompt_version']) ? $ai['prompt_version'] : 'normalize-v1'),
            'retry_count' => max(0, min(5, intval(isset($ai['retry_count']) ? $ai['retry_count'] : 2))),
            'retry_delay_ms' => max(0, min(10000, intval(isset($ai['retry_delay_ms']) ? $ai['retry_delay_ms'] : 500))),
            'duplicate_threshold' => max(0, min(1000, intval(isset($ai['duplicate_threshold']) ? $ai['duplicate_threshold'] : 650))),
            'duplicate_candidate_limit' => max(1, min(2000, intval(isset($ai['duplicate_candidate_limit']) ? $ai['duplicate_candidate_limit'] : 500))),
            'duplicate_fallback_limit' => max(0, min(500, intval(isset($ai['duplicate_fallback_limit']) ? $ai['duplicate_fallback_limit'] : 100))),
            'input_price_micros_per_million' => max(0, intval(isset($ai['input_price_micros_per_million']) ? $ai['input_price_micros_per_million'] : 150000)),
            'output_price_micros_per_million' => max(0, intval(isset($ai['output_price_micros_per_million']) ? $ai['output_price_micros_per_million'] : 600000)),
        ];

        // 继承 ai_search 的金钥（照搬 AdminAssistantService 的既有做法），
        // 避免站长为同一个 OpenAI key 填两遍。
        $inherit = (string)(isset($ai['use_ai_search_credentials']) ? $ai['use_ai_search_credentials'] : '0') === '1';
        if ($inherit && isset($cfg['ai_search']) && is_array($cfg['ai_search'])) {
            $src = $cfg['ai_search'];
            if ($out['api_key'] === '' && !empty($src['api_key'])) {
                $out['api_key'] = trim((string)$src['api_key']);
            }
            if ($out['api_base'] === '' && !empty($src['api_base'])) {
                $out['api_base'] = rtrim(trim((string)$src['api_base']), '/');
            }
        }

        if ($out['api_base'] === '') {
            $out['api_base'] = self::defaultBase($out['provider']);
        }
        return $out;
    }

    public static function dailyBudgetMicros(array $config)
    {
        $ai = isset($config['ai_content']) && is_array($config['ai_content']) ? $config['ai_content'] : [];
        return max(0, intval(isset($ai['daily_budget_micros']) ? $ai['daily_budget_micros'] : 0));
    }

    public static function normalizeContentConfig(array $ai)
    {
        return [
            'prompt_version' => self::cleanPromptVersion(isset($ai['prompt_version']) ? $ai['prompt_version'] : 'normalize-v1'),
            'retry_count' => max(0, min(5, intval(isset($ai['retry_count']) ? $ai['retry_count'] : 2))),
            'retry_delay_ms' => max(0, min(10000, intval(isset($ai['retry_delay_ms']) ? $ai['retry_delay_ms'] : 500))),
            'duplicate_threshold' => max(0, min(1000, intval(isset($ai['duplicate_threshold']) ? $ai['duplicate_threshold'] : 650))),
            'duplicate_candidate_limit' => max(1, min(2000, intval(isset($ai['duplicate_candidate_limit']) ? $ai['duplicate_candidate_limit'] : 500))),
            'duplicate_fallback_limit' => max(0, min(500, intval(isset($ai['duplicate_fallback_limit']) ? $ai['duplicate_fallback_limit'] : 100))),
            'input_price_micros_per_million' => max(0, intval(isset($ai['input_price_micros_per_million']) ? $ai['input_price_micros_per_million'] : 150000)),
            'output_price_micros_per_million' => max(0, intval(isset($ai['output_price_micros_per_million']) ? $ai['output_price_micros_per_million'] : 600000)),
        ];
    }

    private static function cleanPromptVersion($value)
    {
        $value = trim((string) preg_replace('/[\\x00-\\x20\\x7F]+/', '', (string) $value));
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/', $value) ? $value : 'normalize-v1';
    }

    public static function extractUsage($cfg, $respBody)
    {
        $json = json_decode((string) $respBody, true);
        if (!is_array($json)) { return null; }
        $provider = strtolower((string) (isset($cfg['provider']) ? $cfg['provider'] : 'openai'));
        if ($provider === 'claude') {
            $input = isset($json['usage']['input_tokens']) ? $json['usage']['input_tokens'] : null;
            $output = isset($json['usage']['output_tokens']) ? $json['usage']['output_tokens'] : null;
        } elseif ($provider === 'gemini') {
            $input = isset($json['usageMetadata']['promptTokenCount']) ? $json['usageMetadata']['promptTokenCount'] : null;
            $output = isset($json['usageMetadata']['candidatesTokenCount']) ? $json['usageMetadata']['candidatesTokenCount'] : null;
        } else {
            $input = isset($json['usage']['prompt_tokens']) ? $json['usage']['prompt_tokens'] : null;
            $output = isset($json['usage']['completion_tokens']) ? $json['usage']['completion_tokens'] : null;
        }
        if (!is_numeric($input) || !is_numeric($output) || (int) $input < 0 || (int) $output < 0) { return null; }
        return ['input_tokens' => (int) $input, 'output_tokens' => (int) $output];
    }

    public static function estimateCostMicros($inputTokens, $outputTokens, array $cfg)
    {
        $inputPrice = max(0, intval(isset($cfg['input_price_micros_per_million']) ? $cfg['input_price_micros_per_million'] : 0));
        $outputPrice = max(0, intval(isset($cfg['output_price_micros_per_million']) ? $cfg['output_price_micros_per_million'] : 0));
        return (int) ceil((max(0, intval($inputTokens)) * $inputPrice + max(0, intval($outputTokens)) * $outputPrice) / 1000000);
    }

    private static function defaultBase($provider)
    {
        $map = [
            'openai' => 'https://api.openai.com/v1',
            'claude' => 'https://api.anthropic.com/v1',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta',
            'deepseek' => 'https://api.deepseek.com/v1',
            'qwen' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'glm' => 'https://open.bigmodel.cn/api/paas/v4',
        ];
        return isset($map[$provider]) ? $map[$provider] : $map['openai'];
    }

    /**
     * 纯函数：构建各家的请求体。
     */
    public static function buildRequest($cfg, $systemPrompt, $userPrompt)
    {
        $provider = isset($cfg['provider']) ? strtolower((string)$cfg['provider']) : 'openai';
        $model = (string)$cfg['model'];
        $maxTokens = max(256, intval(isset($cfg['max_tokens']) ? $cfg['max_tokens'] : 800));

        if ($provider === 'claude') {
            // Anthropic 的 system 是顶层独立字段，不能塞进 messages
            return [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => (string)$systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => (string)$userPrompt],
                ],
            ];
        }

        if ($provider === 'gemini') {
            // Gemini 没有 system role，把 system 并进第一段 user 文本
            return [
                'contents' => [
                    ['parts' => [['text' => (string)$systemPrompt . "\n\n" . (string)$userPrompt]]],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => $maxTokens,
                    'temperature' => 0.4,
                ],
            ];
        }

        // openai / deepseek / qwen / glm 都是 OpenAI 兼容的 chat/completions
        return [
            'model' => $model,
            'temperature' => 0.4,
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => (string)$systemPrompt],
                ['role' => 'user', 'content' => (string)$userPrompt],
            ],
        ];
    }

    /**
     * 纯函数：从各家响应里抽出模型输出的纯文本。抽不到一律返回 ''。
     */
    public static function extractText($cfg, $respBody)
    {
        $respBody = (string)$respBody;
        if ($respBody === '') {
            return '';
        }
        $json = json_decode($respBody, true);
        if (!is_array($json)) {
            return '';
        }
        $provider = isset($cfg['provider']) ? strtolower((string)$cfg['provider']) : 'openai';

        if ($provider === 'claude') {
            if (!isset($json['content']) || !is_array($json['content'])) {
                return '';
            }
            foreach ($json['content'] as $block) {
                if (is_array($block) && isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                    return (string)$block['text'];
                }
            }
            return '';
        }

        if ($provider === 'gemini') {
            if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                return (string)$json['candidates'][0]['content']['parts'][0]['text'];
            }
            return '';
        }

        if (isset($json['choices'][0]['message']['content'])) {
            return (string)$json['choices'][0]['message']['content'];
        }
        return '';
    }

    private static function endpoint($cfg)
    {
        $provider = strtolower((string)$cfg['provider']);
        $base = rtrim((string)$cfg['api_base'], '/');
        if ($provider === 'claude') {
            return $base . '/messages';
        }
        if ($provider === 'gemini') {
            return $base . '/models/' . rawurlencode((string)$cfg['model']) . ':generateContent?key=' . rawurlencode((string)$cfg['api_key']);
        }
        return $base . '/chat/completions';
    }

    private static function headers($cfg)
    {
        $provider = strtolower((string)$cfg['provider']);
        $headers = ['Content-Type: application/json'];
        if ($provider === 'claude') {
            $headers[] = 'x-api-key: ' . (string)$cfg['api_key'];
            $headers[] = 'anthropic-version: 2023-06-01';
            return $headers;
        }
        if ($provider === 'gemini') {
            // key 走 query string，见 endpoint()
            return $headers;
        }
        $headers[] = 'Authorization: Bearer ' . (string)$cfg['api_key'];
        return $headers;
    }

    public static function chat($cfg, $systemPrompt, $userPrompt)
    {
        if (empty($cfg['enabled'])) {
            return ['code' => 0, 'msg' => 'ai_content disabled', 'text' => ''];
        }
        if ((string)$cfg['api_key'] === '') {
            return ['code' => 0, 'msg' => 'api_key missing', 'text' => ''];
        }

        $body = json_encode(self::buildRequest($cfg, $systemPrompt, $userPrompt), JSON_UNESCAPED_UNICODE);
        $url = self::endpoint($cfg);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return ['code' => 0, 'msg' => 'invalid ai endpoint', 'text' => ''];
        }
        $response = null;
        $attempts = 1 + max(0, min(5, intval(isset($cfg['retry_count']) ? $cfg['retry_count'] : 0)));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $client = new HardenedHttpClient(new ExternalHttpPolicy());
                $response = $client->post($url, $body, [
                    'allowed_hosts' => [$host],
                    'headers' => self::headers($cfg),
                    'timeout' => intval($cfg['timeout']),
                    'max_bytes' => 4194304,
                    'max_redirects' => 0,
                ]);
                if ((int) $response['status'] < 500 && (int) $response['status'] !== 429) { break; }
            } catch (\Exception $exception) {
                $response = null;
            }
            if ($attempt < $attempts) {
                $delay = max(0, min(10000, intval(isset($cfg['retry_delay_ms']) ? $cfg['retry_delay_ms'] : 0)));
                if ($delay > 0) { usleep($delay * 1000); }
            }
        }
        if (!is_array($response)) { return ['code' => 0, 'msg' => 'ai request failed', 'text' => '']; }
        if ((int)$response['status'] === 429) {
            throw new ContentJobFailure('rate_limit', 'External provider rate limit reached.');
        }
        $resp = (string)$response['body'];
        if ($resp === '') {
            return ['code' => 0, 'msg' => 'empty ai response', 'text' => ''];
        }
        $text = self::extractText($cfg, $resp);
        if ($text === '') {
            return ['code' => 0, 'msg' => 'invalid ai response', 'text' => ''];
        }
        return ['code' => 1, 'msg' => '', 'text' => $text, 'usage' => self::extractUsage($cfg, $resp)];
    }
}
