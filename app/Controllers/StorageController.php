<?php
declare(strict_types=1);

namespace Rin\Controllers;

use Rin\Core\Context;
use Rin\Core\HttpException;
use Rin\Core\Response;

final class StorageController
{
    public static function upload(Context $ctx): Response
    {
        $ctx->requireUser();
        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw HttpException::text('File is required', 400);
        }
        $key = $_POST['key'] ?? ($file['name'] ?? 'file');
        $suffix = str_contains($key, '.') ? pathinfo($key, PATHINFO_EXTENSION) : '';
        $binary = (string) file_get_contents($file['tmp_name']);
        $hash = sha1($binary);
        $hashkey = $hash . '.' . $suffix;
        $storage = app_storage();
        $storageKey = $storage->put($hashkey, $binary);
        return Response::json(['url' => $storage->publicUrl($storageKey, $ctx->request->baseUrl())]);
    }

    public static function blob(Context $ctx, array $params): Response
    {
        $key = rawurldecode($params['key'] ?? '');
        if ($key === '') {
            throw HttpException::text('Blob key is required', 400);
        }
        $object = app_storage()->get($key);
        if (!$object) {
            throw HttpException::text('Not found', 404);
        }
        return Response::bytes($object['body'], $object['mime'], 200, [
            'Cache-Control' => 'public, max-age=31536000',
            'Content-Length' => (string) $object['size'],
        ]);
    }
}
