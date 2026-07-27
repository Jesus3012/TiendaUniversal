<?php
include 'includes/session.php';
include 'includes/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('No se encontró la conexión $conn. Revisa includes/db.php');
}

$conn->set_charset('utf8mb4');
date_default_timezone_set('America/Mexico_City');

/* ================== FUNCIONES BASE ================== */

function caja_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function caja_money($value) {
    return '$' . number_format((float)$value, 2, '.', ',');
}

function caja_to_float($value) {
    $value = str_replace(',', '', (string)$value);
    return is_numeric($value) ? (float)$value : 0.00;
}

function caja_usuario_id() {
    return $_SESSION['usuario_id']
        ?? $_SESSION['id_usuario']
        ?? $_SESSION['id']
        ?? null;
}

function caja_usuario_nombre() {
    return $_SESSION['usuario_nombre']
        ?? $_SESSION['nombre_usuario']
        ?? $_SESSION['nombre']
        ?? 'Usuario del sistema';
}

function caja_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);

    if ($types !== '' && count($params) > 0) {
        $bind = [];
        $bind[] = $types;

        foreach ($params as $key => $value) {
            $bind[] = &$params[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function caja_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $rows = caja_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function caja_execute(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);

    if ($types !== '' && count($params) > 0) {
        $bind = [];
        $bind[] = $types;

        foreach ($params as $key => $value) {
            $bind[] = &$params[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    $stmt->execute();
    $insertId = $stmt->insert_id;
    $stmt->close();

    return $insertId;
}

function caja_execute_affected(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);

    if ($types !== '' && count($params) > 0) {
        $bind = [];
        $bind[] = $types;

        foreach ($params as $key => $value) {
            $bind[] = &$params[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    return $affectedRows;
}

function caja_redirect(array $params = []) {
    $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');

    if (!empty($params)) {
        header('Location: ' . $baseUrl . '?' . http_build_query($params));
    } else {
        header('Location: ' . $baseUrl);
    }

    exit;
}

function caja_registrar_auditoria(mysqli $conn, $usuarioId, $accion, $detalle) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        caja_execute(
            $conn,
            "INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?, ?, ?, ?)",
            "isss",
            [$usuarioId, $accion, $detalle, $ip]
        );
    } catch (Throwable $e) {
        // No detenemos caja si falla auditoría general.
    }
}

/* ================== CAJA ================== */

function caja_obtener_abierta(mysqli $conn) {
    return caja_fetch_one(
        $conn,
        "SELECT * FROM caja_sesiones WHERE estado = 'abierta' ORDER BY id DESC LIMIT 1"
    );
}

function caja_obtener_historial(mysqli $conn) {
    return caja_fetch_all(
        $conn,
        "SELECT * FROM caja_sesiones ORDER BY created_at DESC LIMIT 12"
    );
}

function caja_guardar_movimiento(
    mysqli $conn,
    $cajaId,
    $tipo,
    $concepto,
    $monto,
    $usuarioId,
    $usuarioNombre,
    $observaciones = ''
) {
    return caja_execute(
        $conn,
        "
        INSERT INTO caja_movimientos (
            caja_id,
            tipo,
            concepto,
            monto,
            usuario_id,
            usuario_nombre,
            observaciones
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ",
        "issdiss",
        [
            (int)$cajaId,
            $tipo,
            $concepto,
            (float)$monto,
            $usuarioId,
            $usuarioNombre,
            $observaciones
        ]
    );
}

function caja_obtener_movimientos(mysqli $conn, $cajaId) {
    return caja_fetch_all(
        $conn,
        "SELECT * FROM caja_movimientos WHERE caja_id = ? ORDER BY fecha DESC",
        "i",
        [(int)$cajaId]
    );
}

function caja_obtener_totales_movimientos(mysqli $conn, $cajaId) {
    $row = caja_fetch_one(
        $conn,
        "
        SELECT
            COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN monto ELSE 0 END), 0) AS entradas,
            COALESCE(SUM(CASE WHEN tipo = 'salida' THEN monto ELSE 0 END), 0) AS salidas
        FROM caja_movimientos
        WHERE caja_id = ?
        ",
        "i",
        [(int)$cajaId]
    );

    return [
        'entradas' => (float)($row['entradas'] ?? 0),
        'salidas' => (float)($row['salidas'] ?? 0),
    ];
}

/* ================== VENTAS ================== */

function caja_joins_devoluciones() {
    return "
        LEFT JOIN (
            SELECT id_venta, SUM(cantidad_devuelta) AS cantidad_devuelta
            FROM devoluciones_parciales
            GROUP BY id_venta
        ) dp ON dp.id_venta = v.id

        LEFT JOIN (
            SELECT 
                id_venta,
                SUM(cantidad_devuelta) AS cantidad_cancelada,
                COUNT(*) AS total_cancelaciones
            FROM ventas_canceladas
            GROUP BY id_venta
        ) vc ON vc.id_venta = v.id
    ";
}

function caja_expresiones_venta() {
    $cancelada = "
        (
            CASE
                WHEN vc.total_cancelaciones IS NULL THEN 0
                WHEN IFNULL(vc.cantidad_cancelada, 0) = 0 THEN v.cantidad_vendida
                ELSE vc.cantidad_cancelada
            END
        )
    ";

    $devuelta = "IFNULL(dp.cantidad_devuelta, 0)";

    $neta = "
        GREATEST(
            v.cantidad_vendida - {$devuelta} - {$cancelada},
            0
        )
    ";

    $metodo = "
        CASE
            WHEN LOWER(TRIM(IFNULL(v.metodo_pago, ''))) LIKE '%efect%'
                OR LOWER(TRIM(IFNULL(v.metodo_pago, ''))) = 'cash'
                THEN 'efectivo'

            WHEN LOWER(TRIM(IFNULL(v.metodo_pago, ''))) LIKE '%tarj%'
                OR LOWER(TRIM(IFNULL(v.metodo_pago, ''))) LIKE '%deb%'
                OR LOWER(TRIM(IFNULL(v.metodo_pago, ''))) LIKE '%cred%'
                OR LOWER(TRIM(IFNULL(v.metodo_pago, ''))) LIKE '%créd%'
                THEN 'tarjeta'

            WHEN LOWER(TRIM(IFNULL(v.metodo_pago, ''))) LIKE '%trans%'
                OR LOWER(TRIM(IFNULL(v.metodo_pago, ''))) LIKE '%spei%'
                THEN 'transferencia'

            ELSE 'otros'
        END
    ";

    return [$cancelada, $devuelta, $neta, $metodo];
}

function caja_obtener_resumen_ventas(mysqli $conn, $fechaInicio, $fechaFin) {
    [, , $neta, $metodo] = caja_expresiones_venta();
    $precioVentaReal = "COALESCE(NULLIF(v.precio_unitario, 0), p.precio_venta)";

    $sql = "
        SELECT
            COALESCE(SUM({$neta} * {$precioVentaReal}), 0) AS ventas_sistema,
            COALESCE(SUM(CASE WHEN {$metodo} = 'efectivo' THEN {$neta} * {$precioVentaReal} ELSE 0 END), 0) AS efectivo_sistema,
            COALESCE(SUM(CASE WHEN {$metodo} = 'tarjeta' THEN {$neta} * {$precioVentaReal} ELSE 0 END), 0) AS tarjeta_sistema,
            COALESCE(SUM(CASE WHEN {$metodo} = 'transferencia' THEN {$neta} * {$precioVentaReal} ELSE 0 END), 0) AS transferencia_sistema,
            COALESCE(SUM(CASE WHEN {$metodo} = 'otros' THEN {$neta} * {$precioVentaReal} ELSE 0 END), 0) AS otros_sistema,
            COALESCE(SUM({$neta}), 0) AS total_piezas,
            COALESCE(SUM({$neta} * ({$precioVentaReal} - p.precio_compra)), 0) AS utilidad_estimada,
            COUNT(DISTINCT IFNULL(v.folio_ticket, CONCAT('VENTA-', v.id))) AS total_tickets
        FROM ventas v
        INNER JOIN productos p ON p.id = v.id_producto
        " . caja_joins_devoluciones() . "
        WHERE v.fecha_venta >= ? AND v.fecha_venta <= ?
    ";

    $row = caja_fetch_one($conn, $sql, 'ss', [$fechaInicio, $fechaFin]);

    return [
        'ventas_sistema' => (float)($row['ventas_sistema'] ?? 0),
        'efectivo_sistema' => (float)($row['efectivo_sistema'] ?? 0),
        'tarjeta_sistema' => (float)($row['tarjeta_sistema'] ?? 0),
        'transferencia_sistema' => (float)($row['transferencia_sistema'] ?? 0),
        'otros_sistema' => (float)($row['otros_sistema'] ?? 0),
        'total_piezas' => (float)($row['total_piezas'] ?? 0),
        'utilidad_estimada' => (float)($row['utilidad_estimada'] ?? 0),
        'total_tickets' => (int)($row['total_tickets'] ?? 0),
    ];
}

function caja_contar_ventas(mysqli $conn, $fechaInicio, $fechaFin) {
    $row = caja_fetch_one(
        $conn,
        "
        SELECT COUNT(*) AS total
        FROM ventas v
        WHERE v.fecha_venta >= ? AND v.fecha_venta <= ?
        ",
        "ss",
        [$fechaInicio, $fechaFin]
    );

    return (int)($row['total'] ?? 0);
}

function caja_obtener_ventas_paginadas(mysqli $conn, $fechaInicio, $fechaFin, $pagina = 1, $porPagina = 10) {
    $pagina = max(1, (int)$pagina);
    $porPagina = max(5, (int)$porPagina);
    $offset = ($pagina - 1) * $porPagina;

    $sql = "
        SELECT
            v.id,
            v.folio_ticket,
            v.fecha_venta,
            v.metodo_pago,
            v.referencia_pago,
            v.cantidad_vendida,
            p.nombre AS producto_nombre,
            COALESCE(NULLIF(v.precio_unitario, 0), p.precio_venta) AS precio_venta,
            p.precio_compra,
            u.nombre AS vendedor_nombre,
            COALESCE(
                NULLIF(v.subtotal, 0),
                v.cantidad_vendida * COALESCE(NULLIF(v.precio_unitario, 0), p.precio_venta)
            ) AS subtotal,
            (
                COALESCE(
                    NULLIF(v.subtotal, 0),
                    v.cantidad_vendida * COALESCE(NULLIF(v.precio_unitario, 0), p.precio_venta)
                )
                - (v.cantidad_vendida * p.precio_compra)
            ) AS utilidad_estimada
        FROM ventas v
        INNER JOIN productos p ON p.id = v.id_producto
        LEFT JOIN usuarios u ON u.id = v.id_vendedor
        WHERE v.fecha_venta >= ? AND v.fecha_venta <= ?
        ORDER BY v.fecha_venta DESC, v.id DESC
        LIMIT ? OFFSET ?
    ";

    return caja_fetch_all($conn, $sql, "ssii", [$fechaInicio, $fechaFin, $porPagina, $offset]);
}

function caja_guardar_ventas_auditoria(mysqli $conn, $cajaId, $fechaInicio, $fechaFin) {
    $sql = "
        INSERT INTO caja_ventas_auditoria (
            caja_id,
            venta_id,
            folio_ticket,
            id_producto,
            producto_nombre,
            id_vendedor,
            vendedor_nombre,
            cantidad_vendida,
            metodo_pago,
            referencia_pago,
            precio_venta,
            precio_compra,
            subtotal,
            utilidad_estimada,
            fecha_venta
        )
        SELECT
            ? AS caja_id,
            v.id AS venta_id,
            v.folio_ticket,
            v.id_producto,
            p.nombre AS producto_nombre,
            v.id_vendedor,
            u.nombre AS vendedor_nombre,
            v.cantidad_vendida,
            v.metodo_pago,
            v.referencia_pago,
            COALESCE(NULLIF(v.precio_unitario, 0), p.precio_venta) AS precio_venta,
            p.precio_compra,
            COALESCE(
                NULLIF(v.subtotal, 0),
                v.cantidad_vendida * COALESCE(NULLIF(v.precio_unitario, 0), p.precio_venta)
            ) AS subtotal,
            (
                COALESCE(
                    NULLIF(v.subtotal, 0),
                    v.cantidad_vendida * COALESCE(NULLIF(v.precio_unitario, 0), p.precio_venta)
                )
                - (v.cantidad_vendida * p.precio_compra)
            ) AS utilidad_estimada,
            v.fecha_venta
        FROM ventas v
        INNER JOIN productos p ON p.id = v.id_producto
        LEFT JOIN usuarios u ON u.id = v.id_vendedor
        WHERE v.fecha_venta >= ?
          AND v.fecha_venta <= ?
        ON DUPLICATE KEY UPDATE
            folio_ticket = VALUES(folio_ticket),
            id_producto = VALUES(id_producto),
            producto_nombre = VALUES(producto_nombre),
            id_vendedor = VALUES(id_vendedor),
            vendedor_nombre = VALUES(vendedor_nombre),
            cantidad_vendida = VALUES(cantidad_vendida),
            metodo_pago = VALUES(metodo_pago),
            referencia_pago = VALUES(referencia_pago),
            precio_venta = VALUES(precio_venta),
            precio_compra = VALUES(precio_compra),
            subtotal = VALUES(subtotal),
            utilidad_estimada = VALUES(utilidad_estimada),
            fecha_venta = VALUES(fecha_venta),
            fecha_auditoria = CURRENT_TIMESTAMP
    ";

    return caja_execute_affected($conn, $sql, "iss", [(int)$cajaId, $fechaInicio, $fechaFin]);
}

/* ================== ACCIONES POST ANTES DE IMPRIMIR HTML ================== */

$usuarioId = caja_usuario_id();
$usuarioNombre = caja_usuario_nombre();

$error = '';
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'abrir_caja') {
            $cajaAbierta = caja_obtener_abierta($conn);

            if ($cajaAbierta) {
                throw new Exception('Ya existe una caja abierta. Primero debes cerrar la caja actual.');
            }

            $montoInicial = caja_to_float($_POST['monto_inicial'] ?? 0);
            $observaciones = trim((string)($_POST['observaciones_apertura'] ?? ''));

            if ($montoInicial < 0) {
                throw new Exception('El monto inicial no puede ser negativo.');
            }

            $folio = 'CAJA-' . date('Ymd-His') . '-' . random_int(100, 999);

            $conn->begin_transaction();

            $cajaId = caja_execute(
                $conn,
                "
                INSERT INTO caja_sesiones (
                    folio_caja,
                    usuario_apertura_id,
                    usuario_apertura_nombre,
                    monto_inicial,
                    observaciones_apertura
                ) VALUES (?, ?, ?, ?, ?)
                ",
                "sisds",
                [
                    $folio,
                    $usuarioId,
                    $usuarioNombre,
                    $montoInicial,
                    $observaciones
                ]
            );

            caja_guardar_movimiento(
                $conn,
                $cajaId,
                'apertura',
                'Apertura de caja',
                $montoInicial,
                $usuarioId,
                $usuarioNombre,
                $observaciones
            );

            caja_registrar_auditoria(
                $conn,
                $usuarioId,
                'ABRIR_CAJA',
                'Se abrió la caja ' . $folio . ' con monto inicial ' . caja_money($montoInicial)
            );

            $conn->commit();

            caja_redirect(['abierta' => 1]);
        }

        if ($accion === 'agregar_movimiento') {
            $cajaAbierta = caja_obtener_abierta($conn);

            if (!$cajaAbierta) {
                throw new Exception('No hay una caja abierta.');
            }

            $tipo = $_POST['tipo'] ?? '';
            $concepto = trim((string)($_POST['concepto'] ?? ''));
            $monto = caja_to_float($_POST['monto'] ?? 0);
            $observaciones = trim((string)($_POST['observaciones'] ?? ''));

            if (!in_array($tipo, ['entrada', 'salida'], true)) {
                throw new Exception('Selecciona si el movimiento es entrada o salida.');
            }

            if ($concepto === '') {
                throw new Exception('Escribe el concepto del movimiento.');
            }

            if ($monto <= 0) {
                throw new Exception('El monto debe ser mayor a 0.');
            }

            caja_guardar_movimiento(
                $conn,
                (int)$cajaAbierta['id'],
                $tipo,
                $concepto,
                $monto,
                $usuarioId,
                $usuarioNombre,
                $observaciones
            );

            caja_registrar_auditoria(
                $conn,
                $usuarioId,
                'MOVIMIENTO_CAJA',
                ucfirst($tipo) . ' de caja por ' . caja_money($monto) . '. Concepto: ' . $concepto
            );

            caja_redirect(['movimiento' => 1]);
        }

        if ($accion === 'cerrar_caja') {
            $cajaAbierta = caja_obtener_abierta($conn);

            if (!$cajaAbierta) {
                throw new Exception('No hay una caja abierta para cerrar.');
            }

            $fechaInicio = $cajaAbierta['fecha_apertura'];
            $fechaFin = date('Y-m-d H:i:s');

            $resumen = caja_obtener_resumen_ventas($conn, $fechaInicio, $fechaFin);
            $movs = caja_obtener_totales_movimientos($conn, (int)$cajaAbierta['id']);

            $efectivoContado = caja_to_float($_POST['efectivo_contado'] ?? 0);
            $observacionesCierre = trim((string)($_POST['observaciones_cierre'] ?? ''));

            if ($efectivoContado < 0) {
                throw new Exception('El efectivo contado no puede ser negativo.');
            }

            $montoInicial = (float)$cajaAbierta['monto_inicial'];
            $entradas = (float)$movs['entradas'];
            $salidas = (float)$movs['salidas'];

            $efectivoEsperado = $montoInicial + $resumen['efectivo_sistema'] + $entradas - $salidas;
            $diferencia = $efectivoContado - $efectivoEsperado;

            $totalVentasParaAuditoria = caja_contar_ventas($conn, $fechaInicio, $fechaFin);

            $conn->begin_transaction();

            $filasAuditoriaVentas = caja_guardar_ventas_auditoria(
                $conn,
                (int)$cajaAbierta['id'],
                $fechaInicio,
                $fechaFin
            );

            caja_execute(
                $conn,
                "
                UPDATE caja_sesiones
                SET
                    estado = 'cerrada',
                    usuario_cierre_id = ?,
                    usuario_cierre_nombre = ?,
                    fecha_cierre = ?,

                    ventas_sistema = ?,
                    efectivo_sistema = ?,
                    tarjeta_sistema = ?,
                    transferencia_sistema = ?,
                    otros_sistema = ?,

                    entradas_efectivo = ?,
                    salidas_efectivo = ?,
                    efectivo_esperado = ?,
                    efectivo_contado = ?,
                    diferencia_efectivo = ?,

                    total_tickets = ?,
                    total_piezas = ?,
                    utilidad_estimada = ?,
                    observaciones_cierre = ?
                WHERE id = ?
                ",
                "issddddddddddiddsi",
                [
                    $usuarioId,
                    $usuarioNombre,
                    $fechaFin,

                    $resumen['ventas_sistema'],
                    $resumen['efectivo_sistema'],
                    $resumen['tarjeta_sistema'],
                    $resumen['transferencia_sistema'],
                    $resumen['otros_sistema'],

                    $entradas,
                    $salidas,
                    $efectivoEsperado,
                    $efectivoContado,
                    $diferencia,

                    $resumen['total_tickets'],
                    $resumen['total_piezas'],
                    $resumen['utilidad_estimada'],
                    $observacionesCierre,
                    (int)$cajaAbierta['id']
                ]
            );

            caja_guardar_movimiento(
                $conn,
                (int)$cajaAbierta['id'],
                'cierre',
                'Cierre de caja',
                $efectivoContado,
                $usuarioId,
                $usuarioNombre,
                $observacionesCierre
            );

            caja_registrar_auditoria(
                $conn,
                $usuarioId,
                'AUDITORIA_VENTAS_CAJA',
                'Caja ' . $cajaAbierta['folio_caja'] .
                '. Ventas detectadas: ' . $totalVentasParaAuditoria .
                '. Filas afectadas auditoría: ' . $filasAuditoriaVentas
            );

            caja_registrar_auditoria(
                $conn,
                $usuarioId,
                'CERRAR_CAJA',
                'Se cerró la caja ' . $cajaAbierta['folio_caja'] . '. Diferencia: ' . caja_money($diferencia)
            );

            $conn->commit();

            caja_redirect(['cerrada' => 1]);
        }
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {}

        $error = $e->getMessage();
    }
}

/* ================== CARGA DE DATOS ================== */

$cajaAbierta = caja_obtener_abierta($conn);
$historial = caja_obtener_historial($conn);

$resumen = [
    'ventas_sistema' => 0,
    'efectivo_sistema' => 0,
    'tarjeta_sistema' => 0,
    'transferencia_sistema' => 0,
    'otros_sistema' => 0,
    'total_piezas' => 0,
    'utilidad_estimada' => 0,
    'total_tickets' => 0,
];

$movimientos = [];
$totalesMovimientos = ['entradas' => 0, 'salidas' => 0];
$ventasCaja = [];
$totalVentasAuditoria = 0;
$totalPaginasVentas = 1;
$paginaVentas = isset($_GET['pagina_ventas']) ? max(1, (int)$_GET['pagina_ventas']) : 1;
$ventasPorPagina = 10;
$efectivoEsperado = 0;
$fechaInicioCaja = null;
$fechaFinCaja = null;

if ($cajaAbierta) {
    $fechaInicioCaja = $cajaAbierta['fecha_apertura'];
    $fechaFinCaja = date('Y-m-d H:i:s');

    $resumen = caja_obtener_resumen_ventas($conn, $fechaInicioCaja, $fechaFinCaja);
    $movimientos = caja_obtener_movimientos($conn, (int)$cajaAbierta['id']);
    $totalesMovimientos = caja_obtener_totales_movimientos($conn, (int)$cajaAbierta['id']);

    $totalVentasAuditoria = caja_contar_ventas($conn, $fechaInicioCaja, $fechaFinCaja);
    $totalPaginasVentas = max(1, (int)ceil($totalVentasAuditoria / $ventasPorPagina));

    if ($paginaVentas > $totalPaginasVentas) {
        $paginaVentas = $totalPaginasVentas;
    }

    $ventasCaja = caja_obtener_ventas_paginadas(
        $conn,
        $fechaInicioCaja,
        $fechaFinCaja,
        $paginaVentas,
        $ventasPorPagina
    );

    $efectivoEsperado =
        (float)$cajaAbierta['monto_inicial']
        + (float)$resumen['efectivo_sistema']
        + (float)$totalesMovimientos['entradas']
        - (float)$totalesMovimientos['salidas'];
}

if (isset($_GET['abierta'])) {
    $mensaje = 'Caja abierta correctamente.';
}

if (isset($_GET['movimiento'])) {
    $mensaje = 'Movimiento registrado correctamente.';
}

if (isset($_GET['cerrada'])) {
    $mensaje = 'Caja cerrada correctamente.';
}

/* ================== HTML ================== */

include 'includes/header.php';
include 'includes/navbar.php';

$metodosNoEfectivo =
    (float)$resumen['tarjeta_sistema']
    + (float)$resumen['transferencia_sistema']
    + (float)$resumen['otros_sistema'];

$datosPdfCajaActual = null;

if ($cajaAbierta) {
    $datosPdfCajaActual = [
        'folio' => $cajaAbierta['folio_caja'] ?? '',
        'estado' => 'abierta',
        'usuario_apertura' => $cajaAbierta['usuario_apertura_nombre'] ?? $usuarioNombre,
        'usuario_cierre' => '',
        'apertura' => !empty($cajaAbierta['fecha_apertura'])
            ? date('d/m/Y H:i', strtotime($cajaAbierta['fecha_apertura']))
            : '',
        'cierre' => '',
        'monto_inicial' => (float)($cajaAbierta['monto_inicial'] ?? 0),
        'ventas' => (float)$resumen['ventas_sistema'],
        'efectivo' => (float)$resumen['efectivo_sistema'],
        'tarjeta' => (float)$resumen['tarjeta_sistema'],
        'transferencia' => (float)$resumen['transferencia_sistema'],
        'otros' => (float)$resumen['otros_sistema'],
        'entradas' => (float)$totalesMovimientos['entradas'],
        'salidas' => (float)$totalesMovimientos['salidas'],
        'esperado' => (float)$efectivoEsperado,
        'contado' => null,
        'diferencia' => null,
        'tickets' => (int)$resumen['total_tickets'],
        'piezas' => (float)$resumen['total_piezas'],
        'observaciones_apertura' => $cajaAbierta['observaciones_apertura'] ?? '',
        'observaciones_cierre' => '',
        'generado' => date('d/m/Y H:i'),
    ];
}
?>

<link rel="stylesheet" href="css/corte_caja.css?v=<?= time() ?>">

<div class="content-wrapper caja-page">
    <section class="content-header caja-page-header">
        <div class="container-fluid">
            <div class="caja-heading-row">
                <div class="caja-heading">
                    <div class="caja-heading-icon" aria-hidden="true">
                        <i class="fas fa-cash-register"></i>
                    </div>

                    <div>
                        <span class="caja-eyebrow">Operación diaria</span>
                        <h1>Control de caja</h1>
                        <p>
                            <?php if ($cajaAbierta): ?>
                                Revisa el dinero del turno, registra movimientos y realiza el cierre.
                            <?php else: ?>
                                Abre una caja para comenzar a contabilizar las ventas del turno.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="caja-header-actions no-print">
                    <?php if ($cajaAbierta): ?>
                        <span class="caja-state is-open">
                            <span class="caja-state-dot"></span>
                            Caja abierta
                        </span>

                        <button type="button" class="caja-button is-secondary is-pdf" id="btnDescargarCortePdf">
                            <i class="fas fa-file-pdf"></i>
                            <span>Corte en PDF</span>
                        </button>
                    <?php else: ?>
                        <span class="caja-state is-closed">
                            <i class="fas fa-lock"></i>
                            Caja cerrada
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?php if (!$cajaAbierta): ?>
                <div class="caja-start-tip-toolbar no-print">
                    <button
                        type="button"
                        class="caja-tip-toggle"
                        id="btnToggleConsejoCaja"
                        aria-controls="cajaConsejoApertura"
                        aria-expanded="false"
                    >
                        <span class="caja-tip-toggle-icon" aria-hidden="true">
                            <i class="fas fa-lightbulb"></i>
                        </span>
                        <span class="caja-tip-toggle-copy">
                            <strong id="textoToggleConsejoCaja">Ver consejo de apertura</strong>
                            <small id="ayudaToggleConsejoCaja">Consulta cómo iniciar y cerrar el turno correctamente.</small>
                        </span>
                        <i class="fas fa-chevron-down caja-tip-toggle-chevron" aria-hidden="true"></i>
                    </button>
                </div>

                <section class="caja-start-layout is-tip-hidden" id="cajaStartLayout">
                    <aside
                        class="caja-start-tip"
                        id="cajaConsejoApertura"
                        aria-label="Consejo para abrir la caja"
                        hidden
                    >
                        <div class="caja-start-tip-icon" aria-hidden="true">
                            <i class="fas fa-lightbulb"></i>
                        </div>

                        <div class="caja-start-tip-content">
                            <span class="caja-section-kicker">Consejo antes de comenzar</span>
                            <h2>Registra el efectivo disponible para dar cambio</h2>
                            <p>
                                La caja empezará a contabilizar las ventas desde el momento en que la abras.
                                Al terminar el turno, podrás comparar el efectivo contado contra el esperado.
                            </p>

                            <div class="caja-start-tip-points">
                                <span><i class="fas fa-check"></i> Cuenta el fondo inicial</span>
                                <span><i class="fas fa-check"></i> Registra entradas y salidas</span>
                                <span><i class="fas fa-check"></i> Cierra al finalizar el turno</span>
                            </div>
                        </div>
                    </aside>

                    <article class="caja-panel caja-start-form-panel">
                        <div class="caja-panel-heading caja-opening-heading">
                            <div>
                                <span class="caja-section-kicker">Nueva apertura</span>
                                <h2>Abrir caja</h2>
                                <p>Indica con cuánto efectivo inicia este turno.</p>
                            </div>

                            <span class="caja-opening-state">
                                <i class="fas fa-lock"></i>
                                Aún cerrada
                            </span>
                        </div>

                        <form method="POST" id="formAbrirCaja" novalidate>
                            <input type="hidden" name="accion" value="abrir_caja">

                            <div class="caja-field">
                                <label for="monto_inicial">
                                    Monto inicial
                                    <span>Obligatorio</span>
                                </label>

                                <div class="caja-money-field">
                                    <span>$</span>
                                    <input
                                        type="number"
                                        id="monto_inicial"
                                        name="monto_inicial"
                                        step="0.50"
                                        min="0"
                                        inputmode="decimal"
                                        placeholder="0.00"
                                        required
                                    >
                                </div>

                                <small>Escribe el efectivo que está físicamente disponible para dar cambio.</small>
                            </div>

                            <div class="caja-field">
                                <label for="observaciones_apertura">Nota de apertura <span>Opcional</span></label>
                                <textarea
                                    id="observaciones_apertura"
                                    name="observaciones_apertura"
                                    rows="3"
                                    placeholder="Ejemplo: Se recibió completo el fondo del turno anterior."
                                ></textarea>
                            </div>

                            <button type="submit" class="caja-button is-primary is-full">
                                <i class="fas fa-lock-open"></i>
                                Abrir caja e iniciar turno
                            </button>
                        </form>
                    </article>
                </section>

            <?php else: ?>
                <section
                    class="caja-shift-banner"
                    data-opened-at="<?= caja_h(date('c', strtotime($cajaAbierta['fecha_apertura']))) ?>"
                >
                    <div class="caja-shift-main">
                        <span class="caja-live-indicator">
                            <span></span>
                            Turno activo
                        </span>

                        <div>
                            <h2><?= caja_h($cajaAbierta['folio_caja']) ?></h2>
                            <p>Todo lo registrado desde la apertura pertenece a esta caja.</p>
                        </div>
                    </div>

                    <div class="caja-shift-meta">
                        <div>
                            <small>Apertura</small>
                            <strong><?= caja_h(date('d/m/Y · H:i', strtotime($cajaAbierta['fecha_apertura']))) ?></strong>
                        </div>

                        <div>
                            <small>Responsable</small>
                            <strong><?= caja_h($cajaAbierta['usuario_apertura_nombre'] ?: $usuarioNombre) ?></strong>
                        </div>

                        <div>
                            <small>Tiempo transcurrido</small>
                            <strong id="cajaTiempoTurno">Calculando…</strong>
                        </div>
                    </div>
                </section>

                <section class="caja-metrics" aria-label="Resumen principal de la caja">
                    <article class="caja-metric is-expected">
                        <div class="caja-metric-icon"><i class="fas fa-calculator"></i></div>
                        <div>
                            <small>Efectivo esperado</small>
                            <strong><?= caja_money($efectivoEsperado) ?></strong>
                            <span>Lo que debería existir físicamente</span>
                        </div>
                    </article>

                    <article class="caja-metric">
                        <div class="caja-metric-icon"><i class="fas fa-receipt"></i></div>
                        <div>
                            <small>Ventas del turno</small>
                            <strong><?= caja_money($resumen['ventas_sistema']) ?></strong>
                            <span><?= (int)$resumen['total_tickets'] ?> tickets · <?= number_format((float)$resumen['total_piezas'], 2) ?> piezas</span>
                        </div>
                    </article>

                    <article class="caja-metric">
                        <div class="caja-metric-icon"><i class="fas fa-money-bill-wave"></i></div>
                        <div>
                            <small>Ventas en efectivo</small>
                            <strong><?= caja_money($resumen['efectivo_sistema']) ?></strong>
                            <span>Sí forman parte del efectivo esperado</span>
                        </div>
                    </article>

                    <article class="caja-metric">
                        <div class="caja-metric-icon"><i class="fas fa-chart-line"></i></div>
                        <div>
                            <small>Utilidad estimada</small>
                            <strong><?= caja_money($resumen['utilidad_estimada']) ?></strong>
                            <span>Venta menos costo de los productos</span>
                        </div>
                    </article>
                </section>

                <section class="caja-panel caja-balance-panel">
                    <div class="caja-panel-heading is-inline">
                        <div>
                            <span class="caja-section-kicker">Resumen del turno</span>
                            <h2>¿Cómo se obtiene el efectivo esperado?</h2>
                            <p>Las ventas con tarjeta o transferencia no se cuentan como dinero físico.</p>
                        </div>

                        <span class="caja-total-pill">
                            <small>Resultado</small>
                            <strong><?= caja_money($efectivoEsperado) ?></strong>
                        </span>
                    </div>

                    <div class="caja-equation" aria-label="Cálculo del efectivo esperado">
                        <div class="caja-equation-item">
                            <small>Monto inicial</small>
                            <strong><?= caja_money($cajaAbierta['monto_inicial']) ?></strong>
                        </div>
                        <span class="caja-equation-operator">+</span>
                        <div class="caja-equation-item">
                            <small>Ventas efectivo</small>
                            <strong><?= caja_money($resumen['efectivo_sistema']) ?></strong>
                        </div>
                        <span class="caja-equation-operator">+</span>
                        <div class="caja-equation-item">
                            <small>Entradas</small>
                            <strong><?= caja_money($totalesMovimientos['entradas']) ?></strong>
                        </div>
                        <span class="caja-equation-operator">−</span>
                        <div class="caja-equation-item">
                            <small>Salidas</small>
                            <strong><?= caja_money($totalesMovimientos['salidas']) ?></strong>
                        </div>
                        <span class="caja-equation-operator is-equal">=</span>
                        <div class="caja-equation-item is-result">
                            <small>Efectivo esperado</small>
                            <strong><?= caja_money($efectivoEsperado) ?></strong>
                        </div>
                    </div>

                    <div class="caja-method-grid">
                        <div class="caja-method">
                            <span class="is-cash"><i class="fas fa-money-bill-wave"></i></span>
                            <div><small>Efectivo</small><strong><?= caja_money($resumen['efectivo_sistema']) ?></strong></div>
                        </div>
                        <div class="caja-method">
                            <span class="is-card"><i class="fas fa-credit-card"></i></span>
                            <div><small>Tarjeta</small><strong><?= caja_money($resumen['tarjeta_sistema']) ?></strong></div>
                        </div>
                        <div class="caja-method">
                            <span class="is-transfer"><i class="fas fa-exchange-alt"></i></span>
                            <div><small>Transferencia</small><strong><?= caja_money($resumen['transferencia_sistema']) ?></strong></div>
                        </div>
                        <div class="caja-method">
                            <span class="is-other"><i class="fas fa-wallet"></i></span>
                            <div><small>Otros métodos</small><strong><?= caja_money($resumen['otros_sistema']) ?></strong></div>
                        </div>
                    </div>
                </section>

                <section class="caja-work-layout">
                    <article class="caja-panel caja-movements-panel">
                        <div class="caja-panel-heading is-inline">
                            <div>
                                <span class="caja-section-kicker">Dinero físico</span>
                                <h2>Entradas y salidas</h2>
                                <p>Registra efectivo agregado o retirado que no proviene de una venta.</p>
                            </div>

                            <div class="caja-inline-totals">
                                <span class="is-entry">+ <?= caja_money($totalesMovimientos['entradas']) ?></span>
                                <span class="is-exit">− <?= caja_money($totalesMovimientos['salidas']) ?></span>
                            </div>
                        </div>

                        <div class="caja-movement-layout">
                            <form method="POST" id="formMovimientoCaja" class="caja-movement-form no-print" novalidate>
                                <input type="hidden" name="accion" value="agregar_movimiento">

                                <div class="caja-field">
                                    <label for="tipo_movimiento">Tipo de movimiento</label>
                                    <select id="tipo_movimiento" name="tipo" required>
                                        <option value="entrada">Entrada de efectivo</option>
                                        <option value="salida">Salida de efectivo</option>
                                    </select>
                                </div>

                                <div class="caja-field">
                                    <label for="concepto_movimiento">Concepto</label>
                                    <input
                                        type="text"
                                        id="concepto_movimiento"
                                        name="concepto"
                                        maxlength="120"
                                        placeholder="Ejemplo: cambio adicional"
                                        required
                                    >
                                </div>

                                <div class="caja-field">
                                    <label for="monto_movimiento">Monto</label>
                                    <div class="caja-money-field">
                                        <span>$</span>
                                        <input
                                            type="number"
                                            id="monto_movimiento"
                                            name="monto"
                                            step="0.50"
                                            min="0.50"
                                            inputmode="decimal"
                                            placeholder="0.00"
                                            required
                                        >
                                    </div>
                                </div>

                                <div class="caja-field">
                                    <label for="observaciones_movimiento">Nota <span>Opcional</span></label>
                                    <textarea
                                        id="observaciones_movimiento"
                                        name="observaciones"
                                        rows="2"
                                        placeholder="Agrega un detalle si es necesario."
                                    ></textarea>
                                </div>

                                <button type="submit" class="caja-button is-dark is-full">
                                    <i class="fas fa-plus"></i>
                                    Registrar movimiento
                                </button>
                            </form>

                            <div class="caja-movement-history">
                                <div class="caja-subsection-heading">
                                    <strong>Movimientos del turno</strong>
                                    <small><?= count($movimientos) ?> registrados</small>
                                </div>

                                <?php if (count($movimientos) > 0): ?>
                                    <div class="caja-movement-list">
                                        <?php foreach ($movimientos as $mov):
                                            $tipoMovimiento = $mov['tipo'] ?? 'otro';
                                            $claseMovimiento = in_array($tipoMovimiento, ['entrada', 'apertura'], true)
                                                ? 'is-positive'
                                                : (in_array($tipoMovimiento, ['salida'], true) ? 'is-negative' : 'is-neutral');
                                            $signoMovimiento = in_array($tipoMovimiento, ['salida'], true) ? '−' : '+';
                                        ?>
                                            <div class="caja-movement-row <?= caja_h($claseMovimiento) ?>">
                                                <span class="caja-movement-icon">
                                                    <?php if ($tipoMovimiento === 'entrada'): ?>
                                                        <i class="fas fa-arrow-down"></i>
                                                    <?php elseif ($tipoMovimiento === 'salida'): ?>
                                                        <i class="fas fa-arrow-up"></i>
                                                    <?php elseif ($tipoMovimiento === 'apertura'): ?>
                                                        <i class="fas fa-lock-open"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-lock"></i>
                                                    <?php endif; ?>
                                                </span>

                                                <div class="caja-movement-copy">
                                                    <strong><?= caja_h($mov['concepto']) ?></strong>
                                                    <small>
                                                        <?= caja_h(ucfirst($tipoMovimiento)) ?> ·
                                                        <?= caja_h(date('d/m/Y H:i', strtotime($mov['fecha']))) ?>
                                                    </small>
                                                </div>

                                                <strong class="caja-movement-amount">
                                                    <?= $signoMovimiento ?><?= caja_money($mov['monto']) ?>
                                                </strong>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="caja-empty-state is-compact">
                                        <i class="fas fa-random"></i>
                                        <strong>Sin movimientos manuales</strong>
                                        <span>Las entradas y salidas aparecerán aquí.</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>

                    <aside class="caja-panel caja-close-panel">
                        <div class="caja-panel-heading">
                            <div>
                                <span class="caja-section-kicker">Finalizar turno</span>
                                <h2>Cerrar caja</h2>
                                <p>Cuenta únicamente el efectivo que tienes físicamente.</p>
                            </div>
                        </div>

                        <div class="caja-close-expected">
                            <small>Deberías contar</small>
                            <strong><?= caja_money($efectivoEsperado) ?></strong>
                            <span>Tarjeta y transferencias no se incluyen.</span>
                        </div>

                        <form method="POST" id="formCerrarCaja" class="no-print" novalidate>
                            <input type="hidden" name="accion" value="cerrar_caja">

                            <div class="caja-field">
                                <label for="efectivo_contado">Efectivo contado</label>
                                <div class="caja-money-field is-large">
                                    <span>$</span>
                                    <input
                                        type="number"
                                        id="efectivo_contado"
                                        name="efectivo_contado"
                                        step="0.50"
                                        min="0"
                                        inputmode="decimal"
                                        value="<?= caja_h(number_format($efectivoEsperado, 2, '.', '')) ?>"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="caja-difference-preview is-balanced" id="cajaDiferenciaPreview" aria-live="polite">
                                <span><i class="fas fa-check-circle"></i> Sin diferencia</span>
                                <strong>$0.00</strong>
                            </div>

                            <div class="caja-field">
                                <label for="observaciones_cierre">Nota del cierre <span>Opcional</span></label>
                                <textarea
                                    id="observaciones_cierre"
                                    name="observaciones_cierre"
                                    rows="3"
                                    placeholder="Ejemplo: Caja completa y sin incidencias."
                                ></textarea>
                            </div>

                            <button type="submit" class="caja-button is-primary is-full">
                                <i class="fas fa-lock"></i>
                                Revisar y cerrar caja
                            </button>
                        </form>
                    </aside>
                </section>

                <section class="caja-panel caja-sales-panel">
                    <div class="caja-panel-heading is-inline">
                        <div>
                            <span class="caja-section-kicker">Auditoría del turno</span>
                            <h2>Ventas registradas</h2>
                            <p>Productos vendidos desde la apertura de esta caja.</p>
                        </div>

                        <span class="caja-record-count">
                            <strong><?= (int)$totalVentasAuditoria ?></strong>
                            registros
                        </span>
                    </div>

                    <?php if (count($ventasCaja) > 0): ?>
                        <div class="caja-table-shell">
                            <table class="caja-data-table">
                                <thead>
                                    <tr>
                                        <th>Venta</th>
                                        <th>Producto</th>
                                        <th>Fecha</th>
                                        <th>Método</th>
                                        <th class="is-number">Cantidad</th>
                                        <th class="is-number">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ventasCaja as $venta): ?>
                                        <tr>
                                            <td data-label="Venta">
                                                <strong><?= caja_h($venta['folio_ticket'] ?: 'VENTA-' . $venta['id']) ?></strong>
                                                <small><?= caja_h($venta['vendedor_nombre'] ?: 'Sin vendedor') ?></small>
                                            </td>
                                            <td data-label="Producto"><?= caja_h($venta['producto_nombre']) ?></td>
                                            <td data-label="Fecha"><?= caja_h(date('d/m/Y H:i', strtotime($venta['fecha_venta']))) ?></td>
                                            <td data-label="Método">
                                                <span class="caja-payment-badge">
                                                    <?= caja_h($venta['metodo_pago'] ?: 'Sin método') ?>
                                                </span>
                                            </td>
                                            <td data-label="Cantidad" class="is-number"><?= number_format((float)$venta['cantidad_vendida'], 2) ?></td>
                                            <td data-label="Total" class="is-number"><strong><?= caja_money($venta['subtotal']) ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($totalPaginasVentas > 1): ?>
                            <nav class="caja-pagination no-print" aria-label="Paginación de ventas">
                                <?php
                                $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
                                $queryActual = $_GET;
                                unset($queryActual['abierta'], $queryActual['movimiento'], $queryActual['cerrada']);

                                $crearUrlPagina = static function ($pagina) use ($baseUrl, $queryActual) {
                                    return $baseUrl . '?' . http_build_query(array_merge($queryActual, [
                                        'pagina_ventas' => $pagina,
                                    ]));
                                };
                                ?>

                                <a
                                    href="<?= caja_h($crearUrlPagina(max(1, $paginaVentas - 1))) ?>"
                                    class="caja-page-arrow <?= $paginaVentas <= 1 ? 'is-disabled' : '' ?>"
                                    aria-label="Página anterior"
                                >
                                    <i class="fas fa-chevron-left"></i>
                                </a>

                                <span class="caja-page-status">
                                    Página <strong><?= $paginaVentas ?></strong> de <strong><?= $totalPaginasVentas ?></strong>
                                </span>

                                <a
                                    href="<?= caja_h($crearUrlPagina(min($totalPaginasVentas, $paginaVentas + 1))) ?>"
                                    class="caja-page-arrow <?= $paginaVentas >= $totalPaginasVentas ? 'is-disabled' : '' ?>"
                                    aria-label="Página siguiente"
                                >
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </nav>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="caja-empty-state">
                            <i class="fas fa-receipt"></i>
                            <strong>Aún no hay ventas en este turno</strong>
                            <span>Cuando se registre una venta aparecerá automáticamente aquí.</span>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="caja-panel caja-history-panel historial-cajas">
                <div class="caja-panel-heading is-inline caja-history-heading">
                    <div>
                        <span class="caja-section-kicker">Turnos anteriores</span>
                        <h2>Historial de cajas</h2>
                        <p>Revisa quién abrió cada caja, sus horarios, ventas y resultado del cierre.</p>
                    </div>

                    <?php if (count($historial) > 0): ?>
                        <div class="caja-history-filters no-print">
                            <div class="caja-search-field">
                                <i class="fas fa-search"></i>
                                <input type="search" id="cajaHistorialBuscar" placeholder="Buscar folio o usuario…">
                            </div>

                            <select id="cajaHistorialEstado" aria-label="Filtrar historial por estado">
                                <option value="">Todas las cajas</option>
                                <option value="abierta">Solo abiertas</option>
                                <option value="cerrada">Solo cerradas</option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (count($historial) > 0): ?>
                    <div class="caja-history-table-shell">
                        <table class="caja-history-table" id="cajaHistorialGrid">
                            <thead>
                                <tr>
                                    <th>Caja</th>
                                    <th>Estado</th>
                                    <th>Apertura</th>
                                    <th>Cierre</th>
                                    <th class="is-number">Inicial</th>
                                    <th class="is-number">Ventas</th>
                                    <th class="is-number">Resultado</th>
                                    <th class="is-action no-print">Detalle</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($historial as $caja):
                                    $estadoHistorial = $caja['estado'] ?? 'cerrada';
                                    $esCajaActivaActual = $cajaAbierta
                                        && (int)$cajaAbierta['id'] === (int)$caja['id']
                                        && $estadoHistorial === 'abierta';

                                    $ventasHistorial = $esCajaActivaActual
                                        ? (float)$resumen['ventas_sistema']
                                        : (float)($caja['ventas_sistema'] ?? 0);
                                    $efectivoHistorial = $esCajaActivaActual
                                        ? (float)$resumen['efectivo_sistema']
                                        : (float)($caja['efectivo_sistema'] ?? 0);
                                    $tarjetaHistorial = $esCajaActivaActual
                                        ? (float)$resumen['tarjeta_sistema']
                                        : (float)($caja['tarjeta_sistema'] ?? 0);
                                    $transferenciaHistorial = $esCajaActivaActual
                                        ? (float)$resumen['transferencia_sistema']
                                        : (float)($caja['transferencia_sistema'] ?? 0);
                                    $otrosHistorial = $esCajaActivaActual
                                        ? (float)$resumen['otros_sistema']
                                        : (float)($caja['otros_sistema'] ?? 0);
                                    $piezasHistorial = $esCajaActivaActual
                                        ? (float)$resumen['total_piezas']
                                        : (float)($caja['total_piezas'] ?? 0);
                                    $entradasHistorial = $esCajaActivaActual
                                        ? (float)$totalesMovimientos['entradas']
                                        : (float)($caja['entradas_efectivo'] ?? 0);
                                    $salidasHistorial = $esCajaActivaActual
                                        ? (float)$totalesMovimientos['salidas']
                                        : (float)($caja['salidas_efectivo'] ?? 0);
                                    $esperadoHistorial = $esCajaActivaActual
                                        ? (float)$efectivoEsperado
                                        : (float)($caja['efectivo_esperado'] ?? 0);
                                    $ticketsHistorial = $esCajaActivaActual
                                        ? (int)$resumen['total_tickets']
                                        : (int)($caja['total_tickets'] ?? 0);
                                    $diferenciaHistorial = $esCajaActivaActual
                                        ? 0.0
                                        : (float)($caja['diferencia_efectivo'] ?? 0);

                                    if ($estadoHistorial === 'abierta') {
                                        $claseDiferencia = 'is-pending';
                                        $textoDiferencia = 'Pendiente de cierre';
                                    } elseif (abs($diferenciaHistorial) <= 0.01) {
                                        $claseDiferencia = 'is-balanced';
                                        $textoDiferencia = 'Caja exacta';
                                    } elseif ($diferenciaHistorial > 0) {
                                        $claseDiferencia = 'is-surplus';
                                        $textoDiferencia = 'Sobrante';
                                    } else {
                                        $claseDiferencia = 'is-shortage';
                                        $textoDiferencia = 'Faltante';
                                    }

                                    $detalleCaja = [
                                        'folio' => $caja['folio_caja'],
                                        'estado' => $estadoHistorial,
                                        'usuario_apertura' => $caja['usuario_apertura_nombre'] ?? '',
                                        'usuario_cierre' => $caja['usuario_cierre_nombre'] ?? '',
                                        'apertura' => $caja['fecha_apertura']
                                            ? date('d/m/Y H:i', strtotime($caja['fecha_apertura']))
                                            : '',
                                        'cierre' => $caja['fecha_cierre']
                                            ? date('d/m/Y H:i', strtotime($caja['fecha_cierre']))
                                            : '',
                                        'monto_inicial' => (float)($caja['monto_inicial'] ?? 0),
                                        'ventas' => $ventasHistorial,
                                        'efectivo' => $efectivoHistorial,
                                        'tarjeta' => $tarjetaHistorial,
                                        'transferencia' => $transferenciaHistorial,
                                        'otros' => $otrosHistorial,
                                        'entradas' => $entradasHistorial,
                                        'salidas' => $salidasHistorial,
                                        'esperado' => $esperadoHistorial,
                                        'contado' => (float)($caja['efectivo_contado'] ?? 0),
                                        'diferencia' => $diferenciaHistorial,
                                        'tickets' => $ticketsHistorial,
                                        'piezas' => $piezasHistorial,
                                        'generado' => date('d/m/Y H:i'),
                                        'observaciones_apertura' => $caja['observaciones_apertura'] ?? '',
                                        'observaciones_cierre' => $caja['observaciones_cierre'] ?? '',
                                    ];
                                ?>
                                    <tr
                                        class="caja-history-card"
                                        data-history-state="<?= caja_h($estadoHistorial) ?>"
                                        data-history-search="<?= caja_h(strtolower(($caja['folio_caja'] ?? '') . ' ' . ($caja['usuario_apertura_nombre'] ?? '') . ' ' . ($caja['usuario_cierre_nombre'] ?? ''))) ?>"
                                    >
                                        <td data-label="Caja">
                                            <div class="caja-history-folio">
                                                <strong><?= caja_h($caja['folio_caja']) ?></strong>
                                                <small>
                                                    <i class="fas fa-user"></i>
                                                    <?= caja_h($caja['usuario_apertura_nombre'] ?: 'Sin responsable') ?>
                                                </small>
                                            </div>
                                        </td>

                                        <td data-label="Estado">
                                            <span class="caja-history-status <?= $estadoHistorial === 'abierta' ? 'is-open' : 'is-closed' ?>">
                                                <i class="fas <?= $estadoHistorial === 'abierta' ? 'fa-lock-open' : 'fa-lock' ?>"></i>
                                                <?= $estadoHistorial === 'abierta' ? 'Abierta' : 'Cerrada' ?>
                                            </span>
                                        </td>

                                        <td data-label="Apertura">
                                            <span class="caja-history-date">
                                                <?= caja_h(date('d/m/Y', strtotime($caja['fecha_apertura']))) ?>
                                                <small><?= caja_h(date('H:i', strtotime($caja['fecha_apertura']))) ?></small>
                                            </span>
                                        </td>

                                        <td data-label="Cierre">
                                            <?php if ($caja['fecha_cierre']): ?>
                                                <span class="caja-history-date">
                                                    <?= caja_h(date('d/m/Y', strtotime($caja['fecha_cierre']))) ?>
                                                    <small><?= caja_h(date('H:i', strtotime($caja['fecha_cierre']))) ?></small>
                                                </span>
                                            <?php else: ?>
                                                <span class="caja-history-running">En curso</span>
                                            <?php endif; ?>
                                        </td>

                                        <td data-label="Monto inicial" class="is-number">
                                            <strong><?= caja_money($caja['monto_inicial']) ?></strong>
                                        </td>

                                        <td data-label="Ventas" class="is-number">
                                            <strong><?= caja_money($ventasHistorial) ?></strong>
                                            <small class="caja-history-tickets"><?= $ticketsHistorial ?> tickets</small>
                                        </td>

                                        <td data-label="Resultado" class="is-number">
                                            <div class="caja-history-result">
                                                <span class="caja-history-difference <?= caja_h($claseDiferencia) ?>">
                                                    <?= caja_h($textoDiferencia) ?>
                                                </span>
                                                <?php if ($estadoHistorial !== 'abierta'): ?>
                                                    <strong><?= caja_money($diferenciaHistorial) ?></strong>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <td data-label="Detalle" class="is-action no-print">
                                            <button
                                                type="button"
                                                class="caja-history-detail"
                                                aria-label="Ver detalle de <?= caja_h($caja['folio_caja']) ?>"
                                                data-caja-detail='<?= caja_h(json_encode($detalleCaja, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
                                            >
                                                <i class="fas fa-eye"></i>
                                                <span>Ver</span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="caja-empty-state is-filtered" id="cajaHistorialSinResultados" hidden>
                        <i class="fas fa-search"></i>
                        <strong>No encontramos cajas con ese filtro</strong>
                        <span>Prueba con otro folio, usuario o estado.</span>
                    </div>
                <?php else: ?>
                    <div class="caja-empty-state">
                        <i class="fas fa-history"></i>
                        <strong>Todavía no hay cajas registradas</strong>
                        <span>Cuando abras y cierres el primer turno aparecerá aquí.</span>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
<script>
(function () {
    'use strict';

    const efectivoEsperado = <?= json_encode((float)$efectivoEsperado) ?>;
    const mensajeServidor = <?= json_encode($mensaje, JSON_UNESCAPED_UNICODE) ?>;
    const errorServidor = <?= json_encode($error, JSON_UNESCAPED_UNICODE) ?>;
    const cajaPdfActual = <?= json_encode($datosPdfCajaActual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;


    const CLAVE_CONSEJO_APERTURA = 'caja_consejo_apertura_visible_v1';
    const btnToggleConsejo = document.getElementById('btnToggleConsejoCaja');
    const consejoApertura = document.getElementById('cajaConsejoApertura');
    const layoutApertura = document.getElementById('cajaStartLayout');
    const textoToggleConsejo = document.getElementById('textoToggleConsejoCaja');
    const ayudaToggleConsejo = document.getElementById('ayudaToggleConsejoCaja');

    function leerPreferenciaConsejo() {
        try {
            return window.localStorage.getItem(CLAVE_CONSEJO_APERTURA) === 'visible';
        } catch (error) {
            return false;
        }
    }

    function guardarPreferenciaConsejo(visible) {
        try {
            window.localStorage.setItem(
                CLAVE_CONSEJO_APERTURA,
                visible ? 'visible' : 'oculto'
            );
        } catch (error) {
            // El módulo continúa funcionando aunque el navegador bloquee localStorage.
        }
    }

    function actualizarConsejoApertura(visible, guardar = false) {
        if (!btnToggleConsejo || !consejoApertura || !layoutApertura) {
            return;
        }

        consejoApertura.hidden = !visible;
        layoutApertura.classList.toggle('is-tip-hidden', !visible);
        btnToggleConsejo.classList.toggle('is-open', visible);
        btnToggleConsejo.setAttribute('aria-expanded', visible ? 'true' : 'false');

        if (textoToggleConsejo) {
            textoToggleConsejo.textContent = visible
                ? 'Ocultar consejo de apertura'
                : 'Ver consejo de apertura';
        }

        if (ayudaToggleConsejo) {
            ayudaToggleConsejo.textContent = visible
                ? 'El consejo permanecerá visible la próxima vez que regreses.'
                : 'Consulta cómo iniciar y cerrar el turno correctamente.';
        }

        if (guardar) {
            guardarPreferenciaConsejo(visible);
        }
    }

    if (btnToggleConsejo && consejoApertura && layoutApertura) {
        actualizarConsejoApertura(leerPreferenciaConsejo(), false);

        btnToggleConsejo.addEventListener('click', function () {
            const seAbrira = btnToggleConsejo.getAttribute('aria-expanded') !== 'true';
            actualizarConsejoApertura(seAbrira, true);
        });
    }

    function moneda(valor) {
        return Number(valor || 0).toLocaleString('es-MX', {
            style: 'currency',
            currency: 'MXN',
            minimumFractionDigits: 2
        });
    }

    function textoSeguro(valor) {
        const elemento = document.createElement('div');
        elemento.textContent = String(valor ?? '');
        return elemento.innerHTML;
    }

    function limpiarMensajesUrl() {
        const url = new URL(window.location.href);
        ['abierta', 'movimiento', 'cerrada'].forEach((clave) => {
            url.searchParams.delete(clave);
        });
        window.history.replaceState({}, '', url.toString());
    }

    function mostrarMensajeServidor() {
        if (errorServidor) {
            Swal.fire({
                icon: 'error',
                title: 'No se pudo completar la operación',
                text: errorServidor,
                confirmButtonText: 'Entendido',
                confirmButtonColor: '#f97316',
                customClass: { popup: 'caja-swal-popup' }
            });
            return;
        }

        if (mensajeServidor) {
            Swal.fire({
                icon: 'success',
                title: 'Operación completada',
                text: mensajeServidor,
                confirmButtonText: 'Aceptar',
                confirmButtonColor: '#16a34a',
                timer: 2600,
                timerProgressBar: true,
                customClass: { popup: 'caja-swal-popup' }
            });
            limpiarMensajesUrl();
        }
    }

    function validarFormulario(formulario) {
        const invalido = formulario.querySelector(':invalid');

        if (!invalido) {
            return true;
        }

        invalido.focus();
        Swal.fire({
            icon: 'warning',
            title: 'Falta completar un dato',
            text: invalido.validationMessage || 'Revisa los campos obligatorios.',
            confirmButtonText: 'Revisar',
            confirmButtonColor: '#f97316',
            customClass: { popup: 'caja-swal-popup' }
        });

        return false;
    }

    const formAbrir = document.getElementById('formAbrirCaja');
    if (formAbrir) {
        formAbrir.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!validarFormulario(formAbrir)) return;

            const monto = Number(formAbrir.elements.monto_inicial.value || 0);
            const observacion = formAbrir.elements.observaciones_apertura.value.trim();

            Swal.fire({
                icon: 'question',
                title: 'Confirmar apertura',
                html: `
                    <div class="caja-swal-summary">
                        <p>La caja comenzará a registrar las ventas desde este momento.</p>
                        <div class="caja-swal-amount">
                            <small>Monto inicial</small>
                            <strong>${moneda(monto)}</strong>
                        </div>
                        ${observacion ? `<div class="caja-swal-note"><strong>Nota:</strong> ${textoSeguro(observacion)}</div>` : ''}
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Abrir caja',
                cancelButtonText: 'Seguir editando',
                confirmButtonColor: '#f97316',
                cancelButtonColor: '#64748b',
                reverseButtons: true,
                customClass: { popup: 'caja-swal-popup' }
            }).then((resultado) => {
                if (resultado.isConfirmed) formAbrir.submit();
            });
        });
    }

    const formMovimiento = document.getElementById('formMovimientoCaja');
    if (formMovimiento) {
        formMovimiento.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!validarFormulario(formMovimiento)) return;

            const tipo = formMovimiento.elements.tipo.value;
            const concepto = formMovimiento.elements.concepto.value.trim();
            const monto = Number(formMovimiento.elements.monto.value || 0);
            const esEntrada = tipo === 'entrada';

            Swal.fire({
                icon: 'question',
                title: esEntrada ? 'Registrar entrada' : 'Registrar salida',
                html: `
                    <div class="caja-swal-summary">
                        <div class="caja-swal-movement ${esEntrada ? 'is-entry' : 'is-exit'}">
                            <span><i class="fas ${esEntrada ? 'fa-arrow-down' : 'fa-arrow-up'}"></i></span>
                            <div>
                                <small>${esEntrada ? 'Dinero agregado a caja' : 'Dinero retirado de caja'}</small>
                                <strong>${moneda(monto)}</strong>
                            </div>
                        </div>
                        <div class="caja-swal-note"><strong>Concepto:</strong> ${textoSeguro(concepto)}</div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: esEntrada ? 'Registrar entrada' : 'Registrar salida',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: esEntrada ? '#16a34a' : '#dc2626',
                cancelButtonColor: '#64748b',
                reverseButtons: true,
                customClass: { popup: 'caja-swal-popup' }
            }).then((resultado) => {
                if (resultado.isConfirmed) formMovimiento.submit();
            });
        });
    }

    const inputContado = document.getElementById('efectivo_contado');
    const previewDiferencia = document.getElementById('cajaDiferenciaPreview');

    function actualizarDiferencia() {
        if (!inputContado || !previewDiferencia) return 0;

        const contado = Number(inputContado.value || 0);
        const diferencia = contado - efectivoEsperado;
        const equilibrada = Math.abs(diferencia) <= 0.01;

        previewDiferencia.className = 'caja-difference-preview';

        if (equilibrada) {
            previewDiferencia.classList.add('is-balanced');
            previewDiferencia.querySelector('span').innerHTML = '<i class="fas fa-check-circle"></i> Sin diferencia';
        } else if (diferencia > 0) {
            previewDiferencia.classList.add('is-surplus');
            previewDiferencia.querySelector('span').innerHTML = '<i class="fas fa-arrow-up"></i> Sobrante';
        } else {
            previewDiferencia.classList.add('is-shortage');
            previewDiferencia.querySelector('span').innerHTML = '<i class="fas fa-arrow-down"></i> Faltante';
        }

        previewDiferencia.querySelector('strong').textContent = moneda(diferencia);
        return diferencia;
    }

    if (inputContado) {
        inputContado.addEventListener('input', actualizarDiferencia);
        actualizarDiferencia();
    }

    const formCerrar = document.getElementById('formCerrarCaja');
    if (formCerrar) {
        formCerrar.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!validarFormulario(formCerrar)) return;

            const contado = Number(formCerrar.elements.efectivo_contado.value || 0);
            const diferencia = contado - efectivoEsperado;
            const equilibrada = Math.abs(diferencia) <= 0.01;
            const estado = equilibrada ? 'Sin diferencia' : (diferencia > 0 ? 'Sobrante' : 'Faltante');

            Swal.fire({
                icon: equilibrada ? 'question' : 'warning',
                title: 'Revisar cierre de caja',
                html: `
                    <div class="caja-swal-summary">
                        <div class="caja-swal-lines">
                            <div><span>Efectivo esperado</span><strong>${moneda(efectivoEsperado)}</strong></div>
                            <div><span>Efectivo contado</span><strong>${moneda(contado)}</strong></div>
                            <div class="is-difference ${equilibrada ? 'is-ok' : 'is-alert'}">
                                <span>${estado}</span>
                                <strong>${moneda(diferencia)}</strong>
                            </div>
                        </div>
                        <p class="caja-swal-warning">
                            Al confirmar se cerrará el turno y ya no podrá recibir nuevas ventas.
                        </p>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: equilibrada ? 'Cerrar caja' : 'Cerrar con diferencia',
                cancelButtonText: 'Volver a revisar',
                confirmButtonColor: equilibrada ? '#f97316' : '#dc2626',
                cancelButtonColor: '#64748b',
                reverseButtons: true,
                customClass: { popup: 'caja-swal-popup' }
            }).then((resultado) => {
                if (resultado.isConfirmed) formCerrar.submit();
            });
        });
    }

    function cajaPdfNumero(value) {
        const numero = Number(value);
        return Number.isFinite(numero) ? numero : 0;
    }

    function cajaPdfMoneda(value) {
        return cajaPdfNumero(value).toLocaleString('es-MX', {
            style: 'currency',
            currency: 'MXN',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function cajaPdfTexto(value, fallback = '-') {
        const texto = String(value ?? '').trim();
        return texto || fallback;
    }

    function cajaPdfNombreArchivo(value) {
        return String(value || 'caja')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-zA-Z0-9_-]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .toLowerCase();
    }

    function cajaGenerarPdf(datos) {
        if (!datos) {
            Swal.fire({
                icon: 'error',
                title: 'No hay datos para exportar',
                text: 'Abre una caja o selecciona un registro del historial.',
                confirmButtonColor: '#f97316'
            });
            return;
        }

        if (!window.jspdf?.jsPDF) {
            Swal.fire({
                icon: 'error',
                title: 'No se pudo generar el PDF',
                text: 'La librería del PDF no terminó de cargar. Actualiza la página e inténtalo nuevamente.',
                confirmButtonColor: '#f97316'
            });
            return;
        }

        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
        const pageWidth = doc.internal.pageSize.getWidth();
        const pageHeight = doc.internal.pageSize.getHeight();
        const margin = 16;
        const orange = [249, 115, 22];
        const orangeDark = [234, 88, 12];
        const orangeSoft = [255, 247, 237];
        const navy = [15, 23, 42];
        const slate = [71, 85, 105];
        const muted = [100, 116, 139];
        const border = [226, 232, 240];
        const green = [22, 163, 74];
        const red = [220, 38, 38];
        const amber = [217, 119, 6];
        const estaAbierta = datos.estado === 'abierta';
        const diferencia = datos.diferencia === null || datos.diferencia === undefined
            ? null
            : cajaPdfNumero(datos.diferencia);
        const equilibrada = diferencia !== null && Math.abs(diferencia) <= 0.01;
        const estadoResultado = estaAbierta
            ? 'CORTE PRELIMINAR'
            : (equilibrada ? 'CAJA EXACTA' : (diferencia > 0 ? 'SOBRANTE' : 'FALTANTE'));
        const colorResultado = estaAbierta
            ? orange
            : (equilibrada ? green : (diferencia > 0 ? amber : red));

        doc.setFillColor(...orange);
        doc.rect(0, 0, pageWidth, 6, 'F');

        doc.setFillColor(...orangeSoft);
        doc.roundedRect(margin, 16, 18, 18, 4, 4, 'F');
        doc.setTextColor(...orangeDark);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(13);
        doc.text('$', margin + 9, 27.5, { align: 'center' });

        doc.setTextColor(...navy);
        doc.setFontSize(18);
        doc.text('CORTE DE CAJA', margin + 23, 22.5);
        doc.setFont('helvetica', 'normal');
        doc.setTextColor(...muted);
        doc.setFontSize(9);
        doc.text(estaAbierta ? 'Resumen del turno en curso' : 'Resumen final del turno', margin + 23, 29);

        const badgeWidth = doc.getTextWidth(estadoResultado) + 12;
        doc.setFillColor(...colorResultado);
        doc.roundedRect(pageWidth - margin - badgeWidth, 19, badgeWidth, 9, 4.5, 4.5, 'F');
        doc.setTextColor(255, 255, 255);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(7.2);
        doc.text(estadoResultado, pageWidth - margin - badgeWidth / 2, 24.8, { align: 'center' });

        doc.setDrawColor(...border);
        doc.line(margin, 40, pageWidth - margin, 40);

        const metaY = 48;
        const col2 = 112;
        const escribirMeta = (label, value, x, y) => {
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(7.2);
            doc.setTextColor(...muted);
            doc.text(label.toUpperCase(), x, y);
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(9);
            doc.setTextColor(...navy);
            doc.text(cajaPdfTexto(value), x, y + 5);
        };

        escribirMeta('Folio', datos.folio, margin, metaY);
        escribirMeta('Responsable', datos.usuario_apertura, col2, metaY);
        escribirMeta('Apertura', datos.apertura, margin, metaY + 14);
        escribirMeta('Cierre', estaAbierta ? 'En curso' : datos.cierre, col2, metaY + 14);

        const cardY = 81;
        const gap = 5;
        const cardWidth = (pageWidth - margin * 2 - gap * 2) / 3;
        const cards = [
            ['VENTAS TOTALES', cajaPdfMoneda(datos.ventas)],
            ['EFECTIVO ESPERADO', cajaPdfMoneda(datos.esperado)],
            [estaAbierta ? 'TICKETS' : 'EFECTIVO CONTADO', estaAbierta ? String(Number(datos.tickets || 0)) : cajaPdfMoneda(datos.contado)]
        ];

        cards.forEach((card, index) => {
            const x = margin + index * (cardWidth + gap);
            doc.setFillColor(index === 1 ? 255 : 248, index === 1 ? 247 : 250, index === 1 ? 237 : 252);
            doc.setDrawColor(...(index === 1 ? [254, 215, 170] : border));
            doc.roundedRect(x, cardY, cardWidth, 23, 4, 4, 'FD');
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(6.8);
            doc.setTextColor(...muted);
            doc.text(card[0], x + 5, cardY + 7);
            doc.setFontSize(index === 2 && estaAbierta ? 15 : 12.5);
            doc.setTextColor(...(index === 1 ? orangeDark : navy));
            doc.text(card[1], x + 5, cardY + 16.5);
        });

        let y = 116;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(10.5);
        doc.setTextColor(...navy);
        doc.text('Ventas por método de pago', margin, y);

        const metodos = [
            ['Efectivo', cajaPdfMoneda(datos.efectivo)],
            ['Tarjeta', cajaPdfMoneda(datos.tarjeta)],
            ['Transferencia', cajaPdfMoneda(datos.transferencia)]
        ];

        if (Math.abs(cajaPdfNumero(datos.otros)) > 0.001) {
            metodos.push(['Otros métodos', cajaPdfMoneda(datos.otros)]);
        }
        metodos.push(['Total de ventas', cajaPdfMoneda(datos.ventas)]);

        doc.autoTable({
            startY: y + 4,
            margin: { left: margin, right: margin },
            head: [['Método', 'Importe']],
            body: metodos,
            theme: 'grid',
            styles: {
                font: 'helvetica',
                fontSize: 8.2,
                cellPadding: 3.1,
                lineColor: border,
                lineWidth: 0.25,
                textColor: navy
            },
            headStyles: {
                fillColor: [248, 250, 252],
                textColor: slate,
                fontStyle: 'bold',
                halign: 'left'
            },
            columnStyles: {
                0: { cellWidth: 112 },
                1: { halign: 'right', fontStyle: 'bold' }
            },
            didParseCell: function (hook) {
                if (hook.section === 'body' && hook.row.index === metodos.length - 1) {
                    hook.cell.styles.fillColor = orangeSoft;
                    hook.cell.styles.textColor = orangeDark;
                    hook.cell.styles.fontStyle = 'bold';
                }
            }
        });

        y = doc.lastAutoTable.finalY + 12;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(10.5);
        doc.setTextColor(...navy);
        doc.text('Control de efectivo', margin, y);

        const controlEfectivo = [
            ['Monto inicial', cajaPdfMoneda(datos.monto_inicial)],
            ['Ventas en efectivo', cajaPdfMoneda(datos.efectivo)],
            ['Entradas manuales', cajaPdfMoneda(datos.entradas)],
            ['Salidas manuales', '- ' + cajaPdfMoneda(datos.salidas)],
            ['Efectivo esperado', cajaPdfMoneda(datos.esperado)]
        ];

        if (!estaAbierta) {
            controlEfectivo.push(['Efectivo contado', cajaPdfMoneda(datos.contado)]);
            controlEfectivo.push(['Diferencia', cajaPdfMoneda(datos.diferencia)]);
        }

        doc.autoTable({
            startY: y + 4,
            margin: { left: margin, right: margin },
            body: controlEfectivo,
            theme: 'grid',
            styles: {
                font: 'helvetica',
                fontSize: 8.2,
                cellPadding: 3.1,
                lineColor: border,
                lineWidth: 0.25,
                textColor: navy
            },
            columnStyles: {
                0: { cellWidth: 112 },
                1: { halign: 'right', fontStyle: 'bold' }
            },
            didParseCell: function (hook) {
                const esperadoIndex = 4;
                const diferenciaIndex = !estaAbierta ? controlEfectivo.length - 1 : -1;

                if (hook.section === 'body' && hook.row.index === esperadoIndex) {
                    hook.cell.styles.fillColor = orangeSoft;
                    hook.cell.styles.textColor = orangeDark;
                    hook.cell.styles.fontStyle = 'bold';
                }

                if (hook.section === 'body' && hook.row.index === diferenciaIndex) {
                    hook.cell.styles.fillColor = equilibrada ? [236, 253, 243] : [254, 242, 242];
                    hook.cell.styles.textColor = equilibrada ? green : red;
                    hook.cell.styles.fontStyle = 'bold';
                }
            }
        });

        y = doc.lastAutoTable.finalY + 10;
        const notas = [];
        if (datos.observaciones_apertura) notas.push('Apertura: ' + datos.observaciones_apertura);
        if (datos.observaciones_cierre) notas.push('Cierre: ' + datos.observaciones_cierre);

        if (notas.length) {
            doc.setFillColor(248, 250, 252);
            doc.setDrawColor(...border);
            const lineas = doc.splitTextToSize(notas.join('\n'), pageWidth - margin * 2 - 12);
            const altura = Math.max(19, 11 + lineas.length * 4.2);
            doc.roundedRect(margin, y, pageWidth - margin * 2, altura, 4, 4, 'FD');
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(7);
            doc.setTextColor(...muted);
            doc.text('OBSERVACIONES', margin + 6, y + 7);
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(8.2);
            doc.setTextColor(...slate);
            doc.text(lineas, margin + 6, y + 13);
        }

        if (estaAbierta) {
            doc.setFillColor(...orangeSoft);
            doc.setDrawColor(254, 215, 170);
            doc.roundedRect(margin, pageHeight - 35, pageWidth - margin * 2, 13, 4, 4, 'FD');
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(7.7);
            doc.setTextColor(...orangeDark);
            doc.text(
                'Reporte preliminar: los importes pueden cambiar hasta que la caja sea cerrada.',
                pageWidth / 2,
                pageHeight - 27,
                { align: 'center' }
            );
        }

        doc.setDrawColor(...border);
        doc.line(margin, pageHeight - 17, pageWidth - margin, pageHeight - 17);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(7);
        doc.setTextColor(...muted);
        doc.text('Generado: ' + cajaPdfTexto(datos.generado, new Date().toLocaleString('es-MX')), margin, pageHeight - 11);
        doc.text(
            `${Number(datos.tickets || 0)} tickets - ${cajaPdfNumero(datos.piezas).toLocaleString('es-MX')} piezas`,
            pageWidth - margin,
            pageHeight - 11,
            { align: 'right' }
        );

        const folioArchivo = cajaPdfNombreArchivo(datos.folio || 'sin_folio');
        doc.save(`corte_caja_${folioArchivo}.pdf`);
    }

    const bannerTurno = document.querySelector('.caja-shift-banner[data-opened-at]');
    const tiempoTurno = document.getElementById('cajaTiempoTurno');

    function actualizarTiempoTurno() {
        if (!bannerTurno || !tiempoTurno) return;

        const inicio = new Date(bannerTurno.dataset.openedAt);
        const segundos = Math.max(0, Math.floor((Date.now() - inicio.getTime()) / 1000));
        const horas = Math.floor(segundos / 3600);
        const minutos = Math.floor((segundos % 3600) / 60);

        tiempoTurno.textContent = horas > 0
            ? `${horas} h ${minutos} min`
            : `${minutos} min`;
    }

    actualizarTiempoTurno();
    setInterval(actualizarTiempoTurno, 60000);

    const btnDescargarCortePdf = document.getElementById('btnDescargarCortePdf');
    if (btnDescargarCortePdf) {
        btnDescargarCortePdf.addEventListener('click', function () {
            cajaGenerarPdf(cajaPdfActual);
        });
    }

    const buscadorHistorial = document.getElementById('cajaHistorialBuscar');
    const estadoHistorial = document.getElementById('cajaHistorialEstado');
    const tarjetasHistorial = Array.from(document.querySelectorAll('.caja-history-card'));
    const sinResultadosHistorial = document.getElementById('cajaHistorialSinResultados');

    function filtrarHistorial() {
        if (!tarjetasHistorial.length) return;

        const termino = (buscadorHistorial?.value || '').trim().toLowerCase();
        const estado = estadoHistorial?.value || '';
        let visibles = 0;

        tarjetasHistorial.forEach((tarjeta) => {
            const coincideTexto = !termino || tarjeta.dataset.historySearch.includes(termino);
            const coincideEstado = !estado || tarjeta.dataset.historyState === estado;
            const mostrar = coincideTexto && coincideEstado;

            tarjeta.hidden = !mostrar;
            if (mostrar) visibles++;
        });

        if (sinResultadosHistorial) sinResultadosHistorial.hidden = visibles !== 0;
    }

    buscadorHistorial?.addEventListener('input', filtrarHistorial);
    estadoHistorial?.addEventListener('change', filtrarHistorial);

    document.querySelectorAll('[data-caja-detail]').forEach((boton) => {
        boton.addEventListener('click', function () {
            let detalle;

            try {
                detalle = JSON.parse(this.dataset.cajaDetail);
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'No se pudo abrir el detalle',
                    text: 'La información de esta caja no está disponible.',
                    confirmButtonColor: '#f97316'
                });
                return;
            }

            const diferencia = Number(detalle.diferencia || 0);
            const equilibrada = Math.abs(diferencia) <= 0.01;
            const estadoCaja = detalle.estado === 'abierta' ? 'Abierta' : 'Cerrada';
            const estadoDiferencia = detalle.estado === 'abierta'
                ? 'En curso'
                : (equilibrada ? 'Sin diferencia' : (diferencia > 0 ? 'Sobrante' : 'Faltante'));

            Swal.fire({
                title: textoSeguro(detalle.folio),
                html: `
                    <div class="caja-history-modal">
                        <div class="caja-history-modal-status">
                            <span class="${detalle.estado === 'abierta' ? 'is-open' : 'is-closed'}">${estadoCaja}</span>
                            <strong class="${equilibrada ? 'is-ok' : 'is-alert'}">${estadoDiferencia}</strong>
                        </div>

                        <div class="caja-history-modal-dates">
                            <div><small>Apertura</small><strong>${textoSeguro(detalle.apertura || '—')}</strong></div>
                            <div><small>Cierre</small><strong>${textoSeguro(detalle.cierre || 'En curso')}</strong></div>
                        </div>

                        <div class="caja-swal-lines">
                            <div><span>Monto inicial</span><strong>${moneda(detalle.monto_inicial)}</strong></div>
                            <div><span>Ventas totales</span><strong>${moneda(detalle.ventas)}</strong></div>
                            <div><span>Ventas en efectivo</span><strong>${moneda(detalle.efectivo)}</strong></div>
                            <div><span>Tarjeta</span><strong>${moneda(detalle.tarjeta)}</strong></div>
                            <div><span>Transferencia</span><strong>${moneda(detalle.transferencia)}</strong></div>
                            ${Number(detalle.otros || 0) !== 0 ? `<div><span>Otros métodos</span><strong>${moneda(detalle.otros)}</strong></div>` : ''}
                            <div><span>Entradas manuales</span><strong>${moneda(detalle.entradas)}</strong></div>
                            <div><span>Salidas manuales</span><strong>${moneda(detalle.salidas)}</strong></div>
                            <div><span>Efectivo esperado</span><strong>${moneda(detalle.esperado)}</strong></div>
                            <div><span>Efectivo contado</span><strong>${moneda(detalle.contado)}</strong></div>
                            <div class="is-difference ${equilibrada ? 'is-ok' : 'is-alert'}">
                                <span>Diferencia</span><strong>${moneda(detalle.diferencia)}</strong>
                            </div>
                        </div>

                        <div class="caja-history-modal-users">
                            <p><strong>Abrió:</strong> ${textoSeguro(detalle.usuario_apertura || 'Sin nombre')}</p>
                            ${detalle.usuario_cierre ? `<p><strong>Cerró:</strong> ${textoSeguro(detalle.usuario_cierre)}</p>` : ''}
                            <p><strong>Tickets:</strong> ${Number(detalle.tickets || 0)}</p>
                        </div>

                        ${detalle.observaciones_cierre ? `<div class="caja-swal-note"><strong>Nota de cierre:</strong> ${textoSeguro(detalle.observaciones_cierre)}</div>` : ''}
                    </div>
                `,
                width: 620,
                showDenyButton: true,
                confirmButtonText: 'Cerrar',
                denyButtonText: '<i class="fas fa-file-pdf mr-1"></i> Descargar PDF',
                confirmButtonColor: '#64748b',
                denyButtonColor: '#f97316',
                customClass: { popup: 'caja-swal-popup is-wide' }
            }).then((resultado) => {
                if (resultado.isDenied) {
                    cajaGenerarPdf(detalle);
                }
            });
        });
    });

    document.addEventListener('DOMContentLoaded', mostrarMensajeServidor);
})();
</script>

<?php include 'includes/footer.php'; ?>
