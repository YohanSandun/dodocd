<?php

declare(strict_types=1);

define('DODO_VERSION', '0.1.0');
define('DODO_ROOT', dirname(__DIR__));
define('DODO_AGENT', __DIR__);
define('DODO_CONFIG', __DIR__ . '/config.php');

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Dodo\\')) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('Dodo\\'))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/src/helpers.php';
