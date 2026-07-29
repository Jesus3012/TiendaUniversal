<?php
declare(strict_types=1);

function api_hash_token(string $token): string
{
    return hash('sha256', $token);
}

function api_new_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
}

function api_issue_tokens(mysqli $db, int $userId, ?string $deviceId, ?string $familyId = null): array
{
    $access = api_new_token();
    $refresh = api_new_token();
    $accessHash = api_hash_token($access);
    $refreshHash = api_hash_token($refresh);
    $familyId = $familyId ?: api_uuid();
    $accessMinutes = max(5, min(120, (int) (getenv('TP_ACCESS_TOKEN_MINUTES') ?: 30)));
    $refreshDays = max(1, min(90, (int) (getenv('TP_REFRESH_TOKEN_DAYS') ?: 30)));

    $stmt = $db->prepare(
        'INSERT INTO api_access_tokens
        (user_id,device_id,token_hash,expires_at,request_id)
        VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? MINUTE),?)'
    );
    $requestId = api_request_id();
    $stmt->bind_param('issis', $userId, $deviceId, $accessHash, $accessMinutes, $requestId);
    $stmt->execute();

    $stmt = $db->prepare(
        'INSERT INTO api_refresh_tokens
        (user_id,device_id,token_hash,family_id,expires_at)
        VALUES (?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? DAY))'
    );
    $stmt->bind_param('isssi', $userId, $deviceId, $refreshHash, $familyId, $refreshDays);
    $stmt->execute();

    return [
        'access_token' => $access,
        'access_expires_in' => $accessMinutes * 60,
        'refresh_token' => $refresh,
        'refresh_expires_in' => $refreshDays * 86400,
        'token_type' => 'Bearer',
    ];
}

function api_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{40,})$/', $header, $matches)) {
        api_fail(401, 'AUTH_REQUIRED', 'Se requiere un token Bearer.');
    }
    return $matches[1];
}

function api_authenticate(mysqli $db): array
{
    $hash = api_hash_token(api_bearer_token());
    $stmt = $db->prepare(
        'SELECT t.id token_id,t.user_id,t.device_id,u.uuid user_uuid,u.nombre,u.email,u.rol
         FROM api_access_tokens t
         JOIN usuarios u ON u.id=t.user_id
         WHERE t.token_hash=? AND t.revoked_at IS NULL
           AND t.expires_at>UTC_TIMESTAMP(6) AND u.activo=1 AND u.deleted_at IS NULL'
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    if (!$result) {
        api_fail(401, 'TOKEN_EXPIRED_OR_INVALID', 'El token venció, fue revocado o no es válido.');
    }
    $update = $db->prepare('UPDATE api_access_tokens SET last_used_at=UTC_TIMESTAMP(6) WHERE id=?');
    $update->bind_param('i', $result['token_id']);
    $update->execute();
    return $result;
}

function api_require_role(array $auth, array $roles): void
{
    if (!in_array($auth['rol'], $roles, true)) {
        api_fail(403, 'FORBIDDEN_ROLE', 'El rol no permite esta operación.');
    }
}

function api_require_module(mysqli $db, array $auth, string $module): void
{
    if ($auth['rol'] === 'super_administrador') {
        return;
    }
    $stmt = $db->prepare(
        'SELECT 1
         FROM roles_modulos rm
         JOIN modulos_sistema m ON m.id=rm.modulo_id
         WHERE rm.rol=? AND m.clave=? AND rm.permitido=1 AND m.activo=1
           AND rm.deleted_at IS NULL AND m.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->bind_param('ss', $auth['rol'], $module);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_row()) {
        api_fail(403, 'FORBIDDEN_MODULE', 'No tiene permiso para el módulo solicitado.');
    }
}

function api_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
        substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function api_rate_limit(mysqli $db, string $endpoint, int $limit, int $windowSeconds = 60): void
{
    $identity = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = hash('sha256', $identity);
    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            'SELECT hits,window_started_at FROM api_rate_limits
             WHERE bucket_key=? AND endpoint=? FOR UPDATE'
        );
        $stmt->bind_param('ss', $key, $endpoint);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $now = time();
        $hits = 1;
        if ($row && strtotime($row['window_started_at'] . ' UTC') > $now - $windowSeconds) {
            $hits = (int) $row['hits'] + 1;
        }
        $stmt = $db->prepare(
            'INSERT INTO api_rate_limits
             (bucket_key,endpoint,window_started_at,hits,expires_at)
             VALUES (?,?,UTC_TIMESTAMP(),?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))
             ON DUPLICATE KEY UPDATE
               window_started_at=IF(window_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? SECOND),UTC_TIMESTAMP(),window_started_at),
               hits=?,
               expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND)'
        );
        $stmt->bind_param('ssiiiii', $key, $endpoint, $hits, $windowSeconds, $windowSeconds, $hits, $windowSeconds);
        $stmt->execute();
        $db->commit();
        if ($hits > $limit) {
            header('Retry-After: ' . $windowSeconds);
            api_fail(429, 'RATE_LIMITED', 'Demasiadas solicitudes.');
        }
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

