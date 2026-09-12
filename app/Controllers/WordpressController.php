<?php
declare(strict_types=1);

namespace Rin\Controllers;

use Rin\Core\Context;
use Rin\Core\HttpException;
use Rin\Core\Response;
use Rin\Support\Dates;
use Rin\Support\Helpers;
use Rin\Support\Markdown;

final class WordpressController
{
    public static function import(Context $ctx): Response
    {
        $ctx->requireAdmin();
        $xml = '';
        if (!empty($_FILES['data']['tmp_name'])) {
            $xml = (string) file_get_contents($_FILES['data']['tmp_name']);
        } else {
            $body = $ctx->request->json();
            $xml = (string) ($body['xml'] ?? '');
        }
        if ($xml === '') {
            throw HttpException::text('Data is required', 400);
        }
        $prev = libxml_use_internal_errors(true);
        $rss = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if ($rss === false || !isset($rss->channel->item)) {
            throw HttpException::text('No items found', 404);
        }
        $items = $rss->channel->item;
        $success = 0;
        $skipped = 0;
        $skippedList = [];
        foreach ($items as $item) {
            $ns = $item->children('http://wordpress.org/export/1.2/');
            $contentNs = $item->children('http://purl.org/rss/1.0/modules/content/');
            $created = strtotime((string) ($ns->post_date ?? $item->pubDate)) ?: Dates::now();
            $updated = strtotime((string) ($ns->post_modified ?? $item->pubDate)) ?: $created;
            $draft = ((string) ($ns->status ?? 'publish')) !== 'publish';
            $html = (string) ($contentNs->encoded ?? $item->description ?? '');
            $content = Markdown::htmlToMarkdown($html);
            $summary = mb_strlen($content) > 100 ? mb_substr($content, 0, 100) : $content;
            $title = (string) $item->title;
            $tags = [];
            foreach ($item->category as $cat) {
                $tags[] = (string) $cat;
            }
            if ($content === '') {
                $skippedList[] = ['title' => $title, 'reason' => 'no content'];
                $skipped++;
                continue;
            }
            $exist = $ctx->db->fetch('SELECT id FROM feeds WHERE content = :c', ['c' => $content]);
            if ($exist) {
                $skippedList[] = ['title' => $title, 'reason' => 'content exists'];
                $skipped++;
                continue;
            }
            $id = $ctx->db->insert(
                'INSERT INTO feeds (title, summary, content, listed, draft, uid, created_at, updated_at)
                 VALUES (:title, :summary, :content, 1, :draft, 1, :c, :u)',
                [
                    'title' => $title,
                    'summary' => $summary,
                    'content' => $content,
                    'draft' => $draft ? 1 : 0,
                    'c' => $created,
                    'u' => $updated,
                ]
            );
            if ($tags) {
                Helpers::bindTags($ctx->db, $id, $tags);
            }
            $success++;
        }
        $ctx->cache->deletePrefix('feeds_');
        return Response::json(['success' => $success, 'skipped' => $skipped, 'skippedList' => $skippedList]);
    }
}
