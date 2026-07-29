<?php
declare(strict_types=1);

function api_payment_create_order(mysqli $db, array $auth): void
{
    api_require_module($db, $auth, 'ventas');
    $body = api_json_body();
    api_require_fields($body, ['sale_uuid','idempotency_key']);
    $saleUuid = api_assert_uuid($body['sale_uuid'], 'sale_uuid');
    $key = (string) $body['idempotency_key'];
    if (strlen($key) !== 64) api_fail(422, 'VALIDATION_ERROR', 'Idempotency key inválida.');
    $stmt = $db->prepare(
        'SELECT id,folio,total FROM ventas_cabecera WHERE uuid=?'
    );
    $stmt->bind_param('s', $saleUuid); $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();
    if (!$sale) api_fail(404, 'SALE_NOT_FOUND', 'Venta no encontrada.');
    $stmt = $db->prepare('SELECT * FROM mercadopago_operaciones WHERE external_reference=? LIMIT 1');
    $stmt->bind_param('s', $key); $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) api_ok(['order'=>$existing,'idempotent'=>true]);
    $remote = api_mp_request('POST', '/v1/orders', [
        'external_reference'=>$key,
        'total_amount'=>number_format((float) $sale['total'], 2, '.', ''),
    ], $key);
    $orderId = (string) ($remote['id'] ?? '');
    if ($orderId === '') api_fail(502, 'PAYMENT_PROVIDER_ERROR', 'Mercado Pago no devolvió order_id.', ['provider'=>$remote]);
    $status = (string) ($remote['status'] ?? 'created');
    $raw = json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare(
        'INSERT INTO mercadopago_operaciones
         (folio_ticket,usuario_id,external_reference,order_id,amount,order_status,raw_order_json)
         VALUES(?,?,?,?,?,?,?)'
    );
    $stmt->bind_param('sissdss', $sale['folio'], $auth['user_id'], $key, $orderId, $sale['total'], $status, $raw);
    $stmt->execute();
    api_audit($db, (int) $auth['user_id'], 'api.payment_order_created', ['sale_uuid'=>$saleUuid,'order_id'=>$orderId]);
    api_ok(['order'=>['order_id'=>$orderId,'status'=>$status],'idempotent'=>false], 201);
}

function api_payment_check_order(mysqli $db, array $auth, string $orderId): void
{
    api_require_module($db, $auth, 'ventas');
    $stmt = $db->prepare('SELECT * FROM mercadopago_operaciones WHERE order_id=?');
    $stmt->bind_param('s', $orderId); $stmt->execute();
    $operation = $stmt->get_result()->fetch_assoc();
    if (!$operation) api_fail(404, 'PAYMENT_ORDER_NOT_FOUND', 'Orden no encontrada.');
    $remote = api_mp_request('GET', '/v1/orders/' . rawurlencode($orderId));
    $orderStatus = (string) ($remote['status'] ?? 'unknown');
    $payment = $remote['transactions']['payments'][0] ?? [];
    $paymentStatus = (string) ($payment['status'] ?? 'pending');
    $approved = in_array($paymentStatus, ['approved','processed'], true);
    $paid = $approved ? (float) ($payment['paid_amount'] ?? $operation['amount']) : 0.0;
    $raw = json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare(
        'UPDATE mercadopago_operaciones SET order_status=?,payment_status=?,
         payment_id=?,paid_amount=?,raw_order_json=? WHERE order_id=?'
    );
    $paymentId = $payment['id'] ?? null;
    $stmt->bind_param('sssdss', $orderStatus, $paymentStatus, $paymentId, $paid, $raw, $orderId);
    $stmt->execute();
    api_ok(['order_id'=>$orderId,'order_status'=>$orderStatus,'payment_status'=>$paymentStatus,'confirmed_paid'=>$approved]);
}

function api_payment_create_refund(mysqli $db, array $auth): void
{
    api_require_module($db, $auth, 'ventas');
    $body = api_json_body();
    api_require_fields($body, ['sale_uuid','correction_uuid','idempotency_key']);
    $saleUuid = api_assert_uuid($body['sale_uuid'], 'sale_uuid');
    $correctionUuid = api_assert_uuid($body['correction_uuid'], 'correction_uuid');
    $key = (string) $body['idempotency_key'];
    $stmt = $db->prepare(
        'SELECT c.amount,h.folio,o.id operation_id,o.order_id
         FROM sale_corrections c
         JOIN ventas_cabecera h ON h.uuid=c.sale_uuid
         JOIN mercadopago_operaciones o ON o.folio_ticket=h.folio
         WHERE c.uuid=? AND c.sale_uuid=?'
    );
    $stmt->bind_param('ss', $correctionUuid, $saleUuid); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) api_fail(409, 'PAYMENT_OPERATION_REQUIRED', 'No existe orden pagada para reembolsar.');
    $stmt = $db->prepare('SELECT * FROM mercadopago_reembolsos WHERE idempotency_key=?');
    $stmt->bind_param('s', $key); $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) api_ok(['refund'=>$existing,'idempotent'=>true]);
    $remote = api_mp_request('POST', '/v1/orders/' . rawurlencode($row['order_id']) . '/refunds', [
        'amount'=>number_format((float) $row['amount'], 2, '.', ''),
    ], $key);
    $refundId = (string) ($remote['id'] ?? '');
    $status = (string) ($remote['status'] ?? 'pending');
    if ($refundId === '') api_fail(502, 'PAYMENT_PROVIDER_ERROR', 'Mercado Pago no confirmó el reembolso.', ['provider'=>$remote]);
    $raw = json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $action = $row['amount'] > 0 ? 'reembolso_parcial' : 'reembolso_total';
    $stmt = $db->prepare(
        'INSERT INTO mercadopago_reembolsos
         (folio_ticket,mercadopago_operacion_id,order_id,refund_id,accion,monto,status,
          idempotency_key,motivo,usuario_id,raw_response_json)
         VALUES(?,?,?,?,?,?,?,?,?,?,?)'
    );
    $reason = 'Corrección ' . $correctionUuid;
    $stmt->bind_param('sisssdsssis', $row['folio'], $row['operation_id'], $row['order_id'], $refundId, $action, $row['amount'], $status, $key, $reason, $auth['user_id'], $raw);
    $stmt->execute();
    api_ok(['refund'=>['refund_id'=>$refundId,'status'=>$status],'idempotent'=>false], 201);
}

function api_payment_check_refund(mysqli $db, array $auth, string $refundId): void
{
    api_require_module($db, $auth, 'ventas');
    $stmt = $db->prepare('SELECT order_id FROM mercadopago_reembolsos WHERE refund_id=?');
    $stmt->bind_param('s', $refundId); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) api_fail(404, 'REFUND_NOT_FOUND', 'Reembolso no encontrado.');
    $remote = api_mp_request('GET', '/v1/orders/' . rawurlencode($row['order_id']) . '/refunds/' . rawurlencode($refundId));
    $status = (string) ($remote['status'] ?? 'manual_review');
    $raw = json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare('UPDATE mercadopago_reembolsos SET status=?,raw_response_json=? WHERE refund_id=?');
    $stmt->bind_param('sss', $status, $raw, $refundId); $stmt->execute();
    api_ok(['refund_id'=>$refundId,'status'=>$status,'confirmed'=>in_array($status,['approved','processed','refunded'],true)]);
}

function api_mp_request(string $method, string $path, ?array $body = null, ?string $key = null): array
{
    if (getenv('TP_MP_FAKE') === '1') {
        $id = substr(hash('sha256', $key ?: $path), 0, 20);
        if (strpos($path, '/refunds') !== false) return ['id'=>'refund-'.$id,'status'=>'processed'];
        return ['id'=>'order-'.$id,'status'=>'processed','transactions'=>['payments'=>[['id'=>'payment-'.$id,'status'=>'approved','paid_amount'=>$body['total_amount'] ?? 0]]]];
    }
    $token = getenv('TP_MP_ACCESS_TOKEN') ?: '';
    if ($token === '') api_fail(503, 'PAYMENT_PROVIDER_NOT_CONFIGURED', 'Mercado Pago no está configurado.');
    $curl = curl_init('https://api.mercadopago.com' . $path);
    $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
    if ($key) $headers[] = 'X-Idempotency-Key: ' . $key;
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>20,
    ]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false || $status < 200 || $status >= 300) {
        api_fail(502, 'PAYMENT_PROVIDER_ERROR', $error ?: 'Error de Mercado Pago.', ['http_status'=>$status]);
    }
    return json_decode($response, true) ?: [];
}
