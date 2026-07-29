<?php
// Archivo: includes/auth_guard.php
// Debe cargarse como la primera dependencia de cada página protegida.

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permisos.php';

/** @var mysqli $conn */
permisos_proteger_ruta($conn);
