<?php
declare(strict_types=1);

function api_upload_product_image(mysqli $db, array $auth): void
{
    api_require_module($db, $auth, 'productos');
    $productUuid = api_assert_uuid($_POST['product_uuid'] ?? '', 'product_uuid');
    $expectedHash = strtolower((string) ($_POST['sha256'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
        api_fail(422, 'IMAGE_HASH_INVALID', 'El hash de imagen no es válido.');
    }
    $file = $_FILES['file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024) {
        api_fail(422, 'IMAGE_INVALID', 'La imagen no es válida o excede 10 MB.');
    }
    $actualHash = hash_file('sha256', $file['tmp_name']);
    if (!hash_equals($expectedHash, $actualHash)) {
        api_fail(422, 'IMAGE_HASH_MISMATCH', 'El contenido no coincide con el hash.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        api_fail(422, 'IMAGE_TYPE_INVALID', 'El formato de imagen no está permitido.');
    }
    $stmt = $db->prepare('SELECT id FROM productos WHERE uuid=? AND deleted_at IS NULL');
    $stmt->bind_param('s', $productUuid);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_row()) {
        api_fail(404, 'PRODUCT_NOT_FOUND', 'El producto no existe.');
    }
    $relative = 'uploads/product-images/' . gmdate('Y/m') . '/' . $actualHash . '.' . $extensions[$mime];
    $target = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0750, true) && !is_dir(dirname($target))) {
        throw new RuntimeException('No se pudo crear el directorio de imágenes.');
    }
    if (!is_file($target) && !move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('No se pudo conservar la imagen.');
    }
    $imageId = api_uuid();
    $stmt = $db->prepare(
        'INSERT INTO product_images
         (image_id,product_uuid,sha256,storage_path,mime_type,size_bytes,uploaded_by)
         VALUES(?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE image_id=image_id'
    );
    $size = (int) $file['size'];
    $stmt->bind_param('sssssii', $imageId, $productUuid, $actualHash, $relative, $mime, $size, $auth['user_id']);
    $stmt->execute();
    $stmt = $db->prepare('UPDATE productos SET imagen=? WHERE uuid=?');
    $stmt->bind_param('ss', $relative, $productUuid);
    $stmt->execute();
    api_audit($db, (int) $auth['user_id'], 'api.product_image_uploaded', [
        'product_uuid' => $productUuid,
        'sha256' => $actualHash,
    ]);
    api_ok(['image_id' => $imageId, 'sha256' => $actualHash, 'path' => $relative], 201);
}
