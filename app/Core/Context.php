<?php
declare(strict_types=1);

namespace Rin\Core;

use Rin\Support\ConfigStore;
use Rin\Support\Database;

final class Context
{
    public function __construct(
        public readonly Request $request,
        public readonly Database $db,
        public readonly array $env,
        public readonly ConfigStore $clientConfig,
        public readonly ConfigStore $serverConfig,
        public readonly ConfigStore $cache,
        public ?int $uid = null,
        public ?string $username = null,
        public bool $admin = false,
    ) {
    }

    public function requireUser(): int
    {
        if ($this->uid === null) {
            throw HttpException::text('Unauthorized', 401);
        }
        return $this->uid;
    }

    public function requireAdmin(): int
    {
        $uid = $this->requireUser();
        if (!$this->admin) {
            throw HttpException::text('Permission denied', 403);
        }
        return $uid;
    }
}
