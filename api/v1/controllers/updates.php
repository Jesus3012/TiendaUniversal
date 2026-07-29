<?php
declare(strict_types=1);

function api_update_manifest(mysqli $db): void
{
    $channel = (string) ($_GET['channel'] ?? 'stable');
    if (!in_array($channel, ['test', 'stable'], true)) {
        api_fail(422, 'UPDATE_CHANNEL_INVALID', 'Canal de actualización inválido.');
    }
    $stmt = $db->prepare(
        'SELECT version,channel,artifact_url,sha256,notes,published_at
         FROM update_releases
         WHERE channel=? AND active=1 AND rollout_percent>0
         ORDER BY published_at DESC,id DESC LIMIT 1'
    );
    $stmt->bind_param('s', $channel);
    $stmt->execute();
    $release = $stmt->get_result()->fetch_assoc();
    if (!$release) {
        api_fail(404, 'UPDATE_NOT_AVAILABLE', 'No hay actualización disponible para el canal.');
    }
    api_ok([
        'version' => $release['version'],
        'channel' => $release['channel'],
        'url' => $release['artifact_url'],
        'sha256' => strtolower($release['sha256']),
        'publishedAt' => gmdate('c', strtotime($release['published_at'] . ' UTC')),
        'notes' => $release['notes'],
    ]);
}
