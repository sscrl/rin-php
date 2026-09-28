<?php
declare(strict_types=1);

namespace Rin\Support;

final class Images
{
    public static function extractImage(string $content): ?string
    {
        $url = self::extractImageWithMetadata($content);
        return $url === null ? null : explode('#', $url, 2)[0];
    }

    public static function extractImageWithMetadata(string $content): ?string
    {
        if (preg_match('/!\[.*?\]\((\S+?)(?:\s+"[^"]*")?\)/', $content, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function listMarkdownImageUrls(string $content): array
    {
        preg_match_all('/!\[.*?\]\((\S+?)(?:\s+"[^"]*")?\)/', $content, $m);
        return $m[1] ?? [];
    }

    public static function listHtmlImageUrls(string $content): array
    {
        preg_match_all('/<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $content, $m);
        return $m[1] ?? [];
    }

    public static function contentHasImagesMissingMetadata(string $content): bool
    {
        foreach ([...self::listMarkdownImageUrls($content), ...self::listHtmlImageUrls($content)] as $url) {
            if (self::missingDimensions($url)) {
                return true;
            }
        }
        return false;
    }

    public static function stripMediaMarkup(string $text): string
    {
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $text) ?? $text;
        $text = preg_replace('/<img\b[^>]*>/i', ' ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        return $text;
    }

    public static function blobKeyFromUrl(string $url): ?string
    {
        $path = parse_url(explode('#', $url, 2)[0], PHP_URL_PATH);
        if (!is_string($path) || !str_contains($path, '/api/blob/')) {
            return null;
        }
        $key = rawurldecode((string) preg_replace('#^.*/api/blob/#', '', $path));
        $key = trim($key, '/');
        return $key === '' ? null : $key;
    }

    public static function storageObjectFromUrl(string $url): ?array
    {
        $key = self::blobKeyFromUrl($url);
        if ($key === null) {
            return null;
        }
        try {
            $object = app_storage()->get($key);
        } catch (\Throwable) {
            return null;
        }
        return is_array($object) ? $object : null;
    }

    public static function enrichPublicImageUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $hashPos = strpos($url, '#');
        $base = $hashPos === false ? $url : substr($url, 0, $hashPos);
        $fragment = $hashPos === false ? '' : substr($url, $hashPos + 1);

        $key = self::blobKeyFromUrl($base);
        if ($key !== null) {
            $encoded = implode('/', array_map('rawurlencode', array_filter(explode('/', $key), static fn ($part) => $part !== '')));
            $base = '/api/blob/' . $encoded;
        }

        if (!self::missingDimensions($base . '#' . $fragment)) {
            return $fragment === '' ? $base : $base . '#' . $fragment;
        }

        $width = null;
        $height = null;
        if ($key !== null) {
            try {
                $path = app_storage()->path($key);
            } catch (\Throwable) {
                $path = '';
            }
            if ($path !== '' && is_file($path)) {
                $size = @getimagesize($path);
                if (!is_array($size) || ($size[0] ?? 0) <= 0 || ($size[1] ?? 0) <= 0) {
                    $binary = @file_get_contents($path);
                    $size = is_string($binary) && $binary !== '' ? @getimagesizefromstring($binary) : false;
                }
                if (is_array($size) && ($size[0] ?? 0) > 0 && ($size[1] ?? 0) > 0) {
                    $width = (int) $size[0];
                    $height = (int) $size[1];
                }
            }
        }

        if ($width === null || $height === null) {
            return $fragment === '' ? $base : $base . '#' . $fragment;
        }

        parse_str(str_replace('&amp;', '&', $fragment), $params);
        $params['width'] = (string) $width;
        $params['height'] = (string) $height;
        return $base . '#' . http_build_query($params);
    }

    public static function promoteSectionHeadings(string $content): string
    {
        return preg_replace(
            '/(^|\r?\n)\*\*([^*\r\n]{1,40})\*\*(?=\r?\n|$)/u',
            '$1## $2',
            $content
        ) ?? $content;
    }

    public static function enrichContentImages(string $content): string
    {
        $content = self::promoteSectionHeadings($content);
        $content = preg_replace_callback(
            '/(!\[.*?\]\()(\S+?)((?:\s+"[^"]*")?\))/',
            static function (array $match): string {
                return $match[1] . (self::enrichPublicImageUrl($match[2]) ?? $match[2]) . $match[3];
            },
            $content
        ) ?? $content;

        $content = preg_replace_callback(
            '/(<img\b[^>]*\bsrc=["\'])([^"\']+)(["\'])/i',
            static function (array $match): string {
                return $match[1] . (self::enrichPublicImageUrl($match[2]) ?? $match[2]) . $match[3];
            },
            $content
        ) ?? $content;

        return $content;
    }

    private static function missingDimensions(string $url): bool
    {
        $fragment = '';
        if (str_contains($url, '#')) {
            $fragment = explode('#', $url, 2)[1];
        }
        parse_str(str_replace('&amp;', '&', $fragment), $params);
        $width = (int) ($params['width'] ?? 0);
        $height = (int) ($params['height'] ?? 0);
        return $width <= 0 || $height <= 0;
    }
}
