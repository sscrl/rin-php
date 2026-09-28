<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/autoload.php';

if (!Rin\Support\Installer::isInstalled($root)) {
    echo "not installed\n";
    exit(0);
}

(new Rin\Core\App($root))->cron();
echo "friend health check done\n";
