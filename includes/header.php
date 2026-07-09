<?php
// includes/header.php

// Cargar conexión si aún no existe
if (!isset($conn)) {
    $dbPath = __DIR__ . '/db.php';
    if (file_exists($dbPath)) {
        require_once $dbPath;
    }
}

$nombre_tienda = 'Tienda de Souvenirs';
$logo_tienda = '';
$favicon_tienda = '';

// Extensiones permitidas para el logo principal
$extensiones_logo = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'ico'];

/*
|--------------------------------------------------------------------------
| 1. Leer nombre y logo desde configuracion_galeria
|--------------------------------------------------------------------------
*/
if (isset($conn)) {
    $sql_logo = "SELECT nombre, logo FROM configuracion_galeria WHERE id = 1 LIMIT 1";
    $result_logo = $conn->query($sql_logo);

    if ($result_logo && $result_logo->num_rows > 0) {
        $row_logo = $result_logo->fetch_assoc();

        if (!empty($row_logo['nombre'])) {
            $nombre_tienda = $row_logo['nombre'];
        }

        if (!empty($row_logo['logo'])) {
            $ruta_db = trim($row_logo['logo']);

            // Validar contra ruta física
            $ruta_fisica_db = dirname(__DIR__) . '/' . ltrim($ruta_db, '/');

            if (file_exists($ruta_fisica_db)) {
                $logo_tienda = $ruta_db;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| 2. Si la BD no tiene logo válido, buscar img/panel_principal.*
|--------------------------------------------------------------------------
| Tu sistema siempre lo guarda como:
| img/panel_principal.png
| img/panel_principal.jpg
| img/panel_principal.jpeg
| etc.
*/
if (empty($logo_tienda)) {
    foreach ($extensiones_logo as $ext) {
        $ruta_relativa = 'img/panel_principal.' . $ext;
        $ruta_fisica = dirname(__DIR__) . '/' . $ruta_relativa;

        if (file_exists($ruta_fisica)) {
            $logo_tienda = $ruta_relativa;

            // Si existe el archivo pero la BD no estaba actualizada, la actualizamos
            if (isset($conn)) {
                $stmt_update_logo = $conn->prepare("
                    UPDATE configuracion_galeria
                    SET logo = ?
                    WHERE id = 1
                ");

                if ($stmt_update_logo) {
                    $stmt_update_logo->bind_param("s", $logo_tienda);
                    $stmt_update_logo->execute();
                    $stmt_update_logo->close();
                }
            }

            break;
        }
    }
}

/*
|--------------------------------------------------------------------------
| 3. Preparar favicon
|--------------------------------------------------------------------------
*/
if (!empty($logo_tienda)) {
    $ruta_fisica_logo = dirname(__DIR__) . '/' . ltrim($logo_tienda, '/');
    $version_logo = file_exists($ruta_fisica_logo) ? filemtime($ruta_fisica_logo) : time();

    $favicon_tienda = htmlspecialchars($logo_tienda, ENT_QUOTES, 'UTF-8') . '?v=' . $version_logo;
}

$titulo_pagina = htmlspecialchars($nombre_tienda, ENT_QUOTES, 'UTF-8');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?></title>

    <?php if (!empty($favicon_tienda)): ?>
        <link rel="icon" href="<?php echo $favicon_tienda; ?>" type="image/png">
        <link rel="shortcut icon" href="<?php echo $favicon_tienda; ?>" type="image/png">
        <link rel="apple-touch-icon" href="<?php echo $favicon_tienda; ?>">
    <?php endif; ?>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">

    <style>
        body,
        .wrapper,
        .content-wrapper,
        .content,
        .container-fluid,
        .container {
            background: #FFF4E6 !important;
        }

        .content-wrapper {
            padding: 20px !important;
            min-height: 100vh !important;
            margin-top: 10px !important;
        }

        .wrapper {
            min-height: 100vh !important;
        }

        .main-sidebar {
            top: 0 !important;
            padding-top: 60px !important;
        }

        .content-wrapper .container {
            margin-top: 15px !important;
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body class="hold-transition sidebar-mini">