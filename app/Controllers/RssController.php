<?php
declare(strict_types=1);

namespace Rin\Controllers;

use Rin\Core\Context;
use Rin\Core\Response;
use Rin\Support\Rss as RssBuilder;

final class RssController
{
    public static function rss(Context $ctx): Response
    {
        return self::output($ctx, 'rss.xml', 'application/rss+xml; charset=UTF-8');
    }

    public static function atom(Context $ctx): Response
    {
        return self::output($ctx, 'atom.xml', 'application/atom+xml; charset=UTF-8');
    }

    public static function json(Context $ctx): Response
    {
        return self::output($ctx, 'rss.json', 'application/feed+json; charset=UTF-8');
    }

    public static function feedJson(Context $ctx): Response
    {
        return self::output($ctx, 'feed.json', 'application/feed+json; charset=UTF-8');
    }

    public static function legacy(): Response
    {
        return Response::redirect('/rss.xml', 301);
    }

    private static function output(Context $ctx, string $type, string $contentType): Response
    {
        $content = RssBuilder::generate($ctx, $type);
        return Response::bytes($content, $contentType, 200, [
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
