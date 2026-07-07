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

    $sql = "
        SELECT
            COALESCE(SUM({$neta} * p.precio_venta), 0) AS ventas_sistema,
            COALESCE(SUM(CASE WHEN {$metodo} = 'efectivo' THEN {$neta} * p.precio_venta ELSE 0 END), 0) AS efectivo_sistema,
            COALESCE(SUM(CASE WHEN {$metodo} = 'tarjeta' THEN {$neta} * p.precio_venta ELSE 0 END), 0) AS tarjeta_sistema,
            COALESCE(SUM(CASE WHEN {$metodo} = 'transferencia' THEN {$neta} * p.precio_venta ELSE 0 END), 0) AS transferencia_sistema,
            COALESCE(SUM(CASE WHEN {$metodo} = 'otros' THEN {$neta} * p.precio_venta ELSE 0 END), 0) AS otros_sistema,
            COALESCE(SUM({$neta}), 0) AS total_piezas,
            COALESCE(SUM({$neta} * (p.precio_venta - p.precio_compra)), 0) AS utilidad_estimada,
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
            p.precio_venta,
            p.precio_compra,
            u.nombre AS vendedor_nombre,
            (v.cantidad_vendida * p.precio_venta) AS subtotal,
            (v.cantidad_vendida * (p.precio_venta - p.precio_compra)) AS utilidad_estimada
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
            p.precio_venta,
            p.precio_compra,
            (v.cantidad_vendida * p.precio_venta) AS subtotal,
            (v.cantidad_vendida * (p.precio_venta - p.precio_compra)) AS utilidad_estimada,
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
?>

<style>
.content-wrapper {
    background: linear-gradient(180deg, #FFF4E6, #FFFFFF);
    min-height: 100vh;
    padding: 25px;
    border-radius: 18px 0 0 18px;
}

.page-title {
    font-size: 1.9rem;
    font-weight: 700;
    color: #2c2c2c;
}

.caja-subtitle {
    color: #6c757d;
    font-size: .92rem;
    margin-top: 4px;
}

.caja-card {
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 10px 26px rgba(0,0,0,0.08);
    border: 1px solid rgba(0,0,0,0.03);
    transition: all .35s ease;
}

.caja-card:hover {
    box-shadow: 0 18px 45px rgba(0,0,0,0.12);
}

.caja-card-body {
    padding: 20px;
}

.caja-btn {
    border: none;
    border-radius: 40px;
    padding: 10px 18px;
    font-weight: 700;
    transition: all .28s ease;
    box-shadow: 0 8px 18px rgba(0,0,0,.08);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.caja-btn:hover {
    transform: translateY(-2px);
    text-decoration: none;
}

.caja-btn-primary {
    background: #111;
    color: white;
}

.caja-btn-primary:hover {
    background: #000;
    color: white;
}

.caja-btn-light {
    background: white;
    color: #2c2c2c;
    border: 1px solid #e4e7eb;
}

.caja-btn-danger {
    background: #fff0f0;
    color: #dc3545;
    border: 1px solid #ffd2d2;
}

.caja-btn-success {
    background: #111;
    color: white;
}

.caja-btn-success:hover {
    background: #007bff;
    color: white;
}

.caja-label {
    font-size: .72rem;
    color: #69798b;
    text-transform: uppercase;
    letter-spacing: .35px;
    font-weight: 800;
    margin-bottom: 7px;
}

.caja-input,
.caja-select,
.caja-textarea {
    border-radius: 14px !important;
    border: 1px solid #ced4da !important;
    padding: 10px 14px !important;
    min-height: 44px;
    box-shadow: none !important;
}

.caja-textarea {
    min-height: 86px;
    resize: vertical;
}

.caja-input:focus,
.caja-select:focus,
.caja-textarea:focus {
    border-color: #111 !important;
    box-shadow: 0 0 0 3px rgba(17,17,17,.08) !important;
}

.caja-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 40px;
    padding: 8px 13px;
    font-size: .8rem;
    font-weight: 800;
}

.caja-status-open {
    background: #eaf8ef;
    color: #16a34a;
    border: 1px solid #c9efd6;
}

.caja-status-closed {
    background: #f1f3f5;
    color: #495057;
    border: 1px solid #dee2e6;
}

.caja-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.caja-stat {
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 10px 26px rgba(0,0,0,0.08);
    border: 1px solid rgba(0,0,0,0.03);
    padding: 16px;
    min-height: 142px;
    position: relative;
    overflow: hidden;
}

.caja-stat::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 5px;
    background: #007bff;
}

.caja-stat.stat-dark::before { background: #111; }
.caja-stat.stat-success::before { background: #28a745; }
.caja-stat.stat-danger::before { background: #dc3545; }
.caja-stat.stat-warning::before { background: #ffc107; }

.caja-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #34495e;
    margin-bottom: 12px;
}

.caja-stat-label {
    font-size: .68rem;
    color: #69798b;
    text-transform: uppercase;
    letter-spacing: .25px;
    font-weight: 800;
    margin-bottom: 5px;
}

.caja-stat-value {
    font-size: 1.35rem;
    line-height: 1.1;
    font-weight: 800;
    color: #212529;
    letter-spacing: -.5px;
}

.caja-stat-small {
    font-size: .78rem;
    color: #6c757d;
    margin-top: 6px;
}

.caja-section-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 16px;
}

.caja-section-title h5 {
    font-size: 1.15rem;
    font-weight: 800;
    color: #222;
    margin: 0;
}

.caja-section-title small {
    color: #6c757d;
}

.caja-table-wrapper {
    background: white;
    border: 1px solid #edf1f5;
    border-radius: 18px;
    overflow: hidden;
}

.caja-table-wrapper .table {
    margin-bottom: 0;
}

.caja-table-wrapper thead th {
    background: #f8fafc;
    color: #69798b;
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .25px;
    border-bottom: 1px solid #edf1f5;
    white-space: nowrap;
}

.caja-table-wrapper tbody td {
    vertical-align: middle;
    border-top: 1px solid #edf1f5;
}

.caja-num {
    text-align: right;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.caja-empty {
    text-align: center;
    padding: 45px 20px;
    background: white;
    border-radius: 18px;
    color: #6c757d;
}

.caja-empty i {
    font-size: 3rem;
    color: #adb5bd;
    margin-bottom: 12px;
}

.caja-open-hero {
    background: linear-gradient(135deg, #111 0%, #343a40 100%);
    color: white;
    border-radius: 22px;
    padding: 24px;
    box-shadow: 0 16px 38px rgba(0,0,0,.14);
}

.caja-open-hero h3 {
    font-weight: 800;
    margin-bottom: 8px;
}

.caja-open-hero p {
    color: rgba(255,255,255,.78);
    margin-bottom: 0;
}

.caja-cierre-box {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    color: #222;
    border-radius: 18px;
    padding: 20px;
    border: 1px solid rgba(0,0,0,0.03);
    box-shadow: 0 10px 26px rgba(0,0,0,0.08);
}

.caja-cierre-box h5 {
    color: #222;
}

.caja-cierre-line {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 9px 0;
    border-bottom: 1px solid #edf1f5;
}

.caja-cierre-line:last-child {
    border-bottom: none;
}

.caja-cierre-line span {
    color: #69798b;
    font-weight: 700;
}

.caja-cierre-line strong {
    color: #212529;
    font-weight: 900;
}

.caja-cierre-box label {
    color: #222 !important;
}

.caja-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 40px;
    padding: 7px 12px;
    font-size: .75rem;
    font-weight: 800;
    background: #eef6ff;
    color: #007bff;
    border: 1px solid #d7eaff;
}

.caja-badge-success {
    background: #eaf8ef;
    color: #16a34a;
    border-color: #c9efd6;
}

.caja-badge-danger {
    background: #fff0f0;
    color: #dc3545;
    border-color: #ffd2d2;
}

.caja-badge-muted {
    background: #f1f3f5;
    color: #495057;
    border-color: #dee2e6;
}

.pagination-wrapper {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 15px;
    margin-top: 25px;
    margin-bottom: 5px;
    flex-wrap: wrap;
}

.pagination-btn {
    padding: 10px 20px;
    background: white;
    border: 2px solid #007bff;
    border-radius: 40px;
    color: #007bff;
    font-weight: 600;
    transition: all 0.3s;
    cursor: pointer;
    font-size: 0.9rem;
    text-decoration: none;
}

.pagination-btn:hover:not(.disabled) {
    background: #007bff;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,123,255,0.3);
    text-decoration: none;
}

.pagination-btn.disabled,
.pagination-btn.disabled:hover {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
    background: white;
    color: #007bff;
    transform: none;
    box-shadow: none;
}

.page-number {
    min-width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 10px;
    color: #495057;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    margin: 0 3px;
    text-decoration: none;
}

.page-number:hover {
    background: #e9ecef;
    border-color: #adb5bd;
    color: #495057;
    text-decoration: none;
}

.page-number.active {
    background: #007bff;
    border-color: #007bff;
    color: white;
}

.toast-custom {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #28a745;
    color: white;
    padding: 12px 24px;
    border-radius: 50px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    z-index: 9999;
    animation: cajaSlideIn .3s ease;
    font-weight: 600;
}

@keyframes cajaSlideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

@media (max-width: 1199px) {
    .caja-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 575px) {
    .content-wrapper {
        padding: 14px;
        border-radius: 0;
    }

    .page-title {
        font-size: 1.45rem;
    }

    .caja-summary-grid {
        grid-template-columns: 1fr;
    }

    .caja-section-title {
        align-items: flex-start;
        flex-direction: column;
    }

    .caja-btn,
    .pagination-btn {
        width: 100%;
    }

    .pagination-wrapper {
        gap: 8px;
    }
}

@media print {
    .main-sidebar,
    .main-header,
    .no-print,
    .historial-cajas {
        display: none !important;
    }

    .content-wrapper {
        margin-left: 0 !important;
        padding: 0 !important;
        background: white !important;
        border-radius: 0 !important;
    }

    .caja-card,
    .caja-stat,
    .caja-cierre-box {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }

    .caja-summary-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
    }

    .table td,
    .table th {
        font-size: 10px;
        padding: 6px;
    }
}
/* ================== DISEÑO ABRIR CAJA SIN NEGRO ================== */

.row.justify-content-center .col-lg-7 {
    max-width: 760px;
}

/* Encabezado de abrir caja */
.caja-open-hero {
    background: linear-gradient(135deg, #fff2e8 0%, #ffffff 72%);
    color: #2f2f2f;
    border-radius: 20px;
    padding: 22px 24px;
    box-shadow: 0 12px 28px rgba(255, 122, 61, 0.12);
    border: 1px solid rgba(255, 122, 61, 0.22);
    position: relative;
    overflow: hidden;
}

.caja-open-hero::before {
    content: "";
    position: absolute;
    left: 0;
    top: 18px;
    bottom: 18px;
    width: 6px;
    border-radius: 0 12px 12px 0;
    background: linear-gradient(180deg, #ff6b35, #ff9f43);
}

.caja-open-hero::after {
    content: "";
    position: absolute;
    right: -42px;
    top: -42px;
    width: 125px;
    height: 125px;
    border-radius: 50%;
    background: rgba(255, 122, 61, 0.08);
}

.caja-open-hero h3 {
    font-size: 1.52rem;
    font-weight: 850;
    margin: 0 0 8px;
    color: #3a2a20;
    position: relative;
    z-index: 1;
}

.caja-open-hero h3 i {
    color: #ff6b35;
    margin-right: 8px;
}

.caja-open-hero p {
    margin: 0;
    color: #745a49;
    font-size: .94rem;
    line-height: 1.5;
    position: relative;
    z-index: 1;
}

/* Tarjeta del formulario */
.row.justify-content-center .col-lg-7 > .caja-card {
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(255, 122, 61, 0.09);
    border: 1px solid rgba(255, 122, 61, 0.18);
    background: linear-gradient(180deg, #ffffff 0%, #fffaf6 100%);
}

.row.justify-content-center .col-lg-7 > .caja-card .caja-card-body {
    padding: 24px;
}

/* Labels */
.row.justify-content-center .caja-label {
    color: #70472e;
    font-size: .7rem;
    font-weight: 850;
    letter-spacing: .35px;
    margin-bottom: 8px;
}

.row.justify-content-center .caja-label i {
    color: #ff6b35;
    margin-right: 6px !important;
}

/* Inputs */
.row.justify-content-center .caja-input,
.row.justify-content-center .caja-textarea {
    border-radius: 15px !important;
    border: 1px solid #e6d8cf !important;
    background: #ffffff;
    min-height: 48px;
    padding: 11px 14px !important;
    font-size: .94rem;
    color: #3f3f46;
    transition: all .22s ease;
}

.row.justify-content-center .caja-textarea {
    min-height: 92px;
}

.row.justify-content-center .caja-input:hover,
.row.justify-content-center .caja-textarea:hover {
    border-color: #ffb27f !important;
}

.row.justify-content-center .caja-input:focus,
.row.justify-content-center .caja-textarea:focus {
    border-color: #ff6b35 !important;
    box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.13) !important;
}

/* Botón abrir caja */
.row.justify-content-center .caja-btn-primary {
    min-height: 50px;
    border-radius: 15px;
    background: linear-gradient(135deg, #ff6b35 0%, #ff9f43 100%);
    color: #ffffff;
    box-shadow: 0 10px 22px rgba(255, 122, 61, 0.24);
    font-size: .96rem;
    border: none;
}

.row.justify-content-center .caja-btn-primary:hover {
    background: linear-gradient(135deg, #f45f2d 0%, #ff8f2f 100%);
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 13px 28px rgba(255, 122, 61, 0.30);
}

.row.justify-content-center .form-group {
    margin-bottom: 18px;
}

/* Responsive */
@media (max-width: 768px) {
    .row.justify-content-center .col-lg-7 {
        max-width: 100%;
    }

    .caja-open-hero {
        padding: 20px;
        border-radius: 18px;
    }

    .caja-open-hero h3 {
        font-size: 1.35rem;
    }

    .row.justify-content-center .col-lg-7 > .caja-card .caja-card-body {
        padding: 20px;
    }
}
</style>

<div class="content-wrapper">
    <section class="content-header mb-4">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-lg-7">
                    <h1 class="page-title mb-0">
                        <i class="fas fa-cash-register mr-2"></i> Caja
                    </h1>

                    <div class="caja-subtitle">
                        <?php if ($cajaAbierta): ?>
                            Caja abierta desde
                            <strong><?= caja_h(date('d/m/Y H:i', strtotime($cajaAbierta['fecha_apertura']))) ?></strong>
                            · <?= caja_h($cajaAbierta['folio_caja']) ?>
                        <?php else: ?>
                            Primero abre caja con el monto inicial para comenzar el turno.
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-5 mt-3 mt-lg-0 text-lg-right no-print">
                    <?php if ($cajaAbierta): ?>
                        <button type="button" class="caja-btn caja-btn-light" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimir resumen
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-12">
                    <?php if ($cajaAbierta): ?>
                        <span class="caja-status-pill caja-status-open">
                            <i class="fas fa-lock-open"></i> Caja abierta
                        </span>
                    <?php else: ?>
                        <span class="caja-status-pill caja-status-closed">
                            <i class="fas fa-lock"></i> Caja cerrada
                        </span>
                    <?php endif; ?>

                    <small class="text-muted ml-2">
                        Usuario: <strong><?= caja_h($usuarioNombre) ?></strong>
                    </small>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?php if ($mensaje): ?>
                <div class="alert alert-success text-center">
                    <i class="fas fa-check-circle mr-1"></i> <?= caja_h($mensaje) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger text-center">
                    <i class="fas fa-exclamation-triangle mr-1"></i> <?= caja_h($error) ?>
                </div>
            <?php endif; ?>

            <?php if (!$cajaAbierta): ?>

                <div class="row justify-content-center">
                    <div class="col-lg-7">
                        <div class="caja-open-hero mb-4">
                            <h3>
                                <i class="fas fa-door-open mr-2"></i>
                                Abrir caja
                            </h3>
                            <p>
                                Ingresa el efectivo inicial con el que empieza el turno.
                                Desde este momento se tomarán las ventas para el cierre.
                            </p>
                        </div>

                        <div class="caja-card mb-4">
                            <div class="caja-card-body">
                                <form method="POST" id="formAbrirCaja">
                                    <input type="hidden" name="accion" value="abrir_caja">

                                    <div class="form-group">
                                        <div class="caja-label">
                                            <i class="fas fa-money-bill-wave mr-1"></i> Monto inicial en caja
                                        </div>
                                        <input type="number"
                                               step="0.50"
                                               min="0"
                                               name="monto_inicial"
                                               class="form-control caja-input"
                                               placeholder="Ejemplo: 500.50"
                                               required>
                                    </div>

                                    <div class="form-group">
                                        <div class="caja-label">
                                            <i class="fas fa-sticky-note mr-1"></i> Observaciones
                                        </div>
                                        <textarea name="observaciones_apertura"
                                                  class="form-control caja-textarea"
                                                  placeholder="Ejemplo: Caja abierta con cambio inicial."></textarea>
                                    </div>

                                    <button type="submit" class="caja-btn caja-btn-primary w-100">
                                        <i class="fas fa-lock-open"></i> Abrir caja
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>

                <div class="caja-summary-grid mb-4">
                    <div class="caja-stat stat-dark">
                        <div class="caja-stat-icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <div class="caja-stat-label">Monto inicial</div>
                        <div class="caja-stat-value"><?= caja_money($cajaAbierta['monto_inicial']) ?></div>
                        <div class="caja-stat-small">Efectivo al abrir caja</div>
                    </div>

                    <div class="caja-stat stat-success">
                        <div class="caja-stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="caja-stat-label">Ventas en efectivo</div>
                        <div class="caja-stat-value text-success"><?= caja_money($resumen['efectivo_sistema']) ?></div>
                        <div class="caja-stat-small">Registradas en sistema</div>
                    </div>

                    <div class="caja-stat">
                        <div class="caja-stat-icon">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <div class="caja-stat-label">Ventas totales</div>
                        <div class="caja-stat-value text-primary"><?= caja_money($resumen['ventas_sistema']) ?></div>
                        <div class="caja-stat-small">
                            <?= (int)$resumen['total_tickets'] ?> tickets · <?= number_format((float)$resumen['total_piezas'], 2) ?> piezas
                        </div>
                    </div>

                    <div class="caja-stat stat-warning">
                        <div class="caja-stat-icon">
                            <i class="fas fa-calculator"></i>
                        </div>
                        <div class="caja-stat-label">Efectivo esperado</div>
                        <div class="caja-stat-value"><?= caja_money($efectivoEsperado) ?></div>
                        <div class="caja-stat-small">Inicial + efectivo + entradas - salidas</div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-lg-8 mb-4 mb-lg-0">
                        <div class="caja-card h-100">
                            <div class="caja-card-body">
                                <div class="caja-section-title">
                                    <div>
                                        <h5>
                                            <i class="fas fa-chart-pie mr-2"></i>
                                            Resumen de caja
                                        </h5>
                                        <small>Vista simple del dinero registrado desde que se abrió la caja.</small>
                                    </div>
                                </div>

                                <div class="caja-table-wrapper table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Concepto</th>
                                                <th class="caja-num">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td><i class="fas fa-wallet text-dark mr-2"></i> Monto inicial</td>
                                                <td class="caja-num"><strong><?= caja_money($cajaAbierta['monto_inicial']) ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-money-bill-wave text-success mr-2"></i> Ventas en efectivo</td>
                                                <td class="caja-num"><strong><?= caja_money($resumen['efectivo_sistema']) ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-credit-card text-primary mr-2"></i> Ventas con tarjeta</td>
                                                <td class="caja-num"><strong><?= caja_money($resumen['tarjeta_sistema']) ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-exchange-alt text-info mr-2"></i> Transferencias</td>
                                                <td class="caja-num"><strong><?= caja_money($resumen['transferencia_sistema']) ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-wallet text-muted mr-2"></i> Otros métodos</td>
                                                <td class="caja-num"><strong><?= caja_money($resumen['otros_sistema']) ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-plus-circle text-success mr-2"></i> Entradas manuales</td>
                                                <td class="caja-num"><strong><?= caja_money($totalesMovimientos['entradas']) ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td><i class="fas fa-minus-circle text-danger mr-2"></i> Salidas manuales</td>
                                                <td class="caja-num"><strong><?= caja_money($totalesMovimientos['salidas']) ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Efectivo esperado</strong></td>
                                                <td class="caja-num"><strong><?= caja_money($efectivoEsperado) ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="row mt-3">
                                    <div class="col-md-6 mb-2 mb-md-0">
                                        <span class="caja-badge caja-badge-success">
                                            <i class="fas fa-plus-circle"></i>
                                            Entradas: <?= caja_money($totalesMovimientos['entradas']) ?>
                                        </span>
                                    </div>

                                    <div class="col-md-6 text-md-right">
                                        <span class="caja-badge caja-badge-danger">
                                            <i class="fas fa-minus-circle"></i>
                                            Salidas: <?= caja_money($totalesMovimientos['salidas']) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="caja-cierre-box h-100">
                            <h5 class="font-weight-bold mb-3">
                                <i class="fas fa-lock mr-2"></i> Cerrar caja
                            </h5>

                            <div class="caja-cierre-line">
                                <span>Monto inicial</span>
                                <strong><?= caja_money($cajaAbierta['monto_inicial']) ?></strong>
                            </div>

                            <div class="caja-cierre-line">
                                <span>Ventas efectivo</span>
                                <strong><?= caja_money($resumen['efectivo_sistema']) ?></strong>
                            </div>

                            <div class="caja-cierre-line">
                                <span>Entradas</span>
                                <strong><?= caja_money($totalesMovimientos['entradas']) ?></strong>
                            </div>

                            <div class="caja-cierre-line">
                                <span>Salidas</span>
                                <strong><?= caja_money($totalesMovimientos['salidas']) ?></strong>
                            </div>

                            <div class="caja-cierre-line">
                                <span>Efectivo esperado</span>
                                <strong><?= caja_money($efectivoEsperado) ?></strong>
                            </div>

                            <form method="POST" id="formCerrarCaja" class="mt-3 no-print">
                                <input type="hidden" name="accion" value="cerrar_caja">

                                <div class="form-group">
                                    <label class="font-weight-bold small">
                                        Efectivo contado físicamente
                                    </label>
                                    <input type="number"
                                           step="0.50"
                                           min="0"
                                           name="efectivo_contado"
                                           id="efectivo_contado"
                                           class="form-control caja-input"
                                           value="<?= caja_h(number_format($efectivoEsperado, 2, '.', '')) ?>"
                                           required>
                                </div>

                                <div class="form-group">
                                    <label class="font-weight-bold small">
                                        Observaciones de cierre
                                    </label>
                                    <textarea name="observaciones_cierre"
                                              class="form-control caja-textarea"
                                              placeholder="Ejemplo: caja sin diferencia, faltante de cambio, etc."></textarea>
                                </div>

                                <button type="submit" class="caja-btn caja-btn-success w-100">
                                    <i class="fas fa-check-circle"></i> Cerrar caja
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-lg-5 mb-4 mb-lg-0">
                        <div class="caja-card h-100">
                            <div class="caja-card-body">
                                <div class="caja-section-title">
                                    <div>
                                        <h5>
                                            <i class="fas fa-random mr-2"></i>
                                            Entradas y salidas
                                        </h5>
                                        <small>Registra dinero agregado o retirado de caja.</small>
                                    </div>
                                </div>

                                <form method="POST" id="formMovimientoCaja" class="no-print mb-3">
                                    <input type="hidden" name="accion" value="agregar_movimiento">

                                    <div class="form-group">
                                        <div class="caja-label">Tipo</div>
                                        <select name="tipo" class="form-control caja-select" required>
                                            <option value="entrada">Entrada de efectivo</option>
                                            <option value="salida">Salida de efectivo</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <div class="caja-label">Concepto</div>
                                        <input type="text"
                                               name="concepto"
                                               class="form-control caja-input"
                                               placeholder="Ejemplo: cambio adicional"
                                               required>
                                    </div>

                                    <div class="form-group">
                                        <div class="caja-label">Monto</div>
                                        <input type="number"
                                               step="0.50"
                                               min="0.50"
                                               name="monto"
                                               class="form-control caja-input"
                                               placeholder="0.50"
                                               required>
                                    </div>

                                    <div class="form-group">
                                        <div class="caja-label">Observaciones</div>
                                        <textarea name="observaciones"
                                                  class="form-control caja-textarea"
                                                  placeholder="Opcional"></textarea>
                                    </div>

                                    <button type="submit" class="caja-btn caja-btn-primary w-100">
                                        <i class="fas fa-save"></i> Registrar movimiento
                                    </button>
                                </form>

                                <?php if (count($movimientos) > 0): ?>
                                    <div class="caja-table-wrapper table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Tipo</th>
                                                    <th>Concepto</th>
                                                    <th class="caja-num">Monto</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($movimientos as $mov): ?>
                                                    <tr>
                                                        <td>
                                                            <?php if ($mov['tipo'] === 'entrada'): ?>
                                                                <span class="text-success font-weight-bold">Entrada</span>
                                                            <?php elseif ($mov['tipo'] === 'salida'): ?>
                                                                <span class="text-danger font-weight-bold">Salida</span>
                                                            <?php elseif ($mov['tipo'] === 'apertura'): ?>
                                                                <span class="text-primary font-weight-bold">Apertura</span>
                                                            <?php else: ?>
                                                                <span class="text-dark font-weight-bold">Cierre</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?= caja_h($mov['concepto']) ?>
                                                            <br>
                                                            <small class="text-muted">
                                                                <?= caja_h(date('d/m/Y H:i', strtotime($mov['fecha']))) ?>
                                                            </small>
                                                        </td>
                                                        <td class="caja-num">
                                                            <strong><?= caja_money($mov['monto']) ?></strong>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="caja-empty">
                                        <i class="fas fa-random"></i>
                                        <h5>Sin movimientos</h5>
                                        <p class="text-muted mb-0">Aquí aparecerán apertura, entradas, salidas y cierre.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="caja-card h-100">
                            <div class="caja-card-body">
                                <div class="caja-section-title">
                                    <div>
                                        <h5>
                                            <i class="fas fa-clipboard-list mr-2"></i>
                                            Ventas de la caja
                                        </h5>
                                        <small>Auditoría de todas las ventas registradas desde la apertura.</small>
                                    </div>

                                    <span class="caja-badge">
                                        <?= (int)$totalVentasAuditoria ?> registros
                                    </span>
                                </div>

                                <?php if (count($ventasCaja) > 0): ?>
                                    <div class="caja-table-wrapper table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Folio</th>
                                                    <th>Producto</th>
                                                    <th>Fecha</th>
                                                    <th>Método</th>
                                                    <th class="caja-num">Cant.</th>
                                                    <th class="caja-num">Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($ventasCaja as $venta): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= caja_h($venta['folio_ticket'] ?: 'VENTA-' . $venta['id']) ?></strong>
                                                            <br>
                                                            <small class="text-muted"><?= caja_h($venta['vendedor_nombre'] ?: 'Sin vendedor') ?></small>
                                                        </td>

                                                        <td><?= caja_h($venta['producto_nombre']) ?></td>

                                                        <td><?= caja_h(date('d/m/Y H:i', strtotime($venta['fecha_venta']))) ?></td>

                                                        <td>
                                                            <span class="caja-badge">
                                                                <?= caja_h($venta['metodo_pago'] ?: 'Sin método') ?>
                                                            </span>
                                                        </td>

                                                        <td class="caja-num"><?= number_format((float)$venta['cantidad_vendida'], 2) ?></td>

                                                        <td class="caja-num">
                                                            <strong><?= caja_money($venta['subtotal']) ?></strong>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if ($totalPaginasVentas > 1): ?>
                                        <div class="pagination-wrapper no-print">
                                            <?php
                                            $baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
                                            $queryActual = $_GET;

                                            $urlPrimera = $baseUrl . '?' . http_build_query(array_merge($queryActual, ['pagina_ventas' => 1]));
                                            $urlAnterior = $baseUrl . '?' . http_build_query(array_merge($queryActual, ['pagina_ventas' => max(1, $paginaVentas - 1)]));
                                            $urlSiguiente = $baseUrl . '?' . http_build_query(array_merge($queryActual, ['pagina_ventas' => min($totalPaginasVentas, $paginaVentas + 1)]));
                                            $urlUltima = $baseUrl . '?' . http_build_query(array_merge($queryActual, ['pagina_ventas' => $totalPaginasVentas]));
                                            ?>

                                            <a class="pagination-btn <?= $paginaVentas <= 1 ? 'disabled' : '' ?>"
                                               href="<?= caja_h($urlPrimera) ?>">
                                                <i class="fas fa-angle-double-left"></i> Primera
                                            </a>

                                            <a class="pagination-btn <?= $paginaVentas <= 1 ? 'disabled' : '' ?>"
                                               href="<?= caja_h($urlAnterior) ?>">
                                                <i class="fas fa-chevron-left"></i> Anterior
                                            </a>

                                            <div class="pagination-pages">
                                                <?php
                                                $rango = 2;
                                                $inicio = max(1, $paginaVentas - $rango);
                                                $fin = min($totalPaginasVentas, $paginaVentas + $rango);

                                                for ($i = $inicio; $i <= $fin; $i++):
                                                    $urlPagina = $baseUrl . '?' . http_build_query(array_merge($queryActual, ['pagina_ventas' => $i]));
                                                ?>
                                                    <a href="<?= caja_h($urlPagina) ?>"
                                                       class="page-number <?= $i === $paginaVentas ? 'active' : '' ?>">
                                                        <?= $i ?>
                                                    </a>
                                                <?php endfor; ?>
                                            </div>

                                            <a class="pagination-btn <?= $paginaVentas >= $totalPaginasVentas ? 'disabled' : '' ?>"
                                               href="<?= caja_h($urlSiguiente) ?>">
                                                Siguiente <i class="fas fa-chevron-right"></i>
                                            </a>

                                            <a class="pagination-btn <?= $paginaVentas >= $totalPaginasVentas ? 'disabled' : '' ?>"
                                               href="<?= caja_h($urlUltima) ?>">
                                                Última <i class="fas fa-angle-double-right"></i>
                                            </a>
                                        </div>
                                    <?php endif; ?>

                                <?php else: ?>
                                    <div class="caja-empty">
                                        <i class="fas fa-search"></i>
                                        <h5>No hay ventas todavía</h5>
                                        <p class="text-muted mb-0">Las ventas aparecerán aquí después de abrir caja.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

            <div class="caja-card historial-cajas">
                <div class="caja-card-body">
                    <div class="caja-section-title">
                        <div>
                            <h5>
                                <i class="fas fa-history mr-2"></i>
                                Historial de cajas
                            </h5>
                            <small>Últimas aperturas y cierres registrados.</small>
                        </div>
                    </div>

                    <?php if (count($historial) > 0): ?>
                        <div class="caja-table-wrapper table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Folio</th>
                                        <th>Estado</th>
                                        <th>Apertura</th>
                                        <th>Cierre</th>
                                        <th class="caja-num">Inicial</th>
                                        <th class="caja-num">Ventas</th>
                                        <th class="caja-num">Esperado</th>
                                        <th class="caja-num">Contado</th>
                                        <th class="caja-num">Diferencia</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($historial as $caja): ?>
                                        <tr>
                                            <td>
                                                <strong><?= caja_h($caja['folio_caja']) ?></strong>
                                                <br>
                                                <small class="text-muted"><?= caja_h($caja['usuario_apertura_nombre'] ?? '') ?></small>
                                            </td>

                                            <td>
                                                <?php if ($caja['estado'] === 'abierta'): ?>
                                                    <span class="caja-status-pill caja-status-open">
                                                        <i class="fas fa-lock-open"></i> Abierta
                                                    </span>
                                                <?php else: ?>
                                                    <span class="caja-status-pill caja-status-closed">
                                                        <i class="fas fa-lock"></i> Cerrada
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <td><?= caja_h(date('d/m/Y H:i', strtotime($caja['fecha_apertura']))) ?></td>

                                            <td>
                                                <?= $caja['fecha_cierre']
                                                    ? caja_h(date('d/m/Y H:i', strtotime($caja['fecha_cierre'])))
                                                    : '<span class="text-muted">---</span>' ?>
                                            </td>

                                            <td class="caja-num"><?= caja_money($caja['monto_inicial']) ?></td>
                                            <td class="caja-num"><?= caja_money($caja['ventas_sistema']) ?></td>
                                            <td class="caja-num"><?= caja_money($caja['efectivo_esperado']) ?></td>
                                            <td class="caja-num"><?= caja_money($caja['efectivo_contado']) ?></td>
                                            <td class="caja-num">
                                                <strong class="<?= abs((float)$caja['diferencia_efectivo']) <= 0.01 ? 'text-success' : 'text-danger' ?>">
                                                    <?= caja_money($caja['diferencia_efectivo']) ?>
                                                </strong>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="caja-empty">
                            <i class="fas fa-clock"></i>
                            <h5>Todavía no hay cajas registradas</h5>
                            <p class="text-muted mb-0">Cuando abras y cierres caja aparecerá el historial aquí.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const efectivoEsperado = <?= json_encode((float)$efectivoEsperado) ?>;

function cajaFormatoMoneda(value) {
    const number = Number(value || 0);

    return number.toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN'
    });
}

function cajaToast(mensaje, tipo = 'success') {
    const toast = document.createElement('div');
    toast.className = 'toast-custom';
    toast.textContent = mensaje;

    if (tipo === 'danger') {
        toast.style.background = '#dc3545';
    }

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'cajaSlideIn .3s reverse';
        setTimeout(() => toast.remove(), 300);
    }, 2200);
}

const formAbrirCaja = document.getElementById('formAbrirCaja');

if (formAbrirCaja) {
    formAbrirCaja.addEventListener('submit', function(e) {
        e.preventDefault();

        const monto = this.querySelector('[name="monto_inicial"]').value || '0';

        Swal.fire({
            icon: 'question',
            title: '¿Abrir caja?',
            html: `
                <div class="text-center">
                    <p>Se abrirá caja con monto inicial:</p>
                    <h3 style="font-weight:800;">${cajaFormatoMoneda(monto)}</h3>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Sí, abrir caja',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#111',
            cancelButtonColor: '#6c757d'
        }).then((result) => {
            if (result.isConfirmed) {
                formAbrirCaja.submit();
            }
        });
    });
}

const formMovimientoCaja = document.getElementById('formMovimientoCaja');

if (formMovimientoCaja) {
    formMovimientoCaja.addEventListener('submit', function(e) {
        e.preventDefault();

        const tipo = this.querySelector('[name="tipo"]').value;
        const concepto = this.querySelector('[name="concepto"]').value || '';
        const monto = this.querySelector('[name="monto"]').value || '0';

        Swal.fire({
            icon: 'question',
            title: '¿Registrar movimiento?',
            html: `
                <div class="text-center">
                    <p class="mb-2">Se registrará el movimiento en caja.</p>
                    <div style="background:#f8fafc;border-radius:14px;padding:14px;border:1px solid #edf1f5;">
                        <strong>Tipo:</strong> ${tipo}<br>
                        <strong>Concepto:</strong> ${concepto}<br>
                        <strong>Monto:</strong> ${cajaFormatoMoneda(monto)}
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Sí, registrar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#111',
            cancelButtonColor: '#6c757d'
        }).then((result) => {
            if (result.isConfirmed) {
                formMovimientoCaja.submit();
            }
        });
    });
}

const formCerrarCaja = document.getElementById('formCerrarCaja');

if (formCerrarCaja) {
    formCerrarCaja.addEventListener('submit', function(e) {
        e.preventDefault();

        const contadoInput = document.getElementById('efectivo_contado');
        const contado = Number(contadoInput ? contadoInput.value : 0);
        const diferencia = contado - efectivoEsperado;

        Swal.fire({
            icon: Math.abs(diferencia) <= 0.01 ? 'question' : 'warning',
            title: '¿Cerrar caja?',
            html: `
                <div class="text-center">
                    <p class="mb-2">Revisa los importes antes de cerrar.</p>
                    <div style="background:#f8fafc;border-radius:14px;padding:14px;border:1px solid #edf1f5;">
                        <strong>Efectivo esperado:</strong> ${cajaFormatoMoneda(efectivoEsperado)}<br>
                        <strong>Efectivo contado:</strong> ${cajaFormatoMoneda(contado)}<br>
                        <strong>Diferencia:</strong> 
                        <span style="color:${Math.abs(diferencia) <= 0.01 ? '#16a34a' : '#dc3545'};font-weight:800;">
                            ${cajaFormatoMoneda(diferencia)}
                        </span>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Sí, cerrar caja',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#111',
            cancelButtonColor: '#6c757d'
        }).then((result) => {
            if (result.isConfirmed) {
                formCerrarCaja.submit();
            }
        });
    });
}

<?php if ($mensaje): ?>
document.addEventListener('DOMContentLoaded', function() {
    cajaToast(<?= json_encode($mensaje) ?>);
});
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>