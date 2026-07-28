<?php

function permisos_minusculas($valor): string
{
    $valor = trim((string) $valor);

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($valor, 'UTF-8');
    }

    return strtolower($valor);
}

function permisos_normalizar_rol($rol): string
{
    $rol = permisos_minusculas($rol);

    $mapa = [
        'admin' => 'administrador',
        'administrador' => 'administrador',
        'superadmin' => 'super_administrador',
        'superadministrador' => 'super_administrador',
        'super admin' => 'super_administrador',
        'super_admin' => 'super_administrador',
        'super-administrador' => 'super_administrador',
        'super administrador' => 'super_administrador',
        'super_administrador' => 'super_administrador',
        'vendedor' => 'vendedor',
        'seller' => 'vendedor',
    ];

    return $mapa[$rol] ?? $rol;
}

function permisos_usuario_id(): int
{
    return (int) (
        $_SESSION['usuario_id']
        ?? $_SESSION['id_usuario']
        ?? $_SESSION['id']
        ?? 0
    );
}

function permisos_ruta_actual(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $ruta = parse_url($script, PHP_URL_PATH);

    return basename((string) $ruta);
}

function permisos_paginas_publicas(): array
{
    return [
        'login.php',
        'logout.php',
        'forgot_password.php',
        'reset_password.php',
        'procesar_reset.php',
        'sin_permiso.php',
        'manifest.json',
        'sw.js',
    ];
}

function permisos_modulos_obligatorios($rol): array
{
    $rol = permisos_normalizar_rol($rol);

    if ($rol === 'administrador') {
        return [
            'panel_admin',
            'configuracion',
            'mi_perfil',
        ];
    }

    if ($rol === 'vendedor') {
        return [
            'panel_vendedor',
            'mi_perfil',
        ];
    }

    return [];
}

/**
 * Respaldo temporal si todavía no se ejecutó el SQL.
 * Evita bloquear el sistema durante la instalación.
 */
function permisos_configuracion_legacy(): array
{
    return [
        'panel_admin' => [
            'roles' => ['administrador', 'super_administrador'],
            'rutas' => ['dashboard_admin.php'],
        ],
        'panel_vendedor' => [
            'roles' => ['vendedor'],
            'rutas' => ['dashboard_vendedor.php'],
        ],
        'corte_caja' => [
            'roles' => ['administrador', 'super_administrador'],
            'rutas' => ['corte_caja.php'],
        ],
        'ventas' => [
            'roles' => ['administrador', 'super_administrador', 'vendedor'],
            'rutas' => [
                'dashboard_ventas.php',
                'ventas.php',
                'venta_admin.php',
                'ventas_proveedor.php',
                'pedidos.php',
            ],
        ],
        'historial_ventas' => [
            'roles' => ['administrador', 'super_administrador', 'vendedor'],
            'rutas' => ['historial_ventas.php'],
        ],
        'inventario' => [
            'roles' => ['administrador', 'super_administrador', 'vendedor'],
            'rutas' => [
                'dashboard_inventario.php',
                'inventario_admin.php',
                'inventario.php',
                'reporte_inventario_filtrado.php',
            ],
        ],
        'productos' => [
            'roles' => ['administrador', 'super_administrador'],
            'rutas' => [
                'dashboard_productos.php',
                'productos.php',
                'ajustes_productos.php',
                'get_producto.php',
            ],
        ],
        'promociones' => [
            'roles' => ['administrador', 'super_administrador'],
            'rutas' => ['promociones.php'],
        ],
        'ajustes_productos' => [
            'roles' => [
                'administrador',
                'super_administrador',
                'vendedor',
            ],
            'rutas' => ['vendedor_ajustes_productos.php'],
        ],
        'proveedores' => [
            'roles' => ['administrador', 'super_administrador'],
            'rutas' => ['proveedores.php'],
        ],
        'reportes' => [
            'roles' => ['administrador', 'super_administrador', 'vendedor'],
            'rutas' => [
                'historial_reportes.php',
                'dashboard_reportes_ventas.php',
                'reportes_vendedor.php',
                'reporte_vendedor_productos.php',
            ],
        ],
        'historial_stock' => [
            'roles' => ['administrador', 'super_administrador'],
            'rutas' => ['historial_stock.php'],
        ],
        'estadisticas' => [
            'roles' => ['administrador', 'super_administrador'],
            'rutas' => ['ver_ventas.php'],
        ],
        'configuracion' => [
            'roles' => ['administrador', 'super_administrador'],
            'rutas' => ['configuracion.php'],
        ],
        'asignar_productos' => [
            'roles' => ['administrador', 'super_administrador'],
            'rutas' => ['asignar_productos_vendedor.php'],
        ],
        'control_accesos' => [
            'roles' => ['administrador', 'super_administrador'],
            'rutas' => ['control_accesos.php'],
        ],
        'mi_perfil' => [
            'roles' => ['administrador', 'super_administrador', 'vendedor'],
            'rutas' => ['mi_perfil.php'],
        ],
    ];
}

function permisos_tablas_disponibles($conn): bool
{
    static $estado = [];

    if (!is_object($conn)) {
        return false;
    }

    $clave = function_exists('spl_object_hash')
        ? spl_object_hash($conn)
        : 'conexion';

    if (array_key_exists($clave, $estado)) {
        return $estado[$clave];
    }

    $requeridas = [
        'modulos_sistema',
        'modulos_rutas',
        'roles_modulos',
    ];

    foreach ($requeridas as $tabla) {
        $tablaSegura = $conn->real_escape_string($tabla);
        $resultado = @$conn->query("SHOW TABLES LIKE '{$tablaSegura}'");

        if (!$resultado || $resultado->num_rows === 0) {
            $estado[$clave] = false;
            return false;
        }
    }

    $estado[$clave] = true;
    return true;
}

function permisos_modulo_legacy_por_ruta($ruta): ?array
{
    $ruta = basename((string) $ruta);

    foreach (permisos_configuracion_legacy() as $clave => $datos) {
        if (in_array($ruta, $datos['rutas'], true)) {
            return [
                'clave' => $clave,
                'es_ajax' => 0,
                'activo' => 1,
            ];
        }
    }

    return null;
}

function permisos_obtener_modulo_por_ruta($conn, $ruta): ?array
{
    static $cache = [];

    $ruta = basename((string) $ruta);
    $cacheKey = $ruta;

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (!permisos_tablas_disponibles($conn)) {
        $cache[$cacheKey] = permisos_modulo_legacy_por_ruta($ruta);
        return $cache[$cacheKey];
    }

    $stmt = $conn->prepare("
        SELECT
            m.id,
            m.clave,
            m.nombre,
            m.activo,
            mr.es_ajax
        FROM modulos_rutas mr
        INNER JOIN modulos_sistema m
            ON m.id = mr.modulo_id
        WHERE mr.ruta = ?
        LIMIT 1
    ");

    if (!$stmt) {
        $cache[$cacheKey] = null;
        return null;
    }

    $stmt->bind_param('s', $ruta);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $modulo = $resultado ? $resultado->fetch_assoc() : null;
    $stmt->close();

    $cache[$cacheKey] = $modulo ?: null;
    return $cache[$cacheKey];
}

function permisos_rol_tiene_modulo($conn, $rol, $claveModulo): bool
{
    static $cache = [];

    $rol = permisos_normalizar_rol($rol);
    $claveModulo = trim((string) $claveModulo);

    if ($rol === 'super_administrador') {
        return true;
    }

    // Administrador y superadministrador pueden abrir el módulo.
    // El administrador únicamente puede editar los accesos del vendedor;
    // esa restricción se valida dentro de control_accesos.php.
    if ($claveModulo === 'control_accesos') {
        return in_array(
            $rol,
            ['administrador', 'super_administrador'],
            true
        );
    }

    if (
        $rol === '' ||
        $claveModulo === ''
    ) {
        return false;
    }

    if (
        in_array(
            $claveModulo,
            permisos_modulos_obligatorios($rol),
            true
        )
    ) {
        return true;
    }

    $cacheKey = $rol . '|' . $claveModulo;

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (!permisos_tablas_disponibles($conn)) {
        $legacy = permisos_configuracion_legacy();
        $permitido = isset($legacy[$claveModulo])
            && in_array($rol, $legacy[$claveModulo]['roles'], true);

        $cache[$cacheKey] = $permitido;
        return $permitido;
    }

    $stmt = $conn->prepare("
        SELECT rm.permitido
        FROM roles_modulos rm
        INNER JOIN modulos_sistema m
            ON m.id = rm.modulo_id
        WHERE rm.rol = ?
          AND m.clave = ?
          AND m.activo = 1
        LIMIT 1
    ");

    if (!$stmt) {
        $cache[$cacheKey] = false;
        return false;
    }

    $stmt->bind_param('ss', $rol, $claveModulo);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $fila = $resultado ? $resultado->fetch_assoc() : null;
    $stmt->close();

    /*
     * Sin fila = sin permiso.
     * Esto hace que los módulos agregados en el futuro no se habiliten
     * automáticamente para administrador ni vendedor.
     */
    $permitido = $fila && (int) $fila['permitido'] === 1;

    $cache[$cacheKey] = $permitido;
    return $permitido;
}

function permisos_ruta_permitida($conn, $rol, $ruta): bool
{
    $ruta = basename((string) $ruta);
    $rol = permisos_normalizar_rol($rol);

    if (in_array($ruta, permisos_paginas_publicas(), true)) {
        return true;
    }

    if ($rol === 'super_administrador') {
        return true;
    }

    $modulo = permisos_obtener_modulo_por_ruta($conn, $ruta);

    /*
     * Con las tablas ya instaladas, una ruta que no está registrada
     * se considera un módulo nuevo y queda bloqueada por defecto.
     */
    if (!$modulo || (int) ($modulo['activo'] ?? 0) !== 1) {
        return false;
    }

    return permisos_rol_tiene_modulo(
        $conn,
        $rol,
        $modulo['clave']
    );
}

function permisos_es_ajax($conn = null, $ruta = null): bool
{
    $requestedWith = permisos_minusculas(
        $_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''
    );

    $accept = permisos_minusculas(
        $_SERVER['HTTP_ACCEPT'] ?? ''
    );

    $contentType = permisos_minusculas(
        $_SERVER['CONTENT_TYPE'] ?? ''
    );

    if (
        $requestedWith === 'xmlhttprequest' ||
        strpos($accept, 'application/json') !== false ||
        strpos($contentType, 'application/json') !== false
    ) {
        return true;
    }

    if ($conn && $ruta) {
        $modulo = permisos_obtener_modulo_por_ruta($conn, $ruta);
        return $modulo && (int) ($modulo['es_ajax'] ?? 0) === 1;
    }

    return false;
}

function permisos_redirigir($destino): void
{
    if (!headers_sent()) {
        header('Location: ' . $destino);
        exit;
    }

    $destinoJs = json_encode(
        (string) $destino,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_AMP |
        JSON_HEX_QUOT
    );

    echo '<script>window.location.replace(' . $destinoJs . ');</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url='
        . htmlspecialchars((string) $destino, ENT_QUOTES, 'UTF-8')
        . '"></noscript>';
    exit;
}

function permisos_denegar($conn, $ruta, $mensaje = 'No tienes permiso para acceder a este módulo.'): void
{
    if (permisos_es_ajax($conn, $ruta)) {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            [
                'success' => false,
                'ok' => false,
                'error' => $mensaje,
                'message' => $mensaje,
            ],
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    permisos_redirigir(
        'sin_permiso.php?modulo=' . rawurlencode((string) $ruta)
    );
}

function permisos_proteger_ruta($conn, $ruta = null): bool
{
    $ruta = $ruta ?: permisos_ruta_actual();

    if (in_array($ruta, permisos_paginas_publicas(), true)) {
        return true;
    }

    $usuarioId = permisos_usuario_id();
    $rol = permisos_normalizar_rol($_SESSION['rol'] ?? '');

    if ($usuarioId <= 0 || $rol === '') {
        if (permisos_es_ajax($conn, $ruta)) {
            permisos_denegar(
                $conn,
                $ruta,
                'Tu sesión ha expirado. Inicia sesión nuevamente.'
            );
        }

        permisos_redirigir('login.php?expired=1');
    }

    if (!permisos_ruta_permitida($conn, $rol, $ruta)) {
        permisos_denegar($conn, $ruta);
    }

    return true;
}

function permisos_puede_gestionar($rol): bool
{
    return in_array(
        permisos_normalizar_rol($rol),
        ['administrador', 'super_administrador'],
        true
    );
}

function permisos_nombres_roles(): array
{
    return [
        'administrador' => 'Administrador',
        'vendedor' => 'Vendedor',
        'super_administrador' => 'Superadministrador',
    ];
}
