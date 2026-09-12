<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/autoload.php';

(new Rin\Core\App($root))->cron();
echo "friend health check done\n";
