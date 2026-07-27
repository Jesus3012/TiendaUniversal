<?php
declare(strict_types=1);

require_once __DIR__ . '/password_config_key.php';

function cfgPasswordAsegurarTabla(mysqli $conn): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS configuracion_seguridad (
            id TINYINT UNSIGNED NOT NULL DEFAULT 1,
            password_temporal_cifrada TEXT NOT NULL,
            password_temporal_iv VARCHAR(64) NOT NULL,
            password_temporal_tag VARCHAR(64) NOT NULL,
            longitud_minima TINYINT UNSIGNED NOT NULL DEFAULT 8,
            actualizado_por INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL
                DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ";

    if (!$conn->query($sql)) {
        throw new RuntimeException(
            'No fue posible crear configuracion_seguridad: '
            . $conn->error
        );
    }
}

function cfgPasswordLlaveBinaria(): string
{
    if (
        !defined('PASSWORD_CONFIG_KEY')
        || trim((string) PASSWORD_CONFIG_KEY) === ''
    ) {
        throw new RuntimeException(
            'PASSWORD_CONFIG_KEY no está configurada.'
        );
    }

    return hash('sha256', (string) PASSWORD_CONFIG_KEY, true);
}

/**
 * @return array{cifrado:string,iv:string,tag:string}
 */
function cfgPasswordCifrar(string $password): array
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException(
            'La extensión OpenSSL no está disponible.'
        );
    }

    $iv = random_bytes(12);
    $tag = '';

    $cifrado = openssl_encrypt(
        $password,
        'aes-256-gcm',
        cfgPasswordLlaveBinaria(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($cifrado === false || $tag === '') {
        throw new RuntimeException(
            'No fue posible cifrar la contraseña temporal.'
        );
    }

    return [
        'cifrado' => base64_encode($cifrado),
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
    ];
}

function cfgPasswordDescifrar(
    string $cifradoBase64,
    string $ivBase64,
    string $tagBase64
): string {
    $cifrado = base64_decode($cifradoBase64, true);
    $iv = base64_decode($ivBase64, true);
    $tag = base64_decode($tagBase64, true);

    if (
        $cifrado === false
        || $iv === false
        || $tag === false
    ) {
        throw new RuntimeException(
            'Los datos cifrados no tienen un formato válido.'
        );
    }

    $password = openssl_decrypt(
        $cifrado,
        'aes-256-gcm',
        cfgPasswordLlaveBinaria(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($password === false) {
        throw new RuntimeException(
            'No fue posible descifrar la contraseña temporal. '
            . 'Verifica password_config_key.php.'
        );
    }

    return $password;
}

/**
 * @return array{ok:bool,mensaje:string}
 */
function cfgPasswordValidar(
    string $password,
    int $longitudMinima = 8
): array {
    $longitud = function_exists('mb_strlen')
        ? mb_strlen($password, 'UTF-8')
        : strlen($password);

    if ($password === '') {
        return [
            'ok' => false,
            'mensaje' => 'Captura la nueva contraseña temporal.',
        ];
    }

    if ($longitud < $longitudMinima) {
        return [
            'ok' => false,
            'mensaje' => 'La contraseña debe tener al menos '
                . $longitudMinima
                . ' caracteres.',
        ];
    }

    if (strlen($password) > 72) {
        return [
            'ok' => false,
            'mensaje' => 'La contraseña no debe superar 72 bytes.',
        ];
    }

    if (!preg_match('/[A-Za-zÁÉÍÓÚáéíóúÑñ]/u', $password)) {
        return [
            'ok' => false,
            'mensaje' => 'Incluye al menos una letra.',
        ];
    }

    if (!preg_match('/\d/', $password)) {
        return [
            'ok' => false,
            'mensaje' => 'Incluye al menos un número.',
        ];
    }

    return ['ok' => true, 'mensaje' => ''];
}

function cfgPasswordGuardar(
    mysqli $conn,
    string $password,
    ?int $usuarioId = null,
    int $longitudMinima = 8
): void {
    cfgPasswordAsegurarTabla($conn);

    $validacion = cfgPasswordValidar($password, $longitudMinima);

    if (!$validacion['ok']) {
        throw new InvalidArgumentException($validacion['mensaje']);
    }

    $datos = cfgPasswordCifrar($password);

    $stmt = $conn->prepare("
        INSERT INTO configuracion_seguridad (
            id,
            password_temporal_cifrada,
            password_temporal_iv,
            password_temporal_tag,
            longitud_minima,
            actualizado_por
        )
        VALUES (1, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            password_temporal_cifrada =
                VALUES(password_temporal_cifrada),
            password_temporal_iv =
                VALUES(password_temporal_iv),
            password_temporal_tag =
                VALUES(password_temporal_tag),
            longitud_minima =
                VALUES(longitud_minima),
            actualizado_por =
                VALUES(actualizado_por)
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible preparar la configuración: '
            . $conn->error
        );
    }

    $cifrado = $datos['cifrado'];
    $iv = $datos['iv'];
    $tag = $datos['tag'];
    $usuario = $usuarioId;

    $stmt->bind_param(
        'sssii',
        $cifrado,
        $iv,
        $tag,
        $longitudMinima,
        $usuario
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'No fue posible guardar la contraseña temporal: '
            . $error
        );
    }

    $stmt->close();
}

function cfgPasswordObtener(
    mysqli $conn,
    string $valorInicial = 'Pescadores1'
): string {
    cfgPasswordAsegurarTabla($conn);

    $resultado = $conn->query("
        SELECT
            password_temporal_cifrada,
            password_temporal_iv,
            password_temporal_tag
        FROM configuracion_seguridad
        WHERE id = 1
        LIMIT 1
    ");

    if (!$resultado) {
        throw new RuntimeException(
            'No fue posible consultar la contraseña temporal: '
            . $conn->error
        );
    }

    $fila = $resultado->fetch_assoc();

    if (!$fila) {
        cfgPasswordGuardar($conn, $valorInicial, null, 8);
        return $valorInicial;
    }

    return cfgPasswordDescifrar(
        (string) $fila['password_temporal_cifrada'],
        (string) $fila['password_temporal_iv'],
        (string) $fila['password_temporal_tag']
    );
}

function cfgPasswordLongitudMinima(
    mysqli $conn,
    int $valorInicial = 8
): int {
    cfgPasswordAsegurarTabla($conn);

    $resultado = $conn->query("
        SELECT longitud_minima
        FROM configuracion_seguridad
        WHERE id = 1
        LIMIT 1
    ");

    if (!$resultado) {
        return $valorInicial;
    }

    $fila = $resultado->fetch_assoc();

    return max(
        8,
        (int) ($fila['longitud_minima'] ?? $valorInicial)
    );
}
