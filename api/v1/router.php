<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/controllers/auth.php';
require_once __DIR__ . '/controllers/devices.php';
require_once __DIR__ . '/controllers/sync.php';
require_once __DIR__ . '/controllers/catalog.php';
require_once __DIR__ . '/controllers/payments.php';
require_once __DIR__ . '/controllers/reports.php';
require_once __DIR__ . '/controllers/updates.php';

$db = api_db();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$prefix = '/api/v1';
$prefixPosition = strpos($path, $prefix);
if ($prefixPosition !== false) {
    $path = substr($path, $prefixPosition + strlen($prefix));
}
$path = '/' . trim($path, '/');

if ($method === 'GET' && $path === '/health') {
    $db->query('SELECT 1');
    api_ok(['status' => 'healthy', 'version' => 'v1', 'time' => gmdate('c')]);
}
if ($method === 'GET' && $path === '/updates/manifest') {
    api_rate_limit($db, 'updates-manifest', 60, 60);
    api_update_manifest($db);
}
if ($method === 'POST' && $path === '/auth/login') {
    api_login($db);
}
if ($method === 'POST' && $path === '/auth/refresh') {
    api_refresh($db);
}

$auth = api_authenticate($db);
api_rate_limit($db, 'authenticated', 240, 60);

if ($method === 'POST' && $path === '/auth/logout') {
    api_logout($db, $auth);
}
if ($method === 'GET' && $path === '/auth/session') {
    api_session($db, $auth);
}
if ($method === 'POST' && $path === '/auth/change-password') {
    api_change_password($db, $auth);
}
if ($method === 'POST' && $path === '/auth/legal-acceptance') {
    api_accept_legal($db, $auth);
}
if ($method === 'POST' && $path === '/devices/register') {
    api_register_device($db, $auth);
}
if ($method === 'POST' && $path === '/devices/heartbeat') {
    api_device_heartbeat($db, $auth);
}
if ($method === 'POST' && preg_match('#^/devices/([0-9a-f-]+)/revoke$#i', $path, $matches)) {
    api_revoke_device($db, $auth, $matches[1]);
}
if ($method === 'GET' && $path === '/bootstrap') {
    api_bootstrap_catalogs($db, $auth);
}
if ($method === 'POST' && $path === '/sync/push') {
    api_sync_push($db, $auth);
}
if ($method === 'GET' && $path === '/sync/pull') {
    api_sync_pull($db, $auth);
}
if ($method === 'POST' && $path === '/sync/ack') {
    api_sync_ack($db, $auth);
}
if ($method === 'GET' && $path === '/sync/status') {
    api_sync_status($db, $auth);
}
if ($method === 'POST' && $path === '/catalog/images') {
    api_upload_product_image($db, $auth);
}
if ($method === 'POST' && $path === '/payments/orders') {
    api_payment_create_order($db, $auth);
}
if ($method === 'GET' && preg_match('#^/payments/orders/([^/]+)$#', $path, $matches)) {
    api_payment_check_order($db, $auth, $matches[1]);
}
if ($method === 'POST' && $path === '/payments/refunds') {
    api_payment_create_refund($db, $auth);
}
if ($method === 'GET' && preg_match('#^/payments/refunds/([^/]+)$#', $path, $matches)) {
    api_payment_check_refund($db, $auth, $matches[1]);
}
if ($method === 'GET' && $path === '/reports/summary') {
    api_report_summary($db, $auth);
}

api_fail(404, 'ROUTE_NOT_FOUND', 'La ruta solicitada no existe.');
