<?php
/**
 * Configuración central de duración de sesión.
 * Compatible con PHP 7.4+.
 */

if (!function_exists('cfgSesionValoresPredeterminados')) {
    function cfgSesionValoresPredeterminados(): array
    {
        return [
            'id' => 1,
            'inactividad_minutos' => 30,
            'aviso_minutos' => 2,
            'duracion_maxima_horas' => 12,
            'aviso_activo' => 1,
            'heartbeat_segundos' => 60,
            'updated_by' => null,
            'updated_at' => null,
        ];
    }
}

if (!function_exists('cfgSesionAsegurarTabla')) {
    function cfgSesionAsegurarTabla(mysqli $conn): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS configuracion_sesion (
                id TINYINT UNSIGNED NOT NULL DEFAULT 1,
                inactividad_minutos SMALLINT UNSIGNED NOT NULL DEFAULT 30,
                aviso_minutos SMALLINT UNSIGNED NOT NULL DEFAULT 2,
                duracion_maxima_horas SMALLINT UNSIGNED NOT NULL DEFAULT 12,
                aviso_activo TINYINT(1) NOT NULL DEFAULT 1,
                heartbeat_segundos SMALLINT UNSIGNED NOT NULL DEFAULT 60,
                updated_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if (!$conn->query($sql)) {
            throw new RuntimeException(
                'No fue posible preparar configuracion_sesion: ' . $conn->error
            );
        }

        $insert = "
            INSERT INTO configuracion_sesion (
                id,
                inactividad_minutos,
                aviso_minutos,
                duracion_maxima_horas,
                aviso_activo,
                heartbeat_segundos
            ) VALUES (1, 30, 2, 12, 1, 60)
            ON DUPLICATE KEY UPDATE id = id
        ";

        if (!$conn->query($insert)) {
            throw new RuntimeException(
                'No fue posible inicializar configuracion_sesion: ' . $conn->error
            );
        }
    }
}

if (!function_exists('cfgSesionNormalizar')) {
    function cfgSesionNormalizar(array $fila): array
    {
        $defaults = cfgSesionValoresPredeterminados();
        $fila = array_merge($defaults, $fila);

        $inactividad = max(1, min(1440, (int) $fila['inactividad_minutos']));
        $aviso = max(0, min(60, (int) $fila['aviso_minutos']));

        if ($aviso >= $inactividad) {
            $aviso = max(0, $inactividad - 1);
        }

        $maxima = max(0, min(168, (int) $fila['duracion_maxima_horas']));
        $heartbeat = max(15, min(300, (int) $fila['heartbeat_segundos']));

        return [
            'id' => 1,
            'inactividad_minutos' => $inactividad,
            'aviso_minutos' => $aviso,
            'duracion_maxima_horas' => $maxima,
            'aviso_activo' => ((int) $fila['aviso_activo'] === 1) ? 1 : 0,
            'heartbeat_segundos' => $heartbeat,
            'updated_by' => isset($fila['updated_by']) ? (int) $fila['updated_by'] : null,
            'updated_at' => $fila['updated_at'] ?? null,
        ];
    }
}

if (!function_exists('cfgSesionObtener')) {
    function cfgSesionObtener(mysqli $conn): array
    {
        static $cache = null;

        if (is_array($cache)) {
            return $cache;
        }

        try {
            cfgSesionAsegurarTabla($conn);

            $resultado = $conn->query("SELECT * FROM configuracion_sesion WHERE id = 1 LIMIT 1");

            if ($resultado && $fila = $resultado->fetch_assoc()) {
                $cache = cfgSesionNormalizar($fila);
                return $cache;
            }
        } catch (Throwable $e) {
            error_log('Configuración de sesión: ' . $e->getMessage());
        }

        $cache = cfgSesionValoresPredeterminados();
        return cfgSesionNormalizar($cache);
    }
}

if (!function_exists('cfgSesionValidar')) {
    function cfgSesionValidar(array $datos): array
    {
        $inactividad = (int) ($datos['inactividad_minutos'] ?? 0);
        $aviso = (int) ($datos['aviso_minutos'] ?? 0);
        $maxima = (int) ($datos['duracion_maxima_horas'] ?? 0);
        $avisoActivo = ((int) ($datos['aviso_activo'] ?? 0) === 1) ? 1 : 0;

        if ($inactividad < 1 || $inactividad > 1440) {
            return [
                'ok' => false,
                'mensaje' => 'El tiempo de inactividad debe estar entre 1 y 1440 minutos.',
            ];
        }

        if ($aviso < 0 || $aviso > 60) {
            return [
                'ok' => false,
                'mensaje' => 'El aviso debe estar entre 0 y 60 minutos.',
            ];
        }

        if ($avisoActivo === 1 && $aviso >= $inactividad) {
            return [
                'ok' => false,
                'mensaje' => 'El aviso debe mostrarse antes de que se cumpla el tiempo de inactividad.',
            ];
        }

        if ($maxima < 0 || $maxima > 168) {
            return [
                'ok' => false,
                'mensaje' => 'La duración máxima debe estar entre 0 y 168 horas. Usa 0 para desactivarla.',
            ];
        }

        return [
            'ok' => true,
            'mensaje' => '',
            'datos' => [
                'inactividad_minutos' => $inactividad,
                'aviso_minutos' => $aviso,
                'duracion_maxima_horas' => $maxima,
                'aviso_activo' => $avisoActivo,
                'heartbeat_segundos' => 60,
            ],
        ];
    }
}

if (!function_exists('cfgSesionGuardar')) {
    function cfgSesionGuardar(mysqli $conn, array $datos, int $usuarioId): array
    {
        $validacion = cfgSesionValidar($datos);

        if (!$validacion['ok']) {
            return $validacion;
        }

        cfgSesionAsegurarTabla($conn);

        $valores = $validacion['datos'];
        $stmt = $conn->prepare("
            UPDATE configuracion_sesion
            SET
                inactividad_minutos = ?,
                aviso_minutos = ?,
                duracion_maxima_horas = ?,
                aviso_activo = ?,
                heartbeat_segundos = ?,
                updated_by = ?
            WHERE id = 1
        ");

        if (!$stmt) {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible preparar la actualización de sesión.',
            ];
        }

        $stmt->bind_param(
            'iiiiii',
            $valores['inactividad_minutos'],
            $valores['aviso_minutos'],
            $valores['duracion_maxima_horas'],
            $valores['aviso_activo'],
            $valores['heartbeat_segundos'],
            $usuarioId
        );

        $ok = $stmt->execute();
        $error = $stmt->error;
        $stmt->close();

        if (!$ok) {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible guardar la configuración: ' . $error,
            ];
        }

        if ($conn->query("SHOW TABLES LIKE 'auditoria'") instanceof mysqli_result) {
            $detalle = sprintf(
                'Configuró sesión: inactividad %d min, aviso %d min, máximo %d h',
                $valores['inactividad_minutos'],
                $valores['aviso_minutos'],
                $valores['duracion_maxima_horas']
            );
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $audit = $conn->prepare("
                INSERT INTO auditoria (usuario_id, accion, detalle, ip)
                VALUES (?, 'CONFIGURAR_SESION', ?, ?)
            ");

            if ($audit) {
                $audit->bind_param('iss', $usuarioId, $detalle, $ip);
                $audit->execute();
                $audit->close();
            }
        }

        return [
            'ok' => true,
            'mensaje' => 'Configuración de sesión actualizada correctamente.',
            'datos' => $valores,
        ];
    }
}

if (!function_exists('cfgSesionSegundosInactividad')) {
    function cfgSesionSegundosInactividad(array $config): int
    {
        return max(60, (int) $config['inactividad_minutos'] * 60);
    }
}

if (!function_exists('cfgSesionSegundosMaximos')) {
    function cfgSesionSegundosMaximos(array $config): int
    {
        $horas = (int) $config['duracion_maxima_horas'];
        return $horas > 0 ? $horas * 3600 : 0;
    }
}

if (!function_exists('cfgSesionDatosCliente')) {
    function cfgSesionDatosCliente(array $config, array $session): array
    {
        $ahora = time();
        $ultimaActividad = (int) ($session['last_activity'] ?? $ahora);
        $inicio = (int) ($session['session_started_at'] ?? $ahora);
        $inactividadSegundos = cfgSesionSegundosInactividad($config);
        $maxSegundos = cfgSesionSegundosMaximos($config);
        $expiraInactividad = $ultimaActividad + $inactividadSegundos;
        $expiraMaximo = $maxSegundos > 0 ? $inicio + $maxSegundos : 0;

        return [
            'authenticated' => !empty($session['usuario_id']),
            'serverNow' => $ahora,
            'lastActivity' => $ultimaActividad,
            'sessionStartedAt' => $inicio,
            'expiresAt' => $expiraInactividad,
            'maxExpiresAt' => $expiraMaximo,
            'timeoutSeconds' => $inactividadSegundos,
            'warningSeconds' => max(0, (int) $config['aviso_minutos'] * 60),
            'warningEnabled' => ((int) $config['aviso_activo'] === 1),
            'heartbeatSeconds' => max(15, (int) $config['heartbeat_segundos']),
            'keepAliveUrl' => function_exists('sesionUrl')
                ? sesionUrl('mantener_sesion.php')
                : 'mantener_sesion.php',
            'logoutUrl' => function_exists('sesionUrl')
                ? sesionUrl('logout.php')
                : 'logout.php',
            'loginUrl' => function_exists('sesionUrl')
                ? sesionUrl('login.php?expired=inactivity')
                : 'login.php?expired=inactivity',
        ];
    }
}
