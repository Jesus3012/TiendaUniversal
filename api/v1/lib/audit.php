<?php
declare(strict_types=1);

function api_audit(mysqli $db, ?int $userId, string $action, array $detail = []): void
{
    $detail['request_id'] = api_request_id();
    $json = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $db->prepare('INSERT INTO auditoria(usuario_id,accion,detalle,ip) VALUES(?,?,?,?)');
    $stmt->bind_param('isss', $userId, $action, $json, $ip);
    $stmt->execute();
}

