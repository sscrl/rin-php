<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "Rin PHP requires PHP 8.1 or newer.\n");
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Rin\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = __DIR__ . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

function app_storage(): Rin\Support\StorageService
{
    if (!isset($GLOBALS['rin_storage']) || !$GLOBALS['rin_storage'] instanceof Rin\Support\StorageService) {
        throw new RuntimeException('Storage is not initialized');
    }
    return $GLOBALS['rin_storage'];
}
