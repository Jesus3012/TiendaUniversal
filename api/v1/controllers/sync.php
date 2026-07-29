<?php
declare(strict_types=1);

function api_bootstrap_catalogs(mysqli $db, array $auth): void
{
    api_require_module($db, $auth, 'productos');
    $limit = api_query_int('limit', 200, 1, 500);
    $offset = api_query_int('offset', 0, 0, 1000000);
    $products = $db->query(
        "SELECT uuid,nombre,categoria,atributos,proveedor_id,cantidad,
                precio_compra,precio_venta,activo,tipo_inventario,version,updated_at,deleted_at
         FROM productos ORDER BY id LIMIT $limit OFFSET $offset"
    )->fetch_all(MYSQLI_ASSOC);
    $providers = $db->query(
        'SELECT uuid,nombre,correo,telefono,activo,version,updated_at,deleted_at
         FROM proveedores ORDER BY nombre'
    )->fetch_all(MYSQLI_ASSOC);
    $barcodes = $db->query(
        'SELECT uuid,producto_id,codigo,disponible,version,updated_at,deleted_at
         FROM codigos_barras ORDER BY id'
    )->fetch_all(MYSQLI_ASSOC);
    $modules = $db->query(
        'SELECT uuid,clave,nombre,activo,version,updated_at,deleted_at
         FROM modulos_sistema ORDER BY orden'
    )->fetch_all(MYSQLI_ASSOC);
    $revision = (int) ($db->query('SELECT COALESCE(MAX(server_revision),0) FROM change_log')->fetch_row()[0]);
    api_ok([
        'catalogs' => [
            'products' => $products,
            'providers' => $providers,
            'barcodes' => $barcodes,
            'modules' => $modules,
        ],
        'page' => ['limit' => $limit, 'offset' => $offset, 'count' => count($products)],
        'cursor' => $revision,
    ]);
}

function api_sync_push(mysqli $db, array $auth): void
{
    $body = api_json_body();
    api_require_fields($body, ['operations']);
    if (!is_array($body['operations']) || count($body['operations']) < 1 || count($body['operations']) > 100) {
        api_fail(422, 'INVALID_BATCH', 'El lote debe contener entre 1 y 100 operaciones.');
    }
    foreach ($body['operations'] as $candidate) {
        $type = is_array($candidate) ? ($candidate['aggregate_type'] ?? '') : '';
        $module = in_array($type, ['venta', 'caja_sesion', 'caja_movimiento', 'correccion_venta'], true)
            ? 'ventas'
            : (in_array($type, ['configuracion','rol','usuario','documento_legal'], true)
                ? 'administracion'
                : ($type === 'pedido' || $type === 'proveedor' ? 'proveedores' : 'productos'));
        api_require_module($db, $auth, $module);
    }
    if (!$auth['device_id']) {
        api_fail(409, 'DEVICE_REQUIRED', 'Registre el dispositivo antes de sincronizar.');
    }
    $results = [];
    foreach ($body['operations'] as $operation) {
        if (!is_array($operation)) {
            api_fail(422, 'VALIDATION_ERROR', 'Cada operación debe ser un objeto.');
        }
        $aggregateType = $operation['aggregate_type'] ?? '';
        $results[] = in_array($aggregateType, ['configuracion','rol','usuario','documento_legal'], true)
            ? api_apply_administration_operation($db, $auth, $operation)
            : ($aggregateType === 'correccion_venta'
            ? api_apply_sale_correction_operation($db, $auth, $operation)
            : ($aggregateType === 'caja_sesion'
            ? api_apply_cash_session_operation($db, $auth, $operation)
            : ($aggregateType === 'caja_movimiento'
                ? api_apply_cash_movement_operation($db, $auth, $operation)
                : ($aggregateType === 'inventario_movimiento'
            ? api_apply_inventory_operation($db, $auth, $operation)
            : ($aggregateType === 'pedido'
                ? api_apply_order_operation($db, $auth, $operation)
                : ($aggregateType === 'venta'
            ? api_apply_sale_operation($db, $auth, $operation)
            : ($aggregateType === 'proveedor'
            ? api_apply_provider_operation($db, $auth, $operation)
            : ($aggregateType === 'asignacion_producto'
                ? api_apply_assignment_operation($db, $auth, $operation)
                : api_apply_product_operation($db, $auth, $operation)))))))));
    }
    api_ok(['results' => $results]);
}

function api_apply_administration_operation(mysqli $db, array $auth, array $operation): array
{
    api_require_module($db, $auth, 'administracion');
    api_require_fields($operation, ['operation_id','aggregate_type','aggregate_uuid','payload']);
    $operationId = api_assert_uuid($operation['operation_id'], 'operation_id');
    $type = (string) $operation['aggregate_type'];
    $uuid = (string) $operation['aggregate_uuid'];
    $payload = $operation['payload'];
    if (!is_array($payload)) {
        api_fail(422, 'VALIDATION_ERROR', 'El payload administrativo no es válido.');
    }
    $hash = hash(
        'sha256',
        json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );
    $existing = $db->prepare('SELECT request_hash,response_json FROM processed_operations WHERE operation_id=?');
    $existing->bind_param('s', $operationId);
    $existing->execute();
    $row = $existing->get_result()->fetch_assoc();
    if ($row) {
        if (!hash_equals($row['request_hash'], $hash)) {
            api_fail(409, 'IDEMPOTENCY_MISMATCH', 'El operation_id ya fue usado con otro contenido.');
        }
        return json_decode($row['response_json'], true);
    }
    $db->begin_transaction();
    try {
        if ($type === 'configuracion') {
            if (!in_array($uuid, ['store','security','cancellations','email','prices'], true)) {
                api_fail(422, 'SETTING_INVALID', 'Configuración no permitida.');
            }
            foreach (array_keys($payload) as $field) {
                if (preg_match('/password|secret|token/i', (string) $field)) {
                    api_fail(422, 'SENSITIVE_SETTING_FORBIDDEN', 'Los secretos no se sincronizan.');
                }
            }
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $stmt = $db->prepare(
                'INSERT INTO administration_settings(setting_key,value_json,updated_by,updated_at)
                 VALUES(?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),
                 version=version+1,updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)'
            );
            $stmt->bind_param('ssi', $uuid, $json, $auth['user_id']);
            $stmt->execute();
        } elseif ($type === 'rol') {
            $roleUuid = api_assert_uuid($uuid, 'aggregate_uuid');
            $name = trim((string) ($payload['name'] ?? ''));
            $modules = json_encode(array_values(array_unique($payload['modules'] ?? [])), JSON_THROW_ON_ERROR);
            $active = !empty($payload['active']) ? 1 : 0;
            if ($name === '') api_fail(422, 'ROLE_INVALID', 'Nombre de rol requerido.');
            $stmt = $db->prepare(
                'INSERT INTO administration_roles(uuid,name,modules_json,active,updated_at)
                 VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE name=VALUES(name),
                 modules_json=VALUES(modules_json),active=VALUES(active),version=version+1,
                 updated_at=VALUES(updated_at)'
            );
            $stmt->bind_param('sssi', $roleUuid, $name, $modules, $active);
            $stmt->execute();
        } elseif ($type === 'usuario') {
            $userUuid = api_assert_uuid($uuid, 'aggregate_uuid');
            $roleUuid = api_assert_uuid($payload['roleUuid'] ?? '', 'roleUuid');
            $name = trim((string) ($payload['name'] ?? ''));
            $email = strtolower(trim((string) ($payload['email'] ?? '')));
            $active = !empty($payload['active']) ? 1 : 0;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
                api_fail(422, 'USER_INVALID', 'Usuario inválido.');
            }
            $stmt = $db->prepare(
                'INSERT INTO administration_users(uuid,name,email,role_uuid,active,updated_at)
                 VALUES(?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE name=VALUES(name),
                 email=VALUES(email),role_uuid=VALUES(role_uuid),active=VALUES(active),
                 version=version+1,updated_at=VALUES(updated_at)'
            );
            $stmt->bind_param('ssssi', $userUuid, $name, $email, $roleUuid, $active);
            $stmt->execute();
        } else {
            $documentUuid = api_assert_uuid($uuid, 'aggregate_uuid');
            $version = trim((string) ($payload['version'] ?? ''));
            $title = trim((string) ($payload['title'] ?? ''));
            $body = trim((string) ($payload['body'] ?? ''));
            $active = !empty($payload['active']) ? 1 : 0;
            if ($active) $db->query('UPDATE legal_documents SET active=0');
            $stmt = $db->prepare(
                'INSERT INTO legal_documents(uuid,version,title,body,active,published_at)
                 VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))'
            );
            $stmt->bind_param('ssssi', $documentUuid, $version, $title, $body, $active);
            $stmt->execute();
        }
        $response = ['operation_id' => $operationId, 'status' => 'applied', 'aggregate_uuid' => $uuid];
        $responseJson = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $db->prepare(
            'INSERT INTO processed_operations(operation_id,device_id,aggregate_type,aggregate_uuid,request_hash,response_json)
             VALUES(?,?,?,?,?,?)'
        );
        $stmt->bind_param('ssssss', $operationId, $auth['device_id'], $type, $uuid, $hash, $responseJson);
        $stmt->execute();
        api_audit($db, (int) $auth['user_id'], 'api.administration_applied', [
            'operation_id' => $operationId, 'aggregate_type' => $type, 'aggregate_uuid' => $uuid,
        ]);
        $db->commit();
        return $response;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function api_apply_sale_correction_operation(mysqli $db, array $auth, array $operation): array
{
    api_require_module($db, $auth, 'ventas');
    api_require_fields($operation, ['operation_id','aggregate_uuid','operation_type','payload']);
    $operationId = api_assert_uuid($operation['operation_id'], 'operation_id');
    $correctionUuid = api_assert_uuid($operation['aggregate_uuid'], 'aggregate_uuid');
    $payload = $operation['payload'];
    $correction = $payload['correction'] ?? null;
    $items = $payload['items'] ?? null;
    if (!is_array($correction) || !is_array($items) || count($items) < 1) {
        api_fail(422, 'VALIDATION_ERROR', 'Corrección inválida.');
    }
    $saleUuid = api_assert_uuid($correction['sale_uuid'] ?? '', 'sale_uuid');
    $idempotencyKey = (string) ($correction['idempotency_key'] ?? '');
    $reason = trim((string) ($correction['reason'] ?? ''));
    if (strlen($idempotencyKey) !== 64 || $reason === '') api_fail(422, 'VALIDATION_ERROR', 'Motivo e idempotencia obligatorios.');
    $requestHash = hash('sha256', json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT request_hash,response_json FROM processed_operations WHERE operation_id=? FOR UPDATE');
        $stmt->bind_param('s', $operationId); $stmt->execute();
        $processed = $stmt->get_result()->fetch_assoc();
        if ($processed) {
            if (!hash_equals($processed['request_hash'], $requestHash)) api_fail(409, 'IDEMPOTENCY_KEY_REUSED', 'operation_id reutilizado.');
            $db->commit();
            return json_decode($processed['response_json'], true);
        }
        $stmt = $db->prepare('SELECT metodo_pago,caja_sesion_uuid,fecha_operacion FROM ventas_cabecera WHERE uuid=? FOR UPDATE');
        $stmt->bind_param('s', $saleUuid); $stmt->execute();
        $sale = $stmt->get_result()->fetch_assoc();
        if (!$sale) api_fail(422, 'SALE_NOT_FOUND', 'Venta no encontrada.');
        if (strtotime((string) $sale['fecha_operacion']) < strtotime('-30 days')) {
            api_fail(409, 'CORRECTION_DEADLINE_EXPIRED', 'El plazo de corrección venció.');
        }
        $type = (string) $correction['correction_type'];
        $status = (string) $correction['status'];
        $amount = ((int) $correction['amount_cents']) / 100;
        $createdAt = (string) $correction['created_at'];
        $stmt = $db->prepare(
            'INSERT INTO sale_corrections
             (uuid,sale_uuid,correction_type,reason,amount,status,idempotency_key,
              operation_id,created_at,origin_device_id)
             VALUES(?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->bind_param('ssssdsssss', $correctionUuid, $saleUuid, $type, $reason, $amount, $status, $idempotencyKey, $operationId, $createdAt, $auth['device_id']);
        $stmt->execute();
        foreach ($items as $item) {
            $itemUuid = api_assert_uuid($item['uuid'] ?? '', 'item_uuid');
            $saleItemUuid = api_assert_uuid($item['sale_item_uuid'] ?? '', 'sale_item_uuid');
            $productUuid = api_assert_uuid($item['product_uuid'] ?? '', 'product_uuid');
            $quantity = (float) ($item['quantity'] ?? 0);
            $itemAmount = ((int) ($item['amount_cents'] ?? 0)) / 100;
            $stmt = $db->prepare(
                'SELECT d.cantidad-COALESCE(SUM(ci.quantity),0) remaining
                 FROM ventas_detalle d
                 LEFT JOIN sale_correction_items ci ON ci.sale_item_uuid=d.uuid
                 WHERE d.uuid=? AND d.venta_uuid=? GROUP BY d.uuid FOR UPDATE'
            );
            $stmt->bind_param('ss', $saleItemUuid, $saleUuid); $stmt->execute();
            $remaining = $stmt->get_result()->fetch_assoc();
            if (!$remaining || $quantity <= 0 || $quantity > (float) $remaining['remaining']) {
                api_fail(409, 'RETURN_EXCEEDS_SOLD', 'La devolución excede la cantidad vendida.');
            }
            $stmt = $db->prepare(
                'INSERT INTO sale_correction_items(uuid,correction_uuid,sale_item_uuid,product_uuid,quantity,amount)
                 VALUES(?,?,?,?,?,?)'
            );
            $stmt->bind_param('ssssdd', $itemUuid, $correctionUuid, $saleItemUuid, $productUuid, $quantity, $itemAmount); $stmt->execute();
            $stmt = $db->prepare('SELECT cantidad FROM productos WHERE uuid=? FOR UPDATE');
            $stmt->bind_param('s', $productUuid); $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $balance = (float) $product['cantidad'] + $quantity;
            $stmt = $db->prepare('UPDATE productos SET cantidad=?,version=version+1 WHERE uuid=?');
            $stmt->bind_param('ds', $balance, $productUuid); $stmt->execute();
            $movementUuid = api_uuid();
            $movementOperation = $itemUuid;
            $stmt = $db->prepare(
                "INSERT INTO inventario_movimientos
                 (uuid,producto_uuid,tipo,cantidad,referencia_operacion,usuario_uuid,device_id,
                  fecha_operacion,operation_id,motivo,saldo_proyectado,version,origin_device_id)
                 SELECT ?,?,'devolucion',?,?,u.uuid,?,?,?,?,?,1,? FROM usuarios u WHERE u.id=?"
            );
            $stmt->bind_param('ssdsssssdsi', $movementUuid, $productUuid, $quantity, $correctionUuid, $auth['device_id'], $createdAt, $movementOperation, $reason, $balance, $auth['device_id'], $auth['user_id']);
            $stmt->execute();
        }
        if ($sale['metodo_pago'] === 'efectivo') {
            $stmt = $db->prepare("SELECT id FROM caja_sesiones WHERE uuid=? AND estado='abierta' FOR UPDATE");
            $stmt->bind_param('s', $sale['caja_sesion_uuid']); $stmt->execute();
            $cash = $stmt->get_result()->fetch_assoc();
            if (!$cash) api_fail(409, 'ORIGINAL_CASH_SESSION_NOT_OPEN', 'La caja original no está abierta.');
            $cashMovementUuid = api_uuid();
            $stmt = $db->prepare(
                "INSERT INTO caja_movimientos(uuid,caja_id,tipo,concepto,monto,usuario_id,usuario_nombre,fecha,version,origin_device_id)
                 SELECT ?,?,'salida',?,?,u.id,u.nombre,?,1,? FROM usuarios u WHERE u.id=?"
            );
            $stmt->bind_param('sisdssi', $cashMovementUuid, $cash['id'], $reason, $amount, $createdAt, $auth['device_id'], $auth['user_id']);
            $stmt->execute();
        }
        $eventId = api_uuid();
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            "INSERT INTO change_log(event_id,entity_type,entity_uuid,operation_type,entity_version,payload_json,origin_device_id,operation_id)
             VALUES(?,'correccion_venta',?,'create',1,?,?,?)"
        );
        $stmt->bind_param('sssss', $eventId, $correctionUuid, $payloadJson, $auth['device_id'], $operationId); $stmt->execute();
        $revision = (int) $db->insert_id;
        $result = ['operation_id'=>$operationId,'status'=>'applied','aggregate_uuid'=>$correctionUuid,'version'=>1,'server_revision'=>$revision];
        $response = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            "INSERT INTO processed_operations(operation_id,device_id,aggregate_type,aggregate_uuid,request_hash,response_json)
             VALUES(?,?,'correccion_venta',?,?,?)"
        );
        $stmt->bind_param('sssss', $operationId, $auth['device_id'], $correctionUuid, $requestHash, $response); $stmt->execute();
        api_audit($db, (int) $auth['user_id'], 'api.sale_corrected', ['correction_uuid'=>$correctionUuid,'sale_uuid'=>$saleUuid]);
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function api_apply_cash_session_operation(mysqli $db, array $auth, array $operation): array
{
    api_require_module($db, $auth, 'ventas');
    api_require_fields($operation, ['operation_id', 'aggregate_uuid', 'operation_type', 'payload']);
    $operationId = api_assert_uuid($operation['operation_id'], 'operation_id');
    $sessionUuid = api_assert_uuid($operation['aggregate_uuid'], 'aggregate_uuid');
    $payload = $operation['payload'];
    $session = $payload['session'] ?? null;
    if (!is_array($session)) api_fail(422, 'VALIDATION_ERROR', 'Sesión de caja inválida.');
    $requestHash = hash('sha256', json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT request_hash,response_json FROM processed_operations WHERE operation_id=? FOR UPDATE');
        $stmt->bind_param('s', $operationId); $stmt->execute();
        $processed = $stmt->get_result()->fetch_assoc();
        if ($processed) {
            if (!hash_equals($processed['request_hash'], $requestHash)) api_fail(409, 'IDEMPOTENCY_KEY_REUSED', 'operation_id reutilizado.');
            $db->commit();
            return json_decode($processed['response_json'], true);
        }
        $stmt = $db->prepare('SELECT id,estado,version FROM caja_sesiones WHERE uuid=? FOR UPDATE');
        $stmt->bind_param('s', $sessionUuid); $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $status = (string) ($session['status'] ?? 'open');
        if (!$existing) {
            $stmt = $db->prepare(
                "SELECT uuid FROM caja_sesiones
                 WHERE estado='abierta' AND origin_device_id=? AND uuid<>? LIMIT 1 FOR UPDATE"
            );
            $stmt->bind_param('ss', $auth['device_id'], $sessionUuid); $stmt->execute();
            $incompatible = $stmt->get_result()->fetch_assoc();
            if ($incompatible) {
                $local = json_encode($payload, JSON_UNESCAPED_UNICODE);
                $server = json_encode($incompatible, JSON_UNESCAPED_UNICODE);
                $stmt = $db->prepare(
                    "INSERT INTO sync_conflicts(entity_type,entity_uuid,operation_id,local_payload,server_payload,reason)
                     VALUES('caja_sesion',?,?,?,?, 'INCOMPATIBLE_OPEN_CASH')"
                );
                $stmt->bind_param('ssss', $sessionUuid, $operationId, $local, $server); $stmt->execute();
                $result = ['operation_id'=>$operationId,'status'=>'conflict','code'=>'INCOMPATIBLE_OPEN_CASH','aggregate_uuid'=>$sessionUuid];
                $db->commit();
                return $result;
            }
            $folio = 'CAJA-' . substr($sessionUuid, 0, 8);
            $opening = ((int) ($session['opening_amount_cents'] ?? 0)) / 100;
            $openedAt = (string) ($session['opened_at'] ?? gmdate('c'));
            $stmt = $db->prepare(
                "INSERT INTO caja_sesiones
                 (uuid,folio_caja,estado,usuario_apertura_id,usuario_apertura_nombre,
                  fecha_apertura,monto_inicial,version,origin_device_id)
                 SELECT ?,?,'abierta',u.id,u.nombre,?,?,1,? FROM usuarios u WHERE u.id=?"
            );
            $stmt->bind_param('sssdsi', $sessionUuid, $folio, $openedAt, $opening, $auth['device_id'], $auth['user_id']);
            $stmt->execute();
        } else {
            if ($existing['estado'] === 'cerrada') api_fail(409, 'CASH_SESSION_CLOSED', 'La caja cerrada es inmutable.');
            if ($status !== 'closed') api_fail(422, 'INVALID_CASH_TRANSITION', 'Transición de caja inválida.');
            $expected = ((int) $session['expected_cash_cents']) / 100;
            $counted = ((int) $session['counted_cash_cents']) / 100;
            $difference = ((int) $session['difference_cents']) / 100;
            $closedAt = (string) $session['closed_at'];
            $payments = json_decode((string) ($session['payment_totals_json'] ?? '{}'), true) ?: [];
            $cash = ((int) ($payments['efectivo'] ?? 0)) / 100;
            $card = ((int) ($payments['tarjeta'] ?? 0)) / 100;
            $transfer = ((int) ($payments['transferencia'] ?? 0)) / 100;
            $total = $cash + $card + $transfer;
            $stmt = $db->prepare(
                "UPDATE caja_sesiones SET estado='cerrada',usuario_cierre_id=?,
                 usuario_cierre_nombre=(SELECT nombre FROM usuarios WHERE id=?),
                 fecha_cierre=?,ventas_sistema=?,efectivo_sistema=?,tarjeta_sistema=?,
                 transferencia_sistema=?,efectivo_esperado=?,efectivo_contado=?,
                 diferencia_efectivo=?,version=version+1,origin_device_id=? WHERE uuid=?"
            );
            $stmt->bind_param(
                'iisdddddddss',
                $auth['user_id'], $auth['user_id'], $closedAt, $total, $cash, $card,
                $transfer, $expected, $counted, $difference, $auth['device_id'], $sessionUuid
            );
            $stmt->execute();
        }
        $eventId = api_uuid();
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $opType = $existing ? 'update' : 'create';
        $version = $existing ? (int) $existing['version'] + 1 : 1;
        $stmt = $db->prepare(
            "INSERT INTO change_log(event_id,entity_type,entity_uuid,operation_type,entity_version,payload_json,origin_device_id,operation_id)
             VALUES(?,'caja_sesion',?,?,?,?,?,?)"
        );
        $stmt->bind_param('sssisss', $eventId, $sessionUuid, $opType, $version, $payloadJson, $auth['device_id'], $operationId);
        $stmt->execute();
        $revision = (int) $db->insert_id;
        $result = ['operation_id'=>$operationId,'status'=>'applied','aggregate_uuid'=>$sessionUuid,'version'=>$version,'server_revision'=>$revision];
        $response = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            "INSERT INTO processed_operations(operation_id,device_id,aggregate_type,aggregate_uuid,request_hash,response_json)
             VALUES(?,?,'caja_sesion',?,?,?)"
        );
        $stmt->bind_param('sssss', $operationId, $auth['device_id'], $sessionUuid, $requestHash, $response); $stmt->execute();
        api_audit($db, (int) $auth['user_id'], $existing ? 'api.cash_closed' : 'api.cash_opened', ['cash_uuid'=>$sessionUuid]);
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function api_apply_cash_movement_operation(mysqli $db, array $auth, array $operation): array
{
    api_require_module($db, $auth, 'ventas');
    api_require_fields($operation, ['operation_id', 'aggregate_uuid', 'payload']);
    $operationId = api_assert_uuid($operation['operation_id'], 'operation_id');
    $movementUuid = api_assert_uuid($operation['aggregate_uuid'], 'aggregate_uuid');
    $payload = $operation['payload'];
    $sessionUuid = api_assert_uuid($payload['session_uuid'] ?? '', 'session_uuid');
    $requestHash = hash('sha256', json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT request_hash,response_json FROM processed_operations WHERE operation_id=? FOR UPDATE');
        $stmt->bind_param('s', $operationId); $stmt->execute();
        $processed = $stmt->get_result()->fetch_assoc();
        if ($processed) {
            if (!hash_equals($processed['request_hash'], $requestHash)) api_fail(409, 'IDEMPOTENCY_KEY_REUSED', 'operation_id reutilizado.');
            $db->commit();
            return json_decode($processed['response_json'], true);
        }
        $stmt = $db->prepare("SELECT id FROM caja_sesiones WHERE uuid=? AND estado='abierta' FOR UPDATE");
        $stmt->bind_param('s', $sessionUuid); $stmt->execute();
        $cash = $stmt->get_result()->fetch_assoc();
        if (!$cash) api_fail(409, 'CASH_SESSION_NOT_OPEN', 'La caja no está abierta.');
        $type = (string) ($payload['movement_type'] ?? '');
        if (!in_array($type, ['entry','exit'], true)) api_fail(422, 'VALIDATION_ERROR', 'Tipo de movimiento inválido.');
        $amount = ((int) ($payload['amount_cents'] ?? 0)) / 100;
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($amount <= 0 || $reason === '') api_fail(422, 'VALIDATION_ERROR', 'Monto y motivo obligatorios.');
        $legacyType = $type === 'entry' ? 'entrada' : 'salida';
        $date = (string) ($payload['created_at'] ?? gmdate('c'));
        $stmt = $db->prepare(
            'INSERT INTO caja_movimientos
             (uuid,caja_id,tipo,concepto,monto,usuario_id,usuario_nombre,fecha,version,origin_device_id)
             SELECT ?,?, ?,?,?,u.id,u.nombre,?,1,? FROM usuarios u WHERE u.id=?'
        );
        $stmt->bind_param('sissdssi', $movementUuid, $cash['id'], $legacyType, $reason, $amount, $date, $auth['device_id'], $auth['user_id']);
        $stmt->execute();
        $eventId = api_uuid();
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            "INSERT INTO change_log(event_id,entity_type,entity_uuid,operation_type,entity_version,payload_json,origin_device_id,operation_id)
             VALUES(?,'caja_movimiento',?,'create',1,?,?,?)"
        );
        $stmt->bind_param('sssss', $eventId, $movementUuid, $payloadJson, $auth['device_id'], $operationId); $stmt->execute();
        $revision = (int) $db->insert_id;
        $result = ['operation_id'=>$operationId,'status'=>'applied','aggregate_uuid'=>$movementUuid,'version'=>1,'server_revision'=>$revision];
        $response = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            "INSERT INTO processed_operations(operation_id,device_id,aggregate_type,aggregate_uuid,request_hash,response_json)
             VALUES(?,?,'caja_movimiento',?,?,?)"
        );
        $stmt->bind_param('sssss', $operationId, $auth['device_id'], $movementUuid, $requestHash, $response); $stmt->execute();
        api_audit($db, (int) $auth['user_id'], 'api.cash_moved', $payload);
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function api_apply_inventory_operation(mysqli $db, array $auth, array $operation): array
{
    api_require_module($db, $auth, 'productos');
    api_require_fields($operation, ['operation_id', 'aggregate_uuid', 'operation_type', 'payload']);
    $operationId = api_assert_uuid($operation['operation_id'], 'operation_id');
    $movementUuid = api_assert_uuid($operation['aggregate_uuid'], 'aggregate_uuid');
    $payload = $operation['payload'];
    api_require_fields($payload, ['producto_uuid', 'tipo', 'cantidad', 'motivo', 'fecha_operacion']);
    $productUuid = api_assert_uuid($payload['producto_uuid'], 'producto_uuid');
    if (trim((string) $payload['motivo']) === '' || (float) $payload['cantidad'] == 0) {
        api_fail(422, 'VALIDATION_ERROR', 'El movimiento requiere cantidad y motivo.');
    }
    $requestHash = hash('sha256', json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT request_hash,response_json FROM processed_operations WHERE operation_id=? FOR UPDATE');
        $stmt->bind_param('s', $operationId); $stmt->execute();
        $processed = $stmt->get_result()->fetch_assoc();
        if ($processed) {
            if (!hash_equals($processed['request_hash'], $requestHash)) {
                api_fail(409, 'IDEMPOTENCY_KEY_REUSED', 'operation_id reutilizado.');
            }
            $db->commit();
            return json_decode($processed['response_json'], true);
        }
        $stmt = $db->prepare('SELECT cantidad FROM productos WHERE uuid=? AND deleted_at IS NULL FOR UPDATE');
        $stmt->bind_param('s', $productUuid); $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        if (!$product) api_fail(422, 'PRODUCT_NOT_FOUND', 'Producto no encontrado.');
        $quantity = (float) $payload['cantidad'];
        $balance = (float) $product['cantidad'] + $quantity;
        if ($balance < 0) api_fail(409, 'INSUFFICIENT_STOCK', 'Existencia insuficiente.');
        $stmt = $db->prepare('UPDATE productos SET cantidad=?,version=version+1,origin_device_id=? WHERE uuid=?');
        $stmt->bind_param('dss', $balance, $auth['device_id'], $productUuid); $stmt->execute();
        $type = (string) $payload['tipo'];
        $date = (string) $payload['fecha_operacion'];
        $reason = (string) $payload['motivo'];
        $stmt = $db->prepare(
            'INSERT INTO inventario_movimientos
             (uuid,producto_uuid,tipo,cantidad,referencia_operacion,usuario_uuid,device_id,
              fecha_operacion,operation_id,motivo,saldo_proyectado,version,origin_device_id)
             SELECT ?,?,?,?,NULL,u.uuid,?,?,?,?,?,1,? FROM usuarios u WHERE u.id=?'
        );
        $stmt->bind_param(
            'sssdssssdsi',
            $movementUuid, $productUuid, $type, $quantity, $auth['device_id'],
            $date, $operationId, $reason, $balance, $auth['device_id'], $auth['user_id']
        );
        $stmt->execute();
        $eventId = api_uuid();
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            'INSERT INTO change_log(event_id,entity_type,entity_uuid,operation_type,entity_version,payload_json,origin_device_id,operation_id)
             VALUES(?,\'inventario_movimiento\',?,\'create\',1,?,?,?)'
        );
        $stmt->bind_param('sssss', $eventId, $movementUuid, $payloadJson, $auth['device_id'], $operationId);
        $stmt->execute();
        $revision = (int) $db->insert_id;
        $result = ['operation_id'=>$operationId,'status'=>'applied','aggregate_uuid'=>$movementUuid,'version'=>1,'server_revision'=>$revision];
        $response = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            'INSERT INTO processed_operations(operation_id,device_id,aggregate_type,aggregate_uuid,request_hash,response_json)
             VALUES(?,?,\'inventario_movimiento\',?,?,?)'
        );
        $stmt->bind_param('sssss', $operationId, $auth['device_id'], $movementUuid, $requestHash, $response);
        $stmt->execute();
        api_audit($db, (int) $auth['user_id'], 'api.inventory_moved', $payload);
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function api_apply_order_operation(mysqli $db, array $auth, array $operation): array
{
    api_require_module($db, $auth, 'proveedores');
    api_require_fields($operation, ['operation_id', 'aggregate_uuid', 'operation_type', 'payload']);
    $operationId = api_assert_uuid($operation['operation_id'], 'operation_id');
    $orderUuid = api_assert_uuid($operation['aggregate_uuid'], 'aggregate_uuid');
    $payload = $operation['payload'];
    $order = $payload['order'] ?? null;
    $items = $payload['items'] ?? null;
    if (!is_array($order) || !is_array($items) || count($items) < 1) {
        api_fail(422, 'VALIDATION_ERROR', 'Pedido inválido.');
    }
    $requestHash = hash('sha256', json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT request_hash,response_json FROM processed_operations WHERE operation_id=? FOR UPDATE');
        $stmt->bind_param('s', $operationId); $stmt->execute();
        $processed = $stmt->get_result()->fetch_assoc();
        if ($processed) {
            if (!hash_equals($processed['request_hash'], $requestHash)) api_fail(409, 'IDEMPOTENCY_KEY_REUSED', 'operation_id reutilizado.');
            $db->commit();
            return json_decode($processed['response_json'], true);
        }
        $stmt = $db->prepare('SELECT version FROM purchase_orders WHERE uuid=? FOR UPDATE');
        $stmt->bind_param('s', $orderUuid); $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $folio = (string) $order['folio'];
        $provider = $order['provider_uuid'] ?? null;
        $requestedBy = $order['requested_by'] ?? null;
        $status = (string) $order['status'];
        $notes = $order['notes'] ?? null;
        $created = (string) $order['created_at'];
        $updated = (string) $order['updated_at'];
        if (!$existing) {
            $stmt = $db->prepare(
                'INSERT INTO purchase_orders(uuid,folio,provider_uuid,requested_by,status,notes,version,created_at,updated_at,origin_device_id)
                 VALUES(?,?,?,?,?,?,1,?,?,?)'
            );
            $stmt->bind_param('sssssssss', $orderUuid, $folio, $provider, $requestedBy, $status, $notes, $created, $updated, $auth['device_id']);
            $stmt->execute();
        } else {
            $stmt = $db->prepare('UPDATE purchase_orders SET status=?,notes=?,version=version+1,updated_at=?,origin_device_id=? WHERE uuid=?');
            $stmt->bind_param('sssss', $status, $notes, $updated, $auth['device_id'], $orderUuid);
            $stmt->execute();
        }
        foreach ($items as $item) {
            $itemUuid = api_assert_uuid($item['uuid'] ?? '', 'item_uuid');
            $productUuid = api_assert_uuid($item['product_uuid'] ?? '', 'producto_uuid');
            $name = (string) $item['product_name'];
            $requested = (float) $item['requested_quantity'];
            $received = (float) $item['received_quantity'];
            $stmt = $db->prepare(
                'INSERT INTO purchase_order_items(uuid,order_uuid,product_uuid,product_name,requested_quantity,received_quantity)
                 VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE received_quantity=VALUES(received_quantity)'
            );
            $stmt->bind_param('ssssdd', $itemUuid, $orderUuid, $productUuid, $name, $requested, $received);
            $stmt->execute();
        }
        $eventId = api_uuid();
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            'INSERT INTO change_log(event_id,entity_type,entity_uuid,operation_type,entity_version,payload_json,origin_device_id,operation_id)
             VALUES(?,\'pedido\',?,?,1,?,?,?)'
        );
        $opType = $existing ? 'update' : 'create';
        $stmt->bind_param('ssssss', $eventId, $orderUuid, $opType, $payloadJson, $auth['device_id'], $operationId);
        $stmt->execute();
        $revision = (int) $db->insert_id;
        $result = ['operation_id'=>$operationId,'status'=>'applied','aggregate_uuid'=>$orderUuid,'version'=>1,'server_revision'=>$revision];
        $response = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            'INSERT INTO processed_operations(operation_id,device_id,aggregate_type,aggregate_uuid,request_hash,response_json)
             VALUES(?,?,\'pedido\',?,?,?)'
        );
        $stmt->bind_param('sssss', $operationId, $auth['device_id'], $orderUuid, $requestHash, $response);
        $stmt->execute();
        api_audit($db, (int) $auth['user_id'], 'api.order_changed', ['order_uuid'=>$orderUuid,'status'=>$status]);
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function api_apply_sale_operation(mysqli $db, array $auth, array $operation): array
{
    api_require_module($db, $auth, 'ventas');
    api_require_fields($operation, [
        'operation_id', 'aggregate_uuid', 'operation_type', 'base_version', 'payload',
    ]);
    $operationId = api_assert_uuid($operation['operation_id'], 'operation_id');
    $saleUuid = api_assert_uuid($operation['aggregate_uuid'], 'aggregate_uuid');
    if ($operation['operation_type'] !== 'create' || (int) $operation['base_version'] !== 0) {
        api_fail(422, 'UNSUPPORTED_OPERATION', 'La venta solo admite creación.');
    }
    $payload = $operation['payload'];
    $sale = $payload['sale'] ?? null;
    $items = $payload['items'] ?? null;
    if (!is_array($sale) || !is_array($items) || count($items) < 1) {
        api_fail(422, 'VALIDATION_ERROR', 'La venta requiere cabecera y detalles.');
    }
    foreach (['folio', 'payment_method', 'subtotal_cents', 'discount_cents', 'total_cents', 'sold_at'] as $field) {
        if (!array_key_exists($field, $sale)) {
            api_fail(422, 'VALIDATION_ERROR', "Falta $field en la venta.");
        }
    }
    $requestHash = hash('sha256', json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            'SELECT request_hash,response_json FROM processed_operations
             WHERE operation_id=? FOR UPDATE'
        );
        $stmt->bind_param('s', $operationId);
        $stmt->execute();
        $processed = $stmt->get_result()->fetch_assoc();
        if ($processed) {
            if (!hash_equals($processed['request_hash'], $requestHash)) {
                $db->rollback();
                api_fail(409, 'IDEMPOTENCY_KEY_REUSED', 'operation_id ya fue usado con otro contenido.');
            }
            $db->commit();
            return json_decode($processed['response_json'], true);
        }

        $subtotal = ((int) $sale['subtotal_cents']) / 100;
        $discount = ((int) $sale['discount_cents']) / 100;
        $total = ((int) $sale['total_cents']) / 100;
        if ($subtotal < 0 || $discount < 0 || abs(($subtotal - $discount) - $total) > 0.001) {
            api_fail(422, 'INVALID_TOTAL', 'Los totales de la venta no cuadran.');
        }
        $stmt = $db->prepare(
            'INSERT INTO ventas_cabecera
             (uuid,folio,caja_sesion_uuid,metodo_pago,subtotal,descuento,total,estado,correo_cliente,
              fecha_operacion,version,origin_device_id)
             VALUES(?,?,?,?,?,?,? ,\'confirmada\',?,?,1,?)'
        );
        $folio = (string) $sale['folio'];
        $payment = (string) $sale['payment_method'];
        $email = $sale['customer_email'] ?? null;
        $soldAt = (string) $sale['sold_at'];
        $cashSessionUuid = $sale['cash_session_uuid'] ?? null;
        if ($payment === 'efectivo' && !$cashSessionUuid) {
            api_fail(422, 'CASH_SESSION_REQUIRED', 'La venta en efectivo requiere caja.');
        }
        $stmt->bind_param(
            'ssssdddsss',
            $saleUuid, $folio, $cashSessionUuid, $payment, $subtotal, $discount, $total,
            $email, $soldAt, $auth['device_id']
        );
        $stmt->execute();

        $calculated = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                api_fail(422, 'VALIDATION_ERROR', 'Detalle inválido.');
            }
            $itemUuid = api_assert_uuid($item['uuid'] ?? '', 'detalle_uuid');
            $productUuid = api_assert_uuid($item['product_uuid'] ?? '', 'producto_uuid');
            $quantity = (float) ($item['quantity'] ?? 0);
            $lineSubtotal = ((int) ($item['subtotal_cents'] ?? -1)) / 100;
            if ($quantity <= 0 || $lineSubtotal < 0) {
                api_fail(422, 'VALIDATION_ERROR', 'Cantidad o subtotal inválido.');
            }
            $stmt = $db->prepare(
                'SELECT id,nombre,cantidad,precio_compra,precio_venta FROM productos
                 WHERE uuid=? AND activo=1 AND deleted_at IS NULL FOR UPDATE'
            );
            $stmt->bind_param('s', $productUuid);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            if (!$product || (float) $product['cantidad'] < $quantity) {
                api_fail(409, 'INSUFFICIENT_STOCK', 'Existencia central insuficiente.');
            }
            $unitPrice = ((int) ($item['unit_price_cents'] ?? 0)) / 100;
            $lineDiscount = ((int) ($item['discount_cents'] ?? 0)) / 100;
            $cost = ((int) ($item['historical_cost_cents'] ?? 0)) / 100;
            $name = (string) ($item['product_name'] ?? $product['nombre']);
            $barcode = $item['barcode'] ?? null;
            $stmt = $db->prepare(
                'INSERT INTO ventas_detalle
                 (uuid,venta_uuid,producto_uuid,cantidad,precio_base,precio_final,
                  descuento,subtotal,costo_historico,nombre_historico,codigo_historico,
                  version,origin_device_id)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,1,?)'
            );
            $finalPrice = $quantity > 0 ? $lineSubtotal / $quantity : 0;
            $stmt->bind_param(
                'sssddddddsss',
                $itemUuid, $saleUuid, $productUuid, $quantity, $unitPrice, $finalPrice,
                $lineDiscount, $lineSubtotal, $cost, $name, $barcode, $auth['device_id']
            );
            $stmt->execute();
            $balance = (float) $product['cantidad'] - $quantity;
            $stmt = $db->prepare(
                'UPDATE productos SET cantidad=?,version=version+1,origin_device_id=? WHERE uuid=?'
            );
            $stmt->bind_param('dss', $balance, $auth['device_id'], $productUuid);
            $stmt->execute();
            $movementUuid = api_uuid();
            $movementOperation = $itemUuid;
            $negativeQuantity = -$quantity;
            $stmt = $db->prepare(
                'INSERT INTO inventario_movimientos
                 (uuid,producto_uuid,tipo,cantidad,referencia_operacion,usuario_uuid,
                  device_id,fecha_operacion,operation_id,motivo,saldo_proyectado,
                  version,origin_device_id)
                 SELECT ?,?,\'venta\',?,?,u.uuid,?,?,?,\'Venta offline\',?,1,?
                 FROM usuarios u WHERE u.id=?'
            );
            $stmt->bind_param(
                'ssdssssdsi',
                $movementUuid, $productUuid, $negativeQuantity, $folio,
                $auth['device_id'], $soldAt, $movementOperation, $balance,
                $auth['device_id'], $auth['user_id']
            );
            $stmt->execute();
            $calculated += $lineSubtotal;
        }
        if (abs(($calculated - $discount) - $total) > 0.001) {
            api_fail(422, 'INVALID_TOTAL', 'Los detalles no coinciden con el total.');
        }
        $eventId = api_uuid();
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            'INSERT INTO change_log
             (event_id,entity_type,entity_uuid,operation_type,entity_version,
              payload_json,origin_device_id,operation_id)
             VALUES(?,\'venta\',?,\'create\',1,?,?,?)'
        );
        $stmt->bind_param('sssss', $eventId, $saleUuid, $payloadJson, $auth['device_id'], $operationId);
        $stmt->execute();
        $revision = (int) $db->insert_id;
        $result = [
            'operation_id' => $operationId, 'status' => 'applied',
            'aggregate_uuid' => $saleUuid, 'version' => 1, 'server_revision' => $revision,
        ];
        $responseJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            'INSERT INTO processed_operations
             (operation_id,device_id,aggregate_type,aggregate_uuid,request_hash,response_json)
             VALUES(?,?,\'venta\',?,?,?)'
        );
        $stmt->bind_param('sssss', $operationId, $auth['device_id'], $saleUuid, $requestHash, $responseJson);
        $stmt->execute();
        api_audit($db, (int) $auth['user_id'], 'api.sale_created', ['sale_uuid' => $saleUuid, 'folio' => $folio]);
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function api_apply_product_operation(mysqli $db, array $auth, array $operation): array
{
    api_require_module($db, $auth, 'productos');
    api_require_fields($operation, [
        'operation_id', 'aggregate_type', 'aggregate_uuid', 'operation_type',
        'base_version', 'payload',
    ]);
    $operationId = api_assert_uuid($operation['operation_id'], 'operation_id');
    $aggregateUuid = api_assert_uuid($operation['aggregate_uuid'], 'aggregate_uuid');
    if ($operation['aggregate_type'] !== 'producto') {
        api_fail(422, 'UNSUPPORTED_AGGREGATE', 'El agregado no está permitido.');
    }
    if (!in_array($operation['operation_type'], ['create', 'update', 'delete'], true)) {
        api_fail(422, 'VALIDATION_ERROR', 'operation_type inválido.');
    }
    if (!is_int($operation['base_version']) || $operation['base_version'] < 0 || !is_array($operation['payload'])) {
        api_fail(422, 'VALIDATION_ERROR', 'Versión o payload inválidos.');
    }
    $requestHash = hash('sha256', json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            'SELECT request_hash,response_json FROM processed_operations
             WHERE operation_id=? FOR UPDATE'
        );
        $stmt->bind_param('s', $operationId);
        $stmt->execute();
        $processed = $stmt->get_result()->fetch_assoc();
        if ($processed) {
            if (!hash_equals($processed['request_hash'], $requestHash)) {
                $db->rollback();
                return ['operation_id' => $operationId, 'status' => 'rejected', 'code' => 'IDEMPOTENCY_MISMATCH'];
            }
            $db->commit();
            return json_decode($processed['response_json'], true);
        }

        $stmt = $db->prepare('SELECT id,version,deleted_at FROM productos WHERE uuid=? FOR UPDATE');
        $stmt->bind_param('s', $aggregateUuid);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $baseVersion = (int) $operation['base_version'];
        $barcodeConflict = null;
        $barcode = $operation['payload']['codigo_barras'] ?? null;
        if ($barcode) {
            $stmt = $db->prepare(
                'SELECT p.uuid,c.codigo FROM codigos_barras c JOIN productos p ON p.id=c.producto_id
                 WHERE c.codigo=? AND c.deleted_at IS NULL AND p.uuid<>? LIMIT 1'
            );
            $stmt->bind_param('ss', $barcode, $aggregateUuid);
            $stmt->execute();
            $barcodeConflict = $stmt->get_result()->fetch_assoc();
        }
        if ($barcodeConflict || ($current && (int) $current['version'] !== $baseVersion) || (!$current && $baseVersion !== 0)) {
            $serverPayload = $current ?: [];
            $localJson = json_encode($operation['payload'], JSON_UNESCAPED_UNICODE);
            $serverJson = json_encode($serverPayload, JSON_UNESCAPED_UNICODE);
            $stmt = $db->prepare(
                'INSERT INTO sync_conflicts
                 (entity_type,entity_uuid,operation_id,local_payload,server_payload,reason)
                 VALUES(\'producto\',?,?,?,?,?)'
            );
            $conflictReason = $barcodeConflict ? 'BARCODE_DUPLICATE' : 'VERSION_MISMATCH';
            $stmt->bind_param('sssss', $aggregateUuid, $operationId, $localJson, $serverJson, $conflictReason);
            $stmt->execute();
            $result = [
                'operation_id' => $operationId,
                'status' => 'conflict',
                'code' => $conflictReason,
                'server_version' => $current ? (int) $current['version'] : null,
            ];
        } else {
            $nextVersion = $baseVersion + 1;
            if ($operation['operation_type'] === 'create') {
                api_validate_product_payload($operation['payload'], true);
                $p = $operation['payload'];
                $stmt = $db->prepare(
                    'INSERT INTO productos
                     (uuid,nombre,categoria,atributos,proveedor_id,cantidad,
                      precio_compra,precio_venta,activo,tipo_inventario,
                      tipo_adquisicion,version,origin_device_id)
                     VALUES(?,?,?,?,?,?,?,?,1,?,?,?,?)'
                );
                $attributes = isset($p['atributos']) ? json_encode($p['atributos'], JSON_UNESCAPED_UNICODE) : null;
                $inventoryType = $p['tipo_inventario'] ?? 'producto';
                $acquisitionType = $p['tipo_adquisicion'] ?? 'concesion';
                $providerId = api_product_provider_id($db, $p['proveedor_uuid'] ?? null);
                $stmt->bind_param(
                    'ssssiiddssis',
                    $aggregateUuid, $p['nombre'], $p['categoria'], $attributes,
                    $providerId, $p['cantidad'], $p['precio_compra'], $p['precio_venta'],
                    $inventoryType, $acquisitionType, $nextVersion, $auth['device_id']
                );
                $stmt->execute();
                if ($barcode) {
                    $productId = (int) $db->insert_id;
                    $barcodeUuid = api_uuid();
                    $stmt = $db->prepare(
                        'INSERT INTO codigos_barras(uuid,producto_id,codigo,disponible,version,origin_device_id)
                         VALUES(?,?,?,1,1,?)'
                    );
                    $stmt->bind_param('siss', $barcodeUuid, $productId, $barcode, $auth['device_id']);
                    $stmt->execute();
                }
            } elseif ($operation['operation_type'] === 'update') {
                api_validate_product_payload($operation['payload'], false);
                $updatePayload = $operation['payload'];
                if (array_key_exists('atributos', $updatePayload)) {
                    $updatePayload['atributos'] = json_encode($updatePayload['atributos'], JSON_UNESCAPED_UNICODE);
                }
                if (array_key_exists('proveedor_uuid', $updatePayload)) {
                    $updatePayload['proveedor_id'] = api_product_provider_id($db, $updatePayload['proveedor_uuid']);
                }
                $allowed = [
                    'nombre', 'categoria', 'cantidad', 'precio_compra', 'precio_venta',
                    'activo', 'atributos', 'proveedor_id', 'tipo_inventario', 'tipo_adquisicion',
                ];
                $sets = [];
                $values = [];
                $types = '';
                foreach ($allowed as $field) {
                    if (array_key_exists($field, $updatePayload)) {
                        $sets[] = "`$field`=?";
                        $values[] = $updatePayload[$field];
                        $types .= in_array($field, ['cantidad', 'activo', 'proveedor_id'], true) ? 'i' :
                            (strpos($field, 'precio_') === 0 ? 'd' : 's');
                    }
                }
                if (!$sets) {
                    api_fail(422, 'VALIDATION_ERROR', 'No hay campos editables.');
                }
                $sets[] = 'version=?';
                $sets[] = 'origin_device_id=?';
                $values[] = $nextVersion;
                $values[] = $auth['device_id'];
                $values[] = $aggregateUuid;
                $types .= 'iss';
                $stmt = $db->prepare('UPDATE productos SET ' . implode(',', $sets) . ' WHERE uuid=?');
                $bindArgs = [$types];
                foreach ($values as $index => $_value) {
                    $bindArgs[] = &$values[$index];
                }
                call_user_func_array([$stmt, 'bind_param'], $bindArgs);
                $stmt->execute();
                if (array_key_exists('codigo_barras', $operation['payload'])) {
                    $stmt = $db->prepare('UPDATE codigos_barras SET deleted_at=UTC_TIMESTAMP(6) WHERE producto_id=? AND deleted_at IS NULL');
                    $stmt->bind_param('i', $current['id']);
                    $stmt->execute();
                    if ($barcode) {
                        $barcodeUuid = api_uuid();
                        $stmt = $db->prepare(
                            'INSERT INTO codigos_barras(uuid,producto_id,codigo,disponible,version,origin_device_id)
                             VALUES(?,?,?,1,1,?)'
                        );
                        $stmt->bind_param('siss', $barcodeUuid, $current['id'], $barcode, $auth['device_id']);
                        $stmt->execute();
                    }
                }
            } else {
                if (!$current) {
                    api_fail(404, 'ENTITY_NOT_FOUND', 'El producto no existe.');
                }
                $stmt = $db->prepare(
                    'UPDATE productos SET deleted_at=UTC_TIMESTAMP(6),activo=0,
                     version=?,origin_device_id=? WHERE uuid=?'
                );
                $stmt->bind_param('iss', $nextVersion, $auth['device_id'], $aggregateUuid);
                $stmt->execute();
            }
            $payloadJson = json_encode($operation['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $eventId = api_uuid();
            $stmt = $db->prepare(
                'INSERT INTO change_log
                 (event_id,entity_type,entity_uuid,operation_type,entity_version,
                  payload_json,origin_device_id,operation_id)
                 VALUES(?,\'producto\',?,?,?,?,?,?)'
            );
            $operationType = $operation['operation_type'];
            $stmt->bind_param(
                'sssisss',
                $eventId, $aggregateUuid, $operationType, $nextVersion,
                $payloadJson, $auth['device_id'], $operationId
            );
            $stmt->execute();
            $revision = (int) $db->insert_id;
            $stmt = $db->prepare(
                'INSERT INTO product_catalog_history
                 (entity_type,entity_uuid,operation_id,action,payload_json,user_id,device_id)
                 VALUES(\'producto\',?,?,?,?,?,?)'
            );
            $stmt->bind_param(
                'ssssis',
                $aggregateUuid, $operationId, $operationType, $payloadJson,
                $auth['user_id'], $auth['device_id']
            );
            $stmt->execute();
            $result = [
                'operation_id' => $operationId,
                'status' => 'applied',
                'aggregate_uuid' => $aggregateUuid,
                'version' => $nextVersion,
                'server_revision' => $revision,
            ];
        }
        $responseJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            'INSERT INTO processed_operations
             (operation_id,device_id,aggregate_type,aggregate_uuid,request_hash,response_json)
             VALUES(?,?,\'producto\',?,?,?)'
        );
        $stmt->bind_param('sssss', $operationId, $auth['device_id'], $aggregateUuid, $requestHash, $responseJson);
        $stmt->execute();
        api_audit($db, (int) $auth['user_id'], 'api.sync_push', [
            'operation_id' => $operationId,
            'status' => $result['status'],
        ]);
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function api_validate_product_payload(array $payload, bool $create): void
{
    if ($create) {
        api_require_fields($payload, ['nombre', 'categoria', 'cantidad', 'precio_compra', 'precio_venta']);
    }
    foreach (['nombre', 'categoria'] as $field) {
        if (isset($payload[$field]) && (!is_string($payload[$field]) || trim($payload[$field]) === '')) {
            api_fail(422, 'VALIDATION_ERROR', 'Texto de producto inválido.', ['field' => $field]);
        }
    }
    foreach (['cantidad', 'precio_compra', 'precio_venta'] as $field) {
        if (isset($payload[$field]) && (!is_numeric($payload[$field]) || (float) $payload[$field] < 0)) {
            api_fail(422, 'VALIDATION_ERROR', 'Número de producto inválido.', ['field' => $field]);
        }
    }
    if (isset($payload['codigo_barras']) &&
        !preg_match('/^[A-Za-z0-9-]{4,50}$/', (string) $payload['codigo_barras'])) {
        api_fail(422, 'INVALID_BARCODE', 'El código de barras no es válido.');
    }
    if (isset($payload['tipo_inventario']) &&
        !in_array($payload['tipo_inventario'], ['producto', 'insumo'], true)) {
        api_fail(422, 'VALIDATION_ERROR', 'Tipo de inventario no válido.');
    }
    if (isset($payload['tipo_adquisicion']) &&
        !in_array($payload['tipo_adquisicion'], ['pagado', 'concesion'], true)) {
        api_fail(422, 'VALIDATION_ERROR', 'Tipo de adquisición no válido.');
    }
}

function api_apply_provider_operation(mysqli $db, array $auth, array $operation): array
{
    api_require_module($db, $auth, 'proveedores');
    api_require_fields($operation, [
        'operation_id', 'aggregate_uuid', 'operation_type', 'base_version', 'payload',
    ]);
    $operationId = api_assert_uuid($operation['operation_id'], 'operation_id');
    $providerUuid = api_assert_uuid($operation['aggregate_uuid'], 'aggregate_uuid');
    if ($operation['operation_type'] !== 'create' || (int) $operation['base_version'] !== 0) {
        api_fail(422, 'UNSUPPORTED_OPERATION', 'La operación de proveedor no está permitida.');
    }
    $payload = $operation['payload'];
    if (!is_array($payload) || trim((string) ($payload['nombre'] ?? '')) === '') {
        api_fail(422, 'VALIDATION_ERROR', 'El nombre del proveedor es obligatorio.');
    }
    $requestHash = hash('sha256', json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT request_hash,response_json FROM processed_operations WHERE operation_id=? FOR UPDATE');
        $stmt->bind_param('s', $operationId);
        $stmt->execute();
        $processed = $stmt->get_result()->fetch_assoc();
        if ($processed) {
            if (!hash_equals($processed['request_hash'], $requestHash)) {
                $db->rollback();
                api_fail(409, 'IDEMPOTENCY_KEY_REUSED', 'operation_id ya fue usado con otro contenido.');
            }
            $db->commit();
            return json_decode($processed['response_json'], true);
        }
        $stmt = $db->prepare(
            'INSERT INTO proveedores(uuid,nombre,correo,telefono,activo,version,origin_device_id)
             VALUES(?,?,?,?,1,1,?)'
        );
        $name = trim((string) $payload['nombre']);
        $email = $payload['correo'] ?? null;
        $phone = $payload['telefono'] ?? null;
        $stmt->bind_param('sssss', $providerUuid, $name, $email, $phone, $auth['device_id']);
        $stmt->execute();
        $eventId = api_uuid();
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            'INSERT INTO change_log
             (event_id,entity_type,entity_uuid,operation_type,entity_version,
              payload_json,origin_device_id,operation_id)
             VALUES(?,\'proveedor\',?,\'create\',1,?,?,?)'
        );
        $stmt->bind_param('sssss', $eventId, $providerUuid, $payloadJson, $auth['device_id'], $operationId);
        $stmt->execute();
        $revision = (int) $db->insert_id;
        $result = [
            'operation_id' => $operationId,
            'status' => 'applied',
            'aggregate_uuid' => $providerUuid,
            'version' => 1,
            'server_revision' => $revision,
        ];
        $responseJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            'INSERT INTO processed_operations
             (operation_id,device_id,aggregate_type,aggregate_uuid,request_hash,response_json)
             VALUES(?,?,\'proveedor\',?,?,?)'
        );
        $stmt->bind_param('sssss', $operationId, $auth['device_id'], $providerUuid, $requestHash, $responseJson);
        $stmt->execute();
        $stmt = $db->prepare(
            'INSERT INTO product_catalog_history
             (entity_type,entity_uuid,operation_id,action,payload_json,user_id,device_id)
             VALUES(\'proveedor\',?,?,\'create\',?,?,?)'
        );
        $stmt->bind_param('sssis', $providerUuid, $operationId, $payloadJson, $auth['user_id'], $auth['device_id']);
        $stmt->execute();
        api_audit($db, (int) $auth['user_id'], 'api.provider_created', ['provider_uuid' => $providerUuid]);
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function api_product_provider_id(mysqli $db, $providerUuid): ?int
{
    if ($providerUuid === null || $providerUuid === '') {
        return null;
    }
    $providerUuid = api_assert_uuid($providerUuid, 'proveedor_uuid');
    $stmt = $db->prepare('SELECT id FROM proveedores WHERE uuid=? AND deleted_at IS NULL');
    $stmt->bind_param('s', $providerUuid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        api_fail(422, 'PROVIDER_NOT_FOUND', 'El proveedor seleccionado no existe.');
    }
    return (int) $row['id'];
}

function api_apply_assignment_operation(mysqli $db, array $auth, array $operation): array
{
    api_require_module($db, $auth, 'productos');
    api_require_fields($operation, ['operation_id', 'aggregate_uuid', 'payload']);
    $operationId = api_assert_uuid($operation['operation_id'], 'operation_id');
    $aggregateUuid = api_assert_uuid($operation['aggregate_uuid'], 'aggregate_uuid');
    $payload = $operation['payload'];
    api_require_fields($payload, ['producto_uuid', 'vendedor_uuid', 'activo']);
    $productUuid = api_assert_uuid($payload['producto_uuid'], 'producto_uuid');
    $sellerUuid = api_assert_uuid($payload['vendedor_uuid'], 'vendedor_uuid');
    $requestHash = hash('sha256', json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT request_hash,response_json FROM processed_operations WHERE operation_id=? FOR UPDATE');
        $stmt->bind_param('s', $operationId);
        $stmt->execute();
        $processed = $stmt->get_result()->fetch_assoc();
        if ($processed) {
            if (!hash_equals($processed['request_hash'], $requestHash)) {
                $db->rollback();
                api_fail(409, 'IDEMPOTENCY_KEY_REUSED', 'operation_id ya fue usado con otro contenido.');
            }
            $db->commit();
            return json_decode($processed['response_json'], true);
        }
        $stmt = $db->prepare(
            'SELECT p.id product_id,u.id seller_id FROM productos p
             JOIN usuarios u ON u.uuid=? AND u.activo=1
             WHERE p.uuid=? AND p.deleted_at IS NULL'
        );
        $stmt->bind_param('ss', $sellerUuid, $productUuid);
        $stmt->execute();
        $ids = $stmt->get_result()->fetch_assoc();
        if (!$ids) {
            $db->rollback();
            api_fail(422, 'ASSIGNMENT_REFERENCE_NOT_FOUND', 'Producto o vendedor no existe.');
        }
        $active = (int) ((bool) $payload['activo']);
        $stmt = $db->prepare(
            'INSERT INTO vendedor_productos
             (uuid,vendedor_id,producto_id,asignado_por,activo,version,origin_device_id)
             VALUES(?,?,?,?,?,1,?)
             ON DUPLICATE KEY UPDATE activo=VALUES(activo),version=version+1,
               origin_device_id=VALUES(origin_device_id)'
        );
        $stmt->bind_param(
            'siiiis',
            $aggregateUuid, $ids['seller_id'], $ids['product_id'],
            $auth['user_id'], $active, $auth['device_id']
        );
        $stmt->execute();
        $eventId = api_uuid();
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            'INSERT INTO change_log
             (event_id,entity_type,entity_uuid,operation_type,entity_version,
              payload_json,origin_device_id,operation_id)
             VALUES(?,\'asignacion_producto\',?,\'update\',1,?,?,?)'
        );
        $stmt->bind_param('sssss', $eventId, $aggregateUuid, $payloadJson, $auth['device_id'], $operationId);
        $stmt->execute();
        $revision = (int) $db->insert_id;
        $result = [
            'operation_id' => $operationId, 'status' => 'applied',
            'aggregate_uuid' => $aggregateUuid, 'version' => 1,
            'server_revision' => $revision,
        ];
        $responseJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            'INSERT INTO processed_operations
             (operation_id,device_id,aggregate_type,aggregate_uuid,request_hash,response_json)
             VALUES(?,?,\'asignacion_producto\',?,?,?)'
        );
        $stmt->bind_param('sssss', $operationId, $auth['device_id'], $aggregateUuid, $requestHash, $responseJson);
        $stmt->execute();
        api_audit($db, (int) $auth['user_id'], 'api.product_seller_assigned', $payload);
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function api_sync_pull(mysqli $db, array $auth): void
{
    if (!$auth['device_id']) {
        api_fail(409, 'DEVICE_REQUIRED', 'Registre el dispositivo antes de sincronizar.');
    }
    $cursor = api_query_int('cursor', 0, 0, PHP_INT_MAX);
    $limit = api_query_int('limit', 100, 1, 500);
    $stmt = $db->prepare(
        'SELECT server_revision,event_id,entity_type,entity_uuid,operation_type,
                entity_version,payload_json,origin_device_id,operation_id,changed_at
         FROM change_log WHERE server_revision>? ORDER BY server_revision LIMIT ?'
    );
    $stmt->bind_param('ii', $cursor, $limit);
    $stmt->execute();
    $events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($events as &$event) {
        $event['payload'] = $event['payload_json'] ? json_decode($event['payload_json'], true) : null;
        unset($event['payload_json']);
        $event['server_revision'] = (int) $event['server_revision'];
        $event['entity_version'] = (int) $event['entity_version'];
    }
    unset($event);
    $next = $events ? end($events)['server_revision'] : $cursor;
    api_ok(['events' => $events, 'cursor' => $next, 'has_more' => count($events) === $limit]);
}

function api_sync_ack(mysqli $db, array $auth): void
{
    if (!$auth['device_id']) {
        api_fail(409, 'DEVICE_REQUIRED', 'Registre el dispositivo antes de sincronizar.');
    }
    $body = api_json_body();
    api_require_fields($body, ['server_revision']);
    if (!is_int($body['server_revision']) || $body['server_revision'] < 0) {
        api_fail(422, 'VALIDATION_ERROR', 'server_revision inválida.');
    }
    $stmt = $db->prepare(
        'INSERT INTO sync_acks(device_id,server_revision)
         VALUES(?,?) ON DUPLICATE KEY UPDATE
         server_revision=GREATEST(server_revision,VALUES(server_revision)),
         acknowledged_at=UTC_TIMESTAMP(6)'
    );
    $stmt->bind_param('si', $auth['device_id'], $body['server_revision']);
    $stmt->execute();
    api_ok(['acknowledged' => $body['server_revision']]);
}

function api_sync_status(mysqli $db, array $auth): void
{
    if (!$auth['device_id']) {
        api_fail(409, 'DEVICE_REQUIRED', 'Registre el dispositivo antes de sincronizar.');
    }
    $stmt = $db->prepare(
        'SELECT d.device_id,d.status,d.last_seen_at,d.app_version,
                COALESCE(a.server_revision,0) acknowledged_revision,
                (SELECT COALESCE(MAX(server_revision),0) FROM change_log) latest_revision
         FROM devices d LEFT JOIN sync_acks a ON a.device_id=d.device_id
         WHERE d.device_id=?'
    );
    $stmt->bind_param('s', $auth['device_id']);
    $stmt->execute();
    $status = $stmt->get_result()->fetch_assoc();
    if (!$status) {
        api_fail(404, 'DEVICE_NOT_FOUND', 'No se encontró el dispositivo.');
    }
    $status['acknowledged_revision'] = (int) $status['acknowledged_revision'];
    $status['latest_revision'] = (int) $status['latest_revision'];
    api_ok($status);
}
