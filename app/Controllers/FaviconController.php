<?php
declare(strict_types=1);

namespace Rin\Controllers;

use Rin\Core\Context;
use Rin\Core\HttpException;
use Rin\Core\Response;
use Rin\Support\Favicon as FaviconHelper;
use Rin\Support\Helpers;

final class FaviconController
{

    public static function siteAvatar(Context $ctx): Response
    {
        $url = Helpers::siteAvatar($ctx);
        if ($url !== '' && !preg_match('#/avatar\\.png(\?.*)?$#i', $url)) {
            if (str_contains($url, '/api/blob/')) {
                $path = parse_url($url, PHP_URL_PATH) ?: $url;
                $key = rawurldecode((string) preg_replace('#^.*/api/blob/#', '', $path));
                $object = app_storage()->get($key);
                if ($object) {
                    return Response::bytes($object['body'], $object['mime'], 200, [
                        'Cache-Control' => 'public, max-age=3600',
                    ]);
                }
            }
            if (preg_match('#^(https?:)?//#i', $url) || str_starts_with($url, '/')) {
                return Response::redirect($url, 302);
            }
        }
        $im = imagecreatetruecolor(128, 128);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $bg = imagecolorallocate($im, 252, 70, 107);
        imagefilledrectangle($im, 0, 0, 128, 128, $bg);
        ob_start();
        imagepng($im);
        $out = (string) ob_get_clean();
        imagedestroy($im);
        return Response::bytes($out, 'image/png', 200, [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
    public static function show(Context $ctx): Response
    {
        $storage = app_storage();
        $key = $storage->join($storage->folder(), 'favicon.webp');
        $object = $storage->get($key);
        if (!$object) {
            foreach (FaviconHelper::TYPES as $mime => $ext) {
                $origin = $storage->join($storage->folder(), 'originFavicon' . $ext);
                $source = $storage->get($origin);
                if ($source) {
                    $webp = FaviconHelper::resizeToWebp($source['body']);
                    $storage->putAtKey($key, $webp);
                    $object = $storage->get($key);
                    break;
                }
            }
        }
        if (!$object) {
            $fallback = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'favicon.png';
            if (is_file($fallback)) {
                return Response::bytes((string) file_get_contents($fallback), 'image/png', 200, [
                    'Cache-Control' => 'public, max-age=31536000',
                ]);
            }
            throw HttpException::text('Not found', 404);
        }
        return Response::bytes($object['body'], 'image/webp', 200, [
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

    public static function original(Context $ctx): Response
    {
        $storage = app_storage();
        foreach (FaviconHelper::TYPES as $mime => $ext) {
            $origin = $storage->join($storage->folder(), 'originFavicon' . $ext);
            $object = $storage->get($origin);
            if ($object) {
                return Response::bytes($object['body'], $mime, 200, [
                    'Cache-Control' => 'public, max-age=31536000',
                ]);
            }
        }
        throw HttpException::text('Original favicon not found', 404);
    }

    public static function upload(Context $ctx): Response
    {
        $ctx->requireAdmin();
        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw HttpException::text('No file uploaded', 400);
        }
        if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
            throw HttpException::text('File size exceeds limit (10MB)', 400);
        }
        $type = $file['type'] ?: (mime_content_type($file['tmp_name']) ?: '');
        if (!isset(FaviconHelper::TYPES[$type])) {
            throw HttpException::text('Disallowed file type', 400);
        }
        $binary = (string) file_get_contents($file['tmp_name']);
        $storage = app_storage();
        $originKey = $storage->join($storage->folder(), 'originFavicon' . FaviconHelper::TYPES[$type]);
        $storage->putAtKey($originKey, $binary);
        $faviconKey = $storage->join($storage->folder(), 'favicon.webp');
        $storage->putAtKey($faviconKey, FaviconHelper::resizeToWebp($binary));
        return Response::json(['url' => $storage->publicUrl($faviconKey, $ctx->request->baseUrl())]);
    }
}
