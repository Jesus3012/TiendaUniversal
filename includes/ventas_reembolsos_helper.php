<?php
declare(strict_types=1);

/**
 * Utilidades compartidas para cancelaciones, devoluciones y Mercado Pago Point.
 *
 * Requisitos:
 * - Ejecutar migracion_reembolsos_y_plazos.sql.
 * - Tener cURL habilitado en PHP.
 * - Definir MP_ACCESS_TOKEN en includes/mercadopago_config.php o como variable de entorno.
 */

/*
 * Compatibilidad con PHP 7.4.
 * str_contains() existe únicamente desde PHP 8.0.
 */
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('vrh_contiene')) {
    function vrh_contiene(string $texto, string $busqueda): bool
    {
        return $busqueda === '' || strpos($texto, $busqueda) !== false;
    }
}

if (!function_exists('vrh_responder')) {
    function vrh_responder(array $data, int $http = 200): void
    {
        http_response_code($http);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('vrh_json_entrada')) {
    function vrh_json_entrada(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true);

        if (!is_array($data)) {
            $data = $_POST ?? [];
        }

        return is_array($data) ? $data : [];
    }
}

if (!function_exists('vrh_tabla_existe')) {
    function vrh_tabla_existe(mysqli $conn, string $tabla): bool
    {
        $stmt = $conn->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $tabla);
        $stmt->execute();
        $existe = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();

        return $existe;
    }
}

if (!function_exists('vrh_columna_existe')) {
    function vrh_columna_existe(mysqli $conn, string $tabla, string $columna): bool
    {
        $stmt = $conn->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ss', $tabla, $columna);
        $stmt->execute();
        $existe = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();

        return $existe;
    }
}

if (!function_exists('vrh_normalizar_metodo')) {
    function vrh_normalizar_metodo(?string $metodo): string
    {
        $metodo = mb_strtolower(trim((string) $metodo), 'UTF-8');
        $metodo = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', ' ', '-', '/', '.'],
            ['a', 'e', 'i', 'o', 'u', 'u', '_', '_', '_', '_'],
            $metodo
        );
        $metodo = preg_replace('/_+/', '_', $metodo) ?? $metodo;
        $metodo = trim($metodo, '_');

        /*
         * Compatibilidad con todos los valores que puede guardar el sistema:
         * tarjeta_debito, tarjeta_credito, debit_card, credit_card,
         * débito, crédito, tarjeta, efectivo, cash, transferencia y SPEI.
         */
        if (
            vrh_contiene($metodo, 'tarjeta_debito')
            || vrh_contiene($metodo, 'debito')
            || vrh_contiene($metodo, 'debit_card')
            || $metodo === 'debit'
        ) {
            return 'tarjeta_debito';
        }

        if (
            vrh_contiene($metodo, 'tarjeta_credito')
            || vrh_contiene($metodo, 'credito')
            || vrh_contiene($metodo, 'credit_card')
            || $metodo === 'credit'
        ) {
            return 'tarjeta_credito';
        }

        if (
            vrh_contiene($metodo, 'transfer')
            || vrh_contiene($metodo, 'spei')
            || vrh_contiene($metodo, 'deposito')
        ) {
            return 'transferencia';
        }

        if (
            vrh_contiene($metodo, 'efect')
            || $metodo === 'cash'
            || $metodo === 'contado'
        ) {
            return 'efectivo';
        }

        /* Valor genérico heredado: se conserva la decisión anterior. */
        if (
            vrh_contiene($metodo, 'tarjeta')
            || $metodo === 'card'
            || $metodo === 'mercado_pago'
            || $metodo === 'mercadopago'
        ) {
            return 'tarjeta_credito';
        }

        return $metodo;
    }
}

if (!function_exists('vrh_es_tarjeta')) {
    function vrh_es_tarjeta(string $metodo): bool
    {
        return in_array(vrh_normalizar_metodo($metodo), ['tarjeta_debito', 'tarjeta_credito'], true);
    }
}

if (!function_exists('vrh_precio_unitario')) {
    function vrh_precio_unitario(array $venta): float
    {
        $precio = (float) ($venta['precio_unitario'] ?? 0);

        if ($precio <= 0) {
            $subtotal = (float) ($venta['subtotal'] ?? 0);
            $cantidad = max((int) ($venta['cantidad_vendida'] ?? 0), 1);
            $precio = $subtotal > 0 ? $subtotal / $cantidad : 0;
        }

        if ($precio <= 0) {
            $precio = (float) ($venta['precio_venta'] ?? 0);
        }

        return round(max($precio, 0), 2);
    }
}

if (!function_exists('vrh_total_devuelto')) {
    function vrh_total_devuelto(mysqli $conn, int $ventaId): int
    {
        if (!vrh_tabla_existe($conn, 'devoluciones_parciales')) {
            return 0;
        }

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(cantidad_devuelta), 0) AS total
            FROM devoluciones_parciales
            WHERE id_venta = ?
        ");
        $stmt->bind_param('i', $ventaId);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return max($total, 0);
    }
}

if (!function_exists('vrh_total_cancelado')) {
    function vrh_total_cancelado(mysqli $conn, int $ventaId, int $cantidadVendida): int
    {
        if (!vrh_tabla_existe($conn, 'ventas_canceladas')) {
            return 0;
        }

        $stmt = $conn->prepare("
            SELECT cantidad_devuelta
            FROM ventas_canceladas
            WHERE id_venta = ?
            ORDER BY id ASC
        ");
        $stmt->bind_param('i', $ventaId);
        $stmt->execute();
        $result = $stmt->get_result();

        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $cantidad = (int) ($row['cantidad_devuelta'] ?? 0);
            $total += $cantidad > 0 ? $cantidad : $cantidadVendida;
        }

        $stmt->close();

        return min(max($total, 0), $cantidadVendida);
    }
}

if (!function_exists('vrh_cantidad_disponible')) {
    function vrh_cantidad_disponible(mysqli $conn, array $venta): int
    {
        $cantidad = max((int) ($venta['cantidad_vendida'] ?? 0), 0);
        $ventaId = (int) ($venta['id'] ?? 0);

        if ($ventaId <= 0 || $cantidad <= 0) {
            return 0;
        }

        return max(
            $cantidad
            - vrh_total_devuelto($conn, $ventaId)
            - vrh_total_cancelado($conn, $ventaId, $cantidad),
            0
        );
    }
}

if (!function_exists('vrh_obtener_politica')) {
    function vrh_obtener_politica(mysqli $conn, string $metodo): array
    {
        static $cachePoliticas = [];

        $metodo = vrh_normalizar_metodo($metodo);

        if (isset($cachePoliticas[$metodo])) {
            return $cachePoliticas[$metodo];
        }

        if (!vrh_tabla_existe($conn, 'configuracion_cancelaciones')) {
            throw new RuntimeException(
                'Falta la tabla configuracion_cancelaciones. Ejecuta migracion_reembolsos_y_plazos.sql.'
            );
        }

        $stmt = $conn->prepare("
            SELECT
                metodo_pago,
                permite_cancelacion_total,
                dias_cancelacion_total,
                permite_devolucion_parcial,
                dias_devolucion_parcial,
                activo
            FROM configuracion_cancelaciones
            WHERE metodo_pago = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $metodo);
        $stmt->execute();
        $politica = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$politica) {
            throw new RuntimeException("No existe una política de cancelación para el método {$metodo}.");
        }

        $cachePoliticas[$metodo] = $politica;

        return $politica;
    }
}

if (!function_exists('vrh_fecha_local')) {
    function vrh_fecha_local(string $fecha, DateTimeZone $zona): DateTimeImmutable
    {
        $fecha = trim($fecha);

        if ($fecha === '') {
            throw new RuntimeException('La venta no tiene una fecha válida para calcular el plazo.');
        }

        $formatos = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
            DateTimeInterface::ATOM,
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'd/m/Y',
        ];

        foreach ($formatos as $formato) {
            $valor = DateTimeImmutable::createFromFormat('!' . $formato, $fecha, $zona);
            $errores = DateTimeImmutable::getLastErrors();

            if ($valor !== false && ($errores === false || (($errores['warning_count'] ?? 0) === 0 && ($errores['error_count'] ?? 0) === 0))) {
                return $valor->setTimezone($zona);
            }
        }

        try {
            return new DateTimeImmutable($fecha, $zona);
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo interpretar la fecha de la venta: ' . $fecha);
        }
    }
}

if (!function_exists('vrh_validar_plazo')) {
    function vrh_validar_plazo(
        mysqli $conn,
        string $metodo,
        string $fechaVenta,
        string $tipo
    ): array {
        $politica = vrh_obtener_politica($conn, $metodo);
        $metodoNormalizado = vrh_normalizar_metodo($metodo);

        if ((int) ($politica['activo'] ?? 0) !== 1) {
            throw new RuntimeException('Las cancelaciones están desactivadas para esta forma de pago.');
        }

        $esTotal = $tipo === 'total';
        $permitido = $esTotal
            ? (int) ($politica['permite_cancelacion_total'] ?? 0)
            : (int) ($politica['permite_devolucion_parcial'] ?? 0);

        if ($permitido !== 1) {
            throw new RuntimeException(
                $esTotal
                    ? 'La cancelación total está desactivada para esta forma de pago.'
                    : 'La devolución parcial está desactivada para esta forma de pago.'
            );
        }

        /*
         * Se respeta exactamente el número guardado en la tabla.
         * No se aplica un límite oculto de 90 días para tarjetas.
         */
        $diasPermitidos = $esTotal
            ? (int) ($politica['dias_cancelacion_total'] ?? 0)
            : (int) ($politica['dias_devolucion_parcial'] ?? 0);

        if ($diasPermitidos < 0) {
            $diasPermitidos = 0;
        }

        $zona = new DateTimeZone('America/Mexico_City');

        /*
         * Los plazos se calculan por DÍAS DE CALENDARIO, no por bloques de
         * 24 horas. Una venta realizada ayer cuenta como 1 día aunque todavía
         * no se cumpla la misma hora de hoy.
         */
        $venta = vrh_fecha_local($fechaVenta, $zona)->setTime(0, 0, 0);
        $hoy = (new DateTimeImmutable('now', $zona))->setTime(0, 0, 0);

        if ($venta > $hoy) {
            $diasTranscurridos = 0;
        } else {
            $diasTranscurridos = (int) $venta->diff($hoy)->days;
        }

        $fechaLimite = $venta
            ->modify('+' . $diasPermitidos . ' days')
            ->setTime(23, 59, 59);

        $vencido = $hoy > $fechaLimite;
        $diasRestantes = max($diasPermitidos - $diasTranscurridos, 0);

        if ($vencido) {
            throw new RuntimeException(
                'El plazo venció el ' . $fechaLimite->format('d/m/Y')
                . ". Han transcurrido {$diasTranscurridos} día(s) y la configuración para {$metodoNormalizado} permite {$diasPermitidos} día(s)."
            );
        }

        return [
            'metodo_pago' => $metodoNormalizado,
            'dias_transcurridos' => $diasTranscurridos,
            'dias_permitidos' => $diasPermitidos,
            'dias_restantes' => $diasRestantes,
            'fecha_venta' => $venta->format('Y-m-d'),
            'fecha_actual' => $hoy->format('Y-m-d'),
            'fecha_limite' => $fechaLimite->format('Y-m-d'),
            'fecha_limite_formateada' => $fechaLimite->format('d/m/Y'),
            'vencido' => false,
        ];
    }
}

if (!function_exists('vrh_extraer_ids_mp')) {
    function vrh_extraer_ids_mp(string $referencia): array
    {
        $orderId = '';
        $paymentId = '';

        if (preg_match('/Orden:\s*([A-Za-z0-9_-]+)/i', $referencia, $m)) {
            $orderId = trim($m[1]);
        }

        if (preg_match('/Pago:\s*([A-Za-z0-9_-]+)/i', $referencia, $m)) {
            $paymentId = trim($m[1]);
        }

        return [
            'order_id' => $orderId,
            'payment_id' => $paymentId,
        ];
    }
}

if (!function_exists('vrh_cargar_config_mp')) {
    function vrh_cargar_config_mp(): void
    {
        $archivos = [
            __DIR__ . '/mercadopago_config.php',
            __DIR__ . '/mercadopago_point.php',
            __DIR__ . '/mercadopago_helpers.php',
            dirname(__DIR__) . '/includes/mercadopago_config.php',
            dirname(__DIR__) . '/includes/mercadopago_point.php',
            dirname(__DIR__) . '/includes/mercadopago_helpers.php',
        ];

        foreach (array_unique($archivos) as $archivo) {
            if (is_file($archivo)) {
                require_once $archivo;
            }
        }
    }
}

if (!function_exists('vrh_access_token')) {
    function vrh_access_token(): string
    {
        vrh_cargar_config_mp();

        $constantes = [
            'MP_ACCESS_TOKEN',
            'MERCADOPAGO_ACCESS_TOKEN',
            'MERCADO_PAGO_ACCESS_TOKEN',
            'MP_ACCESS_TOKEN_PROD',
        ];

        foreach ($constantes as $constante) {
            if (defined($constante)) {
                $valor = trim((string) constant($constante));
                if ($valor !== '') {
                    return $valor;
                }
            }
        }

        $variables = [
            'MP_ACCESS_TOKEN',
            'MERCADOPAGO_ACCESS_TOKEN',
            'MERCADO_PAGO_ACCESS_TOKEN',
        ];

        foreach ($variables as $variable) {
            $valor = trim((string) (getenv($variable) ?: ''));
            if ($valor !== '') {
                return $valor;
            }
        }

        throw new RuntimeException(
            'No se encontró el Access Token de Mercado Pago. Define MP_ACCESS_TOKEN en includes/mercadopago_config.php.'
        );
    }
}

if (!function_exists('vrh_uuid_desde_semilla')) {
    function vrh_uuid_desde_semilla(string $semilla): string
    {
        $hex = substr(hash('sha256', $semilla), 0, 32);
        $hex[12] = '4';
        $n = hexdec($hex[16]);
        $hex[16] = dechex(($n & 0x3) | 0x8);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

if (!function_exists('vrh_mp_request')) {
    function vrh_mp_request(
        string $metodo,
        string $ruta,
        ?array $body = null,
        ?string $idempotencyKey = null,
        array $headersExtra = []
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('La extensión cURL de PHP no está habilitada.');
        }

        $token = vrh_access_token();
        $url = 'https://api.mercadopago.com' . $ruta;
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
        }

        foreach ($headersExtra as $header) {
            if (trim((string) $header) !== '') {
                $headers[] = (string) $header;
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($metodo),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return [
                'ok' => false,
                'http_status' => 0,
                'data' => [],
                'raw' => '',
                'message' => 'No fue posible comunicarse con Mercado Pago: ' . $curlError,
            ];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = [];
        }

        $message = (string) (
            $data['message']
            ?? $data['error']
            ?? $data['status_detail']
            ?? ''
        );

        if ($message === '' && isset($data['errors'][0]['message'])) {
            $message = (string) $data['errors'][0]['message'];
        }

        return [
            'ok' => $http >= 200 && $http < 300,
            'http_status' => $http,
            'data' => $data,
            'raw' => $raw,
            'message' => $message,
        ];
    }
}

if (!function_exists('vrh_pago_desde_order')) {
    function vrh_pago_desde_order(array $order): array
    {
        $payments = $order['transactions']['payments'] ?? [];

        if (isset($payments['id'])) {
            $payments = [$payments];
        }

        if (!is_array($payments)) {
            $payments = [];
        }

        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }

            $status = mb_strtolower((string) ($payment['status'] ?? ''), 'UTF-8');
            if (in_array($status, ['processed', 'approved'], true) || $status === '') {
                return $payment;
            }
        }

        return isset($payments[0]) && is_array($payments[0]) ? $payments[0] : [];
    }
}

if (!function_exists('vrh_buscar_operacion_mp')) {
    function vrh_buscar_operacion_mp(mysqli $conn, string $orderId, string $folio): ?array
    {
        if (!vrh_tabla_existe($conn, 'mercadopago_operaciones')) {
            return null;
        }

        $tieneFolio = vrh_columna_existe($conn, 'mercadopago_operaciones', 'folio_ticket');

        if ($orderId !== '') {
            $stmt = $conn->prepare("
                SELECT *
                FROM mercadopago_operaciones
                WHERE order_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->bind_param('s', $orderId);
        } elseif ($tieneFolio && $folio !== '') {
            $stmt = $conn->prepare("
                SELECT *
                FROM mercadopago_operaciones
                WHERE folio_ticket = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->bind_param('s', $folio);
        } else {
            return null;
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }
}

if (!function_exists('vrh_monto_reembolsado_local')) {
    function vrh_monto_reembolsado_local(mysqli $conn, string $orderId): float
    {
        if (!vrh_tabla_existe($conn, 'mercadopago_reembolsos')) {
            return 0.0;
        }

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(monto), 0) AS total
            FROM mercadopago_reembolsos
            WHERE order_id = ?
              AND accion IN ('reembolso_total', 'reembolso_parcial')
              AND LOWER(status) IN (
                    'processed', 'approved', 'refunded',
                    'processing', 'pending', 'accepted'
              )
        ");
        $stmt->bind_param('s', $orderId);
        $stmt->execute();
        $total = (float) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return round(max($total, 0), 2);
    }
}

if (!function_exists('vrh_log_reembolso_existente')) {
    function vrh_log_reembolso_existente(mysqli $conn, string $idempotencyKey): ?array
    {
        if (!vrh_tabla_existe($conn, 'mercadopago_reembolsos')) {
            throw new RuntimeException(
                'Falta la tabla mercadopago_reembolsos. Ejecuta migracion_reembolsos_y_plazos.sql.'
            );
        }

        $stmt = $conn->prepare("
            SELECT *
            FROM mercadopago_reembolsos
            WHERE idempotency_key = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }
}

if (!function_exists('vrh_estado_mp_aceptado')) {
    function vrh_estado_mp_aceptado(string $status): bool
    {
        return in_array(
            mb_strtolower(trim($status), 'UTF-8'),
            ['processed', 'approved', 'refunded', 'processing', 'pending', 'accepted', 'canceled', 'cancelled'],
            true
        );
    }
}

if (!function_exists('vrh_insertar_solicitud_reembolso')) {
    function vrh_insertar_solicitud_reembolso(
        mysqli $conn,
        string $folio,
        ?int $ventaId,
        ?int $operacionId,
        string $orderId,
        ?string $transactionId,
        string $accion,
        float $monto,
        string $idempotencyKey,
        string $motivo,
        int $usuarioId
    ): void {
        $stmt = $conn->prepare("
            INSERT INTO mercadopago_reembolsos (
                folio_ticket,
                venta_id,
                mercadopago_operacion_id,
                order_id,
                transaction_id,
                accion,
                monto,
                status,
                idempotency_key,
                motivo,
                usuario_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'solicitado', ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                updated_at = CURRENT_TIMESTAMP
        ");

        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la bitácora de reembolso: ' . $conn->error);
        }

        $stmt->bind_param(
            'siisssdssi',
            $folio,
            $ventaId,
            $operacionId,
            $orderId,
            $transactionId,
            $accion,
            $monto,
            $idempotencyKey,
            $motivo,
            $usuarioId
        );
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('vrh_actualizar_log_reembolso')) {
    function vrh_actualizar_log_reembolso(
        mysqli $conn,
        string $idempotencyKey,
        string $status,
        int $httpStatus,
        ?string $refundId,
        ?string $referenceId,
        array $respuesta
    ): void {
        $raw = json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $conn->prepare("
            UPDATE mercadopago_reembolsos
            SET status = ?,
                http_status = ?,
                refund_id = ?,
                reference_id = ?,
                raw_response_json = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE idempotency_key = ?
        ");
        $stmt->bind_param(
            'sissss',
            $status,
            $httpStatus,
            $refundId,
            $referenceId,
            $raw,
            $idempotencyKey
        );
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('vrh_actualizar_operacion_mp')) {
    function vrh_actualizar_operacion_mp(
        mysqli $conn,
        string $orderId,
        string $folio,
        ?string $paymentId,
        float $montoReembolsado,
        string $orderStatus,
        array $orderData
    ): void {
        if (!vrh_tabla_existe($conn, 'mercadopago_operaciones')) {
            return;
        }

        $raw = json_encode($orderData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tieneFolio = vrh_columna_existe($conn, 'mercadopago_operaciones', 'folio_ticket');

        if ($tieneFolio) {
            $stmt = $conn->prepare("
                UPDATE mercadopago_operaciones
                SET folio_ticket = COALESCE(NULLIF(folio_ticket, ''), ?),
                    payment_id = COALESCE(NULLIF(payment_id, ''), ?),
                    refunded_amount = GREATEST(refunded_amount, ?),
                    order_status = ?,
                    raw_order_json = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE order_id = ?
            ");
            $stmt->bind_param('ssdsss', $folio, $paymentId, $montoReembolsado, $orderStatus, $raw, $orderId);
        } else {
            $stmt = $conn->prepare("
                UPDATE mercadopago_operaciones
                SET payment_id = COALESCE(NULLIF(payment_id, ''), ?),
                    refunded_amount = GREATEST(refunded_amount, ?),
                    order_status = ?,
                    raw_order_json = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE order_id = ?
            ");
            $stmt->bind_param('sdsss', $paymentId, $montoReembolsado, $orderStatus, $raw, $orderId);
        }

        if ($stmt) {
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('vrh_procesar_reembolso_mp')) {
    /**
     * Ejecuta cancelación de una order no cobrada o reembolso total/parcial.
     *
     * $tipo: total | parcial
     * $monto: importe exacto a devolver según precios guardados en ventas.
     * $semilla: debe cambiar cuando cambia la operación local, pero permanecer
     * estable durante un reintento del mismo movimiento.
     */
    function vrh_procesar_reembolso_mp(
        mysqli $conn,
        array $ventaBase,
        string $folio,
        float $monto,
        string $tipo,
        string $semilla,
        string $motivo,
        int $usuarioId
    ): array {
        $metodo = vrh_normalizar_metodo((string) ($ventaBase['metodo_pago'] ?? ''));

        if (!vrh_es_tarjeta($metodo)) {
            return [
                'ok' => true,
                'aplica' => false,
                'status' => 'no_aplica',
                'message' => 'La forma de pago no requiere una llamada a Mercado Pago.',
                'monto' => round($monto, 2),
            ];
        }

        $ids = vrh_extraer_ids_mp((string) ($ventaBase['referencia_pago'] ?? ''));
        $orderId = $ids['order_id'];
        $paymentIdReferencia = $ids['payment_id'];
        $operacion = vrh_buscar_operacion_mp($conn, $orderId, $folio);

        if ($orderId === '' && $operacion) {
            $orderId = trim((string) ($operacion['order_id'] ?? ''));
        }

        if ($orderId === '') {
            throw new RuntimeException(
                'La venta es con tarjeta, pero no tiene el ID de orden de Mercado Pago. No se restauró stock ni se cambió el estado.'
            );
        }

        $consulta = vrh_mp_request('GET', '/v1/orders/' . rawurlencode($orderId));

        if (!$consulta['ok']) {
            throw new RuntimeException(
                'No se pudo consultar la orden en Mercado Pago. ' .
                ($consulta['message'] !== '' ? $consulta['message'] : 'HTTP ' . $consulta['http_status'])
            );
        }

        $order = $consulta['data'];
        $orderStatus = mb_strtolower(trim((string) ($order['status'] ?? '')), 'UTF-8');
        $payment = vrh_pago_desde_order($order);
        $paymentId = trim((string) ($payment['id'] ?? $paymentIdReferencia));
        $paidAmount = (float) (
            $payment['amount']
            ?? $payment['paid_amount']
            ?? $operacion['paid_amount']
            ?? $operacion['amount']
            ?? 0
        );

        $monto = round(max($monto, 0), 2);
        $operacionId = $operacion ? (int) ($operacion['id'] ?? 0) : null;
        $ventaId = isset($ventaBase['id']) ? (int) $ventaBase['id'] : null;
        $idempotencyKey = vrh_uuid_desde_semilla(
            $orderId . '|' . $folio . '|' . $tipo . '|' . number_format($monto, 2, '.', '') . '|' . $semilla
        );

        $existente = vrh_log_reembolso_existente($conn, $idempotencyKey);
        if ($existente && vrh_estado_mp_aceptado((string) ($existente['status'] ?? ''))) {
            return [
                'ok' => true,
                'aplica' => true,
                'reutilizado' => true,
                'status' => (string) $existente['status'],
                'message' => 'Mercado Pago ya había aceptado esta misma solicitud. Se reutilizó la operación idempotente.',
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'refund_id' => $existente['refund_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'monto' => (float) ($existente['monto'] ?? $monto),
            ];
        }

        if ($orderStatus === 'created' || $orderStatus === 'at_terminal') {
            if ($tipo !== 'total') {
                throw new RuntimeException(
                    'La orden todavía no está cobrada. No se puede hacer una devolución parcial; primero debe cancelarse la orden completa.'
                );
            }

            $accion = 'cancelacion_order';
            vrh_insertar_solicitud_reembolso(
                $conn,
                $folio,
                $ventaId,
                $operacionId,
                $orderId,
                $paymentId !== '' ? $paymentId : null,
                $accion,
                0.00,
                $idempotencyKey,
                $motivo,
                $usuarioId
            );

            $headersExtra = $orderStatus === 'at_terminal'
                ? ['X-Allow-Cancelable-Status: at_terminal']
                : [];

            $respuesta = vrh_mp_request(
                'POST',
                '/v1/orders/' . rawurlencode($orderId) . '/cancel',
                null,
                $idempotencyKey,
                $headersExtra
            );

            $status = mb_strtolower((string) (
                $respuesta['data']['status']
                ?? ($respuesta['ok'] ? 'canceled' : 'rejected')
            ), 'UTF-8');

            vrh_actualizar_log_reembolso(
                $conn,
                $idempotencyKey,
                $status,
                (int) $respuesta['http_status'],
                null,
                $respuesta['data']['reference_id'] ?? null,
                $respuesta['data'] ?: ['message' => $respuesta['message']]
            );

            if (!$respuesta['ok']) {
                throw new RuntimeException(
                    'Mercado Pago no permitió cancelar la orden. ' .
                    ($respuesta['message'] !== '' ? $respuesta['message'] : 'HTTP ' . $respuesta['http_status'])
                );
            }

            vrh_actualizar_operacion_mp($conn, $orderId, $folio, $paymentId, 0, 'canceled', $respuesta['data']);

            return [
                'ok' => true,
                'aplica' => true,
                'status' => $status,
                'message' => 'La orden pendiente fue cancelada en Mercado Pago.',
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'idempotency_key' => $idempotencyKey,
                'monto' => 0.00,
            ];
        }

        if (!in_array($orderStatus, ['processed', 'refunded', 'partially_refunded'], true)) {
            throw new RuntimeException(
                "La orden de Mercado Pago tiene estado '{$orderStatus}' y no admite devolución automática."
            );
        }

        if ($paymentId === '') {
            throw new RuntimeException(
                'Mercado Pago no devolvió el identificador de la transacción de pago. No se procesó la devolución.'
            );
        }

        if ($paidAmount <= 0) {
            // Como respaldo, usa el total registrado localmente para una venta completa.
            $paidAmount = max((float) ($operacion['amount'] ?? 0), $monto);
        }

        $yaReembolsado = vrh_monto_reembolsado_local($conn, $orderId);
        $disponible = round(max($paidAmount - $yaReembolsado, 0), 2);

        if ($monto <= 0) {
            throw new RuntimeException('El monto calculado para el reembolso no es válido.');
        }

        if ($monto > $disponible + 0.01) {
            throw new RuntimeException(
                'El importe solicitado excede lo que queda disponible por devolver en Mercado Pago. ' .
                'Disponible: $' . number_format($disponible, 2) . '.'
            );
        }

        $esTotalSinReembolsosPrevios =
            $tipo === 'total'
            && $yaReembolsado <= 0.01
            && abs($monto - $paidAmount) <= 0.01;

        $accion = $esTotalSinReembolsosPrevios ? 'reembolso_total' : 'reembolso_parcial';
        $body = $esTotalSinReembolsosPrevios
            ? null
            : [
                'transactions' => [[
                    'id' => $paymentId,
                    'amount' => number_format($monto, 2, '.', ''),
                ]],
            ];

        vrh_insertar_solicitud_reembolso(
            $conn,
            $folio,
            $ventaId,
            $operacionId,
            $orderId,
            $paymentId,
            $accion,
            $monto,
            $idempotencyKey,
            $motivo,
            $usuarioId
        );

        $respuesta = vrh_mp_request(
            'POST',
            '/v1/orders/' . rawurlencode($orderId) . '/refund',
            $body,
            $idempotencyKey
        );

        $refundData = $respuesta['data'];
        $refundItem = $refundData['transactions']['refunds'][0]
            ?? $refundData['refunds'][0]
            ?? [];
        if (!is_array($refundItem)) {
            $refundItem = [];
        }

        $status = mb_strtolower((string) (
            $refundItem['status']
            ?? $refundData['status']
            ?? ($respuesta['ok'] ? 'accepted' : 'rejected')
        ), 'UTF-8');
        $refundId = (string) (
            $refundItem['id']
            ?? $refundData['refund_id']
            ?? ''
        );
        $referenceId = (string) (
            $refundItem['reference_id']
            ?? $refundData['reference_id']
            ?? ''
        );

        vrh_actualizar_log_reembolso(
            $conn,
            $idempotencyKey,
            $status,
            (int) $respuesta['http_status'],
            $refundId !== '' ? $refundId : null,
            $referenceId !== '' ? $referenceId : null,
            $refundData ?: ['message' => $respuesta['message']]
        );

        if (!$respuesta['ok'] || !vrh_estado_mp_aceptado($status)) {
            $detalle = $respuesta['message'] !== ''
                ? $respuesta['message']
                : 'HTTP ' . $respuesta['http_status'];

            throw new RuntimeException(
                'Mercado Pago rechazó el reembolso. ' . $detalle .
                ' No se restauró stock ni se cambió el estado local.'
            );
        }

        $nuevoReembolsado = round($yaReembolsado + $monto, 2);
        $nuevoEstado = $nuevoReembolsado + 0.01 >= $paidAmount
            ? 'refunded'
            : 'partially_refunded';

        vrh_actualizar_operacion_mp(
            $conn,
            $orderId,
            $folio,
            $paymentId,
            $nuevoReembolsado,
            $nuevoEstado,
            $refundData
        );

        return [
            'ok' => true,
            'aplica' => true,
            'status' => $status,
            'message' => $accion === 'reembolso_total'
                ? 'Mercado Pago aceptó el reembolso total.'
                : 'Mercado Pago aceptó el reembolso parcial.',
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'refund_id' => $refundId !== '' ? $refundId : null,
            'reference_id' => $referenceId !== '' ? $referenceId : null,
            'idempotency_key' => $idempotencyKey,
            'monto' => $monto,
            'accion' => $accion,
        ];
    }
}

if (!function_exists('vrh_insertar_auditoria')) {
    function vrh_insertar_auditoria(
        mysqli $conn,
        int $usuarioId,
        string $accion,
        string $detalle
    ): void {
        if (!vrh_tabla_existe($conn, 'auditoria')) {
            return;
        }

        $stmt = $conn->prepare("
            INSERT INTO auditoria (usuario_id, accion, detalle, ip, fecha)
            VALUES (?, ?, ?, ?, NOW())
        ");

        if (!$stmt) {
            return;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt->bind_param('isss', $usuarioId, $accion, $detalle, $ip);
        $stmt->execute();
        $stmt->close();
    }
}
