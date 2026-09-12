<?php
declare(strict_types=1);

namespace Rin\Support;

final class Auth
{
    public static function hashPassword(string $password): string
    {
        return hash('sha256', $password);
    }

    public static function cookieOptions(bool $secure): array
    {
        return [
            'expires' => time() + 60 * 60 * 24 * 7,
            'path' => '/',
            'httpOnly' => true,
            'secure' => $secure,
            'sameSite' => 'Lax',
        ];
    }
}
