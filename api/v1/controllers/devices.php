<?php
declare(strict_types=1);

function api_register_device(mysqli $db, array $auth): void
{
    $body = api_json_body();
    api_require_fields($body, ['installation_id', 'device_name', 'app_version']);
    $installationId = api_assert_uuid($body['installation_id'], 'installation_id');
    $deviceName = trim((string) $body['device_name']);
    $appVersion = trim((string) $body['app_version']);
    if ($deviceName === '' || strlen($deviceName) > 150 || strlen($appVersion) > 40) {
        api_fail(422, 'VALIDATION_ERROR', 'Datos del dispositivo inválidos.');
    }
    $deviceId = api_uuid();
    $stmt = $db->prepare(
        'INSERT INTO devices(device_id,device_name,installation_id,last_seen_at,status,app_version)
         VALUES(?,?,?,UTC_TIMESTAMP(6),\'active\',?)
         ON DUPLICATE KEY UPDATE
           device_name=VALUES(device_name),last_seen_at=UTC_TIMESTAMP(6),
           app_version=VALUES(app_version)'
    );
    $stmt->bind_param('ssss', $deviceId, $deviceName, $installationId, $appVersion);
    $stmt->execute();
    $stmt = $db->prepare('SELECT device_id,status FROM devices WHERE installation_id=?');
    $stmt->bind_param('s', $installationId);
    $stmt->execute();
    $device = $stmt->get_result()->fetch_assoc();
    if ($device['status'] !== 'active') {
        api_fail(403, 'DEVICE_REVOKED', 'El dispositivo está revocado.');
    }
    $tokenHash = api_hash_token(api_bearer_token());
    $stmt = $db->prepare('UPDATE api_access_tokens SET device_id=? WHERE token_hash=?');
    $stmt->bind_param('ss', $device['device_id'], $tokenHash);
    $stmt->execute();
    $stmt = $db->prepare(
        'UPDATE api_refresh_tokens SET device_id=?
         WHERE user_id=? AND device_id IS NULL AND revoked_at IS NULL'
    );
    $stmt->bind_param('si', $device['device_id'], $auth['user_id']);
    $stmt->execute();
    api_audit($db, (int) $auth['user_id'], 'api.device_registered', ['device_id' => $device['device_id']]);
    api_ok(['device_id' => $device['device_id'], 'status' => 'active'], 201);
}

function api_device_heartbeat(mysqli $db, array $auth): void
{
    if (!$auth['device_id']) {
        api_fail(409, 'DEVICE_REQUIRED', 'El token no está asociado a un dispositivo.');
    }
    $body = api_json_body();
    $version = trim((string) ($body['app_version'] ?? ''));
    if ($version === '' || strlen($version) > 40) {
        api_fail(422, 'VALIDATION_ERROR', 'app_version inválida.');
    }
    $stmt = $db->prepare(
        'UPDATE devices SET last_seen_at=UTC_TIMESTAMP(6),app_version=?
         WHERE device_id=? AND status=\'active\''
    );
    $stmt->bind_param('ss', $version, $auth['device_id']);
    $stmt->execute();
    if ($stmt->affected_rows < 1) {
        api_fail(403, 'DEVICE_REVOKED', 'El dispositivo no está activo.');
    }
    api_ok(['heartbeat' => true]);
}

function api_revoke_device(mysqli $db, array $auth, string $deviceId): void
{
    api_require_role($auth, ['super_administrador', 'administrador']);
    $deviceId = api_assert_uuid($deviceId, 'device_id');
    $db->begin_transaction();
    try {
        $stmt = $db->prepare("UPDATE devices SET status='revoked' WHERE device_id=?");
        $stmt->bind_param('s', $deviceId);
        $stmt->execute();
        $stmt = $db->prepare(
            'UPDATE api_access_tokens SET revoked_at=UTC_TIMESTAMP(6)
             WHERE device_id=? AND revoked_at IS NULL'
        );
        $stmt->bind_param('s', $deviceId);
        $stmt->execute();
        $stmt = $db->prepare(
            'UPDATE api_refresh_tokens SET revoked_at=UTC_TIMESTAMP(6)
             WHERE device_id=? AND revoked_at IS NULL'
        );
        $stmt->bind_param('s', $deviceId);
        $stmt->execute();
        api_audit($db, (int) $auth['user_id'], 'api.device_revoked', ['device_id' => $deviceId]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
    api_ok(['revoked' => true, 'device_id' => $deviceId]);
}
