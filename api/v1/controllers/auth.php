<?php
declare(strict_types=1);

function api_login(mysqli $db): void
{
    api_rate_limit($db, 'auth.login', 10, 60);
    $body = api_json_body();
    api_require_fields($body, ['email', 'password']);
    $email = filter_var((string) $body['email'], FILTER_VALIDATE_EMAIL);
    if (!$email || strlen((string) $body['password']) > 200) {
        api_fail(422, 'VALIDATION_ERROR', 'Credenciales con formato inválido.');
    }
    $stmt = $db->prepare(
        'SELECT id,uuid,nombre,email,password,rol,debe_cambiar_password,
                permissions_version,credentials_version
         FROM usuarios WHERE email=? AND activo=1 AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user || !password_verify((string) $body['password'], $user['password'])) {
        api_audit($db, $user ? (int) $user['id'] : null, 'api.login_failed', ['email' => $email]);
        api_fail(401, 'INVALID_CREDENTIALS', 'Correo o contraseña incorrectos.');
    }
    $tokens = api_issue_tokens($db, (int) $user['id'], null);
    api_audit($db, (int) $user['id'], 'api.login');
    $authorization = api_authorization_snapshot($db, (int) $user['id'], $user['rol']);
    unset($user['password'], $user['id']);
    api_ok(['user' => $user, 'tokens' => $tokens, 'authorization' => $authorization]);
}

function api_refresh(mysqli $db): void
{
    api_rate_limit($db, 'auth.refresh', 30, 60);
    $body = api_json_body();
    api_require_fields($body, ['refresh_token']);
    $hash = api_hash_token((string) $body['refresh_token']);
    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            'SELECT r.id,r.user_id,r.device_id,r.family_id
             FROM api_refresh_tokens r JOIN usuarios u ON u.id=r.user_id
             WHERE r.token_hash=? AND r.revoked_at IS NULL AND r.rotated_at IS NULL
               AND r.expires_at>UTC_TIMESTAMP(6) AND u.activo=1 AND u.deleted_at IS NULL
             FOR UPDATE'
        );
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $token = $stmt->get_result()->fetch_assoc();
        if (!$token) {
            $db->rollback();
            api_fail(401, 'REFRESH_EXPIRED_OR_INVALID', 'El refresh token no es válido.');
        }
        $tokens = api_issue_tokens(
            $db,
            (int) $token['user_id'],
            $token['device_id'],
            $token['family_id']
        );
        $replacement = api_hash_token($tokens['refresh_token']);
        $stmt = $db->prepare(
            'UPDATE api_refresh_tokens
             SET rotated_at=UTC_TIMESTAMP(6),replaced_by_hash=? WHERE id=?'
        );
        $stmt->bind_param('si', $replacement, $token['id']);
        $stmt->execute();
        $db->commit();
        api_ok(['tokens' => $tokens]);
    } catch (Throwable $error) {
        if ($db->errno === 0) {
            $db->rollback();
        }
        throw $error;
    }
}

function api_logout(mysqli $db, array $auth): void
{
    $hash = api_hash_token(api_bearer_token());
    $stmt = $db->prepare('UPDATE api_access_tokens SET revoked_at=UTC_TIMESTAMP(6) WHERE token_hash=?');
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    if ($auth['device_id']) {
        $stmt = $db->prepare(
            'UPDATE api_refresh_tokens SET revoked_at=UTC_TIMESTAMP(6)
             WHERE user_id=? AND device_id=? AND revoked_at IS NULL'
        );
        $stmt->bind_param('is', $auth['user_id'], $auth['device_id']);
        $stmt->execute();
    }
    api_audit($db, (int) $auth['user_id'], 'api.logout');
    api_ok(['logged_out' => true]);
}

function api_authorization_snapshot(mysqli $db, int $userId, string $role): array
{
    if ($role === 'super_administrador') {
        $result = $db->query('SELECT clave FROM modulos_sistema WHERE activo=1 AND deleted_at IS NULL ORDER BY clave');
    } else {
        $stmt = $db->prepare(
            'SELECT m.clave FROM roles_modulos rm JOIN modulos_sistema m ON m.id=rm.modulo_id
             WHERE rm.rol=? AND rm.permitido=1 AND rm.deleted_at IS NULL
               AND m.activo=1 AND m.deleted_at IS NULL ORDER BY m.clave'
        );
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    $modules = [];
    while ($row = $result->fetch_assoc()) {
        $modules[] = $row['clave'];
    }
    $stmt = $db->prepare(
        'SELECT permissions_version,credentials_version,
          EXISTS(SELECT 1 FROM legal_acceptances la WHERE la.user_id=u.id
                 AND la.document_key=\'privacy_terms\' AND la.document_version=?) legal_accepted
         FROM usuarios u WHERE u.id=?'
    );
    $legalVersion = getenv('TP_LEGAL_VERSION') ?: '2026-01';
    $stmt->bind_param('si', $legalVersion, $userId);
    $stmt->execute();
    $metadata = $stmt->get_result()->fetch_assoc();
    return [
        'modules' => $modules,
        'permissions_version' => (int) $metadata['permissions_version'],
        'credentials_version' => (int) $metadata['credentials_version'],
        'legal_version' => $legalVersion,
        'legal_accepted' => (bool) $metadata['legal_accepted'],
    ];
}

function api_session(mysqli $db, array $auth): void
{
    api_ok([
        'user' => [
            'uuid' => $auth['user_uuid'],
            'nombre' => $auth['nombre'],
            'email' => $auth['email'],
            'rol' => $auth['rol'],
        ],
        'authorization' => api_authorization_snapshot($db, (int) $auth['user_id'], $auth['rol']),
    ]);
}

function api_change_password(mysqli $db, array $auth): void
{
    $body = api_json_body();
    api_require_fields($body, ['current_password', 'new_password']);
    $newPassword = (string) $body['new_password'];
    if (strlen($newPassword) < 12 || strlen($newPassword) > 200) {
        api_fail(422, 'PASSWORD_POLICY', 'La contraseña nueva debe tener entre 12 y 200 caracteres.');
    }
    $stmt = $db->prepare('SELECT password FROM usuarios WHERE id=?');
    $stmt->bind_param('i', $auth['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || !password_verify((string) $body['current_password'], $row['password'])) {
        api_fail(401, 'CURRENT_PASSWORD_INVALID', 'La contraseña actual no es válida.');
    }
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        'UPDATE usuarios SET password=?,debe_cambiar_password=0,
         credentials_version=credentials_version+1 WHERE id=?'
    );
    $stmt->bind_param('si', $hash, $auth['user_id']);
    $stmt->execute();
    $stmt = $db->prepare(
        'UPDATE api_refresh_tokens SET revoked_at=UTC_TIMESTAMP(6)
         WHERE user_id=? AND revoked_at IS NULL'
    );
    $stmt->bind_param('i', $auth['user_id']);
    $stmt->execute();
    api_audit($db, (int) $auth['user_id'], 'api.password_changed');
    api_ok(['password_changed' => true]);
}

function api_accept_legal(mysqli $db, array $auth): void
{
    $body = api_json_body();
    api_require_fields($body, ['document_version']);
    $required = getenv('TP_LEGAL_VERSION') ?: '2026-01';
    if (!hash_equals($required, (string) $body['document_version'])) {
        api_fail(409, 'LEGAL_VERSION_MISMATCH', 'La versión legal ya no está vigente.');
    }
    $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $deviceId = $auth['device_id'];
    $stmt = $db->prepare(
        'INSERT IGNORE INTO legal_acceptances
         (user_id,document_key,document_version,device_id,ip_hash)
         VALUES (?,\'privacy_terms\',?,?,?)'
    );
    $stmt->bind_param('isss', $auth['user_id'], $required, $deviceId, $ipHash);
    $stmt->execute();
    api_audit($db, (int) $auth['user_id'], 'api.legal_accepted', ['version' => $required]);
    api_ok(['accepted' => true, 'document_version' => $required]);
}
