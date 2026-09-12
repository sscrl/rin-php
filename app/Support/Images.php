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
            $parts = explode('#', $url, 2);
            $fragment = $parts[1] ?? '';
            parse_str(str_replace('&amp;', '&', $fragment), $params);
            if (empty($params['blurhash']) || empty($params['width']) || empty($params['height'])) {
                return true;
            }
        }
        return false;
    }
}
