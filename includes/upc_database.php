<?php
/**
 * Integración compartida para lectores físicos, códigos comerciales y UPC Database.
 * Compatible con PHP 7.4+ y MySQL/MariaDB.
 */

declare(strict_types=1);

if (!function_exists('barcode_normalizar_codigo')) {
    function barcode_normalizar_codigo($codigo): string
    {
        $codigo = trim((string) $codigo);
        $codigo = preg_replace('/[\r\n\t\s]+/', '', $codigo);
        return strtoupper((string) $codigo);
    }
}

if (!function_exists('barcode_limpiar_token_api')) {
    /**
     * Acepta el token aunque se haya pegado desde el panel con prefijos,
     * comillas o como una asignación de variable de entorno.
     * Nunca devuelve ni registra el token fuera del servidor.
     */
    function barcode_limpiar_token_api($token): string
    {
        $token = (string) $token;

        // Quitar BOM UTF-8 y caracteres invisibles comunes al copiar/pegar.
        $token = preg_replace('/^\xEF\xBB\xBF/', '', $token);
        $token = trim($token);

        // Permitir que se pegue: UPCDATABASE_API_TOKEN=XXXXX
        if (preg_match('/^UPCDATABASE_API_TOKEN\s*=\s*(.+)$/i', $token, $m)) {
            $token = trim((string) $m[1]);
        }

        // Permitir que se pegue el encabezado completo por accidente.
        $token = preg_replace('/^Authorization\s*:\s*/i', '', $token);
        $token = preg_replace('/^Bearer\s+/i', '', $token);

        // Quitar una pareja de comillas envolventes.
        if (strlen($token) >= 2) {
            $primero = $token[0];
            $ultimo = $token[strlen($token) - 1];
            if (($primero === "'" && $ultimo === "'") || ($primero === '"' && $ultimo === '"')) {
                $token = substr($token, 1, -1);
            }
        }

        // Un token HTTP no debe contener espacios, tabuladores ni saltos de línea.
        $token = preg_replace('/[\x00-\x20\x7F]+/', '', $token);
        return trim((string) $token);
    }
}

if (!function_exists('barcode_diagnostico_token_api')) {
    function barcode_diagnostico_token_api($token): array
    {
        $limpio = barcode_limpiar_token_api($token);
        $longitud = strlen($limpio);
        $mascara = '';
        if ($longitud > 0) {
            $inicio = substr($limpio, 0, min(4, $longitud));
            $final = $longitud > 4 ? substr($limpio, -4) : '';
            $mascara = $inicio . str_repeat('*', max(4, $longitud - strlen($inicio) - strlen($final))) . $final;
        }

        return [
            'configurado' => $limpio !== '',
            'longitud' => $longitud,
            'mascara' => $mascara,
            // La documentación muestra tokens alfanuméricos; se permiten también
            // caracteres habituales de tokens para no rechazar formatos futuros.
            'formato_local_valido' => $limpio !== ''
                && $longitud >= 20
                && $longitud <= 255
                && preg_match('/^[A-Za-z0-9._~+\-\/=]+$/', $limpio) === 1,
        ];
    }
}

if (!function_exists('barcode_recortar')) {
    function barcode_recortar($valor, int $maximo): string
    {
        $valor = trim((string) $valor);
        if ($maximo <= 0) return '';
        return function_exists('mb_substr')
            ? mb_substr($valor, 0, $maximo, 'UTF-8')
            : substr($valor, 0, $maximo);
    }
}

if (!function_exists('barcode_tiene_columna')) {
    function barcode_tiene_columna(mysqli $conn, string $tabla, string $columna): bool
    {
        $tabla = preg_replace('/[^A-Za-z0-9_]/', '', $tabla);
        $columna = preg_replace('/[^A-Za-z0-9_]/', '', $columna);
        if ($tabla === '' || $columna === '') return false;

        try {
            $resultado = $conn->query(
                "SHOW COLUMNS FROM `{$tabla}` LIKE '" . $conn->real_escape_string($columna) . "'"
            );
            return $resultado instanceof mysqli_result && $resultado->num_rows > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('barcode_buscar_producto_local')) {
    function barcode_buscar_producto_local(mysqli $conn, string $codigo): ?array
    {
        $codigo = barcode_normalizar_codigo($codigo);
        if ($codigo === '') return null;

        $campoOrigen = barcode_tiene_columna($conn, 'codigos_barras', 'origen')
            ? 'cb.origen'
            : "'interno' AS origen";

        $sql = "SELECT
                    p.id, p.nombre, p.categoria, p.proveedor, p.cantidad,
                    p.precio_compra, p.precio_venta, p.tipo_venta,
                    p.unidad_medida, p.stock_especial, p.activo,
                    cb.codigo, {$campoOrigen}
                FROM codigos_barras cb
                INNER JOIN productos p ON p.id = cb.producto_id
                WHERE cb.codigo = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo consultar el inventario por código: ' . $conn->error);
        }
        $stmt->bind_param('s', $codigo);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $fila = $resultado ? $resultado->fetch_assoc() : null;
        $stmt->close();
        return $fila ?: null;
    }
}

if (!function_exists('barcode_obtener_configuracion')) {
    function barcode_obtener_configuracion(): array
    {
        $config = [
            'activo' => true,
            'api_token' => '',
            'endpoint_base' => 'https://api.upcdatabase.org',
            'timeout_segundos' => 7,
            'conexion_timeout_segundos' => 3,
            'verificar_ssl' => true,
            'cache_segundos' => 900,
        ];

        $archivo = dirname(__DIR__) . '/config/upc_database.php';
        if (is_file($archivo)) {
            $cargada = require $archivo;
            if (is_array($cargada)) $config = array_merge($config, $cargada);
        }

        $config['activo'] = (bool) ($config['activo'] ?? true);
        $config['api_token'] = barcode_limpiar_token_api($config['api_token'] ?? '');
        $config['endpoint_base'] = rtrim((string) ($config['endpoint_base'] ?? ''), '/');
        $config['timeout_segundos'] = max(2, min(20, (int) ($config['timeout_segundos'] ?? 7)));
        $config['conexion_timeout_segundos'] = max(1, min(10, (int) ($config['conexion_timeout_segundos'] ?? 3)));
        $config['verificar_ssl'] = (bool) ($config['verificar_ssl'] ?? true);
        $config['cache_segundos'] = max(0, min(3600, (int) ($config['cache_segundos'] ?? 900)));
        return $config;
    }
}

if (!function_exists('barcode_api_configurada')) {
    function barcode_api_configurada(array $config): bool
    {
        $token = barcode_limpiar_token_api($config['api_token'] ?? '');
        if (!(bool) ($config['activo'] ?? true) || $token === '') return false;

        foreach (['TU_TOKEN', 'PEGA_AQUI', 'YOUR_TOKEN', 'API_TOKEN_AQUI', 'TU_API_KEY'] as $marcador) {
            if (stripos($token, $marcador) !== false) return false;
        }

        $diagnostico = barcode_diagnostico_token_api($token);
        return (bool) ($diagnostico['formato_local_valido'] ?? false);
    }
}

if (!function_exists('barcode_es_consultable_en_api')) {
    function barcode_es_consultable_en_api(string $codigo): bool
    {
        $codigo = barcode_normalizar_codigo($codigo);
        if ($codigo === '' || !ctype_digit($codigo)) return false;
        return in_array(strlen($codigo), [7, 8, 10, 11, 12, 13, 14], true);
    }
}

if (!function_exists('barcode_categoria_simple')) {
    function barcode_categoria_simple($categoria): string
    {
        $categoria = trim((string) $categoria);
        if ($categoria === '') return 'General';
        $partes = preg_split('/\s*>\s*/', $categoria);
        if (is_array($partes) && $partes) {
            $ultima = trim((string) end($partes));
            if ($ultima !== '') $categoria = $ultima;
        }
        return barcode_recortar($categoria, 50) ?: 'General';
    }
}

if (!function_exists('barcode_primera_imagen_segura')) {
    function barcode_primera_imagen_segura($imagenes): string
    {
        if (!is_array($imagenes)) return '';
        foreach ($imagenes as $imagen) {
            $imagen = trim((string) $imagen);
            if ($imagen !== '' && filter_var($imagen, FILTER_VALIDATE_URL)) {
                $esquema = strtolower((string) parse_url($imagen, PHP_URL_SCHEME));
                if (in_array($esquema, ['http', 'https'], true)) return barcode_recortar($imagen, 500);
            }
        }
        return '';
    }
}

if (!function_exists('barcode_normalizar_producto_api')) {
    function barcode_normalizar_producto_api(array $producto, string $codigoSolicitado): array
    {
        $metadata = is_array($producto['metadata'] ?? null) ? $producto['metadata'] : [];
        $marca = trim((string) ($producto['brand'] ?? ''));
        if ($marca === '') $marca = trim((string) ($producto['manufacturer'] ?? ''));

        $cantidad = trim((string) ($metadata['quantity'] ?? ''));
        $unidad = trim((string) ($metadata['unit'] ?? ''));
        $tamano = trim((string) ($metadata['size'] ?? ''));
        $presentacionCantidad = $cantidad !== ''
            ? trim($cantidad . ($unidad !== '' ? ' ' . $unidad : ''))
            : '';
        $presentacion = $tamano;
        if ($presentacionCantidad !== '' && stripos($tamano, $presentacionCantidad) === false) {
            $presentacion = $presentacion !== ''
                ? $presentacion . ' · ' . $presentacionCantidad
                : $presentacionCantidad;
        }

        $peso = trim((string) ($metadata['weight'] ?? ''));
        if ($peso !== '' && $unidad !== '' && stripos($peso, $unidad) === false) {
            $peso .= ' ' . $unidad;
        }

        return [
            'codigo_barra' => barcode_normalizar_codigo($producto['barcode'] ?? $codigoSolicitado),
            'nombre' => barcode_recortar($producto['title'] ?? $producto['alias'] ?? '', 100),
            'categoria' => barcode_categoria_simple($producto['category'] ?? 'General'),
            'marca' => barcode_recortar($marca, 120),
            'modelo' => barcode_recortar($producto['mpn'] ?? '', 120),
            'presentacion' => barcode_recortar($presentacion, 120),
            'color' => barcode_recortar($metadata['color'] ?? '', 80),
            'talla' => barcode_recortar($metadata['size'] ?? '', 80),
            'peso' => barcode_recortar($peso, 80),
            'material' => '',
            'descripcion' => barcode_recortar($producto['description'] ?? '', 800),
            'imagen_url' => barcode_primera_imagen_segura($producto['images'] ?? []),
            'fuente' => 'upc_database',
        ];
    }
}

if (!function_exists('barcode_quota_desde_headers')) {
    function barcode_quota_desde_headers(array $headers): array
    {
        $restantes = isset($headers['apilimit-lookups']) ? (int) $headers['apilimit-lookups'] : null;
        $resetEpoch = isset($headers['apilimit-reset']) ? (int) $headers['apilimit-reset'] : null;
        return [
            'restantes' => $restantes,
            'reinicio_epoch' => $resetEpoch,
            'reinicio_iso' => $resetEpoch ? date(DATE_ATOM, $resetEpoch) : null,
        ];
    }
}

if (!function_exists('barcode_cache_sesion_obtener')) {
    function barcode_cache_sesion_obtener(string $codigo, int $segundos): ?array
    {
        if ($segundos <= 0 || session_status() !== PHP_SESSION_ACTIVE) return null;
        $cache = $_SESSION['upc_database_cache'][$codigo] ?? null;
        if (!is_array($cache) || !isset($cache['guardado_en'], $cache['respuesta'])) return null;
        if ((time() - (int) $cache['guardado_en']) > $segundos) {
            unset($_SESSION['upc_database_cache'][$codigo]);
            return null;
        }
        $respuesta = is_array($cache['respuesta']) ? $cache['respuesta'] : null;
        if ($respuesta) $respuesta['desde_cache'] = true;
        return $respuesta;
    }
}

if (!function_exists('barcode_cache_sesion_guardar')) {
    function barcode_cache_sesion_guardar(string $codigo, array $respuesta): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        if (!isset($_SESSION['upc_database_cache']) || !is_array($_SESSION['upc_database_cache'])) {
            $_SESSION['upc_database_cache'] = [];
        }
        if (count($_SESSION['upc_database_cache']) > 40) {
            array_shift($_SESSION['upc_database_cache']);
        }
        $_SESSION['upc_database_cache'][$codigo] = [
            'guardado_en' => time(),
            'respuesta' => $respuesta,
        ];
    }
}

if (!function_exists('barcode_consultar_api')) {
    function barcode_consultar_api(string $codigo): array
    {
        $codigo = barcode_normalizar_codigo($codigo);
        $config = barcode_obtener_configuracion();

        if (!barcode_es_consultable_en_api($codigo)) {
            return [
                'status' => 'no_consultable',
                'message' => 'UPC Database consulta códigos numéricos UPC/EAN/GTIN. Puedes conservar este código mediante captura manual o usar la generación interna.',
                'producto' => null,
                'quota' => null,
            ];
        }
        if (!barcode_api_configurada($config)) {
            $diag = barcode_diagnostico_token_api($config['api_token'] ?? '');
            $mensaje = ($diag['configurado'] ?? false)
                ? 'El token configurado no tiene un formato local válido. Pega únicamente el token generado en API Keys, sin escribir Bearer, Authorization, comillas ni el nombre de la variable.'
                : 'UPC Database todavía no tiene un token configurado. Pega únicamente el token generado en API Keys dentro de config/upc_database.php.';

            return [
                'status' => 'sin_configurar',
                'message' => $mensaje,
                'producto' => null,
                'quota' => null,
                'token_diagnostico' => [
                    'configurado' => (bool) ($diag['configurado'] ?? false),
                    'longitud' => (int) ($diag['longitud'] ?? 0),
                    'mascara' => (string) ($diag['mascara'] ?? ''),
                ],
            ];
        }

        $cache = barcode_cache_sesion_obtener($codigo, $config['cache_segundos']);
        if ($cache !== null) return $cache;

        if (!function_exists('curl_init')) {
            return [
                'status' => 'curl_no_disponible',
                'message' => 'El servidor no tiene habilitada la extensión cURL. Puedes completar el producto manualmente y conservar el código.',
                'producto' => null,
                'quota' => null,
            ];
        }

        $url = $config['endpoint_base'] . '/product/' . rawurlencode($codigo);
        $headersRespuesta = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $config['conexion_timeout_segundos'],
            CURLOPT_TIMEOUT => $config['timeout_segundos'],
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $config['api_token'],
                'User-Agent: TiendaUniversalPOS/1.0',
            ],
            CURLOPT_SSL_VERIFYPEER => $config['verificar_ssl'],
            CURLOPT_SSL_VERIFYHOST => $config['verificar_ssl'] ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $linea) use (&$headersRespuesta): int {
                $longitud = strlen($linea);
                $partes = explode(':', $linea, 2);
                if (count($partes) === 2) {
                    $headersRespuesta[strtolower(trim($partes[0]))] = trim($partes[1]);
                }
                return $longitud;
            },
        ]);

        $respuestaCruda = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $quota = barcode_quota_desde_headers($headersRespuesta);

        if ($respuestaCruda === false || $curlError !== '') {
            return [
                'status' => 'sin_conexion',
                'message' => 'No fue posible conectarse con UPC Database. Puedes seguir con captura manual sin perder el código.',
                'producto' => null,
                'quota' => $quota,
            ];
        }

        $json = json_decode((string) $respuestaCruda, true);
        if (!is_array($json)) {
            return [
                'status' => 'respuesta_invalida',
                'message' => 'UPC Database respondió con un formato inesperado. Puedes seguir con captura manual.',
                'producto' => null,
                'quota' => $quota,
            ];
        }

        $errorMensaje = trim((string) ($json['error']['message'] ?? ''));
        $errorTokenInvalido = stripos($errorMensaje, 'API Key is invalid') !== false
            || stripos($errorMensaje, 'API key is invalid') !== false
            || stripos($errorMensaje, 'invalid API Key') !== false
            || stripos($errorMensaje, 'invalid token') !== false
            || stripos($errorMensaje, 'unauthorized') !== false;

        // UPC Database puede devolver success=false con el mensaje de llave
        // inválida incluso cuando el código HTTP no sea 401/403.
        if ($httpStatus === 401 || $errorTokenInvalido) {
            $diag = barcode_diagnostico_token_api($config['api_token'] ?? '');
            return [
                'status' => 'api_token_invalido',
                'message' => 'UPC Database rechazó el token. Debe ser el OAuth token generado en la sección API Keys. Pega solo el valor del token; no agregues Bearer, Authorization:, comillas ni espacios. Token leído por PHP: ' .
                    ((int) ($diag['longitud'] ?? 0)) . ' caracteres, ' .
                    ((string) ($diag['mascara'] ?? 'sin valor')) . '.',
                'producto' => null,
                'quota' => $quota,
                'token_diagnostico' => [
                    'configurado' => (bool) ($diag['configurado'] ?? false),
                    'longitud' => (int) ($diag['longitud'] ?? 0),
                    'mascara' => (string) ($diag['mascara'] ?? ''),
                    'formato_local_valido' => (bool) ($diag['formato_local_valido'] ?? false),
                ],
            ];
        }
        if ($httpStatus === 429 || ($quota['restantes'] !== null && $quota['restantes'] <= 0)) {
            return [
                'status' => 'limite_superado',
                'message' => 'Se alcanzó el límite diario de consultas de UPC Database. Puedes completar el producto manualmente y conservar el código.',
                'producto' => null,
                'quota' => $quota,
            ];
        }
        if ($httpStatus === 404) {
            $resultado = [
                'status' => 'no_encontrado',
                'message' => 'UPC Database no encontró información para este código. Completa los datos manualmente; se guardará exactamente el código escaneado.',
                'producto' => null,
                'quota' => $quota,
            ];
            barcode_cache_sesion_guardar($codigo, $resultado);
            return $resultado;
        }
        if ($httpStatus === 400 || $httpStatus === 403) {
            return [
                'status' => 'codigo_rechazado',
                'message' => $errorMensaje !== ''
                    ? 'UPC Database rechazó el código: ' . barcode_recortar($errorMensaje, 180)
                    : 'UPC Database rechazó el formato del código. Puedes guardarlo mediante captura manual.',
                'producto' => null,
                'quota' => $quota,
            ];
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return [
                'status' => 'error_api',
                'message' => 'UPC Database no pudo procesar la consulta en este momento. Puedes seguir con captura manual.',
                'producto' => null,
                'quota' => $quota,
            ];
        }

        $exito = filter_var($json['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$exito) {
            return [
                'status' => 'error_api',
                'message' => $errorMensaje !== '' ? barcode_recortar($errorMensaje, 180) : 'UPC Database no devolvió un producto válido.',
                'producto' => null,
                'quota' => $quota,
            ];
        }

        $producto = barcode_normalizar_producto_api($json, $codigo);
        $resultado = $producto['nombre'] === ''
            ? [
                'status' => 'datos_incompletos',
                'message' => 'UPC Database encontró el código, pero no devolvió un nombre útil. Completa o corrige los campos manualmente.',
                'producto' => $producto,
                'quota' => $quota,
            ]
            : [
                'status' => 'encontrado',
                'message' => 'Producto encontrado en UPC Database. Verifica la información sugerida y completa precios, proveedor y existencias.',
                'producto' => $producto,
                'quota' => $quota,
            ];

        barcode_cache_sesion_guardar($codigo, $resultado);
        return $resultado;
    }
}
