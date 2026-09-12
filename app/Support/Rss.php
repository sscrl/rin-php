<?php
declare(strict_types=1);

namespace Rin\Support;

use Rin\Core\Context;

final class Rss
{
    public static function generate(Context $ctx, string $type): string
    {
        $frontendUrl = $ctx->request->baseUrl();
        $title = (string) ($ctx->env['rss_title'] ?? '');
        if ($title === '') {
            $user = $ctx->db->fetch('SELECT username FROM users WHERE id = 1');
            $title = $user['username'] ?? 'Rin';
        }
        $desc = (string) ($ctx->env['rss_description'] ?? 'Feed from Rin');
        $feeds = $ctx->db->fetchAll(
            Helpers::feedSelectSql() . ' ' . Helpers::feedJoinSql() . '
             WHERE f.draft = 0 AND f.listed = 1
             ORDER BY f.created_at DESC, f.updated_at DESC
             LIMIT 20'
        );
        $storage = app_storage();
        $image = null;
        $favicon = null;
        foreach (['.jpg', '.png', '.gif', '.webp'] as $ext) {
            $key = $storage->join($storage->folder(), 'originFavicon' . $ext);
            if ($storage->exists($key)) {
                $image = $storage->publicUrl($key, $frontendUrl);
                break;
            }
        }
        $faviconKey = $storage->join($storage->folder(), 'favicon.webp');
        if ($storage->exists($faviconKey)) {
            $favicon = $storage->publicUrl($faviconKey, $frontendUrl);
        }

        if ($type === 'rss.json' || $type === 'feed.json') {
            $items = [];
            foreach ($feeds as $feed) {
                $items[] = [
                    'id' => (string) $feed['id'],
                    'url' => $frontendUrl . '/feed/' . $feed['id'],
                    'title' => $feed['title'] ?: 'No title',
                    'content_html' => Markdown::toHtml((string) $feed['content']),
                    'summary' => Helpers::summary((string) $feed['summary'], (string) $feed['content']),
                    'date_published' => Dates::iso($feed['created_at']),
                    'authors' => [['name' => $feed['user_name']]],
                    'image' => Images::extractImage((string) $feed['content']),
                ];
            }
            return json_encode([
                'version' => 'https://jsonfeed.org/version/1',
                'title' => $title,
                'home_page_url' => $frontendUrl,
                'feed_url' => $frontendUrl . '/' . $type,
                'description' => $desc,
                'icon' => $image,
                'favicon' => $favicon,
                'items' => $items,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        $isAtom = $type === 'atom.xml';
        $updated = Dates::iso(time());
        $itemsXml = '';
        foreach ($feeds as $feed) {
            $link = htmlspecialchars($frontendUrl . '/feed/' . $feed['id'], ENT_QUOTES);
            $itemTitle = htmlspecialchars((string) ($feed['title'] ?: 'No title'), ENT_QUOTES);
            $summary = htmlspecialchars(Helpers::summary((string) $feed['summary'], (string) $feed['content']), ENT_QUOTES);
            $html = Markdown::toHtml((string) $feed['content']);
            $date = Dates::iso($feed['created_at']);
            $author = htmlspecialchars((string) $feed['user_name'], ENT_QUOTES);
            if ($isAtom) {
                $itemsXml .= "<entry><title>{$itemTitle}</title><id>{$link}</id><link href=\"{$link}\"/><updated>{$date}</updated><summary>{$summary}</summary><content type=\"html\">" . htmlspecialchars($html, ENT_QUOTES) . "</content><author><name>{$author}</name></author></entry>";
            } else {
                $itemsXml .= "<item><title>{$itemTitle}</title><link>{$link}</link><guid>{$link}</guid><pubDate>" . gmdate(DATE_RSS, (int) $feed['created_at']) . "</pubDate><description>{$summary}</description><content:encoded><![CDATA[{$html}]]></content:encoded><author>{$author}</author></item>";
            }
        }

        $escTitle = htmlspecialchars($title, ENT_QUOTES);
        $escDesc = htmlspecialchars($desc, ENT_QUOTES);
        if ($isAtom) {
            return '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom"><title>' . $escTitle . '</title><link href="' . htmlspecialchars($frontendUrl, ENT_QUOTES) . '"/><updated>' . $updated . '</updated><id>' . htmlspecialchars($frontendUrl, ENT_QUOTES) . '</id><subtitle>' . $escDesc . '</subtitle><generator>Feed from Rin</generator>' . $itemsXml . '</feed>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>' . $escTitle . '</title><link>' . htmlspecialchars($frontendUrl, ENT_QUOTES) . '</link><description>' . $escDesc . '</description><generator>Feed from Rin</generator>' . $itemsXml . '</channel></rss>';
    }
}
