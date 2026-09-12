<?php
declare(strict_types=1);

namespace Rin\Support;

final class Jwt
{
    public function __construct(private string $secret)
    {
        if ($this->secret === '') {
            throw new \InvalidArgumentException("Secret can't be empty");
        }
    }

    public function sign(array $payload): string
    {
        $header = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $payload['iat'] = $payload['iat'] ?? time();
        $body = $this->b64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $sig = $this->b64(hash_hmac('sha256', $header . '.' . $body, $this->secret, true));
        return $header . '.' . $body . '.' . $sig;
    }

    public function verify(?string $jwt): array|false
    {
        if (!$jwt) {
            return false;
        }
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }
        [$header, $body, $sig] = $parts;
        $expected = $this->b64(hash_hmac('sha256', $header . '.' . $body, $this->secret, true));
        if (!hash_equals($expected, $sig)) {
            return false;
        }
        $payload = json_decode($this->ub64($body), true);
        return is_array($payload) ? $payload : false;
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function ub64(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
