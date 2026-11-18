<?php
/**
 * Front controller: every request that is not a real file ends up here
 * (root .htaccess rewrite on the hosting, FallbackResource in the dev container).
 */
require __DIR__ . '/bootstrap.php';

try {
    $App = new WebApplication\Application();
} catch (Throwable $e) {
    // configuration missing/broken — the error handler is not registered yet
    error_log('WebApplication start failed: ' . $e->getMessage());
    http_response_code(500);
    echo WebApplication\ErrorHandler::page(500, 'Something went wrong');
    exit;
}
$App->run();
