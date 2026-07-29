<?php
/**
 * Archivo: includes/session.php
 * Sesión centralizada con tiempos configurables desde la base de datos.
 *
 * La carpeta storage/sessions es OPCIONAL. Si existe y tiene permisos de
 * escritura se utiliza; de lo contrario PHP usa su almacenamiento normal.
 */

declare(strict_types=1);

if (!defined('APP_SESSION_BOOTSTRAPPED')) {
    define('APP_SESSION_BOOTSTRAPPED', true);

    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/configuracion_sesion.php';

    if (!function_exists('sesionEsHttps')) {
        function sesionEsHttps(): bool
        {
            if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
                return true;
            }

            return isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
        }
    }

    if (!function_exists('sesionRutaBaseWeb')) {
        function sesionRutaBaseWeb(): string
        {
            $projectRoot = realpath(dirname(__DIR__));
            $documentRoot = isset($_SERVER['DOCUMENT_ROOT'])
                ? realpath((string) $_SERVER['DOCUMENT_ROOT'])
                : false;

            if ($projectRoot && $documentRoot) {
                $project = str_replace('\\', '/', $projectRoot);
                $document = rtrim(str_replace('\\', '/', $documentRoot), '/');

                if (stripos($project, $document) === 0) {
                    $relative = trim(substr($project, strlen($document)), '/');
                    return $relative === '' ? '' : '/' . $relative;
                }
            }

            return '';
        }
    }

    if (!function_exists('sesionUrl')) {
        function sesionUrl(string $archivo): string
        {
            $archivo = ltrim($archivo, '/');
            $base = rtrim(sesionRutaBaseWeb(), '/');
            return ($base === '' ? '' : $base) . '/' . $archivo;
        }
    }

    if (!function_exists('sesionEsPeticionJson')) {
        function sesionEsPeticionJson(): bool
        {
            $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
            $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
            $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

            return $requestedWith === 'xmlhttprequest'
                || strpos($accept, 'application/json') !== false
                || strpos($contentType, 'application/json') !== false;
        }
    }

    if (!function_exists('sesionDestruir')) {
        function sesionDestruir(): void
        {
            $_SESSION = [];

            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    (string) ($params['path'] ?? '/'),
                    (string) ($params['domain'] ?? ''),
                    (bool) ($params['secure'] ?? false),
                    (bool) ($params['httponly'] ?? true)
                );
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
        }
    }

    if (!function_exists('sesionResponderExpirada')) {
        function sesionResponderExpirada(string $motivo): void
        {
            sesionDestruir();

            $reason = $motivo === 'maximum' ? 'maximum' : 'inactivity';
            $redirect = sesionUrl('login.php?expired=' . rawurlencode($reason));

            if (sesionEsPeticionJson()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                echo json_encode([
                    'success' => false,
                    'ok' => false,
                    'expired' => true,
                    'reason' => $reason,
                    'message' => $reason === 'maximum'
                        ? 'La duración máxima de la sesión terminó.'
                        : 'La sesión terminó por inactividad.',
                    'redirect' => $redirect,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }

            if (!headers_sent()) {
                header('Location: ' . $redirect);
                exit;
            }

            echo '<script>window.location.replace(' . json_encode($redirect) . ');</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') . '"></noscript>';
            exit;
        }
    }

    if (!function_exists('sesionRenovarActividad')) {
        function sesionRenovarActividad(): array
        {
            global $session_config;

            $ahora = time();
            $inicio = (int) ($_SESSION['session_started_at'] ?? $ahora);
            $_SESSION['session_started_at'] = $inicio;
            $_SESSION['last_activity'] = $ahora;

            $limiteInactividad = cfgSesionSegundosInactividad($session_config);
            $limiteMaximo = cfgSesionSegundosMaximos($session_config);

            return [
                'server_now' => $ahora,
                'expires_at' => $ahora + $limiteInactividad,
                'max_expires_at' => $limiteMaximo > 0
                    ? $inicio + $limiteMaximo
                    : 0,
            ];
        }
    }

    /** @var mysqli $conn */
    $session_config = cfgSesionObtener($conn);

    $gcSeconds = max(
        cfgSesionSegundosInactividad($session_config),
        cfgSesionSegundosMaximos($session_config),
        3600
    ) + 3600;

    @ini_set('session.gc_maxlifetime', (string) $gcSeconds);
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');

    if (session_status() !== PHP_SESSION_ACTIVE) {
        // OPCIONAL: solo se usa si la carpeta ya existe y puede escribirse.
        $customStorage = dirname(__DIR__) . '/storage/sessions';
        if (is_dir($customStorage) && is_writable($customStorage)) {
            session_save_path($customStorage);
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => sesionEsHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    if (!empty($_SESSION['usuario_id'])) {
        $ahora = time();
        $inicio = (int) ($_SESSION['session_started_at'] ?? $ahora);
        $ultimaActividad = (int) ($_SESSION['last_activity'] ?? $ahora);

        $_SESSION['session_started_at'] = $inicio;
        $_SESSION['last_activity'] = $ultimaActividad;

        $limiteInactividad = cfgSesionSegundosInactividad($session_config);
        $limiteMaximo = cfgSesionSegundosMaximos($session_config);

        if (($ahora - $ultimaActividad) >= $limiteInactividad) {
            sesionResponderExpirada('inactivity');
        }

        if ($limiteMaximo > 0 && ($ahora - $inicio) >= $limiteMaximo) {
            sesionResponderExpirada('maximum');
        }

        /*
         * Las navegaciones y formularios tradicionales sí cuentan como actividad.
         * Las llamadas AJAX comunes no renuevan la sesión automáticamente.
         * mantener_sesion.php la renueva de forma explícita.
         */
        $scriptActual = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptActual !== 'mantener_sesion.php' && !sesionEsPeticionJson()) {
            $_SESSION['last_activity'] = $ahora;
        }

        $_SESSION['session_timeout_seconds'] = $limiteInactividad;
        $_SESSION['session_warning_seconds'] = max(
            0,
            (int) $session_config['aviso_minutos'] * 60
        );
    }
}
