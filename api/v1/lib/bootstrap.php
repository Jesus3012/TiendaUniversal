<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/audit.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Request-ID: ' . api_request_id());

set_exception_handler(function (Throwable $error): void {
    error_log(sprintf('[api][%s] %s', api_request_id(), $error->getMessage()));
    api_fail(500, 'INTERNAL_ERROR', 'Ocurrió un error interno controlado.');
});

