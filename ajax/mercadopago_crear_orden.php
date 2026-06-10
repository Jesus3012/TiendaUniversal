<?php
// ajax/mercadopago_crear_orden.php
date_default_timezone_set('America/Mexico_City');

include('../includes/db.php');
include('../includes/session.php');
require_once('../includes/mercadopago_config.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SESSION['rol'] !== 'administrador' && $_SESSION['rol'] !== 'vendedor') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

function responder_error($mensaje, $http = 400, $extra = []) {
    http_response_code($http);
    echo json_encode(array_merge(['success' => false, 'message' => $mensaje], $extra));
    exit;
}

function mp_request($method, $endpoint, $body = null, $idempotency = false) {
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . MP_ACCESS_TOKEN
    ];

    if ($idempotency) {
        $headers[] = 'X-Idempotency-Key: ' . bin2hex(random_bytes(16));
    }

    $ch = curl_init('https://api.mercadopago.com' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 40
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }

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
    if (!is_array($input)) responder_error('JSON inválido.');

    $carrito = $input['carrito'] ?? [];
    $metodo_pago = $input['metodo_pago'] ?? '';
    $total_cliente = round((float)($input['total'] ?? 0), 2);

    if (!in_array($metodo_pago, ['tarjeta_debito', 'tarjeta_credito'], true)) {
        responder_error('Método de pago inválido para Mercado Pago.');
    }

    if (empty($carrito) || $total_cliente <= 0) {
        responder_error('Carrito vacío o total inválido.');
    }

    // Recalcular total con precios reales en BD para evitar manipulación desde navegador.
    $total_bd = 0.0;
    foreach ($carrito as $item) {
        $id = (int)($item['id'] ?? 0);
        $cantidad = (int)($item['cantidad'] ?? 0);

        if ($id <= 0 || $cantidad <= 0) {
            responder_error('Producto o cantidad inválida.');
        }

        $stmt = $conn->prepare('SELECT nombre, precio_venta, cantidad FROM productos WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $producto = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$producto) {
            responder_error('Producto no encontrado: ' . $id);
        }

        if ((int)$producto['cantidad'] < $cantidad) {
            responder_error('Stock insuficiente para: ' . $producto['nombre']);
        }

        $total_bd += ((float)$producto['precio_venta']) * $cantidad;
    }

    $total_bd = round($total_bd, 2);

    if (abs($total_bd - $total_cliente) > 0.01) {
        responder_error('El total del carrito no coincide con la base de datos. Actualiza la venta.');
    }

    $default_type = $metodo_pago === 'tarjeta_credito' ? 'credit_card' : 'debit_card';
    $external_reference = 'POS_' . date('YmdHis') . '_' . ($_SESSION['usuario_id'] ?? '0') . '_' . random_int(1000, 9999);

    $body = [
        'type' => 'point',
        'external_reference' => $external_reference,
        'description' => 'Venta punto de venta',
        'expiration_time' => 'PT3M',
        'config' => [
            'point' => [
                'terminal_id' => MP_TERMINAL_ID,
                'print_on_terminal' => MP_PRINT_ON_TERMINAL
            ],
            'payment_method' => [
                'default_type' => $default_type
            ]
        ],
        'transactions' => [
            'payments' => [
                [
                    'amount' => number_format($total_bd, 2, '.', '.')
                ]
            ]
        ]
    ];

    $order = mp_request('POST', '/v1/orders', $body, true);
    $payment = $order['transactions']['payments'][0] ?? [];

    echo json_encode([
        'success' => true,
        'order_id' => $order['id'] ?? null,
        'payment_id' => $payment['id'] ?? null,
        'status' => $order['status'] ?? null,
        'order' => $order
    ]);
} catch (Throwable $e) {
    responder_error($e->getMessage(), 500);
}
