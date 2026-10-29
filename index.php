<?php

declare(strict_types=1);

require __DIR__ . '/agent/bootstrap.php';

$path = dodo_request_path();
$installed = dodo_config() !== null;

if ($path === '/api' || str_starts_with($path, '/api/')) {
    $apiPath = substr($path, strlen('/api'));
    require DODO_AGENT . '/api.php';
    exit;
}

if ($path === '/setup' || str_starts_with($path, '/setup/')) {
    if ($installed) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }

    $setupPath = substr($path, strlen('/setup'));
    require DODO_AGENT . '/setup.php';
    exit;
}

if (!$installed) {
    dodo_redirect('/setup');
}

require DODO_AGENT . '/app.php';
