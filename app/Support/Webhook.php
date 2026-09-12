<?php
declare(strict_types=1);

namespace Rin\Support;

final class Webhook
{
    public static function notify(string $url, array $payload, array $format = []): ?array
    {
        if ($url === '') {
            return null;
        }
        $request = self::build($payload, $format + ['urlTemplate' => $url]);
        $ch = curl_init($request['url']);
        if ($ch === false) {
            return null;
        }
        $headers = [];
        foreach ($request['headers'] as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $request['method'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if (($request['body'] ?? null) !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request['body']);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'ok' => $body !== false && $status >= 200 && $status < 300,
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
        ];
    }

    public static function build(array $payload, array $format = []): array
    {
        $method = strtoupper(trim((string) ($format['method'] ?? 'POST')) ?: 'POST');
        $contentType = trim((string) ($format['contentType'] ?? 'application/json')) ?: 'application/json';
        $urlTemplate = trim((string) ($format['urlTemplate'] ?? ''));
        $bodyTemplate = self::normalizeTemplate($format['bodyTemplate'] ?? null, '{"content":"{{message}}"}');
        $headersTemplate = self::normalizeTemplate($format['headers'] ?? null, '{}');
        $values = [
            'event' => (string) ($payload['event'] ?? ''),
            'message' => (string) ($payload['message'] ?? ''),
            'title' => (string) ($payload['title'] ?? ''),
            'url' => (string) ($payload['url'] ?? ''),
            'username' => (string) ($payload['username'] ?? ''),
            'content' => (string) ($payload['content'] ?? ''),
            'description' => (string) ($payload['description'] ?? ''),
        ];
        $jsonBody = self::isJson($bodyTemplate);
        $jsonHeaders = self::isJson($headersTemplate);
        $renderedBody = self::render($bodyTemplate, $values, $jsonBody);
        $renderedHeaders = self::render($headersTemplate, $values, $jsonHeaders);
        $requestUrl = self::render($urlTemplate, $values, false, true);
        $parsedHeaders = json_decode($renderedHeaders, true);
        if (!is_array($parsedHeaders)) {
            $parsedHeaders = [];
        }
        $headers = [];
        foreach ($parsedHeaders as $k => $v) {
            $headers[(string) $k] = (string) $v;
        }
        $hasContentType = false;
        foreach ($headers as $k => $_) {
            if (strtolower($k) === 'content-type') {
                $hasContentType = true;
            }
        }
        $allowsBody = $method !== 'GET' && $method !== 'HEAD';
        if ($allowsBody && !$hasContentType) {
            $headers['Content-Type'] = $contentType;
        }
        if (!$allowsBody) {
            foreach (array_keys($headers) as $k) {
                if (strtolower($k) === 'content-type') {
                    unset($headers[$k]);
                }
            }
        }
        return [
            'url' => trim($requestUrl),
            'method' => $method,
            'headers' => $headers,
            'body' => $allowsBody ? $renderedBody : null,
        ];
    }

    private static function normalizeTemplate(mixed $value, string $fallback): string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed !== '' ? $trimmed : $fallback;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $fallback;
        }
        return $fallback;
    }

    private static function isJson(string $template): bool
    {
        json_decode($template);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private static function render(string $template, array $values, bool $escapeJson = false, bool $escapeUrl = false): string
    {
        return (string) preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', static function (array $m) use ($values, $escapeJson, $escapeUrl) {
            $value = $values[$m[1]] ?? '';
            if ($escapeJson) {
                return substr(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
            }
            return $escapeUrl ? rawurlencode($value) : $value;
        }, $template);
    }
}
