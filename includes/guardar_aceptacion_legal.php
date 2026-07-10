<?php
/**
 * includes/guardar_aceptacion_legal.php
 *
 * Endpoint que registra la aceptación de la versión vigente.
 * No almacena dirección IP ni user agent.
 */

declare(strict_types=1);

ob_start();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/legal_config.php';

function responder_aceptacion_legal(
    $codigo,
    $datos
) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($codigo);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        $datos,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException(
            'No se pudo establecer la conexión con la base de datos.'
        );
    }

    $conn->set_charset('utf8mb4');

    if (
        ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        !== 'POST'
    ) {
        responder_aceptacion_legal(405, [
            'success' => false,
            'message' => 'Método no permitido.'
        ]);
    }

    $usuarioId = legal_obtener_usuario_id($conn);

    if ($usuarioId <= 0) {
        responder_aceptacion_legal(401, [
            'success' => false,
            'message' => 'La sesión no contiene un usuario válido.'
        ]);
    }

    $tokenRecibido = (string)(
        $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''
    );

    $tokenSesion = (string)(
        $_SESSION['legal_csrf_token'] ?? ''
    );

    if (
        $tokenRecibido === ''
        || $tokenSesion === ''
        || !hash_equals($tokenSesion, $tokenRecibido)
    ) {
        responder_aceptacion_legal(403, [
            'success' => false,
            'message' =>
                'La solicitud de seguridad no es válida. '
                . 'Recarga la página e inténtalo nuevamente.'
        ]);
    }

    $entrada = json_decode(
        (string)file_get_contents('php://input'),
        true
    );

    if (
        !is_array($entrada)
        || empty($entrada['acepto_terminos'])
        || empty($entrada['acepto_privacidad'])
    ) {
        responder_aceptacion_legal(422, [
            'success' => false,
            'message' =>
                'Debes aceptar los Términos y el '
                . 'Aviso de Privacidad.'
        ]);
    }

    legal_asegurar_tabla($conn);

    $documentos = legal_construir_documentos($conn);

    $conn->begin_transaction();

    try {
        $sql = "
            INSERT INTO aceptaciones_legales (
                usuario_id,
                version_terminos,
                version_privacidad,
                hash_terminos,
                hash_privacidad,
                acepto_terminos,
                acepto_privacidad,
                fecha_aceptacion,
                fecha_revocacion
            ) VALUES (
                ?, ?, ?, ?, ?, 1, 1, NOW(), NULL
            )
            ON DUPLICATE KEY UPDATE
                hash_terminos = VALUES(hash_terminos),
                hash_privacidad = VALUES(hash_privacidad),
                acepto_terminos = 1,
                acepto_privacidad = 1,
                fecha_aceptacion = NOW(),
                fecha_revocacion = NULL
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException(
                'No se pudo preparar el registro: '
                . $conn->error
            );
        }

        $stmt->bind_param(
            'issss',
            $usuarioId,
            $documentos['version_terminos'],
            $documentos['version_privacidad'],
            $documentos['hash_terminos'],
            $documentos['hash_privacidad']
        );

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'No se pudo guardar la aceptación: '
                . $stmt->error
            );
        }

        $stmt->close();

        /*
         * Auditoría sin dirección IP.
         * El campo ip de auditoria queda NULL.
         */
        $accion =
            'ACEPTACION_DOCUMENTOS_LEGALES';

        $detalle =
            'El usuario aceptó los Términos versión '
            . $documentos['version_terminos']
            . ' y el Aviso de Privacidad versión '
            . $documentos['version_privacidad']
            . '.';

        $stmtAuditoria = $conn->prepare(
            "INSERT INTO auditoria (
                usuario_id,
                accion,
                detalle,
                fecha
             ) VALUES (?, ?, ?, NOW())"
        );

        if ($stmtAuditoria) {
            $stmtAuditoria->bind_param(
                'iss',
                $usuarioId,
                $accion,
                $detalle
            );

            $stmtAuditoria->execute();
            $stmtAuditoria->close();
        }

        $conn->commit();

        $_SESSION['id'] = $usuarioId;
        $_SESSION['usuario_id'] = $usuarioId;

        $_SESSION['legal_csrf_token'] =
            bin2hex(random_bytes(32));

        responder_aceptacion_legal(200, [
            'success' => true,
            'message' =>
                'Aceptación registrada correctamente.'
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Throwable $e) {
    error_log(
        'Error al guardar aceptación legal: '
        . $e->getMessage()
    );

    responder_aceptacion_legal(500, [
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
