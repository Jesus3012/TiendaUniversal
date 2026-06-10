<?php
// ajax/mercadopago_consultar_orden.php
date_default_timezone_set('America/Mexico_City');

include('../includes/session.php');
require_once('../includes/mercadopago_config.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SESSION['rol'] !== 'administrador' && $_SESSION['rol'] !== 'vendedor') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

function responder_error($mensaje, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'message' => $mensaje]);
    exit;
}

function mp_get_order($order_id) {
    $ch = curl_init('https://api.mercadopago.com/v1/orders/' . rawurlencode($order_id));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . MP_ACCESS_TOKEN
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $raw = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new Exception('Error CURL Mercado Pago: ' . $curl_error);
    }

    $json = json_decode($raw, true);
    if ($http_code < 200 || $http_code >= 300) {
        $msg = $json['message'] ?? $json['error'] ?? $raw;
        throw new Exception('Mercado Pago HTTP ' . $http_code . ': ' . $msg);
    }

    return $json;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $order_id = trim($input['order_id'] ?? '');

    if ($order_id === '') {
        responder_error('Falta order_id.');
    }

    $order = mp_get_order($order_id);
    $payment = $order['transactions']['payments'][0] ?? [];

    echo json_encode([
        'success' => true,
        'order' => $order,
        'order_status' => $order['status'] ?? null,
        'payment_status' => $payment['status'] ?? null,
        'payment_status_detail' => $payment['status_detail'] ?? null,
        'payment_reference_id' => $payment['reference_id'] ?? null,
        'payment_method' => $payment['payment_method'] ?? null
    ]);
} catch (Throwable $e) {
    responder_error($e->getMessage(), 500);
}
