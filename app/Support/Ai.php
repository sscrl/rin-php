<?php
declare(strict_types=1);

namespace Rin\Support;

final class Ai
{
    public const SYSTEM_PROMPT = '你是一个中文内容摘要助手。请用简洁、准确、自然的中文总结用户提供的内容，不超过200字，不要添加原文没有的信息，不要输出标题或项目符号。';

    public const PROVIDER_URLS = [
        'openai' => 'https://api.openai.com/v1',
        'claude' => 'https://api.anthropic.com/v1',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'deepseek' => 'https://api.deepseek.com/v1',
    ];

    public static function chatCompletionsUrl(string $provider, string $apiUrl): string
    {
        $normalized = rtrim($apiUrl !== '' ? $apiUrl : (self::PROVIDER_URLS[$provider] ?? ''), '/');
        $normalized = preg_replace('#/chat/completions$#i', '', $normalized) ?? $normalized;
        if ($normalized === '') {
            throw new \RuntimeException('API URL not configured');
        }
        return $normalized . '/chat/completions';
    }

    public static function complete(array $config, array $messages, int $maxTokens = 500): string
    {
        if (($config['api_key'] ?? '') === '') {
            throw new \RuntimeException('API key not configured');
        }
        $url = self::chatCompletionsUrl((string) $config['provider'], (string) ($config['api_url'] ?? ''));
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to init curl');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config['api_key'],
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $config['model'],
                'messages' => $messages,
                'max_tokens' => $maxTokens,
                'temperature' => 0.3,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException($error !== '' ? $error : 'AI request failed');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('API error ' . $status . ': ' . $raw);
        }
        $data = json_decode($raw, true);
        $text = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new \RuntimeException('Empty AI response');
        }
        return trim($text);
    }

    public static function summarize(array $config, string $content): string
    {
        $truncated = mb_strlen($content) > 8000 ? mb_substr($content, 0, 8000) . '...' : $content;
        return self::complete($config, [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $truncated],
        ]);
    }
}
