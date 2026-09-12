<?php
declare(strict_types=1);

namespace Rin\Support;

final class Markdown
{
    public static function toHtml(string $markdown): string
    {
        $escaped = htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace('/```([\s\S]*?)```/', '<pre><code>$1</code></pre>', $escaped) ?? $escaped;
        $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
        $escaped = preg_replace('/^###### (.+)$/m', '<h6>$1</h6>', $escaped) ?? $escaped;
        $escaped = preg_replace('/^##### (.+)$/m', '<h5>$1</h5>', $escaped) ?? $escaped;
        $escaped = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $escaped) ?? $escaped;
        $escaped = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $escaped) ?? $escaped;
        $escaped = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $escaped) ?? $escaped;
        $escaped = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $escaped) ?? $escaped;
        $escaped = preg_replace('/!\[(.*?)\]\((.*?)\)/', '<img alt="$1" src="$2" />', $escaped) ?? $escaped;
        $escaped = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2">$1</a>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace('/^\s*[-*] (.+)$/m', '<li>$1</li>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?:<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $escaped) ?? $escaped;
        $parts = preg_split('/\n{2,}/', $escaped) ?: [$escaped];
        $html = [];
        foreach ($parts as $part) {
            $trim = trim($part);
            if ($trim === '') {
                continue;
            }
            if (preg_match('/^<(h\d|ul|pre|blockquote)/', $trim)) {
                $html[] = $trim;
            } else {
                $html[] = '<p>' . nl2br($trim) . '</p>';
            }
        }
        return implode("\n", $html);
    }

    public static function htmlToMarkdown(string $html): string
    {
        $html = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', "# $1\n\n", $html) ?? $html;
        $html = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "## $1\n\n", $html) ?? $html;
        $html = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "### $1\n\n", $html) ?? $html;
        $html = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<img[^>]*src=["\']([^"\']+)["\'][^>]*>/i', '![]($1)', $html) ?? $html;
        $html = preg_replace('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $html) ?? $html;
        $html = preg_replace('/<strong>(.*?)<\/strong>/is', '**$1**', $html) ?? $html;
        $html = preg_replace('/<b>(.*?)<\/b>/is', '**$1**', $html) ?? $html;
        $html = preg_replace('/<em>(.*?)<\/em>/is', '*$1*', $html) ?? $html;
        $html = preg_replace('/<li>(.*?)<\/li>/is', "- $1\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
